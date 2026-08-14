<?php
require_once 'db.php';

$email = 'ieslitadmin@iesl.lk';
$password = password_hash('Password123', PASSWORD_BCRYPT);

$stmt = $pdo->prepare("UPDATE users SET password = ? WHERE email = ?");
$stmt->execute([$password, $email]);

if ($stmt->rowCount() > 0) {
    echo "Admin password updated successfully! You can now log in with Password123.";
} else {
    // If no row was updated, insert the user
    $insert = $pdo->prepare("INSERT INTO users (full_name, email, password, role) VALUES ('System Admin', ?, ?, 'admin')");
    $insert->execute([$email, $password]);
    echo "Admin user created successfully!";
}
?>