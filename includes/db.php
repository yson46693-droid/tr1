<?php
/**
 * اتصال قاعدة البيانات MySQL
 * نظام إدارة الشركات المتكامل
 */

// منع الوصول المباشر
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';

class Database {
    private static $instance = null;
    private $connection;
    
    private function __construct() {
        try {
            $this->connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
            
            if ($this->connection->connect_error) {
                throw new Exception("Connection failed: " . $this->connection->connect_error);
            }
            
            // تعيين ترميز UTF-8
            $this->connection->set_charset("utf8mb4");
            
            // تعيين المنطقة الزمنية لتوقيت القاهرة (UTC+2)
            // مصر تستخدم توقيت UTC+2 بدون توقيت صيفي
            $this->connection->query("SET time_zone = '+02:00'");
            
        } catch (Exception $e) {
            die("Database connection error: " . $e->getMessage());
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    // منع الاستنساخ
    private function __clone() {}
    
    // منع إلغاء التسلسل
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
    
    /**
     * تنفيذ استعلام SELECT
     */
    public function query($sql, $params = []) {
        $stmt = $this->connection->prepare($sql);
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $this->connection->error);
        }
        
        if (!empty($params)) {
            $types = '';
            $values = [];
            
            foreach ($params as $param) {
                if (is_int($param)) {
                    $types .= 'i';
                } elseif (is_float($param)) {
                    $types .= 'd';
                } else {
                    $types .= 's';
                }
                $values[] = $param;
            }
            
            $stmt->bind_param($types, ...$values);
        }
        
        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        
        $result = $stmt->get_result();
        $data = [];
        
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        
        $stmt->close();
        return $data;
    }
    
    /**
     * تنفيذ استعلام SELECT واحد
     */
    public function queryOne($sql, $params = []) {
        $result = $this->query($sql, $params);
        return !empty($result) ? $result[0] : null;
    }
    
    /**
     * تنفيذ استعلام INSERT/UPDATE/DELETE
     */
    public function execute($sql, $params = []) {
        $stmt = $this->connection->prepare($sql);
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $this->connection->error);
        }
        
        if (!empty($params)) {
            $types = '';
            $values = [];
            
            foreach ($params as $param) {
                if (is_int($param)) {
                    $types .= 'i';
                } elseif (is_float($param)) {
                    $types .= 'd';
                } else {
                    $types .= 's';
                }
                $values[] = $param;
            }
            
            $stmt->bind_param($types, ...$values);
        }
        
        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        
        $affectedRows = $stmt->affected_rows;
        $insertId = $stmt->insert_id;
        $stmt->close();
        
        return [
            'affected_rows' => $affectedRows,
            'insert_id' => $insertId
        ];
    }
    
    /**
     * بدء معاملة
     */
    public function beginTransaction() {
        return $this->connection->begin_transaction();
    }
    
    /**
     * تأكيد المعاملة
     */
    public function commit() {
        return $this->connection->commit();
    }
    
    /**
     * إلغاء المعاملة
     */
    public function rollback() {
        return $this->connection->rollback();
    }
    
    /**
     * الهروب من الأحرف الخاصة
     */
    public function escape($string) {
        return $this->connection->real_escape_string($string);
    }
    
    /**
     * الحصول على آخر معرف تم إدراجه
     */
    public function getLastInsertId() {
        return $this->connection->insert_id;
    }
    
    /**
     * إغلاق الاتصال
     */
    public function close() {
        if ($this->connection) {
            $this->connection->close();
        }
    }
}

// دالة مساعدة للحصول على اتصال قاعدة البيانات
function getDB() {
    return Database::getInstance()->getConnection();
}

// دالة مساعدة للحصول على كائن قاعدة البيانات
function db() {
    return Database::getInstance();
}

// ============================================================
// تشغيل الإصلاح التلقائي لمشكلة 262145 (مرة واحدة فقط)
// Auto-fix for 262145 issue (runs only once)
// ============================================================
if (file_exists(__DIR__ . '/auto_fix_262145.php')) {
    define('AUTO_FIX_ALLOWED', true);
    define('RUN_AUTO_FIX', true);
    
    try {
        $autoFixResult = include __DIR__ . '/auto_fix_262145.php';
        
        // تسجيل النتيجة في session للإشعار (اختياري)
        if (is_array($autoFixResult) && isset($autoFixResult['status'])) {
            // يمكن تسجيل النتيجة في log أو session حسب الحاجة
            if ($autoFixResult['status'] === 'completed') {
                error_log("Auto-fix 262145: Successfully completed");
            }
        }
    } catch (Exception $e) {
        // في حالة حدوث خطأ، نسجله فقط ولا نوقف النظام
        error_log("Auto-fix 262145 error: " . $e->getMessage());
    }
}

