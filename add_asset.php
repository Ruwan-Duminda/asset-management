<?php
require_once 'db.php';
require_once 'auth_helpers.php';
checkAccess(['admin', 'editor']);

$categories  = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();
$departments = $pdo->query("SELECT * FROM departments ORDER BY name ASC")->fetchAll();
$employees   = $pdo->query("SELECT * FROM employees WHERE status = 'Active' ORDER BY full_name ASC")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tag         = trim($_POST['asset_tag']);
    $cat_id      = $_POST['category_id'];
    $brand       = trim($_POST['brand']);
    $model       = trim($_POST['model']);
    $serial      = trim($_POST['serial_number']);
    $price       = !empty($_POST['purchase_price']) ? $_POST['purchase_price'] : 0.00;
    $p_date      = !empty($_POST['purchase_date']) ? $_POST['purchase_date'] : null;
    $w_expiry    = !empty($_POST['warranty_expiry']) ? $_POST['warranty_expiry'] : null;
    $dept_id     = !empty($_POST['assigned_department_id']) ? $_POST['assigned_department_id'] : null;
    $emp_id      = !empty($_POST['assigned_employee_id']) ? $_POST['assigned_employee_id'] : null;
    $status      = $_POST['status'];
    $condition   = $_POST['condition_status'];

    $specs = json_encode([
        'cpu'     => $_POST['cpu'] ?? '',
        'ram'     => $_POST['ram'] ?? '',
        'storage' => $_POST['storage'] ?? '',
        'os'      => $_POST['os'] ?? ''
    ]);

    $assignDate = $emp_id ? date('Y-m-d') : null;

    $sql = "INSERT INTO assets (
                asset_tag, category_id, brand, model, serial_number, specs, 
                purchase_price, purchase_date, warranty_expiry, status, condition_status,
                assigned_department_id, assigned_employee_id, assignment_date
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $tag, $cat_id, $brand, $model, $serial, $specs, 
        $price, $p_date, $w_expiry, $status, $condition,
        $dept_id, $emp_id, $assignDate
    ]);

    $newAssetId = $pdo->lastInsertId();

    logAudit($pdo, $tag, 'CREATE', "Registered new asset ($brand $model) with condition: $condition");

    // If an employee is assigned during creation, redirect straight to receipt print view
    if ($emp_id) {
        header("Location: print_receipt.php?id=$newAssetId&auto=true");
    } else {
        header('Location: view_assets.php');
    }
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>ITAM - Add Asset</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 text-slate-800">
    <?php renderNav(); ?>
    <main class="max-w-3xl mx-auto px-4 pb-12">
        <form method="POST" class="bg-white p-6 rounded-xl border border-slate-200 shadow-sm space-y-6">
            <h1 class="text-xl font-bold text-slate-900">Register New Hardware Asset</h1>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold uppercase mb-1">Asset Tag ID *</label>
                    <input type="text" name="asset_tag" placeholder="e.g. IT-LAP-001" required class="w-full border p-2 rounded text-sm">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-1">Category *</label>
                    <select name="category_id" required class="w-full border p-2 rounded text-sm bg-white">
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-1">Brand/Make *</label>
                    <input type="text" name="brand" placeholder="Dell, Lenovo, HP" required class="w-full border p-2 rounded text-sm">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-1">Model Name *</label>
                    <input type="text" name="model" placeholder="ThinkPad T14" required class="w-full border p-2 rounded text-sm">
                </div>
            </div>

            <div class="border-t pt-4 space-y-3">
                <h2 class="text-sm font-bold text-slate-500 uppercase">Specs (Laptop / PC Detail)</h2>
                <div class="grid grid-cols-2 gap-4">
                    <input type="text" name="cpu" placeholder="CPU (e.g. Intel i7 / Apple M2)" class="border p-2 rounded text-sm">
                    <input type="text" name="ram" placeholder="RAM (e.g. 16GB DDR4)" class="border p-2 rounded text-sm">
                    <input type="text" name="storage" placeholder="Storage (e.g. 512GB NVMe SSD)" class="border p-2 rounded text-sm">
                    <input type="text" name="os" placeholder="OS (e.g. Windows 11 Pro)" class="border p-2 rounded text-sm">
                </div>
            </div>

            <!-- Financial & Warranty Details -->
            <div class="border-t pt-4 grid grid-cols-2 sm:grid-cols-4 gap-4">
                <div>
                    <label class="block text-xs font-bold uppercase mb-1">Serial Number</label>
                    <input type="text" name="serial_number" class="w-full border p-2 rounded text-sm">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-1">Purchase Price (Rs.)</label>
                    <input type="number" step="0.01" name="purchase_price" placeholder="0.00" class="w-full border p-2 rounded text-sm">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-1">Purchase Date</label>
                    <input type="date" name="purchase_date" class="w-full border p-2 rounded text-sm">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-1">Warranty Expiry</label>
                    <input type="date" name="warranty_expiry" class="w-full border p-2 rounded text-sm">
                </div>
            </div>

            <!-- Assignment & Initial Physical State -->
            <div class="border-t pt-4 grid grid-cols-2 sm:grid-cols-4 gap-4">
                <div>
                    <label class="block text-xs font-bold uppercase mb-1">Department</label>
                    <select name="assigned_department_id" class="w-full border p-2 rounded text-sm bg-white">
                        <option value="">Unassigned</option>
                        <?php foreach ($departments as $d): ?>
                            <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-1">Assigned Employee</label>
                    <select name="assigned_employee_id" class="w-full border p-2 rounded text-sm bg-white">
                        <option value="">Unassigned</option>
                        <?php foreach ($employees as $e): ?>
                            <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['full_name'] . ' (' . $e['employee_id'] . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-1">Status</label>
                    <select name="status" class="w-full border p-2 rounded text-sm bg-white">
                        <option value="In Stock">In Stock</option>
                        <option value="In Use">In Use</option>
                        <option value="Maintenance">Maintenance</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-1">Physical Condition *</label>
                    <select name="condition_status" required class="w-full border p-2 rounded text-sm bg-white">
                        <option value="New" selected>New</option>
                        <option value="Excellent">Excellent</option>
                        <option value="Good">Good</option>
                        <option value="Fair">Fair</option>
                        <option value="Poor">Poor</option>
                        <option value="Damaged">Damaged</option>
                    </select>
                </div>
            </div>

            <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2.5 rounded shadow transition">Save Asset</button>
        </form>
    </main>
    <?php renderFooter(); ?>
</body>
</html>