<?php
session_start();
require '../auth/config.php';

if (!$conn || $conn->connect_error) {
    $_SESSION['error'] = 'Database connection failed';
    header('Location: ../register.php');
    exit;
}

if (
    !empty($_SESSION['first_name']) && !empty($_SESSION['last_name']) &&
    !empty($_SESSION['email']) && !empty($_SESSION['phone']) &&
    !empty($_SESSION['user_type']) &&
    !empty($_SESSION['password']) && !empty($_SESSION['confirm_password'])
) {
    $name = ucwords(strtolower($_SESSION['first_name'] . ' ' . $_SESSION['last_name']));
    $email = $_SESSION['email'];
    $phone = $_SESSION['phone'];
    $userType = $_SESSION['user_type'];
    $password = $_SESSION['password'];
    $confirm  = $_SESSION['confirm_password'];


    if ($password !== $confirm) {
        $_SESSION['error'] = "Passwords don't match.";
        if (isset($_GET['admin_code'])) {
        // Sanitize admin_code to avoid issues
        $admin_code=($_GET['admin_code']);
        header("Location: register.php?admin_code=$admin_code");
        exit;
    } else {
        header("Location: register.php");
        exit;
    }
    }

    if (strlen($password) < 6) {
        $_SESSION['error'] = "Password must be at least 6 characters.";
        if (isset($_GET['admin_code'])) {
        // Sanitize admin_code to avoid issues
        $admin_code=($_GET['admin_code']);
        header("Location: register.php?admin_code=$admin_code");
        exit;
    } else {
        header("Location: register.php");
        exit;
    }
    }


    $stmt = $conn->prepare("SELECT user_id FROM users WHERE Phone = ?");
    $stmt->bind_param("s", $phone);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows > 0) {
        $_SESSION['error'] = "Mobile number already registered.";
        if (isset($_GET['admin_code'])) {
        $admin_code=($_GET['admin_code']);
        header("Location: register.php?admin_code=$admin_code");
        exit;
    } else {
        header("Location: register.php");
        exit;
    }
    }


    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO users (Name, Email, Phone, password, User_type) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sssss", $name, $email, $phone, $hashedPassword, $userType);
    
    if ($stmt->execute()) {
        session_unset(); // clear registration data
        $_SESSION['logged_in'] = true;
        $_SESSION['user_name'] = $name;
        $_SESSION['user_type'] = $userType;
        $_SESSION['user_id'] = $stmt->insert_id;

        header('Location: ../index.php');
        exit;
    } else {
        error_log("DB Error: " . $stmt->error);
        $_SESSION['error'] = "Registration failed. Please try again.";
        header('Location: ../register.php');
        exit;
    }
} else {
    $_SESSION['error'] = "Please fill all required fields.";
    header('Location: ../register.php');
    exit;
}
