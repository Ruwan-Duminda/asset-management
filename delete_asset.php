<?php
require_once 'db.php';
require_once 'auth_helpers.php';
checkAccess(['admin']);

$id = $_GET['id'] ?? null;
if ($id) {
    $stmt = $pdo->prepare("SELECT asset_tag FROM assets WHERE id = ?");
    $stmt->execute([$id]);
    $asset = $stmt->fetch();

    if ($asset) {
        $delStmt = $pdo->prepare("DELETE FROM assets WHERE id = ?");
        $delStmt->execute([$id]);
        logAudit($pdo, $asset['asset_tag'], 'DELETE', "Permanently deleted asset tag {$asset['asset_tag']}");
    }
}
header('Location: view_assets.php');
exit();
?>