<?php
session_start();

if (isset($_SESSION['logged_in']) && $_SESSION['logged_in']) {
    header('Location: index.php');
    exit;
}
?>
<?php include 'includes/header.php'; ?>
<?php include 'includes/navigation.php'; ?>

<main class="auth-wrapper">
    <section class="auth-card" aria-labelledby="login-heading">
        <h2 id="login-heading">Welcome</h2>
        <p>Please sign in to your account</p>

        <?php
        if (isset($_SESSION['error'])) {
            echo '<div style="color: red; font-weight: bold; margin-bottom: 10px;">' . $_SESSION['error'] . '</div>';
            unset($_SESSION['error']);
        }
        ?>

        <form action="auth/login_check.php" method="POST">

            <div class="input-group">
                <input type="tel" name="mobile" 
                       pattern="[0-9]{10}" 
                       maxlength="10" minlength="10" 
                       placeholder="Enter 10-digit mobile number"  value="<?php echo isset($_COOKIE['unumber']) ? $_COOKIE['unumber'] : ''; ?>"  required >
            </div>

            <div class="input-group">
                <input type="password" name="password" minlength="6" maxlength="10" required placeholder="Password" value="<?php echo isset($_COOKIE['upassword']) ? $_COOKIE['upassword'] : ''; ?>"  >
            </div>

            <div class="form-options">
                <label class="checkbox-label">
                    <input type="checkbox" name="remember">
                    <span>Remember me</span>
                </label>
                <a href="forgotpassword.php">forgot password?</a>
            </div>

            <button type="submit" class="primary-btn" name="btnlogin">Sign in</button>
        </form>

        <p class="alt-text">
            New to AgriRent? <a href="register.php">Create an account</a>
        </p>
    </section>
</main>


<?php include 'includes/footer.php'; ?>