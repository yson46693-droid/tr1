<?php
/**
 * نظام الإصلاح التلقائي لمشكلة 262145
 * Auto-fix system for 262145 issue
 * 
 * يتم تشغيل هذا الملف تلقائياً مرة واحدة فقط عند أول تحميل للنظام
 * This file runs automatically only once on first system load
 */

// منع الوصول المباشر
if (!defined('AUTO_FIX_ALLOWED')) {
    die('Direct access not permitted');
}

/**
 * التحقق مما إذا كان الإصلاح قد تم تشغيله من قبل
 */
function isFixAlreadyRun() {
    $flagFile = __DIR__ . '/../database/.fix_262145_completed';
    return file_exists($flagFile);
}

/**
 * تحديد ملف العلامة بأن الإصلاح تم
 */
function markFixAsCompleted() {
    $flagFile = __DIR__ . '/../database/.fix_262145_completed';
    $timestamp = date('Y-m-d H:i:s');
    $content = "Fix 262145 completed at: {$timestamp}\n";
    $content .= "This file indicates that the automatic fix has been executed.\n";
    $content .= "Do not delete this file unless you want to re-run the fix.\n";
    return file_put_contents($flagFile, $content) !== false;
}

/**
 * التحقق مما إذا كانت المشكلة موجودة فعلاً
 */
function needsFix() {
    // التحقق من وجود دالة db()
    if (!function_exists('db')) {
        return false;
    }
    
    try {
        $db = db();
        
        // التحقق من جدول users
        $usersCheck = $db->queryOne("
            SELECT COUNT(*) as count 
            FROM users 
            WHERE hourly_rate = 262145 
               OR hourly_rate > 100000 
               OR hourly_rate < 0
               OR CAST(hourly_rate AS CHAR) LIKE '%262145%'
        ");
        
        // التحقق من جدول salaries
        $salariesCheck = $db->queryOne("
            SELECT COUNT(*) as count 
            FROM salaries 
            WHERE hourly_rate = 262145 
               OR hourly_rate > 100000 
               OR hourly_rate < 0
               OR CAST(hourly_rate AS CHAR) LIKE '%262145%'
        ");
        
        return ($usersCheck['count'] > 0) || ($salariesCheck['count'] > 0);
    } catch (Exception $e) {
        // في حالة حدوث خطأ، نفترض عدم الحاجة للإصلاح
        error_log("Auto-fix 262145 check error: " . $e->getMessage());
        return false;
    }
}

/**
 * تنفيذ الإصلاح التلقائي
 */
function runAutoFix() {
    // التحقق من وجود دالة db()
    if (!function_exists('db')) {
        throw new Exception('Database function not available');
    }
    
    try {
        $db = db();
        $results = [];
        
        // بدء المعاملة
        $db->execute("START TRANSACTION");
        
        // الخطوة 1: تنظيف جدول users
        $step1 = $db->execute("
            UPDATE users
            SET hourly_rate = 0.00
            WHERE hourly_rate = 262145
               OR hourly_rate > 100000
               OR hourly_rate < 0
               OR CAST(hourly_rate AS CHAR) LIKE '%262145%'
        ");
        $results['users_fixed'] = $step1['affected_rows'] ?? 0;
        
        // الخطوة 2: تنظيف جدول salaries
        $step2 = $db->execute("
            UPDATE salaries
            SET hourly_rate = 0.00
            WHERE hourly_rate = 262145
               OR hourly_rate > 100000
               OR hourly_rate < 0
               OR CAST(hourly_rate AS CHAR) LIKE '%262145%'
        ");
        $results['salaries_fixed'] = $step2['affected_rows'] ?? 0;
        
        // الخطوة 3: تعديل بنية جدول users (إذا لم يكن معدلاً)
        try {
            $db->execute("
                ALTER TABLE users 
                MODIFY COLUMN hourly_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00
            ");
            $results['structure_updated'] = true;
        } catch (Exception $e) {
            // البنية معدلة مسبقاً
            $results['structure_updated'] = false;
            $results['structure_message'] = 'Already updated';
        }
        
        // إنهاء المعاملة
        $db->execute("COMMIT");
        
        // تحديد ملف العلامة
        markFixAsCompleted();
        
        // تسجيل في سجل النظام
        $logMessage = sprintf(
            "Auto-fix 262145: Fixed %d users, %d salaries. Structure: %s",
            $results['users_fixed'],
            $results['salaries_fixed'],
            $results['structure_updated'] ? 'Updated' : 'Already OK'
        );
        error_log($logMessage);
        
        return [
            'success' => true,
            'results' => $results,
            'message' => $logMessage
        ];
        
    } catch (Exception $e) {
        // التراجع في حالة الخطأ
        try {
            $db->execute("ROLLBACK");
        } catch (Exception $rollbackError) {
            // تجاهل
        }
        
        $errorMessage = "Auto-fix 262145 failed: " . $e->getMessage();
        error_log($errorMessage);
        
        return [
            'success' => false,
            'error' => $errorMessage
        ];
    }
}

/**
 * الدالة الرئيسية للتشغيل التلقائي
 */
function autoFix262145() {
    // التحقق مما إذا كان الإصلاح قد تم من قبل
    if (isFixAlreadyRun()) {
        return [
            'status' => 'skipped',
            'message' => 'Fix already completed previously'
        ];
    }
    
    // التحقق مما إذا كانت المشكلة موجودة
    if (!needsFix()) {
        // لا توجد مشكلة، لكن نحدد الملف لعدم التحقق مجدداً
        markFixAsCompleted();
        return [
            'status' => 'not_needed',
            'message' => 'No issues found, system is clean'
        ];
    }
    
    // تنفيذ الإصلاح
    $result = runAutoFix();
    
    if ($result['success']) {
        return [
            'status' => 'completed',
            'message' => 'Auto-fix executed successfully',
            'details' => $result['results']
        ];
    } else {
        return [
            'status' => 'failed',
            'message' => 'Auto-fix failed',
            'error' => $result['error']
        ];
    }
}

// تشغيل الإصلاح التلقائي فقط إذا تم استدعاؤه من config.php
if (defined('RUN_AUTO_FIX') && RUN_AUTO_FIX === true) {
    return autoFix262145();
}

