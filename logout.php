<?php
require_once 'auth.php';
// Expire the session cookie in the browser before destroying server-side data
setcookie(session_name(), '', time() - 3600, '/');
session_destroy();
header('Location: login.php');
exit;
