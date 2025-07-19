<?php
require_once 'config/config.php';

function generateAgoraToken($channelName, $uid = 0, $role = 'publisher', $expireTimeInSeconds = 3600) {
    $appId = AGORA_APP_ID;
    $appCertificate = 'your_agora_app_certificate'; // Replace with your Agora App Certificate
    $currentTimestamp = time();
    $expireTimestamp = $currentTimestamp + $expireTimeInSeconds;

    $token = generateRtcToken($appId, $appCertificate, $channelName, $uid, $role, $expireTimestamp);
    return $token;
}

function generateRtcToken($appId, $appCertificate, $channelName, $uid, $role, $expireTimestamp) {
    $role = $role === 'publisher' ? 1 : 2;
    $tokenVersion = '006';
    $message = [
        'appID' => $appId,
        'channelName' => $channelName,
        'uid' => (string)$uid,
        'role' => $role,
        'expireTimestamp' => (string)$expireTimestamp
    ];
    $messageJson = json_encode($message);
    $signature = hash_hmac('sha256', $messageJson, $appCertificate, true);
    $signatureBase64 = base64_encode($signature);
    $token = $tokenVersion . $appId . $signatureBase64 . base64_encode($messageJson);
    return $token;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $channelName = $input['channelName'] ?? '';
    $uid = $input['uid'] ?? 0;
    if ($channelName) {
        $token = generateAgoraToken($channelName, $uid);
        echo json_encode(['token' => $token]);
    } else {
        echo json_encode(['error' => 'Invalid channel name']);
    }
}
?>