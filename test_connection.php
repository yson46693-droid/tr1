<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>اختبار الاتصال - Telegram</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            padding: 20px;
            background: #f0f2f5;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #0088cc;
            text-align: center;
        }
        .test-box {
            margin: 20px 0;
            padding: 15px;
            border: 2px solid #ddd;
            border-radius: 5px;
        }
        .success {
            border-color: #28a745;
            background: #d4edda;
        }
        .error {
            border-color: #dc3545;
            background: #f8d7da;
        }
        .warning {
            border-color: #ffc107;
            background: #fff3cd;
        }
        .test-title {
            font-weight: bold;
            margin-bottom: 10px;
            font-size: 18px;
        }
        pre {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            overflow-x: auto;
            font-size: 12px;
        }
        .btn {
            background: #0088cc;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            margin: 5px;
        }
        .btn:hover {
            background: #006699;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 اختبار تشخيصي للاتصال بـ Telegram</h1>
        
        <?php
        error_reporting(E_ALL);
        ini_set('display_errors', 1);
        
        echo "<div class='test-box'>";
        echo "<div class='test-title'>📋 معلومات النظام:</div>";
        echo "<pre>";
        echo "PHP Version: " . PHP_VERSION . "\n";
        echo "OS: " . PHP_OS . "\n";
        echo "cURL: " . (function_exists('curl_init') ? '✓ مثبت' : '✗ غير مثبت') . "\n";
        echo "allow_url_fopen: " . (ini_get('allow_url_fopen') ? '✓ مفعّل' : '✗ معطّل') . "\n";
        echo "</pre>";
        echo "</div>";
        
        // Test 1: DNS Resolution
        echo "<div class='test-box'>";
        echo "<div class='test-title'>🌐 اختبار 1: حل اسم النطاق (DNS)</div>";
        
        $host = 'api.telegram.org';
        $ip = gethostbyname($host);
        
        if ($ip && $ip !== $host) {
            echo "<div class='success'>";
            echo "✓ تم حل اسم النطاق بنجاح!<br>";
            echo "IP Address: <strong>{$ip}</strong>";
            echo "</div>";
        } else {
            echo "<div class='error'>";
            echo "✗ فشل حل اسم النطاق<br>";
            echo "<strong>الحل:</strong> غيّر DNS إلى 8.8.8.8 (Google DNS)";
            echo "</div>";
        }
        echo "</div>";
        
        // Test 2: PHP file_get_contents
        echo "<div class='test-box'>";
        echo "<div class='test-title'>📡 اختبار 2: الاتصال عبر file_get_contents</div>";
        
        if (ini_get('allow_url_fopen')) {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 10,
                    'ignore_errors' => true
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false
                ]
            ]);
            
            $url = "https://api.telegram.org/bot6286098014:AAGr6q-6mvUHYIa3elUkssoijFhY7OXBrew/getMe";
            $result = @file_get_contents($url, false, $context);
            
            if ($result) {
                $data = json_decode($result, true);
                if (isset($data['ok']) && $data['ok']) {
                    echo "<div class='success'>";
                    echo "✓ الاتصال ناجح!<br>";
                    echo "<pre>" . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
                    echo "</div>";
                } else {
                    echo "<div class='error'>";
                    echo "✗ فشل: " . ($data['description'] ?? 'خطأ غير معروف');
                    echo "</div>";
                }
            } else {
                echo "<div class='error'>";
                echo "✗ لا يمكن الاتصال<br>";
                $error = error_get_last();
                echo "<pre>" . print_r($error, true) . "</pre>";
                echo "</div>";
            }
        } else {
            echo "<div class='warning'>⚠️ allow_url_fopen معطّل</div>";
        }
        echo "</div>";
        
        // Test 3: cURL
        echo "<div class='test-box'>";
        echo "<div class='test-title'>🔌 اختبار 3: الاتصال عبر cURL</div>";
        
        if (function_exists('curl_init')) {
            $url = "https://api.telegram.org/bot6286098014:AAGr6q-6mvUHYIa3elUkssoijFhY7OXBrew/getMe";
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_VERBOSE => true
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            $curlInfo = curl_getinfo($ch);
            curl_close($ch);
            
            if ($httpCode === 200 && $response) {
                $data = json_decode($response, true);
                if (isset($data['ok']) && $data['ok']) {
                    echo "<div class='success'>";
                    echo "✓ الاتصال ناجح!<br>";
                    echo "HTTP Code: {$httpCode}<br>";
                    echo "<strong>معلومات البوت:</strong>";
                    echo "<pre>" . json_encode($data['result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
                    echo "</div>";
                } else {
                    echo "<div class='error'>";
                    echo "✗ خطأ من Telegram API<br>";
                    echo "<pre>" . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
                    echo "</div>";
                }
            } else {
                echo "<div class='error'>";
                echo "✗ فشل الاتصال<br>";
                echo "<strong>HTTP Code:</strong> {$httpCode}<br>";
                echo "<strong>cURL Error:</strong> {$curlError}<br>";
                echo "<strong>معلومات إضافية:</strong>";
                echo "<pre>";
                echo "Connect Time: " . $curlInfo['connect_time'] . "s\n";
                echo "Total Time: " . $curlInfo['total_time'] . "s\n";
                echo "Primary IP: " . ($curlInfo['primary_ip'] ?? 'N/A') . "\n";
                echo "</pre>";
                echo "<div class='warning'>";
                echo "<strong>الحلول المقترحة:</strong><br>";
                echo "1. افتح CMD كمسؤول وشغّل: <code>ipconfig /flushdns</code><br>";
                echo "2. غيّر DNS إلى 8.8.8.8<br>";
                echo "3. عطّل Firewall/Antivirus مؤقتاً<br>";
                echo "4. تأكد من اتصالك بالإنترنت<br>";
                echo "</div>";
                echo "</div>";
            }
            
            // عرض تفاصيل cURL الكاملة
            echo "<details style='margin-top:10px;'>";
            echo "<summary style='cursor:pointer;'>📊 تفاصيل cURL الكاملة</summary>";
            echo "<pre>" . print_r($curlInfo, true) . "</pre>";
            echo "</details>";
            
        } else {
            echo "<div class='error'>✗ cURL غير مثبت</div>";
        }
        echo "</div>";
        
        // Test 4: Test with IP directly
        echo "<div class='test-box'>";
        echo "<div class='test-title'>🎯 اختبار 4: الاتصال المباشر عبر IP</div>";
        
        $ip = '149.154.167.220';
        $url = "https://{$ip}/bot6286098014:AAGr6q-6mvUHYIa3elUkssoijFhY7OXBrew/getMe";
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Host: api.telegram.org']
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            if (isset($data['ok']) && $data['ok']) {
                echo "<div class='success'>";
                echo "✓ الاتصال عبر IP ناجح!<br>";
                echo "<strong>الحل:</strong> يمكنك استخدام IP بدلاً من Domain مؤقتاً";
                echo "</div>";
            } else {
                echo "<div class='warning'>⚠️ الاتصال تم ولكن هناك خطأ في الاستجابة</div>";
            }
        } else {
            echo "<div class='error'>";
            echo "✗ فشل الاتصال عبر IP أيضاً<br>";
            echo "Error: {$curlError}";
            echo "</div>";
        }
        echo "</div>";
        
        // Final Recommendations
        echo "<div class='test-box warning'>";
        echo "<div class='test-title'>💡 التوصيات النهائية:</div>";
        echo "<ol>";
        echo "<li>إذا فشلت جميع الاختبارات: <strong>المشكلة في الاتصال بالإنترنت أو DNS</strong></li>";
        echo "<li>إذا نجح الاختبار 1 وفشلت البقية: <strong>المشكلة في Firewall أو SSL</strong></li>";
        echo "<li>إذا نجح الاختبار 4 فقط: <strong>المشكلة في DNS - استخدم IP مؤقتاً</strong></li>";
        echo "<li>إذا نجحت جميع الاختبارات: <strong>المشكلة في كود التطبيق</strong></li>";
        echo "</ol>";
        echo "</div>";
        ?>
        
        <div style="text-align: center; margin-top: 20px;">
            <button class="btn" onclick="location.reload()">🔄 إعادة الاختبار</button>
            <button class="btn" onclick="location.href='test_telegram_full.php'">📋 اختبارات شاملة</button>
        </div>
    </div>
</body>
</html>

