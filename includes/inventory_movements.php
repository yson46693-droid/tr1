<?php
/**
 * نظام حركات المخزون
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit_log.php';

/**
 * تسجيل حركة مخزون
 */
function recordInventoryMovement($productId, $warehouseId, $type, $quantity, $referenceType = null, $referenceId = null, $notes = null, $createdBy = null) {
    try {
        $db = db();
        
        if ($createdBy === null) {
            require_once __DIR__ . '/auth.php';
            $currentUser = getCurrentUser();
            $createdBy = $currentUser['id'] ?? null;
        }
        
        if (!$createdBy) {
            return ['success' => false, 'message' => 'يجب تسجيل الدخول'];
        }
        
        // الحصول على الكمية الحالية
        $product = $db->queryOne(
            "SELECT quantity, warehouse_id FROM products WHERE id = ?",
            [$productId]
        );
        
        if (!$product) {
            return ['success' => false, 'message' => 'المنتج غير موجود'];
        }
        
        $quantityBefore = $product['quantity'];
        
        // حساب الكمية الجديدة
        $quantityAfter = $quantityBefore;
        switch ($type) {
            case 'in':
                $quantityAfter = $quantityBefore + $quantity;
                break;
            case 'out':
                $quantityAfter = $quantityBefore - $quantity;
                if ($quantityAfter < 0) {
                    return ['success' => false, 'message' => 'الكمية غير كافية في المخزون'];
                }
                break;
            case 'adjustment':
                $quantityAfter = $quantity;
                break;
            case 'transfer':
                // للتحويل، نحتاج معالجة خاصة
                $quantityAfter = $quantityBefore - $quantity;
                if ($quantityAfter < 0) {
                    return ['success' => false, 'message' => 'الكمية غير كافية في المخزون'];
                }
                break;
        }
        
        // تحديث كمية المنتج
        $updateSql = "UPDATE products SET quantity = ?, warehouse_id = ? WHERE id = ?";
        $db->execute($updateSql, [$quantityAfter, $warehouseId ?? $product['warehouse_id'], $productId]);
        
        // تسجيل الحركة
        $sql = "INSERT INTO inventory_movements 
                (product_id, warehouse_id, type, quantity, quantity_before, quantity_after, reference_type, reference_id, notes, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $result = $db->execute($sql, [
            $productId,
            $warehouseId ?? $product['warehouse_id'],
            $type,
            $quantity,
            $quantityBefore,
            $quantityAfter,
            $referenceType,
            $referenceId,
            $notes,
            $createdBy
        ]);
        
        // تسجيل سجل التدقيق
        logAudit($createdBy, 'inventory_movement', 'product', $productId, 
                 ['quantity_before' => $quantityBefore], 
                 ['quantity_after' => $quantityAfter, 'type' => $type]);
        
        return ['success' => true, 'movement_id' => $result['insert_id']];
        
    } catch (Exception $e) {
        error_log("Inventory Movement Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'حدث خطأ في تسجيل الحركة'];
    }
}

/**
 * الحصول على حركات المخزون
 */
function getInventoryMovements($filters = [], $limit = 100, $offset = 0) {
    $db = db();
    
    $sql = "SELECT im.*, p.name as product_name, w.name as warehouse_name, 
                   u.username as created_by_name
            FROM inventory_movements im
            LEFT JOIN products p ON im.product_id = p.id
            LEFT JOIN warehouses w ON im.warehouse_id = w.id
            LEFT JOIN users u ON im.created_by = u.id
            WHERE 1=1";
    
    $params = [];
    
    if (!empty($filters['product_id'])) {
        $sql .= " AND im.product_id = ?";
        $params[] = $filters['product_id'];
    }
    
    if (!empty($filters['warehouse_id'])) {
        $sql .= " AND im.warehouse_id = ?";
        $params[] = $filters['warehouse_id'];
    }
    
    if (!empty($filters['type'])) {
        $sql .= " AND im.type = ?";
        $params[] = $filters['type'];
    }
    
    if (!empty($filters['date_from'])) {
        $sql .= " AND DATE(im.created_at) >= ?";
        $params[] = $filters['date_from'];
    }
    
    if (!empty($filters['date_to'])) {
        $sql .= " AND DATE(im.created_at) <= ?";
        $params[] = $filters['date_to'];
    }
    
    $sql .= " ORDER BY im.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    
    return $db->query($sql, $params);
}

/**
 * الحصول على عدد حركات المخزون
 */
function getInventoryMovementsCount($filters = []) {
    $db = db();
    
    $sql = "SELECT COUNT(*) as count FROM inventory_movements WHERE 1=1";
    $params = [];
    
    if (!empty($filters['product_id'])) {
        $sql .= " AND product_id = ?";
        $params[] = $filters['product_id'];
    }
    
    if (!empty($filters['warehouse_id'])) {
        $sql .= " AND warehouse_id = ?";
        $params[] = $filters['warehouse_id'];
    }
    
    if (!empty($filters['type'])) {
        $sql .= " AND type = ?";
        $params[] = $filters['type'];
    }
    
    if (!empty($filters['date_from'])) {
        $sql .= " AND DATE(created_at) >= ?";
        $params[] = $filters['date_from'];
    }
    
    if (!empty($filters['date_to'])) {
        $sql .= " AND DATE(created_at) <= ?";
        $params[] = $filters['date_to'];
    }
    
    $result = $db->queryOne($sql, $params);
    return $result['count'] ?? 0;
}

/**
 * الحصول على تقرير استهلاك المواد
 */
function getMaterialConsumptionReport($productId = null, $dateFrom = null, $dateTo = null) {
    $db = db();
    
    $sql = "SELECT im.product_id, p.name as product_name, 
                   SUM(CASE WHEN im.type = 'out' THEN im.quantity ELSE 0 END) as total_out,
                   SUM(CASE WHEN im.type = 'in' THEN im.quantity ELSE 0 END) as total_in,
                   (SUM(CASE WHEN im.type = 'in' THEN im.quantity ELSE 0 END) - 
                    SUM(CASE WHEN im.type = 'out' THEN im.quantity ELSE 0 END)) as net_consumption
            FROM inventory_movements im
            LEFT JOIN products p ON im.product_id = p.id
            WHERE 1=1";
    
    $params = [];
    
    if ($productId) {
        $sql .= " AND im.product_id = ?";
        $params[] = $productId;
    }
    
    if ($dateFrom) {
        $sql .= " AND DATE(im.created_at) >= ?";
        $params[] = $dateFrom;
    }
    
    if ($dateTo) {
        $sql .= " AND DATE(im.created_at) <= ?";
        $params[] = $dateTo;
    }
    
    $sql .= " GROUP BY im.product_id, p.name
              ORDER BY net_consumption DESC";
    
    return $db->query($sql, $params);
}

