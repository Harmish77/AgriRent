<?php
session_start();

$RAPID_HOST = "whatsauth-whatsapp-otp1.p.rapidapi.com";
$RAPID_KEY = "c13670a4a8msh48858eae9b5abd2p1121bcjsne2eea50360c0";

if (!isset($_SESSION['phone'])) {
    $_SESSION['error'] = "Phone number missing from session. Please request OTP again.";
    header('Location: forgot_password.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_otp'])) {
    $phone = '+91' . $_SESSION['phone'];
    $otp = $_POST['otp'] ?? '';

    if (empty($otp) || !preg_match('/^\d{6}$/', $otp)) {
        $_SESSION['error'] = "Please enter a valid 6-digit OTP.";
        header('Location: verify_forgot_otp.php');
        exit;
    }

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => "https://$RAPID_HOST/verify-otp?phone=" . urlencode($phone) . "&otp=" . urlencode($otp),
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
        $_SESSION['error'] = "Error verifying OTP: " . $err;
        header('Location: verify_forgot_otp.php');
        exit;
    } else {
        $responseClean = trim($response);
        $lastBrace = strrpos($responseClean, '}');
        if ($lastBrace !== false) {
            $responseClean = substr($responseClean, 0, $lastBrace + 1);
        }
        $result = json_decode($responseClean, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $_SESSION['error'] = "Invalid API response: " . $responseClean;
            header('Location: verify_forgot_otp.php');
            exit;
        } elseif (isset($result['success']) && $result['success'] === true) {
            $_SESSION['otp_verified_forgot'] = true;
            $_SESSION['success'] = $result['message'] ?? "OTP verified successfully.";
            header('Location: reset_password.php');
            exit;
        } else {
            $_SESSION['error'] = $result['message'] ?? "Invalid OTP!";
            header('Location: verify_forgot_otp.php');
            exit;
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Verify OTP - Forgot Password</title>
</head>
<body>
    <h2>Verify OTP</h2>
    <?php
    if (isset($_SESSION['error'])) {
        echo '<p style="color:red; font-weight: bold;">' . $_SESSION['error'] . '</p>';
        unset($_SESSION['error']);
    }
    if (isset($_SESSION['success'])) {
        echo '<p style="color:green; font-weight: bold;">' . $_SESSION['success'] . '</p>';
        unset($_SESSION['success']);
    }
    ?>
    <form method="POST" action="">
        <label>Enter OTP sent to your WhatsApp:</label><br>
        <input type="text" name="otp" maxlength="6" pattern="\d{6}" required>
        <br><br>
        <button type="submit" name="verify_otp">Verify OTP</button>
    </form>
</body>
</html>
