<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>اختبار شامل لبوت Telegram</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding: 30px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .test-container {
            max-width: 1000px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        .test-result {
            margin-top: 15px;
            padding: 15px;
            border-radius: 10px;
            font-size: 14px;
        }
        .test-result.success {
            background: #d4edda;
            border: 2px solid #c3e6cb;
            color: #155724;
        }
        .test-result.error {
            background: #f8d7da;
            border: 2px solid #f5c6cb;
            color: #721c24;
        }
        .test-result.info {
            background: #d1ecf1;
            border: 2px solid #bee5eb;
            color: #0c5460;
        }
        .btn-test {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
            padding: 10px 25px;
            border-radius: 25px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-test:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
            color: white;
        }
        .config-item {
            padding: 10px;
            margin: 5px 0;
            background: #f8f9fa;
            border-radius: 5px;
            border-left: 4px solid #667eea;
        }
        .config-item .label {
            font-weight: bold;
            color: #667eea;
        }
        .config-item .value {
            font-family: monospace;
            color: #333;
        }
        pre {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            border: 1px solid #dee2e6;
            max-height: 400px;
            overflow-y: auto;
        }
    </style>
</head>
<body>
    <div class="test-container">
        <h1 class="text-center mb-4">
            <i class="bi bi-telegram text-primary"></i>
            اختبار شامل لبوت Telegram
        </h1>

        <div class="alert alert-info">
            <i class="bi bi-info-circle-fill me-2"></i>
            هذه الصفحة لفحص واختبار جميع وظائف بوت Telegram بما في ذلك إرسال الرسائل والصور
        </div>

        <!-- Test 1: Check Configuration -->
        <div class="card mb-3">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-gear-fill me-2"></i>1. فحص إعدادات Telegram</h5>
            </div>
            <div class="card-body">
                <button class="btn btn-test" onclick="checkConfig()">
                    <i class="bi bi-play-fill me-2"></i>فحص الإعدادات
                </button>
                <div id="test1-result"></div>
            </div>
        </div>

        <!-- Test 2: Test Connection -->
        <div class="card mb-3">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0"><i class="bi bi-wifi me-2"></i>2. اختبار الاتصال بـ Telegram API</h5>
            </div>
            <div class="card-body">
                <button class="btn btn-test" onclick="testConnection()">
                    <i class="bi bi-play-fill me-2"></i>اختبار الاتصال
                </button>
                <div id="test2-result"></div>
            </div>
        </div>

        <!-- Test 3: Send Text Message -->
        <div class="card mb-3">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="bi bi-chat-text me-2"></i>3. إرسال رسالة نصية</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">نص الرسالة:</label>
                    <textarea class="form-control" id="textMessage" rows="3" placeholder="اكتب رسالة اختبار...">🔔 رسالة اختبار من النظام
التاريخ: <?php echo date('Y-m-d H:i:s'); ?>
الحالة: ✅ يعمل بشكل صحيح</textarea>
                </div>
                <button class="btn btn-test" onclick="sendTextMessage()">
                    <i class="bi bi-send-fill me-2"></i>إرسال الرسالة
                </button>
                <div id="test3-result"></div>
            </div>
        </div>

        <!-- Test 4: Send Photo from Camera -->
        <div class="card mb-3">
            <div class="card-header bg-warning text-dark">
                <h5 class="mb-0"><i class="bi bi-camera me-2"></i>4. التقاط صورة وإرسالها</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <video id="camera" width="320" height="240" autoplay style="border: 2px solid #ddd; border-radius: 10px;"></video>
                    <canvas id="canvas" width="320" height="240" style="display:none;"></canvas>
                </div>
                <button class="btn btn-test me-2" onclick="startCamera()">
                    <i class="bi bi-camera-video me-2"></i>تشغيل الكاميرا
                </button>
                <button class="btn btn-test me-2" onclick="captureAndSend()">
                    <i class="bi bi-camera-fill me-2"></i>التقاط وإرسال
                </button>
                <button class="btn btn-secondary" onclick="stopCamera()">
                    <i class="bi bi-stop-circle me-2"></i>إيقاف الكاميرا
                </button>
                <div id="test4-result"></div>
            </div>
        </div>

        <!-- Test 5: Send Test Photo -->
        <div class="card mb-3">
            <div class="card-header bg-danger text-white">
                <h5 class="mb-0"><i class="bi bi-image me-2"></i>5. إرسال صورة اختبار (Base64)</h5>
            </div>
            <div class="card-body">
                <p class="text-muted small">سيتم إنشاء صورة اختبار وإرسالها بصيغة Base64</p>
                <button class="btn btn-test" onclick="sendTestPhoto()">
                    <i class="bi bi-send-fill me-2"></i>إرسال صورة اختبار
                </button>
                <div id="test5-result"></div>
            </div>
        </div>

        <!-- Test 6: Check Error Log -->
        <div class="card mb-3">
            <div class="card-header bg-secondary text-white">
                <h5 class="mb-0"><i class="bi bi-bug me-2"></i>6. فحص سجل أخطاء PHP</h5>
            </div>
            <div class="card-body">
                <button class="btn btn-test" onclick="checkErrorLog()">
                    <i class="bi bi-journal-code me-2"></i>عرض آخر الأخطاء
                </button>
                <div id="test6-result"></div>
            </div>
        </div>

        <div class="text-center mt-4">
            <button class="btn btn-lg btn-test" onclick="runAllTests()">
                <i class="bi bi-lightning-fill me-2"></i>تشغيل جميع الاختبارات
            </button>
        </div>
    </div>

    <script>
        let cameraStream = null;

        function showResult(elementId, status, message, data = null) {
            const element = document.getElementById(elementId);
            let html = `<div class="test-result ${status}">
                <strong><i class="bi bi-${status === 'success' ? 'check-circle-fill' : status === 'error' ? 'x-circle-fill' : 'info-circle-fill'}"></i> ${status === 'success' ? 'نجح' : status === 'error' ? 'فشل' : 'معلومات'}:</strong>
                <p class="mb-0 mt-2">${message}</p>`;
            
            if (data) {
                html += `<pre class="mt-2 mb-0"><code>${typeof data === 'string' ? data : JSON.stringify(data, null, 2)}</code></pre>`;
            }
            
            html += '</div>';
            element.innerHTML = html;
        }

        async function checkConfig() {
            try {
                const response = await fetch('test_telegram_api.php?action=check_config');
                const result = await response.json();
                
                if (result.success) {
                    showResult('test1-result', 'success', result.message, result.data);
                } else {
                    showResult('test1-result', 'error', result.error, result.data);
                }
            } catch (error) {
                showResult('test1-result', 'error', 'فشل الاتصال: ' + error.message);
            }
        }

        async function testConnection() {
            try {
                const response = await fetch('test_telegram_api.php?action=test_connection');
                const result = await response.json();
                
                if (result.success) {
                    showResult('test2-result', 'success', result.message, result.data);
                } else {
                    showResult('test2-result', 'error', result.error, result.data);
                }
            } catch (error) {
                showResult('test2-result', 'error', 'فشل الاتصال: ' + error.message);
            }
        }

        async function sendTextMessage() {
            const message = document.getElementById('textMessage').value;
            try {
                const response = await fetch('test_telegram_api.php?action=send_text', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'message=' + encodeURIComponent(message)
                });
                const result = await response.json();
                
                if (result.success) {
                    showResult('test3-result', 'success', result.message, result.data);
                } else {
                    showResult('test3-result', 'error', result.error, result.data);
                }
            } catch (error) {
                showResult('test3-result', 'error', 'فشل الاتصال: ' + error.message);
            }
        }

        async function startCamera() {
            try {
                cameraStream = await navigator.mediaDevices.getUserMedia({ 
                    video: { 
                        width: { ideal: 1280 },
                        height: { ideal: 720 }
                    } 
                });
                document.getElementById('camera').srcObject = cameraStream;
                showResult('test4-result', 'success', 'تم تشغيل الكاميرا بنجاح');
            } catch (error) {
                showResult('test4-result', 'error', 'فشل تشغيل الكاميرا: ' + error.message);
            }
        }

        function stopCamera() {
            if (cameraStream) {
                cameraStream.getTracks().forEach(track => track.stop());
                cameraStream = null;
                document.getElementById('camera').srcObject = null;
                showResult('test4-result', 'info', 'تم إيقاف الكاميرا');
            }
        }

        async function captureAndSend() {
            const video = document.getElementById('camera');
            const canvas = document.getElementById('canvas');
            const context = canvas.getContext('2d');
            
            if (!cameraStream) {
                showResult('test4-result', 'error', 'يجب تشغيل الكاميرا أولاً');
                return;
            }
            
            // التقاط الصورة
            context.drawImage(video, 0, 0, canvas.width, canvas.height);
            const imageData = canvas.toDataURL('image/jpeg', 0.8);
            
            // إرسال الصورة
            try {
                showResult('test4-result', 'info', 'جاري إرسال الصورة...');
                const response = await fetch('test_telegram_api.php?action=send_photo', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'photo=' + encodeURIComponent(imageData) + '&caption=' + encodeURIComponent('📸 صورة من الكاميرا - اختبار التليجرام')
                });
                const result = await response.json();
                
                if (result.success) {
                    showResult('test4-result', 'success', result.message, result.data);
                } else {
                    showResult('test4-result', 'error', result.error, result.data);
                }
            } catch (error) {
                showResult('test4-result', 'error', 'فشل الإرسال: ' + error.message);
            }
        }

        async function sendTestPhoto() {
            // إنشاء صورة اختبار بسيطة
            const canvas = document.createElement('canvas');
            canvas.width = 400;
            canvas.height = 300;
            const ctx = canvas.getContext('2d');
            
            // رسم خلفية زرقاء
            ctx.fillStyle = '#667eea';
            ctx.fillRect(0, 0, 400, 300);
            
            // رسم نص
            ctx.fillStyle = 'white';
            ctx.font = 'bold 30px Arial';
            ctx.textAlign = 'center';
            ctx.fillText('اختبار Telegram', 200, 120);
            ctx.font = '20px Arial';
            ctx.fillText('<?php echo date("Y-m-d H:i:s"); ?>', 200, 160);
            ctx.fillText('✓ النظام يعمل', 200, 200);
            
            const imageData = canvas.toDataURL('image/jpeg', 0.8);
            
            try {
                showResult('test5-result', 'info', 'جاري إرسال الصورة الاختبارية...');
                const response = await fetch('test_telegram_api.php?action=send_photo', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'photo=' + encodeURIComponent(imageData) + '&caption=' + encodeURIComponent('🧪 صورة اختبارية من النظام')
                });
                const result = await response.json();
                
                if (result.success) {
                    showResult('test5-result', 'success', result.message, result.data);
                } else {
                    showResult('test5-result', 'error', result.error, result.data);
                }
            } catch (error) {
                showResult('test5-result', 'error', 'فشل الإرسال: ' + error.message);
            }
        }

        async function checkErrorLog() {
            try {
                const response = await fetch('test_telegram_api.php?action=check_errors');
                const result = await response.json();
                
                if (result.success) {
                    showResult('test6-result', 'info', result.message, result.data);
                } else {
                    showResult('test6-result', 'error', result.error, result.data);
                }
            } catch (error) {
                showResult('test6-result', 'error', 'فشل الاتصال: ' + error.message);
            }
        }

        async function runAllTests() {
            await checkConfig();
            await new Promise(resolve => setTimeout(resolve, 1000));
            await testConnection();
            await new Promise(resolve => setTimeout(resolve, 1000));
            await sendTextMessage();
            await new Promise(resolve => setTimeout(resolve, 1000));
            await sendTestPhoto();
            await new Promise(resolve => setTimeout(resolve, 1000));
            await checkErrorLog();
        }
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

