<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
if (empty($_SESSION )) {
    header('Location: ../admin-login.php');
    exit;
}

require_once '../project/db.php';

echo "✅ Admin logged in | Database connected | Ready to build dashboard";
?>