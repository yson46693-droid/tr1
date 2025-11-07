<?php
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/consumption_reports.php';

requireRole('manager');

$successMessage = $_SESSION['success_message'] ?? '';
$errorMessage = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$todaySummary = getConsumptionSummary($today, $today);
$monthSummary = getConsumptionSummary($monthStart, $today);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($token)) {
        $_SESSION['error_message'] = 'رمز الأمان غير صالح. يرجى إعادة المحاولة.';
    } else {
        $scope = $_POST['report_scope'] ?? 'daily';
        if ($scope === 'daily') {
            $result = sendConsumptionReport($today, $today, 'التقرير اليومي');
        } elseif ($scope === 'monthly') {
            $result = sendConsumptionReport($monthStart, $today, 'تقرير الشهر الحالي');
        } else {
            $result = ['success' => false, 'message' => 'نوع التقرير غير معروف.'];
        }
        if ($result['success']) {
            $_SESSION['success_message'] = $result['message'] ?? 'تم إرسال التقرير.';
        } else {
            $_SESSION['error_message'] = $result['message'] ?? 'تعذر إنشاء التقرير.';
        }
    }
    preventDuplicateSubmission(null, ['page' => 'production_reports'], null, 'manager');
}

function renderConsumptionTable($items, $includeCategory = false)
{
    if (empty($items)) {
        echo '<div class="table-responsive"><div class="text-center text-muted py-4">لا توجد بيانات</div></div>';
        return;
    }
    echo '<div class="table-responsive">';
    echo '<table class="table table-hover align-middle">';
    echo '<thead class="table-light"><tr>';
    echo '<th>المادة</th>';
    if ($includeCategory) {
        echo '<th>الفئة</th>';
    }
    echo '<th>الاستهلاك</th><th>الوارد</th><th>الصافي</th><th>الحركات</th>';
    echo '</tr></thead><tbody>';
    foreach ($items as $item) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($item['name']) . '</td>';
        if ($includeCategory) {
            echo '<td><span class="badge bg-secondary">' . htmlspecialchars($item['sub_category']) . '</span></td>';
        }
        echo '<td>' . number_format($item['total_out'], 3) . '</td>';
        echo '<td>' . number_format($item['total_in'], 3) . '</td>';
        echo '<td>' . number_format($item['net'], 3) . '</td>';
        echo '<td>' . intval($item['movements']) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

$csrfToken = generateCSRFToken();

?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1"><i class="bi bi-graph-up-arrow me-2"></i>تقارير الإنتاج</h2>
        <p class="text-muted mb-0">متابعة استهلاك أدوات التعبئة والمواد الخام</p>
    </div>
    <div class="d-flex gap-2">
        <form method="post" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="report_scope" value="daily">
            <button class="btn btn-primary">
                <i class="bi bi-send-check me-1"></i>إرسال تقرير اليوم
            </button>
        </form>
        <form method="post" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="report_scope" value="monthly">
            <button class="btn btn-outline-primary">
                <i class="bi bi-send-fill me-1"></i>إرسال تقرير الشهر
            </button>
        </form>
    </div>
</div>

<?php if ($errorMessage): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($errorMessage); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($successMessage): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($successMessage); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php
function renderSummaryCards($label, $summary)
{
    echo '<div class="card mb-4 shadow-sm"><div class="card-body">';
    echo '<div class="d-flex justify-content-between align-items-center mb-3">';
    echo '<div><h4 class="mb-1">' . htmlspecialchars($label) . '</h4><span class="text-muted">' . htmlspecialchars($summary['date_from']) . ' &mdash; ' . htmlspecialchars($summary['date_to']) . '</span></div>';
    echo '<span class="badge bg-primary-subtle text-primary">آخر تحديث: ' . htmlspecialchars($summary['generated_at']) . '</span>';
    echo '</div>';
    echo '<div class="row g-3">';
    echo '<div class="col-md-3"><div class="border rounded-3 p-3 h-100"><div class="text-muted small mb-1">استهلاك أدوات التعبئة</div><div class="fs-4 fw-semibold text-primary">' . number_format($summary['packaging']['total_out'], 3) . '</div></div></div>';
    echo '<div class="col-md-3"><div class="border rounded-3 p-3 h-100"><div class="text-muted small mb-1">استهلاك المواد الخام</div><div class="fs-4 fw-semibold text-primary">' . number_format($summary['raw']['total_out'], 3) . '</div></div></div>';
    echo '<div class="col-md-3"><div class="border rounded-3 p-3 h-100"><div class="text-muted small mb-1">الصافي الكلي</div><div class="fs-4 fw-semibold text-success">' . number_format($summary['packaging']['net'] + $summary['raw']['net'], 3) . '</div></div></div>';
    echo '<div class="col-md-3"><div class="border rounded-3 p-3 h-100"><div class="text-muted small mb-1">إجمالي الحركات</div><div class="fs-4 fw-semibold text-secondary">' . number_format(array_sum(array_column($summary['packaging']['items'], 'movements')) + array_sum(array_column($summary['raw']['items'], 'movements'))) . '</div></div></div>';
    echo '</div>';
    echo '</div></div>';
}

renderSummaryCards('تقرير اليوم', $todaySummary);
?>

<div class="card mb-4 shadow-sm">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <span><i class="bi bi-box-seam me-2"></i>أدوات التعبئة المستهلكة اليوم</span>
    </div>
    <div class="card-body">
        <?php renderConsumptionTable($todaySummary['packaging']['items']); ?>
    </div>
</div>

<div class="card mb-5 shadow-sm">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <span><i class="bi bi-droplet-half me-2"></i>المواد الخام المستهلكة اليوم</span>
    </div>
    <div class="card-body">
        <?php renderConsumptionTable($todaySummary['raw']['items'], true); ?>
    </div>
</div>

<?php
renderSummaryCards('تقرير الشهر الحالي', $monthSummary);
?>

<div class="card mb-4 shadow-sm">
    <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
        <span><i class="bi bi-box-seam me-2"></i>أدوات التعبئة للشهر الحالي</span>
    </div>
    <div class="card-body">
        <?php renderConsumptionTable($monthSummary['packaging']['items']); ?>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
        <span><i class="bi bi-droplet-half me-2"></i>المواد الخام للشهر الحالي</span>
    </div>
    <div class="card-body">
        <?php renderConsumptionTable($monthSummary['raw']['items'], true); ?>
    </div>
</div>

