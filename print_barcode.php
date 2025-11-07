<?php
/**
 * صفحة طباعة الباركود - مبسطة
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/batch_numbers.php';
require_once __DIR__ . '/includes/simple_barcode.php';

requireRole(['production', 'accountant', 'manager']);

$batchNumber = $_GET['batch'] ?? '';
$quantity = isset($_GET['quantity']) ? max(1, intval($_GET['quantity'])) : 1;
$format = $_GET['format'] ?? 'single';

if (empty($batchNumber)) {
    die('رقم التشغيلة مطلوب');
}

$batch = getBatchByNumber($batchNumber);
if (!$batch) {
    die('رقم التشغيلة غير موجود');
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>طباعة باركود - <?php echo htmlspecialchars($batchNumber); ?></title>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        @media print {
            .no-print { display: none !important; }
            @page { margin: 0.3cm; size: A4; }
            body { margin: 0; padding: 5px; }
            .barcode-item { page-break-inside: avoid; margin: 2mm; }
        }
        
        body {
            font-family: Arial, sans-serif;
            padding: 20px;
            background: #f5f5f5;
        }
        
        .print-controls {
            text-align: center;
            padding: 20px;
            background: white;
            border-radius: 5px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .print-controls h4 {
            margin-bottom: 10px;
            color: #1e3a5f;
        }
        
        .print-controls p {
            margin-bottom: 15px;
            color: #666;
        }
        
        .btn {
            padding: 10px 20px;
            margin: 0 5px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            font-weight: bold;
        }
        
        .btn-primary {
            background: #1e3a5f;
            color: white;
        }
        
        .btn-primary:hover {
            background: #2c5282;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .print-container {
            max-width: 210mm;
            margin: 0 auto;
            background: white;
            padding: 10px;
        }
        
        .barcode-item {
            border: 1px solid #000;
            padding: 8px;
            margin: 5px;
            display: inline-block;
            text-align: center;
            vertical-align: top;
            width: <?php echo $format === 'single' ? '100%' : '48mm'; ?>;
            min-width: 45mm;
            max-width: 50mm;
            min-height: 70mm;
            box-sizing: border-box;
        }
        
        .barcode-svg {
            margin: 5px 0;
            max-width: 100%;
            height: auto;
        }
        
        .barcode-label {
            font-weight: bold;
            font-size: 10px;
            margin-bottom: 5px;
            color: #1e3a5f;
        }
        
        .barcode-number {
            font-size: 9px;
            font-weight: bold;
            margin: 3px 0;
            word-break: break-all;
        }
        
        .barcode-info {
            font-size: 8px;
            margin-top: 5px;
            line-height: 1.3;
        }
        
        @media print {
            body { background: white; padding: 0; }
            .barcode-item {
                width: 48mm !important;
                min-width: 48mm !important;
                max-width: 48mm !important;
                height: 70mm !important;
            }
        }
    </style>
</head>
<body>
    <div class="print-controls no-print">
        <h4>طباعة باركود - <?php echo htmlspecialchars($batchNumber); ?></h4>
        <p>الكمية: <?php echo $quantity; ?> | التنسيق: <?php echo $format === 'single' ? 'فردية' : 'متعددة'; ?></p>
        <button onclick="window.print()" class="btn btn-primary">🖨️ طباعة</button>
        <button onclick="window.close()" class="btn btn-secondary">✕ إغلاق</button>
    </div>
    
    <div class="print-container">
        <?php for ($i = 0; $i < $quantity; $i++): ?>
            <div class="barcode-item">
                <div class="barcode-label"><?php echo htmlspecialchars(COMPANY_NAME); ?></div>
                <div class="barcode-label" style="font-size: 12px;"><?php echo htmlspecialchars($batch['product_name'] ?? ''); ?></div>
                
                <div class="barcode-svg">
                    <?php echo generateBarcode($batchNumber, 'barcode'); ?>
                </div>
                
                <div class="barcode-number"><?php echo htmlspecialchars($batchNumber); ?></div>
                
                <div class="barcode-info">
                    <div><strong>تاريخ الإنتاج:</strong> <?php echo formatDate($batch['production_date']); ?></div>
                    <?php if ($batch['expiry_date']): ?>
                        <div><strong>تاريخ انتهاء:</strong> <?php echo formatDate($batch['expiry_date']); ?></div>
                    <?php endif; ?>
                    <?php if ($batch['honey_supplier_name']): ?>
                        <div><strong>مورد:</strong> <?php echo htmlspecialchars($batch['honey_supplier_name']); ?></div>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if (($i + 1) % 2 == 0 && $format === 'multiple'): ?>
                <div style="page-break-after: always;"></div>
            <?php endif; ?>
        <?php endfor; ?>
    </div>
    
    <script>
        // طباعة تلقائية
        if (window.location.search.includes('print=1')) {
            setTimeout(function() {
                window.print();
            }, 500);
        }
    </script>
</body>
</html>

