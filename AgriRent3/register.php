    <?php
    session_start();

    if (isset($_SESSION['logged_in']) && $_SESSION['logged_in']) {
        header('Location: index.php');
        exit;
    }
    
    
    ?>
    <?php include 'includes/header.php'; ?>
    <?php include 'includes/navigation.php'; ?>
    <?php require_once('auth/config.php');
 ?>

    <?php
    foreach (['first_name', 'last_name', 'email', 'phone', 'user_type', 'admin_code', 'password', 'confirm_password'] as $field) {
        if (isset($_POST[$field])) {
            $_SESSION[$field] = $_POST[$field];
        }
    }



    $RAPID_HOST = "whatsauth-whatsapp-otp1.p.rapidapi.com";
    $RAPID_KEY = "c13670a4a8msh48858eae9b5abd2p1121bcjsne2eea50360c0";

    if (isset($_POST['get_otp'])) {

        if (!$conn) {
            $_SESSION['error'] = 'Database connection failed';
            header('location: register.php');
            exit;
        }


        if (!empty($_SESSION['phone'])) {
            $mobile = $_SESSION['phone'];
            $stmt = $conn->prepare("SELECT user_id FROM users WHERE Phone = ?");
            $stmt->bind_param("s", $mobile);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $result->num_rows > 0) {
                $_SESSION['error'] = "Mobile Number Already Registered";
                header("Location: register.php");
                exit;
            }
        }

        $phone = '+91' . preg_replace('/\D/', '', $_POST['phone']);
        $name = $_POST['first_name'];
        $_SESSION['reg_phone'] = $phone;
        $_SESSION['reg_name'] = $name;

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
                $_SESSION['success'] = "OTP sent successfully to Whatsapp $phone";
            } else {
                $_SESSION['error'] = $result['message'] ?? "Failed to send OTP. Please try again.";
            }
        }
        if (isset($_GET['admin_code'])) {
            $admin_code = ($_GET['admin_code']);
            header("Location: register.php?admin_code=$admin_code");
            exit;
        } else {
            header("Location: register.php");
            exit;
        }
    }

    if (isset($_POST['verify_otp'])) {
        if (!isset($_SESSION['reg_phone'])) {
            $_SESSION['error'] = "Phone number missing from session. Please request OTP again.";
            header("Location: register.php");
            exit;
        }
        $phone = $_SESSION['reg_phone'];
        $otp = $_POST['otp'];

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
            $responseClean = trim($response);
            $lastBrace = strrpos($responseClean, '}');
            if ($lastBrace !== false) {
                $responseClean = substr($responseClean, 0, $lastBrace + 1);
            }
            $result = json_decode($responseClean, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $_SESSION['error'] = "Invalid API response: " . $responseClean;
            } elseif (isset($result['success']) && $result['success'] === true) {
                $_SESSION['otp_verified'] = true;
                $_SESSION['verified_phone'] = $result['data']['phone'] ?? null;
                $_SESSION['success'] = $result['message'] ?? "OTP verified successfully.";
            } else {
                $_SESSION['error'] = $result['message'] ?? "Invalid OTP!";
            }
        }
        if (isset($_GET['admin_code'])) {
            $admin_code=($_GET['admin_code']);
            header("Location: register.php?admin_code=$admin_code");
            exit;
        } else {
            header("Location: register.php");
            exit;
        }
    }


    if (isset($_POST['create_account'])) {
        if (empty($_SESSION['otp_verified'])) {
            $_SESSION['error'] = "Please verify your OTP before creating the account.";
            header("Location: register.php");
            exit;
        }
        if (isset($_GET['admin_code'])) {
            // Sanitize admin_code to avoid issues
            $admin_code=($_GET['admin_code']);
            header("Location: " . "auth/register_check.php?admin_code=$admin_code");
            exit;
        } else {
            header("Location: " . "auth/register_check.php");
            exit;
        }
    }
    ?>
    <main class="auth-wrapper">
        <section class="auth-card" aria-labelledby="reg-heading">
            <h2 id="reg-heading">Create an AgriRent account</h2>

    <?php
    if (isset($_SESSION['error'])) {
        echo '<div style="color:red;font-weight:bold;margin-bottom:10px;">' . $_SESSION['error'] . '</div>';
        unset($_SESSION['error']);
    }
    if (isset($_SESSION['success'])) {
        echo '<div style="color:green;font-weight:bold;">' . $_SESSION['success'] . '</div>';
        unset($_SESSION['success']);
    }
    ?>

            <form action="" method="POST">
                <div class="input-group">
                    <label for="first_name">First name</label>
                    <input type="text" id="first_name" name="first_name"
                           pattern="[a-zA-Z]+"
                           oninput="this.value=this.value.replace(/[^a-zA-Z]/g,'')"
                           placeholder="Name"
                           value="<?= isset($_SESSION['first_name']) ? $_SESSION['first_name'] : '' ?>"
                           required>
                </div>

                <div class="input-group">
                    <label for="last_name">Last name</label>
                    <input type="text" id="last_name" name="last_name"
                           pattern="[a-zA-Z]+"
                           oninput="this.value=this.value.replace(/[^a-zA-Z]/g,'')"
                           placeholder="Surname"
                           value="<?= isset($_SESSION['last_name']) ? $_SESSION['last_name'] : '' ?>"
                           required>
                </div>

                <div class="input-group">
                    <label for="email">Email address</label>
                    <input type="email" id="email" name="email"
                           pattern="[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}"
                           placeholder="abc1@agrirent.com"
                           value="<?= isset($_SESSION['email']) ? $_SESSION['email'] : '' ?>"
                           >
                </div>

                <div class="input-group">
                    <label for="phone">Phone number</label>
                    <input type="tel" id="phone" name="phone"
                           pattern="[0-9]{10}"
                           maxlength="10" minlength="10"
                           placeholder="98765XXXXX"
                           value="<?= isset($_SESSION['phone']) ? $_SESSION['phone'] : '' ?>"
                           required>
                </div>

    <?php
    $flag = false;
    if (isset($_GET['admin_code']) && $_GET['admin_code'] === 'SECRET123') {
        $_SESSION['user_type'] = 'A';
        echo '<input type="hidden" name="user_type" value="A">';
        $flag = true;
    }

    if (!$flag) {
        echo '<fieldset class="radio-set">';
        echo '<legend>I am a…</legend>';

        $checkedF = (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'F') ? 'checked' : '';
        $checkedO = (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'O') ? 'checked' : '';

        echo '<label><input type="radio" name="user_type" value="F" required ' . $checkedF . '> Farmer - renting equipment</label>';
        echo '<label><input type="radio" name="user_type" value="O" required ' . $checkedO . '> Equipment owner - listing equipment</label>';
        echo '</fieldset>';
    }
    ?>



    <?php if (!isset($_SESSION['otp_sent'])): ?>

                    <button type="submit" class="primary-btn" name="get_otp">Get OTP</button>

    <?php elseif (!isset($_SESSION['otp_verified'])): ?>

                    <div class="input-group">
                        <label for="otp">Enter OTP</label>
                        <input type="tel" id="otp" name="otp" minlength="6" maxlength="6" placeholder="******" required>
                    </div>
                    <button type="submit" class="primary-btn" name="verify_otp">Verify OTP</button>

    <?php else: ?>
                    <div class="input-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password"
                               minlength="6" maxlength="10"
                               placeholder="******"
                               value="<?= isset($_SESSION['password']) ? $_SESSION['password'] : '' ?>"
                               required>
                    </div>
                    <div class="input-group">
                        <label for="confirm_password">Confirm password</label>
                        <input type="password" id="confirm_password" name="confirm_password"
                               minlength="6" maxlength="10"
                               placeholder="******"
                               value="<?= isset($_SESSION['confirm_password']) ? $_SESSION['confirm_password'] : '' ?>"
                               required>
                    </div>
                    <label class="checkbox-label">
                        <input type="checkbox" name="agree" required>
                        <span>I agree to the <a href="includes/terms.php">Terms</a> & <a href="includes/privacy.php">Privacy</a></span>
                    </label>
                    <button type="submit" class="primary-btn" name="create_account">Create account</button>
    <?php endif; ?>
            </form>

            <p class="alt-text">
                Already have an account? <a href="login.php">Sign in</a>
            </p>
        </section>
    </main>

    <?php include 'includes/footer.php'; ?>