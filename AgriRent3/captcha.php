<?php
session_start();

// Generate random alphanumeric captcha
function generateTextCaptcha($length = 6) {
    // Mix of letters and numbers (excluding similar looking characters like 0, O, 1, l, I)
    $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $captcha_code = '';
    
    for ($i = 0; $i < $length; $i++) {
        $captcha_code .= $characters[rand(0, strlen($characters) - 1)];
    }
    
    return $captcha_code;
}

// Generate captcha code and store in session
$captcha_code = generateTextCaptcha(6);
$_SESSION['captcha_code'] = $captcha_code;

// Output the captcha code
echo $captcha_code;
?>
