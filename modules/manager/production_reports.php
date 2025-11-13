<?php
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/path_helper.php';

requireRole('manager');

$productionReportsPath = __DIR__ . '/../production/productivity_reports.php';

if (file_exists($productionReportsPath)) {
    include $productionReportsPath;
    return;
}

?>
<div class="card shadow-sm">
    <div class="card-body text-center py-5">
        <i class="bi bi-exclamation-triangle text-warning display-5 mb-3"></i>
        <h4 class="mb-2">تعذر تحميل تقارير الإنتاج</h4>
        <p class="text-muted mb-0">لم يتم العثور على ملف تقارير الإنتاج في الوقت الحالي. يرجى التواصل مع فريق التطوير.</p>
    </div>
</div>
 