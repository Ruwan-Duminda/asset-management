<?php
require_once 'db.php';
require_once 'auth_helpers.php';

checkAccess(['admin']);

$successMessage = '';
$errorMessage = '';

// Handle Delete Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_user') {
    $deleteId = intval($_POST['user_id'] ?? 0);
    $currentUserId = $_SESSION['user_id'] ?? 0;

    if ($deleteId === $currentUserId) {
        $errorMessage = "You cannot delete your own account while logged in.";
    } else {
        try {
            // Fetch target user details before deleting for audit log
            $stmtFetch = $pdo->prepare("SELECT email, full_name FROM users WHERE id = ?");
            $stmtFetch->execute([$deleteId]);
            $targetUser = $stmtFetch->fetch();

            if ($targetUser) {
                $stmtDelete = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmtDelete->execute([$deleteId]);

                if (function_exists('logAudit')) {
                    logAudit($pdo, $targetUser['email'], 'DELETE', "Deleted user: {$targetUser['full_name']}");
                }

                $successMessage = "User '{$targetUser['full_name']}' was successfully deleted.";
            } else {
                $errorMessage = "User not found.";
            }
        } catch (PDOException $e) {
            $errorMessage = "Database error: " . $e->getMessage();
        }
    }
}

// Handle Edit Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_user') {
    $editId       = intval($_POST['user_id'] ?? 0);
    $fullName     = trim($_POST['full_name'] ?? '');
    $email        = trim($_POST['email'] ?? '');
    $role         = $_POST['role'] ?? 'viewer';
    $departmentId = !empty($_POST['department_id']) ? intval($_POST['department_id']) : null;
    $rawPassword  = $_POST['password'] ?? '';

    if (empty($fullName) || empty($email) || empty($role)) {
        $errorMessage = "Please fill in all required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errorMessage = "Invalid email format.";
    } else {
        try {
            // Check for unique email on other users
            $checkEmail = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $checkEmail->execute([$email, $editId]);

            if ($checkEmail->fetch()) {
                $errorMessage = "This email address is already in use by another account.";
            } else {
                if (!empty($rawPassword)) {
                    if (strlen($rawPassword) < 8) {
                        $errorMessage = "Password must be at least 8 characters long.";
                    } else {
                        $hashedPassword = password_hash($rawPassword, PASSWORD_BCRYPT);
                        $stmtUpdate = $pdo->prepare("
                            UPDATE users 
                            SET full_name = ?, email = ?, password = ?, role = ?, department_id = ? 
                            WHERE id = ?
                        ");
                        $stmtUpdate->execute([$fullName, $email, $hashedPassword, $role, $departmentId, $editId]);
                    }
                } else {
                    $stmtUpdate = $pdo->prepare("
                        UPDATE users 
                        SET full_name = ?, email = ?, role = ?, department_id = ? 
                        WHERE id = ?
                    ");
                    $stmtUpdate->execute([$fullName, $email, $role, $departmentId, $editId]);
                }

                if (empty($errorMessage)) {
                    if (function_exists('logAudit')) {
                        logAudit($pdo, $email, 'UPDATE', "Updated user details for: $fullName");
                    }
                    $successMessage = "User '$fullName' updated successfully!";
                }
            }
        } catch (PDOException $e) {
            $errorMessage = "Database error: " . $e->getMessage();
        }
    }
}

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=system_users_' . date('Y-m-d') . '.csv');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Full Name', 'Email', 'Role', 'Department', 'Created At']);

    $exportQuery = $pdo->query("
        SELECT u.id, u.full_name, u.email, u.role, d.name AS department_name, u.created_at 
        FROM users u 
        LEFT JOIN departments d ON u.department_id = d.id 
        ORDER BY u.id ASC
    ");

    while ($row = $exportQuery->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['id'],
            $row['full_name'],
            $row['email'],
            strtoupper($row['role']),
            $row['department_name'] ?? 'Unassigned',
            $row['created_at'] ?? 'N/A'
        ]);
    }
    fclose($output);
    exit();
}

// Fetch all users with department details
try {
    $users = $pdo->query("
        SELECT u.*, d.name AS department_name 
        FROM users u 
        LEFT JOIN departments d ON u.department_id = d.id 
        ORDER BY u.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $departments = $pdo->query("SELECT id, name FROM departments ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $users = [];
    $departments = [];
    $errorMessage = "Failed to load users: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ITAM - Manage System Users</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; color: black !important; }
            .print-table { width: 100% !important; border: 1px solid #ccc !important; }
            .print-table th, .print-table td { border: 1px solid #ddd !important; padding: 8px !important; }
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-800">
    <div class="no-print">
        <?php if (function_exists('renderNav')) renderNav(); ?>
    </div>

    <main class="max-w-7xl mx-auto px-4 py-8 space-y-6">
        <!-- Header -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
            <div>
                <h1 class="text-2xl font-bold text-slate-900">System Users Management</h1>
                <p class="text-sm text-slate-500">View, update, export, and delete registered user accounts.</p>
            </div>
            <div class="flex items-center space-x-2 no-print">
                <a href="add_user.php" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg font-bold text-xs shadow flex items-center space-x-2 transition">
                    <span>➕</span> <span>Add New User</span>
                </a>
                <button onclick="window.print()" class="bg-slate-700 hover:bg-slate-800 text-white px-4 py-2 rounded-lg font-bold text-xs shadow flex items-center space-x-2 transition">
                    <span>🖨️</span> <span>Print</span>
                </button>
                <a href="view_users.php?export=csv" class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg font-bold text-xs shadow flex items-center space-x-2 transition">
                    <span>📥</span> <span>Export CSV</span>
                </a>
            </div>
        </div>

        <!-- Alerts -->
        <?php if (!empty($successMessage)): ?>
            <div class="p-4 rounded-lg bg-emerald-50 text-emerald-800 border border-emerald-200 text-sm font-semibold flex justify-between items-center no-print">
                <span>✅ <?= htmlspecialchars($successMessage) ?></span>
                <button onclick="this.parentElement.remove()" class="text-emerald-600 hover:text-emerald-900">&times;</button>
            </div>
        <?php endif; ?>

        <?php if (!empty($errorMessage)): ?>
            <div class="p-4 rounded-lg bg-rose-50 text-rose-800 border border-rose-200 text-sm font-semibold flex justify-between items-center no-print">
                <span>⚠️ <?= htmlspecialchars($errorMessage) ?></span>
                <button onclick="this.parentElement.remove()" class="text-rose-600 hover:text-rose-900">&times;</button>
            </div>
        <?php endif; ?>

        <!-- Table Container -->
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
            <table class="w-full text-left text-sm print-table">
                <thead class="bg-slate-50 border-b text-xs text-slate-500 uppercase">
                    <tr>
                        <th class="p-3">ID</th>
                        <th class="p-3">Full Name</th>
                        <th class="p-3">Email Address</th>
                        <th class="p-3">System Role</th>
                        <th class="p-3">Department</th>
                        <th class="p-3">Created At</th>
                        <th class="p-3 text-right no-print">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="7" class="p-6 text-center text-slate-400 italic">No users found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $u): ?>
                            <tr class="hover:bg-slate-50 transition">
                                <td class="p-3 text-xs font-mono text-slate-500">#<?= $u['id'] ?></td>
                                <td class="p-3 font-semibold text-slate-800"><?= htmlspecialchars($u['full_name']) ?></td>
                                <td class="p-3 text-slate-600"><?= htmlspecialchars($u['email']) ?></td>
                                <td class="p-3">
                                    <span class="px-2 py-0.5 text-xs font-bold rounded
                                        <?= $u['role'] === 'admin' ? 'bg-purple-100 text-purple-800' : '' ?>
                                        <?= $u['role'] === 'editor' ? 'bg-blue-100 text-blue-800' : '' ?>
                                        <?= $u['role'] === 'viewer' ? 'bg-slate-100 text-slate-700' : '' ?>">
                                        <?= strtoupper(htmlspecialchars($u['role'])) ?>
                                    </span>
                                </td>
                                <td class="p-3 text-slate-600"><?= htmlspecialchars($u['department_name'] ?? 'Unassigned') ?></td>
                                <td class="p-3 text-xs text-slate-500 font-mono"><?= $u['created_at'] ?? 'N/A' ?></td>
                                <td class="p-3 text-right space-x-2 no-print">
                                    <button onclick='openEditModal(<?= json_encode($u) ?>)' class="px-2.5 py-1 text-xs bg-amber-50 hover:bg-amber-100 text-amber-700 font-semibold rounded border border-amber-200 transition">
                                        ✏️ Edit
                                    </button>
                                    <form method="POST" class="inline-block" onsubmit="return confirm('Are you sure you want to delete user \'<?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>\'?');">
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="px-2.5 py-1 text-xs bg-rose-50 hover:bg-rose-100 text-rose-700 font-semibold rounded border border-rose-200 transition">
                                            🗑️ Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <!-- Modal for Editing User -->
    <div id="editModal" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm hidden items-center justify-center p-4 z-50 no-print">
        <div class="bg-white rounded-xl shadow-xl max-w-lg w-full p-6 space-y-4">
            <div class="flex justify-between items-center border-b pb-3">
                <h3 class="text-lg font-bold text-slate-800">Edit User Profile</h3>
                <button onclick="closeEditModal()" class="text-slate-400 hover:text-slate-600 text-xl font-bold">&times;</button>
            </div>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="user_id" id="edit_user_id">

                <div>
                    <label class="block text-xs font-bold uppercase mb-1 text-slate-700">Full Name *</label>
                    <input type="text" name="full_name" id="edit_full_name" required class="w-full border border-slate-300 p-2.5 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase mb-1 text-slate-700">Email Address *</label>
                    <input type="email" name="email" id="edit_email" required class="w-full border border-slate-300 p-2.5 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase mb-1 text-slate-700">New Password</label>
                    <input type="password" name="password" minlength="8" placeholder="Leave blank to keep unchanged" class="w-full border border-slate-300 p-2.5 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold uppercase mb-1 text-slate-700">System Role *</label>
                        <select name="role" id="edit_role" required class="w-full border border-slate-300 p-2.5 rounded-lg text-sm bg-white focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                            <option value="viewer">Viewer</option>
                            <option value="editor">Editor</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase mb-1 text-slate-700">Department</label>
                        <select name="department_id" id="edit_department_id" class="w-full border border-slate-300 p-2.5 rounded-lg text-sm bg-white focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                            <option value="">-- Unassigned --</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?= $dept['id'] ?>"><?= htmlspecialchars($dept['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="pt-4 border-t flex justify-end gap-2">
                    <button type="button" onclick="closeEditModal()" class="px-4 py-2 border rounded-lg text-xs font-semibold text-slate-600 hover:bg-slate-50 transition">Cancel</button>
                    <button type="submit" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-xs font-semibold shadow transition">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openEditModal(user) {
            document.getElementById('edit_user_id').value = user.id;
            document.getElementById('edit_full_name').value = user.full_name;
            document.getElementById('edit_email').value = user.email;
            document.getElementById('edit_role').value = user.role;
            document.getElementById('edit_department_id').value = user.department_id || '';
            
            const modal = document.getElementById('editModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closeEditModal() {
            const modal = document.getElementById('editModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }
    </script>

    <div class="no-print">
        <?php if (function_exists('renderFooter')) renderFooter(); ?>
    </div>
</body>
</html>