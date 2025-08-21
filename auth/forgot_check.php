<?php
session_start();
include '../auth/config.php'; // Your DB connection file

$RAPID_HOST = "whatsauth-whatsapp-otp1.p.rapidapi.com";
$RAPID_KEY = "c13670a4a8msh48858eae9b5abd2p1121bcjsne2eea50360c0";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['btnforgot'])) {
    if (!$conn) {
        $_SESSION['error'] = "Database connection failed";
        header('Location: ../forgot_password.php');
        exit;
    }

    $mobile_raw = $_POST['mobile'] ?? '';
    $mobile = preg_replace('/\D/', '', $mobile_raw);
    
    if (!preg_match('/^[6-9]\d{9}$/', $mobile)) {
        $_SESSION['error'] = "Please enter a valid 10-digit mobile number.";
        header('Location: ../forgot_password.php');
        exit;
    }

    // Check if mobile is registered
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE Phone = ?");
    $stmt->bind_param("s", $mobile);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        $_SESSION['error'] = "Mobile number not registered.";
        header('Location: ../forgot_password.php');
        exit;
    }

    $_SESSION['phone'] = $mobile;
    $phone = '+91' . $mobile;

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => "https://$RAPID_HOST/send-otp?phone=" . urlencode($phone)
            . "&image=https%3A%2F%2Fcdn.pixabay.com%2Fphoto%2F2024%2F04%2F20%2F11%2F47%2Fai-generated-8708404_1280.jpg"
            . "&name=" . urlencode('User') 
            . "&company=AgriRent&template=forgotpassword&language=en&force=true",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => "GET",
        CURLOPT_HTTPHEADER => [
            "x-rapidapi-host: $RAPID_HOST",
            "x-rapidapi-key: $RAPID_KEY"
        ],
    ]);
    $response = curl_exec($curl);
    $err = curl_error($curl);
    curl_close($curl);

    if ($err) {
        $_SESSION['error'] = "Error sending OTP: " . $err;
    } else {
        $result = json_decode($response, true);
        if (isset($result['success']) && $result['success'] === true) {
            $_SESSION['otp_sent'] = true;
            $_SESSION['success'] = "OTP sent successfully to Whatsapp $phone";
            header('Location: ../auth/verify_forgot_otp.php');
            exit;
        } else {
            $_SESSION['error'] = $result['message'] ?? "Failed to send OTP. Please try again.";
            header('Location: ../auth/forgot_password.php');
            exit;
        }
    }
} else {
    $_SESSION['error'] = "Invalid request.";
    header('Location: ../forgot_password.php');
    exit;
}
?>
