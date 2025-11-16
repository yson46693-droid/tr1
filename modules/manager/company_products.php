<?php
/**
 * صفحة منتجات الشركة - المدير
 * Company Products Page - Manager
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/table_styles.php';
require_once __DIR__ . '/../../includes/audit_log.php';

requireRole('manager');

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';

// معالجة العمليات
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_external_product') {
        $name = trim($_POST['product_name'] ?? '');
        $quantity = max(0, floatval($_POST['quantity'] ?? 0));
        $unitPrice = max(0, floatval($_POST['unit_price'] ?? 0));
        $unit = trim($_POST['unit'] ?? 'قطعة');
        
        if ($name === '') {
            $error = 'يرجى إدخال اسم المنتج.';
        } else {
            try {
                // التأكد من وجود الأعمدة المطلوبة
                try {
                    $productTypeColumn = $db->queryOne("SHOW COLUMNS FROM products LIKE 'product_type'");
                    if (empty($productTypeColumn)) {
                        $db->execute("ALTER TABLE `products` ADD COLUMN `product_type` ENUM('internal','external') DEFAULT 'internal' AFTER `category`");
                    }
                } catch (Exception $e) {
                    // العمود موجود بالفعل
                }
                
                $db->execute(
                    "INSERT INTO products (name, category, product_type, quantity, unit, unit_price, status)
                     VALUES (?, 'منتجات خارجية', 'external', ?, ?, ?, 'active')",
                    [$name, $quantity, $unit, $unitPrice]
                );
                
                $productId = $db->getLastInsertId();
                logAudit($currentUser['id'], 'create_external_product', 'product', $productId, null, [
                    'name' => $name,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice
                ]);
                
                $success = 'تم إضافة المنتج الخارجي بنجاح.';
            } catch (Exception $e) {
                error_log('create_external_product error: ' . $e->getMessage());
                $error = 'تعذر إضافة المنتج الخارجي. يرجى المحاولة لاحقاً.';
            }
        }
    } elseif ($action === 'update_external_product') {
        $productId = intval($_POST['product_id'] ?? 0);
        $name = trim($_POST['product_name'] ?? '');
        $quantity = max(0, floatval($_POST['quantity'] ?? 0));
        $unitPrice = max(0, floatval($_POST['unit_price'] ?? 0));
        $unit = trim($_POST['unit'] ?? 'قطعة');
        
        if ($productId <= 0 || $name === '') {
            $error = 'بيانات غير صحيحة.';
        } else {
            try {
                $db->execute(
                    "UPDATE products SET name = ?, quantity = ?, unit = ?, unit_price = ?, updated_at = NOW()
                     WHERE id = ? AND product_type = 'external'",
                    [$name, $quantity, $unit, $unitPrice, $productId]
                );
                
                logAudit($currentUser['id'], 'update_external_product', 'product', $productId, null, [
                    'name' => $name,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice
                ]);
                
                $success = 'تم تحديث المنتج الخارجي بنجاح.';
            } catch (Exception $e) {
                error_log('update_external_product error: ' . $e->getMessage());
                $error = 'تعذر تحديث المنتج الخارجي. يرجى المحاولة لاحقاً.';
            }
        }
    } elseif ($action === 'delete_external_product') {
        $productId = intval($_POST['product_id'] ?? 0);
        
        if ($productId <= 0) {
            $error = 'بيانات غير صحيحة.';
        } else {
            try {
                $db->execute(
                    "DELETE FROM products WHERE id = ? AND product_type = 'external'",
                    [$productId]
                );
                
                logAudit($currentUser['id'], 'delete_external_product', 'product', $productId, null, []);
                $success = 'تم حذف المنتج الخارجي بنجاح.';
            } catch (Exception $e) {
                error_log('delete_external_product error: ' . $e->getMessage());
                $error = 'تعذر حذف المنتج الخارجي. يرجى المحاولة لاحقاً.';
            }
        }
    }
}

// الحصول على منتجات المصنع (من جدول products مع عمال الإنتاج)
$factoryProducts = [];
try {
    // التحقق من وجود عمود user_id أو worker_id في جدول production
    $userIdColumn = null;
    $columns = $db->query("SHOW COLUMNS FROM production");
    foreach ($columns as $col) {
        if (in_array(strtolower($col['Field']), ['user_id', 'worker_id', 'employee_id'], true)) {
            $userIdColumn = $col['Field'];
            break;
        }
    }
    
    $factoryProducts = $db->query("
        SELECT 
            p.id,
            p.name as product_name,
            p.category,
            p.quantity,
            COALESCE(p.unit, 'قطعة') as unit,
            p.unit_price,
            " . ($userIdColumn ? "
            GROUP_CONCAT(DISTINCT u.full_name ORDER BY u.full_name SEPARATOR ', ') AS workers
            " : "'غير محدد' AS workers") . ",
            COUNT(DISTINCT pr.id) as production_count,
            COALESCE(SUM(pr.quantity), 0) as total_produced
        FROM products p
        LEFT JOIN production pr ON p.id = pr.product_id
        " . ($userIdColumn ? "LEFT JOIN users u ON pr.$userIdColumn = u.id" : "") . "
        WHERE (p.product_type = 'internal' OR p.product_type IS NULL OR p.product_type = '')
          AND p.status = 'active'
        GROUP BY p.id
        ORDER BY p.name ASC
    ");
} catch (Exception $e) {
    error_log('Error fetching factory products: ' . $e->getMessage());
}

// الحصول على المنتجات الخارجية
$externalProducts = [];
try {
    $externalProducts = $db->query("
        SELECT 
            id,
            name,
            quantity,
            COALESCE(unit, 'قطعة') as unit,
            unit_price,
            (quantity * unit_price) as total_value,
            created_at,
            updated_at
        FROM products
        WHERE product_type = 'external'
          AND status = 'active'
        ORDER BY name ASC
    ");
} catch (Exception $e) {
    error_log('Error fetching external products: ' . $e->getMessage());
}

// إحصائيات
$totalFactoryProducts = count($factoryProducts);
$totalExternalProducts = count($externalProducts);
$totalExternalValue = 0;
foreach ($externalProducts as $ext) {
    $totalExternalValue += floatval($ext['total_value'] ?? 0);
}
?>

<style>
.company-products-page {
    padding: 1.5rem 0;
}

.section-header {
    background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%);
    color: white;
    padding: 1.25rem 1.5rem;
    border-radius: 12px 12px 0 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.section-header h5 {
    margin: 0;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.section-header .badge {
    background: rgba(255, 255, 255, 0.2);
    color: white;
    padding: 0.35rem 0.75rem;
    border-radius: 20px;
    font-size: 0.875rem;
}

.company-card {
    border: none;
    border-radius: 12px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07);
    margin-bottom: 2rem;
    overflow: hidden;
}

.company-card .card-body {
    padding: 1.5rem;
}

.btn-primary-custom {
    background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 100%);
    border: none;
    color: white;
    padding: 0.6rem 1.25rem;
    border-radius: 8px;
    font-weight: 500;
    transition: all 0.3s ease;
}

.btn-primary-custom:hover {
    background: linear-gradient(135deg, #1e40af 0%, #1d4ed8 100%);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(29, 78, 216, 0.3);
    color: white;
}

.btn-success-custom {
    background: linear-gradient(135deg, #059669 0%, #10b981 100%);
    border: none;
    color: white;
    padding: 0.6rem 1.25rem;
    border-radius: 8px;
    font-weight: 500;
    transition: all 0.3s ease;
}

.btn-success-custom:hover {
    background: linear-gradient(135deg, #047857 0%, #059669 100%);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3);
    color: white;
}

@media (max-width: 768px) {
    .section-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
    }
    
    .company-card .card-body {
        padding: 1rem;
    }
}
</style>

<div class="company-products-page">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0"><i class="bi bi-box-seam me-2 text-primary"></i>منتجات الشركة</h2>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle-fill me-2"></i>
            <?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- قسم منتجات المصنع -->
    <div class="card company-card mb-4">
        <div class="section-header">
            <h5>
                <i class="bi bi-building"></i>
                منتجات المصنع
            </h5>
            <span class="badge"><?php echo $totalFactoryProducts; ?> منتج</span>
        </div>
        <div class="card-body">
            <div class="table-responsive dashboard-table-wrapper">
                <table class="table dashboard-table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>اسم المنتج</th>
                            <th>الفئة</th>
                            <th>الكمية المتاحة</th>
                            <th>سعر الوحدة</th>
                            <th>إجمالي القيمة</th>
                            <th>عدد عمليات الإنتاج</th>
                            <th>إجمالي المنتج</th>
                            <th>العمال المشاركون</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($factoryProducts)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">لا توجد منتجات مصنع حالياً</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($factoryProducts as $product): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($product['product_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($product['category'] ?? '—'); ?></td>
                                    <td><?php echo number_format((float)$product['quantity'], 2); ?> <?php echo htmlspecialchars($product['unit'] ?? 'قطعة'); ?></td>
                                    <td><?php echo formatCurrency((float)$product['unit_price']); ?></td>
                                    <td><strong class="text-success"><?php echo formatCurrency((float)$product['quantity'] * (float)$product['unit_price']); ?></strong></td>
                                    <td><?php echo number_format((int)$product['production_count']); ?></td>
                                    <td><?php echo number_format((float)($product['total_produced'] ?? 0), 2); ?></td>
                                    <td><?php echo htmlspecialchars($product['workers'] ?? 'غير محدد'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- قسم المنتجات الخارجية -->
    <div class="card company-card">
        <div class="section-header">
            <h5>
                <i class="bi bi-cart4"></i>
                المنتجات الخارجية
            </h5>
            <div class="d-flex gap-2 align-items-center">
                <span class="badge"><?php echo $totalExternalProducts; ?> منتج</span>
                <button type="button" class="btn btn-success-custom btn-sm" data-bs-toggle="modal" data-bs-target="#addExternalProductModal">
                    <i class="bi bi-plus-circle me-1"></i>إضافة منتج خارجي
                </button>
            </div>
        </div>
        <div class="card-body">
            <?php if (!empty($externalProducts)): ?>
                <div class="mb-3 p-3 bg-light rounded">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-bold">القيمة الإجمالية للمنتجات الخارجية:</span>
                        <span class="text-success fw-bold fs-5"><?php echo formatCurrency($totalExternalValue); ?></span>
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="table-responsive dashboard-table-wrapper">
                <table class="table dashboard-table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>اسم المنتج</th>
                            <th>الكمية</th>
                            <th>سعر الوحدة</th>
                            <th>الإجمالي</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($externalProducts)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">لا توجد منتجات خارجية. قم بإضافة منتج جديد باستخدام الزر أعلاه.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($externalProducts as $product): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($product['name']); ?></strong></td>
                                    <td><?php echo number_format((float)$product['quantity'], 2); ?> <?php echo htmlspecialchars($product['unit'] ?? 'قطعة'); ?></td>
                                    <td><?php echo formatCurrency((float)$product['unit_price']); ?></td>
                                    <td><strong class="text-primary"><?php echo formatCurrency((float)$product['total_value']); ?></strong></td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button type="button" class="btn btn-outline-primary js-edit-external" 
                                                    data-id="<?php echo $product['id']; ?>"
                                                    data-name="<?php echo htmlspecialchars($product['name'], ENT_QUOTES); ?>"
                                                    data-quantity="<?php echo $product['quantity']; ?>"
                                                    data-unit="<?php echo htmlspecialchars($product['unit'] ?? 'قطعة', ENT_QUOTES); ?>"
                                                    data-price="<?php echo $product['unit_price']; ?>">
                                                <i class="bi bi-pencil"></i> تعديل
                                            </button>
                                            <button type="button" class="btn btn-outline-danger js-delete-external" 
                                                    data-id="<?php echo $product['id']; ?>"
                                                    data-name="<?php echo htmlspecialchars($product['name'], ENT_QUOTES); ?>">
                                                <i class="bi bi-trash"></i> حذف
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal إضافة منتج خارجي -->
<div class="modal fade" id="addExternalProductModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>إضافة منتج خارجي جديد</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="create_external_product">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">اسم المنتج <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="product_name" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">الكمية</label>
                            <input type="number" step="0.01" class="form-control" name="quantity" value="0" min="0">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">الوحدة</label>
                            <input type="text" class="form-control" name="unit" value="قطعة">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">سعر الوحدة</label>
                        <input type="number" step="0.01" class="form-control" name="unit_price" value="0" min="0">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary-custom">حفظ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal تعديل منتج خارجي -->
<div class="modal fade" id="editExternalProductModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>تعديل منتج خارجي</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_external_product">
                <input type="hidden" name="product_id" id="edit_product_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">اسم المنتج <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="product_name" id="edit_product_name" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">الكمية</label>
                            <input type="number" step="0.01" class="form-control" name="quantity" id="edit_quantity" min="0">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">الوحدة</label>
                            <input type="text" class="form-control" name="unit" id="edit_unit">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">سعر الوحدة</label>
                        <input type="number" step="0.01" class="form-control" name="unit_price" id="edit_unit_price" min="0">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-primary-custom">حفظ التغييرات</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal حذف منتج خارجي -->
<div class="modal fade" id="deleteExternalProductModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>تأكيد الحذف</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="delete_external_product">
                <input type="hidden" name="product_id" id="delete_product_id">
                <div class="modal-body">
                    <p>هل أنت متأكد من حذف المنتج <strong id="delete_product_name"></strong>؟</p>
                    <p class="text-danger mb-0"><small>لا يمكن التراجع عن هذه العملية.</small></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-danger">حذف</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// معالجة تعديل المنتجات الخارجية
document.querySelectorAll('.js-edit-external').forEach(btn => {
    btn.addEventListener('click', function() {
        const id = this.dataset.id;
        const name = this.dataset.name;
        const quantity = this.dataset.quantity;
        const unit = this.dataset.unit;
        const price = this.dataset.price;
        
        document.getElementById('edit_product_id').value = id;
        document.getElementById('edit_product_name').value = name;
        document.getElementById('edit_quantity').value = quantity;
        document.getElementById('edit_unit').value = unit;
        document.getElementById('edit_unit_price').value = price;
        
        new bootstrap.Modal(document.getElementById('editExternalProductModal')).show();
    });
});

// معالجة حذف المنتجات الخارجية
document.querySelectorAll('.js-delete-external').forEach(btn => {
    btn.addEventListener('click', function() {
        const id = this.dataset.id;
        const name = this.dataset.name;
        
        document.getElementById('delete_product_id').value = id;
        document.getElementById('delete_product_name').textContent = name;
        
        new bootstrap.Modal(document.getElementById('deleteExternalProductModal')).show();
    });
});
</script>

