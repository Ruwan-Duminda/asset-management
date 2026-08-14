<?php
require_once 'db.php';
require_once 'auth_helpers.php';

// Ensure user has access to view this page
checkAccess(['admin', 'editor', 'viewer']);

// Determine current user role (assuming function exists or set in session)
$userRole = $_SESSION['user_role'] ?? 'viewer';

// -----------------------------------------------------------------------------
// ACTION: EXPORT ASSETS TO CSV
// -----------------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    // Audit Log for the export activity
    if (function_exists('logAudit')) {
        logAudit($pdo, 'SYSTEM', 'READ', 'Exported assets database to CSV');
    }

    $filename = "ITAM_Assets_Export_" . date('Y-m-d_H-i-s') . ".csv";

    // Header options to force file download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    // Create a output stream write pointer
    $output = fopen('php://output', 'w');

    // Add UTF-8 BOM to fix special character encoding in Microsoft Excel
    fputs($output, "\xEF\xBB\xBF");

    // CSV Header row
    fputcsv($output, [
        'ID', 
        'Asset Tag', 
        'Brand', 
        'Model', 
        'Category', 
        'Status', 
        'Condition', 
        'Damage Type', 
        'Damage Notes', 
        'Assigned Employee', 
        'Assigned Department', 
        'Purchase Date', 
        'Created At'
    ]);

    // Fetch data for CSV
    $exportSql = "SELECT a.id, 
                         a.asset_tag, 
                         a.brand, 
                         a.model, 
                         c.name AS category_name, 
                         a.status, 
                         a.condition_status, 
                         a.damage_type, 
                         a.damage_notes, 
                         e.full_name AS employee_name, 
                         d.name AS department_name, 
                         a.purchase_date, 
                         a.created_at
                  FROM assets a
                  LEFT JOIN categories c ON a.category_id = c.id
                  LEFT JOIN departments d ON a.assigned_department_id = d.id
                  LEFT JOIN employees e ON a.assigned_employee_id = e.id
                  ORDER BY a.id DESC";

    $stmt = $pdo->query($exportSql);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['id'],
            $row['asset_tag'],
            $row['brand'],
            $row['model'],
            $row['category_name'] ?? 'Uncategorized',
            $row['status'],
            $row['condition_status'],
            $row['damage_type'] ?? 'None',
            $row['damage_notes'] ?? '',
            $row['employee_name'] ?? 'Unassigned',
            $row['department_name'] ?? 'N/A',
            $row['purchase_date'] ?? 'N/A',
            $row['created_at'] ?? ''
        ]);
    }

    fclose($output);
    exit(); // Terminate execution so HTML isn't output into the CSV file
}

$updateMessage = '';
$errorMessage = '';

// Handle Actions (Edit, Assign, Delete) via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // Restrict write actions to authorized roles only
    if (!in_array($userRole, ['admin', 'editor'])) {
        $errorMessage = "Unauthorized action. You do not have permission to modify assets.";
    } else {

        // ACTION: EDIT ASSET
        if ($_POST['action'] === 'edit_asset') {
            try {
                $asset_id         = $_POST['asset_id'];
                $asset_tag        = trim($_POST['asset_tag']);
                $brand            = trim($_POST['brand']);
                $model            = trim($_POST['model']);
                $category_id      = !empty($_POST['category_id']) ? $_POST['category_id'] : null;
                $purchase_date    = !empty($_POST['purchase_date']) ? $_POST['purchase_date'] : null;
                $status           = $_POST['status'];
                $condition_status = $_POST['condition_status'];
                $damage_type      = $_POST['damage_type'];
                $damage_notes     = trim($_POST['damage_notes']);

                $stmt = $pdo->prepare("
                    UPDATE assets 
                    SET asset_tag = ?, 
                        brand = ?, 
                        model = ?, 
                        category_id = ?, 
                        purchase_date = ?, 
                        status = ?, 
                        condition_status = ?, 
                        damage_type = ?, 
                        damage_notes = ? 
                    WHERE id = ?
                ");
                $stmt->execute([
                    $asset_tag, $brand, $model, $category_id, 
                    $purchase_date, $status, $condition_status, 
                    $damage_type, $damage_notes, $asset_id
                ]);

                if (function_exists('logAudit')) {
                    logAudit($pdo, $asset_tag, 'UPDATE', "Updated asset details ($brand $model)");
                }
                $updateMessage = "Asset updated successfully!";
            } catch (PDOException $e) {
                $errorMessage = "Error updating asset: " . $e->getMessage();
            }
        }

        // ACTION: ASSIGN ASSET
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
                        SET assigned_employee_id = ?, 
                            assigned_department_id = ?, 
                            status = 'In Use' 
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

        // ACTION: DELETE ASSET
        if ($_POST['action'] === 'delete_asset') {
            // Admin-only protection for hard deletion
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
                    $errorMessage = "Cannot delete asset: It may be referenced in audit logs or assignment histories. (" . $e->getMessage() . ")";
                }
            }
        }
    }
}

// Fetch helper collections for dropdowns
$categories  = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$employees   = $pdo->query("SELECT id, full_name, employee_id FROM employees ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$departments = $pdo->query("SELECT id, name FROM departments ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch all assets with joined details
$sql = "SELECT a.*, 
               c.name AS category_name, 
               d.name AS department_name, 
               e.full_name AS employee_name
        FROM assets a
        LEFT JOIN categories c ON a.category_id = c.id
        LEFT JOIN departments d ON a.assigned_department_id = d.id
        LEFT JOIN employees e ON a.assigned_employee_id = e.id
        ORDER BY a.id DESC";

$assets = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ITAM - View & Manage Assets</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 text-slate-800">
    <?php renderNav(); ?>

    <main class="max-w-7xl mx-auto px-4 py-8">
        <!-- Success Alert -->
        <?php if (!empty($updateMessage)): ?>
            <div class="mb-4 p-4 rounded-lg bg-emerald-50 text-emerald-800 border border-emerald-200 text-sm font-semibold flex justify-between items-center">
                <span><?= htmlspecialchars($updateMessage) ?></span>
                <button onclick="this.parentElement.remove()" class="text-emerald-600 hover:text-emerald-900">&times;</button>
            </div>
        <?php endif; ?>

        <!-- Error Alert -->
        <?php if (!empty($errorMessage)): ?>
            <div class="mb-4 p-4 rounded-lg bg-rose-50 text-rose-800 border border-rose-200 text-sm font-semibold flex justify-between items-center">
                <span><?= htmlspecialchars($errorMessage) ?></span>
                <button onclick="this.parentElement.remove()" class="text-rose-600 hover:text-rose-900">&times;</button>
            </div>
        <?php endif; ?>

        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-2xl font-bold text-slate-900">IT Hardware Assets</h1>
                <p class="text-sm text-slate-500">Track purchase dates, physical condition, damage details, and manage assignments.</p>
            </div>
            
            <div class="flex items-center space-x-3">
                <!-- Export CSV Button -->
                <a href="view_assets.php?action=export_csv" 
                   class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold px-4 py-2 rounded-lg shadow transition text-sm flex items-center gap-1.5"
                   title="Export all asset data to a CSV document">
                    📥 Export CSV
                </a>

                <?php if (in_array($userRole, ['admin', 'editor'])): ?>
                    <a href="add_asset.php" class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-4 py-2 rounded-lg shadow transition text-sm">
                        + Register New Asset
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Assets Table -->
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-sm">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-200 text-xs font-semibold text-slate-500 uppercase tracking-wider">
                            <th class="py-3 px-4">Asset Tag</th>
                            <th class="py-3 px-4">Device / Model</th>
                            <th class="py-3 px-4">Purchase Date</th>
                            <th class="py-3 px-4">Condition & Damage</th>
                            <th class="py-3 px-4">Assigned To</th>
                            <th class="py-3 px-4">Status</th>
                            <?php if (in_array($userRole, ['admin', 'editor'])): ?>
                                <th class="py-3 px-4 text-right">Actions</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (empty($assets)): ?>
                            <tr>
                                <td colspan="<?= in_array($userRole, ['admin', 'editor']) ? '7' : '6' ?>" class="py-6 text-center text-slate-400">
                                    No assets registered in the database yet.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($assets as $a): ?>
                                <tr class="hover:bg-slate-50/50 transition">
                                    <td class="py-3 px-4 font-mono font-bold text-indigo-600">
                                        <?= htmlspecialchars($a['asset_tag']) ?>
                                    </td>
                                    <td class="py-3 px-4">
                                        <div class="font-semibold text-slate-800"><?= htmlspecialchars($a['brand'] . ' ' . $a['model']) ?></div>
                                        <div class="text-xs text-slate-400"><?= htmlspecialchars($a['category_name'] ?? 'Uncategorized') ?></div>
                                    </td>
                                    <td class="py-3 px-4 text-slate-600">
                                        <?= !empty($a['purchase_date']) ? date('M d, Y', strtotime($a['purchase_date'])) : '<span class="text-slate-300">N/A</span>' ?>
                                    </td>
                                    <td class="py-3 px-4">
                                        <?php
                                        $condColor = match($a['condition_status']) {
                                            'New', 'Excellent' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                            'Good', 'Fair'      => 'bg-blue-50 text-blue-700 border-blue-200',
                                            'Poor', 'Damaged'   => 'bg-rose-50 text-rose-700 border-rose-200',
                                            default             => 'bg-slate-50 text-slate-700 border-slate-200'
                                        };
                                        ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium border <?= $condColor ?>">
                                            <?= htmlspecialchars($a['condition_status']) ?>
                                        </span>

                                        <?php if (!empty($a['damage_type']) && $a['damage_type'] !== 'None'): ?>
                                            <div class="mt-1">
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold bg-rose-100 text-rose-800 border border-rose-200">
                                                    ⚠️ <?= htmlspecialchars($a['damage_type']) ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4">
                                        <?php if ($a['employee_name']): ?>
                                            <div class="font-medium text-slate-700"><?= htmlspecialchars($a['employee_name']) ?></div>
                                            <div class="text-xs text-slate-400"><?= htmlspecialchars($a['department_name'] ?? 'No Dept') ?></div>
                                        <?php else: ?>
                                            <span class="text-xs text-slate-400 italic">Unassigned</span>
                                        <?php endif; ?>
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

                                    <!-- Render actions ONLY for Admin or Editor roles -->
                                    <?php if (in_array($userRole, ['admin', 'editor'])): ?>
                                        <td class="py-3 px-4 text-right space-x-1">
                                            <!-- Edit Button -->
                                            <button onclick='openEditModal(<?= htmlspecialchars(json_encode($a), ENT_QUOTES, "UTF-8") ?>)' 
                                                    class="text-xs bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-300 px-2 py-1 rounded font-medium transition"
                                                    title="Edit Asset">
                                                ✏️ Edit
                                            </button>

                                            <!-- Assign Button (Appears if unassigned) -->
                                            <?php if (!$a['assigned_employee_id']): ?>
                                                <button onclick="openAssignModal(<?= $a['id'] ?>, '<?= htmlspecialchars($a['asset_tag'], ENT_QUOTES) ?>', '<?= htmlspecialchars($a['brand'] . ' ' . $a['model'], ENT_QUOTES) ?>')" 
                                                        class="text-xs bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-200 px-2 py-1 rounded font-medium transition"
                                                        title="Assign Asset">
                                                    👤 Assign
                                                </button>
                                            <?php else: ?>
                                                <!-- Return Button (Appears if assigned) -->
                                                <button onclick="openReturnModal(<?= $a['id'] ?>, '<?= htmlspecialchars($a['asset_tag'], ENT_QUOTES) ?>', '<?= htmlspecialchars($a['brand'] . ' ' . $a['model'], ENT_QUOTES) ?>')" 
                                                        class="text-xs bg-amber-50 hover:bg-amber-100 text-amber-700 border border-amber-200 px-2 py-1 rounded font-medium transition"
                                                        title="Return Asset">
                                                    Return
                                                </button>
                                            <?php endif; ?>

                                            <!-- Delete Button (Only Admin) -->
                                            <?php if ($userRole === 'admin'): ?>
                                                <button onclick="openDeleteModal(<?= $a['id'] ?>, '<?= htmlspecialchars($a['asset_tag'], ENT_QUOTES) ?>', '<?= htmlspecialchars($a['brand'] . ' ' . $a['model'], ENT_QUOTES) ?>')" 
                                                        class="text-xs bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 px-2 py-1 rounded font-medium transition"
                                                        title="Delete Asset">
                                                    🗑️
                                                </button>
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
    <div id="editModal" class="fixed inset-0 z-50 hidden bg-slate-900/40 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-xl max-w-lg w-full p-6 border border-slate-100 space-y-4 max-h-[90vh] overflow-y-auto">
            <div class="flex justify-between items-center border-b pb-3">
                <h3 class="text-lg font-bold text-slate-800">Edit Asset Details</h3>
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

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold uppercase mb-1 text-slate-700">Brand *</label>
                        <input type="text" name="brand" id="edit_brand" required class="w-full border p-2 rounded text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase mb-1 text-slate-700">Model *</label>
                        <input type="text" name="model" id="edit_model" required class="w-full border p-2 rounded text-sm">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold uppercase mb-1 text-slate-700">Purchase Date</label>
                        <input type="date" name="purchase_date" id="edit_purchase_date" class="w-full border p-2 rounded text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase mb-1 text-slate-700">Status *</label>
                        <select name="status" id="edit_status" required class="w-full border p-2 rounded text-sm bg-white">
                            <option value="In Stock">In Stock</option>
                            <option value="In Use">In Use</option>
                            <option value="Maintenance">Maintenance</option>
                            <option value="Retired">Retired</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
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
                    <div>
                        <label class="block text-xs font-bold uppercase mb-1 text-slate-700">Damage Type</label>
                        <select name="damage_type" id="edit_damage_type" class="w-full border p-2 rounded text-sm bg-white">
                            <option value="None">None</option>
                            <option value="Screen Crack">Screen Crack</option>
                            <option value="Water Damage">Water Damage</option>
                            <option value="Battery Swell">Battery Swell</option>
                            <option value="Keyboard/Port Failure">Keyboard/Port Failure</option>
                            <option value="Cosmetic/Body Damage">Cosmetic/Body Damage</option>
                            <option value="Other Hardware Issue">Other Hardware Issue</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase mb-1 text-slate-700">Damage Notes / Remarks</label>
                    <textarea name="damage_notes" id="edit_damage_notes" rows="2" class="w-full border p-2 rounded text-sm"></textarea>
                </div>

                <div class="flex justify-end gap-2 pt-3 border-t">
                    <button type="button" onclick="closeEditModal()" class="px-4 py-2 border rounded text-xs font-semibold text-slate-600 hover:bg-slate-50">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded text-xs font-semibold shadow">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal 2: Assign Asset Modal -->
    <div id="assignModal" class="fixed inset-0 z-50 hidden bg-slate-900/40 backdrop-blur-sm flex items-center justify-center p-4">
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
                    <label class="block text-xs font-bold uppercase mb-1 text-slate-700">Assign To Employee *</label>
                    <select name="employee_id" class="w-full border p-2 rounded text-sm bg-white">
                        <option value="">-- Select Employee --</option>
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?= $emp['id'] ?>">
                                <?= htmlspecialchars($emp['full_name']) ?> (<?= htmlspecialchars($emp['employee_id']) ?>)
                            </option>
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

    <!-- Modal 3: Return Asset Modal -->
    <div id="returnModal" class="fixed inset-0 z-50 hidden bg-slate-900/40 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-xl max-w-md w-full p-6 border border-slate-100 space-y-4">
            <div class="flex justify-between items-center border-b pb-3">
                <h3 class="text-lg font-bold text-slate-800">Process Asset Return</h3>
                <button onclick="closeReturnModal()" class="text-slate-400 hover:text-slate-600 font-bold text-xl">&times;</button>
            </div>

            <form action="return_asset.php" method="POST" class="space-y-4">
                <input type="hidden" name="asset_id" id="modal_asset_id">

                <div>
                    <p class="text-xs text-slate-500 uppercase font-bold">Asset Tag / Details</p>
                    <p id="modal_asset_info" class="text-sm font-semibold text-slate-800 mt-0.5"></p>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase mb-1 text-slate-700">Return Condition *</label>
                    <select name="return_condition" id="return_condition_select" required class="w-full border p-2 rounded text-sm bg-white">
                        <option value="New">New (Unopened/Unused)</option>
                        <option value="Excellent">Excellent (Minor cosmetic wear)</option>
                        <option value="Good" selected>Good (Normal operational wear)</option>
                        <option value="Fair">Fair (Noticeable wear/scratches)</option>
                        <option value="Poor">Poor (Requires minor repair)</option>
                        <option value="Damaged">Damaged (Sends auto to Maintenance)</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase mb-1 text-slate-700">Type of Damage (If Applicable)</label>
                    <select name="damage_type" id="return_damage_type_select" class="w-full border p-2 rounded text-sm bg-white">
                        <option value="None">None / No Specific Damage</option>
                        <option value="Screen Crack">Screen Crack / Display Issue</option>
                        <option value="Water Damage">Water / Liquid Damage</option>
                        <option value="Battery Swell">Battery Swell / Degradation</option>
                        <option value="Keyboard/Port Failure">Keyboard, Trackpad, or Port Failure</option>
                        <option value="Cosmetic/Body Damage">Cosmetic / Body Dent or Crack</option>
                        <option value="Other Hardware Issue">Other Internal Hardware Failure</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase mb-1 text-slate-700">Damage Details & Inspection Notes</label>
                    <textarea name="damage_notes" rows="3" placeholder="Describe physical damage or return notes..." class="w-full border p-2 rounded text-sm"></textarea>
                </div>

                <div class="flex justify-end gap-2 pt-2 border-t">
                    <button type="button" onclick="closeReturnModal()" class="px-4 py-2 border rounded text-xs font-semibold text-slate-600 hover:bg-slate-50">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded text-xs font-semibold shadow">Confirm Check-In</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal 4: Delete Confirmation Modal -->
    <div id="deleteModal" class="fixed inset-0 z-50 hidden bg-slate-900/40 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-xl max-w-sm w-full p-6 border border-slate-100 space-y-4">
            <div class="flex items-center space-x-3 text-rose-600 border-b pb-3">
                <span class="text-2xl">⚠️</span>
                <h3 class="text-lg font-bold text-slate-800">Confirm Deletion</h3>
            </div>

            <p class="text-sm text-slate-600">Are you sure you want to permanently delete this asset? This action cannot be undone.</p>

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
        // Edit Modal Controls
        function openEditModal(asset) {
            document.getElementById('edit_asset_id').value = asset.id || '';
            document.getElementById('edit_asset_tag').value = asset.asset_tag || '';
            document.getElementById('edit_brand').value = asset.brand || '';
            document.getElementById('edit_model').value = asset.model || '';
            document.getElementById('edit_category_id').value = asset.category_id || '';
            document.getElementById('edit_purchase_date').value = asset.purchase_date || '';
            document.getElementById('edit_status').value = asset.status || 'In Stock';
            document.getElementById('edit_condition_status').value = asset.condition_status || 'Good';
            document.getElementById('edit_damage_type').value = asset.damage_type || 'None';
            document.getElementById('edit_damage_notes').value = asset.damage_notes || '';
            
            document.getElementById('editModal').classList.remove('hidden');
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.add('hidden');
        }

        // Assign Modal Controls
        function openAssignModal(id, tag, info) {
            document.getElementById('assign_asset_id').value = id;
            document.getElementById('assign_asset_tag_input').value = tag;
            document.getElementById('assign_asset_info').innerText = tag + ' - ' + info;
            document.getElementById('assignModal').classList.remove('hidden');
        }

        function closeAssignModal() {
            document.getElementById('assignModal').classList.add('hidden');
        }

        // Return Modal Controls
        function openReturnModal(id, tag, info) {
            document.getElementById('modal_asset_id').value = id;
            document.getElementById('modal_asset_info').innerText = tag + ' - ' + info;
            document.getElementById('return_condition_select').value = 'Good';
            document.getElementById('return_damage_type_select').value = 'None';
            document.getElementById('returnModal').classList.remove('hidden');
        }

        function closeReturnModal() {
            document.getElementById('returnModal').classList.add('hidden');
        }

        // Delete Modal Controls
        function openDeleteModal(id, tag, info) {
            document.getElementById('delete_asset_id').value = id;
            document.getElementById('delete_asset_tag_input').value = tag;
            document.getElementById('delete_asset_tag').innerText = tag;
            document.getElementById('delete_asset_info').innerText = info;
            document.getElementById('deleteModal').classList.remove('hidden');
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.add('hidden');
        }

        // Close modals on Escape key press
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeEditModal();
                closeAssignModal();
                closeReturnModal();
                closeDeleteModal();
            }
        });
    </script>
    <?php renderFooter(); ?>
</body>
</html>