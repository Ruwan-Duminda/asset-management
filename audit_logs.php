<?php
require_once 'db.php';
require_once 'auth_helpers.php';
checkAccess(['admin']);

$currentUserId   = $_SESSION['user_id'] ?? null;
$currentUserName = $_SESSION['user_name'] ?? $_SESSION['full_name'] ?? 'Admin';

$message = '';
$errorMessage = '';

// Handle POST Clear Logs Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'clear_logs') {
    try {
        $pdo->beginTransaction();

        // 1. Delete/Truncate all records from audit_logs
        $pdo->exec("DELETE FROM audit_logs");

        // 2. Insert a single new record logging that the audit trail was cleared by the current user
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, asset_tag, details, created_at) 
            VALUES (?, 'DELETE', 'SYSTEM', ?, NOW())
        ");
        $logDetails = "Deleted all audit logs records by " . $currentUserName;
        $stmt->execute([$currentUserId, $logDetails]);

        $pdo->commit();

        header("Location: audit_logs.php?cleared=success");
        exit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errorMessage = "Error clearing logs: " . $e->getMessage();
    }
}

if (isset($_GET['cleared']) && $_GET['cleared'] === 'success') {
    $message = "All audit logs have been successfully cleared and recorded.";
}

$action = $_GET['action'] ?? '';
$from   = $_GET['from'] ?? '';
$to     = $_GET['to'] ?? '';

$query = "SELECT l.*, u.full_name, u.email 
          FROM audit_logs l 
          LEFT JOIN users u ON l.user_id = u.id 
          WHERE 1=1";

$params = [];

if ($action !== '') {
    $query .= " AND l.action = ?";
    $params[] = $action;
}
if ($from !== '') {
    $query .= " AND DATE(l.created_at) >= ?";
    $params[] = $from;
}
if ($to !== '') {
    $query .= " AND DATE(l.created_at) <= ?";
    $params[] = $to;
}

$query .= " ORDER BY l.created_at DESC LIMIT 250";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$exportUrl = "export_audit.php?" . http_build_query($_GET);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>ITAM - Audit Log</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: #fff !important; color: #000 !important; }
            main { max-width: 100% !important; padding: 0 !important; margin: 0 !important; }
            .print-table { width: 100% !important; border-collapse: collapse !important; }
            .print-table th, .print-table td { border: 1px solid #cbd5e1 !important; padding: 8px !important; }
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-800">
    <div class="no-print">
        <?php renderNav(); ?>
    </div>

    <main class="max-w-7xl mx-auto px-4 pb-12 space-y-6">
        
        <?php if (!empty($message)): ?>
            <div class="p-4 rounded-lg bg-emerald-50 text-emerald-800 border border-emerald-200 text-sm font-semibold flex justify-between items-center no-print">
                <span>✅ <?= htmlspecialchars($message) ?></span>
                <button onclick="this.parentElement.remove()" class="text-emerald-600 hover:text-emerald-900">&times;</button>
            </div>
        <?php endif; ?>

        <?php if (!empty($errorMessage)): ?>
            <div class="p-4 rounded-lg bg-rose-50 text-rose-800 border border-rose-200 text-sm font-semibold flex justify-between items-center no-print">
                <span>⚠️ <?= htmlspecialchars($errorMessage) ?></span>
                <button onclick="this.parentElement.remove()" class="text-rose-600 hover:text-rose-900">&times;</button>
            </div>
        <?php endif; ?>

        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
            <div>
                <h1 class="text-2xl font-bold">System Audit Logs (Admin Only)</h1>
                <p class="text-sm text-slate-500">Complete security trail of creation, modification, and deletion events.</p>
            </div>
            <div class="flex items-center space-x-2 no-print">
                <button onclick="window.print()" class="bg-slate-700 hover:bg-slate-800 text-white px-4 py-2 rounded-lg font-bold text-xs shadow flex items-center space-x-2 transition">
                    <span>🖨️</span> <span>Print Logs</span>
                </button>
                <a href="<?= htmlspecialchars($exportUrl) ?>" class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg font-bold text-xs shadow flex items-center space-x-2 transition">
                    <span>📥</span> <span>Export CSV</span>
                </a>
                <form method="POST" onsubmit="return confirm('⚠️ WARNING: Are you sure you want to delete ALL audit logs? This action cannot be undone.');">
                    <input type="hidden" name="action_type" value="clear_logs">
                    <button type="submit" class="bg-rose-600 hover:bg-rose-700 text-white px-4 py-2 rounded-lg font-bold text-xs shadow flex items-center space-x-2 transition">
                        <span>🗑️</span> <span>Clear All Logs</span>
                    </button>
                </form>
            </div>
        </div>

        <form method="GET" class="bg-white p-4 rounded-xl border border-slate-200 flex flex-wrap items-center gap-4 text-sm no-print">
            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Action Type</label>
                <select name="action" class="border p-2 rounded">
                    <option value="">All Actions</option>
                    <option value="CREATE" <?= $action === 'CREATE' ? 'selected' : '' ?>>CREATE</option>
                    <option value="UPDATE" <?= $action === 'UPDATE' ? 'selected' : '' ?>>UPDATE</option>
                    <option value="ASSIGN" <?= $action === 'ASSIGN' ? 'selected' : '' ?>>ASSIGN</option>
                    <option value="DELETE" <?= $action === 'DELETE' ? 'selected' : '' ?>>DELETE</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase mb-1">From Date</label>
                <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" class="border p-2 rounded">
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase mb-1">To Date</label>
                <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" class="border p-2 rounded">
            </div>
            <div class="pt-5 flex space-x-2">
                <button type="submit" class="bg-slate-800 text-white px-4 py-2 rounded font-bold">Filter</button>
                <a href="audit_logs.php" class="bg-slate-200 text-slate-700 px-3 py-2 rounded font-medium">Reset</a>
            </div>
        </form>

        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <table class="w-full text-left text-sm print-table">
                <thead class="bg-slate-50 border-b text-xs text-slate-500 uppercase">
                    <tr><th class="p-3">Timestamp</th><th class="p-3">Performed By</th><th class="p-3">Action</th><th class="p-3">Asset Tag</th><th class="p-3">Audit Details</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="5" class="p-6 text-center text-slate-400 italic">No audit logs found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $l): ?>
                            <tr>
                                <td class="p-3 text-xs text-slate-500 font-mono"><?= $l['created_at'] ?></td>
                                <td class="p-3 font-semibold"><?= htmlspecialchars($l['full_name'] ?? 'System') ?></td>
                                <td class="p-3">
                                    <span class="px-2 py-0.5 text-xs font-bold rounded 
                                        <?= $l['action'] === 'CREATE' ? 'bg-emerald-100 text-emerald-800' : '' ?>
                                        <?= $l['action'] === 'UPDATE' ? 'bg-blue-100 text-blue-800' : '' ?>
                                        <?= $l['action'] === 'DELETE' ? 'bg-red-100 text-red-800' : '' ?>">
                                        <?= $l['action'] ?>
                                    </span>
                                </td>
                                <td class="p-3 font-mono font-bold text-indigo-600"><?= htmlspecialchars($l['asset_tag']) ?></td>
                                <td class="p-3 text-slate-600"><?= htmlspecialchars($l['details']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
    <div class="no-print">
        <?php renderFooter(); ?>
    </div>
</body>
</html>