<?php
session_start();
require_once '../auth/config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'A') {
    header('Location: ../login.php');
    exit;
}


require 'header.php';
require 'admin_nav.php';
?>
<div class="main-content">
    <?php include '../includes/error.php'; ?>
</div>


<?php require 'footer.php'; ?>
