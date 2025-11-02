<link rel="stylesheet" href="../assets/css/main.css"/>
<?php
session_start();
require_once 'auth/config.php';

if (!isset($_SESSION['logged_in']) || !isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

if (isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $error = "All fields are required.";
    } elseif ($new_password !== $confirm_password) {
        $error = "New password and confirm password do not match.";
    } elseif (strlen($new_password) < 6) {
        $error = "New password must be at least 6 characters long.";
    } else {
        // Fetch stored password
        $stmt = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->bind_result($hashed_password);
        $stmt->fetch();
        $stmt->close();

        if (password_verify($current_password, $hashed_password)) {
            $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
            $update_stmt->bind_param("si", $new_hash, $user_id);
            if ($update_stmt->execute()) {
                $message = "Password changed successfully.";
            } else {
                $error = "Failed to update password.";
            }
            $update_stmt->close();
        } else {
            $error = "Current password is incorrect.";
        }
    }
}

require 'includes/header.php';
require 'includes/navigation.php';
?>

<div class="changepassword-container">
    <div class="password-change-form">
        <h1>Change Password</h1>
        
        <?php if ($message): ?>
            <div class="success-message"><?= $message ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="error-message"><?= $error ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label for="current_password">Current Password</label>
                <input type="password" id="current_password" name="current_password" required>
            </div>

            <div class="form-group">
                <label for="new_password">New Password</label>
                <input type="password" id="new_password" name="new_password" required>
                <small>Password must be at least 6 characters long</small>
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm New Password</label>
                <input type="password" id="confirm_password" name="confirm_password" required>
            </div>

            <div class="form-buttons">
                <button type="submit" name="change_password" class="btn-primary">Change Password</button>
                <a href="../account.php" class="btn-secondary">Back to Profile</a>
            </div>
        </form>
    </div>
</div>

<?php require 'includes/footer.php'; ?>