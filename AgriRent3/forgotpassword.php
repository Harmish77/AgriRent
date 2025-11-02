<?php
session_start();

if (isset($_SESSION['logged_in']) && $_SESSION['logged_in']) {
    header('Location: index.php');
    exit;
}
?>
<?php include 'includes/header.php'; ?>
<?php include 'includes/navigation.php'; ?>
<?php require_once('auth/config.php');
 ?>

<main class="auth-wrapper">
    <section class="auth-card" aria-labelledby="forgot-heading">
        <h2 id="forgot-heading">Forgot Password</h2>
        <p>Please Enter Your Registered Mobile Number</p>

        <?php
        if (isset($_SESSION['error'])) {
            echo '<div style="color: red; font-weight: bold; margin-bottom: 10px;">' . htmlspecialchars($_SESSION['error']) . '</div>';
            unset($_SESSION['error']);
        }
        
        if (isset($_SESSION['success'])) {
            echo '<div style="color: green; font-weight: bold; margin-bottom: 10px;">' . htmlspecialchars($_SESSION['success']) . '</div>';
            unset($_SESSION['success']);
        }

        
        ?>

        <form action="auth/forgot_check.php" method="POST">
            <div class="input-group">
                <input 
                    type="tel" 
                    name="phone" 
                    pattern="[0-9]{10}" 
                    maxlength="10" minlength="10" 
                    placeholder="Enter registered mobile number" 
                    required
                    <?php 
                    if (isset($_SESSION['otp_sent']) && $_SESSION['otp_sent']) {
                        echo 'readonly value="' . htmlspecialchars($_SESSION['reset_phone_display'] ?? '') . '"';
                    }
                    ?>
                >
            </div>

            <?php if (isset($_SESSION['otp_sent']) && $_SESSION['otp_sent']): ?>
            <div class="input-group">
                <input 
                    type="text" 
                    name="otp" 
                    placeholder="Enter OTP" 
                    required
                    autofocus
                >
            </div>
            
            <div class="input-group">
                <input 
                    type="password" 
                    name="new_password" 
                    placeholder="Enter new password (min 6 characters)" 
                    required
                    minlength="6"
                >
            </div>
            
            <div class="input-group">
                <input 
                    type="password" 
                    name="confirm_password" 
                    placeholder="Confirm new password" 
                    required
                    minlength="6"
                >
            </div>
            <?php endif; ?>

            <button 
                type="submit" 
                class="primary-btn" 
                name="<?php echo isset($_SESSION['otp_sent']) ? 'reset_password' : 'send_otp'; ?>"
            >
                <?php echo isset($_SESSION['otp_sent']) ? 'Reset Password' : 'Send OTP'; ?>
            </button>
        </form>
        
        <?php if (isset($_SESSION['otp_sent']) && $_SESSION['otp_sent']): ?>
        <form action="auth/forgot_check.php" method="POST" style="margin-top: 10px;">
            <button type="submit" name="resend_otp" class="primary-btn" style="background-color: #6c757d;">
                Resend OTP
            </button>
        </form>
        <?php endif; ?>
        
        <p style="margin-top: 15px;">
            <a href="login.php">Back to Login</a>
        </p>
    </section>
</main>

<?php include 'includes/footer.php'; ?>
