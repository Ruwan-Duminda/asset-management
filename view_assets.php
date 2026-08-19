<?php
require_once 'db.php';
require_once 'auth_helpers.php';

// Ensure user has access to view this page
checkAccess(['admin', 'editor', 'viewer']);

// Determine current user role
$userRole = $_SESSION['user_role'] ?? 'viewer';

// -----------------------------------------------------------------------------
// ACTION: EXPORT ASSETS TO CSV
// -----------------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    if (function_exists('logAudit')) {
        logAudit($pdo, 'SYSTEM', 'READ', 'Exported assets database to CSV');
    }

    $filename = "ITAM_Assets_Export_" . date('Y-m-d_H-i-s') . ".csv";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF"); // UTF-8 BOM

    fputcsv($output, [
        'ID', 'Asset Tag', 'Brand', 'Model', 'Serial Number', 'Category', 
        'Status', 'Condition', 'Damage Type', 'Damage Notes', 
        'Assigned Employee', 'Assigned Department', 'Purchase Date', 'Purchase Price', 
        'Warranty Expiry', 'Created At'
    ]);

    $exportSql = "SELECT a.id, a.asset_tag, a.brand, a.model, a.serial_number, c.name AS category_name, 
                         a.status, a.condition_status, a.damage_type, a.damage_notes, 
                         e.full_name AS employee_name, d.name AS department_name, 
                         a.purchase_date, a.purchase_price, a.warranty_expiry, a.created_at
                  FROM assets a
                  LEFT JOIN categories c ON a.category_id = c.id
                  LEFT JOIN departments d ON a.assigned_department_id = d.id
                  LEFT JOIN employees e ON a.assigned_employee_id = e.id
                  ORDER BY a.id DESC";

    $stmt = $pdo->query($exportSql);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['id'], $row['asset_tag'], $row['brand'], $row['model'], $row['serial_number'] ?? '',
            $row['category_name'] ?? 'Uncategorized', $row['status'],
            $row['condition_status'], $row['damage_type'] ?? 'None',
            $row['damage_notes'] ?? '', $row['employee_name'] ?? 'Unassigned',
            $row['department_name'] ?? 'N/A', $row['purchase_date'] ?? 'N/A',
            $row['purchase_price'] ?? '', $row['warranty_expiry'] ?? '', $row['created_at'] ?? ''
        ]);
    }

    fclose($output);
    exit();
}

$updateMessage = '';
$errorMessage = '';

// Handle Actions (Edit, Assign, Delete) via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!in_array($userRole, ['admin', 'editor'])) {
        $errorMessage = "Unauthorized action. You do not have permission to modify assets.";
    } else {
        if ($_POST['action'] === 'edit_asset') {
            try {
                $asset_id         = $_POST['asset_id'];
                $asset_tag        = trim($_POST['asset_tag']);
                $brand            = trim($_POST['brand']);
                $model            = trim($_POST['model']);
                $serial_number    = trim($_POST['serial_number'] ?? '');
                $category_id      = !empty($_POST['category_id']) ? $_POST['category_id'] : null;
                $purchase_date    = !empty($_POST['purchase_date']) ? $_POST['purchase_date'] : null;
                $purchase_price   = !empty($_POST['purchase_price']) ? $_POST['purchase_price'] : null;
                $warranty_expiry  = !empty($_POST['warranty_expiry']) ? $_POST['warranty_expiry'] : null;
                $status           = $_POST['status'];
                $condition_status = $_POST['condition_status'];
                $damage_type      = $_POST['damage_type'] ?? 'None';
                $damage_notes     = trim($_POST['damage_notes'] ?? '');
                $department_id    = !empty($_POST['assigned_department_id']) ? $_POST['assigned_department_id'] : null;
                $employee_id      = !empty($_POST['assigned_employee_id']) ? $_POST['assigned_employee_id'] : null;

                $updatedSpecs = json_encode([
                    'cpu'     => $_POST['cpu'] ?? '',
                    'ram'     => $_POST['ram'] ?? '',
                    'storage' => $_POST['storage'] ?? '',
                    'os'      => $_POST['os'] ?? ''
                ]);

                $stmt = $pdo->prepare("
                    UPDATE assets 
                    SET asset_tag = ?, brand = ?, model = ?, serial_number = ?, category_id = ?, 
                        purchase_date = ?, purchase_price = ?, warranty_expiry = ?, specs = ?, 
                        status = ?, condition_status = ?, damage_type = ?, damage_notes = ?, 
                        assigned_department_id = ?, assigned_employee_id = ? 
                    WHERE id = ?
                ");
                $stmt->execute([
                    $asset_tag, $brand, $model, $serial_number, $category_id, 
                    $purchase_date, $purchase_price, $warranty_expiry, $updatedSpecs, 
                    $status, $condition_status, $damage_type, $damage_notes, 
                    $department_id, $employee_id, $asset_id
                ]);

                if (function_exists('logAudit')) {
                    logAudit($pdo, $asset_tag, 'UPDATE', "Updated full asset details ($brand $model)");
                }
                $updateMessage = "Asset updated successfully!";
            } catch (PDOException $e) {
                $errorMessage = "Error updating asset: " . $e->getMessage();
            }
        }

        if ($_POST['action'] === 'assign_asset') {
            try {
                $asset_id       = $_POST['asset_id'];
                $asset_tag      = $_POST['asset_tag'] ?? 'UNKNOWN';
                $employee_id    = !empty($_POST['employee_id']) ? $_POST['employee_id'] : null;
                $department_id  = !empty($_POST['department_id']) ? $_POST['department_id'] : null;

                if (!$employee_id && !$department_id) {
                    $errorMessage = "Please select either an employee or a department to assign this asset.";
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE assets 
                        SET assigned_employee_id = ?, assigned_department_id = ?, status = 'In Use' 
                        WHERE id = ?
                    ");
                    $stmt->execute([$employee_id, $department_id, $asset_id]);

                    if (function_exists('logAudit')) {
                        logAudit($pdo, $asset_tag, 'ASSIGN', "Assigned asset to employee ID: $employee_id / Dept ID: $department_id");
                    }
                    $updateMessage = "Asset assigned successfully!";
                }
            } catch (PDOException $e) {
                $errorMessage = "Error assigning asset: " . $e->getMessage();
            }
        }

        if ($_POST['action'] === 'delete_asset') {
            if ($userRole !== 'admin') {
                $errorMessage = "Only administrators can delete assets.";
            } else {
                try {
                    $asset_id  = $_POST['asset_id'];
                    $asset_tag = $_POST['asset_tag'] ?? 'UNKNOWN';

                    $stmt = $pdo->prepare("DELETE FROM assets WHERE id = ?");
                    $stmt->execute([$asset_id]);

                    if (function_exists('logAudit')) {
                        logAudit($pdo, $asset_tag, 'DELETE', "Permanently deleted asset tag: $asset_tag");
                    }
                    $updateMessage = "Asset deleted successfully!";
                } catch (PDOException $e) {
                    $errorMessage = "Cannot delete asset: (" . $e->getMessage() . ")";
                }
            }
        }
    }
}

// Read Filter Parameters
$selectedCat    = $_GET['category_id'] ?? '';
$selectedStatus = $_GET['status'] ?? '';
$searchQuery    = trim($_GET['search'] ?? '');

// Fetch helper collections
$categories  = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

try {
    $employees   = $pdo->query("SELECT id, full_name, employee_id FROM employees ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $employees = [];
}

$departments = $pdo->query("SELECT id, name FROM departments ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Category asset count summary breakdown
$categoryCountsRaw = $pdo->query("
    SELECT c.name, COUNT(a.id) AS total_count 
    FROM categories c 
    LEFT JOIN assets a ON a.category_id = c.id 
    GROUP BY c.id, c.name 
    ORDER BY total_count DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Dynamic SQL Query based on filters
$whereClauses = [];
$queryParams = [];

if (!empty($selectedCat)) {
    $whereClauses[] = "a.category_id = ?";
    $queryParams[] = $selectedCat;
}
if (!empty($selectedStatus)) {
    $whereClauses[] = "a.status = ?";
    $queryParams[] = $selectedStatus;
}
if (!empty($searchQuery)) {
    $whereClauses[] = "(a.asset_tag LIKE ? OR a.brand LIKE ? OR a.model LIKE ? OR a.serial_number LIKE ?)";
    $term = "%$searchQuery%";
    $queryParams[] = $term; $queryParams[] = $term; $queryParams[] = $term; $queryParams[] = $term;
}

$whereSql = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

// Fetch Assets Query
$sql = "SELECT a.*, 
               c.name AS category_name, 
               d.name AS department_name,
               e.full_name AS employee_name
        FROM assets a
        LEFT JOIN categories c ON a.category_id = c.id
        LEFT JOIN departments d ON a.assigned_department_id = d.id
        LEFT JOIN employees e ON a.assigned_employee_id = e.id
        $whereSql
        ORDER BY a.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($queryParams);
$assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalFilteredCount = count($assets);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ITAM - View & Manage Assets</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            nav, footer, .no-print { display: none !important; }
            body { background: white !important; color: black !important; }
            .print-full { width: 100% !important; max-width: none !important; margin: 0 !important; padding: 0 !important; }
            .shadow-sm, .rounded-xl { box-shadow: none !important; border-radius: 0 !important; }
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-800 min-h-screen flex flex-col">
    <?php renderNav(); ?>

    <main class="max-w-7xl mx-auto px-4 py-6 w-full flex-grow print-full">
        
        <!-- Alerts -->
        <?php if (!empty($updateMessage)): ?>
            <div class="mb-4 p-4 rounded-lg bg-emerald-50 text-emerald-800 border border-emerald-200 text-sm font-semibold flex justify-between items-center no-print">
                <span><?= htmlspecialchars($updateMessage) ?></span>
                <button onclick="this.parentElement.remove()" class="text-emerald-600 hover:text-emerald-900">&times;</button>
            </div>
        <?php endif; ?>
        <?php if (!empty($errorMessage)): ?>
            <div class="mb-4 p-4 rounded-lg bg-rose-50 text-rose-800 border border-rose-200 text-sm font-semibold flex justify-between items-center no-print">
                <span><?= htmlspecialchars($errorMessage) ?></span>
                <button onclick="this.parentElement.remove()" class="text-rose-600 hover:text-rose-900">&times;</button>
            </div>
        <?php endif; ?>

        <!-- Page Title & Actions -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-6">
            <div>
                <h1 class="text-2xl font-bold text-slate-900">IT Asset Inventory</h1>
                <p class="text-sm text-slate-500">View, search, filter categories, assigned users, and print hardware assets.</p>
            </div>
            
            <div class="flex flex-wrap items-center gap-2 no-print">
                <button onclick="window.print()" class="bg-slate-700 hover:bg-slate-800 text-white font-semibold px-4 py-2 rounded-lg shadow transition text-sm flex items-center gap-1">
                    🖨️ Print List
                </button>
                <a href="view_assets.php?action=export_csv" class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold px-4 py-2 rounded-lg shadow transition text-sm flex items-center gap-1">
                    📥 Export CSV
                </a>
                <?php if (in_array($userRole, ['admin', 'editor'])): ?>
                    <a href="add_asset.php" class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-4 py-2 rounded-lg shadow transition text-sm">
                        + Add Asset
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Asset Category Quick Counts Header -->
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 gap-3 mb-6 no-print">
            <div class="bg-white p-3 rounded-lg border border-slate-200 shadow-sm text-center">
                <span class="text-xs font-bold text-slate-400 uppercase block">Filtered Assets</span>
                <span class="text-xl font-extrabold text-indigo-600"><?= $totalFilteredCount ?></span>
            </div>
            <?php foreach (array_slice($categoryCountsRaw, 0, 5) as $cc): ?>
                <div class="bg-white p-3 rounded-lg border border-slate-200 shadow-sm text-center">
                    <span class="text-xs font-bold text-slate-500 truncate block"><?= htmlspecialchars($cc['name']) ?></span>
                    <span class="text-xl font-extrabold text-slate-800"><?= $cc['total_count'] ?></span>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Filter & Search Toolbar -->
        <form method="GET" action="view_assets.php" class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm mb-6 flex flex-wrap gap-3 items-center no-print">
            <div class="flex-1 min-w-[200px]">
                <input type="text" name="search" value="<?= htmlspecialchars($searchQuery) ?>" placeholder="Search Tag, Model, Brand, or Serial..." class="w-full border p-2 rounded text-sm">
            </div>
            
            <div>
                <select name="category_id" class="border p-2 rounded text-sm bg-white min-w-[150px]" onchange="this.form.submit()">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= $selectedCat == $cat['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <select name="status" class="border p-2 rounded text-sm bg-white min-w-[130px]" onchange="this.form.submit()">
                    <option value="">All Statuses</option>
                    <option value="In Stock" <?= $selectedStatus === 'In Stock' ? 'selected' : '' ?>>In Stock</option>
                    <option value="In Use" <?= $selectedStatus === 'In Use' ? 'selected' : '' ?>>In Use</option>
                    <option value="Maintenance" <?= $selectedStatus === 'Maintenance' ? 'selected' : '' ?>>Maintenance</option>
                    <option value="Retired" <?= $selectedStatus === 'Retired' ? 'selected' : '' ?>>Retired</option>
                </select>
            </div>

            <button type="submit" class="bg-slate-800 text-white px-4 py-2 rounded text-sm font-semibold">Filter</button>
            <?php if (!empty($selectedCat) || !empty($selectedStatus) || !empty($searchQuery)): ?>
                <a href="view_assets.php" class="text-xs text-rose-600 font-bold hover:underline px-2">Clear Filters</a>
            <?php endif; ?>
        </form>

        <!-- Assets Table -->
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-sm">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-200 text-xs font-semibold text-slate-500 uppercase tracking-wider">
                            <th class="py-3 px-4">Asset Tag</th>
                            <th class="py-3 px-4">Category</th>
                            <th class="py-3 px-4">Device Details</th>
                            <th class="py-3 px-4">Assigned User / Dept</th>
                            <th class="py-3 px-4">Condition</th>
                            <th class="py-3 px-4">Status</th>
                            <?php if (in_array($userRole, ['admin', 'editor'])): ?>
                                <th class="py-3 px-4 text-right no-print">Actions</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (empty($assets)): ?>
                            <tr>
                                <td colspan="7" class="py-6 text-center text-slate-400">
                                    No assets found matching the selected criteria.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($assets as $a): ?>
                                <?php 
                                    // Extract custom category type if present
                                    $specsObj = !empty($a['specs']) ? json_decode($a['specs'], true) : [];
                                    $customType = !empty($a['other_category_type']) ? $a['other_category_type'] : ($specsObj['custom_type'] ?? '');
                                ?>
                                <tr class="hover:bg-slate-50/50 transition">
                                    <td class="py-3 px-4 font-mono font-bold text-indigo-600">
                                        <?= htmlspecialchars($a['asset_tag']) ?>
                                    </td>
                                    <td class="py-3 px-4 font-medium text-slate-700">
                                        <div>
                                            <?= htmlspecialchars($a['category_name'] ?? 'Uncategorized') ?>
                                        </div>
                                        <?php if (!empty($customType)): ?>
                                            <div class="text-xs font-semibold text-amber-800 bg-amber-50 border border-amber-200 px-1.5 py-0.5 rounded inline-block mt-1">
                                                📌 Other - <?= htmlspecialchars($customType) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4">
                                        <div class="font-semibold text-slate-800"><?= htmlspecialchars($a['brand'] . ' ' . $a['model']) ?></div>
                                        <div class="text-xs text-slate-400 font-mono">S/N: <?= htmlspecialchars($a['serial_number'] ?? 'N/A') ?></div>
                                    </td>
                                    <td class="py-3 px-4">
                                        <?php if (!empty($a['employee_name'])): ?>
                                            <div class="font-bold text-slate-800">👤 <?= htmlspecialchars($a['employee_name']) ?></div>
                                            <div class="text-xs text-slate-500"><?= htmlspecialchars($a['department_name'] ?? 'No Dept') ?></div>
                                        <?php elseif (!empty($a['department_name'])): ?>
                                            <div class="font-semibold text-slate-700">🏢 <?= htmlspecialchars($a['department_name']) ?></div>
                                            <div class="text-xs text-slate-400">Department Assigned</div>
                                        <?php else: ?>
                                            <span class="text-xs text-slate-400 italic">Unassigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium border bg-slate-50 text-slate-700">
                                            <?= htmlspecialchars($a['condition_status'] ?? 'New') ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-4">
                                        <?php
                                        $statusBadge = match($a['status']) {
                                            'In Use'      => 'bg-indigo-100 text-indigo-800',
                                            'In Stock'    => 'bg-emerald-100 text-emerald-800',
                                            'Maintenance' => 'bg-amber-100 text-amber-800',
                                            'Retired'     => 'bg-slate-100 text-slate-600',
                                            default       => 'bg-slate-100 text-slate-800'
                                        };
                                        ?>
                                        <span class="px-2.5 py-1 text-xs font-semibold rounded-full <?= $statusBadge ?>">
                                            <?= htmlspecialchars($a['status']) ?>
                                        </span>
                                    </td>

                                    <?php if (in_array($userRole, ['admin', 'editor'])): ?>
                                        <td class="py-3 px-4 text-right space-x-1 no-print">
                                            <button onclick='openEditModal(<?= htmlspecialchars(json_encode($a), ENT_QUOTES, "UTF-8") ?>)' 
                                                    class="text-xs bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-300 px-2 py-1 rounded font-medium">✏️ Edit</button>

                                            <!-- Assign button is always visible -->
                                            <button onclick="openAssignModal(<?= $a['id'] ?>, '<?= htmlspecialchars($a['asset_tag'], ENT_QUOTES) ?>', '<?= htmlspecialchars($a['brand'] . ' ' . $a['model'], ENT_QUOTES) ?>')" 
                                                    class="text-xs bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-200 px-2 py-1 rounded font-medium">👤 Assign</button>

                                            <?php if ($userRole === 'admin'): ?>
                                                <button onclick="openDeleteModal(<?= $a['id'] ?>, '<?= htmlspecialchars($a['asset_tag'], ENT_QUOTES) ?>', '<?= htmlspecialchars($a['brand'] . ' ' . $a['model'], ENT_QUOTES) ?>')" 
                                                        class="text-xs bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 px-2 py-1 rounded font-medium">🗑️</button>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Modal 1: Edit Asset Modal -->
    <div id="editModal" class="fixed inset-0 z-50 hidden bg-slate-900/40 backdrop-blur-sm flex items-center justify-center p-4 no-print">
        <div class="bg-white rounded-xl shadow-xl max-w-2xl w-full p-6 border border-slate-100 space-y-4 max-h-[90vh] overflow-y-auto">
            <div class="flex justify-between items-center border-b pb-3">
                <h3 class="text-lg font-bold text-slate-800">Edit Full Asset Details</h3>
                <button onclick="closeEditModal()" class="text-slate-400 hover:text-slate-600 font-bold text-xl">&times;</button>
            </div>

            <form action="view_assets.php" method="POST" class="space-y-4">
                <input type="hidden" name="action" value="edit_asset">
                <input type="hidden" name="asset_id" id="edit_asset_id">

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold uppercase mb-1 text-slate-700">Asset Tag *</label>
                        <input type="text" name="asset_tag" id="edit_asset_tag" required class="w-full border p-2 rounded text-sm font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase mb-1 text-slate-700">Category</label>
                        <select name="category_id" id="edit_category_id" class="w-full border p-2 rounded text-sm bg-white">
                            <option value="">Uncategorized</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-bold uppercase mb-1 text-slate-700">Brand *</label>
                        <input type="text" name="brand" id="edit_brand" required class="w-full border p-2 rounded text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase mb-1 text-slate-700">Model *</label>
                        <input type="text" name="model" id="edit_model" required class="w-full border p-2 rounded text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase mb-1 text-slate-700">Serial Number</label>
                        <input type="text" name="serial_number" id="edit_serial_number" class="w-full border p-2 rounded text-sm font-mono">
                    </div>
                </div>

                <div class="border-t pt-3">
                    <label class="block text-xs font-bold uppercase mb-2 text-slate-500">Hardware Specifications</label>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs text-slate-600 mb-1">CPU</label>
                            <input type="text" name="cpu" id="edit_cpu" placeholder="e.g., Intel i7 / M1" class="w-full border p-2 rounded text-sm">
                        </div>
                        <div>
                            <label class="block text-xs text-slate-600 mb-1">RAM</label>
                            <input type="text" name="ram" id="edit_ram" placeholder="e.g., 16GB" class="w-full border p-2 rounded text-sm">
                        </div>
                        <div>
                            <label class="block text-xs text-slate-600 mb-1">Storage</label>
                            <input type="text" name="storage" id="edit_storage" placeholder="e.g., 512GB SSD" class="w-full border p-2 rounded text-sm">
                        </div>
                        <div>
                            <label class="block text-xs text-slate-600 mb-1">OS</label>
                            <input type="text" name="os" id="edit_os" placeholder="e.g., Windows 11 Pro" class="w-full border p-2 rounded text-sm">
                        </div>
                    </div>
                </div>

                <div class="border-t pt-3 grid grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-bold uppercase mb-1 text-slate-700">Purchase Date</label>
                        <input type="date" name="purchase_date" id="edit_purchase_date" class="w-full border p-2 rounded text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase mb-1 text-slate-700">Purchase Price ($)</label>
                        <input type="number" step="0.01" name="purchase_price" id="edit_purchase_price" class="w-full border p-2 rounded text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase mb-1 text-slate-700">Warranty Expiry</label>
                        <input type="date" name="warranty_expiry" id="edit_warranty_expiry" class="w-full border p-2 rounded text-sm">
                    </div>
                </div>

                <div class="border-t pt-3 grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold uppercase mb-1 text-slate-700">Status *</label>
                        <select name="status" id="edit_status" required class="w-full border p-2 rounded text-sm bg-white">
                            <option value="In Stock">In Stock</option>
                            <option value="In Use">In Use</option>
                            <option value="Maintenance">Maintenance</option>
                            <option value="Retired">Retired</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase mb-1 text-slate-700">Condition *</label>
                        <select name="condition_status" id="edit_condition_status" required class="w-full border p-2 rounded text-sm bg-white">
                            <option value="New">New</option>
                            <option value="Excellent">Excellent</option>
                            <option value="Good">Good</option>
                            <option value="Fair">Fair</option>
                            <option value="Poor">Poor</option>
                            <option value="Damaged">Damaged</option>
                        </select>
                    </div>
                </div>

                <div class="border-t pt-3 grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold uppercase mb-1 text-slate-700">Assigned Department</label>
                        <select name="assigned_department_id" id="edit_assigned_department_id" class="w-full border p-2 rounded text-sm bg-white">
                            <option value="">Unassigned</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?= $dept['id'] ?>"><?= htmlspecialchars($dept['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase mb-1 text-slate-700">Assigned Employee</label>
                        <select name="assigned_employee_id" id="edit_assigned_employee_id" class="w-full border p-2 rounded text-sm bg-white">
                            <option value="">Unassigned</option>
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="flex justify-end gap-2 pt-3 border-t">
                    <button type="button" onclick="closeEditModal()" class="px-4 py-2 border rounded text-xs font-semibold text-slate-600 hover:bg-slate-50">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded text-xs font-semibold shadow">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal 2: Assign Asset Modal -->
    <div id="assignModal" class="fixed inset-0 z-50 hidden bg-slate-900/40 backdrop-blur-sm flex items-center justify-center p-4 no-print">
        <div class="bg-white rounded-xl shadow-xl max-w-md w-full p-6 border border-slate-100 space-y-4">
            <div class="flex justify-between items-center border-b pb-3">
                <h3 class="text-lg font-bold text-slate-800">Assign Asset</h3>
                <button onclick="closeAssignModal()" class="text-slate-400 hover:text-slate-600 font-bold text-xl">&times;</button>
            </div>

            <form action="view_assets.php" method="POST" class="space-y-4">
                <input type="hidden" name="action" value="assign_asset">
                <input type="hidden" name="asset_id" id="assign_asset_id">
                <input type="hidden" name="asset_tag" id="assign_asset_tag_input">

                <div>
                    <p class="text-xs text-slate-500 uppercase font-bold">Target Device</p>
                    <p id="assign_asset_info" class="text-sm font-semibold text-slate-800 mt-0.5"></p>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase mb-1 text-slate-700">Assign To Employee</label>
                    <select name="employee_id" class="w-full border p-2 rounded text-sm bg-white">
                        <option value="">-- Select Employee --</option>
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase mb-1 text-slate-700">Or Assign To Department</label>
                    <select name="department_id" class="w-full border p-2 rounded text-sm bg-white">
                        <option value="">-- Select Department --</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= $dept['id'] ?>"><?= htmlspecialchars($dept['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="flex justify-end gap-2 pt-2 border-t">
                    <button type="button" onclick="closeAssignModal()" class="px-4 py-2 border rounded text-xs font-semibold text-slate-600 hover:bg-slate-50">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded text-xs font-semibold shadow">Confirm Assignment</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal 3: Delete Confirmation Modal -->
    <div id="deleteModal" class="fixed inset-0 z-50 hidden bg-slate-900/40 backdrop-blur-sm flex items-center justify-center p-4 no-print">
        <div class="bg-white rounded-xl shadow-xl max-w-sm w-full p-6 border border-slate-100 space-y-4">
            <div class="flex items-center space-x-3 text-rose-600 border-b pb-3">
                <span class="text-2xl">⚠️</span>
                <h3 class="text-lg font-bold text-slate-800">Confirm Deletion</h3>
            </div>

            <p class="text-sm text-slate-600">Are you sure you want to permanently delete this asset?</p>

            <div class="bg-slate-50 p-3 rounded border text-xs text-slate-700">
                <p><strong>Asset Tag:</strong> <span id="delete_asset_tag"></span></p>
                <p><strong>Device:</strong> <span id="delete_asset_info"></span></p>
            </div>

            <form action="view_assets.php" method="POST" class="flex justify-end gap-2 pt-2 border-t">
                <input type="hidden" name="action" value="delete_asset">
                <input type="hidden" name="asset_id" id="delete_asset_id">
                <input type="hidden" name="asset_tag" id="delete_asset_tag_input">

                <button type="button" onclick="closeDeleteModal()" class="px-4 py-2 border rounded text-xs font-semibold text-slate-600 hover:bg-slate-50">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-rose-600 hover:bg-rose-700 text-white rounded text-xs font-semibold shadow">Yes, Delete</button>
            </form>
        </div>
    </div>

    <script>
        function openEditModal(asset) {
            document.getElementById('edit_asset_id').value = asset.id || '';
            document.getElementById('edit_asset_tag').value = asset.asset_tag || '';
            document.getElementById('edit_brand').value = asset.brand || '';
            document.getElementById('edit_model').value = asset.model || '';
            document.getElementById('edit_serial_number').value = asset.serial_number || '';
            document.getElementById('edit_category_id').value = asset.category_id || '';
            document.getElementById('edit_purchase_date').value = asset.purchase_date || '';
            document.getElementById('edit_purchase_price').value = asset.purchase_price || '';
            document.getElementById('edit_warranty_expiry').value = asset.warranty_expiry || '';
            document.getElementById('edit_status').value = asset.status || 'In Stock';
            document.getElementById('edit_condition_status').value = asset.condition_status || 'Good';
            document.getElementById('edit_assigned_department_id').value = asset.assigned_department_id || '';
            document.getElementById('edit_assigned_employee_id').value = asset.assigned_employee_id || '';

            let specs = {};
            try {
                specs = typeof asset.specs === 'string' ? JSON.parse(asset.specs) : (asset.specs || {});
            } catch (e) {
                specs = {};
            }

            document.getElementById('edit_cpu').value = specs.cpu || '';
            document.getElementById('edit_ram').value = specs.ram || '';
            document.getElementById('edit_storage').value = specs.storage || '';
            document.getElementById('edit_os').value = specs.os || '';

            document.getElementById('editModal').classList.remove('hidden');
        }

        function closeEditModal() { document.getElementById('editModal').classList.add('hidden'); }

        function openAssignModal(id, tag, info) {
            document.getElementById('assign_asset_id').value = id;
            document.getElementById('assign_asset_tag_input').value = tag;
            document.getElementById('assign_asset_info').innerText = tag + ' - ' + info;
            document.getElementById('assignModal').classList.remove('hidden');
        }

        function closeAssignModal() { document.getElementById('assignModal').classList.add('hidden'); }

        function openDeleteModal(id, tag, info) {
            document.getElementById('delete_asset_id').value = id;
            document.getElementById('delete_asset_tag_input').value = tag;
            document.getElementById('delete_asset_tag').innerText = tag;
            document.getElementById('delete_asset_info').innerText = info;
            document.getElementById('deleteModal').classList.remove('hidden');
        }

        function closeDeleteModal() { document.getElementById('deleteModal').classList.add('hidden'); }
    </script>
    
    <?php renderFooter(); ?>
</body>
</html>