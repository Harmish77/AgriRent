<nav class="navbar">
    <div class="container">
        <a href="index.php" class="logo">🚜 AgriRent</a>
        <button class="mobile-menu" id="mobile-menu">☰</button>
        <div class="nav-links" id="nav-links">
            <a href="index.php">Home</a>
            <a href="equipments.php">Equipment</a>
            <a href="products.php">Products</a>
            <a href="about.php">About</a>
            <a href="contact.php">Contact</a>
            <?php if (!empty($_SESSION['logged_in'])): ?>
                <div class="dropdown">
                    <button class="dropdown-toggle">
                        <div class="user-avatar">
                            <?= strtoupper(substr($_SESSION['user_name'] ?? 'U', 0, 1)) ?>
                        </div>
                        <div class="user-info">
                            <span class="username"><?= htmlspecialchars($_SESSION['user_name'] ?? 'User') ?></span>
                            <span class="user-dropdown-arrow">▼</span>
                        </div>
                    </button>
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
