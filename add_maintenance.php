<?php
require_once 'db.php';
require_once 'auth_helpers.php';
checkAccess(['admin', 'editor', 'viewer']);

// Fetch list of assets for the modal dropdowns
$assetsList = $pdo->query("SELECT id, asset_tag, brand, model FROM assets ORDER BY asset_tag ASC")->fetchAll();

// Handle CREATE / UPDATE / DELETE Requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_SESSION['user_role'], ['admin', 'editor'])) {
    
    // --- 1. DELETE LOG ---
    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $log_id = $_POST['log_id'];
        $stmt = $pdo->prepare("DELETE FROM maintenance_logs WHERE id = ?");
        $stmt->execute([$log_id]);

        header('Location: add_maintenance.php?deleted=success');
        exit();
    }

    // --- 2. ADD LOG ---
    if (isset($_POST['action']) && $_POST['action'] === 'create') {
        $asset_id         = $_POST['asset_id'];
        $title            = trim($_POST['title']);
        $description      = trim($_POST['description']);
        $maintenance_type = $_POST['maintenance_type'];
        $provider         = trim($_POST['provider']);
        $cost             = !empty($_POST['cost']) ? $_POST['cost'] : 0.00;
        $start_date       = $_POST['start_date'];
        $completion_date  = !empty($_POST['completion_date']) ? $_POST['completion_date'] : null;
        $status           = $_POST['status'];
        $logged_by        = $_SESSION['user_id'] ?? null;

        $stmt = $pdo->prepare("
            INSERT INTO maintenance_logs (
                asset_id, title, issue_description, maintenance_type, log_type, 
                service_provider, cost, start_date, completion_date, status, logged_by_user_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $asset_id, $title, $description, $maintenance_type, $maintenance_type, 
            $provider, $cost, $start_date, $completion_date, $status, $logged_by
        ]);

        if ($status === 'In Progress') {
            $updateAsset = $pdo->prepare("UPDATE assets SET status = 'Maintenance' WHERE id = ?");
            $updateAsset->execute([$asset_id]);
        }

        header('Location: add_maintenance.php?added=success');
        exit();
    }

    // --- 3. EDIT / UPDATE LOG ---
    if (isset($_POST['action']) && $_POST['action'] === 'update') {
        $log_id           = $_POST['log_id'];
        $asset_id         = $_POST['asset_id'];
        $title            = trim($_POST['title']);
        $description      = trim($_POST['description']);
        $maintenance_type = $_POST['maintenance_type'];
        $provider         = trim($_POST['provider']);
        $cost             = !empty($_POST['cost']) ? $_POST['cost'] : 0.00;
        $start_date       = $_POST['start_date'];
        $completion_date  = !empty($_POST['completion_date']) ? $_POST['completion_date'] : null;
        $status           = $_POST['status'];

        $stmt = $pdo->prepare("
            UPDATE maintenance_logs 
            SET asset_id = ?, title = ?, issue_description = ?, maintenance_type = ?, log_type = ?,
                service_provider = ?, cost = ?, start_date = ?, completion_date = ?, status = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $asset_id, $title, $description, $maintenance_type, $maintenance_type, 
            $provider, $cost, $start_date, $completion_date, $status, $log_id
        ]);

        // Auto-update parent asset status depending on maintenance status
        if ($status === 'In Progress') {
            $updateAsset = $pdo->prepare("UPDATE assets SET status = 'Maintenance' WHERE id = ?");
            $updateAsset->execute([$asset_id]);
        } elseif (in_array($status, ['Completed', 'Cancelled'])) {
            // Check if asset is assigned to employee to decide back to 'Deployed' or 'In Stock'
            $checkAssign = $pdo->prepare("SELECT assigned_employee_id FROM assets WHERE id = ?");
            $checkAssign->execute([$asset_id]);
            $assigned = $checkAssign->fetchColumn();
            $newStatus = $assigned ? 'Deployed' : 'In Stock';

            $updateAsset = $pdo->prepare("UPDATE assets SET status = ? WHERE id = ? AND status = 'Maintenance'");
            $updateAsset->execute([$newStatus, $asset_id]);
        }

        header('Location: add_maintenance.php?updated=success');
        exit();
    }
}

// Fetch Maintenance Records
$maintenanceLogs = $pdo->query("
    SELECT m.*, 
           m.issue_description AS description, 
           m.log_type AS maintenance_type, 
           m.service_provider AS provider,
           a.asset_tag, a.brand, a.model, d.name AS dept_name
    FROM maintenance_logs m
    LEFT JOIN assets a ON m.asset_id = a.id
    LEFT JOIN departments d ON a.assigned_department_id = d.id
    ORDER BY m.start_date DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>ITAM - Maintenance & Repairs</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 text-slate-800">
    <?php renderNav(); ?>
    <main class="max-w-7xl mx-auto px-4 pb-12 space-y-6">
        
        <!-- Header Section -->
        <div class="flex justify-between items-center">
            <div>
                <h1 class="text-2xl font-bold">Maintenance & Repair Logs</h1>
                <p class="text-sm text-slate-500">Track device repairs, servicing schedules, and operational maintenance costs.</p>
            </div>
            <?php if (in_array($_SESSION['user_role'], ['admin', 'editor'])): ?>
                <button onclick="document.getElementById('addMaintenanceModal').classList.remove('hidden')" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg font-medium shadow">+ Log Maintenance</button>
            <?php endif; ?>
        </div>

        <!-- Maintenance Logs Data Table -->
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50 border-b text-xs text-slate-500 uppercase">
                    <tr>
                        <th class="p-3">Asset Tag</th>
                        <th class="p-3">Device / Dept</th>
                        <th class="p-3">Maintenance Details</th>
                        <th class="p-3">Type & Provider</th>
                        <th class="p-3">Dates</th>
                        <th class="p-3 text-right">Cost</th>
                        <th class="p-3 text-center">Status</th>
                        <?php if (in_array($_SESSION['user_role'], ['admin', 'editor'])): ?>
                            <th class="p-3 text-right">Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($maintenanceLogs)): ?>
                        <tr>
                            <td colspan="8" class="p-6 text-center text-slate-400 font-medium">No maintenance logs found.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($maintenanceLogs as $m): ?>
                        <tr>
                            <td class="p-3 font-mono font-bold text-slate-900"><?= htmlspecialchars($m['asset_tag'] ?? 'N/A') ?></td>
                            <td class="p-3">
                                <div class="font-semibold text-indigo-600"><?= htmlspecialchars(($m['brand'] ?? '') . ' ' . ($m['model'] ?? '')) ?></div>
                                <div class="text-xs text-slate-400">🏢 <?= htmlspecialchars($m['dept_name'] ?? 'Unassigned') ?></div>
                            </td>
                            <td class="p-3">
                                <div class="font-medium text-slate-800"><?= htmlspecialchars($m['title']) ?></div>
                                <div class="text-xs text-slate-500 line-clamp-1"><?= htmlspecialchars($m['description'] ?? '') ?></div>
                            </td>
                            <td class="p-3 text-xs">
                                <div class="font-medium"><?= htmlspecialchars($m['maintenance_type']) ?></div>
                                <div class="text-slate-400">🛠️ <?= htmlspecialchars($m['provider'] ?? 'Internal IT') ?></div>
                            </td>
                            <td class="p-3 text-xs">
                                <div><span class="text-slate-400">Start:</span> <?= htmlspecialchars($m['start_date']) ?></div>
                                <div class="text-slate-400"><span class="text-slate-400">Done:</span> <?= htmlspecialchars($m['completion_date'] ?? 'Pending') ?></div>
                            </td>
                            <td class="p-3 text-right font-medium">$<?= number_format($m['cost'], 2) ?></td>
                            <td class="p-3 text-center">
                                <?php
                                    $statusClasses = [
                                        'Scheduled'   => 'bg-blue-50 text-blue-700 border-blue-200',
                                        'In Progress' => 'bg-amber-50 text-amber-700 border-amber-200',
                                        'Completed'   => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                        'Cancelled'   => 'bg-slate-100 text-slate-600 border-slate-200',
                                    ];
                                    $badgeClass = $statusClasses[$m['status']] ?? 'bg-slate-50 text-slate-700';
                                ?>
                                <span class="px-2.5 py-1 text-xs font-bold border rounded-full <?= $badgeClass ?>">
                                    <?= htmlspecialchars($m['status']) ?>
                                </span>
                            </td>
                            
                            <!-- Action Buttons -->
                            <?php if (in_array($_SESSION['user_role'], ['admin', 'editor'])): ?>
                                <td class="p-3 text-right space-x-2">
                                    <button onclick='openEditModal(<?= json_encode($m) ?>)' class="text-xs text-indigo-600 hover:text-indigo-900 font-semibold bg-indigo-50 hover:bg-indigo-100 px-2.5 py-1 rounded">Edit</button>
                                    <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this log?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="log_id" value="<?= $m['id'] ?>">
                                        <button type="submit" class="text-xs text-red-600 hover:text-red-900 font-semibold bg-red-50 hover:bg-red-100 px-2.5 py-1 rounded">Delete</button>
                                    </form>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Add Maintenance Modal -->
        <div id="addMaintenanceModal" class="hidden fixed inset-0 bg-slate-900/50 flex items-center justify-center p-4">
            <div class="bg-white rounded-xl shadow-xl max-w-lg w-full p-6 space-y-4">
                <div class="flex justify-between items-center border-b pb-3">
                    <h3 class="font-bold text-lg">Log Maintenance / Repair</h3>
                    <button onclick="document.getElementById('addMaintenanceModal').classList.add('hidden')" class="text-slate-400 hover:text-black">✕</button>
                </div>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="create">
                    <div>
                        <label class="block text-xs font-bold uppercase mb-1">Asset *</label>
                        <select name="asset_id" required class="w-full border p-2 rounded text-sm bg-white">
                            <option value="">-- Select Asset --</option>
                            <?php foreach ($assetsList as $asset): ?>
                                <option value="<?= $asset['id'] ?>">
                                    <?= htmlspecialchars($asset['asset_tag'] . ' - ' . $asset['brand'] . ' ' . $asset['model']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold uppercase mb-1">Title / Issue *</label>
                            <input type="text" name="title" placeholder="e.g. Battery Replacement" required class="w-full border p-2 rounded text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase mb-1">Type *</label>
                            <select name="maintenance_type" required class="w-full border p-2 rounded text-sm bg-white">
                                <option value="Repair">Repair</option>
                                <option value="Upgrade">Upgrade</option>
                                <option value="Routine Service">Routine Service</option>
                                <option value="Inspection">Inspection</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold uppercase mb-1">Description</label>
                        <textarea name="description" rows="2" placeholder="Details regarding the maintenance..." class="w-full border p-2 rounded text-sm"></textarea>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold uppercase mb-1">Service Provider</label>
                            <input type="text" name="provider" placeholder="e.g. In-House / Dell Care" class="w-full border p-2 rounded text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase mb-1">Cost ($)</label>
                            <input type="number" step="0.01" min="0" name="cost" placeholder="0.00" class="w-full border p-2 rounded text-sm">
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold uppercase mb-1">Start Date *</label>
                            <input type="date" name="start_date" required value="<?= date('Y-m-d') ?>" class="w-full border p-2 rounded text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase mb-1">Completion Date</label>
                            <input type="date" name="completion_date" class="w-full border p-2 rounded text-sm">
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold uppercase mb-1">Status *</label>
                        <select name="status" required class="w-full border p-2 rounded text-sm bg-white">
                            <option value="Scheduled">Scheduled</option>
                            <option value="In Progress" selected>In Progress</option>
                            <option value="Completed">Completed</option>
                            <option value="Cancelled">Cancelled</option>
                        </select>
                    </div>

                    <div class="flex justify-end space-x-2 pt-2">
                        <button type="button" onclick="document.getElementById('addMaintenanceModal').classList.add('hidden')" class="px-4 py-2 border rounded text-sm">Cancel</button>
                        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white font-bold rounded text-sm">Save Record</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Edit Maintenance Modal -->
        <div id="editMaintenanceModal" class="hidden fixed inset-0 bg-slate-900/50 flex items-center justify-center p-4">
            <div class="bg-white rounded-xl shadow-xl max-w-lg w-full p-6 space-y-4">
                <div class="flex justify-between items-center border-b pb-3">
                    <h3 class="font-bold text-lg">Edit Maintenance Record</h3>
                    <button onclick="document.getElementById('editMaintenanceModal').classList.add('hidden')" class="text-slate-400 hover:text-black">✕</button>
                </div>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="log_id" id="edit_log_id">

                    <div>
                        <label class="block text-xs font-bold uppercase mb-1">Asset *</label>
                        <select name="asset_id" id="edit_asset_id" required class="w-full border p-2 rounded text-sm bg-white">
                            <?php foreach ($assetsList as $asset): ?>
                                <option value="<?= $asset['id'] ?>">
                                    <?= htmlspecialchars($asset['asset_tag'] . ' - ' . $asset['brand'] . ' ' . $asset['model']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold uppercase mb-1">Title / Issue *</label>
                            <input type="text" name="title" id="edit_title" required class="w-full border p-2 rounded text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase mb-1">Type *</label>
                            <select name="maintenance_type" id="edit_maintenance_type" required class="w-full border p-2 rounded text-sm bg-white">
                                <option value="Repair">Repair</option>
                                <option value="Upgrade">Upgrade</option>
                                <option value="Routine Service">Routine Service</option>
                                <option value="Inspection">Inspection</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold uppercase mb-1">Description</label>
                        <textarea name="description" id="edit_description" rows="2" class="w-full border p-2 rounded text-sm"></textarea>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold uppercase mb-1">Service Provider</label>
                            <input type="text" name="provider" id="edit_provider" class="w-full border p-2 rounded text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase mb-1">Cost ($)</label>
                            <input type="number" step="0.01" min="0" name="cost" id="edit_cost" class="w-full border p-2 rounded text-sm">
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold uppercase mb-1">Start Date *</label>
                            <input type="date" name="start_date" id="edit_start_date" required class="w-full border p-2 rounded text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase mb-1">Completion Date</label>
                            <input type="date" name="completion_date" id="edit_completion_date" class="w-full border p-2 rounded text-sm">
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold uppercase mb-1">Status *</label>
                        <select name="status" id="edit_status" required class="w-full border p-2 rounded text-sm bg-white">
                            <option value="Scheduled">Scheduled</option>
                            <option value="In Progress">In Progress</option>
                            <option value="Completed">Completed</option>
                            <option value="Cancelled">Cancelled</option>
                        </select>
                    </div>

                    <div class="flex justify-end space-x-2 pt-2">
                        <button type="button" onclick="document.getElementById('editMaintenanceModal').classList.add('hidden')" class="px-4 py-2 border rounded text-sm">Cancel</button>
                        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white font-bold rounded text-sm">Update Record</button>
                    </div>
                </form>
            </div>
        </div>

    </main>

    <script>
        function openEditModal(log) {
            document.getElementById('edit_log_id').value = log.id;
            document.getElementById('edit_asset_id').value = log.asset_id;
            document.getElementById('edit_title').value = log.title;
            document.getElementById('edit_maintenance_type').value = log.maintenance_type;
            document.getElementById('edit_description').value = log.description || '';
            document.getElementById('edit_provider').value = log.provider || '';
            document.getElementById('edit_cost').value = log.cost;
            document.getElementById('edit_start_date').value = log.start_date;
            document.getElementById('edit_completion_date').value = log.completion_date || '';
            document.getElementById('edit_status').value = log.status;

            document.getElementById('editMaintenanceModal').classList.remove('hidden');
        }
    </script>
    <?php renderFooter(); ?>
</body>
</html>