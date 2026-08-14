<?php
require_once 'db.php';
require_once 'auth_helpers.php';
checkAccess(['admin']);

$action = $_GET['action'] ?? '';
$from   = $_GET['from'] ?? '';
$to     = $_GET['to'] ?? '';

$sql = "SELECT l.id, l.created_at, u.full_name AS actor_name, u.email AS actor_email, l.action, l.asset_tag, l.details 
        FROM audit_logs l 
        LEFT JOIN users u ON l.user_id = u.id 
        WHERE 1=1";

$params = [];

if ($action !== '') {
    $sql .= " AND l.action = ?";
    $params[] = $action;
}
if ($from !== '') {
    $sql .= " AND DATE(l.created_at) >= ?";
    $params[] = $from;
}
if ($to !== '') {
    $sql .= " AND DATE(l.created_at) <= ?";
    $params[] = $to;
}

$sql .= " ORDER BY l.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$filename = "audit_log_export_" . date('Y-m-d_H-i') . ".csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');
fputcsv($output, ['Log ID', 'Timestamp', 'Actor Name', 'Actor Email', 'Action', 'Asset Tag', 'Details']);

foreach ($logs as $log) {
    fputcsv($output, [
        $log['id'],
        $log['created_at'],
        $log['actor_name'] ?? 'System',
        $log['actor_email'] ?? 'N/A',
        $log['action'],
        $log['asset_tag'],
        $log['details']
    ]);
}

fclose($output);
exit();
?>