<?php
/**
 * صفحة إدارة طلبات السلفة للمحاسب
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/salary_calculator.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/audit_log.php';

requireRole('accountant');

$currentUser = getCurrentUser();
$db = db();
$error = '';
$success = '';

// التحقق من وجود جدول advance_requests
$tableCheck = $db->queryOne("SHOW TABLES LIKE 'advance_requests'");
if (empty($tableCheck)) {
    // إنشاء الجدول تلقائياً
    try {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `advance_requests` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `user_id` int(11) NOT NULL,
              `amount` decimal(15,2) NOT NULL,
              `requested_month` int(2) NOT NULL,
              `requested_year` int(4) NOT NULL,
              `salary_id` int(11) DEFAULT NULL,
              `reason` text DEFAULT NULL,
              `status` enum('pending','approved','rejected') DEFAULT 'pending',
              `approved_by` int(11) DEFAULT NULL,
              `approved_at` timestamp NULL DEFAULT NULL,
              `rejection_reason` text DEFAULT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `user_id` (`user_id`),
              KEY `salary_id` (`salary_id`),
              KEY `approved_by` (`approved_by`),
              KEY `status` (`status`),
              KEY `requested_month_year` (`requested_month`, `requested_year`),
              CONSTRAINT `advance_requests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
              CONSTRAINT `advance_requests_ibfk_2` FOREIGN KEY (`salary_id`) REFERENCES `salaries` (`id`) ON DELETE SET NULL,
              CONSTRAINT `advance_requests_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) {
        error_log("Error creating advance_requests table: " . $e->getMessage());
    }
}

// معالجة الموافقة/الرفض
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $requestId = intval($_POST['request_id'] ?? 0);
    
    if ($action === 'approve' && $requestId > 0) {
        $request = $db->queryOne("SELECT * FROM advance_requests WHERE id = ?", [$requestId]);
        
        if (!$request) {
            $error = 'طلب السلفة غير موجود';
        } elseif ($request['status'] !== 'pending') {
            $error = 'تمت معالجة هذا الطلب بالفعل';
        } else {
            // الموافقة على الطلب
            $db->execute(
                "UPDATE advance_requests 
                 SET status = 'approved', approved_by = ?, approved_at = NOW() 
                 WHERE id = ?",
                [$currentUser['id'], $requestId]
            );
            
            // إرسال إشعار للمستخدم
            createNotification(
                $request['user_id'],
                'موافقة على طلب السلفة',
                'تم الموافقة على طلب السلفة بقيمة ' . formatCurrency($request['amount']),
                'success',
                null,
                false
            );
            
            logAudit($currentUser['id'], 'approve_advance', 'advance_request', $requestId, null, [
                'user_id' => $request['user_id'],
                'amount' => $request['amount']
            ]);
            
            $success = 'تم الموافقة على طلب السلفة بنجاح';
        }
        
    } elseif ($action === 'reject' && $requestId > 0) {
        $rejectionReason = trim($_POST['rejection_reason'] ?? '');
        
        if (empty($rejectionReason)) {
            $error = 'يجب إدخال سبب الرفض';
        } else {
            $request = $db->queryOne("SELECT * FROM advance_requests WHERE id = ?", [$requestId]);
            
            if (!$request) {
                $error = 'طلب السلفة غير موجود';
            } elseif ($request['status'] !== 'pending') {
                $error = 'تمت معالجة هذا الطلب بالفعل';
            } else {
                // رفض الطلب
                $db->execute(
                    "UPDATE advance_requests 
                     SET status = 'rejected', approved_by = ?, approved_at = NOW(), rejection_reason = ? 
                     WHERE id = ?",
                    [$currentUser['id'], $rejectionReason, $requestId]
                );
                
                // إرسال إشعار للمستخدم
                createNotification(
                    $request['user_id'],
                    'رفض طلب السلفة',
                    'تم رفض طلب السلفة بقيمة ' . formatCurrency($request['amount']) . '. السبب: ' . $rejectionReason,
                    'danger',
                    null,
                    false
                );
                
                logAudit($currentUser['id'], 'reject_advance', 'advance_request', $requestId, null, [
                    'user_id' => $request['user_id'],
                    'amount' => $request['amount'],
                    'reason' => $rejectionReason
                ]);
                
                $success = 'تم رفض طلب السلفة';
            }
        }
    }
}

// الفلترة
$statusFilter = $_GET['status'] ?? 'pending';
$monthFilter = isset($_GET['month']) ? intval($_GET['month']) : 0;
$yearFilter = isset($_GET['year']) ? intval($_GET['year']) : 0;

// بناء استعلام
$whereConditions = [];
$params = [];

if ($statusFilter && $statusFilter !== 'all') {
    $whereConditions[] = "ar.status = ?";
    $params[] = $statusFilter;
}

if ($monthFilter > 0) {
    $whereConditions[] = "ar.requested_month = ?";
    $params[] = $monthFilter;
}

if ($yearFilter > 0) {
    $whereConditions[] = "ar.requested_year = ?";
    $params[] = $yearFilter;
}

$whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

// الحصول على طلبات السلفة
$advanceRequests = $db->query(
    "SELECT ar.*, u.full_name, u.username, u.role, 
            s.total_amount as salary_amount,
            approver.full_name as approver_name
     FROM advance_requests ar
     LEFT JOIN users u ON ar.user_id = u.id
     LEFT JOIN salaries s ON ar.salary_id = s.id
     LEFT JOIN users approver ON ar.approved_by = approver.id
     $whereClause
     ORDER BY ar.created_at DESC",
    $params
);

// إحصائيات
$stats = [
    'pending' => $db->queryOne("SELECT COUNT(*) as count FROM advance_requests WHERE status = 'pending'")['count'] ?? 0,
    'approved' => $db->queryOne("SELECT COUNT(*) as count FROM advance_requests WHERE status = 'approved'")['count'] ?? 0,
    'rejected' => $db->queryOne("SELECT COUNT(*) as count FROM advance_requests WHERE status = 'rejected'")['count'] ?? 0,
    'total_amount_pending' => $db->queryOne("SELECT COALESCE(SUM(amount), 0) as total FROM advance_requests WHERE status = 'pending'")['total'] ?? 0
];
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-cash-coin me-2"></i>طلبات السلفة</h2>
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

<!-- إحصائيات -->
<div class="row mb-4">
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0">
                        <div class="stat-card-icon warning">
                            <i class="bi bi-hourglass-split"></i>
                        </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <div class="text-muted small">طلبات معلقة</div>
                        <div class="h4 mb-0"><?php echo $stats['pending']; ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0">
                        <div class="stat-card-icon success">
                            <i class="bi bi-check-circle"></i>
                        </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <div class="text-muted small">طلبات موافق عليها</div>
                        <div class="h4 mb-0"><?php echo $stats['approved']; ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0">
                        <div class="stat-card-icon danger">
                            <i class="bi bi-x-circle"></i>
                        </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <div class="text-muted small">طلبات مرفوضة</div>
                        <div class="h4 mb-0"><?php echo $stats['rejected']; ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0">
                        <div class="stat-card-icon info">
                            <i class="bi bi-currency-dollar"></i>
                        </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <div class="text-muted small">إجمالي المعلقة</div>
                        <div class="h4 mb-0"><?php echo formatCurrency($stats['total_amount_pending']); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- فلترة -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">الحالة</label>
                <select class="form-select" name="status" onchange="this.form.submit()">
                    <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>الكل</option>
                    <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>معلق</option>
                    <option value="approved" <?php echo $statusFilter === 'approved' ? 'selected' : ''; ?>>موافق عليه</option>
                    <option value="rejected" <?php echo $statusFilter === 'rejected' ? 'selected' : ''; ?>>مرفوض</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">الشهر</label>
                <select class="form-select" name="month" onchange="this.form.submit()">
                    <option value="0">الكل</option>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?php echo $m; ?>" <?php echo $monthFilter == $m ? 'selected' : ''; ?>>
                            <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">السنة</label>
                <select class="form-select" name="year" onchange="this.form.submit()">
                    <option value="0">الكل</option>
                    <?php for ($y = date('Y'); $y >= date('Y') - 2; $y--): ?>
                        <option value="<?php echo $y; ?>" <?php echo $yearFilter == $y ? 'selected' : ''; ?>>
                            <?php echo $y; ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
        </form>
    </div>
</div>

<!-- قائمة طلبات السلفة -->
<div class="card shadow-sm">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">قائمة طلبات السلفة</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>المستخدم</th>
                        <th>التاريخ</th>
                        <th>الشهر المطلوب</th>
                        <th>المبلغ</th>
                        <th>الراتب الحالي</th>
                        <th>السبب</th>
                        <th>الحالة</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($advanceRequests)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted">لا توجد طلبات سلفة</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($advanceRequests as $request): ?>
                            <tr>
                                <td data-label="المستخدم">
                                    <strong><?php echo htmlspecialchars($request['full_name'] ?? $request['username']); ?></strong>
                                    <br><small class="text-muted"><?php echo $request['role']; ?></small>
                                </td>
                                <td data-label="التاريخ"><?php echo formatDateTime($request['created_at']); ?></td>
                                <td data-label="الشهر المطلوب">
                                    <?php echo date('F', mktime(0, 0, 0, $request['requested_month'], 1)); ?> 
                                    <?php echo $request['requested_year']; ?>
                                </td>
                                <td data-label="المبلغ">
                                    <strong class="text-warning"><?php echo formatCurrency($request['amount']); ?></strong>
                                </td>
                                <td data-label="الراتب الحالي">
                                    <?php echo $request['salary_amount'] ? formatCurrency($request['salary_amount']) : '-'; ?>
                                </td>
                                <td data-label="السبب">
                                    <?php echo htmlspecialchars($request['reason'] ?? '-'); ?>
                                </td>
                                <td data-label="الحالة">
                                    <span class="badge bg-<?php 
                                        echo $request['status'] === 'approved' ? 'success' : 
                                            ($request['status'] === 'rejected' ? 'danger' : 'warning'); 
                                    ?>">
                                        <?php 
                                        $statusLabels = [
                                            'pending' => 'قيد المراجعة',
                                            'approved' => 'موافق عليه',
                                            'rejected' => 'مرفوض'
                                        ];
                                        echo $statusLabels[$request['status']] ?? $request['status']; 
                                        ?>
                                    </span>
                                    <?php if ($request['approved_by']): ?>
                                        <br><small class="text-muted">بواسطة: <?php echo htmlspecialchars($request['approver_name'] ?? '-'); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td data-label="الإجراءات">
                                    <?php if ($request['status'] === 'pending'): ?>
                                        <div class="btn-group btn-group-sm">
                                            <button type="button" class="btn btn-success" 
                                                    onclick="approveRequest(<?php echo $request['id']; ?>)">
                                                <i class="bi bi-check-circle"></i> موافقة
                                            </button>
                                            <button type="button" class="btn btn-danger" 
                                                    onclick="rejectRequest(<?php echo $request['id']; ?>)">
                                                <i class="bi bi-x-circle"></i> رفض
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal الموافقة -->
<div class="modal fade" id="approveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">الموافقة على طلب السلفة</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="approveForm">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="request_id" id="approveRequestId">
                <div class="modal-body">
                    <p>هل أنت متأكد من الموافقة على هذا الطلب؟</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-success">موافقة</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal الرفض -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">رفض طلب السلفة</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="rejectForm">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="request_id" id="rejectRequestId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="rejection_reason" class="form-label">سبب الرفض <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="rejection_reason" name="rejection_reason" rows="3" required 
                                  placeholder="اذكر سبب رفض الطلب"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-danger">رفض</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function approveRequest(requestId) {
    document.getElementById('approveRequestId').value = requestId;
    const modal = new bootstrap.Modal(document.getElementById('approveModal'));
    modal.show();
}

function rejectRequest(requestId) {
    document.getElementById('rejectRequestId').value = requestId;
    const modal = new bootstrap.Modal(document.getElementById('rejectModal'));
    modal.show();
}
</script>

