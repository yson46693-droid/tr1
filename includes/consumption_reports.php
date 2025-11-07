<?php
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory_movements.php';
require_once __DIR__ . '/simple_export.php';
require_once __DIR__ . '/reports.php';

function consumptionGetTableColumns($table)
{
    static $cache = [];
    $key = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    if ($key === '') {
        return [];
    }
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $db = db();
    try {
        $rows = $db->query("SHOW COLUMNS FROM `{$key}`");
        $cols = [];
        foreach ($rows as $row) {
            $field = $row['Field'] ?? null;
            if ($field) {
                $cols[$field] = true;
            }
        }
        $cache[$key] = $cols;
        return $cols;
    } catch (Exception $e) {
        $cache[$key] = [];
        return [];
    }
}

function consumptionGetPackagingMap()
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $db = db();
    $map = [];
    try {
        $tableCheck = $db->queryOne("SHOW TABLES LIKE 'packaging_materials'");
        if (!empty($tableCheck)) {
            $rows = $db->query("SELECT id, material_id FROM packaging_materials");
            foreach ($rows as $row) {
                $id = intval($row['id'] ?? 0);
                $materialId = intval($row['material_id'] ?? 0);
                if ($id > 0) {
                    $map[$id] = true;
                }
                if ($materialId > 0) {
                    $map[$materialId] = true;
                }
            }
        }
    } catch (Exception $e) {
        $map = [];
    }
    return $map;
}

function consumptionClassifyProduct($product, $packagingMap)
{
    $id = intval($product['product_id'] ?? 0);
    $name = mb_strtolower($product['name'] ?? '');
    $category = mb_strtolower($product['category'] ?? '');
    $type = mb_strtolower($product['type'] ?? '');
    $spec = mb_strtolower($product['specifications'] ?? '');
    $materialType = mb_strtolower($product['material_type'] ?? '');
    if ($id > 0 && isset($packagingMap[$id])) {
        return ['packaging', 'general', 'أدوات التعبئة'];
    }
    $packKeywords = [
        'تغليف', 'pack', 'عبوة', 'زجاجة', 'غطاء', 'label', 'ملصق', 'كرتون', 'قارورة', 'علبة'
    ];
    foreach ($packKeywords as $keyword) {
        if (mb_strpos($name, $keyword) !== false || mb_strpos($category, $keyword) !== false || mb_strpos($type, $keyword) !== false || mb_strpos($spec, $keyword) !== false) {
            return ['packaging', 'general', 'أدوات التعبئة'];
        }
    }
    $rawCategories = [
        'honey' => [
            'label' => 'منتجات العسل',
            'keywords' => ['عسل', 'honey']
        ],
        'olive_oil' => [
            'label' => 'زيت الزيتون',
            'keywords' => ['زيت', 'olive']
        ],
        'beeswax' => [
            'label' => 'شمع العسل',
            'keywords' => ['شمع', 'wax']
        ],
        'derivatives' => [
            'label' => 'المشتقات',
            'keywords' => ['مشتق', 'derivative', 'extract', 'essence']
        ],
        'nuts' => [
            'label' => 'المكسرات',
            'keywords' => ['مكسر', 'nut', 'لوز', 'بندق', 'كاجو', 'فستق', 'عين جمل', 'سوداني', 'pistachio', 'cashew', 'almond', 'hazelnut', 'walnut', 'peanut']
        ]
    ];
    foreach ($rawCategories as $slug => $data) {
        foreach ($data['keywords'] as $keyword) {
            if (mb_strpos($name, $keyword) !== false || mb_strpos($category, $keyword) !== false || mb_strpos($type, $keyword) !== false || mb_strpos($spec, $keyword) !== false || mb_strpos($materialType, $keyword) !== false) {
                return ['raw', $slug, $data['label']];
            }
        }
    }
    if ($materialType !== '') {
        $materialMatches = [
            'honey_raw' => ['honey', 'منتجات العسل'],
            'honey_filtered' => ['honey', 'منتجات العسل'],
            'olive_oil' => ['olive_oil', 'زيت الزيتون'],
            'beeswax' => ['beeswax', 'شمع العسل'],
            'derivatives' => ['derivatives', 'المشتقات'],
            'nuts' => ['nuts', 'المكسرات']
        ];
        if (isset($materialMatches[$materialType])) {
            return ['raw', $materialMatches[$materialType][0], $materialMatches[$materialType][1]];
        }
    }
    return null;
}

function consumptionFormatNumber($value)
{
    return round((float)$value, 3);
}

function getConsumptionSummary($dateFrom, $dateTo)
{
    $db = db();
    $from = $dateFrom ?: date('Y-m-d');
    $to = $dateTo ?: $from;
    if (strtotime($from) > strtotime($to)) {
        $temp = $from;
        $from = $to;
        $to = $temp;
    }
    $productsColumns = consumptionGetTableColumns('products');
    $select = [
        'im.product_id',
        'p.name',
        "SUM(CASE WHEN im.type = 'out' THEN im.quantity ELSE 0 END) AS total_out",
        "SUM(CASE WHEN im.type = 'in' THEN im.quantity ELSE 0 END) AS total_in",
        'COUNT(*) AS movement_count',
        'MIN(im.created_at) AS first_movement',
        'MAX(im.created_at) AS last_movement'
    ];
    $optionalCols = ['category', 'type', 'specifications', 'unit', 'material_type'];
    foreach ($optionalCols as $col) {
        if (isset($productsColumns[$col])) {
            $select[] = "p.{$col}";
        }
    }
    $sql = "SELECT " . implode(', ', $select) . " FROM inventory_movements im INNER JOIN products p ON im.product_id = p.id WHERE DATE(im.created_at) BETWEEN ? AND ? GROUP BY im.product_id";
    $rows = [];
    try {
        $rows = $db->query($sql, [$from, $to]);
    } catch (Exception $e) {
        $rows = [];
    }
    $packagingMap = consumptionGetPackagingMap();
    $summary = [
        'date_from' => $from,
        'date_to' => $to,
        'generated_at' => date('Y-m-d H:i:s'),
        'packaging' => [
            'items' => [],
            'total_out' => 0,
            'total_in' => 0,
            'net' => 0,
            'sub_totals' => []
        ],
        'raw' => [
            'items' => [],
            'total_out' => 0,
            'total_in' => 0,
            'net' => 0,
            'sub_totals' => []
        ]
    ];
    foreach ($rows as $row) {
        $totalOut = (float)($row['total_out'] ?? 0);
        $totalIn = (float)($row['total_in'] ?? 0);
        if ($totalOut == 0 && $totalIn == 0) {
            continue;
        }
        $classification = consumptionClassifyProduct($row, $packagingMap);
        if (!$classification) {
            continue;
        }
        [$category, $subKey, $subLabel] = $classification;
        $item = [
            'name' => $row['name'] ?? ('#' . $row['product_id']),
            'sub_category' => $subLabel,
            'total_out' => consumptionFormatNumber($totalOut),
            'total_in' => consumptionFormatNumber($totalIn),
            'net' => consumptionFormatNumber($totalOut - $totalIn),
            'movements' => intval($row['movement_count'] ?? 0),
            'unit' => $row['unit'] ?? ''
        ];
        if ($category === 'packaging') {
            $summary['packaging']['items'][] = $item;
            $summary['packaging']['total_out'] += $item['total_out'];
            $summary['packaging']['total_in'] += $item['total_in'];
        } elseif ($category === 'raw') {
            $summary['raw']['items'][] = $item;
            $summary['raw']['total_out'] += $item['total_out'];
            $summary['raw']['total_in'] += $item['total_in'];
            if (!isset($summary['raw']['sub_totals'][$subKey])) {
                $summary['raw']['sub_totals'][$subKey] = [
                    'label' => $subLabel,
                    'total_out' => 0,
                    'total_in' => 0,
                    'net' => 0
                ];
            }
            $summary['raw']['sub_totals'][$subKey]['total_out'] += $item['total_out'];
            $summary['raw']['sub_totals'][$subKey]['total_in'] += $item['total_in'];
        }
    }
    usort($summary['packaging']['items'], function ($a, $b) {
        return $b['total_out'] <=> $a['total_out'];
    });
    usort($summary['raw']['items'], function ($a, $b) {
        return $b['total_out'] <=> $a['total_out'];
    });
    $summary['packaging']['net'] = consumptionFormatNumber($summary['packaging']['total_out'] - $summary['packaging']['total_in']);
    $summary['raw']['net'] = consumptionFormatNumber($summary['raw']['total_out'] - $summary['raw']['total_in']);
    foreach ($summary['raw']['sub_totals'] as $key => $row) {
        $summary['raw']['sub_totals'][$key]['net'] = consumptionFormatNumber($row['total_out'] - $row['total_in']);
    }
    return $summary;
}

function buildConsumptionReportHtml($summary, $meta)
{
    $title = $meta['title'] ?? 'تقرير الاستهلاك';
    $periodLabel = $meta['period'] ?? '';
    $scopeLabel = $meta['scope'] ?? '';
    $primary = '#1e3a5f';
    $secondary = '#2c5282';
    $accent = '#3498db';
    $gradient = 'linear-gradient(135deg, #1e3a5f 0%, #3498db 100%)';
    $html = '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>' . htmlspecialchars($title) . '</title><style>
    body{font-family:"Segoe UI",Arial,sans-serif;background:#f5f8fd;color:#1f2937;padding:40px;}
    .header{background:' . $gradient . ';color:#fff;padding:30px;border-radius:18px;box-shadow:0 12px 32px rgba(30,58,95,0.28);margin-bottom:35px;text-align:center;}
    .header h1{font-size:28px;margin:0;}
    .header p{margin:8px 0 0;font-size:15px;opacity:0.9;}
    .chips{display:flex;gap:12px;flex-wrap:wrap;justify-content:center;margin-top:18px;}
    .chip{background:rgba(255,255,255,0.18);padding:8px 18px;border-radius:999px;font-size:13px;backdrop-filter:blur(6px);}
    .cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:18px;margin-bottom:28px;}
    .card{background:#fff;border-radius:16px;padding:20px;box-shadow:0 10px 28px rgba(15,23,42,0.08);}
    .card h3{font-size:16px;margin:0;color:' . $primary . ';}
    .card .fig{font-size:24px;font-weight:700;margin-top:12px;color:' . $secondary . ';}
    .section{margin-top:40px;}
    .section-title{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;}
    .section-title h2{font-size:22px;color:' . $primary . ';margin:0;}
    .table-wrapper{background:#fff;border-radius:16px;box-shadow:0 10px 28px rgba(15,23,42,0.07);overflow:hidden;}
    table{width:100%;border-collapse:collapse;}
    th{background:' . $primary . ';color:#fff;padding:14px;font-size:13px;text-align:right;}
    td{padding:12px 14px;border-bottom:1px solid #eef2f7;font-size:13px;}
    tr:nth-child(even){background:#f8fafc;}
    .tag{display:inline-flex;align-items:center;padding:4px 12px;border-radius:999px;font-size:12px;background:rgba(52,152,219,0.15);color:' . $secondary . ';}
    .empty{padding:40px;text-align:center;color:#94a3b8;}
    .subtotals{display:flex;gap:12px;flex-wrap:wrap;margin-top:16px;}
    .subtotal{background:#fff;border-radius:14px;padding:16px;box-shadow:0 8px 20px rgba(15,23,42,0.06);min-width:180px;}
    .footer{margin-top:40px;text-align:center;font-size:12px;color:#94a3b8;}
    </style></head><body>';
    $html .= '<div class="header"><h1>' . htmlspecialchars($title) . '</h1>';
    if ($periodLabel !== '') {
        $html .= '<p>' . htmlspecialchars($periodLabel) . '</p>';
    }
    if ($scopeLabel !== '') {
        $html .= '<p>' . htmlspecialchars($scopeLabel) . '</p>';
    }
    $html .= '<div class="chips"><span class="chip">تاريخ الإنشاء: ' . htmlspecialchars($summary['generated_at']) . '</span><span class="chip">الفترة: ' . htmlspecialchars($summary['date_from']) . ' إلى ' . htmlspecialchars($summary['date_to']) . '</span></div></div>';
    $html .= '<div class="section"><div class="section-title"><h2>ملخص الأدوات</h2></div><div class="cards">';
    $html .= '<div class="card"><h3>استهلاك أدوات التعبئة</h3><div class="fig">' . number_format($summary['packaging']['total_out'], 3) . '</div></div>';
    $html .= '<div class="card"><h3>استهلاك المواد الخام</h3><div class="fig">' . number_format($summary['raw']['total_out'], 3) . '</div></div>';
    $html .= '<div class="card"><h3>الصافي الكلي</h3><div class="fig">' . number_format($summary['packaging']['net'] + $summary['raw']['net'], 3) . '</div></div>';
    $html .= '</div></div>';
    $html .= '<div class="section"><div class="section-title"><h2>أدوات التعبئة</h2></div>';
    if (empty($summary['packaging']['items'])) {
        $html .= '<div class="table-wrapper"><div class="empty">لا توجد بيانات</div></div>';
    } else {
        $html .= '<div class="table-wrapper"><table><thead><tr><th>المادة</th><th>إجمالي الاستخدام</th><th>الوارد</th><th>الصافي</th><th>عدد الحركات</th></tr></thead><tbody>';
        foreach ($summary['packaging']['items'] as $item) {
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($item['name']) . '</td>';
            $html .= '<td>' . number_format($item['total_out'], 3) . '</td>';
            $html .= '<td>' . number_format($item['total_in'], 3) . '</td>';
            $html .= '<td>' . number_format($item['net'], 3) . '</td>';
            $html .= '<td>' . intval($item['movements']) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';
    }
    $html .= '</div>';
    $html .= '<div class="section"><div class="section-title"><h2>المواد الخام</h2></div>';
    if (!empty($summary['raw']['sub_totals'])) {
        $html .= '<div class="subtotals">';
        foreach ($summary['raw']['sub_totals'] as $row) {
            $html .= '<div class="subtotal"><div class="tag">' . htmlspecialchars($row['label']) . '</div><div style="margin-top:10px;font-weight:600;color:' . $secondary . '">الاستهلاك: ' . number_format($row['total_out'], 3) . '</div><div style="margin-top:6px;">الصافي: ' . number_format($row['net'], 3) . '</div></div>';
        }
        $html .= '</div>';
    }
    if (empty($summary['raw']['items'])) {
        $html .= '<div class="table-wrapper"><div class="empty">لا توجد بيانات</div></div>';
    } else {
        $html .= '<div class="table-wrapper"><table><thead><tr><th>المادة</th><th>الفئة</th><th>إجمالي الاستخدام</th><th>الوارد</th><th>الصافي</th><th>عدد الحركات</th></tr></thead><tbody>';
        foreach ($summary['raw']['items'] as $item) {
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($item['name']) . '</td>';
            $html .= '<td><span class="tag">' . htmlspecialchars($item['sub_category']) . '</span></td>';
            $html .= '<td>' . number_format($item['total_out'], 3) . '</td>';
            $html .= '<td>' . number_format($item['total_in'], 3) . '</td>';
            $html .= '<td>' . number_format($item['net'], 3) . '</td>';
            $html .= '<td>' . intval($item['movements']) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';
    }
    $html .= '</div>';
    $html .= '<div class="footer">شركة البركة &mdash; نظام التقارير الذكي</div>';
    $html .= '</body></html>';
    return $html;
}

function generateConsumptionPdf($summary, $meta)
{
    $html = buildConsumptionReportHtml($summary, $meta);
    $fileName = sanitizeFileName(($meta['file_prefix'] ?? 'consumption_report') . '_' . $summary['date_from'] . '_' . $summary['date_to']) . '.html';
    $filePath = REPORTS_PATH . $fileName;
    $dir = rtrim(REPORTS_PATH, '/\\');
    if (!file_exists($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($filePath, $html);
    return $filePath;
}

function sendConsumptionReport($dateFrom, $dateTo, $scopeLabel)
{
    $summary = getConsumptionSummary($dateFrom, $dateTo);
    if (empty($summary['packaging']['items']) && empty($summary['raw']['items'])) {
        return ['success' => false, 'message' => 'لا توجد بيانات لاستهلاك الفترة المحددة'];
    }
    $title = 'تقرير استهلاك الإنتاج';
    $meta = [
        'title' => $title,
        'period' => 'الفترة: ' . $summary['date_from'] . ' - ' . $summary['date_to'],
        'scope' => $scopeLabel,
        'file_prefix' => 'consumption_report'
    ];
    $filePath = generateConsumptionPdf($summary, $meta);
    $result = sendReportAndDelete($filePath, $title, $scopeLabel);
    return $result;
}


