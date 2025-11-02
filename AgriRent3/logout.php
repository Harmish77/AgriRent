
<?php

session_start();
session_destroy();
echo '<script>location.replace("login.php");</script>';
header('Location: index.php');
exit;
?>
