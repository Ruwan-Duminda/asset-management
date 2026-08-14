<?php
// Enable temporary error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'db.php';

// Safe Auth Check: Include auth_helpers if available, fallback to basic session check
if (file_exists('auth_helpers.php')) {
    require_once 'auth_helpers.php';
    if (function_exists('checkAccess')) {
        checkAccess(['admin', 'editor']);
    }
} else {
    session_start();
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit();
    }
}

// Fallback audit logging function in case logAudit() is missing in auth_helpers.php
if (!function_exists('logAudit')) {
    function logAudit($pdo, $asset_tag, $action, $details) {
        try {
            $user = $_SESSION['username'] ?? 'System';
            $stmt = $pdo->prepare("INSERT INTO audit_logs (asset_tag, action, details, performed_by) VALUES (?, ?, ?, ?)");
            $stmt->execute([$asset_tag, $action, $details, $user]);
        } catch (Exception $e) {
            // Silently continue if audit logging fails
        }
    }
}

// Ensure the request came via POST from the modal
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $asset_id         = $_POST['asset_id'] ?? null;
    $return_condition = $_POST['return_condition'] ?? 'Good';
    $damage_type      = $_POST['damage_type'] ?? 'None';
    $damage_notes     = trim($_POST['damage_notes'] ?? '');

    if (!$asset_id) {
        die("Error: Invalid Asset ID provided.");
    }

    try {
        // 1. Fetch asset info
        $stmt = $pdo->prepare("SELECT asset_tag, brand, model FROM assets WHERE id = ?");
        $stmt->execute([$asset_id]);
        $asset = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$asset) {
            die("Error: Asset not found.");
        }

        // 2. Determine new status: Damaged/Poor assets automatically move to Maintenance
        $is_damaged = in_array($return_condition, ['Damaged', 'Poor']) || ($damage_type !== 'None');
        $new_status = $is_damaged ? 'Maintenance' : 'In Stock';

        // 3. Update asset details
        $updateSql = "
            UPDATE assets 
            SET assigned_employee_id = NULL,
                assigned_department_id = NULL,
                assignment_date = NULL,
                status = ?,
                condition_status = ?,
                last_return_condition = ?,
                last_return_date = CURDATE(),
                damage_type = ?,
                damage_notes = ?,
                notes = CONCAT(IFNULL(notes, ''), '\n[Returned ', CURDATE(), ']: Cond - ', ?, ' | Damage - ', ?, '. Notes: ', ?)
            WHERE id = ?
        ";

        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->execute([
            $new_status,
            $return_condition,
            $return_condition,
            $damage_type,
            $damage_notes,
            $return_condition,
            $damage_type,
            $damage_notes,
            $asset_id
        ]);

        // 4. Log the audit activity
        $audit_msg = "Asset checked in. Condition: {$return_condition} | Damage: {$damage_type}. Status set to: {$new_status}";
        logAudit($pdo, $asset['asset_tag'], 'UNASSIGN', $audit_msg);

        // Redirect back on success
        header('Location: view_assets.php?return=success');
        exit();

    } catch (PDOException $e) {
        die("Database Error: " . $e->getMessage());
    }
} else {
    // If someone visits return_asset.php directly in their browser without POSTing a form
    header('Location: view_assets.php');
    exit();
}