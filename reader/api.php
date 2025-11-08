<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

session_start([
    'cookie_lifetime' => 0,
    'cookie_httponly' => true,
    'cookie_secure' => isset($_SERVER['HTTPS']),
    'use_strict_mode' => true,
]);

$sessionTimeout = 15 * 60; // 15 minutes
$now = time();

if (isset($_SESSION['reader_last_activity']) && ($now - $_SESSION['reader_last_activity']) > $sessionTimeout) {
    session_unset();
    session_destroy();
    echo json_encode([
        'success' => false,
        'message' => 'انتهت صلاحية الجلسة. يرجى إعادة تحميل الصفحة.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$_SESSION['reader_last_activity'] = $now;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'طريقة الطلب غير مدعومة.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$batchNumber = trim((string)($input['batch_number'] ?? ''));

if ($batchNumber === '') {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'يرجى إدخال رقم التشغيلة.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    define('ACCESS_ALLOWED', true);
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/batch_numbers.php';
} catch (Throwable $e) {
    error_log('Reader bootstrap error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'تعذر تهيئة الاتصال بقاعدة البيانات.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $batch = getBatchByNumber($batchNumber);
    if (!$batch) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'رقم التشغيلة غير موجود أو غير صحيح.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $statusLabels = [
        'in_production' => 'قيد الإنتاج',
        'completed' => 'مكتملة',
        'archived' => 'مؤرشفة',
        'cancelled' => 'ملغاة',
    ];

    $materials = [];
    if (!empty($batch['packaging_materials_details']) && is_array($batch['packaging_materials_details'])) {
        foreach ($batch['packaging_materials_details'] as $item) {
            $details = [];
            if (!empty($item['type'])) {
                $details[] = 'النوع: ' . $item['type'];
            }
            if (!empty($item['specifications'])) {
                $details[] = $item['specifications'];
            }
            $materials[] = [
                'id' => $item['id'] ?? null,
                'name' => $item['name'] ?? '—',
                'details' => implode(' — ', array_filter($details)),
            ];
        }
    }

    $workers = [];
    if (!empty($batch['workers_details']) && is_array($batch['workers_details'])) {
        foreach ($batch['workers_details'] as $worker) {
            $workers[] = [
                'id' => $worker['id'] ?? null,
                'username' => $worker['username'] ?? null,
                'full_name' => $worker['full_name'] ?? null,
                'role' => 'عامل إنتاج',
            ];
        }
    }

    $response = [
        'success' => true,
        'batch' => [
            'id' => $batch['id'] ?? null,
            'batch_number' => $batch['batch_number'] ?? $batchNumber,
            'product_name' => $batch['product_name'] ?? null,
            'product_category' => $batch['product_category'] ?? null,
            'production_date' => $batch['production_date'] ?? $batch['production_date_value'] ?? null,
            'quantity' => $batch['quantity'] ?? null,
            'status' => $batch['status'] ?? null,
            'status_label' => $statusLabels[$batch['status'] ?? ''] ?? 'غير معروف',
            'honey_supplier_name' => $batch['honey_supplier_name'] ?? null,
            'packaging_supplier_name' => $batch['packaging_supplier_name'] ?? null,
            'notes' => $batch['notes'] ?? null,
            'created_by_name' => $batch['created_by_name'] ?? null,
            'materials' => $materials,
            'workers' => $workers,
        ]
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('Reader API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ أثناء معالجة الطلب.'
    ], JSON_UNESCAPED_UNICODE);
}
