<?php
require_once 'db.php';
require_once 'auth_helpers.php';
checkAccess(['admin', 'editor']);

$id = $_GET['id'] ?? null;
$stmt = $pdo->prepare("SELECT * FROM assets WHERE id = ?");
$stmt->execute([$id]);
$asset = $stmt->fetch();

if (!$asset) die("Asset not found.");

$categories  = $pdo->query("SELECT * FROM categories")->fetchAll();
$departments = $pdo->query("SELECT * FROM departments ORDER BY name ASC")->fetchAll();
$employees   = $pdo->query("SELECT * FROM employees ORDER BY full_name ASC")->fetchAll();
$specs       = json_decode($asset['specs'], true) ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $brand    = trim($_POST['brand']);
    $model    = trim($_POST['model']);
    $status   = $_POST['status'];
    $dept_id  = $_POST['assigned_department_id'] ?: null;
    $emp_id   = $_POST['assigned_employee_id'] ?: null;
    $price    = $_POST['purchase_price'];
    $w_expiry = $_POST['warranty_expiry'] ?: null;

    $updatedSpecs = json_encode([
        'cpu'      => $_POST['cpu'] ?? '',
        'ram'      => $_POST['ram'] ?? '',
        'storage'  => $_POST['storage'] ?? '',
        'os'       => $_POST['os'] ?? ''
    ]);

    $stmt = $pdo->prepare("UPDATE assets SET brand=?, model=?, specs=?, purchase_price=?, warranty_expiry=?, status=?, assigned_department_id=?, assigned_employee_id=? WHERE id=?");
    $stmt->execute([$brand, $model, $updatedSpecs, $price, $w_expiry, $status, $dept_id, $emp_id, $id]);

    logAudit($pdo, $asset['asset_tag'], 'UPDATE', "Updated asset specs or assignment");

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
    <main class="max-w-3xl mx-auto px-4 pb-12">
        <form method="POST" class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm space-y-6">
            <h1 class="text-xl font-bold">Edit Asset: <?= htmlspecialchars($asset['asset_tag']) ?></h1>

            <div class="grid grid-cols-2 gap-4">
                <div><label class="block text-xs font-bold uppercase mb-1">Brand</label><input type="text" name="brand" value="<?= htmlspecialchars($asset['brand']) ?>" required class="w-full border p-2 rounded text-sm"></div>
                <div><label class="block text-xs font-bold uppercase mb-1">Model</label><input type="text" name="model" value="<?= htmlspecialchars($asset['model']) ?>" required class="w-full border p-2 rounded text-sm"></div>
            </div>

            <div class="border-t pt-4 grid grid-cols-2 gap-4">
                <input type="text" name="cpu" value="<?= htmlspecialchars($specs['cpu'] ?? '') ?>" placeholder="CPU" class="border p-2 rounded text-sm">
                <input type="text" name="ram" value="<?= htmlspecialchars($specs['ram'] ?? '') ?>" placeholder="RAM" class="border p-2 rounded text-sm">
                <input type="text" name="storage" value="<?= htmlspecialchars($specs['storage'] ?? '') ?>" placeholder="Storage" class="border p-2 rounded text-sm">
                <input type="text" name="os" value="<?= htmlspecialchars($specs['os'] ?? '') ?>" placeholder="OS" class="border p-2 rounded text-sm">
            </div>

            <div class="border-t pt-4 grid grid-cols-3 gap-4">
                <div><label class="block text-xs font-bold uppercase mb-1">Price</label><input type="number" step="0.01" name="purchase_price" value="<?= $asset['purchase_price'] ?>" class="w-full border p-2 rounded text-sm"></div>
                <div><label class="block text-xs font-bold uppercase mb-1">Warranty Expiry</label><input type="date" name="warranty_expiry" value="<?= $asset['warranty_expiry'] ?>" class="w-full border p-2 rounded text-sm"></div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-1">Status</label>
                    <select name="status" class="w-full border p-2 rounded text-sm">
                        <option value="In Stock" <?= $asset['status'] === 'In Stock' ? 'selected' : '' ?>>In Stock</option>
                        <option value="In Use" <?= $asset['status'] === 'In Use' ? 'selected' : '' ?>>In Use</option>
                        <option value="Maintenance" <?= $asset['status'] === 'Maintenance' ? 'selected' : '' ?>>Maintenance</option>
                        <option value="Retired" <?= $asset['status'] === 'Retired' ? 'selected' : '' ?>>Retired</option>
                    </select>
                </div>
            </div>

            <div class="border-t pt-4 grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold uppercase mb-1">Department</label>
                    <select name="assigned_department_id" class="w-full border p-2 rounded text-sm">
                        <option value="">Unassigned</option>
                        <?php foreach ($departments as $d): ?>
                            <option value="<?= $d['id'] ?>" <?= $asset['assigned_department_id'] == $d['id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-1">Assigned Employee</label>
                    <select name="assigned_employee_id" class="w-full border p-2 rounded text-sm">
                        <option value="">Unassigned</option>
                        <?php foreach ($employees as $e): ?>
                            <option value="<?= $e['id'] ?>" <?= $asset['assigned_employee_id'] == $e['id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['full_name'] . ' (' . $e['employee_id'] . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <button type="submit" class="w-full bg-indigo-600 text-white font-bold py-2 rounded">Save Changes</button>
        </form>
    </main>
</body>
</html>