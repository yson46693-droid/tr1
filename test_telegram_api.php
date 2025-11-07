<?php
/**
 * API لاختبار بوت Telegram
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/simple_telegram.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'check_config':
            // فحص إعدادات Telegram
            $botToken = TELEGRAM_BOT_TOKEN;
            $chatId = TELEGRAM_CHAT_ID;
            $isConfigured = isTelegramConfigured();
            
            // إخفاء جزء من التوكن للأمان
            $displayToken = $botToken;
            if (strlen($botToken) > 20) {
                $displayToken = substr($botToken, 0, 10) . '...' . substr($botToken, -5);
            }
            
            $config = [
                'Bot Token' => $displayToken,
                'Chat ID' => $chatId,
                'Is Configured' => $isConfigured ? 'نعم ✓' : 'لا ✗',
                'API URL' => defined('TELEGRAM_API_URL') ? 'محدد' : 'غير محدد',
                'cURL Available' => function_exists('curl_init') ? 'متوفر ✓' : 'غير متوفر ✗',
                'CURLFile Class' => class_exists('CURLFile') ? 'متوفر ✓' : 'غير متوفر ✗'
            ];
            
            if ($isConfigured) {
                echo json_encode([
                    'success' => true,
                    'message' => 'إعدادات Telegram محددة بشكل صحيح',
                    'data' => $config
                ], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => 'إعدادات Telegram غير محددة أو غير صحيحة',
                    'data' => $config
                ], JSON_UNESCAPED_UNICODE);
            }
            break;
            
        case 'test_connection':
            // اختبار الاتصال بـ Telegram API
            if (!isTelegramConfigured()) {
                echo json_encode([
                    'success' => false,
                    'error' => 'يجب تحديد إعدادات Telegram أولاً'
                ], JSON_UNESCAPED_UNICODE);
                break;
            }
            
            $url = TELEGRAM_API_URL . '/getMe';
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($httpCode === 200) {
                $result = json_decode($response, true);
                if (isset($result['ok']) && $result['ok']) {
                    $botInfo = $result['result'];
                    echo json_encode([
                        'success' => true,
                        'message' => 'الاتصال بـ Telegram API ناجح!',
                        'data' => [
                            'Bot ID' => $botInfo['id'] ?? 'N/A',
                            'Bot Name' => $botInfo['first_name'] ?? 'N/A',
                            'Bot Username' => '@' . ($botInfo['username'] ?? 'N/A'),
                            'Can Join Groups' => isset($botInfo['can_join_groups']) && $botInfo['can_join_groups'] ? 'نعم' : 'لا',
                            'Can Read Messages' => isset($botInfo['can_read_all_group_messages']) && $botInfo['can_read_all_group_messages'] ? 'نعم' : 'لا'
                        ]
                    ], JSON_UNESCAPED_UNICODE);
                } else {
                    echo json_encode([
                        'success' => false,
                        'error' => 'فشل الاتصال: ' . ($result['description'] ?? 'خطأ غير معروف'),
                        'data' => $result
                    ], JSON_UNESCAPED_UNICODE);
                }
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => "خطأ HTTP {$httpCode}: {$curlError}",
                    'data' => [
                        'HTTP Code' => $httpCode,
                        'cURL Error' => $curlError,
                        'Response' => substr($response, 0, 500)
                    ]
                ], JSON_UNESCAPED_UNICODE);
            }
            break;
            
        case 'send_text':
            // إرسال رسالة نصية
            if (!isTelegramConfigured()) {
                echo json_encode([
                    'success' => false,
                    'error' => 'يجب تحديد إعدادات Telegram أولاً'
                ], JSON_UNESCAPED_UNICODE);
                break;
            }
            
            $message = $_POST['message'] ?? 'رسالة اختبار';
            
            error_log("Test Telegram: Sending text message: " . substr($message, 0, 50));
            
            $result = sendTelegramMessage($message);
            
            if ($result) {
                echo json_encode([
                    'success' => true,
                    'message' => 'تم إرسال الرسالة النصية بنجاح إلى Telegram!',
                    'data' => [
                        'Message ID' => $result['result']['message_id'] ?? 'N/A',
                        'Chat ID' => $result['result']['chat']['id'] ?? 'N/A',
                        'Date' => isset($result['result']['date']) ? date('Y-m-d H:i:s', $result['result']['date']) : 'N/A'
                    ]
                ], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => 'فشل إرسال الرسالة النصية. راجع سجل الأخطاء.'
                ], JSON_UNESCAPED_UNICODE);
            }
            break;
            
        case 'send_photo':
            // إرسال صورة
            if (!isTelegramConfigured()) {
                echo json_encode([
                    'success' => false,
                    'error' => 'يجب تحديد إعدادات Telegram أولاً'
                ], JSON_UNESCAPED_UNICODE);
                break;
            }
            
            $photoData = $_POST['photo'] ?? '';
            $caption = $_POST['caption'] ?? 'صورة اختبار';
            
            if (empty($photoData)) {
                echo json_encode([
                    'success' => false,
                    'error' => 'لم يتم توفير بيانات الصورة'
                ], JSON_UNESCAPED_UNICODE);
                break;
            }
            
            error_log("Test Telegram: Sending photo, data length: " . strlen($photoData));
            error_log("Test Telegram: Caption: " . $caption);
            
            $result = sendTelegramPhoto($photoData, $caption, null, true);
            
            if ($result) {
                echo json_encode([
                    'success' => true,
                    'message' => 'تم إرسال الصورة بنجاح إلى Telegram! 🎉',
                    'data' => [
                        'Message ID' => $result['result']['message_id'] ?? 'N/A',
                        'Chat ID' => $result['result']['chat']['id'] ?? 'N/A',
                        'Photo Size' => isset($result['result']['photo']) ? count($result['result']['photo']) . ' sizes' : 'N/A',
                        'Date' => isset($result['result']['date']) ? date('Y-m-d H:i:s', $result['result']['date']) : 'N/A'
                    ]
                ], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => 'فشل إرسال الصورة. راجع سجل الأخطاء للتفاصيل.'
                ], JSON_UNESCAPED_UNICODE);
            }
            break;
            
        case 'check_errors':
            // فحص سجل الأخطاء
            $errorLogPath = ini_get('error_log');
            if (!$errorLogPath || !file_exists($errorLogPath)) {
                $errorLogPath = __DIR__ . '/error_log';
            }
            
            $logExists = file_exists($errorLogPath);
            $recentErrors = [];
            
            if ($logExists && is_readable($errorLogPath)) {
                $lines = file($errorLogPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                // آخر 30 سطر مع التركيز على أخطاء Telegram
                $allLines = array_slice($lines, -50);
                foreach ($allLines as $line) {
                    if (stripos($line, 'telegram') !== false || 
                        stripos($line, 'photo') !== false || 
                        stripos($line, 'attendance') !== false) {
                        $recentErrors[] = $line;
                    }
                }
                if (empty($recentErrors)) {
                    $recentErrors = array_slice($lines, -20);
                }
            }
            
            echo json_encode([
                'success' => true,
                'message' => $logExists ? 'تم العثور على سجل الأخطاء' : 'لا يوجد سجل أخطاء',
                'data' => [
                    'Error Log Path' => $errorLogPath,
                    'Exists' => $logExists ? 'نعم' : 'لا',
                    'Readable' => ($logExists && is_readable($errorLogPath)) ? 'نعم' : 'لا',
                    'Recent Errors' => $recentErrors ?: ['لا توجد أخطاء حديثة'],
                    'PHP Error Reporting' => ini_get('error_reporting'),
                    'Display Errors' => ini_get('display_errors') ? 'مفعل' : 'معطل',
                    'Log Errors' => ini_get('log_errors') ? 'مفعل' : 'معطل'
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        default:
            echo json_encode([
                'success' => false,
                'error' => 'إجراء غير صحيح: ' . htmlspecialchars($action)
            ], JSON_UNESCAPED_UNICODE);
    }
} catch (Exception $e) {
    error_log("Test Telegram API Exception: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    echo json_encode([
        'success' => false,
        'error' => 'حدث خطأ: ' . $e->getMessage(),
        'data' => [
            'Exception' => $e->getMessage(),
            'File' => $e->getFile(),
            'Line' => $e->getLine(),
            'Trace' => $e->getTraceAsString()
        ]
    ], JSON_UNESCAPED_UNICODE);
}

