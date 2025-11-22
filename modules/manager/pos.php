<?php
/**
 * نقطة بيع محلية للمدير - بيع من مخزن الشركة الرئيسي مع طلبات الشحن
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/inventory_movements.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/invoices.php';
require_once __DIR__ . '/../../includes/reports.php';
require_once __DIR__ . '/../../includes/simple_telegram.php';

if (!function_exists('renderManagerInvoiceHtml')) {
    function renderManagerInvoiceHtml(array $invoice, array $meta = []): string
    {
        ob_start();
        $selectedInvoice = $invoice;
        $invoiceData = $invoice;
        $invoiceMeta = $meta;
        include __DIR__ . '/../accountant/invoice_print.php';
        return (string) ob_get_clean();
    }
}

if (!function_exists('storeManagerInvoiceDocument')) {
    function storeManagerInvoiceDocument(array $invoice, array $meta = []): ?array
    {
        try {
            if (!function_exists('ensurePrivateDirectory')) {
                return null;
            }

            $basePath = defined('REPORTS_PRIVATE_PATH')
                ? REPORTS_PRIVATE_PATH
                : (defined('REPORTS_PATH') ? REPORTS_PATH : (dirname(__DIR__, 2) . '/reports'));

            $basePath = rtrim((string) $basePath, '/\\');
            if ($basePath === '') {
                return null;
            }

            ensurePrivateDirectory($basePath);

            $exportsDir = $basePath . DIRECTORY_SEPARATOR . 'exports';
            $managerDir = $exportsDir . DIRECTORY_SEPARATOR . 'manager-pos';

            ensurePrivateDirectory($exportsDir);
            ensurePrivateDirectory($managerDir);

            if (!is_dir($managerDir) || !is_writable($managerDir)) {
                error_log('Manager POS invoice directory not writable: ' . $managerDir);
                return null;
            }

            $document = renderManagerInvoiceHtml($invoice, $meta);
            if ($document === '') {
                return null;
            }

            $pattern = $managerDir . DIRECTORY_SEPARATOR . 'pos-invoice-*';
            foreach (glob($pattern) ?: [] as $file) {
                if (is_string($file)) {
                    @unlink($file);
                }
            }

            $token = bin2hex(random_bytes(8));
            $normalizedNumber = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($invoice['invoice_number'] ?? 'INV'));
            $filename = sprintf('pos-invoice-%s-%s-%s.html', date('Ymd-His'), $normalizedNumber, $token);
            $fullPath = $managerDir . DIRECTORY_SEPARATOR . $filename;

            if (@file_put_contents($fullPath, $document) === false) {
                return null;
            }

            $relativePath = 'exports/manager-pos/' . $filename;
            $viewerPath = '/reports/view.php?type=export&file=' . rawurlencode($relativePath) . '&token=' . $token;
            $printPath = $viewerPath . '&print=1';

            $absoluteViewer = function_exists('getAbsoluteUrl')
                ? getAbsoluteUrl(ltrim($viewerPath, '/'))
                : $viewerPath;
            $absolutePrint = function_exists('getAbsoluteUrl')
                ? getAbsoluteUrl(ltrim($printPath, '/'))
                : $printPath;

            return [
                'relative_path' => $relativePath,
                'viewer_path' => $viewerPath,
                'print_path' => $printPath,
                'absolute_report_url' => $absoluteViewer,
                'absolute_print_url' => $absolutePrint,
                'generated_at' => date('Y-m-d H:i:s'),
                'summary' => $meta['summary'] ?? [],
                'token' => $token,
            ];
        } catch (Throwable $error) {
            error_log('Manager POS invoice storage failed: ' . $error->getMessage());
            return null;
        }
    }
}

function generateShippingOrderNumber(Database $db): string
{
    $year = date('Y');
    $month = date('m');
    $prefix = "SHIP-{$year}{$month}-";

    $lastOrder = $db->queryOne(
        "SELECT order_number FROM shipping_company_orders WHERE order_number LIKE ? ORDER BY order_number DESC LIMIT 1",
        [$prefix . '%']
    );

    if ($lastOrder && isset($lastOrder['order_number'])) {
        $parts = explode('-', $lastOrder['order_number']);
        $serial = (int)($parts[2] ?? 0) + 1;
    } else {
        $serial = 1;
    }

    return sprintf('%s%04d', $prefix, $serial);
}

requireRole('manager');

$currentUser = getCurrentUser();
$pageDirection = getDirection();
$db = db();
$error = '';
$success = '';
$validationErrors = [];

// التأكد من وجود الجداول المطلوبة
$customersTableExists = $db->queryOne("SHOW TABLES LIKE 'customers'");
$productsTableExists = $db->queryOne("SHOW TABLES LIKE 'products'");
$salesTableExists = $db->queryOne("SHOW TABLES LIKE 'sales'");
$warehousesTableExists = $db->queryOne("SHOW TABLES LIKE 'warehouses'");

if (empty($customersTableExists) || empty($productsTableExists) || empty($salesTableExists)) {
    $error = 'بعض الجداول المطلوبة غير متوفرة في قاعدة البيانات. يرجى التواصل مع المسؤول.';
}

// الحصول على المخزن الرئيسي
$mainWarehouse = null;
$mainWarehouseId = null;

if (!$error && !empty($warehousesTableExists)) {
    $mainWarehouse = $db->queryOne(
        "SELECT id, name, location, description FROM warehouses WHERE warehouse_type = 'main' AND status = 'active' LIMIT 1"
    );

    if (!$mainWarehouse) {
        $db->execute(
            "INSERT INTO warehouses (name, warehouse_type, status, location, description) VALUES (?, 'main', 'active', ?, ?)",
            ['المخزن الرئيسي', 'الموقع الرئيسي للشركة', 'مخزن الشركة الرئيسي']
        );
        $mainWarehouseId = $db->getLastInsertId();
        $mainWarehouse = $db->queryOne(
            "SELECT id, name, location, description FROM warehouses WHERE id = ?",
            [$mainWarehouseId]
        );
    }

    if ($mainWarehouse) {
        $mainWarehouseId = (int) $mainWarehouse['id'];
    }
}

// تحميل قائمة العملاء
$customers = [];
if (!$error && !empty($customersTableExists)) {
    $statusColumnExists = $db->queryOne("SHOW COLUMNS FROM customers LIKE 'status'");
    $customerSql = "SELECT id, name FROM customers WHERE 1=1";
    $customerParams = [];

    if (!empty($statusColumnExists)) {
        $customerSql .= " AND status = 'active'";
    }

    $customerSql .= " ORDER BY name ASC";
    $customers = $db->query($customerSql, $customerParams);
}

// تحميل منتجات المخزن الرئيسي
$mainWarehouseProducts = [];
$inventoryStats = [
    'total_products' => 0,
    'total_quantity' => 0,
    'total_value' => 0,
];

if (!$error && $mainWarehouseId) {
    try {
        $mainWarehouseProducts = $db->query(
            "SELECT 
                id AS product_id,
                name AS product_name,
                COALESCE(category, '') AS category,
                quantity,
                unit_price,
                COALESCE(unit, 'قطعة') AS unit
            FROM products
            WHERE status = 'active' 
                AND quantity > 0
                AND (warehouse_id = ? OR (warehouse_id IS NULL AND ? IN (SELECT id FROM warehouses WHERE warehouse_type = 'main')))
            ORDER BY name ASC",
            [$mainWarehouseId, $mainWarehouseId]
        ) ?? [];

        foreach ($mainWarehouseProducts as &$item) {
            $item['quantity'] = cleanFinancialValue($item['quantity'] ?? 0);
            $item['unit_price'] = cleanFinancialValue($item['unit_price'] ?? 0);
            $computedTotal = cleanFinancialValue(($item['quantity'] ?? 0) * ($item['unit_price'] ?? 0));
            $item['total_value'] = $computedTotal;

            $inventoryStats['total_products']++;
            $inventoryStats['total_quantity'] += (float) ($item['quantity'] ?? 0);
            $inventoryStats['total_value'] += (float) ($item['total_value'] ?? 0);
        }
        unset($item);
    } catch (Throwable $e) {
        error_log('Error loading main warehouse products: ' . $e->getMessage());
        $mainWarehouseProducts = [];
    }
}

// معالجة إنشاء عملية بيع جديدة
$posInvoiceLinks = null;
$productsByProductId = [];
foreach ($mainWarehouseProducts as $item) {
    $productsByProductId[(int) ($item['product_id'] ?? 0)] = $item;
}

if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_pos_sale') {
        $cartPayload = $_POST['cart_data'] ?? '';
        $cartItems = json_decode($cartPayload, true);
        $saleDate = $_POST['sale_date'] ?? date('Y-m-d');
        $customerMode = $_POST['customer_mode'] ?? 'existing';
        $paymentType = $_POST['payment_type'] ?? 'full';
        $prepaidAmount = cleanFinancialValue($_POST['prepaid_amount'] ?? 0);
        $paidAmountInput = cleanFinancialValue($_POST['paid_amount'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        $dueDateInput = trim($_POST['due_date'] ?? '');
        $dueDate = null;
        if (!empty($dueDateInput) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDateInput)) {
            $dueDate = $dueDateInput;
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $saleDate)) {
            $saleDate = date('Y-m-d');
        }

        if (!in_array($paymentType, ['full', 'partial', 'credit'], true)) {
            $paymentType = 'full';
        }

        if (!is_array($cartItems) || empty($cartItems)) {
            $validationErrors[] = 'يجب اختيار منتج واحد على الأقل من المخزون.';
        }

        $normalizedCart = [];
        $subtotal = 0;

        if (empty($validationErrors)) {
            foreach ($cartItems as $index => $row) {
                $productId = isset($row['product_id']) ? (int) $row['product_id'] : 0;
                $quantity = isset($row['quantity']) ? (float) $row['quantity'] : 0;
                $unitPrice = isset($row['unit_price']) ? round((float) $row['unit_price'], 2) : 0;

                if ($productId <= 0 || !isset($productsByProductId[$productId])) {
                    $validationErrors[] = 'المنتج المحدد رقم ' . ($index + 1) . ' غير متاح في المخزن الرئيسي.';
                    continue;
                }

                $product = $productsByProductId[$productId];
                $available = (float) ($product['quantity'] ?? 0);

                if ($quantity <= 0) {
                    $validationErrors[] = 'يجب تحديد كمية صالحة للمنتج ' . htmlspecialchars($product['product_name'] ?? '', ENT_QUOTES, 'UTF-8') . '.';
                    continue;
                }

                if ($quantity > $available) {
                    $validationErrors[] = 'الكمية المطلوبة للمنتج ' . htmlspecialchars($product['product_name'] ?? '', ENT_QUOTES, 'UTF-8') . ' تتجاوز الكمية المتاحة.';
                    continue;
                }

                if ($unitPrice <= 0) {
                    $unitPrice = round((float) ($product['unit_price'] ?? 0), 2);
                    if ($unitPrice <= 0) {
                        $validationErrors[] = 'لا يمكن تحديد سعر المنتج ' . htmlspecialchars($product['product_name'] ?? '', ENT_QUOTES, 'UTF-8') . '.';
                        continue;
                    }
                }

                $lineTotal = round($unitPrice * $quantity, 2);
                $subtotal += $lineTotal;

                $normalizedCart[] = [
                    'product_id' => $productId,
                    'name' => $product['product_name'] ?? 'منتج',
                    'category' => $product['category'] ?? null,
                    'quantity' => $quantity,
                    'available' => $available,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                ];
            }
        }

        if ($subtotal <= 0 && empty($validationErrors)) {
            $validationErrors[] = 'لا يمكن إتمام عملية بيع بمجموع صفري.';
        }

        $prepaidAmount = max(0, min($prepaidAmount, $subtotal));
        $netTotal = round($subtotal - $prepaidAmount, 2);

        $effectivePaidAmount = 0.0;
        if ($paymentType === 'full') {
            $effectivePaidAmount = $netTotal;
        } elseif ($paymentType === 'partial') {
            if ($paidAmountInput <= 0) {
                $validationErrors[] = 'يجب إدخال مبلغ التحصيل الجزئي.';
            } elseif ($paidAmountInput >= $netTotal) {
                $validationErrors[] = 'مبلغ التحصيل الجزئي يجب أن يكون أقل من الإجمالي بعد الخصم.';
            } else {
                $effectivePaidAmount = $paidAmountInput;
            }
        } else { // credit
            $effectivePaidAmount = 0.0;
        }

        $baseDueAmount = round(max(0, $netTotal - $effectivePaidAmount), 2);
        $dueAmount = $baseDueAmount;
        $creditUsed = 0.0;

        $customerId = 0;
        $createdCustomerId = null;
        $customer = null;

        if ($customerMode === 'existing') {
            $customerId = isset($_POST['customer_id']) ? (int) $_POST['customer_id'] : 0;
            if ($customerId <= 0) {
                $validationErrors[] = 'يجب اختيار عميل من القائمة.';
            } else {
                $customer = $db->queryOne("SELECT id, name, balance FROM customers WHERE id = ?", [$customerId]);
                if (!$customer) {
                    $validationErrors[] = 'العميل المحدد غير موجود.';
                }
            }
        } else {
            $newCustomerName = trim($_POST['new_customer_name'] ?? '');
            $newCustomerPhone = trim($_POST['new_customer_phone'] ?? '');
            $newCustomerAddress = trim($_POST['new_customer_address'] ?? '');

            if ($newCustomerName === '') {
                $validationErrors[] = 'يجب إدخال اسم العميل الجديد.';
            }
        }

        if (empty($validationErrors) && empty($normalizedCart)) {
            $validationErrors[] = 'قائمة المنتجات فارغة.';
        }

        if (empty($validationErrors)) {
            try {
                $conn = $db->getConnection();
                $conn->begin_transaction();

                if ($customerMode === 'new') {
                    $dueAmount = $baseDueAmount;
                    $creditUsed = 0.0;
                    $db->execute(
                        "INSERT INTO customers (name, phone, address, balance, status, created_by) VALUES (?, ?, ?, ?, 'active', ?)",
                        [
                            $newCustomerName,
                            $newCustomerPhone !== '' ? $newCustomerPhone : null,
                            $newCustomerAddress !== '' ? $newCustomerAddress : null,
                            $dueAmount,
                            $currentUser['id'],
                        ]
                    );
                    $customerId = (int) $db->getLastInsertId();
                    $createdCustomerId = $customerId;
                    $customer = [
                        'id' => $customerId,
                        'name' => $newCustomerName,
                        'balance' => $dueAmount,
                    ];
                } else {
                    $customer = $db->queryOne(
                        "SELECT id, balance FROM customers WHERE id = ? FOR UPDATE",
                        [$customerId]
                    );

                    if (!$customer) {
                        throw new RuntimeException('تعذر تحميل بيانات العميل أثناء المعالجة.');
                    }

                    $currentBalance = (float) ($customer['balance'] ?? 0);
                    if ($currentBalance < 0 && $dueAmount > 0) {
                        $creditUsed = min(abs($currentBalance), $dueAmount);
                        $dueAmount = round($dueAmount - $creditUsed, 2);
                        $effectivePaidAmount += $creditUsed;
                    } else {
                        $creditUsed = 0.0;
                    }

                    $newBalance = round($currentBalance + $creditUsed + $dueAmount, 2);
                    if (abs($newBalance - $currentBalance) > 0.0001) {
                        $db->execute("UPDATE customers SET balance = ? WHERE id = ?", [$newBalance, $customerId]);
                        $customer['balance'] = $newBalance;
                    }
                }

                $invoiceItems = [];
                foreach ($normalizedCart as $item) {
                    $invoiceItems[] = [
                        'product_id' => $item['product_id'],
                        'description' => $item['name'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                    ];
                }

                $invoiceResult = createInvoice(
                    $customerId,
                    $currentUser['id'],
                    $saleDate,
                    $invoiceItems,
                    0,
                    $prepaidAmount,
                    $notes,
                    $currentUser['id'],
                    $dueDate
                );

                if (empty($invoiceResult['success'])) {
                    throw new RuntimeException($invoiceResult['message'] ?? 'تعذر إنشاء الفاتورة.');
                }

                $invoiceId = (int) $invoiceResult['invoice_id'];
                $invoiceNumber = $invoiceResult['invoice_number'] ?? '';

                $invoiceStatus = 'sent';
                if ($dueAmount <= 0.0001) {
                    $invoiceStatus = 'paid';
                    if ($creditUsed > 0 && $effectivePaidAmount < $netTotal) {
                        $effectivePaidAmount = $netTotal;
                    }
                } elseif ($effectivePaidAmount > 0) {
                    $invoiceStatus = 'partial';
                }

                $db->execute(
                    "UPDATE invoices SET paid_amount = ?, remaining_amount = ?, status = ?, updated_at = NOW() WHERE id = ?",
                    [$effectivePaidAmount, $dueAmount, $invoiceStatus, $invoiceId]
                );

                foreach ($normalizedCart as $item) {
                    $productId = $item['product_id'];
                    $quantity = $item['quantity'];
                    $unitPrice = $item['unit_price'];
                    $lineTotal = $item['line_total'];

                    $productRow = $db->queryOne(
                        "SELECT quantity FROM products WHERE id = ? FOR UPDATE",
                        [$productId]
                    );

                    if (!$productRow) {
                        throw new RuntimeException('المنتج ' . $item['name'] . ' غير موجود في المخزن.');
                    }

                    $available = (float)($productRow['quantity'] ?? 0);

                    if ($quantity > $available) {
                        throw new RuntimeException('الكمية المتاحة للمنتج ' . $item['name'] . ' غير كافية. المتاح: ' . $available . '، المطلوب: ' . $quantity);
                    }

                    $movementResult = recordInventoryMovement(
                        $productId,
                        $mainWarehouseId,
                        'out',
                        $quantity,
                        'sales',
                        $invoiceId,
                        'بيع من نقطة بيع المدير - فاتورة ' . $invoiceNumber,
                        $currentUser['id'],
                        null
                    );

                    if (empty($movementResult['success'])) {
                        throw new RuntimeException($movementResult['message'] ?? 'تعذر تسجيل حركة المخزون.');
                    }

                    $newQuantity = max(0, $available - $quantity);
                    $db->execute(
                        "UPDATE products SET quantity = ?, updated_at = NOW() WHERE id = ?",
                        [$newQuantity, $productId]
                    );

                    $db->execute(
                        "INSERT INTO sales (customer_id, product_id, quantity, price, total, date, salesperson_id, status) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, 'completed')",
                        [$customerId, $productId, $quantity, $unitPrice, $lineTotal, $saleDate, $currentUser['id']]
                    );

                    $productsByProductId[$productId]['quantity'] = $newQuantity;
                    $productsByProductId[$productId]['total_value'] = ($newQuantity * $unitPrice);
                }

                logAudit($currentUser['id'], 'create_manager_pos_sale', 'invoice', $invoiceId, null, [
                    'invoice_number'    => $invoiceNumber,
                    'items'             => $normalizedCart,
                    'net_total'         => $netTotal,
                    'paid_amount'       => $effectivePaidAmount,
                    'base_due_amount'   => $baseDueAmount,
                    'credit_used'       => $creditUsed,
                    'due_amount'        => $dueAmount,
                    'customer_id'       => $customerId,
                ]);

                $conn->commit();

                $invoiceData = getInvoice($invoiceId);
                $invoiceMeta = [
                    'summary' => [
                        'subtotal' => $subtotal,
                        'prepaid' => $prepaidAmount,
                        'net_total' => $netTotal,
                        'paid' => $effectivePaidAmount,
                        'due_before_credit' => $baseDueAmount,
                        'credit_used' => $creditUsed,
                        'due' => $dueAmount,
                    ],
                ];
                $reportInfo = $invoiceData ? storeManagerInvoiceDocument($invoiceData, $invoiceMeta) : null;

                if ($reportInfo) {
                    $telegramResult = sendReportAndDelete($reportInfo, 'manager_pos_invoice', 'فاتورة نقطة بيع المدير');
                    $reportInfo['telegram_sent'] = !empty($telegramResult['success']);
                    $posInvoiceLinks = $reportInfo;
                }

                $success = 'تم إتمام عملية البيع بنجاح. رقم الفاتورة: ' . htmlspecialchars($invoiceNumber);
                if ($createdCustomerId) {
                    $success .= ' - تم إنشاء العميل الجديد.';
                }

                // إعادة تحميل المنتجات والإحصائيات
                $mainWarehouseProducts = $db->query(
                    "SELECT 
                        id AS product_id,
                        name AS product_name,
                        COALESCE(category, '') AS category,
                        quantity,
                        unit_price,
                        COALESCE(unit, 'قطعة') AS unit
                    FROM products
                    WHERE status = 'active' 
                        AND quantity > 0
                        AND (warehouse_id = ? OR (warehouse_id IS NULL AND ? IN (SELECT id FROM warehouses WHERE warehouse_type = 'main')))
                    ORDER BY name ASC",
                    [$mainWarehouseId, $mainWarehouseId]
                ) ?? [];

                $inventoryStats = [
                    'total_products' => 0,
                    'total_quantity' => 0,
                    'total_value' => 0,
                ];
                foreach ($mainWarehouseProducts as &$item) {
                    $item['quantity'] = cleanFinancialValue($item['quantity'] ?? 0);
                    $item['unit_price'] = cleanFinancialValue($item['unit_price'] ?? 0);
                    $computedTotal = cleanFinancialValue(($item['quantity'] ?? 0) * ($item['unit_price'] ?? 0));
                    $item['total_value'] = $computedTotal;

                    $inventoryStats['total_products']++;
                    $inventoryStats['total_quantity'] += (float) ($item['quantity'] ?? 0);
                    $inventoryStats['total_value'] += (float) ($item['total_value'] ?? 0);
                }
                unset($item);

                $productsByProductId = [];
                foreach ($mainWarehouseProducts as $item) {
                    $productsByProductId[(int) ($item['product_id'] ?? 0)] = $item;
                }

            } catch (Throwable $exception) {
                if (isset($conn)) {
                    $conn->rollback();
                }
                $error = 'حدث خطأ أثناء حفظ عملية البيع: ' . $exception->getMessage();
            }
        } else {
            $error = implode('<br>', array_map('htmlspecialchars', $validationErrors));
        }
    }
}

// آخر عمليات البيع
$recentSales = [];
if (!$error) {
    $recentSales = $db->query(
        "SELECT s.*, c.name AS customer_name, p.name AS product_name
         FROM sales s
         LEFT JOIN customers c ON s.customer_id = c.id
         LEFT JOIN products p ON s.product_id = p.id
         WHERE s.salesperson_id = ?
         ORDER BY s.created_at DESC
         LIMIT 10",
        [$currentUser['id']]
    ) ?? [];
}

// ========== SHIPPING ORDERS SECTION ==========
// Ensure shipping tables exist
try {
    $db->execute(
        "CREATE TABLE IF NOT EXISTS `shipping_companies` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(150) NOT NULL,
            `contact_person` varchar(100) DEFAULT NULL,
            `phone` varchar(30) DEFAULT NULL,
            `email` varchar(120) DEFAULT NULL,
            `address` text DEFAULT NULL,
            `balance` decimal(15,2) NOT NULL DEFAULT 0.00,
            `status` enum('active','inactive') NOT NULL DEFAULT 'active',
            `notes` text DEFAULT NULL,
            `created_by` int(11) DEFAULT NULL,
            `updated_by` int(11) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `name` (`name`),
            KEY `status` (`status`),
            KEY `created_by` (`created_by`),
            KEY `updated_by` (`updated_by`),
            CONSTRAINT `shipping_companies_created_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            CONSTRAINT `shipping_companies_updated_fk` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
} catch (Throwable $tableError) {
    error_log('pos: failed ensuring shipping_companies table -> ' . $tableError->getMessage());
}

try {
    $db->execute(
        "CREATE TABLE IF NOT EXISTS `shipping_company_orders` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `order_number` varchar(50) NOT NULL,
            `shipping_company_id` int(11) NOT NULL,
            `customer_id` int(11) NOT NULL,
            `invoice_id` int(11) DEFAULT NULL,
            `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
            `status` enum('assigned','in_transit','delivered','cancelled') NOT NULL DEFAULT 'assigned',
            `handed_over_at` timestamp NULL DEFAULT NULL,
            `delivered_at` timestamp NULL DEFAULT NULL,
            `notes` text DEFAULT NULL,
            `created_by` int(11) DEFAULT NULL,
            `updated_by` int(11) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `order_number` (`order_number`),
            KEY `shipping_company_id` (`shipping_company_id`),
            KEY `customer_id` (`customer_id`),
            KEY `invoice_id` (`invoice_id`),
            KEY `status` (`status`),
            CONSTRAINT `shipping_company_orders_company_fk` FOREIGN KEY (`shipping_company_id`) REFERENCES `shipping_companies` (`id`) ON DELETE CASCADE,
            CONSTRAINT `shipping_company_orders_customer_fk` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
            CONSTRAINT `shipping_company_orders_invoice_fk` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
            CONSTRAINT `shipping_company_orders_created_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            CONSTRAINT `shipping_company_orders_updated_fk` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
} catch (Throwable $tableError) {
    error_log('pos: failed ensuring shipping_company_orders table -> ' . $tableError->getMessage());
}

try {
    $db->execute(
        "CREATE TABLE IF NOT EXISTS `shipping_company_order_items` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `order_id` int(11) NOT NULL,
            `product_id` int(11) NOT NULL,
            `quantity` decimal(10,2) NOT NULL,
            `unit_price` decimal(15,2) NOT NULL,
            `total_price` decimal(15,2) NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `order_id` (`order_id`),
            KEY `product_id` (`product_id`),
            CONSTRAINT `shipping_company_order_items_order_fk` FOREIGN KEY (`order_id`) REFERENCES `shipping_company_orders` (`id`) ON DELETE CASCADE,
            CONSTRAINT `shipping_company_order_items_product_fk` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
} catch (Throwable $tableError) {
    error_log('pos: failed ensuring shipping_company_order_items table -> ' . $tableError->getMessage());
}

// Handle shipping orders POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_shipping_company') {
        $name = trim($_POST['company_name'] ?? '');
        $contactPerson = trim($_POST['contact_person'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $notes = trim($_POST['company_notes'] ?? '');

        if ($name === '') {
            $error = 'يجب إدخال اسم شركة الشحن.';
        } else {
            try {
                $existingCompany = $db->queryOne("SELECT id FROM shipping_companies WHERE name = ?", [$name]);
                if ($existingCompany) {
                    throw new InvalidArgumentException('اسم شركة الشحن مستخدم بالفعل.');
                }

                $db->execute(
                    "INSERT INTO shipping_companies (name, contact_person, phone, email, address, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [
                        $name,
                        $contactPerson !== '' ? $contactPerson : null,
                        $phone !== '' ? $phone : null,
                        $email !== '' ? $email : null,
                        $address !== '' ? $address : null,
                        $notes !== '' ? $notes : null,
                        $currentUser['id'] ?? null,
                    ]
                );

                $success = 'تم إضافة شركة الشحن بنجاح.';
            } catch (InvalidArgumentException $validationError) {
                $error = $validationError->getMessage();
            } catch (Throwable $addError) {
                error_log('pos: add company error -> ' . $addError->getMessage());
                $error = 'تعذر إضافة شركة الشحن. يرجى المحاولة لاحقاً.';
            }
        }
    }

    if ($action === 'create_shipping_order') {
        $shippingCompanyId = isset($_POST['shipping_company_id']) ? (int)$_POST['shipping_company_id'] : 0;
        $customerId = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
        $notes = trim($_POST['order_notes'] ?? '');
        $itemsInput = $_POST['items'] ?? [];

        if ($shippingCompanyId <= 0) {
            $error = 'يرجى اختيار شركة الشحن.';
        } elseif ($customerId <= 0) {
            $error = 'يرجى اختيار العميل.';
        } elseif (!is_array($itemsInput) || empty($itemsInput)) {
            $error = 'يرجى إضافة منتجات إلى الطلب.';
        } else {
            $normalizedItems = [];
            $totalAmount = 0.0;
            $productIds = [];

            foreach ($itemsInput as $itemRow) {
                if (!is_array($itemRow)) {
                    continue;
                }

                $productId = isset($itemRow['product_id']) ? (int)$itemRow['product_id'] : 0;
                $quantity = isset($itemRow['quantity']) ? (float)$itemRow['quantity'] : 0.0;
                $unitPrice = isset($itemRow['unit_price']) ? (float)$itemRow['unit_price'] : 0.0;

                if ($productId <= 0 || $quantity <= 0 || $unitPrice < 0) {
                    continue;
                }

                $productIds[] = $productId;
                $lineTotal = round($quantity * $unitPrice, 2);
                $totalAmount += $lineTotal;

                $normalizedItems[] = [
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_price' => $lineTotal,
                ];
            }

            if (empty($normalizedItems)) {
                $error = 'يرجى التأكد من إدخال بيانات صحيحة للمنتجات.';
            } else {
                $totalAmount = round($totalAmount, 2);
                $transactionStarted = false;

                try {
                    $db->beginTransaction();
                    $transactionStarted = true;

                    $shippingCompany = $db->queryOne(
                        "SELECT id, status, balance FROM shipping_companies WHERE id = ? FOR UPDATE",
                        [$shippingCompanyId]
                    );

                    if (!$shippingCompany || ($shippingCompany['status'] ?? '') !== 'active') {
                        throw new InvalidArgumentException('شركة الشحن المحددة غير متاحة أو غير نشطة.');
                    }

                    $customer = $db->queryOne(
                        "SELECT id, balance FROM customers WHERE id = ? FOR UPDATE",
                        [$customerId]
                    );

                    if (!$customer) {
                        throw new InvalidArgumentException('تعذر العثور على العميل المحدد.');
                    }

                    $productsMap = [];
                    if (!empty($productIds)) {
                        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
                        $productsRows = $db->query(
                            "SELECT id, name, quantity, unit_price FROM products WHERE id IN ($placeholders) FOR UPDATE",
                            $productIds
                        );

                        foreach ($productsRows as $row) {
                            $productsMap[(int)$row['id']] = $row;
                        }
                    }

                    foreach ($normalizedItems as $normalizedItem) {
                        $productId = $normalizedItem['product_id'];
                        $requestedQuantity = $normalizedItem['quantity'];

                        $productRow = $productsMap[$productId] ?? null;
                        if (!$productRow) {
                            throw new InvalidArgumentException('تعذر العثور على منتج من عناصر الطلب.');
                        }

                        $availableQuantity = (float)($productRow['quantity'] ?? 0);
                        if ($availableQuantity < $requestedQuantity) {
                            throw new InvalidArgumentException('الكمية المتاحة للمنتج ' . ($productRow['name'] ?? '') . ' غير كافية.');
                        }
                    }

                    $invoiceItems = [];
                    foreach ($normalizedItems as $normalizedItem) {
                        $productRow = $productsMap[$normalizedItem['product_id']];
                        $invoiceItems[] = [
                            'product_id' => $normalizedItem['product_id'],
                            'description' => $productRow['name'] ?? null,
                            'quantity' => $normalizedItem['quantity'],
                            'unit_price' => $normalizedItem['unit_price'],
                        ];
                    }

                    $invoiceResult = createInvoice(
                        $customerId,
                        null,
                        date('Y-m-d'),
                        $invoiceItems,
                        0,
                        0,
                        $notes,
                        $currentUser['id'] ?? null
                    );

                    if (empty($invoiceResult['success'])) {
                        throw new RuntimeException($invoiceResult['message'] ?? 'تعذر إنشاء الفاتورة الخاصة بالطلب.');
                    }

                    $invoiceId = (int)$invoiceResult['invoice_id'];
                    $invoiceNumber = $invoiceResult['invoice_number'] ?? '';

                    $db->execute(
                        "UPDATE invoices SET paid_amount = 0, remaining_amount = ?, status = 'sent', updated_at = NOW() WHERE id = ?",
                        [$totalAmount, $invoiceId]
                    );

                    $orderNumber = generateShippingOrderNumber($db);

                    $db->execute(
                        "INSERT INTO shipping_company_orders (order_number, shipping_company_id, customer_id, invoice_id, total_amount, status, handed_over_at, notes, created_by) VALUES (?, ?, ?, ?, ?, 'assigned', NOW(), ?, ?)",
                        [
                            $orderNumber,
                            $shippingCompanyId,
                            $customerId,
                            $invoiceId,
                            $totalAmount,
                            $notes !== '' ? $notes : null,
                            $currentUser['id'] ?? null,
                        ]
                    );

                    $orderId = (int)$db->getLastInsertId();

                    foreach ($normalizedItems as $normalizedItem) {
                        $productRow = $productsMap[$normalizedItem['product_id']];

                        $db->execute(
                            "INSERT INTO shipping_company_order_items (order_id, product_id, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?)",
                            [
                                $orderId,
                                $normalizedItem['product_id'],
                                $normalizedItem['quantity'],
                                $normalizedItem['unit_price'],
                                $normalizedItem['total_price'],
                            ]
                        );

                        $movementNote = 'تسليم طلب شحن #' . $orderNumber . ' لشركة الشحن';
                        $movementResult = recordInventoryMovement(
                            $normalizedItem['product_id'],
                            $mainWarehouseId,
                            'out',
                            $normalizedItem['quantity'],
                            'shipping_order',
                            $orderId,
                            $movementNote,
                            $currentUser['id'] ?? null
                        );

                        if (empty($movementResult['success'])) {
                            throw new RuntimeException($movementResult['message'] ?? 'تعذر تسجيل حركة المخزون.');
                        }

                        $newQuantity = max(0, (float)($productRow['quantity'] ?? 0) - $normalizedItem['quantity']);
                        $db->execute(
                            "UPDATE products SET quantity = ?, updated_at = NOW() WHERE id = ?",
                            [$newQuantity, $normalizedItem['product_id']]
                        );
                    }

                    $db->execute(
                        "UPDATE shipping_companies SET balance = balance + ?, updated_by = ?, updated_at = NOW() WHERE id = ?",
                        [$totalAmount, $currentUser['id'] ?? null, $shippingCompanyId]
                    );

                    logAudit(
                        $currentUser['id'] ?? null,
                        'create_shipping_order',
                        'shipping_order',
                        $orderId,
                        null,
                        [
                            'order_number' => $orderNumber,
                            'total_amount' => $totalAmount,
                            'shipping_company_id' => $shippingCompanyId,
                            'customer_id' => $customerId,
                        ]
                    );

                    $db->commit();
                    $transactionStarted = false;

                    $success = 'تم تسجيل طلب الشحن وتسليم المنتجات لشركة الشحن بنجاح.';
                } catch (InvalidArgumentException $validationError) {
                    if ($transactionStarted) {
                        $db->rollback();
                    }
                    $error = $validationError->getMessage();
                } catch (Throwable $createError) {
                    if ($transactionStarted) {
                        $db->rollback();
                    }
                    error_log('pos: create order error -> ' . $createError->getMessage());
                    $error = 'تعذر إنشاء طلب الشحن. يرجى المحاولة لاحقاً.';
                }
            }
        }
    }

    if ($action === 'update_shipping_status') {
        $orderId = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
        $newStatus = $_POST['status'] ?? '';
        $allowedStatuses = ['in_transit'];

        if ($orderId <= 0 || !in_array($newStatus, $allowedStatuses, true)) {
            $error = 'طلب غير صالح لتحديث الحالة.';
        } else {
            try {
                $updateResult = $db->execute(
                    "UPDATE shipping_company_orders SET status = ?, updated_by = ?, updated_at = NOW() WHERE id = ? AND status = 'assigned'",
                    [$newStatus, $currentUser['id'] ?? null, $orderId]
                );

                if (($updateResult['affected_rows'] ?? 0) < 1) {
                    throw new RuntimeException('لا يمكن تحديث حالة هذا الطلب في الوقت الحالي.');
                }

                logAudit(
                    $currentUser['id'] ?? null,
                    'update_shipping_order_status',
                    'shipping_order',
                    $orderId,
                    null,
                    ['status' => $newStatus]
                );

                $success = 'تم تحديث حالة طلب الشحن.';
            } catch (Throwable $statusError) {
                error_log('pos: update status error -> ' . $statusError->getMessage());
                $error = 'تعذر تحديث حالة الطلب.';
            }
        }
    }

    if ($action === 'complete_shipping_order') {
        $orderId = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;

        if ($orderId <= 0) {
            $error = 'طلب غير صالح لإتمام التسليم.';
        } else {
            $transactionStarted = false;

            try {
                $db->beginTransaction();
                $transactionStarted = true;

                $order = $db->queryOne(
                    "SELECT id, shipping_company_id, customer_id, total_amount, status, invoice_id FROM shipping_company_orders WHERE id = ? FOR UPDATE",
                    [$orderId]
                );

                if (!$order) {
                    throw new InvalidArgumentException('طلب الشحن المحدد غير موجود.');
                }

                if ($order['status'] === 'delivered') {
                    throw new InvalidArgumentException('تم تسليم هذا الطلب بالفعل.');
                }

                if ($order['status'] === 'cancelled') {
                    throw new InvalidArgumentException('لا يمكن إتمام طلب ملغى.');
                }

                $shippingCompany = $db->queryOne(
                    "SELECT id, balance FROM shipping_companies WHERE id = ? FOR UPDATE",
                    [$order['shipping_company_id']]
                );

                if (!$shippingCompany) {
                    throw new InvalidArgumentException('شركة الشحن المرتبطة بالطلب غير موجودة.');
                }

                $customer = $db->queryOne(
                    "SELECT id, balance FROM customers WHERE id = ? FOR UPDATE",
                    [$order['customer_id']]
                );

                if (!$customer) {
                    throw new InvalidArgumentException('تعذر العثور على العميل المرتبط بالطلب.');
                }

                $totalAmount = (float)($order['total_amount'] ?? 0.0);

                $db->execute(
                    "UPDATE shipping_companies SET balance = balance - ?, updated_by = ?, updated_at = NOW() WHERE id = ?",
                    [$totalAmount, $currentUser['id'] ?? null, $order['shipping_company_id']]
                );

                $db->execute(
                    "UPDATE customers SET balance = balance + ?, updated_at = NOW() WHERE id = ?",
                    [$totalAmount, $order['customer_id']]
                );

                $db->execute(
                    "UPDATE shipping_company_orders SET status = 'delivered', delivered_at = NOW(), updated_by = ?, updated_at = NOW() WHERE id = ?",
                    [$currentUser['id'] ?? null, $orderId]
                );

                if (!empty($order['invoice_id'])) {
                    $db->execute(
                        "UPDATE invoices SET status = 'sent', remaining_amount = ?, updated_at = NOW() WHERE id = ?",
                        [$totalAmount, $order['invoice_id']]
                    );
                }

                logAudit(
                    $currentUser['id'] ?? null,
                    'complete_shipping_order',
                    'shipping_order',
                    $orderId,
                    null,
                    [
                        'total_amount' => $totalAmount,
                        'customer_id' => $order['customer_id'],
                        'shipping_company_id' => $order['shipping_company_id'],
                    ]
                );

                $db->commit();
                $transactionStarted = false;

                $success = 'تم تأكيد تسليم الطلب للعميل ونقل الدين بنجاح.';
            } catch (InvalidArgumentException $validationError) {
                if ($transactionStarted) {
                    $db->rollback();
                }
                $error = $validationError->getMessage();
            } catch (Throwable $completeError) {
                if ($transactionStarted) {
                    $db->rollback();
                }
                error_log('pos: complete order error -> ' . $completeError->getMessage());
                $error = 'تعذر إتمام إجراءات الطلب. يرجى المحاولة لاحقاً.';
            }
        }
    }
}

// Load shipping data
$shippingCompanies = [];
try {
    $shippingCompanies = $db->query(
        "SELECT id, name, phone, status, balance FROM shipping_companies ORDER BY status = 'active' DESC, name ASC"
    ) ?? [];
} catch (Throwable $companiesError) {
    error_log('pos: failed fetching companies -> ' . $companiesError->getMessage());
    $shippingCompanies = [];
}

$activeCustomers = [];
try {
    $activeCustomers = $db->query(
        "SELECT id, name, phone FROM customers WHERE status = 'active' ORDER BY name ASC"
    ) ?? [];
} catch (Throwable $customersError) {
    error_log('pos: failed fetching customers -> ' . $customersError->getMessage());
    $activeCustomers = [];
}

$availableProductsForShipping = [];
try {
    $availableProductsForShipping = $db->query(
        "SELECT id, name, quantity, unit, unit_price FROM products WHERE status = 'active' AND quantity > 0 ORDER BY name ASC"
    ) ?? [];
} catch (Throwable $productsError) {
    error_log('pos: failed fetching products -> ' . $productsError->getMessage());
    $availableProductsForShipping = [];
}

$orders = [];
try {
    $orders = $db->query(
        "SELECT 
            sco.*, 
            sc.name AS shipping_company_name,
            sc.balance AS company_balance,
            c.name AS customer_name,
            c.phone AS customer_phone,
            i.invoice_number
        FROM shipping_company_orders sco
        LEFT JOIN shipping_companies sc ON sco.shipping_company_id = sc.id
        LEFT JOIN customers c ON sco.customer_id = c.id
        LEFT JOIN invoices i ON sco.invoice_id = i.id
        ORDER BY sco.created_at DESC
        LIMIT 50"
    ) ?? [];
} catch (Throwable $ordersError) {
    error_log('pos: failed fetching orders -> ' . $ordersError->getMessage());
    $orders = [];
}

$ordersStats = [
    'total_orders' => 0,
    'active_orders' => 0,
    'delivered_orders' => 0,
    'outstanding_amount' => 0.0,
];

try {
    $statsRow = $db->queryOne(
        "SELECT 
            COUNT(*) AS total_orders,
            SUM(CASE WHEN status IN ('assigned','in_transit') THEN 1 ELSE 0 END) AS active_orders,
            SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) AS delivered_orders,
            SUM(CASE WHEN status IN ('assigned','in_transit') THEN total_amount ELSE 0 END) AS outstanding_amount
        FROM shipping_company_orders"
    );

    if ($statsRow) {
        $ordersStats['total_orders'] = (int)($statsRow['total_orders'] ?? 0);
        $ordersStats['active_orders'] = (int)($statsRow['active_orders'] ?? 0);
        $ordersStats['delivered_orders'] = (int)($statsRow['delivered_orders'] ?? 0);
        $ordersStats['outstanding_amount'] = (float)($statsRow['outstanding_amount'] ?? 0);
    }
} catch (Throwable $statsError) {
    error_log('pos: failed fetching stats -> ' . $statsError->getMessage());
}

$statusLabels = [
    'assigned' => ['label' => 'تم التسليم لشركة الشحن', 'class' => 'bg-primary'],
    'in_transit' => ['label' => 'جاري الشحن', 'class' => 'bg-warning text-dark'],
    'delivered' => ['label' => 'تم التسليم للعميل', 'class' => 'bg-success'],
    'cancelled' => ['label' => 'ملغي', 'class' => 'bg-secondary'],
];

$hasProductsForShipping = !empty($availableProductsForShipping);
$hasShippingCompanies = !empty($shippingCompanies);
?>

<div class="page-header">
    <h2><i class="bi bi-shop me-2"></i>نقطة بيع المدير</h2>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" id="errorAlert" data-auto-refresh="true" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?php echo $error; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" id="successAlert" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if (!empty($posInvoiceLinks['absolute_report_url'])): ?>
<!-- Modal عرض الفاتورة بعد البيع -->
<div class="modal fade" id="posInvoiceModal" tabindex="-1" aria-labelledby="posInvoiceModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="posInvoiceModalLabel">
                    <i class="bi bi-receipt-cutoff me-2"></i>
                    فاتورة البيع
                </h5>
        