<?php
/**
 * نظام تصدير مبسط - PDF, Excel, CSV
 * يعمل دائماً بدون مكتبات خارجية
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';

// التأكد من وجود الدالة getCurrentLanguage
if (!function_exists('getCurrentLanguage')) {
    function getCurrentLanguage() {
        return $_SESSION['language'] ?? DEFAULT_LANGUAGE;
    }
}

/**
 * تصدير PDF (HTML للطباعة)
 */
function exportPDF($data, $title, $filters = []) {
    $dir = getCurrentLanguage() === 'ar' ? 'rtl' : 'ltr';
    
    // بناء HTML
    $html = '<!DOCTYPE html>
<html lang="' . getCurrentLanguage() . '" dir="' . $dir . '">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . htmlspecialchars($title) . '</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Arial, "Segoe UI", Tahoma, sans-serif;
            padding: 20px;
            color: #333;
            direction: ' . $dir . ';
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 3px solid #1e3a5f;
            padding-bottom: 20px;
        }
        .header h1 {
            color: #1e3a5f;
            font-size: 24px;
            margin-bottom: 10px;
        }
        .header .company {
            color: #666;
            font-size: 16px;
            margin-bottom: 5px;
        }
        .header .date {
            color: #999;
            font-size: 14px;
        }
        .filters {
            margin-bottom: 20px;
            padding: 15px;
            background: #f5f5f5;
            border-radius: 5px;
        }
        .filters h3 {
            font-size: 16px;
            margin-bottom: 10px;
            color: #1e3a5f;
        }
        .filters p {
            margin: 5px 0;
            font-size: 14px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            font-size: 12px;
        }
        table th {
            background: #1e3a5f;
            color: white;
            padding: 12px;
            text-align: ' . ($dir === 'rtl' ? 'right' : 'left') . ';
            font-weight: bold;
            border: 1px solid #ddd;
        }
        table td {
            padding: 10px;
            border: 1px solid #ddd;
            text-align: ' . ($dir === 'rtl' ? 'right' : 'left') . ';
        }
        table tr:nth-child(even) {
            background: #f9f9f9;
        }
        table tr:hover {
            background: #f0f0f0;
        }
        .no-data {
            text-align: center;
            padding: 40px;
            color: #999;
            font-size: 16px;
        }
        @media print {
            body { padding: 10px; }
            .no-print { display: none; }
        }
        .print-btn {
            position: fixed;
            top: 20px;
            ' . ($dir === 'rtl' ? 'left' : 'right') . ': 20px;
            background: #1e3a5f;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            z-index: 1000;
        }
        .print-btn:hover {
            background: #2c5282;
        }
    </style>
</head>
<body>
    <button class="print-btn no-print" onclick="window.print()">🖨️ طباعة</button>
    
    <div class="header">
        <h1>' . htmlspecialchars($title) . '</h1>
        <div class="company">' . htmlspecialchars(COMPANY_NAME) . '</div>
        <div class="date">' . date('Y-m-d H:i:s') . '</div>
    </div>';
    
    // الفلاتر
    if (!empty($filters)) {
        $html .= '<div class="filters">
            <h3>الفلاتر:</h3>';
        foreach ($filters as $key => $value) {
            if (!empty($value)) {
                $html .= '<p><strong>' . htmlspecialchars($key) . ':</strong> ' . htmlspecialchars($value) . '</p>';
            }
        }
        $html .= '</div>';
    }
    
    // الجدول
    if (!empty($data) && is_array($data) && count($data) > 0) {
        $headers = array_keys($data[0]);
        
        $html .= '<table>
            <thead>
                <tr>';
        foreach ($headers as $header) {
            $html .= '<th>' . htmlspecialchars($header) . '</th>';
        }
        $html .= '</tr>
            </thead>
            <tbody>';
        
        foreach ($data as $row) {
            $html .= '<tr>';
            foreach ($headers as $header) {
                $html .= '<td>' . htmlspecialchars($row[$header] ?? '') . '</td>';
            }
            $html .= '</tr>';
        }
        
        $html .= '</tbody>
        </table>';
    } else {
        $html .= '<div class="no-data">لا توجد بيانات متاحة</div>';
    }
    
    $html .= '</body>
</html>';
    
    // حفظ الملف
    $fileName = sanitizeFileName($title) . '_' . date('Y-m-d_His') . '.html';
    $filePath = REPORTS_PATH . $fileName;
    
    // التأكد من وجود المجلد
    $reportsDir = rtrim(REPORTS_PATH, '/\\');
    if (!file_exists($reportsDir)) {
        if (!@mkdir($reportsDir, 0755, true)) {
            error_log("Failed to create reports directory: " . $reportsDir);
            error_log("Current working directory: " . getcwd());
            error_log("REPORTS_PATH: " . REPORTS_PATH);
            throw new Exception('فشل في إنشاء مجلد التقارير. يرجى التحقق من الصلاحيات.');
        }
    }
    
    // التحقق من صلاحيات الكتابة
    if (!is_writable($reportsDir)) {
        error_log("Reports directory is not writable: " . $reportsDir);
        throw new Exception('مجلد التقارير غير قابل للكتابة. يرجى التحقق من الصلاحيات.');
    }
    
    // حفظ الملف
    $result = @file_put_contents($filePath, $html);
    if ($result === false) {
        $error = error_get_last();
        error_log("Failed to save PDF file: " . ($error['message'] ?? 'Unknown error'));
        error_log("File path: " . $filePath);
        error_log("Content length: " . strlen($html));
        throw new Exception('فشل في حفظ ملف PDF. يرجى التحقق من الصلاحيات والمساحة المتاحة.');
    }
    
    // التحقق من أن الملف تم إنشاؤه بنجاح
    if (!file_exists($filePath) || filesize($filePath) === 0) {
        error_log("PDF file was not created properly or is empty: " . $filePath);
        throw new Exception('فشل في إنشاء ملف PDF أو الملف فارغ.');
    }
    
    error_log("PDF report created successfully: " . $filePath . " (" . filesize($filePath) . " bytes)");
    
    return $filePath;
}

/**
 * تصدير Excel/CSV
 */
function exportCSV($data, $title, $filters = []) {
    // تغيير الامتداد إلى CSV
    $fileName = sanitizeFileName($title) . '_' . date('Y-m-d_His') . '.csv';
    $filePath = REPORTS_PATH . $fileName;
    
    // التأكد من وجود المجلد
    $reportsDir = rtrim(REPORTS_PATH, '/\\');
    if (!file_exists($reportsDir)) {
        if (!@mkdir($reportsDir, 0755, true)) {
            error_log("Failed to create reports directory for CSV: " . $reportsDir);
            error_log("Current working directory: " . getcwd());
            error_log("REPORTS_PATH: " . REPORTS_PATH);
            throw new Exception('فشل في إنشاء مجلد التقارير. يرجى التحقق من الصلاحيات.');
        }
    }
    
    // التحقق من صلاحيات الكتابة
    if (!is_writable($reportsDir)) {
        error_log("Reports directory is not writable for CSV: " . $reportsDir);
        throw new Exception('مجلد التقارير غير قابل للكتابة. يرجى التحقق من الصلاحيات.');
    }
    
    // فتح الملف للكتابة
    $output = @fopen($filePath, 'w');
    if ($output === false) {
        $error = error_get_last();
        error_log("Failed to open CSV file for writing: " . ($error['message'] ?? 'Unknown error'));
        error_log("File path: " . $filePath);
        throw new Exception('فشل في فتح ملف CSV للكتابة. يرجى التحقق من الصلاحيات.');
    }
    
    try {
        // إضافة BOM للUTF-8 (للعربية)
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // العنوان
        fputcsv($output, [$title], ',');
        fputcsv($output, [COMPANY_NAME], ',');
        fputcsv($output, ['تاريخ التقرير: ' . date('Y-m-d H:i:s')], ',');
        fputcsv($output, [], ','); // سطر فارغ
        
        // الفلاتر
        if (!empty($filters)) {
            fputcsv($output, ['الفلاتر:'], ',');
            foreach ($filters as $key => $value) {
                if (!empty($value)) {
                    fputcsv($output, [$key . ': ' . $value], ',');
                }
            }
            fputcsv($output, [], ','); // سطر فارغ
        }
        
        // البيانات
        if (!empty($data) && is_array($data) && count($data) > 0) {
            $headers = array_keys($data[0]);
            fputcsv($output, $headers, ',');
            
            foreach ($data as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $values = [];
                foreach ($headers as $header) {
                    $values[] = $row[$header] ?? '';
                }
                fputcsv($output, $values, ',');
            }
        } else {
            fputcsv($output, ['الرسالة'], ',');
            fputcsv($output, ['لا توجد بيانات متاحة في الفترة المحددة'], ',');
        }
        
        fclose($output);
        
        // التحقق من أن الملف تم إنشاؤه
        if (!file_exists($filePath)) {
            error_log("CSV file was not created: " . $filePath);
            throw new Exception('فشل في إنشاء ملف CSV.');
        }
        
        if (filesize($filePath) === 0) {
            error_log("CSV file is empty: " . $filePath);
            throw new Exception('ملف CSV فارغ. لا توجد بيانات للتصدير.');
        }
        
        error_log("CSV report created successfully: " . $filePath . " (" . filesize($filePath) . " bytes)");
        
        return $filePath;
    } catch (Exception $e) {
        error_log("CSV export error: " . $e->getMessage());
        if (isset($output) && is_resource($output)) {
            @fclose($output);
        }
        if (isset($filePath) && file_exists($filePath)) {
            @unlink($filePath);
        }
        throw $e;
    }
}

/**
 * تنظيف اسم الملف
 */
function sanitizeFileName($fileName) {
    // إزالة الأحرف غير المسموحة
    $fileName = preg_replace('/[^a-zA-Z0-9_\x{0600}-\x{06FF}\s-]/u', '', $fileName);
    // استبدال المسافات بشرطة سفلية
    $fileName = preg_replace('/\s+/', '_', $fileName);
    return $fileName;
}

