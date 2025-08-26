<?php

session_start();
$curl = curl_init();

$phone = '+91' . preg_replace('/\D/', '', $_POST['phone']);
$name = $_POST['first_name'];

curl_setopt_array($curl, [
    CURLOPT_URL => "https://whatsauth-whatsapp-otp1.p.rapidapi.com/send-otp?phone=" . urlencode($phone)
    . "&image=https%3A%2F%2Fcdn.pixabay.com%2Fphoto%2F2024%2F04%2F20%2F11%2F47%2Fai-generated-8708404_1280.jpg"
    . "&name=" . urlencode($name)
    . "&company=AgriRent&template=registration&language=en&force=true",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => "",
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => "GET",
    CURLOPT_HTTPHEADER => [
        "x-rapidapi-host: whatsauth-whatsapp-otp1.p.rapidapi.com",
        "x-rapidapi-key: c13670a4a8msh48858eae9b5abd2p1121bcjsne2eea50360c0"
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
        $_SESSION['success'] = "OTP sent successfully to $phone";
    } else {
        $_SESSION['error'] = "Failed to send OTP. Please try again.";
    }
}

header("Location: ../register.php");
exit;
?>


<?php
session_start();
?>
<?php include 'includes/header.php'; ?>
<?php include 'includes/navigation.php'; ?>

<?php
$RAPID_HOST = "whatsauth-whatsapp-otp1.p.rapidapi.com";
$RAPID_KEY  = "c13670a4a8msh48858eae9b5abd2p1121bcjsne2eea50360c0";

// == Step 1: Send OTP ==
if (isset($_POST['get_otp'])) {
    $phone = '+91' . preg_replace('/\D/', '', $_POST['phone']);
    $name  = $_POST['first_name'];

    // Save phone & name in session
    $_SESSION['reg_phone'] = $phone;
    $_SESSION['reg_name']  = $name;

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => "https://$RAPID_HOST/send-otp?phone=" . urlencode($phone)
            . "&image=https%3A%2F%2Fcdn.pixabay.com%2Fphoto%2F2024%2F04%2F20%2F11%2F47%2Fai-generated-8708404_1280.jpg"
            . "&name=" . urlencode($name)
            . "&company=AgriRent&template=registration&language=en&force=true",
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
            $_SESSION['success']  = "OTP sent successfully to $phone";
        } else {
            $_SESSION['error'] = $result['message'] ?? "Failed to send OTP. Please try again.";
        }
    }
    header("Location: register.php");
    exit;
}

// == Step 2: Verify OTP ==
if (isset($_POST['verify_otp'])) {
    // Always use phone from Step 1, not from $_POST
    if (!isset($_SESSION['reg_phone'])) {
        $_SESSION['error'] = "Phone number missing from session. Please request OTP again.";
        header("Location: register.php");
        exit;
    }
    $phone = $_SESSION['reg_phone'];
    $otp   = $_POST['otp'];

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
    } else {
        // Clean trailing data
        $responseClean = trim($response);
        $lastBrace = strrpos($responseClean, '}');
        if ($lastBrace !== false) {
            $responseClean = substr($responseClean, 0, $lastBrace + 1);
        }

        $result = json_decode($responseClean, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $_SESSION['error'] = "Invalid API response: " . $responseClean;
        } elseif (isset($result['success']) && $result['success'] === true) {
            $_SESSION['otp_verified']   = true;
            $_SESSION['verified_phone'] = $result['data']['phone'] ?? null;
            $_SESSION['success']        = $result['message'] ?? "OTP verified successfully.";
        } else {
            $_SESSION['error'] = $result['message'] ?? "Invalid OTP!";
        }
    }
    header("Location: register.php");
    exit;
}

// == Step 3: Create Account ==
if (isset($_POST['create_account'])) {
    if (empty($_SESSION['otp_verified'])) {
        $_SESSION['error'] = "Please verify your OTP before creating the account.";
        header("Location: register.php");
        exit;
    }
    // ✅ At this point: OTP verified — insert into DB here
    $_SESSION['success'] = "Account created successfully!";
    unset($_SESSION['otp_sent'], $_SESSION['otp_verified'], $_SESSION['reg_phone'], $_SESSION['reg_name']);
    header("Location: register.php");
    exit;
}
?>

<!-- HTML FORM -->
<main class="auth-wrapper">
<section class="auth-card" aria-labelledby="reg-heading">
    <h2 id="reg-heading">Create an AgriRent account</h2>
    <?php
    if (isset($_SESSION['error'])) {
        echo '<div style="color: red; font-weight: bold; margin-bottom: 10px;">' . $_SESSION['error'] . '</div>';
        unset($_SESSION['error']);
    }
    if (isset($_SESSION['success'])) {
        echo '<div style="color:green;font-weight:bold;">' . $_SESSION['success'] . '</div>';
        unset($_SESSION['success']);
    }
    ?>

    <form method="POST">
        <div class="input-group">
            <label for="first_name">First name</label>
            <input type="text" id="first_name" name="first_name"
                   value="<?= htmlspecialchars($_SESSION['reg_name'] ?? '') ?>"
                   <?= isset($_SESSION['otp_sent']) ? 'readonly' : '' ?>
                   required>
        </div>
        <div class="input-group">
            <label for="phone">Phone number</label>
            <input type="tel" id="phone" name="phone" pattern="[0-9]{10}" maxlength="10"
                   value="<?= isset($_SESSION['reg_phone']) ? substr($_SESSION['reg_phone'],3) : '' ?>"
                   <?= isset($_SESSION['otp_sent']) ? 'readonly' : '' ?>
                   required>
        </div>

        <?php if (!isset($_SESSION['otp_sent'])): ?>
            <button type="submit" name="get_otp">Get OTP</button>
        <?php elseif (!isset($_SESSION['otp_verified'])): ?>
            <div>
                <label>Enter OTP:</label>
                <input type="text" name="otp" required>
            </div>
            <button type="submit" name="verify_otp">Verify OTP</button>
        <?php else: ?>
            <div class="input-group">
                <label for="password">Password</label>
                <input type="password" name="password" minlength="6" maxlength="10" required>
            </div>
            <div class="input-group">
                <label for="confirm_password">Confirm password</label>
                <input type="password" name="confirm_password" minlength="6" maxlength="10" required>
            </div>
            <label>
                <input type="checkbox" name="agree" required>
                I agree to the <a href="terms.php">Terms</a> & <a href="privacy.php">Privacy</a>
            </label>
            <button type="submit" name="create_account">Create Account</button>
        <?php endif; ?>
    </form>
</section>
</main>

<?php include 'includes/footer.php'; ?>


