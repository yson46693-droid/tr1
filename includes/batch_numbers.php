<?php
/**
 * نظام أرقام التشغيلة
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit_log.php';

/**
 * توليد رقم تشغيلة
 * تنسيق جديد: BATCH: DATE-SUP1-SUP2-id1-id2-id3-RANDOM
 * مثال: BATCH: 20251106-HNY001-PKG002-5-7-12-12345
 */
function generateBatchNumber($productId, $productionDate, $honeySupplierId = null, $packagingSupplierId = null, $workersIds = []) {
    $db = db();
    
    // التحقق من وجود المنتج
    $product = $db->queryOne("SELECT name, category FROM products WHERE id = ?", [$productId]);
    if (!$product) {
        return null;
    }
    
    // تاريخ الإنتاج (YYYYMMDD)
    $dateCode = date('Ymd', strtotime($productionDate));
    
    // كود مورد العسل (من supplier_code)
    $honeySupplierCode = '000';
    if ($honeySupplierId) {
        $honeySupplier = $db->queryOne("SELECT supplier_code FROM suppliers WHERE id = ?", [$honeySupplierId]);
        if ($honeySupplier && !empty($honeySupplier['supplier_code'])) {
            $honeySupplierCode = $honeySupplier['supplier_code'];
        } else {
            // Fallback: استخدام ID إذا لم يكن هناك supplier_code
            $honeySupplierCode = str_pad($honeySupplierId, 3, '0', STR_PAD_LEFT);
        }
    }
    
    // كود مورد التعبئة (من supplier_code)
    $packagingSupplierCode = '000';
    if ($packagingSupplierId) {
        $packagingSupplier = $db->queryOne("SELECT supplier_code FROM suppliers WHERE id = ?", [$packagingSupplierId]);
        if ($packagingSupplier && !empty($packagingSupplier['supplier_code'])) {
            $packagingSupplierCode = $packagingSupplier['supplier_code'];
        } else {
            // Fallback: استخدام ID إذا لم يكن هناك supplier_code
            $packagingSupplierCode = str_pad($packagingSupplierId, 3, '0', STR_PAD_LEFT);
        }
    }
    
    // معرفات عمال الإنتاج الحاضرين (id1-id2-id3...)
    $workersCodes = [];
    if (!empty($workersIds) && is_array($workersIds)) {
        foreach ($workersIds as $workerId) {
            $workerId = intval($workerId);
            if ($workerId > 0) {
                $workersCodes[] = $workerId;
            }
        }
    }
    
    // إذا لم يكن هناك عمال، استخدم 0
    if (empty($workersCodes)) {
        $workersCodes = ['0'];
    }
    $workersString = implode('-', $workersCodes);
    
    // 5 أرقام عشوائية
    $randomCode = str_pad(rand(0, 99999), 5, '0', STR_PAD_LEFT);
    
    // بناء رقم التشغيلة: BATCH: DATE-SUP1-SUP2-id1-id2-id3-RANDOM
    $batchNumber = sprintf('BATCH: %s-%s-%s-%s-%s', $dateCode, $honeySupplierCode, $packagingSupplierCode, $workersString, $randomCode);
    
    // التأكد من عدم تكرار الرقم
    $counter = 0;
    $originalRandom = $randomCode;
    while ($db->queryOne("SELECT id FROM batch_numbers WHERE batch_number = ?", [$batchNumber])) {
        $counter++;
        // توليد 5 أرقام عشوائية جديدة
        $randomCode = str_pad(rand(0, 99999), 5, '0', STR_PAD_LEFT);
        $batchNumber = sprintf('BATCH: %s-%s-%s-%s-%s', $dateCode, $honeySupplierCode, $packagingSupplierCode, $workersString, $randomCode);
        
        if ($counter > 100) {
            // إذا فشل 100 مرة، أضف رقم عشوائي إضافي
            $batchNumber = sprintf('BATCH: %s-%s-%s-%s-%s-%d', $dateCode, $honeySupplierCode, $packagingSupplierCode, $workersString, $randomCode, rand(1000, 9999));
            break;
        }
    }
    
    return $batchNumber;
}

/**
 * إنشاء رقم تشغيلة جديد
 */
function createBatchNumber($productId, $productionId, $productionDate, $honeySupplierId = null, 
                          $packagingMaterials = [], $packagingSupplierId = null, 
                          $workers = [], $quantity = 1, $expiryDate = null, $notes = null, $createdBy = null, $allSuppliers = [], $honeyVariety = null) {
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
        
        // توليد رقم التشغيلة مع معرفات العمال
        $batchNumber = generateBatchNumber($productId, $productionDate, $honeySupplierId, $packagingSupplierId, $workers);
        
        if (!$batchNumber) {
            return ['success' => false, 'message' => 'فشل في توليد رقم التشغيلة'];
        }
        
        // تحويل المصفوفات إلى JSON
        $packagingMaterialsJson = !empty($packagingMaterials) ? json_encode($packagingMaterials) : null;
        $workersJson = !empty($workers) ? json_encode($workers) : null;
        $allSuppliersJson = !empty($allSuppliers) ? json_encode($allSuppliers) : null;
        
        // التحقق من وجود الحقول الجديدة
        $allSuppliersColumnCheck = $db->queryOne("SHOW COLUMNS FROM batch_numbers LIKE 'all_suppliers'");
        $hasAllSuppliersColumn = !empty($allSuppliersColumnCheck);
        
        $honeyVarietyColumnCheck = $db->queryOne("SHOW COLUMNS FROM batch_numbers LIKE 'honey_variety'");
        $hasHoneyVarietyColumn = !empty($honeyVarietyColumnCheck);
        
        // إنشاء رقم التشغيلة
        if ($hasAllSuppliersColumn && $hasHoneyVarietyColumn) {
            $sql = "INSERT INTO batch_numbers 
                    (batch_number, product_id, production_id, production_date, honey_supplier_id, honey_variety,
                     packaging_materials, packaging_supplier_id, all_suppliers, workers, quantity, expiry_date, notes, created_by, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'in_production')";
            
            $result = $db->execute($sql, [
                $batchNumber,
                $productId,
                $productionId,
                $productionDate,
                $honeySupplierId,
                $honeyVariety,
                $packagingMaterialsJson,
                $packagingSupplierId,
                $allSuppliersJson,
                $workersJson,
                $quantity,
                $expiryDate,
                $notes,
                $createdBy
            ]);
        } elseif ($hasAllSuppliersColumn) {
            // للتوافق مع إصدار بدون honey_variety
            $sql = "INSERT INTO batch_numbers 
                    (batch_number, product_id, production_id, production_date, honey_supplier_id, 
                     packaging_materials, packaging_supplier_id, all_suppliers, workers, quantity, expiry_date, notes, created_by, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'in_production')";
            
            $result = $db->execute($sql, [
                $batchNumber,
                $productId,
                $productionId,
                $productionDate,
                $honeySupplierId,
                $packagingMaterialsJson,
                $packagingSupplierId,
                $allSuppliersJson,
                $workersJson,
                $quantity,
                $expiryDate,
                $notes,
                $createdBy
            ]);
        } else {
            // للتوافق مع الإصدارات القديمة جداً
            $sql = "INSERT INTO batch_numbers 
                    (batch_number, product_id, production_id, production_date, honey_supplier_id, 
                     packaging_materials, packaging_supplier_id, workers, quantity, expiry_date, notes, created_by, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'in_production')";
            
            $result = $db->execute($sql, [
                $batchNumber,
                $productId,
                $productionId,
                $productionDate,
                $honeySupplierId,
                $packagingMaterialsJson,
                $packagingSupplierId,
                $workersJson,
                $quantity,
                $expiryDate,
                $notes,
                $createdBy
            ]);
        }
        
        // تسجيل سجل التدقيق
        logAudit($createdBy, 'create_batch_number', 'batch', $result['insert_id'], null, [
            'batch_number' => $batchNumber,
            'product_id' => $productId
        ]);
        
        return ['success' => true, 'batch_id' => $result['insert_id'], 'batch_number' => $batchNumber];
        
    } catch (Exception $e) {
        error_log("Batch Number Creation Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'حدث خطأ في إنشاء رقم التشغيلة'];
    }
}

/**
 * الحصول على رقم تشغيلة
 */
function getBatchNumber($batchId) {
    $db = db();
    
    $batch = $db->queryOne(
        "SELECT b.*, p.name as product_name, p.category as product_category,
                s1.name as honey_supplier_name, s2.name as packaging_supplier_name,
                pr.id as production_id, pr.date as production_date_value,
                u.username as created_by_name
         FROM batch_numbers b
         LEFT JOIN products p ON b.product_id = p.id
         LEFT JOIN suppliers s1 ON b.honey_supplier_id = s1.id
         LEFT JOIN suppliers s2 ON b.packaging_supplier_id = s2.id
         LEFT JOIN production pr ON b.production_id = pr.id
         LEFT JOIN users u ON b.created_by = u.id
         WHERE b.id = ?",
        [$batchId]
    );
    
    if ($batch) {
        // فك تشفير JSON
        $batch['packaging_materials'] = !empty($batch['packaging_materials']) 
            ? json_decode($batch['packaging_materials'], true) : [];
        $batch['workers'] = !empty($batch['workers']) 
            ? json_decode($batch['workers'], true) : [];
        
        // الحصول على معلومات مواد التعبئة
        if (!empty($batch['packaging_materials']) && is_array($batch['packaging_materials']) && count($batch['packaging_materials']) > 0) {
            $materialIds = array_map('intval', $batch['packaging_materials']);
            $materialIds = array_filter($materialIds, function($id) { return $id > 0; }); // إزالة القيم غير الصحيحة
            
            if (!empty($materialIds)) {
                $placeholders = implode(',', array_fill(0, count($materialIds), '?'));
                
                // التحقق من وجود الأعمدة قبل الاستعلام
                $typeColumnCheck = $db->queryOne("SHOW COLUMNS FROM products LIKE 'type'");
                $specificationsColumnCheck = $db->queryOne("SHOW COLUMNS FROM products LIKE 'specifications'");
                $hasTypeColumn = !empty($typeColumnCheck);
                $hasSpecificationsColumn = !empty($specificationsColumnCheck);
                
                $columns = ['id', 'name', 'category'];
                if ($hasTypeColumn) {
                    $columns[] = 'type';
                }
                if ($hasSpecificationsColumn) {
                    $columns[] = 'specifications';
                }
                
                $batch['packaging_materials_details'] = $db->query(
                    "SELECT " . implode(', ', $columns) . " FROM products WHERE id IN ($placeholders) AND status = 'active'",
                    $materialIds
                );
            } else {
                $batch['packaging_materials_details'] = [];
            }
        } else {
            $batch['packaging_materials_details'] = [];
        }
        
        // الحصول على معلومات العمال
        if (!empty($batch['workers']) && is_array($batch['workers']) && count($batch['workers']) > 0) {
            $workerIds = array_map('intval', $batch['workers']);
            $workerIds = array_filter($workerIds, function($id) { return $id > 0; }); // إزالة القيم غير الصحيحة
            
            if (!empty($workerIds)) {
                $placeholders = implode(',', array_fill(0, count($workerIds), '?'));
                $batch['workers_details'] = $db->query(
                    "SELECT id, username, full_name FROM users WHERE id IN ($placeholders) AND status = 'active'",
                    $workerIds
                );
            } else {
                $batch['workers_details'] = [];
            }
        } else {
            $batch['workers_details'] = [];
        }
    }
    
    return $batch;
}

/**
 * الحصول على رقم تشغيلة برقم التشغيلة
 */
function getBatchByNumber($batchNumber) {
    if (empty($batchNumber)) {
        return null;
    }
    
    $db = db();
    
    // تنظيف رقم التشغيلة (إزالة المسافات الزائدة)
    $batchNumber = trim($batchNumber);
    
    // البحث عن رقم التشغيلة بالضبط
    $batch = $db->queryOne("SELECT id FROM batch_numbers WHERE batch_number = ?", [$batchNumber]);
    
    // إذا لم يتم العثور عليه، جرب البحث بدون "BATCH: " في البداية
    if (!$batch && strpos($batchNumber, 'BATCH:') === 0) {
        $cleanBatchNumber = trim(str_replace('BATCH:', '', $batchNumber));
        $batch = $db->queryOne("SELECT id FROM batch_numbers WHERE batch_number LIKE ? OR batch_number = ?", 
            ["%{$cleanBatchNumber}%", $cleanBatchNumber]);
    }
    
    // إذا لم يتم العثور عليه، جرب البحث الجزئي (لأخطاء القراءة)
    if (!$batch) {
        // إزالة "BATCH: " ومسافات زائدة
        $cleanBatchNumber = preg_replace('/^BATCH:\s*/', '', $batchNumber);
        $cleanBatchNumber = trim($cleanBatchNumber);
        
        // البحث عن رقم التشغيلة الذي يحتوي على الأجزاء الرئيسية
        $parts = explode('-', $cleanBatchNumber);
        if (count($parts) >= 3) {
            // البحث عن رقم التشغيلة الذي يحتوي على تاريخ الإنتاج والموردين
            $datePart = $parts[0] ?? '';
            $supplierPart1 = $parts[1] ?? '';
            $supplierPart2 = $parts[2] ?? '';
            
            if (!empty($datePart) && !empty($supplierPart1) && !empty($supplierPart2)) {
                $batch = $db->queryOne(
                    "SELECT id FROM batch_numbers 
                     WHERE batch_number LIKE ? 
                     ORDER BY id DESC LIMIT 1",
                    ["%{$datePart}-{$supplierPart1}-{$supplierPart2}%"]
                );
            }
        }
    }
    
    if (!$batch) {
        return null;
    }
    
    return getBatchNumber($batch['id']);
}

/**
 * تسجيل فحص باركود
 */
function recordBarcodeScan($batchNumber, $scanType = 'verification', $scanLocation = null, $scannedBy = null) {
    try {
        $db = db();
        
        if ($scannedBy === null) {
            require_once __DIR__ . '/auth.php';
            $currentUser = getCurrentUser();
            $scannedBy = $currentUser['id'] ?? null;
        }
        
        $batch = $db->queryOne("SELECT id FROM batch_numbers WHERE batch_number = ?", [$batchNumber]);
        
        if (!$batch) {
            return ['success' => false, 'message' => 'رقم التشغيلة غير موجود'];
        }
        
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        
        $db->execute(
            "INSERT INTO barcode_scans (batch_number_id, scanned_by, scan_location, scan_type, ip_address) 
             VALUES (?, ?, ?, ?, ?)",
            [$batch['id'], $scannedBy, $scanLocation, $scanType, $ipAddress]
        );
        
        return ['success' => true];
        
    } catch (Exception $e) {
        error_log("Barcode Scan Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'حدث خطأ في تسجيل الفحص'];
    }
}

/**
 * الحصول على قائمة أرقام التشغيلة
 */
function getBatchNumbers($filters = [], $limit = 100, $offset = 0) {
    $db = db();
    
    $sql = "SELECT b.*, p.name as product_name, 
                   s1.name as honey_supplier_name, s2.name as packaging_supplier_name
            FROM batch_numbers b
            LEFT JOIN products p ON b.product_id = p.id
            LEFT JOIN suppliers s1 ON b.honey_supplier_id = s1.id
            LEFT JOIN suppliers s2 ON b.packaging_supplier_id = s2.id
            WHERE 1=1";
    
    $params = [];
    
    if (!empty($filters['product_id'])) {
        $sql .= " AND b.product_id = ?";
        $params[] = $filters['product_id'];
    }
    
    if (!empty($filters['batch_number'])) {
        $sql .= " AND b.batch_number LIKE ?";
        $params[] = "%{$filters['batch_number']}%";
    }
    
    if (!empty($filters['production_date'])) {
        $sql .= " AND DATE(b.production_date) = ?";
        $params[] = $filters['production_date'];
    }
    
    if (!empty($filters['status'])) {
        $sql .= " AND b.status = ?";
        $params[] = $filters['status'];
    }
    
    if (!empty($filters['date_from'])) {
        $sql .= " AND DATE(b.production_date) >= ?";
        $params[] = $filters['date_from'];
    }
    
    if (!empty($filters['date_to'])) {
        $sql .= " AND DATE(b.production_date) <= ?";
        $params[] = $filters['date_to'];
    }
    
    $sql .= " ORDER BY b.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    
    return $db->query($sql, $params);
}

/**
 * الحصول على عدد أرقام التشغيلة
 */
function getBatchNumbersCount($filters = []) {
    $db = db();
    
    $sql = "SELECT COUNT(*) as count FROM batch_numbers WHERE 1=1";
    $params = [];
    
    if (!empty($filters['product_id'])) {
        $sql .= " AND product_id = ?";
        $params[] = $filters['product_id'];
    }
    
    if (!empty($filters['batch_number'])) {
        $sql .= " AND batch_number LIKE ?";
        $params[] = "%{$filters['batch_number']}%";
    }
    
    if (!empty($filters['production_date'])) {
        $sql .= " AND DATE(production_date) = ?";
        $params[] = $filters['production_date'];
    }
    
    if (!empty($filters['status'])) {
        $sql .= " AND status = ?";
        $params[] = $filters['status'];
    }
    
    if (!empty($filters['date_from'])) {
        $sql .= " AND DATE(production_date) >= ?";
        $params[] = $filters['date_from'];
    }
    
    if (!empty($filters['date_to'])) {
        $sql .= " AND DATE(production_date) <= ?";
        $params[] = $filters['date_to'];
    }
    
    $result = $db->queryOne($sql, $params);
    return $result['count'] ?? 0;
}

/**
 * تحديث حالة رقم التشغيلة
 */
function updateBatchStatus($batchId, $status, $updatedBy = null) {
    try {
        $db = db();
        
        if ($updatedBy === null) {
            require_once __DIR__ . '/auth.php';
            $currentUser = getCurrentUser();
            $updatedBy = $currentUser['id'] ?? null;
        }
        
        $oldBatch = $db->queryOne("SELECT status FROM batch_numbers WHERE id = ?", [$batchId]);
        
        $db->execute(
            "UPDATE batch_numbers SET status = ?, updated_at = NOW() WHERE id = ?",
            [$status, $batchId]
        );
        
        logAudit($updatedBy, 'update_batch_status', 'batch', $batchId, 
                 ['old_status' => $oldBatch['status']], 
                 ['new_status' => $status]);
        
        return ['success' => true];
        
    } catch (Exception $e) {
        error_log("Update Batch Status Error: " . $e->getMessage());
        return ['success' => false, 'message' => 'حدث خطأ في تحديث الحالة'];
    }
}

