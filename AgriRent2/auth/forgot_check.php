<?php
session_start();
require_once 'config.php'; // Include your database connection file


$RAPID_HOST = "whatsauth-whatsapp-otp1.p.rapidapi.com";
$RAPID_KEY = "c13670a4a8msh48858eae9b5abd2p1121bcjsne2eea50360c0";


function redirect_with_message($type, $message) {
    $_SESSION[$type] = $message;
    header("Location: ../forgotpassword.php");
    exit;
}


function sendOTPAPI($phone, $name, $RAPID_HOST, $RAPID_KEY) {
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => "https://$RAPID_HOST/send-otp?phone=" . urlencode($phone)
            . "&image=https%3A%2F%2Fcdn.pixabay.com%2Fphoto%2F2024%2F04%2F20%2F11%2F47%2Fai-generated-8708404_1280.jpg"
            . "&name=" . urlencode($name)
            . "&company=AgriRent&template=resetPassword&language=en&force=true",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => "GET",
        CURLOPT_HTTPHEADER => [
           "x-rapidapi-host: $RAPID_HOST",
           "x-rapidapi-key: $RAPID_KEY"
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false
    ]);

    $response = curl_exec($curl);
    $err = curl_error($curl);
    curl_close($curl);

    if ($err) {
        return ['success' => false, 'error' => "cURL Error: $err"];
    }

    $result = json_decode($response, true);
    if (!$result) {
        return ['success' => false, 'error' => 'Invalid API response format'];
    }

    return ['success' => true, 'data' => $result];
}


function verifyOTPAPI($phone, $otp, $RAPID_HOST, $RAPID_KEY) {
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => "https://$RAPID_HOST/verify-otp?phone=" . urlencode($phone) . "&otp=" . urlencode($otp),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => "GET",
        CURLOPT_HTTPHEADER => [
            "x-rapidapi-host: $RAPID_HOST",
            "x-rapidapi-key: $RAPID_KEY"
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false
    ]);

    $response = curl_exec($curl);
    $err = curl_error($curl);
    curl_close($curl);

    if ($err) {
        return ['success' => false, 'error' => "cURL Error: $err"];
    }

    
    $responseClean = trim($response);
    $lastBrace = strrpos($responseClean, '}');
    if ($lastBrace !== false) {
        $responseClean = substr($responseClean, 0, $lastBrace + 1);
    }

    $result = json_decode($responseClean, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['success' => false, 'error' => 'Invalid API response format'];
    }

    return ['success' => true, 'data' => $result];
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_otp'])) {
    $phone_input = preg_replace('/\D/', '', $_POST['phone'] ?? '');

    
    if (strlen($phone_input) !== 10) {
        redirect_with_message('error', 'Please enter a valid 10-digit mobile number.');
    }

    
    $stmt = $conn->prepare("SELECT user_id, Name FROM users WHERE Phone = ?");
    if (!$stmt) {
        error_log("Database prepare error: " . $conn->error);
        redirect_with_message('error', 'Database error. Please try again.');
    }

    $stmt->bind_param("s", $phone_input);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        redirect_with_message('error', 'Mobile number not registered.');
    }

    $user = $result->fetch_assoc();
    $name = $user['Name'];
    $user_id = $user['user_id'];

    $phone_for_api = '+91' . $phone_input;

    
    $_SESSION['reset_phone'] = $phone_for_api;
    $_SESSION['reset_phone_display'] = $phone_input;
    $_SESSION['reset_name'] = $name;
    $_SESSION['reset_user_id'] = $user_id;

    
    $otp_result = sendOTPAPI($phone_for_api, $name, $RAPID_HOST, $RAPID_KEY);

    if (!$otp_result['success']) {
        error_log("OTP send error: " . $otp_result['error']);
        redirect_with_message('error', "Error sending OTP: " . $otp_result['error']);
    }

    $api_response = $otp_result['data'];

    if (isset($api_response['success']) && $api_response['success'] === true) {
        $_SESSION['otp_sent'] = true;
        redirect_with_message('success', "OTP sent successfully to WhatsApp " . $phone_input);
    } else {
        $error_message = $api_response['message'] ?? 'Failed to send OTP. Please try again.';
        error_log("OTP API error: " . $error_message);
        redirect_with_message('error', $error_message);
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend_otp'])) {
    if (!isset($_SESSION['reset_phone']) || !isset($_SESSION['reset_name'])) {
        redirect_with_message('error', 'Session expired. Please start the process again.');
    }

    $phone = $_SESSION['reset_phone'];
    $name = $_SESSION['reset_name'];

   
    $otp_result = sendOTPAPI($phone, $name, $RAPID_HOST, $RAPID_KEY);

    if (!$otp_result['success']) {
        error_log("OTP resend error: " . $otp_result['error']);
        redirect_with_message('error', "Error resending OTP: " . $otp_result['error']);
    }

    $api_response = $otp_result['data'];

    if (isset($api_response['success']) && $api_response['success'] === true) {
        redirect_with_message('success', "OTP resent successfully to WhatsApp " . $_SESSION['reset_phone_display']);
    } else {
        $error_message = $api_response['message'] ?? 'Failed to resend OTP. Please try again.';
        error_log("OTP resend API error: " . $error_message);
        redirect_with_message('error', $error_message);
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    if (!isset($_SESSION['reset_phone']) || !isset($_SESSION['reset_user_id']) || !isset($_SESSION['otp_sent'])) {
        redirect_with_message('error', 'Session expired. Please start the process again.');
    }

    $phone = $_SESSION['reset_phone'];
    $user_id = $_SESSION['reset_user_id'];
    $otp = trim($_POST['otp'] ?? '');
    $new_password = trim($_POST['new_password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');

    // Validate inputs
    if (empty($otp)) {
        redirect_with_message('error', 'Please enter the OTP.');
    }

    if (empty($new_password) || empty($confirm_password)) {
        redirect_with_message('error', 'Please enter both password fields.');
    }

    if (strlen($new_password) < 6) {
        redirect_with_message('error', 'Password must be at least 6 characters long.');
    }

    if ($new_password !== $confirm_password) {
        redirect_with_message('error', 'Passwords do not match. Both fields must be exactly the same.');
    }

    
    $verify_result = verifyOTPAPI($phone, $otp, $RAPID_HOST, $RAPID_KEY);

    if (!$verify_result['success']) {
        error_log("OTP verify error: " . $verify_result['error']);
        redirect_with_message('error', "Error verifying OTP: " . $verify_result['error']);
    }

    $api_response = $verify_result['data'];

    if (isset($api_response['success']) && $api_response['success'] === true) {
        
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
        if (!$stmt) {
            error_log("Database prepare error: " . $conn->error);
            redirect_with_message('error', 'Database error. Please try again.');
        }

        $stmt->bind_param("si", $hashed_password, $user_id);

        if ($stmt->execute()) {
            
            error_log("Password reset successful for user_id: " . $user_id);

           
            unset($_SESSION['reset_phone']);
            unset($_SESSION['reset_phone_display']);
            unset($_SESSION['reset_name']);
            unset($_SESSION['reset_user_id']);
            unset($_SESSION['otp_sent']);

            $_SESSION['success'] = "Password reset successfully. You can now login with your new password.";
            header("Location: ../login.php");
            exit;
        } else {
            error_log("Database error updating password for user_id: " . $user_id . " - " . $stmt->error);
            redirect_with_message('error', "Error updating password. Please try again.");
        }
    } else {
        $error_message = $api_response['message'] ?? "Invalid OTP. Please try again.";
        error_log("OTP verification failed: " . $error_message);
        redirect_with_message('error', $error_message);
    }
}


redirect_with_message('error', 'Invalid request method.');
?>