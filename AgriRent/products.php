<?php session_start(); 
if (!isset($_SESSION['logged_in']) || !isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
?>
<?php include 'includes/header.php'; ?>
<?php include 'includes/navigation.php'; ?>
<div class="products-section">
    
    <?php include 'includes/error.php'; ?>
</div>
<?php include 'includes/footer.php'; ?>