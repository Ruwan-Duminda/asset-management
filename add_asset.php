<?php
require_once 'db.php';
require_once 'auth_helpers.php';
checkAccess(['admin', 'editor']);

// Helper function to get prefix by category name
function getCategoryPrefix($categoryName) {
    $name = strtolower(trim($categoryName));
    if (strpos($name, 'laptop') !== false) return 'IT-LAP-';
    if (strpos($name, 'hdd') !== false || strpos($name, 'hard drive') !== false || strpos($name, 'storage') !== false) return 'IT-HDD-';
    if (strpos($name, 'headset') !== false || strpos($name, 'headphone') !== false) return 'IT-HS-';
    if (strpos($name, 'mouse') !== false) return 'IT-MS-';
    if (strpos($name, 'keyboard') !== false) return 'IT-KB-';
    if (strpos($name, 'usb') !== false || strpos($name, 'flash drive') !== false) return 'IT-USB-';
    if (strpos($name, 'phone') !== false || strpos($name, 'mobile') !== false) return 'IT-PH-';
    if (strpos($name, 'printer') !== false) return 'IT-PRN-';
    if (strpos($name, 'dongle') !== false) return 'IT-DGL-';
    if (strpos($name, 'router') !== false) return 'IT-RTR-';
    
    // Default prefix for any other categories
    return 'IT-AST-';
}

// Function to generate next auto asset tag number based on prefix
function generateNextAssetTag($pdo, $prefix) {
    // Search existing tags starting with prefix
    $stmt = $pdo->prepare("SELECT asset_tag FROM assets WHERE asset_tag LIKE ? ORDER BY id DESC");
    $stmt->execute([$prefix . '%']);
    $existingTags = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $maxNum = 0;
    foreach ($existingTags as $tag) {
        $numPart = str_replace($prefix, '', $tag);
        if (is_numeric($numPart)) {
            $num = intval($numPart);
            if ($num > $maxNum) {
                $maxNum = $num;
            }
        }
    }
    $nextNum = $maxNum + 1;
    return $prefix . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
}

// AJAX Endpoint to fetch auto-generated tag when Category dropdown changes
if (isset($_GET['action']) && $_GET['action'] === 'get_next_tag' && isset($_GET['category_id'])) {
    header('Content-Type: application/json');
    $catId = intval($_GET['category_id']);
    $catStmt = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
    $catStmt->execute([$catId]);
    $cat = $catStmt->fetch();
    
    if ($cat) {
        $prefix = getCategoryPrefix($cat['name']);
        $nextTag = generateNextAssetTag($pdo, $prefix);
        echo json_encode(['success' => true, 'tag' => $nextTag]);
    } else {
        echo json_encode(['success' => false, 'tag' => 'IT-AST-001']);
    }
    exit();
}

$categories  = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();
$departments = $pdo->query("SELECT * FROM departments ORDER BY name ASC")->fetchAll();
$employees   = $pdo->query("SELECT * FROM employees WHERE status = 'Active' ORDER BY full_name ASC")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

    // Auto generate tag if left empty or provided manually
    if (!empty($_POST['asset_tag'])) {
        $tag = trim($_POST['asset_tag']);
    } else {
        $catStmt = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
        $catStmt->execute([$cat_id]);
        $catRow = $catStmt->fetch();
        $prefix = getCategoryPrefix($catRow['name'] ?? '');
        $tag = generateNextAssetTag($pdo, $prefix);
    }

    $specs = json_encode([
        'cpu'     => $_POST['cpu'] ?? '',
        'ram'     => $_POST['ram'] ?? '',
        'storage' => $_POST['storage'] ?? '',
        'os'      => $_POST['os'] ?? ''
    ]);

    $stmt = $pdo->prepare("
        INSERT INTO assets 
        (asset_tag, category_id, brand, model, serial_number, specs, purchase_price, purchase_date, warranty_expiry, assigned_department_id, assigned_employee_id, status, condition_status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $tag, $cat_id, $brand, $model, $serial, $specs, $price, $p_date, $w_expiry, $dept_id, $emp_id, $status, $condition
    ]);

    logAudit($pdo, $tag, 'CREATE', "Added asset {$brand} {$model} with tag {$tag}");

    header("Location: view_assets.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add New Asset</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 font-sans text-slate-800 min-h-screen flex flex-col">
    <?php renderNav(); ?>
    <main class="max-w-3xl mx-auto p-6 bg-white rounded-lg shadow mt-6 mb-12 w-full">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-xl font-bold">Register New IT Asset</h1>
            <a href="view_assets.php" class="text-xs text-indigo-600 hover:underline font-semibold">← Back to Assets</a>
        </div>

        <form method="POST" class="space-y-4">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold uppercase mb-1">Category *</label>
                    <select name="category_id" id="category_id" required class="w-full border p-2 rounded text-sm bg-white" onchange="autoGenerateTag()">
                        <option value="">-- Select Category --</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-1">Asset Tag * (Auto-Generated)</label>
                    <div class="flex gap-2">
                        <input type="text" name="asset_tag" id="asset_tag" required placeholder="Select a category first..." class="w-full border p-2 rounded text-sm font-mono font-bold bg-slate-50 text-indigo-700">
                        <button type="button" onclick="autoGenerateTag()" title="Refresh Tag" class="px-3 bg-slate-200 hover:bg-slate-300 rounded text-xs font-bold">🔄</button>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-bold uppercase mb-1">Brand *</label>
                    <input type="text" name="brand" required placeholder="e.g. Dell, HP, Logitech" class="w-full border p-2 rounded text-sm">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-1">Model *</label>
                    <input type="text" name="model" required placeholder="e.g. Latitude 3520" class="w-full border p-2 rounded text-sm">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-1">Serial Number</label>
                    <input type="text" name="serial_number" placeholder="S/N Code" class="w-full border p-2 rounded text-sm font-mono">
                </div>
            </div>

            <div class="border-t pt-3">
                <span class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-2">Technical Specifications</span>
                <div class="grid grid-cols-4 gap-2">
                    <input type="text" name="cpu" placeholder="CPU (e.g. i5-11th Gen)" class="border p-2 rounded text-xs">
                    <input type="text" name="ram" placeholder="RAM (e.g. 16GB)" class="border p-2 rounded text-xs">
                    <input type="text" name="storage" placeholder="Storage (e.g. 512GB SSD)" class="border p-2 rounded text-xs">
                    <input type="text" name="os" placeholder="OS (e.g. Windows 11)" class="border p-2 rounded text-xs">
                </div>
            </div>

            <div class="grid grid-cols-3 gap-4 border-t pt-3">
                <div>
                    <label class="block text-xs font-bold uppercase mb-1">Purchase Price (LKR)</label>
                    <input type="number" step="0.01" name="purchase_price" placeholder="0.00" class="w-full border p-2 rounded text-sm">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-1">Purchase Date</label>
                    <input type="date" name="purchase_date" class="w-full border p-2 rounded text-sm">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase mb-1">Warranty Expiry Date</label>
                    <input type="date" name="warranty_expiry" class="w-full border p-2 rounded text-sm">
                </div>
            </div>

            <div class="grid grid-cols-3 gap-4 border-t pt-3">
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

            <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2.5 rounded shadow transition">Save Asset</button>
        </form>
    </main>

    <script>
        function autoGenerateTag() {
            const catId = document.getElementById('category_id').value;
            const tagInput = document.getElementById('asset_tag');
            
            if (!catId) {
                tagInput.value = '';
                return;
            }
            
            fetch(`add_asset.php?action=get_next_tag&category_id=${catId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        tagInput.value = data.tag;
                    }
                })
                .catch(err => console.error("Error fetching tag:", err));
        }
    </script>

    <?php renderFooter(); ?>
</body>
</html>