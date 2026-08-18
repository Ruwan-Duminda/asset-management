<?php
require_once 'db.php';
require_once 'auth_helpers.php';
checkAccess(['admin', 'editor']);

$id = $_GET['id'] ?? null;
$stmt = $pdo->prepare("SELECT * FROM assets WHERE id = ?");
$stmt->execute([$id]);
$asset = $stmt->fetch();

if (!$asset) die("Asset not found.");

$categories  = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();
$departments = $pdo->query("SELECT * FROM departments ORDER BY name ASC")->fetchAll();
$employees   = $pdo->query("SELECT * FROM employees ORDER BY full_name ASC")->fetchAll();
$specs       = json_decode($asset['specs'] ?? '', true) ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $asset_tag   = trim($_POST['asset_tag']);
    $category_id = $_POST['category_id'] ?: null;
    $brand       = trim($_POST['brand']);
    $model       = trim($_POST['model']);
    $serial_num  = trim($_POST['serial_number']);
    $p_date      = $_POST['purchase_date'] ?: null;
    $price       = $_POST['purchase_price'] ?: null;
    $w_expiry    = $_POST['warranty_expiry'] ?: null;
    $status      = $_POST['status'];
    $condition   = $_POST['condition_status'];
    $damage_type = $_POST['damage_type'];
    $dmg_notes   = trim($_POST['damage_notes']);
    $dept_id     = $_POST['assigned_department_id'] ?: null;
    $emp_id      = $_POST['assigned_employee_id'] ?: null;

    $updatedSpecs = json_encode([
        'cpu'     => $_POST['cpu'] ?? '',
        'ram'     => $_POST['ram'] ?? '',
        'storage' => $_POST['storage'] ?? '',
        'os'      => $_POST['os'] ?? ''
    ]);

    $stmt = $pdo->prepare("
        UPDATE assets 
        SET asset_tag = ?, category_id = ?, brand = ?, model = ?, serial_number = ?, 
            purchase_date = ?, purchase_price = ?, warranty_expiry = ?, specs = ?, 
            status = ?, condition_status = ?, damage_type = ?, damage_notes = ?, 
            assigned_department_id = ?, assigned_employee_id = ? 
        WHERE id = ?
    ");
    $stmt->execute([
        $asset_tag, $category_id, $brand, $model, $serial_num,
        $p_date, $price, $w_expiry, $updatedSpecs,
        $status, $condition, $damage_type, $dmg_notes,
        $dept_id, $emp_id, $id
    ]);

    if (function_exists('logAudit')) {
        logAudit($pdo, $asset_tag, 'UPDATE', "Updated full asset details");
    }

    header('Location: view_assets.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>ITAM - Edit Asset</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 text-slate-800">
    <?php renderNav(); ?>
    <main class="max-w-3xl mx-auto px-4 py-8">
        <form method="POST" class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm space-y-6">
            <h1 class="text-xl font-bold text-slate-800">Edit Asset: <?= htmlspecialchars($asset['asset_tag']) ?></h1>

            <!-- Basic Info -->
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold uppercase mb-1">Asset Tag *</label>
                    <input type="text" name="asset_tag" value="<?= htmlspecialchars($asset['asset_tag']) ?>" required class="w-full border p-2 rounded text-sm font-mono">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-1">Category</label>
                    <select name="category_id" class="w-full border p-2 rounded text-sm bg-white">
                        <option value="">Uncategorized</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= $asset['category_id'] == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-bold uppercase mb-1">Brand *</label>
                    <input type="text" name="brand" value="<?= htmlspecialchars($asset['brand']) ?>" required class="w-full border p-2 rounded text-sm">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-1">Model *</label>
                    <input type="text" name="model" value="<?= htmlspecialchars($asset['model']) ?>" required class="w-full border p-2 rounded text-sm">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-1">Serial Number</label>
                    <input type="text" name="serial_number" value="<?= htmlspecialchars($asset['serial_number'] ?? '') ?>" class="w-full border p-2 rounded text-sm font-mono">
                </div>
            </div>

            <!-- Specifications -->
            <div class="border-t pt-4">
                <label class="block text-xs font-bold uppercase mb-2 text-slate-500">Hardware Specifications</label>
                <div class="grid grid-cols-2 gap-4">
                    <input type="text" name="cpu" value="<?= htmlspecialchars($specs['cpu'] ?? '') ?>" placeholder="CPU (e.g. Intel i7)" class="border p-2 rounded text-sm">
                    <input type="text" name="ram" value="<?= htmlspecialchars($specs['ram'] ?? '') ?>" placeholder="RAM (e.g. 16GB)" class="border p-2 rounded text-sm">
                    <input type="text" name="storage" value="<?= htmlspecialchars($specs['storage'] ?? '') ?>" placeholder="Storage (e.g. 512GB SSD)" class="border p-2 rounded text-sm">
                    <input type="text" name="os" value="<?= htmlspecialchars($specs['os'] ?? '') ?>" placeholder="OS (e.g. Windows 11)" class="border p-2 rounded text-sm">
                </div>
            </div>

            <!-- Financials & Dates -->
            <div class="border-t pt-4 grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-bold uppercase mb-1">Purchase Date</label>
                    <input type="date" name="purchase_date" value="<?= $asset['purchase_date'] ?? '' ?>" class="w-full border p-2 rounded text-sm">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-1">Purchase Price ($)</label>
                    <input type="number" step="0.01" name="purchase_price" value="<?= $asset['purchase_price'] ?? '' ?>" class="w-full border p-2 rounded text-sm">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-1">Warranty Expiry</label>
                    <input type="date" name="warranty_expiry" value="<?= $asset['warranty_expiry'] ?? '' ?>" class="w-full border p-2 rounded text-sm">
                </div>
            </div>

            <!-- Status & Condition -->
            <div class="border-t pt-4 grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-bold uppercase mb-1">Status *</label>
                    <select name="status" class="w-full border p-2 rounded text-sm bg-white">
                        <?php foreach (['In Stock', 'In Use', 'Maintenance', 'Retired'] as $s): ?>
                            <option value="<?= $s ?>" <?= $asset['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-1">Condition *</label>
                    <select name="condition_status" class="w-full border p-2 rounded text-sm bg-white">
                        <?php foreach (['New', 'Excellent', 'Good', 'Fair', 'Poor', 'Damaged'] as $c): ?>
                            <option value="<?= $c ?>" <?= ($asset['condition_status'] ?? '') === $c ? 'selected' : '' ?>><?= $c ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-1">Damage Type</label>
                    <select name="damage_type" class="w-full border p-2 rounded text-sm bg-white">
                        <?php foreach (['None', 'Screen Crack', 'Water Damage', 'Battery Swell', 'Keyboard/Port Failure', 'Cosmetic/Body Damage', 'Other Hardware Issue'] as $dt): ?>
                            <option value="<?= $dt ?>" <?= ($asset['damage_type'] ?? '') === $dt ? 'selected' : '' ?>><?= $dt ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold uppercase mb-1">Damage Notes / Remarks</label>
                <textarea name="damage_notes" rows="2" class="w-full border p-2 rounded text-sm"><?= htmlspecialchars($asset['damage_notes'] ?? '') ?></textarea>
            </div>

            <!-- Assignments -->
            <div class="border-t pt-4 grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold uppercase mb-1">Department</label>
                    <select name="assigned_department_id" class="w-full border p-2 rounded text-sm bg-white">
                        <option value="">Unassigned</option>
                        <?php foreach ($departments as $d): ?>
                            <option value="<?= $d['id'] ?>" <?= $asset['assigned_department_id'] == $d['id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-1">Assigned Employee</label>
                    <select name="assigned_employee_id" class="w-full border p-2 rounded text-sm bg-white">
                        <option value="">Unassigned</option>
                        <?php foreach ($employees as $e): ?>
                            <option value="<?= $e['id'] ?>" <?= $asset['assigned_employee_id'] == $e['id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['full_name'] . ' (' . $e['employee_id'] . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="flex justify-end gap-3 pt-4 border-t">
                <a href="view_assets.php" class="px-4 py-2 border rounded text-sm font-semibold text-slate-600 hover:bg-slate-50">Cancel</a>
                <button type="submit" class="px-6 py-2 bg-indigo-600 text-white font-bold rounded hover:bg-indigo-700 shadow">Save Changes</button>
            </div>
        </form>
    </main>
    <?php renderFooter(); ?>
</body>
</html>