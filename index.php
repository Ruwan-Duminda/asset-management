
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (isset($_SESSION['user_id'])) {
    // Redirect logged-in users directly to the main dashboard
    header("Location: dashboard.php");
    exit();
} else {
    // Redirect guest users to the login page
    header("Location: login.php");
    exit();
}
?>