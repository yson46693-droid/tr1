<?php
/**
 * إنشاء أيقونات PNG من SVG
 * يتطلب PHP GD أو Imagick
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/../../includes/config.php';

// إنشاء مجلد الأيقونات إذا لم يكن موجوداً
$iconsDir = __DIR__;

// الأحجام المطلوبة
$sizes = [72, 96, 128, 144, 152, 192, 384, 512];

// الألوان
$primaryColor = PRIMARY_COLOR; // #1e3a5f
$secondaryColor = '#2c5282';
$textColor = '#FFFFFF';

// دالة لإنشاء أيقونة PNG
function createIconPNG($size, $iconsDir) {
    global $primaryColor, $textColor;
    
    // إنشاء صورة جديدة
    $image = imagecreatetruecolor($size, $size);
    
    // تحويل الألوان من hex إلى RGB
    $primaryRGB = hexToRgb($primaryColor);
    $secondaryRGB = hexToRgb('#2c5282');
    $textRGB = hexToRgb($textColor);
    
    $primaryColorRes = imagecolorallocate($image, $primaryRGB['r'], $primaryRGB['g'], $primaryRGB['b']);
    $secondaryColorRes = imagecolorallocate($image, $secondaryRGB['r'], $secondaryRGB['g'], $secondaryRGB['b']);
    $textColorRes = imagecolorallocate($image, $textRGB['r'], $textRGB['g'], $textRGB['b']);
    $whiteColorRes = imagecolorallocate($image, 255, 255, 255);
    
    // خلفية متدرجة بسيطة
    $padding = $size * 0.05;
    
    // رسم خلفية مستديرة
    imagefilledrectangle($image, 0, 0, $size, $size, $primaryColorRes);
    
    // رسم دائرة خلفية
    $centerX = $size / 2;
    $centerY = $size / 2;
    $radius = $size * 0.35;
    
    // دائرة خلفية شفافة
    imagefilledellipse($image, $centerX, $centerY - $size * 0.05, $radius * 2, $radius * 2, 
                       imagecolorallocatealpha($image, 255, 255, 255, 180));
    
    // رسم النص "ن" (حرف عربي)
    $fontSize = $size * 0.4;
    $text = "ن";
    
    // استخدام خط افتراضي بسيط
    $bbox = imagettfbbox($fontSize, 0, __DIR__ . '/arial.ttf', $text);
    if (!$bbox) {
        // إذا لم يكن الخط متوفراً، استخدم imagestring
        imagestring($image, 5, $centerX - $size * 0.1, $centerY - $size * 0.15, "N", $textColorRes);
    } else {
        $textWidth = $bbox[4] - $bbox[0];
        $textHeight = $bbox[1] - $bbox[7];
        $x = $centerX - ($textWidth / 2);
        $y = $centerY + ($textHeight / 2);
        
        // محاولة استخدام خط عربي إذا كان متوفراً
        $arabicFont = __DIR__ . '/arial.ttf';
        if (file_exists($arabicFont)) {
            imagettftext($image, $fontSize, 0, $x, $y, $textColorRes, $arabicFont, $text);
        } else {
            // استخدام imagestring كبديل
            imagestring($image, 5, $centerX - $size * 0.1, $centerY - $size * 0.15, "N", $textColorRes);
        }
    }
    
    // نقاط زخرفية
    $dotRadius = $size * 0.03;
    imagefilledellipse($image, $centerX - $size * 0.25, $centerY - $size * 0.3, $dotRadius * 2, $dotRadius * 2, 
                       imagecolorallocatealpha($image, 255, 255, 255, 150));
    imagefilledellipse($image, $centerX + $size * 0.25, $centerY - $size * 0.3, $dotRadius * 2, $dotRadius * 2, 
                       imagecolorallocatealpha($image, 255, 255, 255, 150));
    
    // حفظ الصورة
    $filename = $iconsDir . '/icon-' . $size . 'x' . $size . '.png';
    imagepng($image, $filename);
    imagedestroy($image);
    
    return $filename;
}

// دالة لتحويل hex إلى RGB
function hexToRgb($hex) {
    $hex = str_replace('#', '', $hex);
    return [
        'r' => hexdec(substr($hex, 0, 2)),
        'g' => hexdec(substr($hex, 2, 2)),
        'b' => hexdec(substr($hex, 4, 2))
    ];
}

// التحقق من وجود GD
if (!function_exists('imagecreatetruecolor')) {
    die("خطأ: مكتبة GD غير متوفرة. يرجى تفعيلها في php.ini\n");
}

echo "بدء إنشاء الأيقونات...\n\n";

// إنشاء أيقونات PNG
foreach ($sizes as $size) {
    try {
        $filename = createIconPNG($size, $iconsDir);
        echo "✓ تم إنشاء: icon-{$size}x{$size}.png\n";
    } catch (Exception $e) {
        echo "✗ خطأ في إنشاء icon-{$size}x{$size}.png: " . $e->getMessage() . "\n";
    }
}

// إنشاء favicon.ico (16x16 و 32x32)
try {
    $favicon16 = createIconPNG(16, $iconsDir);
    $favicon32 = createIconPNG(32, $iconsDir);
    
    // تحويل إلى ICO (يحتاج مكتبة إضافية أو استخدام أداة خارجية)
    // للآن، سنستخدم PNG كـ favicon
    copy($iconsDir . '/icon-32x32.png', $iconsDir . '/favicon.png');
    
    echo "✓ تم إنشاء: favicon.png\n";
} catch (Exception $e) {
    echo "✗ خطأ في إنشاء favicon: " . $e->getMessage() . "\n";
}

// إنشاء apple-touch-icon (180x180)
try {
    $appleIcon = createIconPNG(180, $iconsDir);
    copy($iconsDir . '/icon-180x180.png', $iconsDir . '/apple-touch-icon.png');
    echo "✓ تم إنشاء: apple-touch-icon.png\n";
} catch (Exception $e) {
    echo "✗ خطأ في إنشاء apple-touch-icon: " . $e->getMessage() . "\n";
}

echo "\nتم إنشاء جميع الأيقونات بنجاح!\n";
echo "يمكنك الآن استخدامها في التطبيق.\n";

