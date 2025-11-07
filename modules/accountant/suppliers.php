<?php
/**
 * صفحة إدارة الموردين
 * Suppliers Management Page
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit_log.php';
require_once __DIR__ . '/../../includes/path_helper.php';

requireRole(['accountant', 'manager']);

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';

// إنشاء/تحديث جدول suppliers لإضافة supplier_code و type
try {
    $supplierCodeCheck = $db->queryOne("SHOW COLUMNS FROM suppliers LIKE 'supplier_code'");
    if (empty($supplierCodeCheck)) {
        $db->execute("ALTER TABLE suppliers ADD COLUMN supplier_code VARCHAR(20) NULL AFTER id");
        $db->execute("ALTER TABLE suppliers ADD UNIQUE KEY supplier_code (supplier_code)");
    }
    
    $supplierTypeCheck = $db->queryOne("SHOW COLUMNS FROM suppliers LIKE 'type'");
    if (empty($supplierTypeCheck)) {
        $db->execute("ALTER TABLE suppliers ADD COLUMN type ENUM('honey', 'packaging', 'nuts', 'olive_oil', 'derivatives', 'beeswax') NULL DEFAULT NULL AFTER supplier_code");
    }
    
    // توليد كود للموردين الموجودين الذين لا يملكون كود
    $suppliersWithoutCode = $db->query("SELECT id, type FROM suppliers WHERE supplier_code IS NULL OR supplier_code = ''");
    foreach ($suppliersWithoutCode as $supplier) {
        if ($supplier['type']) {
            $supplierCode = generateSupplierCode($supplier['type'], $db);
            $db->execute("UPDATE suppliers SET supplier_code = ? WHERE id = ?", [$supplierCode, $supplier['id']]);
        }
    }
} catch (Exception $e) {
    error_log("Error updating suppliers table: " . $e->getMessage());
}

/**
 * دالة لتوليد كود مورد فريد بناءً على نوع المورد
 */
function generateSupplierCode($type, $db) {
    // رموز أنواع الموردين
    $typeCodes = [
        'honey' => 'HNY',       // مورد عسل
        'packaging' => 'PKG',   // أدوات تعبئة
        'nuts' => 'NUT',        // مكسرات
        'olive_oil' => 'OIL',   // زيت زيتون
        'derivatives' => 'DRV', // مشتقات
        'beeswax' => 'WAX'      // شمع عسل
    ];
    
    $prefix = $typeCodes[$type] ?? 'SUP';
    
    // البحث عن آخر رقم تسلسلي لهذا النوع
    $lastCode = $db->queryOne(
        "SELECT supplier_code FROM suppliers 
         WHERE type = ? AND supplier_code LIKE ? 
         ORDER BY supplier_code DESC 
         LIMIT 1",
        [$type, $prefix . '%']
    );
    
    $sequence = 1;
    if ($lastCode) {
        // استخراج الرقم التسلسلي من الكود
        $lastSequence = intval(substr($lastCode['supplier_code'], strlen($prefix)));
        $sequence = $lastSequence + 1;
    }
    
    // كود بثلاثة أرقام (001, 002, ...)
    $code = $prefix . str_pad($sequence, 3, '0', STR_PAD_LEFT);
    
    // التأكد من عدم التكرار
    $counter = 0;
    while ($db->queryOne("SELECT id FROM suppliers WHERE supplier_code = ?", [$code])) {
        $counter++;
        $sequence++;
        $code = $prefix . str_pad($sequence, 3, '0', STR_PAD_LEFT);
        
        if ($counter > 999) {
            // إذا فشل 999 مرة، أضف رقم عشوائي
            $code = $prefix . str_pad($sequence, 3, '0', STR_PAD_LEFT) . '-' . rand(100, 999);
            break;
        }
    }
    
    return $code;
}

// Pagination
$pageNum = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$perPage = 20;
$offset = ($pageNum - 1) * $perPage;

// Search and Filter
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';

// Build query
$whereConditions = [];
$params = [];

if (!empty($search)) {
    $whereConditions[] = "(name LIKE ? OR contact_person LIKE ? OR phone LIKE ? OR email LIKE ?)";
    $searchParam = "%$search%";
    $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam]);
}

if (!empty($statusFilter)) {
    $whereConditions[] = "status = ?";
    $params[] = $statusFilter;
}

$whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

// Get total count
$totalCountQuery = "SELECT COUNT(*) as total FROM suppliers $whereClause";
$totalCount = $db->queryOne($totalCountQuery, $params)['total'] ?? 0;
$totalPages = ceil($totalCount / $perPage);

// Get suppliers
$suppliersQuery = "SELECT * FROM suppliers $whereClause ORDER BY created_at DESC LIMIT ? OFFSET ?";
$queryParams = array_merge($params, [$perPage, $offset]);
$suppliers = $db->query($suppliersQuery, $queryParams);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $type = $_POST['type'] ?? null;
        $contact_person = trim($_POST['contact_person'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $status = $_POST['status'] ?? 'active';
        
        if (empty($name)) {
            $error = 'اسم المورد مطلوب';
        } elseif (empty($type)) {
            $error = 'نوع المورد مطلوب';
        } else {
            try {
                // توليد كود المورد تلقائياً
                $supplierCode = generateSupplierCode($type, $db);
                
                $db->execute(
                    "INSERT INTO suppliers (supplier_code, type, name, contact_person, phone, email, address, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    [$supplierCode, $type, $name, $contact_person ?: null, $phone ?: null, $email ?: null, $address ?: null, $status]
                );
                $success = 'تم إضافة المورد بنجاح - كود المورد: ' . $supplierCode;
                // Redirect to same page with success message
                if (!headers_sent()) {
                    header('Location: ?page=suppliers&success=' . urlencode($success));
                    exit;
                } else {
                    echo '<script>window.location.href = "?page=suppliers&success=' . urlencode($success) . '";</script>';
                    exit;
                }
            } catch (Exception $e) {
                $error = 'حدث خطأ: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'edit') {
        $id = intval($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $type = $_POST['type'] ?? null;
        $contact_person = trim($_POST['contact_person'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $status = $_POST['status'] ?? 'active';
        
        if (empty($name)) {
            $error = 'اسم المورد مطلوب';
        } elseif (empty($type)) {
            $error = 'نوع المورد مطلوب';
        } else {
            try {
                // الحصول على المورد الحالي
                $currentSupplier = $db->queryOne("SELECT type, supplier_code FROM suppliers WHERE id = ?", [$id]);
                
                // إذا تغير نوع المورد، توليد كود جديد
                $supplierCode = $currentSupplier['supplier_code'] ?? null;
                if ($currentSupplier && $currentSupplier['type'] !== $type) {
                    $supplierCode = generateSupplierCode($type, $db);
                }
                
                $db->execute(
                    "UPDATE suppliers SET supplier_code = ?, type = ?, name = ?, contact_person = ?, phone = ?, email = ?, address = ?, status = ?, updated_at = NOW() WHERE id = ?",
                    [$supplierCode, $type, $name, $contact_person ?: null, $phone ?: null, $email ?: null, $address ?: null, $status, $id]
                );
                $success = 'تم تحديث المورد بنجاح';
                // Redirect to same page with success message
                if (!headers_sent()) {
                    header('Location: ?page=suppliers&success=' . urlencode($success));
                    exit;
                } else {
                    echo '<script>window.location.href = "?page=suppliers&success=' . urlencode($success) . '";</script>';
                    exit;
                }
            } catch (Exception $e) {
                $error = 'حدث خطأ: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                $db->execute("DELETE FROM suppliers WHERE id = ?", [$id]);
                $success = 'تم حذف المورد بنجاح';
                // Redirect to same page with success message
                if (!headers_sent()) {
                    header('Location: ?page=suppliers&success=' . urlencode($success));
                    exit;
                } else {
                    echo '<script>window.location.href = "?page=suppliers&success=' . urlencode($success) . '";</script>';
                    exit;
                }
            } catch (Exception $e) {
                $error = 'حدث خطأ: ' . $e->getMessage();
            }
        }
    }
}

// Get success message from URL
if (isset($_GET['success'])) {
    $success = $_GET['success'];
}

// Get supplier for editing
$editSupplier = null;
if (isset($_GET['edit'])) {
    $editId = intval($_GET['edit']);
    $editSupplier = $db->queryOne("SELECT * FROM suppliers WHERE id = ?", [$editId]);
}
?>

<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-truck me-2"></i><?php echo (isset($lang) && isset($lang['suppliers'])) ? $lang['suppliers'] : 'الموردين'; ?> (<?php echo $totalCount; ?>)</h5>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSupplierModal">
            <i class="bi bi-plus-circle me-2"></i><?php echo isset($lang['add']) ? $lang['add'] : 'إضافة'; ?>
        </button>
    </div>
    <div class="card-body">
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Search and Filter -->
        <?php
        require_once __DIR__ . '/../../includes/path_helper.php';
        $currentUrl = getRelativeUrl('dashboard/accountant.php');
        ?>
        <form method="GET" action="<?php echo htmlspecialchars($currentUrl); ?>" class="mb-4">
            <input type="hidden" name="page" value="suppliers">
            <div class="row g-3">
                <div class="col-md-6">
                    <input type="text" class="form-control" name="search" placeholder="<?php echo isset($lang['search']) ? $lang['search'] : 'بحث...'; ?>" value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-4">
                    <select class="form-select" name="status">
                        <option value=""><?php echo isset($lang['all']) ? $lang['all'] : 'جميع الحالات'; ?></option>
                        <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>><?php echo isset($lang['active']) ? $lang['active'] : 'نشط'; ?></option>
                        <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>><?php echo isset($lang['inactive']) ? $lang['inactive'] : 'غير نشط'; ?></option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-outline w-100">
                        <i class="bi bi-search me-2"></i><?php echo isset($lang['filter']) ? $lang['filter'] : 'تصفية'; ?>
                    </button>
                </div>
            </div>
        </form>
        
        <!-- Suppliers Table -->
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>كود المورد</th>
                        <th><?php echo isset($lang['supplier_name']) ? $lang['supplier_name'] : 'اسم المورد'; ?></th>
                        <th>نوع المورد</th>
                        <th><?php echo isset($lang['contact_person']) ? $lang['contact_person'] : 'جهة الاتصال'; ?></th>
                        <th><?php echo isset($lang['phone']) ? $lang['phone'] : 'الهاتف'; ?></th>
                        <th><?php echo isset($lang['email']) ? $lang['email'] : 'البريد'; ?></th>
                        <th><?php echo isset($lang['balance']) ? $lang['balance'] : 'الرصيد'; ?></th>
                        <th><?php echo isset($lang['status']) ? $lang['status'] : 'الحالة'; ?></th>
                        <th><?php echo isset($lang['actions']) ? $lang['actions'] : 'الإجراءات'; ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($suppliers)): ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted py-4">
                                <i class="bi bi-inbox me-2"></i><?php echo isset($lang['no_data']) ? $lang['no_data'] : 'لا توجد بيانات'; ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php 
                        $typeLabels = [
                            'honey' => 'مورد عسل',
                            'packaging' => 'أدوات تعبئة',
                            'nuts' => 'مكسرات',
                            'olive_oil' => 'زيت زيتون',
                            'derivatives' => 'مشتقات',
                            'beeswax' => 'شمع عسل'
                        ];
                        foreach ($suppliers as $index => $supplier): ?>
                            <tr>
                                <td data-label="#"><?php echo $offset + $index + 1; ?></td>
                                <td data-label="كود المورد">
                                    <span class="badge bg-primary"><?php echo htmlspecialchars($supplier['supplier_code'] ?? '-'); ?></span>
                                </td>
                                <td data-label="الاسم"><strong><?php echo htmlspecialchars($supplier['name']); ?></strong></td>
                                <td data-label="نوع المورد">
                                    <span class="badge bg-info"><?php echo htmlspecialchars($typeLabels[$supplier['type'] ?? ''] ?? '-'); ?></span>
                                </td>
                                <td data-label="جهة الاتصال"><?php echo htmlspecialchars($supplier['contact_person'] ?? '-'); ?></td>
                                <td data-label="الهاتف"><?php echo htmlspecialchars($supplier['phone'] ?? '-'); ?></td>
                                <td data-label="البريد"><?php echo htmlspecialchars($supplier['email'] ?? '-'); ?></td>
                                <td data-label="الرصيد">
                                    <?php 
                                    // تنظيف شامل للرصيد قبل العرض باستخدام دالة cleanFinancialValue
                                    $balance = cleanFinancialValue($supplier['balance'] ?? 0);
                                    ?>
                                    <span class="badge <?php echo $balance >= 0 ? 'bg-success' : 'bg-danger'; ?>">
                                        <?php echo formatCurrency($balance); ?>
                                    </span>
                                </td>
                                <td data-label="الحالة">
                                    <span class="badge bg-<?php echo $supplier['status'] === 'active' ? 'success' : 'secondary'; ?>">
                                        <?php echo isset($lang[$supplier['status']]) ? $lang[$supplier['status']] : $supplier['status']; ?>
                                    </span>
                                </td>
                                <td data-label="الإجراءات">
                                    <div class="btn-group btn-group-sm">
                                        <a href="?page=suppliers&edit=<?php echo $supplier['id']; ?>" class="btn btn-outline" data-bs-toggle="tooltip" title="<?php echo isset($lang['edit']) ? $lang['edit'] : 'تعديل'; ?>">
                                            <i class="bi bi-pencil"></i>
                                            <span class="d-none d-md-inline"><?php echo isset($lang['edit']) ? $lang['edit'] : 'تعديل'; ?></span>
                                        </a>
                                        <button type="button" class="btn btn-outline-danger" onclick="deleteSupplier(<?php echo $supplier['id']; ?>, '<?php echo htmlspecialchars($supplier['name'], ENT_QUOTES); ?>')" data-bs-toggle="tooltip" title="<?php echo isset($lang['delete']) ? $lang['delete'] : 'حذف'; ?>">
                                            <i class="bi bi-trash"></i>
                                            <span class="d-none d-md-inline"><?php echo isset($lang['delete']) ? $lang['delete'] : 'حذف'; ?></span>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <nav aria-label="Page navigation" class="mt-3">
            <ul class="pagination justify-content-center flex-wrap">
                <li class="page-item <?php echo $pageNum <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=suppliers&p=<?php echo $pageNum - 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $statusFilter ? '&status=' . urlencode($statusFilter) : ''; ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
                
                <?php
                $startPage = max(1, $pageNum - 2);
                $endPage = min($totalPages, $pageNum + 2);
                
                if ($startPage > 1): ?>
                    <li class="page-item"><a class="page-link" href="?page=suppliers&p=1<?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $statusFilter ? '&status=' . urlencode($statusFilter) : ''; ?>">1</a></li>
                    <?php if ($startPage > 2): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <li class="page-item <?php echo $i == $pageNum ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=suppliers&p=<?php echo $i; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $statusFilter ? '&status=' . urlencode($statusFilter) : ''; ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                    <li class="page-item"><a class="page-link" href="?page=suppliers&p=<?php echo $totalPages; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $statusFilter ? '&status=' . urlencode($statusFilter) : ''; ?>"><?php echo $totalPages; ?></a></li>
                <?php endif; ?>
                
                <li class="page-item <?php echo $pageNum >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=suppliers&p=<?php echo $pageNum + 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $statusFilter ? '&status=' . urlencode($statusFilter) : ''; ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<!-- Add Supplier Modal -->
<div class="modal fade" id="addSupplierModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i><?php echo isset($lang['add']) ? $lang['add'] : 'إضافة'; ?> <?php echo (isset($lang) && isset($lang['suppliers'])) ? $lang['suppliers'] : 'مورد'; ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label"><?php echo isset($lang['supplier_name']) ? $lang['supplier_name'] : 'اسم المورد'; ?> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">نوع المورد <span class="text-danger">*</span></label>
                        <select class="form-select" name="type" required id="supplier_type">
                            <option value="">اختر نوع المورد</option>
                            <option value="honey">مورد عسل</option>
                            <option value="packaging">أدوات تعبئة</option>
                            <option value="nuts">مكسرات</option>
                            <option value="olive_oil">زيت زيتون</option>
                            <option value="derivatives">مشتقات</option>
                            <option value="beeswax">شمع عسل</option>
                        </select>
                        <small class="text-muted">سيتم توليد كود المورد تلقائياً بناءً على النوع المختار</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo isset($lang['contact_person']) ? $lang['contact_person'] : 'جهة الاتصال'; ?></label>
                        <input type="text" class="form-control" name="contact_person">
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?php echo isset($lang['phone']) ? $lang['phone'] : 'الهاتف'; ?></label>
                            <input type="text" class="form-control" name="phone">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?php echo isset($lang['email']) ? $lang['email'] : 'البريد الإلكتروني'; ?></label>
                            <input type="email" class="form-control" name="email">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo isset($lang['address']) ? $lang['address'] : 'العنوان'; ?></label>
                        <textarea class="form-control" name="address" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo isset($lang['status']) ? $lang['status'] : 'الحالة'; ?></label>
                        <select class="form-select" name="status">
                            <option value="active"><?php echo isset($lang['active']) ? $lang['active'] : 'نشط'; ?></option>
                            <option value="inactive"><?php echo isset($lang['inactive']) ? $lang['inactive'] : 'غير نشط'; ?></option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" data-bs-dismiss="modal"><?php echo isset($lang['cancel']) ? $lang['cancel'] : 'إلغاء'; ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo isset($lang['save']) ? $lang['save'] : 'حفظ'; ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Supplier Modal -->
<?php if ($editSupplier): ?>
<div class="modal fade" id="editSupplierModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" value="<?php echo $editSupplier['id']; ?>">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i><?php echo isset($lang['edit']) ? $lang['edit'] : 'تعديل'; ?> <?php echo (isset($lang) && isset($lang['suppliers'])) ? $lang['suppliers'] : 'مورد'; ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">كود المورد</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($editSupplier['supplier_code'] ?? '-'); ?>" readonly>
                        <small class="text-muted">سيتم تحديث الكود تلقائياً إذا تم تغيير نوع المورد</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo isset($lang['supplier_name']) ? $lang['supplier_name'] : 'اسم المورد'; ?> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" value="<?php echo htmlspecialchars($editSupplier['name']); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">نوع المورد <span class="text-danger">*</span></label>
                        <select class="form-select" name="type" required id="edit_supplier_type">
                            <option value="">اختر نوع المورد</option>
                            <option value="honey" <?php echo ($editSupplier['type'] ?? '') === 'honey' ? 'selected' : ''; ?>>مورد عسل</option>
                            <option value="packaging" <?php echo ($editSupplier['type'] ?? '') === 'packaging' ? 'selected' : ''; ?>>أدوات تعبئة</option>
                            <option value="nuts" <?php echo ($editSupplier['type'] ?? '') === 'nuts' ? 'selected' : ''; ?>>مكسرات</option>
                            <option value="olive_oil" <?php echo ($editSupplier['type'] ?? '') === 'olive_oil' ? 'selected' : ''; ?>>زيت زيتون</option>
                            <option value="derivatives" <?php echo ($editSupplier['type'] ?? '') === 'derivatives' ? 'selected' : ''; ?>>مشتقات</option>
                            <option value="beeswax" <?php echo ($editSupplier['type'] ?? '') === 'beeswax' ? 'selected' : ''; ?>>شمع عسل</option>
                        </select>
                        <small class="text-muted">سيتم توليد كود جديد إذا تم تغيير النوع</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo isset($lang['contact_person']) ? $lang['contact_person'] : 'جهة الاتصال'; ?></label>
                        <input type="text" class="form-control" name="contact_person" value="<?php echo htmlspecialchars($editSupplier['contact_person'] ?? ''); ?>">
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?php echo isset($lang['phone']) ? $lang['phone'] : 'الهاتف'; ?></label>
                            <input type="text" class="form-control" name="phone" value="<?php echo htmlspecialchars($editSupplier['phone'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?php echo isset($lang['email']) ? $lang['email'] : 'البريد الإلكتروني'; ?></label>
                            <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($editSupplier['email'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo isset($lang['address']) ? $lang['address'] : 'العنوان'; ?></label>
                        <textarea class="form-control" name="address" rows="3"><?php echo htmlspecialchars($editSupplier['address'] ?? ''); ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo isset($lang['status']) ? $lang['status'] : 'الحالة'; ?></label>
                        <select class="form-select" name="status">
                            <option value="active" <?php echo $editSupplier['status'] === 'active' ? 'selected' : ''; ?>><?php echo isset($lang['active']) ? $lang['active'] : 'نشط'; ?></option>
                            <option value="inactive" <?php echo $editSupplier['status'] === 'inactive' ? 'selected' : ''; ?>><?php echo isset($lang['inactive']) ? $lang['inactive'] : 'غير نشط'; ?></option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline" data-bs-dismiss="modal"><?php echo isset($lang['cancel']) ? $lang['cancel'] : 'إلغاء'; ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo isset($lang['save']) ? $lang['save'] : 'حفظ'; ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function deleteSupplier(id, name) {
    if (confirm('<?php echo isset($lang['confirm_delete']) ? $lang['confirm_delete'] : 'هل أنت متأكد من حذف'; ?> "' + name + '"؟')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="${id}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Initialize tooltips and show edit modal
document.addEventListener('DOMContentLoaded', function() {
    // Initialize tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Show edit modal if supplier is being edited
    <?php if ($editSupplier): ?>
    const editModal = new bootstrap.Modal(document.getElementById('editSupplierModal'));
    editModal.show();
    <?php endif; ?>
});
</script>

