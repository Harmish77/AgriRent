<?php
session_start();
include 'auth/config.php'; // Your DB connection file

$RAPID_HOST = "whatsauth-whatsapp-otp1.p.rapidapi.com";
$RAPID_KEY = "c13670a4a8msh48858eae9b5abd2p1121bcjsne2eea50360c0";

function redirect_with_message($type, $msg) {
    $_SESSION[$type] = $msg;
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

if (isset($_SESSION['logged_in']) && $_SESSION['logged_in']) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_otp'])) {
    if (!$conn) {
        redirect_with_message('error', 'Database connection failed');
    }

    $mobile_raw = $_POST['mobile'] ?? '';
    $mobile = preg_replace('/\D/', '', $mobile_raw);

    if (!preg_match('/^[6-9]\d{9}$/', $mobile)) {
        redirect_with_message('error', 'Please enter a valid 10-digit mobile number.');
    }

    $stmt = $conn->prepare("SELECT user_id FROM users WHERE Phone = ?");
    $stmt->bind_param("s", $mobile);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        redirect_with_message('error', 'Mobile number not registered.');
    }

    $_SESSION['forgot_phone'] = $mobile;
    $phone = '+91' . $mobile;

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => "https://$RAPID_HOST/send-otp?phone=" . urlencode($phone)
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
        error_log("OTP send error: $err");
        redirect_with_message('error', "Error sending OTP: " . $err);
    }

    // After receiving the API response $response

    $result = json_decode($response, true);

    if (!$result) {
        // JSON decode failed
        error_log("OTP API response JSON decode error: " . $response);
        $_SESSION['error'] = "Unexpected API response. Please try again.";
    } elseif (isset($result['success']) && $result['success'] === true) {
        $_SESSION['otp_sent'] = true;
        $_SESSION['success'] = "OTP sent successfully.";
    } else {
        // Log full error message from API
        $error_message = $result['message'] ?? 'Unknown error occurred';
        error_log("OTP API error: " . $error_message);
        $_SESSION['error'] = $error_message;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_otp'])) {
    if (!isset($_SESSION['forgot_phone'])) {
        redirect_with_message('error', 'Phone number missing from session. Please request OTP again.');
    }
    $otp = $_POST['otp'] ?? '';
    if (!preg_match('/^\d{6}$/', $otp)) {
        redirect_with_message('error', 'Please enter a valid 6-digit OTP.');
    }
    $phone = '+91' . $_SESSION['forgot_phone'];

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
        error_log("OTP verify error: $err");
        redirect_with_message('error', "Error verifying OTP: " . $err);
    }

    $responseClean = trim($response);
    $lastBrace = strrpos($responseClean, '}');
    if ($lastBrace !== false) {
        $responseClean = substr($responseClean, 0, $lastBrace + 1);
    }
    $result = json_decode($responseClean, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("OTP verify response JSON error: $responseClean");
        redirect_with_message('error', "Invalid API response. Please try again.");
    }

    if (isset($result['success']) && $result['success'] === true) {
        $_SESSION['otp_verified'] = true;
        redirect_with_message('success', $result['message'] ?? "OTP verified successfully.");
    } else {
        error_log("OTP verify failed. API message: " . ($result['message'] ?? 'No message'));
        redirect_with_message('error', $result['message'] ?? "Invalid OTP!");
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    if (empty($_SESSION['otp_verified']) || !isset($_SESSION['forgot_phone'])) {
        redirect_with_message('error', "Please verify your OTP before resetting the password.");
    }

    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 6) {
        redirect_with_message('error', "Password must be at least 6 characters.");
    }
    if ($password !== $confirm_password) {
        redirect_with_message('error', "Passwords do not match.");
    }

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    $phone = $_SESSION['forgot_phone'];

    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE Phone = ?");
    $stmt->bind_param("ss", $hashed_password, $phone);

    if ($stmt->execute()) {
        unset($_SESSION['otp_sent'], $_SESSION['otp_verified'], $_SESSION['forgot_phone']);
        redirect_with_message('success', "Password reset successfully. You can now log in.");
    } else {
        redirect_with_message('error', "Failed to reset password. Please try again.");
    }
}
?>

<!DOCTYPE html>
<html>
    <head>
        <title>Forgot Password - AgriRent</title>
    </head>
    <body>

        <h2>Forgot Password</h2>

        <?php
        if (isset($_SESSION['error'])) {
            echo '<p style="color:red; font-weight:bold;">' . $_SESSION['error'] . '</p>';
            unset($_SESSION['error']);
        }
        if (isset($_SESSION['success'])) {
            echo '<p style="color:green; font-weight:bold;">' . $_SESSION['success'] . '</p>';
            unset($_SESSION['success']);
        }
        ?>

        <?php if (!isset($_SESSION['otp_sent'])): ?>
            <form method="POST" action="">
                <label>Enter Registered Mobile Number:</label><br>
                <input type="tel" name="mobile" pattern="[0-9]{10}" minlength="10" maxlength="10" required><br><br>
                <button type="submit" name="send_otp">Send OTP</button>
            </form>

        <?php elseif (!isset($_SESSION['otp_verified'])): ?>
            <form method="POST" action="">
                <label>Enter OTP sent to WhatsApp:</label><br>
                <input type="text" name="otp" minlength="6" maxlength="6" pattern="\d{6}" required><br><br>
                <button type="submit" name="verify_otp">Verify OTP</button>
            </form>

        <?php else: ?>
            <form method="POST" action="">
                <label>New Password:</label><br>
                <input type="password" name="password" minlength="6" required><br><br>
                <label>Confirm Password:</label><br>
                <input type="password" name="confirm_password" minlength="6" required><br><br>
                <button type="submit" name="reset_password">Reset Password</button>
            </form>
        <?php endif; ?>

    </body>
</html>
