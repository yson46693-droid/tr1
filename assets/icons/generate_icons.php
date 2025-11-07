<?php
/**
 * سكريبت لإنشاء أيقونات التطبيق بأحجام مختلفة
 * يجب تشغيله مرة واحدة لإنشاء جميع الأيقونات
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/../../includes/config.php';

// إنشاء مجلد الأيقونات إذا لم يكن موجوداً
$iconsDir = __DIR__;
if (!is_dir($iconsDir)) {
    mkdir($iconsDir, 0755, true);
}

// الألوان
$primaryColor = PRIMARY_COLOR; // #1e3a5f
$secondaryColor = '#2c5282';
$accentColor = '#3498db';
$textColor = '#FFFFFF';

// الأحجام المطلوبة
$sizes = [72, 96, 128, 144, 152, 192, 384, 512];

// SVG template
function generateSVG($size) {
    global $primaryColor, $textColor;
    
    $padding = $size * 0.1;
    $iconSize = $size - ($padding * 2);
    $center = $size / 2;
    
    $svg = '<?xml version="1.0" encoding="UTF-8"?>
<svg width="' . $size . '" height="' . $size . '" xmlns="http://www.w3.org/2000/svg">
    <defs>
        <linearGradient id="grad" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" style="stop-color:#1e3a5f;stop-opacity:1" />
            <stop offset="100%" style="stop-color:#2c5282;stop-opacity:1" />
        </linearGradient>
    </defs>
    <rect width="' . $size . '" height="' . $size . '" rx="' . ($size * 0.15) . '" fill="url(#grad)"/>
    
    <!-- Icon Background Circle -->
    <circle cx="' . $center . '" cy="' . ($center - $size * 0.05) . '" r="' . ($iconSize * 0.35) . '" fill="rgba(255,255,255,0.2)"/>
    
    <!-- Letter ن -->
    <text x="' . $center . '" y="' . ($center + $size * 0.12) . '" 
          font-family="Arial, sans-serif" 
          font-size="' . ($iconSize * 0.5) . '" 
          font-weight="bold" 
          fill="' . $textColor . '" 
          text-anchor="middle" 
          dominant-baseline="middle">ن</text>
    
    <!-- Decorative elements -->
    <circle cx="' . ($center - $iconSize * 0.25) . '" cy="' . ($center - $iconSize * 0.3) . '" r="' . ($iconSize * 0.05) . '" fill="' . $textColor . '" opacity="0.6"/>
    <circle cx="' . ($center + $iconSize * 0.25) . '" cy="' . ($center - $iconSize * 0.3) . '" r="' . ($iconSize * 0.05) . '" fill="' . $textColor . '" opacity="0.6"/>
</svg>';
    
    return $svg;
}

// إنشاء SVG لكل حجم
foreach ($sizes as $size) {
    $svg = generateSVG($size);
    $svgFile = $iconsDir . '/icon-' . $size . 'x' . $size . '.svg';
    file_put_contents($svgFile, $svg);
    
    echo "تم إنشاء: icon-{$size}x{$size}.svg\n";
}

// إنشاء favicon.ico بسيط (16x16, 32x32)
$faviconSizes = [16, 32];
$faviconData = '';

foreach ($faviconSizes as $size) {
    $svg = generateSVG($size);
    $faviconData .= $svg;
}

// ملاحظة: لتحويل SVG إلى PNG/ICO، يمكن استخدام:
// 1. ImageMagick: convert icon.svg icon.png
// 2. Inkscape: inkscape icon.svg --export-png=icon.png
// 3. أو استخدام مكتبة PHP GD/Imagick

echo "\nتم إنشاء جميع ملفات SVG!\n";
echo "لتحويل SVG إلى PNG، استخدم:\n";
echo "- ImageMagick: convert icon.svg icon.png\n";
echo "- أو استخدم أي محول SVG إلى PNG online\n";
echo "- أو استخدم مكتبة PHP GD/Imagick\n";

// إنشاء ملف HTML بسيط لمعاينة الأيقونات
$html = '<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>معاينة أيقونات التطبيق</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            padding: 20px;
            background: #f5f5f5;
        }
        .icon-preview {
            display: inline-block;
            margin: 10px;
            text-align: center;
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .icon-preview img {
            display: block;
            margin: 0 auto 10px;
        }
    </style>
</head>
<body>
    <h1>معاينة أيقونات التطبيق</h1>
    <div>';
    
foreach ($sizes as $size) {
    $html .= '<div class="icon-preview">
        <img src="icon-' . $size . 'x' . $size . '.svg" width="' . $size . '" height="' . $size . '" alt="' . $size . 'x' . $size . '">
        <div>' . $size . 'x' . $size . '</div>
    </div>';
}

$html .= '</div>
</body>
</html>';

file_put_contents($iconsDir . '/preview.html', $html);
echo "\nتم إنشاء ملف preview.html للمعاينة\n";

