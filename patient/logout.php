<?php
// Initialize the session environment
session_start();

// 1. Unset all session variables
$_SESSION = array();

// 2. If cookies are used to track the session, invalidate them completely
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), 
        '', 
        time() - 42000,
        $params["path"], 
        $params["domain"],
        $params["secure"], 
        $params["httponly"]
    );
}

// 3. Clear and destroy the session footprint on the server
session_unset();
session_destroy();

// 4. Safely bounce the patient back to your login gatekeeper
header("Location: login.php");
exit();
?>