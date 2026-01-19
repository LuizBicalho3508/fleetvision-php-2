<?php
session_start();

// PERMISSÃO: Agora 'admin' (Dono da Empresa) e 'superadmin' podem acessar
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['superadmin', 'admin'])) {
    http_response_code(403);
    exit(json_encode(['error' => 'Acesso Negado']));
}

$TRACCAR_HOST = 'http://127.0.0.1:8082/api';
$USER = 'admin'; // Credenciais do Traccar
$PASS = 'admin'; 

function request($method, $url, $data = null) {
    global $USER, $PASS;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, "$USER:$PASS");
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    if($method == 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'data' => json_decode($res, true)];
}

$action = $_GET['action'] ?? '';
$imei = $_GET['imei'] ?? '';

header('Content-Type: application/json');

// 1. BUSCAR (Se for Admin Local, só pode buscar? Por enquanto liberamos global para teste de bancada)
if ($action == 'find') {
    if(!$imei) exit(json_encode(['error' => 'IMEI obrigatório']));
    $res = request('GET', $TRACCAR_HOST . '/devices?uniqueId=' . $imei);
    if (empty($res['data'])) exit(json_encode(['found' => false, 'message' => 'Dispositivo não encontrado no Traccar.']));
    $device = $res['data'][0];
    exit(json_encode(['found' => true, 'device' => $device]));
}

// 2. POLLING
if ($action == 'poll') {
    $deviceId = $_GET['id'] ?? 0;
    $pos = request('GET', $TRACCAR_HOST . '/positions?deviceId=' . $deviceId);
    if (empty($pos['data'])) exit(json_encode(['status' => 'waiting', 'log' => 'Aguardando dados...']));
    $lastPos = end($pos['data']);
    echo json_encode(['status' => 'ok', 'telemetry' => $lastPos, 'timestamp' => date('H:i:s')]);
    exit;
}

// 3. COMANDO
if ($action == 'command') {
    $deviceId = $_POST['id'];
    $type = $_POST['type'];
    $cmd = $_POST['command'] ?? '';
    $payload = ['deviceId' => $deviceId, 'type' => $type];
    if($type == 'custom') $payload['attributes'] = ['data' => $cmd];
    $res = request('POST', $TRACCAR_HOST . '/commands', $payload);
    echo json_encode($res);
    exit;
}
?>
