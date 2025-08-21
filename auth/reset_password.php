<?php
session_start();
include 'auth/config.php'; // Your DB connection file

if (empty($_SESSION['otp_verified_forgot']) || !isset($_SESSION['phone'])) {
    $_SESSION['error'] = "Unauthorized access. Please verify OTP first.";
    header('Location: forgot_password.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 6) {
        $_SESSION['error'] = "Password must be at least 6 characters.";
        header('Location: reset_password.php');
        exit;
    }

    if ($password !== $confirm_password) {
        $_SESSION['error'] = "Passwords do not match.";
        header('Location: reset_password.php');
        exit;
    }

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    $phone = $_SESSION['phone'];

    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE Phone = ?");
    $stmt->bind_param("ss", $hashed_password, $phone);

    if ($stmt->execute()) {
        unset($_SESSION['otp_verified_forgot'], $_SESSION['phone']);
        $_SESSION['success'] = "Password reset successfully. You can now log in.";
        header('Location: login.php');
        exit;
    } else {
        $_SESSION['error'] = "Failed to reset password. Please try again.";
        header('Location: reset_password.php');
        exit;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Reset Password</title>
</head>
<body>
    <h2>Reset Password</h2>
    <?php
    if (isset($_SESSION['error'])) {
        echo '<p style="color:red; font-weight: bold;">' . $_SESSION['error'] . '</p>';
        unset($_SESSION['error']);
    }
    if (isset($_SESSION['success'])) {
        echo '<p style="color:green; font-weight: bold;">' . $_SESSION['success'] . '</p>';
        unset($_SESSION['success']);
    }
    ?>
    <form method="POST" action="">
        <label>New Password:</label><br>
        <input type="password" name="password" minlength="6" required><br><br>
        <label>Confirm New Password:</label><br>
        <input type="password" name="confirm_password" minlength="6" required><br><br>
        <button type="submit" name="reset_password">Reset Password</button>
    </form>
</body>
</html>
