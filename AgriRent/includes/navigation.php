<nav class="navbar">
    <div class="container">
        <a href="index.php" class="logo">🚜 AgriRent</a>
        <button class="mobile-menu" id="mobile-menu">☰</button>
        <div class="nav-links" id="nav-links">
            <a href="index.php">Home</a>
            <a href="<?= isset($_SESSION['logged_in']) && $_SESSION['logged_in'] ? 'equipments.php' : 'login.php' ?>">Equipment</a>
            <a href="<?= isset($_SESSION['logged_in']) && $_SESSION['logged_in'] ? 'products.php' : 'login.php' ?>">Products</a>
            <a href="about.php">About</a>
            <a href="contact.php">Contact</a>
            <?php if (!empty($_SESSION['logged_in'])): ?>
                <div class="dropdown">
                    <button class="dropdown-toggle">Profile &#x25BC;</button>
                    <div class="dropdown-menu">
                        <a href="account.php">Account</a>
                        <a href="<?php
                            if (isset($_SESSION['user_type']) && $_SESSION['user_type'] == 'A')
                                echo 'admin/dashboard.php';
                            elseif (isset($_SESSION['user_type']) && $_SESSION['user_type'] == 'F')
                                echo 'farmer/dashboard.php';
                            elseif (isset($_SESSION['user_type']) && $_SESSION['user_type'] == 'O')
                                echo 'owner/dashboard.php';
 
                        ?>">Dashboard</a>
                        <a href="logout.php">Logout</a>
                    </div>
                </div>
            <?php else: ?>
                <a href="login.php">Login</a>
                <a href="register.php">Register</a>
            <?php endif; ?>
        </div>
        <div id="google_translate_element"></div>
    </div>
</nav>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const mobileMenuBtn = document.getElementById('mobile-menu');
        const navLinks = document.getElementById('nav-links');
        mobileMenuBtn.addEventListener('click', function () {
            navLinks.classList.toggle('show');
        });
    });
</script>
