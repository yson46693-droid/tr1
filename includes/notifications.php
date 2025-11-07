<?php
/**
 * نظام الإشعارات
 */

// منع الوصول المباشر
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/telegram_notifications.php';

/**
 * إنشاء إشعار جديد
 */
function createNotification($userId, $title, $message, $type = 'info', $link = null, $sendTelegram = false) {
    try {
        $db = db();
        
        $sql = "INSERT INTO notifications (user_id, title, message, type, link) 
                VALUES (?, ?, ?, ?, ?)";
        
        $db->execute($sql, [
            $userId,
            $title,
            $message,
            $type,
            $link
        ]);
        
        // إرسال إشعار Telegram إذا كان مفعّل
        if ($sendTelegram && isTelegramConfigured()) {
            $user = $db->queryOne("SELECT role FROM users WHERE id = ?", [$userId]);
            if ($user) {
                $telegramMessage = "📢 <b>{$title}</b>\n\n{$message}";
                if ($link) {
                    $telegramMessage .= "\n\n🔗 رابط: {$link}";
                }
                sendTelegramNotificationByRole($user['role'], $telegramMessage, $type);
            }
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Notification Error: " . $e->getMessage());
        return false;
    }
}

/**
 * إنشاء إشعار لجميع المستخدمين بدور معين
 */
function createNotificationForRole($role, $title, $message, $type = 'info', $link = null, $sendTelegram = false) {
    try {
        $db = db();
        
        $users = $db->query("SELECT id FROM users WHERE role = ? AND status = 'active'", [$role]);
        
        foreach ($users as $user) {
            createNotification($user['id'], $title, $message, $type, $link, false);
        }
        
        // إرسال إشعار Telegram للدور إذا كان مفعّل
        if ($sendTelegram && isTelegramConfigured()) {
            $telegramMessage = "📢 <b>{$title}</b>\n\n{$message}";
            if ($link) {
                $telegramMessage .= "\n\n🔗 رابط: {$link}";
            }
            sendTelegramNotificationByRole($role, $telegramMessage, $type);
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Notification Error: " . $e->getMessage());
        return false;
    }
}

/**
 * إنشاء إشعار لجميع المديرين
 */
function notifyManagers($title, $message, $type = 'info', $link = null, $sendTelegram = true) {
    return createNotificationForRole('manager', $title, $message, $type, $link, $sendTelegram);
}

/**
 * الحصول على إشعارات المستخدم
 */
function getUserNotifications($userId, $unreadOnly = false, $limit = 50) {
    $db = db();
    
    $sql = "SELECT * FROM notifications 
            WHERE user_id = ?";
    
    if ($unreadOnly) {
        $sql .= " AND `read` = 0";
    }
    
    $sql .= " ORDER BY created_at DESC LIMIT ?";
    
    return $db->query($sql, [$userId, $limit]);
}

/**
 * الحصول على عدد الإشعارات غير المقروءة
 */
function getUnreadNotificationCount($userId) {
    $db = db();
    
    $result = $db->queryOne(
        "SELECT COUNT(*) as count FROM notifications 
         WHERE user_id = ? AND `read` = 0",
        [$userId]
    );
    
    return $result['count'] ?? 0;
}

/**
 * تحديد إشعار كمقروء
 */
function markNotificationAsRead($notificationId, $userId) {
    $db = db();
    
    $db->execute(
        "UPDATE notifications SET `read` = 1 
         WHERE id = ? AND user_id = ?",
        [$notificationId, $userId]
    );
    
    return true;
}

/**
 * تحديد جميع الإشعارات كمقروءة
 */
function markAllNotificationsAsRead($userId) {
    $db = db();
    
    $db->execute(
        "UPDATE notifications SET `read` = 1 
         WHERE user_id = ? AND `read` = 0",
        [$userId]
    );
    
    return true;
}

/**
 * حذف إشعار
 */
function deleteNotification($notificationId, $userId) {
    $db = db();
    
    $db->execute(
        "DELETE FROM notifications WHERE id = ? AND user_id = ?",
        [$notificationId, $userId]
    );
    
    return true;
}

/**
 * إرسال إشعار متصفح (Browser Notification)
 */
function sendBrowserNotification($title, $body, $icon = null, $tag = null) {
    // يتم إرسال إشعارات المتصفح عبر JavaScript
    // هذه الدالة للإشارة فقط
    return true;
}

