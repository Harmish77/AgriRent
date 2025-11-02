<?php
session_start();
require 'config.php';

if (!$conn) {
    $_SESSION['error'] = 'Database connection failed';
    header('Location: login.php');
    exit;
}


if (!empty($_POST['mobile']) && !empty($_POST['password'])) {
    $mobile = $_POST['mobile'];
    $password = $_POST['password'];

    $query = "SELECT user_id, Name, password, User_type,status FROM users WHERE Phone = '$mobile'";
    $result = $conn->query($query);

    if ($result && $result->num_rows > 0) {
        $login = false;
        while ($row = $result->fetch_assoc()) {
            if (password_verify($password, $row['password'])) {
                $_SESSION['logged_in'] = true;
                $_SESSION['user_id'] = $row['user_id'];
                $_SESSION['user_name'] = $row['Name'];
                $_SESSION['user_type'] = $row['User_type'];
                $_SESSION['status'] = $row['status'];
                $login = true;
                
                if(isset($_POST['remember']))
                {
                setcookie('unumber', $mobile, time() + (7 * 24 * 60 * 60), "/");
                setcookie('upassword', $password, time() + (7 * 24 * 60 * 60), "/");
                }
                break;
            }
        }
        if ($login) {
            header("Location: ../index.php");
            exit;
        } else {
            $_SESSION['error'] = "Invalid contact number or password.";
            header('Location: ../login.php');
            exit;
        }
    } else {
        $_SESSION['error'] = "Mobile number not registered.";
        header('Location: ../login.php');
        exit;
    }
} else {
    $_SESSION['error'] = "Please fill in both fields.";
    header('Location: ../login.php');
    exit;
}
?>
