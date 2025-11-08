<?php
/**
 * أدوات مساعدة لإنشاء ملفات PDF مع دعم كامل للغة العربية
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

$autoloadCandidates = [
    realpath(__DIR__ . '/../vendor/autoload.php'),
    realpath(dirname(__DIR__, 2) . '/vendor/autoload.php'),
    realpath(__DIR__ . '/vendor/autoload.php'),
];

$autoloadLoaded = false;

foreach ($autoloadCandidates as $candidate) {
    if ($candidate !== false && is_readable($candidate)) {
        require_once $candidate;
        $autoloadLoaded = true;
        break;
    }
}

if (!$autoloadLoaded) {
    throw new RuntimeException(
        'تعذر تحميل ملف Composer autoload. تأكد من رفع مجلد vendor أو تثبيت الاعتمادات باستخدام: composer install'
    );
}

use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;

/**
 * إنشاء نسخة مهيأة من mPDF مع دعم الخطوط العربية و RTL.
 *
 * @param array<string, mixed> $overrides
 * @return Mpdf
 */
function createArabicMpdf(array $overrides = []): Mpdf
{
    if (!class_exists(Mpdf::class)) {
        throw new RuntimeException(
            'لم يتم العثور على مكتبة mPDF. يرجى تثبيتها باستخدام الأمر: composer require mpdf/mpdf'
        );
    }

    $defaultConfig = (new ConfigVariables())->getDefaults();
    $defaultFontConfig = (new FontVariables())->getDefaults();

    $fontDirs = $defaultConfig['fontDir'] ?? [];
    $fontData = $defaultFontConfig['fontdata'] ?? [];

    $projectsFontDir = realpath(__DIR__ . '/../assets/fonts');
    if ($projectsFontDir && is_dir($projectsFontDir)) {
        $fontDirs[] = $projectsFontDir;
    }

    if (!isset($fontData['amiri'])) {
        $fontData['amiri'] = [
            'R' => 'Amiri-Regular.ttf',
            'B' => 'Amiri-Bold.ttf',
            'I' => 'Amiri-Italic.ttf',
            'BI' => 'Amiri-BoldItalic.ttf',
        ];
    }

    $config = array_merge([
        'mode'              => 'utf-8',
        'format'            => 'A4',
        'directionality'    => 'rtl',
        'tempDir'           => defined('MPDF_TEMP_PATH') ? MPDF_TEMP_PATH : sys_get_temp_dir(),
        'fontDir'           => $fontDirs,
        'fontdata'          => $fontData,
        'default_font'      => 'amiri',
        'useSubstitutions'  => true,
        'useKerning'        => true,
        'autoLangToFont'    => true,
    ], $overrides);

    $mpdf = new Mpdf($config);
    $mpdf->SetDisplayMode('fullpage');
    $mpdf->SetDirectionality('rtl');
    $mpdf->autoLangToFont = true;
    $mpdf->autoScriptToLang = true;

    return $mpdf;
}


