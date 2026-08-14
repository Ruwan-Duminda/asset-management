<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function checkAccess($allowed_roles = []) {
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit();
    }
    if (!empty($allowed_roles) && !in_array($_SESSION['user_role'], $allowed_roles)) {
        http_response_code(403);
        die("<div style='font-family:sans-serif; text-align:center; margin-top:50px;'>
            <h2>403 Access Denied</h2>
            <p>Your role (<strong>" . htmlspecialchars($_SESSION['user_role']) . "</strong>) lacks permission for this action.</p>
            <a href='dashboard.php'>Return to Dashboard</a>
        </div>");
    }
}

function logAudit($pdo, $asset_tag, $action, $details) {
    $user_id = $_SESSION['user_id'] ?? null;
    $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, asset_tag, details) VALUES (?, ?, ?, ?)");
    $stmt->execute([$user_id, $action, $asset_tag, $details]);
}

function renderNav() {
    $role = $_SESSION['user_role'] ?? 'viewer';
    $name = $_SESSION['user_name'] ?? 'User';
    echo '
    <nav class="bg-slate-900 text-white shadow-md mb-6">
        <div class="max-w-7xl mx-auto px-4 flex justify-between items-center h-16">
            <div class="flex items-center space-x-6">
                <a href="dashboard.php" class="font-bold text-xl flex items-center space-x-2">
                    <span>💻</span> <span>ITAM System</span>
                </a>
                <div class="hidden md:flex space-x-4 text-sm font-medium">
                    <a href="dashboard.php" class="hover:text-indigo-400">Dashboard</a>
                    <a href="view_assets.php" class="hover:text-indigo-400">All Assets</a>
                    <a href="employees.php" class="hover:text-indigo-400">Employees</a>';
                    if ($role === 'admin') {
                        echo '<a href="audit_logs.php" class="hover:text-indigo-400">Audit Logs</a>';
                    }
    echo '      </div>
            </div>
            <div class="flex items-center space-x-4 text-sm">
                <span>' . htmlspecialchars($name) . ' (<strong class="uppercase text-indigo-400">' . htmlspecialchars($role) . '</strong>)</span>
                <a href="logout.php" class="bg-red-600 hover:bg-red-700 px-3 py-1.5 rounded transition font-medium">Logout</a>
            </div>
        </div>
    </nav>';
}
?>