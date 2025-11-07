<?php
/**
 * إنشاء أيقونات بسيطة باستخدام SVG (بدون PHP GD)
 * يعمل على جميع الخوادم
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/../../includes/config.php';

$iconsDir = __DIR__;
$sizes = [16, 32, 72, 96, 128, 144, 152, 180, 192, 384, 512];

// دالة لإنشاء SVG
function createIconSVG($size) {
    $primaryColor = '#1e3a5f';
    $secondaryColor = '#2c5282';
    $textColor = '#FFFFFF';
    
    $svg = '<?xml version="1.0" encoding="UTF-8"?>
<svg width="' . $size . '" height="' . $size . '" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
    <defs>
        <linearGradient id="grad' . $size . '" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" style="stop-color:#1e3a5f;stop-opacity:1" />
            <stop offset="100%" style="stop-color:#2c5282;stop-opacity:1" />
        </linearGradient>
    </defs>
    
    <!-- Background -->
    <rect width="' . $size . '" height="' . $size . '" rx="' . ($size * 0.18) . '" fill="url(#grad' . $size . ')"/>
    
    <!-- Circle background -->
    <circle cx="' . ($size / 2) . '" cy="' . ($size * 0.45) . '" r="' . ($size * 0.32) . '" fill="rgba(255,255,255,0.15)"/>
    
    <!-- Main icon - Letter ن -->
    <text x="' . ($size / 2) . '" y="' . ($size * 0.62) . '" 
          font-family="Arial, 'Segoe UI', sans-serif" 
          font-size="' . ($size * 0.45) . '" 
          font-weight="bold" 
          fill="' . $textColor . '" 
          text-anchor="middle" 
          dominant-baseline="middle"
          style="font-family: Arial, sans-serif;">ن</text>
    
    <!-- Decorative dots -->
    <circle cx="' . ($size * 0.25) . '" cy="' . ($size * 0.22) . '" r="' . ($size * 0.04) . '" fill="' . $textColor . '" opacity="0.5"/>
    <circle cx="' . ($size * 0.75) . '" cy="' . ($size * 0.22) . '" r="' . ($size * 0.04) . '" fill="' . $textColor . '" opacity="0.5"/>
</svg>';
    
    return $svg;
}

echo "بدء إنشاء الأيقونات SVG...\n\n";

// إنشاء SVG لكل حجم
foreach ($sizes as $size) {
    $svg = createIconSVG($size);
    $filename = $iconsDir . '/icon-' . $size . 'x' . $size . '.svg';
    file_put_contents($filename, $svg);
    echo "✓ تم إنشاء: icon-{$size}x{$size}.svg\n";
}

// إنشاء favicon.svg
$favicon = createIconSVG(32);
file_put_contents($iconsDir . '/favicon.svg', $favicon);
copy($iconsDir . '/icon-32x32.svg', $iconsDir . '/favicon.svg');
echo "✓ تم إنشاء: favicon.svg\n";

// إنشاء apple-touch-icon
copy($iconsDir . '/icon-180x180.svg', $iconsDir . '/apple-touch-icon.svg');
echo "✓ تم إنشاء: apple-touch-icon.svg\n";

echo "\n✓ تم إنشاء جميع الأيقونات SVG بنجاح!\n\n";

echo "ملاحظة: لتحويل SVG إلى PNG:\n";
echo "1. استخدم محول online: https://convertio.co/svg-png/\n";
echo "2. أو استخدم ImageMagick: convert icon.svg icon.png\n";
echo "3. أو استخدم Inkscape: inkscape icon.svg --export-png=icon.png\n\n";

echo "يمكنك استخدام SVG مباشرة في المتصفحات الحديثة!\n";

