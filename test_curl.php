<?php
$ch = curl_init("http://127.0.0.1:8000/api/sync-membership");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    "memberId" => 12,
    "firstName" => "Test",
    "lastName" => "Curl",
    "email" => "test@example.com",
    "planLabel" => "Test Plan",
    "planPrice" => 100,
    "planMonths" => 1,
    "startDate" => date('Y-m-d'),
    "nextRenewalDate" => date('Y-m-d', strtotime('+1 month')),
    "status" => "active",
    "paymentMethod" => "Cash",
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "Accept: application/json",
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);

$response = curl_exec($ch);

echo "cURL Error: " . curl_error($ch) . "\n";
echo "HTTP Code: " . curl_getinfo($ch, CURLINFO_HTTP_CODE) . "\n";
echo "Response: " . $response . "\n";

curl_close($ch);
?>
