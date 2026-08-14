<?php
require_once 'db.php';
require_once 'auth_helpers.php';

// Check access for all authenticated roles
checkAccess(['admin', 'editor', 'viewer']);

// 1. Core Asset Overview Statistics
$stats = $pdo->query("
    SELECT 
        COUNT(*) AS total_assets,
        SUM(CASE WHEN status = 'In Use' THEN 1 ELSE 0 END) AS in_use,
        SUM(CASE WHEN status = 'In Stock' THEN 1 ELSE 0 END) AS in_stock,
        SUM(CASE WHEN status = 'Maintenance' THEN 1 ELSE 0 END) AS in_maintenance,
        IFNULL(SUM(purchase_price), 0) AS total_value
    FROM assets
")->fetch();

// 2. Department Breakdown
$departmentStats = $pdo->query("
    SELECT d.id, d.name AS dept_name, COUNT(a.id) AS total_dept_assets, IFNULL(SUM(a.purchase_price), 0) AS dept_value
    FROM departments d LEFT JOIN assets a ON a.assigned_department_id = d.id
    GROUP BY d.id, d.name ORDER BY total_dept_assets DESC, d.name ASC
")->fetchAll();

// 3. Warranty Alerts (< 30 days remaining)
$warrantyAlerts = $pdo->query("
    SELECT a.*, d.name AS dept_name, DATEDIFF(a.warranty_expiry, CURDATE()) AS days_left
    FROM assets a LEFT JOIN departments d ON a.assigned_department_id = d.id
    WHERE a.warranty_expiry IS NOT NULL AND a.warranty_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND a.status != 'Retired'
    ORDER BY a.warranty_expiry ASC
")->fetchAll();

// 4. Active Maintenance Records (In Progress or Scheduled)
$activeMaintenance = $pdo->query("
    SELECT m.*, a.asset_tag, a.brand, a.model, d.name AS dept_name
    FROM maintenance_logs m
    LEFT JOIN assets a ON m.asset_id = a.id
    LEFT JOIN departments d ON a.assigned_department_id = d.id
    WHERE m.status IN ('In Progress', 'Scheduled')
    ORDER BY m.start_date DESC
    LIMIT 5
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ITAM - Dashboard Overview</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 font-sans text-slate-800">
    <?php renderNav(); ?>
    <main class="max-w-7xl mx-auto px-4 pb-12 space-y-8">
        
        <!-- Header -->
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
            <div>
                <h1 class="text-2xl font-bold text-slate-900">Dashboard Overview</h1>
                <p class="text-sm text-slate-500">Track and manage hardware assets across all departments.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <?php if (in_array($_SESSION['user_role'], ['admin', 'editor'])): ?>
                    <a href="add_maintenance.php" class="bg-amber-600 hover:bg-amber-700 text-white px-4 py-2 rounded-lg font-medium shadow transition text-sm flex items-center">🛠️ Log Maintenance</a>
                    <a href="add_asset.php" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg font-medium shadow transition text-sm flex items-center">+ Add Asset</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Metric Stat Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
            <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm">
                <span class="text-xs font-bold text-slate-400 uppercase">Total Assets</span>
                <p class="text-3xl font-extrabold mt-2"><?= $stats['total_assets'] ?></p>
            </div>
            <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm">
                <span class="text-xs font-bold text-emerald-600 uppercase">In Use</span>
                <p class="text-3xl font-extrabold text-emerald-600 mt-2"><?= $stats['in_use'] ?></p>
            </div>
            <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm">
                <span class="text-xs font-bold text-blue-600 uppercase">In Stock</span>
                <p class="text-3xl font-extrabold text-blue-600 mt-2"><?= $stats['in_stock'] ?></p>
            </div>
            <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm flex flex-col justify-between">
                <div>
                    <span class="text-xs font-bold text-amber-600 uppercase">In Maintenance</span>
                    <p class="text-3xl font-extrabold text-amber-600 mt-2"><?= $stats['in_maintenance'] ?></p>
                </div>
                <a href="add_maintenance.php" class="text-xs text-amber-600 hover:underline font-semibold mt-2 inline-block">Manage Logs →</a>
            </div>
            <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm">
                <span class="text-xs font-bold text-slate-400 uppercase">Total Valuation</span>
                <p class="text-2xl font-extrabold mt-2">Rs.<?= number_format($stats['total_value'], 2) ?></p>
            </div>
        </div>

        <!-- System Navigation Hub (Access All Files) -->
        <div class="bg-white rounded-xl border border-slate-200 p-6 shadow-sm">
            <h2 class="text-lg font-bold text-slate-900 mb-4">🚀 Quick Access & Module Navigation</h2>
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3">
                <a href="view_assets.php" class="p-3 bg-slate-50 hover:bg-indigo-50 border border-slate-200 rounded-lg text-center font-medium text-xs text-slate-700 hover:text-indigo-600 transition">
                    💻 Asset Directory
                </a>
                <?php if (in_array($_SESSION['user_role'], ['admin', 'editor'])): ?>
                    <a href="add_asset.php" class="p-3 bg-slate-50 hover:bg-indigo-50 border border-slate-200 rounded-lg text-center font-medium text-xs text-slate-700 hover:text-indigo-600 transition">
                        ➕ Register Asset
                    </a>
                <?php endif; ?>
                <a href="employees.php" class="p-3 bg-slate-50 hover:bg-indigo-50 border border-slate-200 rounded-lg text-center font-medium text-xs text-slate-700 hover:text-indigo-600 transition">
                    👥 Employees
                </a>
                <a href="add_maintenance.php" class="p-3 bg-slate-50 hover:bg-indigo-50 border border-slate-200 rounded-lg text-center font-medium text-xs text-slate-700 hover:text-indigo-600 transition">
                    🛠️ Maintenance Logs
                </a>
                <?php if ($_SESSION['user_role'] === 'admin'): ?>
                    <a href="view_users.php" class="p-3 bg-slate-50 hover:bg-indigo-50 border border-slate-200 rounded-lg text-center font-medium text-xs text-slate-700 hover:text-indigo-600 transition">
                        👤 System Users
                    </a>
                    <a href="add_user.php" class="p-3 bg-slate-50 hover:bg-indigo-50 border border-slate-200 rounded-lg text-center font-medium text-xs text-slate-700 hover:text-indigo-600 transition">
                        ➕ Add User
                    </a>
                    <a href="audit_logs.php" class="p-3 bg-slate-50 hover:bg-indigo-50 border border-slate-200 rounded-lg text-center font-medium text-xs text-slate-700 hover:text-indigo-600 transition">
                        📋 Audit Logs
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Warranty Expiry Alerts -->
        <?php if (!empty($warrantyAlerts)): ?>
            <div class="bg-amber-50 border-l-4 border-amber-500 rounded-lg p-6 space-y-4 shadow-sm">
                <h2 class="text-lg font-bold text-amber-900">⚠️ Warranty Expiry Warnings (<?= count($warrantyAlerts) ?> Devices)</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm bg-white rounded shadow-sm">
                        <thead class="bg-amber-100 text-amber-900 border-b">
                            <tr>
                                <th class="p-3">Asset Tag</th>
                                <th class="p-3">Device</th>
                                <th class="p-3">Department</th>
                                <th class="p-3">Expiry Date</th>
                                <th class="p-3">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($warrantyAlerts as $w): ?>
                                <tr>
                                    <td class="p-3 font-mono font-bold"><?= htmlspecialchars($w['asset_tag']) ?></td>
                                    <td class="p-3"><?= htmlspecialchars($w['brand'] . ' ' . $w['model']) ?></td>
                                    <td class="p-3"><?= htmlspecialchars($w['dept_name'] ?? 'N/A') ?></td>
                                    <td class="p-3"><?= $w['warranty_expiry'] ?></td>
                                    <td class="p-3">
                                        <span class="px-2 py-1 text-xs font-bold rounded-full <?= $w['days_left'] < 0 ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-800' ?>">
                                            <?= $w['days_left'] < 0 ? 'Expired' : 'Expires in ' . $w['days_left'] . ' days' ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- Active Maintenance Overview Widget -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden shadow-sm">
            <div class="p-5 border-b border-slate-200 flex justify-between items-center">
                <h2 class="text-lg font-bold">🛠️ Active Maintenance & Repairs</h2>
                <a href="add_maintenance.php" class="text-indigo-600 hover:underline text-sm font-semibold">View All Logs →</a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 border-b text-xs text-slate-500 uppercase">
                        <tr>
                            <th class="p-4">Asset Tag</th>
                            <th class="p-4">Device / Dept</th>
                            <th class="p-4">Maintenance Task</th>
                            <th class="p-4">Provider</th>
                            <th class="p-4">Start Date</th>
                            <th class="p-4 text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (empty($activeMaintenance)): ?>
                            <tr>
                                <td colspan="6" class="p-6 text-center text-slate-400">No assets currently undergoing maintenance.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($activeMaintenance as $m): ?>
                            <tr>
                                <td class="p-4 font-mono font-bold text-slate-900"><?= htmlspecialchars($m['asset_tag'] ?? 'N/A') ?></td>
                                <td class="p-4">
                                    <div class="font-semibold text-slate-800"><?= htmlspecialchars(($m['brand'] ?? '') . ' ' . ($m['model'] ?? '')) ?></div>
                                    <div class="text-xs text-slate-400"><?= htmlspecialchars($m['dept_name'] ?? 'Unassigned') ?></div>
                                </td>
                                <td class="p-4 font-medium text-indigo-600"><?= htmlspecialchars($m['title']) ?></td>
                                <td class="p-4 text-slate-500"><?= htmlspecialchars($m['provider'] ?? 'Internal IT') ?></td>
                                <td class="p-4 text-slate-500"><?= htmlspecialchars($m['start_date']) ?></td>
                                <td class="p-4 text-center">
                                    <span class="px-2.5 py-1 text-xs font-bold rounded-full <?= $m['status'] === 'In Progress' ? 'bg-amber-100 text-amber-800' : 'bg-blue-100 text-blue-800' ?>">
                                        <?= htmlspecialchars($m['status']) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Department Asset Breakdown -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden shadow-sm">
            <div class="p-5 border-b border-slate-200"><h2 class="text-lg font-bold">Department Asset Breakdown</h2></div>
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50 border-b text-xs text-slate-500 uppercase">
                    <tr>
                        <th class="p-4">Department</th>
                        <th class="p-4 text-center">Asset Count</th>
                        <th class="p-4">Share</th>
                        <th class="p-4 text-right">Valuation</th>
                        <th class="p-4 text-center">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($departmentStats as $d): ?>
                        <?php $pct = $stats['total_assets'] > 0 ? round(($d['total_dept_assets'] / $stats['total_assets']) * 100, 1) : 0; ?>
                        <tr>
                            <td class="p-4 font-bold"><?= htmlspecialchars($d['dept_name']) ?></td>
                            <td class="p-4 text-center font-semibold"><?= $d['total_dept_assets'] ?></td>
                            <td class="p-4"><div class="bg-slate-200 h-2 rounded-full overflow-hidden w-full"><div class="bg-indigo-600 h-2" style="width: <?= $pct ?>%"></div></div></td>
                            <td class="p-4 text-right font-medium">Rs.<?= number_format($d['dept_value'], 2) ?></td>
                            <td class="p-4 text-center"><a href="view_assets.php?department_id=<?= $d['id'] ?>" class="text-indigo-600 hover:underline font-medium">View →</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    </main>
</body>
</html>