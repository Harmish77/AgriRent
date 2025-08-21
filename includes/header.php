
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agricultural Equipment Rental System</title>
    <link rel="stylesheet" href="assets/css/main.css">
</head>
<body>
 <script>
    var mobileMenu = document.getElementById('mobile-menu');
    var navLinks = document.getElementById('nav-links');

    if (mobileMenu && navLinks) {
        mobileMenu.onclick = function () {
            if (navLinks.style.display === 'flex') {
                navLinks.style.display = 'none';
            } else {
                navLinks.style.display = 'flex';
            }
        };
    }
</script>

