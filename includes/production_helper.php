<?php
/**
 * دوال مساعدة للإنتاج
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory_movements.php';

/**
 * التحقق من وجود عمود في جدول مع التخزين المؤقت للنتائج.
 */
function productionColumnExists($table, $column) {
    static $cache = [];

    $cacheKey = $table . '.' . $column;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
        return false;
    }

    $db = db();

    try {
        $result = $db->queryOne("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        $cache[$cacheKey] = !empty($result);
    } catch (Exception $e) {
        error_log("Production Helper: column existence check failed for {$table}.{$column}: " . $e->getMessage());
        $cache[$cacheKey] = false;
    }

    return $cache[$cacheKey];
}

/**
 * إنشاء تعبير SELECT آمن لعمود قد يكون غير موجود.
 */
function getColumnSelectExpression($table, $column, $alias = null, $tableAlias = null) {
    $alias = $alias ?: $column;

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $alias)) {
        throw new InvalidArgumentException('Invalid column alias supplied.');
    }

    $columnExists = productionColumnExists($table, $column);
    $qualifiedColumn = $tableAlias
        ? "`{$tableAlias}`.`{$column}`"
        : "`{$column}`";

    if ($columnExists) {
        if ($alias !== $column) {
            return "{$qualifiedColumn} AS `{$alias}`";
        }
        return $qualifiedColumn;
    }

    return "NULL AS `{$alias}`";
}

/**
 * ربط مواد التغليف بعملية إنتاج
 */
function linkPackagingToProduction($productionId, $materials) {
    $db = db();
    
    try {
        // التحقق من وجود عمود material_id أو product_id
        $materialIdColumnCheck = $db->queryOne("SHOW COLUMNS FROM production_materials LIKE 'material_id'");
        $productIdColumnCheck = $db->queryOne("SHOW COLUMNS FROM production_materials LIKE 'product_id'");
        $hasMaterialIdColumn = !empty($materialIdColumnCheck);
        $hasProductIdColumn = !empty($productIdColumnCheck);
        
        $materialColumn = $hasMaterialIdColumn ? 'material_id' : ($hasProductIdColumn ? 'product_id' : null);
        
        if (!$materialColumn) {
            error_log("Link Packaging Error: No material_id or product_id column found in production_materials");
            return false;
        }
        
        // حذف المواد السابقة
        $db->execute("DELETE FROM production_materials WHERE production_id = ?", [$productionId]);
        
        // إضافة المواد الجديدة
        foreach ($materials as $material) {
            $materialId = intval($material['material_id'] ?? $material['product_id'] ?? 0);
            $quantity = floatval($material['quantity'] ?? $material['quantity_used'] ?? 0);
            
            if ($materialId > 0 && $quantity > 0) {
                $db->execute(
                    "INSERT INTO production_materials (production_id, {$materialColumn}, quantity_used) 
                     VALUES (?, ?, ?)",
                    [$productionId, $materialId, $quantity]
                );
                
                // تسجيل حركة خروج للمواد
                recordInventoryMovement(
                    $materialId,
                    null,
                    'out',
                    $quantity,
                    'production',
                    $productionId,
                    "استخدام في الإنتاج #{$productionId}"
                );
            }
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Link Packaging Error: " . $e->getMessage());
        return false;
    }
}

/**
 * التأكد من وجود منتج يمثل مادة خام أو أداة تعبئة
 */
function ensureProductionMaterialProductId($name, $category = 'raw_material', $unit = null) {
    $name = trim((string)$name);
    if ($name === '') {
        return null;
    }

    static $productCache = [];
    $cacheKey = mb_strtolower($name, 'UTF-8') . '|' . $category;
    if (isset($productCache[$cacheKey])) {
        return $productCache[$cacheKey];
    }

    $db = db();

    try {
        $existing = $db->queryOne("SELECT id, unit FROM products WHERE name = ? LIMIT 1", [$name]);
        if ($existing && !empty($existing['id'])) {
            $productCache[$cacheKey] = (int)$existing['id'];
            return $productCache[$cacheKey];
        }

        $unitValue = $unit ?: ($category === 'packaging' ? 'قطعة' : 'كجم');
        $result = $db->execute(
            "INSERT INTO products (name, category, unit, status, quantity) VALUES (?, ?, ?, 'active', 0)",
            [$name, $category, $unitValue]
        );
        $productId = (int)($result['insert_id'] ?? 0);
        $productCache[$cacheKey] = $productId;
        return $productId ?: null;
    } catch (Exception $e) {
        error_log("ensureProductionMaterialProductId error: " . $e->getMessage());
        return null;
    }
}

/**
 * حفظ المواد المستخدمة في عملية إنتاج داخل جدول production_materials
 */
function storeProductionMaterialsUsage($productionId, $rawMaterials = [], $packagingMaterials = []) {
    $productionId = intval($productionId);
    if ($productionId <= 0) {
        return;
    }

    try {
        $db = db();

        $tableCheck = $db->queryOne("SHOW TABLES LIKE 'production_materials'");
        if (empty($tableCheck)) {
            return;
        }

        $materialIdColumnCheck = $db->queryOne("SHOW COLUMNS FROM production_materials LIKE 'material_id'");
        $columnName = !empty($materialIdColumnCheck) ? 'material_id' : 'product_id';

        $materialsMap = [];
        $addMaterial = function($productId, $quantity) use (&$materialsMap) {
            $productId = intval($productId);
            $quantity = (float)$quantity;
            if ($productId <= 0 || $quantity <= 0) {
                return;
            }
            if (!isset($materialsMap[$productId])) {
                $materialsMap[$productId] = 0.0;
            }
            $materialsMap[$productId] += $quantity;
        };

        static $packagingTableExists = null;
        if ($packagingTableExists === null) {
            $packagingTableExists = !empty($db->queryOne("SHOW TABLES LIKE 'packaging_materials'"));
        }

        foreach ($packagingMaterials as $packItem) {
            $quantity = (float)($packItem['quantity'] ?? 0);
            if ($quantity <= 0) {
                continue;
            }

            $productId = isset($packItem['product_id']) ? (int)$packItem['product_id'] : 0;
            $packagingName = trim((string)($packItem['name'] ?? ''));
            $packagingUnit = $packItem['unit'] ?? null;

            if (!$productId && !empty($packItem['material_id']) && $packagingTableExists) {
                $packagingInfo = $db->queryOne(
                    "SELECT product_id, name, unit FROM packaging_materials WHERE id = ?",
                    [intval($packItem['material_id'])]
                );
                if ($packagingInfo) {
                    if (!empty($packagingInfo['product_id'])) {
                        $productId = (int)$packagingInfo['product_id'];
                    }
                    if ($packagingName === '' && !empty($packagingInfo['name'])) {
                        $packagingName = $packagingInfo['name'];
                    }
                    if ($packagingUnit === null && !empty($packagingInfo['unit'])) {
                        $packagingUnit = $packagingInfo['unit'];
                    }
                }
            }

            if (!$productId) {
                $nameForProduct = $packagingName !== '' ? $packagingName : ('مادة تعبئة #' . ($packItem['material_id'] ?? '?'));
                $productId = ensureProductionMaterialProductId($nameForProduct, 'packaging', $packagingUnit);
            }

            $addMaterial($productId, $quantity);
        }

        foreach ($rawMaterials as $rawItem) {
            $quantity = (float)($rawItem['quantity'] ?? 0);
            if ($quantity <= 0) {
                continue;
            }

            $productId = isset($rawItem['product_id']) ? (int)$rawItem['product_id'] : 0;
            $materialName = trim((string)($rawItem['display_name'] ?? $rawItem['material_name'] ?? ''));
            $materialUnit = $rawItem['unit'] ?? 'كجم';

            if (!$productId) {
                if ($materialName === '') {
                    $materialName = 'مادة خام';
                }
                $productId = ensureProductionMaterialProductId($materialName, 'raw_material', $materialUnit);
            }

            $addMaterial($productId, $quantity);
        }

        $db->execute("DELETE FROM production_materials WHERE production_id = ?", [$productionId]);

        foreach ($materialsMap as $productId => $totalQuantity) {
            $db->execute(
                "INSERT INTO production_materials (production_id, {$columnName}, quantity_used) VALUES (?, ?, ?)",
                [$productionId, $productId, $totalQuantity]
            );
        }
    } catch (Exception $e) {
        error_log("storeProductionMaterialsUsage error: " . $e->getMessage());
    }
}

/**
 * حساب تكلفة الإنتاج بناءً على المواد
 */
function calculateProductionCost($productionId) {
    $db = db();
    
    // التحقق من وجود عمود material_id أو product_id
    $materialIdColumnCheck = $db->queryOne("SHOW COLUMNS FROM production_materials LIKE 'material_id'");
    $productIdColumnCheck = $db->queryOne("SHOW COLUMNS FROM production_materials LIKE 'product_id'");
    $hasMaterialIdColumn = !empty($materialIdColumnCheck);
    $hasProductIdColumn = !empty($productIdColumnCheck);
    
    $materialColumn = $hasMaterialIdColumn ? 'material_id' : ($hasProductIdColumn ? 'product_id' : null);
    
    if (!$materialColumn) {
        return 0; // لا يوجد عمود للمواد
    }
    
    $materials = $db->query(
        "SELECT pm.quantity_used, p.unit_price
         FROM production_materials pm
         LEFT JOIN products p ON pm.{$materialColumn} = p.id
         WHERE pm.production_id = ?",
        [$productionId]
    );
    
    $totalCost = 0;
    foreach ($materials as $material) {
        $totalCost += ($material['quantity_used'] * ($material['unit_price'] ?? 0));
    }
    
    return $totalCost;
}

/**
 * الحصول على تقرير الإنتاجية
 */
function getProductivityReport($userId = null, $dateFrom = null, $dateTo = null) {
    $db = db();
    
    // التحقق من وجود عمود material_id أو product_id في production_materials
    $materialIdColumnCheck = $db->queryOne("SHOW COLUMNS FROM production_materials LIKE 'material_id'");
    $productIdColumnCheck = $db->queryOne("SHOW COLUMNS FROM production_materials LIKE 'product_id'");
    $hasMaterialIdColumn = !empty($materialIdColumnCheck);
    $hasProductIdColumn = !empty($productIdColumnCheck);
    
    $materialColumn = $hasMaterialIdColumn ? 'material_id' : ($hasProductIdColumn ? 'product_id' : null);
    
    // التحقق من وجود عمود date أو production_date في production
    $dateColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'date'");
    $productionDateColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'production_date'");
    $hasDateColumn = !empty($dateColumnCheck);
    $hasProductionDateColumn = !empty($productionDateColumnCheck);
    $dateColumn = $hasDateColumn ? 'date' : ($hasProductionDateColumn ? 'production_date' : 'created_at');
    
    // التحقق من وجود عمود user_id أو worker_id في production
    $userIdColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'user_id'");
    $workerIdColumnCheck = $db->queryOne("SHOW COLUMNS FROM production LIKE 'worker_id'");
    $hasUserIdColumn = !empty($userIdColumnCheck);
    $hasWorkerIdColumn = !empty($workerIdColumnCheck);
    $userIdColumn = $hasUserIdColumn ? 'user_id' : ($hasWorkerIdColumn ? 'worker_id' : null);
    
    // بناء استعلام materials_count و total_cost بشكل ديناميكي
    $materialsCountSubquery = "(SELECT COUNT(*) FROM production_materials pm WHERE pm.production_id = p.id)";
    
    $totalCostSubquery = "0"; // القيمة الافتراضية
    if ($materialColumn) {
        $totalCostSubquery = "(SELECT SUM(pm.quantity_used * pr2.unit_price) 
                               FROM production_materials pm 
                               LEFT JOIN products pr2 ON pm.{$materialColumn} = pr2.id 
                               WHERE pm.production_id = p.id)";
    }
    
    $sql = "SELECT p.*, pr.name as product_name, 
                   {$materialsCountSubquery} as materials_count,
                   {$totalCostSubquery} as total_cost";
    
    if ($userIdColumn) {
        $sql .= ", p.{$userIdColumn} as user_id, u.full_name as user_name";
    } else {
        $sql .= ", NULL as user_id, 'غير محدد' as user_name";
    }
    
    $sql .= " FROM production p
              LEFT JOIN products pr ON p.product_id = pr.id";
    
    if ($userIdColumn) {
        $sql .= " LEFT JOIN users u ON p.{$userIdColumn} = u.id";
    }
    
    $sql .= " WHERE p.status = 'approved'";
    
    $params = [];
    
    if ($userId && $userIdColumn) {
        $sql .= " AND p.{$userIdColumn} = ?";
        $params[] = $userId;
    }
    
    if ($dateFrom) {
        $sql .= " AND DATE(p.{$dateColumn}) >= ?";
        $params[] = $dateFrom;
    }
    
    if ($dateTo) {
        $sql .= " AND DATE(p.{$dateColumn}) <= ?";
        $params[] = $dateTo;
    }
    
    $sql .= " ORDER BY p.{$dateColumn} DESC, p.created_at DESC";
    
    return $db->query($sql, $params);
}

