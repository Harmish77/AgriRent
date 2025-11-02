<?php 
$host = 'localhost';
$user = 'root';
$pass = '';
$db = 'agrirent';

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die('Connection error: ' . $conn->connect_error);
}

// Wrap function in function_exists() check to prevent redeclaration
if (!function_exists('simpleExpireCheck')) {
    function simpleExpireCheck($conn) {
        $query = "UPDATE user_subscriptions SET Status = 'E' WHERE Status = 'A' AND end_date <= CURDATE()";
        $result = $conn->query($query);
        
        if ($result) {
            $count = $conn->affected_rows;
            if ($count > 0) {
                error_log("AgriRent: $count subscriptions expired");
            }
            return $count;
        }
        
        return false;
    }
}

simpleExpireCheck($conn);
?>
