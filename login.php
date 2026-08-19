<?php
require_once 'db.php';
session_start();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // -------------------------------------------------------------------------
    // ACTION: HANDLE LOGIN
    // -------------------------------------------------------------------------
    if (isset($_POST['action']) && $_POST['action'] === 'login') {
        $email    = trim($_POST['email']);
        $password = trim($_POST['password']);

        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['user_name'] = $user['full_name'];
            $_SESSION['user_role'] = $user['role'];
            header('Location: dashboard.php');
            exit();
        } else {
            $error = "Invalid email or password.";
        }
    }

    // -------------------------------------------------------------------------
    // ACTION: DIRECT PASSWORD RESET
    // -------------------------------------------------------------------------
    if (isset($_POST['action']) && $_POST['action'] === 'reset_password') {
        $reset_email     = trim($_POST['reset_email']);
        $new_password    = trim($_POST['new_password']);
        $confirm_pass    = trim($_POST['confirm_password']);

        if ($new_password !== $confirm_pass) {
            $error = "New passwords do not match.";
        } else {
            // Check if email exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$reset_email]);
            $user = $stmt->fetch();

            if ($user) {
                // Hash new password and update
                $hashedPassword = password_hash($new_password, PASSWORD_DEFAULT);
                $updateStmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $updateStmt->execute([$hashedPassword, $user['id']]);

                $success = "Password updated successfully! You can now log in.";
            } else {
                $error = "No user found with that email address.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>ITAM - Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 flex items-center justify-center h-screen">
    <div class="bg-white p-8 rounded-xl shadow-lg w-full max-w-md border border-slate-200 relative">
        <h2 class="text-2xl font-bold text-center text-slate-800 mb-2">IT Asset Management</h2>
        <p class="text-center text-slate-500 text-sm mb-6">Log in to your organization account</p>
        
        <!-- Flash Alerts -->
        <?php if ($error): ?>
            <div class="bg-red-100 border border-red-200 text-red-700 p-3 rounded-lg mb-4 text-sm text-center font-medium">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="bg-emerald-100 border border-emerald-200 text-emerald-800 p-3 rounded-lg mb-4 text-sm text-center font-medium">
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <!-- Login Form -->
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="login">
            
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1">Email Address</label>
                <input type="email" name="email" required class="w-full border rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
            </div>
            
            <div>
                <div class="flex justify-between items-center mb-1">
                    <label class="text-sm font-semibold text-slate-700">Password</label>
                    <button type="button" onclick="openResetModal()" class="text-xs text-indigo-600 hover:underline font-semibold">
                        Forgot Password?
                    </button>
                </div>
                <input type="password" name="password" required class="w-full border rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
            </div>

            <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2.5 rounded-lg shadow transition">
                Sign In
            </button>
        </form>
    </div>

    <!-- Password Reset Modal -->
    <div id="resetModal" class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-xl max-w-sm w-full p-6 border border-slate-200 space-y-4">
            <div class="flex justify-between items-center border-b pb-3">
                <h3 class="text-lg font-bold text-slate-800">Reset Password</h3>
                <button onclick="closeResetModal()" class="text-slate-400 hover:text-slate-600 font-bold text-xl">&times;</button>
            </div>

            <form method="POST" class="space-y-3">
                <input type="hidden" name="action" value="reset_password">

                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase mb-1">Your Registered Email</label>
                    <input type="email" name="reset_email" required class="w-full border rounded p-2 text-sm">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase mb-1">New Password</label>
                    <input type="password" name="new_password" required minlength="6" class="w-full border rounded p-2 text-sm">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase mb-1">Confirm New Password</label>
                    <input type="password" name="confirm_password" required minlength="6" class="w-full border rounded p-2 text-sm">
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" onclick="closeResetModal()" class="px-3 py-1.5 border rounded text-xs font-semibold text-slate-600 hover:bg-slate-50">
                        Cancel
                    </button>
                    <button type="submit" class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded text-xs font-semibold shadow">
                        Reset Password
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openResetModal() {
            document.getElementById('resetModal').classList.remove('hidden');
        }
        function closeResetModal() {
            document.getElementById('resetModal').classList.add('hidden');
        }
    </script>
</body>
</html>