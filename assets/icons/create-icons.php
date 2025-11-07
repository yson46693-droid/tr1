<?php
/**
 * إنشاء أيقونات PWA باستخدام GD
 */

if (!extension_loaded('gd')) {
    die('GD extension not loaded');
}

$sizes = [72, 96, 128, 144, 152, 192, 384, 512];
$bgColor = [30, 58, 95]; // #1e3a5f
$textColor = [255, 255, 255]; // white

foreach ($sizes as $size) {
    // إنشاء صورة
    $image = imagecreatetruecolor($size, $size);
    
    // خلفية
    $bg = imagecolorallocate($image, $bgColor[0], $bgColor[1], $bgColor[2]);
    imagefill($image, 0, 0, $bg);
    
    // النص
    $textColorAlloc = imagecolorallocate($image, $textColor[0], $textColor[1], $textColor[2]);
    $fontSize = $size * 0.4;
    $fontFile = __DIR__ . '/arial.ttf';
    
    // استخدام خط افتراضي إذا لم يكن متوفراً
    if (!file_exists($fontFile)) {
        // استخدام imagestring للخطوط البسيطة
        $text = 'ن';
        $textWidth = imagefontwidth(5) * strlen($text);
        $textHeight = imagefontheight(5);
        $x = ($size - $textWidth) / 2;
        $y = ($size - $textHeight) / 2;
        imagestring($image, 5, $x, $y, $text, $textColorAlloc);
    } else {
        // استخدام TrueType Font
        $bbox = imagettfbbox($fontSize, 0, $fontFile, 'ن');
        $textWidth = $bbox[4] - $bbox[0];
        $textHeight = $bbox[1] - $bbox[7];
        $x = ($size - $textWidth) / 2;
        $y = ($size + $textHeight) / 2;
        imagettftext($image, $fontSize, 0, $x, $y, $textColorAlloc, $fontFile, 'ن');
    }
    
    // حفظ الصورة
    $filename = "icon-{$size}x{$size}.png";
    imagepng($image, __DIR__ . '/' . $filename);
    imagedestroy($image);
    
    echo "Created: $filename\n";
}

echo "All icons created successfully!\n";

