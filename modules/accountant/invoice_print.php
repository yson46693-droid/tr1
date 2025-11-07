<?php
/**
 * صفحة طباعة الفاتورة
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not allowed');
}

if (!$selectedInvoice) {
    die('الفاتورة غير موجودة');
}

$companyName = COMPANY_NAME;
$companyAddress = 'العنوان: ...'; // يمكن إضافتها في إعدادات النظام
$companyPhone = 'الهاتف: ...'; // يمكن إضافتها في إعدادات النظام
?>
<div class="invoice-print" id="invoicePrint">
    <div class="row mb-4">
        <div class="col-md-6">
            <h4 class="mb-2"><?php echo htmlspecialchars($companyName); ?></h4>
            <p class="mb-1"><?php echo htmlspecialchars($companyAddress); ?></p>
            <p class="mb-0"><?php echo htmlspecialchars($companyPhone); ?></p>
        </div>
        <div class="col-md-6 text-end">
            <h3 class="mb-2">فاتورة مبيعات</h3>
            <p class="mb-1"><strong>رقم الفاتورة:</strong> <?php echo htmlspecialchars($selectedInvoice['invoice_number']); ?></p>
            <p class="mb-1"><strong>التاريخ:</strong> <?php echo formatDate($selectedInvoice['date']); ?></p>
            <p class="mb-0"><strong>تاريخ الاستحقاق:</strong> <?php echo formatDate($selectedInvoice['due_date']); ?></p>
        </div>
    </div>
    
    <hr>
    
    <div class="row mb-4">
        <div class="col-md-6">
            <h6 class="mb-2">فاتورة إلى:</h6>
            <p class="mb-1"><strong><?php echo htmlspecialchars($selectedInvoice['customer_name'] ?? '-'); ?></strong></p>
            <?php if ($selectedInvoice['customer_phone']): ?>
                <p class="mb-1"><?php echo htmlspecialchars($selectedInvoice['customer_phone']); ?></p>
            <?php endif; ?>
            <?php if ($selectedInvoice['customer_address']): ?>
                <p class="mb-0"><?php echo htmlspecialchars($selectedInvoice['customer_address']); ?></p>
            <?php endif; ?>
        </div>
        <?php if ($selectedInvoice['sales_rep_name']): ?>
        <div class="col-md-6 text-end">
            <h6 class="mb-2">مندوب المبيعات:</h6>
            <p class="mb-0"><?php echo htmlspecialchars($selectedInvoice['sales_rep_name']); ?></p>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="table-responsive mb-4">
        <table class="table table-bordered">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>المنتج/الخدمة</th>
                    <th>الوصف</th>
                    <th class="text-center">الكمية</th>
                    <th class="text-end">سعر الوحدة</th>
                    <th class="text-end">الإجمالي</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $itemNumber = 1;
                foreach ($selectedInvoice['items'] as $item): 
                ?>
                    <tr>
                        <td><?php echo $itemNumber++; ?></td>
                        <td><?php echo htmlspecialchars($item['product_name'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($item['description'] ?? '-'); ?></td>
                        <td class="text-center"><?php echo number_format($item['quantity'], 2); ?></td>
                        <td class="text-end"><?php echo formatCurrency($item['unit_price']); ?></td>
                        <td class="text-end"><?php echo formatCurrency($item['total_price']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <div class="row">
        <div class="col-md-6">
            <?php if ($selectedInvoice['notes']): ?>
                <div class="mb-3">
                    <h6>ملاحظات:</h6>
                    <p><?php echo nl2br(htmlspecialchars($selectedInvoice['notes'])); ?></p>
                </div>
            <?php endif; ?>
        </div>
        <div class="col-md-6">
            <div class="ms-auto" style="max-width: 300px;">
                <table class="table table-sm">
                    <tr>
                        <td><strong>المجموع الفرعي:</strong></td>
                        <td class="text-end"><?php echo formatCurrency($selectedInvoice['subtotal']); ?></td>
                    </tr>
                    <?php if ($selectedInvoice['discount_amount'] > 0): ?>
                    <tr>
                        <td><strong>الخصم:</strong></td>
                        <td class="text-end"><?php echo formatCurrency($selectedInvoice['discount_amount']); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr class="table-primary">
                        <td><strong>الإجمالي:</strong></td>
                        <td class="text-end"><strong><?php echo formatCurrency($selectedInvoice['total_amount']); ?></strong></td>
                    </tr>
                    <?php if ($selectedInvoice['paid_amount'] > 0): ?>
                    <tr>
                        <td><strong>المدفوع:</strong></td>
                        <td class="text-end text-success"><?php echo formatCurrency($selectedInvoice['paid_amount']); ?></td>
                    </tr>
                    <tr>
                        <td><strong>المتبقي:</strong></td>
                        <td class="text-end text-danger">
                            <?php echo formatCurrency($selectedInvoice['total_amount'] - $selectedInvoice['paid_amount']); ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
    
    <hr>
    
    <div class="text-center mt-4">
        <p class="text-muted mb-0">
            شكراً لتعاملك معنا
        </p>
    </div>
</div>

<style>
@media print {
    .invoice-print {
        padding: 20px;
    }
    
    .btn, .card-header, .sidebar, .navbar {
        display: none !important;
    }
    
    body {
        background: white;
    }
    
    .invoice-print {
        border: none;
        box-shadow: none;
    }
}
</style>

<script>
// طباعة عند فتح صفحة الطباعة
if (window.location.search.includes('print=')) {
    window.onload = function() {
        window.print();
    };
}
</script>

