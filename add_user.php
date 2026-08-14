<?php
require_once 'db.php';
require_once 'auth_helpers.php';

// Only administrators should be able to create new system users
checkAccess(['admin']);

// Get current user role
$userRole = $_SESSION['user_role'] ?? 'viewer';

$successMessage = '';
$errorMessage = '';

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_user') {
    $fullName     = trim($_POST['full_name'] ?? '');
    $email        = trim($_POST['email'] ?? '');
    $rawPassword  = $_POST['password'] ?? '';
    $role         = $_POST['role'] ?? 'viewer';
    $departmentId = !empty($_POST['department_id']) ? intval($_POST['department_id']) : null;

    // Basic Validation
    if (empty($fullName) || empty($email) || empty($rawPassword) || empty($role)) {
        $errorMessage = "Please fill in all required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errorMessage = "Invalid email format.";
    } elseif (strlen($rawPassword) < 8) {
        $errorMessage = "Password must be at least 8 characters long.";
    } else {
        try {
            // Check if email already exists
            $checkEmail = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $checkEmail->execute([$email]);

            if ($checkEmail->fetch()) {
                $errorMessage = "A user with this email address already exists.";
            } else {
                // Securely hash the password
                $hashedPassword = password_hash($rawPassword, PASSWORD_BCRYPT);

                // Insert into Database
                $stmt = $pdo->prepare("
                    INSERT INTO users (full_name, email, password, role, department_id) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$fullName, $email, $hashedPassword, $role, $departmentId]);

                $newUserId = $pdo->lastInsertId();

                // Audit Log Activity (uses 'CREATE' or 'UPDATE' based on allowed ENUM values)
                if (function_exists('logAudit')) {
                    logAudit($pdo, $email, 'CREATE', "Created system user: $fullName ($role)");
                }

                $successMessage = "User '$fullName' registered successfully!";
            }
        } catch (PDOException $e) {
            $errorMessage = "Database error: " . $e->getMessage();
        }
    }
}

// Fetch departments for dropdown
try {
    $departments = $pdo->query("SELECT id, name FROM departments ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $departments = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ITAM - Add System User</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 text-slate-800">
    <?php if (function_exists('renderNav')) renderNav(); ?>

    <main class="max-w-2xl mx-auto px-4 py-10">
        <!-- Breadcrumb / Header -->
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold text-slate-900">Add System User</h1>
                <p class="text-sm text-slate-500">Create login credentials and set system roles for team members.</p>
            </div>
            <a href="view_users.php" class="text-xs bg-slate-200 hover:bg-slate-300 text-slate-700 font-semibold px-3 py-2 rounded-lg transition">
                ← Back to Users
            </a>
        </div>

        <!-- Alerts -->
        <?php if (!empty($successMessage)): ?>
            <div class="mb-4 p-4 rounded-lg bg-emerald-50 text-emerald-800 border border-emerald-200 text-sm font-semibold flex justify-between items-center">
                <span>✅ <?= htmlspecialchars($successMessage) ?></span>
                <button onclick="this.parentElement.remove()" class="text-emerald-600 hover:text-emerald-900">&times;</button>
            </div>
        <?php endif; ?>

        <?php if (!empty($errorMessage)): ?>
            <div class="mb-4 p-4 rounded-lg bg-rose-50 text-rose-800 border border-rose-200 text-sm font-semibold flex justify-between items-center">
                <span>⚠️ <?= htmlspecialchars($errorMessage) ?></span>
                <button onclick="this.parentElement.remove()" class="text-rose-600 hover:text-rose-900">&times;</button>
            </div>
        <?php endif; ?>

        <!-- Form Card -->
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-6">
            <form action="add_user.php" method="POST" class="space-y-4">
                <input type="hidden" name="action" value="add_user">

                <div>
                    <label class="block text-xs font-bold uppercase mb-1 text-slate-700">Full Name *</label>
                    <input type="text" name="full_name" required placeholder="e.g. John Doe"
                           class="w-full border border-slate-300 p-2.5 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase mb-1 text-slate-700">Email Address *</label>
                    <input type="email" name="email" required placeholder="john.doe@organization.com"
                           class="w-full border border-slate-300 p-2.5 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase mb-1 text-slate-700">Password *</label>
                    <input type="password" name="password" required minlength="8" placeholder="••••••••"
                           class="w-full border border-slate-300 p-2.5 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                    <p class="text-[11px] text-slate-400 mt-1">Minimum 8 characters long.</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold uppercase mb-1 text-slate-700">System Role *</label>
                        <select name="role" required class="w-full border border-slate-300 p-2.5 rounded-lg text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            <option value="viewer" selected>Viewer (Read-only)</option>
                            <option value="editor">Editor (Can edit assets)</option>
                            <option value="admin">Admin (Full administrative access)</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-bold uppercase mb-1 text-slate-700">Department</label>
                        <select name="department_id" class="w-full border border-slate-300 p-2.5 rounded-lg text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            <option value="">-- Unassigned / None --</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?= $dept['id'] ?>"><?= htmlspecialchars($dept['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="pt-4 border-t flex justify-end gap-3">
                    <a href="view_assets.php" class="px-4 py-2 border rounded-lg text-xs font-semibold text-slate-600 hover:bg-slate-50 transition">
                        Cancel
                    </a>
                    <button type="submit" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-xs font-semibold shadow transition">
                        Create User
                    </button>
                </div>
            </form>
        </div>
    </main>
</body>
</html>