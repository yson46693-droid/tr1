<?php
/**
 * API لاختبار نظام التقارير
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/simple_export.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'check_directory':
            $reportsPath = rtrim(REPORTS_PATH, '/\\');
            $exists = file_exists($reportsPath);
            $writable = $exists ? is_writable($reportsPath) : false;
            $permissions = $exists ? substr(sprintf('%o', fileperms($reportsPath)), -4) : 'N/A';
            
            // محاولة إنشاء المجلد إذا لم يكن موجوداً
            if (!$exists) {
                $created = @mkdir($reportsPath, 0755, true);
                if ($created) {
                    $exists = true;
                    $writable = is_writable($reportsPath);
                    $permissions = substr(sprintf('%o', fileperms($reportsPath)), -4);
                }
            }
            
            // اختبار الكتابة
            $testFile = $reportsPath . '/test_write_' . time() . '.txt';
            $canWrite = @file_put_contents($testFile, 'test') !== false;
            if ($canWrite && file_exists($testFile)) {
                @unlink($testFile);
            }
            
            echo json_encode([
                'success' => $exists && $writable && $canWrite,
                'message' => $exists && $writable && $canWrite 
                    ? 'مجلد التقارير موجود وقابل للكتابة بنجاح!' 
                    : 'يوجد مشكلة في مجلد التقارير',
                'data' => [
                    'path' => $reportsPath,
                    'exists' => $exists,
                    'writable' => $writable,
                    'can_write_test' => $canWrite,
                    'permissions' => $permissions,
                    'current_dir' => getcwd(),
                    'base_path' => BASE_PATH
                ]
            ]);
            break;
            
        case 'create_test_data':
            // إنشاء بيانات تجريبية للتقرير
            $testData = [
                ['الاسم' => 'أحمد محمد', 'القسم' => 'المبيعات', 'المبلغ' => '5000.00 ج.م'],
                ['الاسم' => 'محمد علي', 'القسم' => 'الإنتاج', 'المبلغ' => '4500.00 ج.م'],
                ['الاسم' => 'سارة أحمد', 'القسم' => 'المحاسبة', 'المبلغ' => '5500.00 ج.م'],
                ['الاسم' => 'فاطمة حسن', 'القسم' => 'المبيعات', 'المبلغ' => '4800.00 ج.م'],
                ['الاسم' => 'خالد محمود', 'القسم' => 'الإنتاج', 'المبلغ' => '4200.00 ج.م'],
            ];
            
            echo json_encode([
                'success' => true,
                'message' => 'تم إنشاء ' . count($testData) . ' سجل تجريبي بنجاح',
                'data' => $testData
            ]);
            break;
            
        case 'test_pdf':
            // اختبار توليد PDF
            $testData = [
                ['الاسم' => 'أحمد محمد', 'القسم' => 'المبيعات', 'المبلغ' => '5000.00 ج.م', 'التاريخ' => '2024-01-15'],
                ['الاسم' => 'محمد علي', 'القسم' => 'الإنتاج', 'المبلغ' => '4500.00 ج.م', 'التاريخ' => '2024-01-16'],
                ['الاسم' => 'سارة أحمد', 'القسم' => 'المحاسبة', 'المبلغ' => '5500.00 ج.م', 'التاريخ' => '2024-01-17'],
            ];
            
            $filters = [
                'من تاريخ' => '2024-01-01',
                'إلى تاريخ' => '2024-01-31',
                'القسم' => 'جميع الأقسام'
            ];
            
            try {
                $filePath = exportPDF($testData, 'تقرير اختبار PDF', $filters);
                
                // حساب URL الملف
                $fileUrl = str_replace($_SERVER['DOCUMENT_ROOT'] ?? '', '', $filePath);
                $fileUrl = str_replace('\\', '/', $fileUrl);
                if (!str_starts_with($fileUrl, '/')) {
                    $fileUrl = '/' . $fileUrl;
                }
                $fileUrl = preg_replace('#/+#', '/', $fileUrl);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'تم إنشاء تقرير PDF بنجاح!',
                    'file_url' => $fileUrl,
                    'data' => [
                        'file_path' => $filePath,
                        'file_size' => filesize($filePath) . ' bytes',
                        'file_exists' => file_exists($filePath),
                        'records_count' => count($testData)
                    ]
                ]);
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'error' => 'فشل في إنشاء تقرير PDF: ' . $e->getMessage(),
                    'data' => [
                        'exception' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]
                ]);
            }
            break;
            
        case 'test_csv':
            // اختبار توليد CSV
            $testData = [
                ['الاسم' => 'أحمد محمد', 'القسم' => 'المبيعات', 'المبلغ' => '5000.00', 'التاريخ' => '2024-01-15'],
                ['الاسم' => 'محمد علي', 'القسم' => 'الإنتاج', 'المبلغ' => '4500.00', 'التاريخ' => '2024-01-16'],
                ['الاسم' => 'سارة أحمد', 'القسم' => 'المحاسبة', 'المبلغ' => '5500.00', 'التاريخ' => '2024-01-17'],
            ];
            
            $filters = [
                'من تاريخ' => '2024-01-01',
                'إلى تاريخ' => '2024-01-31',
                'القسم' => 'جميع الأقسام'
            ];
            
            try {
                $filePath = exportCSV($testData, 'تقرير اختبار CSV', $filters);
                
                // حساب URL الملف
                $fileUrl = str_replace($_SERVER['DOCUMENT_ROOT'] ?? '', '', $filePath);
                $fileUrl = str_replace('\\', '/', $fileUrl);
                if (!str_starts_with($fileUrl, '/')) {
                    $fileUrl = '/' . $fileUrl;
                }
                $fileUrl = preg_replace('#/+#', '/', $fileUrl);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'تم إنشاء تقرير CSV بنجاح!',
                    'file_url' => $fileUrl,
                    'data' => [
                        'file_path' => $filePath,
                        'file_size' => filesize($filePath) . ' bytes',
                        'file_exists' => file_exists($filePath),
                        'records_count' => count($testData)
                    ]
                ]);
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'error' => 'فشل في إنشاء تقرير CSV: ' . $e->getMessage(),
                    'data' => [
                        'exception' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]
                ]);
            }
            break;
            
        case 'check_error_log':
            // فحص آخر الأخطاء في error_log
            $errorLogPath = ini_get('error_log');
            if (!$errorLogPath) {
                $errorLogPath = $_SERVER['DOCUMENT_ROOT'] . '/error_log';
            }
            
            $logExists = file_exists($errorLogPath);
            $logReadable = $logExists && is_readable($errorLogPath);
            
            $recentErrors = [];
            if ($logReadable) {
                $lines = file($errorLogPath);
                $recentErrors = array_slice($lines, -20); // آخر 20 سطر
            }
            
            echo json_encode([
                'success' => true,
                'message' => $logExists 
                    ? 'تم العثور على ملف سجل الأخطاء'
                    : 'لا يوجد ملف سجل أخطاء (وهذا جيد إذا لم تكن هناك أخطاء)',
                'data' => [
                    'error_log_path' => $errorLogPath,
                    'exists' => $logExists,
                    'readable' => $logReadable,
                    'recent_errors' => $logReadable ? $recentErrors : ['لا يمكن قراءة سجل الأخطاء'],
                    'php_version' => PHP_VERSION,
                    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'
                ]
            ]);
            break;
            
        default:
            echo json_encode([
                'success' => false,
                'error' => 'إجراء غير صحيح: ' . htmlspecialchars($action)
            ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'حدث خطأ: ' . $e->getMessage(),
        'data' => [
            'exception' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]
    ]);
}

