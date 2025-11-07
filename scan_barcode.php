<?php
/**
 * صفحة فحص الباركود
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/batch_numbers.php';
require_once __DIR__ . '/includes/simple_barcode.php';
require_once __DIR__ . '/includes/path_helper.php';

requireRole(['production', 'accountant', 'sales', 'manager']);

$currentUser = getCurrentUser();
$batchNumber = $_GET['batch'] ?? $_POST['batch_number'] ?? '';

// معالجة فحص الباركود
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($batchNumber)) {
    // يمكن لأي مستخدم الفحص بدون تحديد نوع أو موقع
    recordBarcodeScan($batchNumber, 'verification', null);
}

$batch = null;
if (!empty($batchNumber)) {
    $batch = getBatchByNumber($batchNumber);
}

$dashboardUrl = getDashboardUrl($currentUser['role']);
require_once __DIR__ . '/includes/lang/' . getCurrentLanguage() . '.php';
$lang = isset($translations) ? $translations : [];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($lang['barcode_scan']) ? $lang['barcode_scan'] : 'فحص الباركود'; ?> - <?php echo COMPANY_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo ASSETS_URL; ?>css/homeline-dashboard.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    <style>
        body {
            background: linear-gradient(135deg, #ffffff 0%, #e8f4fd 100%);
            min-height: 100vh;
            padding: 20px 0;
        }
        
        .barcode-scanner-container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .scanner-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            border: 1px solid rgba(59, 130, 246, 0.2);
            overflow: hidden;
        }
        
        .scanner-header {
            background: linear-gradient(135deg, #3b82f6 0%, #1e3a5f 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .scanner-header h1 {
            margin: 0;
            font-size: 2rem;
            font-weight: 700;
        }
        
        .scanner-body {
            padding: 40px;
        }
        
        .camera-section {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            border: 3px dashed #dee2e6;
            transition: all 0.3s ease;
        }
        
        .camera-section.active {
            border-color: #3b82f6;
            background: #e8f4fd;
        }
        
        #video-container {
            position: relative;
            width: 100%;
            min-width: 800px;
            max-width: 1000px;
            min-height: 400px;
            margin: 0 auto;
            border-radius: 15px;
            overflow: hidden;
            background: #000;
            display: none;
            border: 3px solid #3b82f6;
        }
        
        #video {
            width: 100%;
            height: auto;
            min-height: 400px;
            display: block;
            object-fit: cover;
        }
        
        #canvas {
            display: none;
        }
        
        .scan-overlay {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 90%;
            min-width: 600px;
            max-width: 800px;
            height: 150px;
            border: 3px solid #3b82f6;
            border-radius: 10px;
            box-shadow: 0 0 0 9999px rgba(0,0,0,0.5);
            pointer-events: none;
        }
        
        .scan-line {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background: #3b82f6;
            animation: scan 2s linear infinite;
        }
        
        @keyframes scan {
            0% { top: 0; }
            100% { top: 100%; }
        }
        
        .btn-scan {
            background: linear-gradient(135deg, #3b82f6 0%, #1e3a5f 100%);
            border: none;
            color: white;
            padding: 15px 40px;
            font-size: 1.1rem;
            font-weight: 600;
            border-radius: 50px;
            transition: all 0.3s ease;
            box-shadow: 0 5px 15px rgba(59, 130, 246, 0.4);
        }
        
        .btn-scan:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(59, 130, 246, 0.6);
            background: linear-gradient(135deg, #2563eb 0%, #152940 100%);
            color: white;
        }
        
        .btn-scan:active {
            transform: translateY(0);
        }
        
        .input-group-custom {
            position: relative;
        }
        
        .input-group-custom .form-control {
            border-radius: 50px;
            padding: 15px 25px;
            font-size: 1.1rem;
            border: 2px solid #e0e0e0;
            transition: all 0.3s ease;
        }
        
        .input-group-custom .form-control:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 0.2rem rgba(59, 130, 246, 0.25);
        }
        
        .barcode-result-card {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 15px;
            padding: 30px;
            margin-top: 30px;
            border: 2px solid #28a745;
            animation: slideIn 0.5s ease;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .barcode-display {
            text-align: center;
            padding: 30px;
            background: white;
            border-radius: 15px;
            margin: 20px 0;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .barcode-display h3 {
            color: #3b82f6;
            font-weight: 700;
            margin-bottom: 20px;
        }
        
        .info-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-top: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            border: 1px solid rgba(59, 130, 246, 0.1);
        }
        
        .info-card h6 {
            color: #3b82f6;
            font-weight: 600;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #3b82f6;
        }
        
        .status-badge {
            padding: 8px 20px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .camera-error {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 10px;
            margin-top: 15px;
            display: none;
        }
        
        .btn-back-home {
            background: white;
            color: #3b82f6;
            border: 2px solid #3b82f6;
            padding: 10px 25px;
            border-radius: 50px;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-back-home:hover {
            background: #3b82f6;
            color: white;
            transform: translateX(-5px);
        }
        
        @media (max-width: 768px) {
            .scanner-body {
                padding: 20px;
            }
            
            .scanner-header h1 {
                font-size: 1.5rem;
            }
            
            #video-container {
                min-width: 100%;
                max-width: 100%;
                min-height: 300px;
            }
            
            #video {
                min-height: 300px;
            }
            
            .scan-overlay {
                width: 90%;
                min-width: 90%;
                max-width: 90%;
                height: 120px;
            }
        }
    </style>
</head>
<body>
    <div class="barcode-scanner-container">
        <div class="scanner-card">
            <div class="scanner-header">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <a href="<?php echo htmlspecialchars($dashboardUrl); ?>" class="btn-back-home">
                        <i class="bi bi-arrow-right"></i>
                        <span><?php echo isset($lang['back']) ? $lang['back'] : 'رجوع'; ?></span>
                    </a>
                    <h1 class="flex-grow-1 text-center">
                        <i class="bi bi-upc-scan me-2"></i>
                        <?php echo isset($lang['barcode_scan']) ? $lang['barcode_scan'] : 'فحص الباركود'; ?>
                    </h1>
                    <div style="width: 100px;"></div> <!-- Spacer for alignment -->
                </div>
                <p class="mb-0 opacity-75">امسح الباركود أو أدخل رقم التشغيلة يدوياً</p>
            </div>
            
            <div class="scanner-body">
                <!-- قسم الكاميرا -->
                <div class="camera-section" id="cameraSection">
                    <div class="text-center mb-3">
                        <button type="button" class="btn btn-scan" id="startCameraBtn">
                            <i class="bi bi-camera-video me-2"></i>
                            <?php echo isset($lang['start_camera']) ? $lang['start_camera'] : 'تشغيل الكاميرا'; ?>
                        </button>
                        <button type="button" class="btn btn-danger d-none" id="stopCameraBtn" style="border-radius: 50px; padding: 15px 40px;">
                            <i class="bi bi-camera-video-off me-2"></i>
                            <?php echo isset($lang['stop_camera']) ? $lang['stop_camera'] : 'إيقاف الكاميرا'; ?>
                        </button>
                    </div>
                    
                    <div id="video-container">
                        <video id="video" autoplay playsinline></video>
                        <canvas id="canvas"></canvas>
                        <div class="scan-overlay">
                            <div class="scan-line"></div>
                        </div>
                    </div>
                    
                    <div class="camera-error" id="cameraError">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <span id="cameraErrorText">حدث خطأ في الوصول إلى الكاميرا</span>
                    </div>
                </div>
                
                <!-- قسم الإدخال اليدوي -->
                <form method="POST" id="scanForm">
                    <div class="mb-4">
                        <label class="form-label fw-bold mb-3">
                            <i class="bi bi-keyboard me-2"></i>
                            <?php echo isset($lang['manual_entry']) ? $lang['manual_entry'] : 'إدخال يدوي'; ?>
                        </label>
                        <div class="input-group-custom">
                            <input type="text" 
                                   class="form-control form-control-lg" 
                                   name="batch_number" 
                                   id="batchInput" 
                                   placeholder="<?php echo isset($lang['enter_batch_number']) ? $lang['enter_batch_number'] : 'أدخل رقم التشغيلة أو امسح الباركود'; ?>" 
                                   autofocus 
                                   required>
                        </div>
                        <small class="text-muted d-block mt-2">
                            <i class="bi bi-info-circle me-1"></i>
                            <?php echo isset($lang['barcode_hint']) ? $lang['barcode_hint'] : 'يمكنك استخدام قارئ الباركود أو إدخال الرقم يدوياً'; ?>
                        </small>
                    </div>
                    
                    <div class="text-center mt-4">
                        <button type="submit" class="btn btn-scan btn-lg">
                            <i class="bi bi-search me-2"></i>
                            <?php echo isset($lang['search']) ? $lang['search'] : 'فحص'; ?>
                        </button>
                    </div>
                </form>
                
                <!-- نتائج الفحص -->
                <?php if ($batch): ?>
                    <div class="barcode-result-card">
                        <div class="alert alert-success mb-4">
                            <i class="bi bi-check-circle-fill me-2"></i>
                            <strong><?php echo isset($lang['batch_found']) ? $lang['batch_found'] : 'تم العثور على رقم التشغيلة'; ?></strong>
                        </div>
                        
                        <div class="barcode-display">
                            <h3><?php echo htmlspecialchars($batch['batch_number']); ?></h3>
                            <div class="mt-3">
                                <?php echo generateBarcode($batch['batch_number'], 'barcode'); ?>
                            </div>
                        </div>
                        
                        <div class="info-card">
                            <h6><i class="bi bi-info-circle me-2"></i><?php echo isset($lang['product_info']) ? $lang['product_info'] : 'معلومات المنتج'; ?></h6>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <strong><?php echo isset($lang['product']) ? $lang['product'] : 'المنتج'; ?>:</strong>
                                    <div class="text-muted"><?php echo htmlspecialchars($batch['product_name'] ?? '-'); ?></div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <strong><?php echo isset($lang['production_date']) ? $lang['production_date'] : 'تاريخ الإنتاج'; ?>:</strong>
                                    <div class="text-muted"><?php echo formatDate($batch['production_date']); ?></div>
                                </div>
                                <?php if ($batch['expiry_date']): ?>
                                <div class="col-md-6 mb-3">
                                    <strong><?php echo isset($lang['expiry_date']) ? $lang['expiry_date'] : 'تاريخ انتهاء الصلاحية'; ?>:</strong>
                                    <div class="text-muted"><?php echo formatDate($batch['expiry_date']); ?></div>
                                </div>
                                <?php endif; ?>
                                <div class="col-md-6 mb-3">
                                    <strong><?php echo isset($lang['quantity']) ? $lang['quantity'] : 'الكمية'; ?>:</strong>
                                    <div class="text-muted"><?php echo $batch['quantity']; ?> <?php echo isset($lang['piece']) ? $lang['piece'] : 'قطعة'; ?></div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <strong><?php echo isset($lang['honey_supplier']) ? $lang['honey_supplier'] : 'مورد العسل'; ?>:</strong>
                                    <div class="text-muted"><?php echo htmlspecialchars($batch['honey_supplier_name'] ?? '-'); ?></div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <strong><?php echo isset($lang['packaging_supplier']) ? $lang['packaging_supplier'] : 'مورد أدوات التعبئة'; ?>:</strong>
                                    <div class="text-muted"><?php echo htmlspecialchars($batch['packaging_supplier_name'] ?? '-'); ?></div>
                                </div>
                                <div class="col-md-12 mb-3">
                                    <strong><?php echo isset($lang['status']) ? $lang['status'] : 'الحالة'; ?>:</strong>
                                    <div class="mt-2">
                                        <span class="badge status-badge bg-<?php 
                                            echo $batch['status'] === 'sold' ? 'success' : 
                                                ($batch['status'] === 'in_stock' ? 'info' : 
                                                ($batch['status'] === 'expired' ? 'danger' : 'warning')); 
                                        ?>">
                                            <?php 
                                            $statuses = [
                                                'in_production' => isset($lang['in_production']) ? $lang['in_production'] : 'قيد الإنتاج',
                                                'completed' => isset($lang['completed']) ? $lang['completed'] : 'مكتمل',
                                                'in_stock' => isset($lang['in_stock']) ? $lang['in_stock'] : 'في المخزون',
                                                'sold' => isset($lang['sold']) ? $lang['sold'] : 'مباع',
                                                'expired' => isset($lang['expired']) ? $lang['expired'] : 'منتهي الصلاحية'
                                            ];
                                            echo $statuses[$batch['status']] ?? $batch['status'];
                                            ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if (!empty($batch['packaging_materials_details'])): ?>
                                <div class="mt-4">
                                    <strong><i class="bi bi-box-seam me-2"></i><?php echo isset($lang['packaging_materials']) ? $lang['packaging_materials'] : 'مواد التعبئة'; ?>:</strong>
                                    <ul class="mt-2">
                                        <?php foreach ($batch['packaging_materials_details'] as $material): ?>
                                            <li><?php echo htmlspecialchars($material['name']); ?> 
                                                <?php if (!empty($material['specifications'])): ?>
                                                    <span class="text-muted">(<?php echo htmlspecialchars($material['specifications']); ?>)</span>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($batch['workers_details'])): ?>
                                <div class="mt-4">
                                    <strong><i class="bi bi-people me-2"></i><?php echo isset($lang['workers']) ? $lang['workers'] : 'العمال الحاضرين'; ?>:</strong>
                                    <ul class="mt-2">
                                        <?php foreach ($batch['workers_details'] as $worker): ?>
                                            <li><?php echo htmlspecialchars($worker['full_name'] ?? $worker['username']); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                            
                            <div class="mt-4 d-flex gap-3 justify-content-center flex-wrap">
                                <a href="print_barcode.php?batch=<?php echo urlencode($batch['batch_number']); ?>&quantity=1" 
                                   class="btn btn-primary" target="_blank" style="border-radius: 50px; padding: 12px 30px;">
                                    <i class="bi bi-printer me-2"></i>
                                    <?php echo isset($lang['print_barcode']) ? $lang['print_barcode'] : 'طباعة باركود'; ?>
                                </a>
                                <a href="<?php echo getRelativeUrl('dashboard/production.php'); ?>?page=batch_numbers&id=<?php echo $batch['id']; ?>" 
                                   class="btn btn-info" style="border-radius: 50px; padding: 12px 30px;">
                                    <i class="bi bi-eye me-2"></i>
                                    <?php echo isset($lang['view_details']) ? $lang['view_details'] : 'عرض التفاصيل'; ?>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php elseif (!empty($batchNumber)): ?>
                    <div class="alert alert-danger mt-4" style="border-radius: 15px; padding: 20px;">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <strong><?php echo isset($lang['batch_not_found']) ? $lang['batch_not_found'] : 'رقم التشغيلة غير موجود'; ?>:</strong> 
                        <?php echo htmlspecialchars($batchNumber); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- مكتبة QuaggaJS لقراءة الباركود -->
    <script src="https://unpkg.com/quagga@0.12.1/dist/quagga.min.js"></script>
    <script>
        let currentStream = null;
        let scanning = false;
        
        const startCameraBtn = document.getElementById('startCameraBtn');
        const stopCameraBtn = document.getElementById('stopCameraBtn');
        const videoContainer = document.getElementById('video-container');
        const video = document.getElementById('video');
        const cameraSection = document.getElementById('cameraSection');
        const cameraError = document.getElementById('cameraError');
        const cameraErrorText = document.getElementById('cameraErrorText');
        const batchInput = document.getElementById('batchInput');
        
        // تشغيل الكاميرا
        startCameraBtn.addEventListener('click', async function() {
            try {
                if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                    throw new Error('الكاميرا غير مدعومة في هذا المتصفح');
                }
                
                const constraints = {
                    video: {
                        facingMode: 'environment', // كاميرا خلفية لقراءة الباركود
                        width: { ideal: 1280 },
                        height: { ideal: 720 }
                    }
                };
                
                currentStream = await navigator.mediaDevices.getUserMedia(constraints);
                video.srcObject = currentStream;
                videoContainer.style.display = 'block';
                cameraSection.classList.add('active');
                startCameraBtn.classList.add('d-none');
                stopCameraBtn.classList.remove('d-none');
                cameraError.style.display = 'none';
                
                // تهيئة QuaggaJS
                Quagga.init({
                    inputStream: {
                        name: "Live",
                        type: "LiveStream",
                        target: video,
                        constraints: constraints
                    },
                    decoder: {
                        readers: [
                            "code_128_reader",
                            "ean_reader",
                            "ean_8_reader",
                            "code_39_reader",
                            "code_39_vin_reader",
                            "codabar_reader",
                            "upc_reader",
                            "upc_e_reader",
                            "i2of5_reader"
                        ]
                    },
                    locate: true
                }, function(err) {
                    if (err) {
                        console.error('QuaggaJS Error:', err);
                        cameraErrorText.textContent = 'حدث خطأ في تهيئة قارئ الباركود';
                        cameraError.style.display = 'block';
                        return;
                    }
                    
                    Quagga.start();
                    scanning = true;
                });
                
                // معالجة قراءة الباركود
                Quagga.onDetected(function(result) {
                    if (result && result.codeResult && result.codeResult.code) {
                        let code = result.codeResult.code.trim();
                        
                        // تنظيف رقم التشغيلة (إضافة "BATCH: " إذا لم يكن موجوداً)
                        if (code && !code.startsWith('BATCH:')) {
                            // إذا كان الرقم يبدأ برقم (تاريخ)، أضف "BATCH: "
                            if (/^\d{8}/.test(code)) {
                                code = 'BATCH: ' + code;
                            }
                        }
                        
                        batchInput.value = code;
                        batchInput.focus();
                        
                        // إيقاف الكاميرا
                        stopCamera();
                        
                        // إرسال النموذج تلقائياً
                        setTimeout(() => {
                            document.getElementById('scanForm').submit();
                        }, 500);
                    }
                });
                
            } catch (error) {
                console.error('Camera Error:', error);
                cameraError.style.display = 'block';
                let errorMessage = 'فشل في الوصول إلى الكاميرا. يرجى التأكد من السماح بالوصول إلى الكاميرا.';
                
                if (error.name === 'NotAllowedError' || error.name === 'PermissionDeniedError') {
                    errorMessage = 'تم رفض الوصول إلى الكاميرا. يرجى السماح بالوصول في إعدادات المتصفح.';
                } else if (error.name === 'NotFoundError' || error.name === 'DevicesNotFoundError') {
                    errorMessage = 'لم يتم العثور على كاميرا. يرجى التأكد من وجود كاميرا متصلة.';
                }
                
                cameraErrorText.textContent = errorMessage;
            }
        });
        
        // إيقاف الكاميرا
        stopCameraBtn.addEventListener('click', stopCamera);
        
        function stopCamera() {
            if (scanning) {
                Quagga.stop();
                scanning = false;
            }
            
            if (currentStream) {
                currentStream.getTracks().forEach(track => track.stop());
                currentStream = null;
            }
            
            video.srcObject = null;
            videoContainer.style.display = 'none';
            cameraSection.classList.remove('active');
            startCameraBtn.classList.remove('d-none');
            stopCameraBtn.classList.add('d-none');
        }
        
        // التركيز على حقل الإدخال
        batchInput?.focus();
        
        // فحص تلقائي عند إدخال رقم
        batchInput?.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('scanForm').submit();
            }
        });
        
        // تنظيف عند إغلاق الصفحة
        window.addEventListener('beforeunload', stopCamera);
    </script>
</body>
</html>
