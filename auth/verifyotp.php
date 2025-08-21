<?php

session_start();
$curl = curl_init();

$phone = '+91' . preg_replace('/\D/', '', $_POST['phone']);
$otp = $_POST['otp'];

curl_setopt_array($curl, [
    CURLOPT_URL => "https://whatsauth-whatsapp-otp1.p.rapidapi.com/verify-otp?phone=" . urlencode($phone) . "&otp=" . urlencode($otp),
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
    echo "cURL Error #:" . $err;
} else {
    echo $response;
}