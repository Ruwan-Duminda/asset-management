<?php
require_once 'db.php';
require_once 'auth_helpers.php';
checkAccess(['admin', 'editor', 'viewer']);

$asset_id = $_GET['id'] ?? null;

if (!$asset_id) {
    die("Invalid Asset ID.");
}

// Fetch asset, assigned employee, category, and department details
$stmt = $pdo->prepare("
    SELECT a.*, 
           c.name AS category_name, 
           e.full_name AS employee_name, 
           e.employee_id AS emp_code, 
           e.email AS employee_email,
           d.name AS department_name
    FROM assets a
    LEFT JOIN categories c ON a.category_id = c.id
    LEFT JOIN employees e ON a.assigned_employee_id = e.id
    LEFT JOIN departments d ON a.assigned_department_id = d.id
    WHERE a.id = ?
");
$stmt->execute([$asset_id]);
$asset = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$asset) {
    die("Asset not found.");
}

$specs = json_decode($asset['specs'] ?? '{}', true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Asset Handover Receipt - <?= htmlspecialchars($asset['asset_tag']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            body { background: white; color: black; }
            .no-print { display: none !important; }
            .print-border { border: 1px solid #cbd5e1 !important; }
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-800 p-6 md:p-12">

    <!-- Top Action Bar (Hidden when printing) -->
    <div class="max-w-3xl mx-auto mb-6 flex justify-between items-center no-print">
        <a href="view_assets.php" class="text-xs bg-slate-200 hover:bg-slate-300 text-slate-700 font-semibold px-3 py-2 rounded-lg">
            ← Back to Assets
        </a>
        <button onclick="window.print()" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-4 py-2 rounded-lg text-sm shadow">
            🖨️ Print Receipt
        </button>
    </div>

    <!-- Printable Receipt Container -->
    <div class="max-w-3xl mx-auto bg-white p-8 border border-slate-300 rounded-xl shadow-sm print-border">
        
        <!-- Document Header -->
        <div class="flex justify-between items-start border-b pb-6 mb-6">
            <div>
                <h1 class="text-2xl font-bold uppercase tracking-wider text-slate-900">Asset Handover Receipt</h1>
                <p class="text-xs text-slate-500 mt-1">IT Equipment Assignment & Responsibility Form</p>
            </div>
            <div class="text-right">
                <div class="font-mono text-sm font-bold text-indigo-600">TAG: <?= htmlspecialchars($asset['asset_tag']) ?></div>
                <div class="text-xs text-slate-500">Date: <?= htmlspecialchars($asset['assignment_date'] ?? date('Y-m-d')) ?></div>
            </div>
        </div>

        <!-- Employee & Assignment Info -->
        <div class="mb-6 bg-slate-50 p-4 rounded-lg border border-slate-200">
            <h2 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-2">Assigned To</h2>
            <div class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <span class="text-slate-500">Employee Name:</span>
                    <p class="font-semibold text-slate-900"><?= htmlspecialchars($asset['employee_name'] ?? 'Unassigned') ?></p>
                </div>
                <div>
                    <span class="text-slate-500">Employee ID:</span>
                    <p class="font-semibold text-slate-900"><?= htmlspecialchars($asset['emp_code'] ?? 'N/A') ?></p>
                </div>
                <div>
                    <span class="text-slate-500">Department:</span>
                    <p class="font-semibold text-slate-900"><?= htmlspecialchars($asset['department_name'] ?? 'N/A') ?></p>
                </div>
                <div>
                    <span class="text-slate-500">Email:</span>
                    <p class="font-semibold text-slate-900"><?= htmlspecialchars($asset['employee_email'] ?? 'N/A') ?></p>
                </div>
            </div>
        </div>

        <!-- Asset Details Table -->
        <div class="mb-6">
            <h2 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-2">Hardware Details</h2>
            <table class="w-full text-left border-collapse border border-slate-200 text-sm">
                <tbody>
                    <tr class="border-b">
                        <td class="p-2.5 font-semibold bg-slate-50 w-1/3 border-r">Category / Type</td>
                        <td class="p-2.5"><?= htmlspecialchars($asset['category_name'] ?? 'N/A') ?></td>
                    </tr>
                    <tr class="border-b">
                        <td class="p-2.5 font-semibold bg-slate-50 border-r">Brand & Model</td>
                        <td class="p-2.5"><?= htmlspecialchars($asset['brand']) ?> <?= htmlspecialchars($asset['model']) ?></td>
                    </tr>
                    <tr class="border-b">
                        <td class="p-2.5 font-semibold bg-slate-50 border-r">Serial Number</td>
                        <td class="p-2.5 font-mono"><?= htmlspecialchars($asset['serial_number'] ?? 'N/A') ?></td>
                    </tr>
                    <tr class="border-b">
                        <td class="p-2.5 font-semibold bg-slate-50 border-r">Physical Condition</td>
                        <td class="p-2.5"><?= htmlspecialchars($asset['condition_status']) ?></td>
                    </tr>
                    <?php if (!empty($specs)): ?>
                    <tr>
                        <td class="p-2.5 font-semibold bg-slate-50 border-r">Specifications</td>
                        <td class="p-2.5 text-xs text-slate-600">
                            CPU: <?= htmlspecialchars($specs['cpu'] ?? 'N/A') ?> | 
                            RAM: <?= htmlspecialchars($specs['ram'] ?? 'N/A') ?> | 
                            Storage: <?= htmlspecialchars($specs['storage'] ?? 'N/A') ?> | 
                            OS: <?= htmlspecialchars($specs['os'] ?? 'N/A') ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Terms & Conditions Statement -->
        <div class="text-xs text-slate-500 space-y-2 mb-12 border-t pt-4">
            <p><strong>Declaration & Acknowledgment:</strong></p>
            <p>1. I acknowledge receipt of the asset listed above in good working condition unless stated otherwise.</p>
            <p>2. I agree to maintain and use this equipment in accordance with organizational policies.</p>
            <p>3. In the event of damage, loss, or theft, I will report the incident to the IT Department immediately.</p>
        </div>

        <!-- Signature Section -->
        <div class="grid grid-cols-2 gap-12 pt-8 border-t border-slate-300 text-sm">
            <div>
                <div class="h-12 border-b border-dashed border-slate-400 mb-2"></div>
                <p class="font-bold text-slate-800">Issued By (IT Staff Signature)</p>
                <p class="text-xs text-slate-500">Date: ________________________</p>
            </div>
            <div>
                <div class="h-12 border-b border-dashed border-slate-400 mb-2"></div>
                <p class="font-bold text-slate-800">Received By (Employee Signature)</p>
                <p class="text-xs text-slate-500">Date: ________________________</p>
            </div>
        </div>

    </div>

    <script>
        // Automatically open print dialog when loaded if 'auto=true' is passed
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('auto') === 'true') {
            window.onload = function() { window.print(); }
        }
    </script>
    <?php renderFooter(); ?>
</body>
</html>