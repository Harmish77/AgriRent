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

// Handle profile update submission
if (isset($_POST['update_profile'])) {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);

    if (empty($name) || empty($email)) {
        $error = "Name and Email are required";
    } else {
        $stmt = $conn->prepare("UPDATE users SET Name=?, Email=?, Phone=? WHERE user_id=?");
        $stmt->bind_param("sssi", $name, $email, $phone, $user_id);

        if ($stmt->execute()) {
            $message = "Profile updated successfully";
            $_SESSION['user_name'] = $name;
        } else {
            $error = "Failed to update profile";
        }
        $stmt->close();
    }
}

// Fetch user data
$stmt = $conn->prepare("SELECT Name, Email, Phone, User_type FROM users WHERE user_id=?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

require 'includes/header.php';
require 'includes/navigation.php';
?>

<div class="account-container">
    <div class="profile-page">
        <h1>My Profile</h1>

        <?php if ($message): ?>
            <div class="success-message"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="error-message"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="profile-card">
            <div class="profile-header">
                <div class="profile-avatar"><?= strtoupper(substr($user['Name'], 0, 2)) ?></div>
                <div class="profile-info">
                    <h2><?= htmlspecialchars($user['Name']) ?></h2>
                    <p>
                        <?= $user['User_type'] == 'O' ? 'Equipment Owner' : ($user['User_type'] == 'F' ? 'Farmer' : 'Admin') ?>
                    </p>
                </div>
            </div>

            <!-- Profile Update Form -->
            <form method="POST" class="profile-form">
                <div class="form-group">
                    <label for="name">Full Name</label>
                    <input type="text" id="name" name="name" value="<?= htmlspecialchars($user['Name']) ?>" required>
                </div>

                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" value="<?= htmlspecialchars($user['Email']) ?>" required>
                </div>

                <div class="form-group">
                    <label for="phone">Phone Number</label>
                    <input type="text" id="phone" name="phone" value="<?= htmlspecialchars($user['Phone']) ?>">
                </div>

                <div class="form-group">
                    <label>Account Type</label>
                    <input type="text" value="<?= $user['User_type'] == 'O' ? 'Equipment Owner' : ($user['User_type'] == 'F' ? 'Farmer' : 'Admin') ?>" readonly>
                </div>

                <div class="form-buttons">
                    <button type="submit" name="update_profile" class="btn-primary" style="padding:8.8px;">Update Profile</button>
                    <a href="change_password.php" class="btn-secondary" >Change Password</a>
                    <a href="addresses.php" class="btn-secondary">Manage Addresses</a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require 'includes/footer.php'; ?>
