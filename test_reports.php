<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>اختبار نظام التقارير</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding: 30px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .test-container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        .test-result {
            margin-top: 20px;
            padding: 15px;
            border-radius: 10px;
            font-family: monospace;
        }
        .test-result.success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        .test-result.error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        .test-result.info {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            color: #0c5460;
        }
        .btn-test {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
            padding: 12px 30px;
            border-radius: 25px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-test:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        .log-entry {
            font-size: 12px;
            margin: 5px 0;
            padding: 5px;
            border-bottom: 1px solid #eee;
        }
        .log-entry.error { color: #dc3545; }
        .log-entry.success { color: #28a745; }
        .log-entry.info { color: #17a2b8; }
    </style>
</head>
<body>
    <div class="test-container">
        <h1 class="text-center mb-4">
            <i class="bi bi-bug-fill text-primary"></i>
            اختبار نظام التقارير
        </h1>

        <div class="alert alert-info">
            <i class="bi bi-info-circle-fill me-2"></i>
            هذه الصفحة لاختبار وظائف توليد التقارير (PDF, Excel, CSV)
        </div>

        <!-- Test 1: Check Reports Directory -->
        <div class="card mb-3">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-folder-check me-2"></i>اختبار 1: فحص مجلد التقارير</h5>
            </div>
            <div class="card-body">
                <button class="btn btn-test" onclick="testReportsDirectory()">
                    <i class="bi bi-play-fill me-2"></i>تشغيل الاختبار
                </button>
                <div id="test1-result"></div>
            </div>
        </div>

        <!-- Test 2: Create Test Data -->
        <div class="card mb-3">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0"><i class="bi bi-database-check me-2"></i>اختبار 2: إنشاء بيانات تجريبية</h5>
            </div>
            <div class="card-body">
                <button class="btn btn-test" onclick="testCreateData()">
                    <i class="bi bi-play-fill me-2"></i>تشغيل الاختبار
                </button>
                <div id="test2-result"></div>
            </div>
        </div>

        <!-- Test 3: Generate PDF -->
        <div class="card mb-3">
            <div class="card-header bg-danger text-white">
                <h5 class="mb-0"><i class="bi bi-file-pdf-fill me-2"></i>اختبار 3: توليد تقرير PDF</h5>
            </div>
            <div class="card-body">
                <button class="btn btn-test" onclick="testGeneratePDF()">
                    <i class="bi bi-play-fill me-2"></i>تشغيل الاختبار
                </button>
                <div id="test3-result"></div>
            </div>
        </div>

        <!-- Test 4: Generate CSV -->
        <div class="card mb-3">
            <div class="card-header bg-warning text-dark">
                <h5 class="mb-0"><i class="bi bi-file-earmark-spreadsheet-fill me-2"></i>اختبار 4: توليد تقرير CSV</h5>
            </div>
            <div class="card-body">
                <button class="btn btn-test" onclick="testGenerateCSV()">
                    <i class="bi bi-play-fill me-2"></i>تشغيل الاختبار
                </button>
                <div id="test4-result"></div>
            </div>
        </div>

        <!-- Test 5: Check PHP Error Log -->
        <div class="card mb-3">
            <div class="card-header bg-secondary text-white">
                <h5 class="mb-0"><i class="bi bi-journal-code me-2"></i>اختبار 5: فحص سجل أخطاء PHP</h5>
            </div>
            <div class="card-body">
                <button class="btn btn-test" onclick="testCheckErrorLog()">
                    <i class="bi bi-play-fill me-2"></i>تشغيل الاختبار
                </button>
                <div id="test5-result"></div>
            </div>
        </div>

        <div class="text-center mt-4">
            <button class="btn btn-lg btn-test" onclick="runAllTests()">
                <i class="bi bi-lightning-fill me-2"></i>تشغيل جميع الاختبارات
            </button>
        </div>
    </div>

    <script>
        function showResult(elementId, status, message, data = null) {
            const element = document.getElementById(elementId);
            let html = `<div class="test-result ${status}">
                <strong>${status === 'success' ? '✓ نجح' : status === 'error' ? '✗ فشل' : 'ℹ️ معلومات'}:</strong>
                <p class="mb-0 mt-2">${message}</p>`;
            
            if (data) {
                html += `<pre class="mt-2 mb-0">${JSON.stringify(data, null, 2)}</pre>`;
            }
            
            html += '</div>';
            element.innerHTML = html;
        }

        async function testReportsDirectory() {
            try {
                const response = await fetch('test_reports_api.php?action=check_directory');
                const result = await response.json();
                
                if (result.success) {
                    showResult('test1-result', 'success', result.message, result.data);
                } else {
                    showResult('test1-result', 'error', result.error, result.data);
                }
            } catch (error) {
                showResult('test1-result', 'error', 'فشل الاتصال بالخادم: ' + error.message);
            }
        }

        async function testCreateData() {
            try {
                const response = await fetch('test_reports_api.php?action=create_test_data');
                const result = await response.json();
                
                if (result.success) {
                    showResult('test2-result', 'success', result.message, result.data);
                } else {
                    showResult('test2-result', 'error', result.error, result.data);
                }
            } catch (error) {
                showResult('test2-result', 'error', 'فشل الاتصال بالخادم: ' + error.message);
            }
        }

        async function testGeneratePDF() {
            try {
                const response = await fetch('test_reports_api.php?action=test_pdf');
                const result = await response.json();
                
                if (result.success) {
                    let message = result.message;
                    if (result.file_url) {
                        message += `<br><a href="${result.file_url}" target="_blank" class="btn btn-sm btn-primary mt-2">
                            <i class="bi bi-download me-1"></i>فتح الملف
                        </a>`;
                    }
                    showResult('test3-result', 'success', message, result.data);
                } else {
                    showResult('test3-result', 'error', result.error, result.data);
                }
            } catch (error) {
                showResult('test3-result', 'error', 'فشل الاتصال بالخادم: ' + error.message);
            }
        }

        async function testGenerateCSV() {
            try {
                const response = await fetch('test_reports_api.php?action=test_csv');
                const result = await response.json();
                
                if (result.success) {
                    let message = result.message;
                    if (result.file_url) {
                        message += `<br><a href="${result.file_url}" target="_blank" class="btn btn-sm btn-warning mt-2">
                            <i class="bi bi-download me-1"></i>تحميل الملف
                        </a>`;
                    }
                    showResult('test4-result', 'success', message, result.data);
                } else {
                    showResult('test4-result', 'error', result.error, result.data);
                }
            } catch (error) {
                showResult('test4-result', 'error', 'فشل الاتصال بالخادم: ' + error.message);
            }
        }

        async function testCheckErrorLog() {
            try {
                const response = await fetch('test_reports_api.php?action=check_error_log');
                const result = await response.json();
                
                if (result.success) {
                    showResult('test5-result', 'info', result.message, result.data);
                } else {
                    showResult('test5-result', 'error', result.error, result.data);
                }
            } catch (error) {
                showResult('test5-result', 'error', 'فشل الاتصال بالخادم: ' + error.message);
            }
        }

        async function runAllTests() {
            await testReportsDirectory();
            await new Promise(resolve => setTimeout(resolve, 500));
            await testCreateData();
            await new Promise(resolve => setTimeout(resolve, 500));
            await testGeneratePDF();
            await new Promise(resolve => setTimeout(resolve, 500));
            await testGenerateCSV();
            await new Promise(resolve => setTimeout(resolve, 500));
            await testCheckErrorLog();
        }
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

