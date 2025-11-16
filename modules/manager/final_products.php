<?php
/**
 * صفحة مخزن المنتجات - المدير
 * تعرض المنتجات النهائية مع تحسينات في فتح وعرض النماذج
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

// استخدام نفس ملف production/final_products.php مع تحسينات
define('FINAL_PRODUCTS_CONTEXT', 'manager');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/path_helper.php';
require_once __DIR__ . '/../../includes/table_styles.php';
require_once __DIR__ . '/../../includes/vehicle_inventory.php';
require_once __DIR__ . '/../../includes/audit_log.php';

requireRole('manager');

$currentUser = getCurrentUser();
$db = db();
$isManager = true; // نحن في صفحة المدير
$managerInventoryUrl = getRelativeUrl('manager.php?page=final_products');
$error = '';
$success = '';

// معالجة AJAX لتحميل المنتجات
if (isset($_GET['ajax']) && $_GET['ajax'] === 'load_products') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    
    $warehouseId = isset($_GET['warehouse_id']) ? intval($_GET['warehouse_id']) : null;
    
    try {
        if (!function_exists('getFinishedProductBatchOptions')) {
            require_once __DIR__ . '/../../includes/vehicle_inventory.php';
        }
        
        $products = getFinishedProductBatchOptions(true, $warehouseId);
        echo json_encode([
            'success' => true,
            'products' => $products
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}

// تضمين الملف الأساسي
$baseFile = __DIR__ . '/../production/final_products.php';
if (file_exists($baseFile)) {
    include $baseFile;
    
    // إضافة JavaScript محسّن للنماذج بعد تضمين الملف
    ?>
    <script>
    // تحسين فتح وعرض النماذج
    (function() {
        'use strict';
        
        function enhanceModals() {
            // تحسين جميع النماذج
            document.querySelectorAll('.modal').forEach(function(modal) {
                const modalDialog = modal.querySelector('.modal-dialog');
                if (modalDialog) {
                    // إضافة scrollable و centered إذا لم تكن موجودة
                    if (!modalDialog.classList.contains('modal-dialog-scrollable')) {
                        modalDialog.classList.add('modal-dialog-scrollable');
                    }
                    if (!modalDialog.classList.contains('modal-dialog-centered')) {
                        modalDialog.classList.add('modal-dialog-centered');
                    }
                }
                
                // تحسين فتح النموذج
                modal.addEventListener('show.bs.modal', function() {
                    // إزالة أي backdrops متعددة
                    const backdrops = document.querySelectorAll('.modal-backdrop');
                    backdrops.forEach((backdrop, index) => {
                        if (index > 0) backdrop.remove();
                    });
                });
                
                modal.addEventListener('shown.bs.modal', function() {
                    // التأكد من التموضع الصحيح
                    modal.style.position = 'fixed';
                    modal.style.top = '0';
                    modal.style.left = '0';
                    modal.style.zIndex = '1055';
                    modal.style.width = '100%';
                    modal.style.height = '100%';
                    modal.style.display = 'block';
                    
                    // إزالة أي backdrops متعددة
                    const backdrops = document.querySelectorAll('.modal-backdrop');
                    if (backdrops.length > 1) {
                        for (let i = 1; i < backdrops.length; i++) {
                            backdrops[i].remove();
                        }
                    }
                    
                    // التأكد من أن modal-dialog في الموضع الصحيح
                    const modalDialog = modal.querySelector('.modal-dialog');
                    if (modalDialog) {
                        modalDialog.style.position = 'relative';
                        modalDialog.style.zIndex = 'auto';
                        modalDialog.style.margin = '1.75rem auto';
                    }
                });
                
                modal.addEventListener('hidden.bs.modal', function() {
                    // تنظيف عند الإغلاق
                    const backdrops = document.querySelectorAll('.modal-backdrop');
                    backdrops.forEach(backdrop => backdrop.remove());
                    
                    // إزالة class modal-open من body إذا لم تكن هناك modals أخرى
                    const otherModals = document.querySelectorAll('.modal.show');
                    if (otherModals.length === 0) {
                        document.body.classList.remove('modal-open');
                        document.body.style.overflow = '';
                        document.body.style.paddingRight = '';
                    }
                });
            });
        }
        
        // تنفيذ عند تحميل الصفحة
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', enhanceModals);
        } else {
            enhanceModals();
        }
        
        // إعادة التنفيذ عند إضافة نماذج ديناميكية
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.addedNodes.length) {
                    mutation.addedNodes.forEach(function(node) {
                        if (node.nodeType === 1 && node.classList && node.classList.contains('modal')) {
                            enhanceModals();
                        }
                    });
                }
            });
        });
        
        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    })();
    </script>
    
    <style>
    /* تحسينات إضافية للنماذج */
    .modal-dialog-scrollable {
        max-height: calc(100vh - 3.5rem);
    }
    
    .modal-dialog-centered {
        display: flex;
        align-items: center;
        min-height: calc(100% - 3.5rem);
    }
    
    .modal.show {
        display: block !important;
        overflow-x: hidden;
        overflow-y: auto;
    }
    
    .modal-content {
        border-radius: 0.5rem;
        box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
    }
    
    /* منع ظهور modal في أماكن متعددة */
    .modal:not(.show) {
        display: none !important;
    }
    
    /* تحسين التمرير في النماذج */
    .modal-dialog-scrollable .modal-content {
        max-height: calc(100vh - 3.5rem);
        overflow: hidden;
        display: flex;
        flex-direction: column;
    }
    
    .modal-dialog-scrollable .modal-body {
        overflow-y: auto;
        overflow-x: hidden;
    }
    </style>
    <?php
} else {
    echo '<div class="alert alert-danger">خطأ: لم يتم العثور على ملف المنتجات النهائية الأساسي.</div>';
}
?>

