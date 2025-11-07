<?php
/**
 * API تحديث البروفايل
 */

define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit_log.php';

header('Content-Type: application/json');
requireLogin();

$currentUser = getCurrentUser();
$db = db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$action = $_POST['action'] ?? '';

if ($action === 'update_profile') {
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    
    // التحقق من البيانات
    if (empty($fullName)) {
        http_response_code(400);
        echo json_encode(['error' => 'يجب إدخال الاسم الكامل']);
        exit;
    }
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'البريد الإلكتروني غير صحيح']);
        exit;
    }
    
    // التحقق من البريد الإلكتروني إذا تغير
    $user = getUserById($currentUser['id']);
    if ($email !== $user['email']) {
        $existingUser = getUserByUsername($email);
        if ($existingUser && $existingUser['id'] != $currentUser['id']) {
            http_response_code(400);
            echo json_encode(['error' => 'البريد الإلكتروني مستخدم بالفعل']);
            exit;
        }
    }
    
    // تحديث البيانات
    $db->execute(
        "UPDATE users SET full_name = ?, email = ?, phone = ?, updated_at = NOW() WHERE id = ?",
        [$fullName, $email, $phone, $currentUser['id']]
    );
    
    logAudit($currentUser['id'], 'update_profile', 'user', $currentUser['id'], null, ['full_name' => $fullName, 'email' => $email]);
    
    echo json_encode([
        'success' => true,
        'message' => 'تم تحديث البروفايل بنجاح'
    ]);
    
} elseif ($action === 'change_password') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    $user = getUserById($currentUser['id']);
    
    if (empty($currentPassword)) {
        http_response_code(400);
        echo json_encode(['error' => 'يجب إدخال كلمة المرور الحالية']);
        exit;
    }
    
    if (!verifyPassword($currentPassword, $user['password_hash'])) {
        http_response_code(400);
        echo json_encode(['error' => 'كلمة المرور الحالية غير صحيحة']);
        exit;
    }
    
    if (empty($newPassword)) {
        http_response_code(400);
        echo json_encode(['error' => 'يجب إدخال كلمة المرور الجديدة']);
        exit;
    }
    
    if ($newPassword !== $confirmPassword) {
        http_response_code(400);
        echo json_encode(['error' => 'كلمة المرور الجديدة غير متطابقة']);
        exit;
    }
    
    if (strlen($newPassword) < PASSWORD_MIN_LENGTH) {
        http_response_code(400);
        echo json_encode(['error' => 'كلمة المرور يجب أن تكون على الأقل ' . PASSWORD_MIN_LENGTH . ' أحرف']);
        exit;
    }
    
    $newPasswordHash = hashPassword($newPassword);
    $db->execute(
        "UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?",
        [$newPasswordHash, $currentUser['id']]
    );
    
    logAudit($currentUser['id'], 'change_password', 'user', $currentUser['id'], null, null);
    
    echo json_encode([
        'success' => true,
        'message' => 'تم تغيير كلمة المرور بنجاح'
    ]);
    
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid action']);
}

