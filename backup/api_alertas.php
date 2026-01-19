<?php
// Configurações
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

session_start();

function sendError($msg, $code = 500) {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

if (!isset($_SESSION['user_id'])) { sendError('Sessão expirada', 403); }

$tenant_id = $_SESSION['tenant_id'];
$user_id   = $_SESSION['user_id'];

try {
    $pdo = new PDO("pgsql:host=127.0.0.1;port=5432;dbname=traccar", "traccar", "traccar");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // --- SALVAR PREFERÊNCIAS ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save_config') {
        $input = file_get_contents('php://input');
        $stmt = $pdo->prepare("UPDATE saas_users SET alert_config = ? WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$input, $user_id, $tenant_id]);
        echo json_encode(['success' => true]);
        exit;
    }

    // --- SALVAR TRATATIVA ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        $eventIds = is_array($input['event_id']) ? $input['event_id'] : [$input['event_id']];
        $sql = "INSERT INTO saas_event_actions (event_id, user_id, notes) VALUES (?, ?, ?) 
                ON CONFLICT (event_id) DO UPDATE SET notes = EXCLUDED.notes, user_id = EXCLUDED.user_id, created_at = NOW()";
        $stmt = $pdo->prepare($sql);

        foreach ($eventIds as $id) {
            $stmt->execute([$id, $user_id, $input['notes']]);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    // --- LER PREFERÊNCIAS ---
    if (isset($_GET['action']) && $_GET['action'] === 'get_config') {
        $stmtUser = $pdo->prepare("SELECT alert_config FROM saas_users WHERE id = ?");
        $stmtUser->execute([$user_id]);
        $json = $stmtUser->fetchColumn();
        echo $json ?: json_encode(['ignition'=>true, 'speed'=>true, 'fence'=>true, 'battery'=>true, 'sos'=>true, 'driver'=>true, 'connection'=>true]);
        exit;
    }

    // --- BUSCAR ALERTAS (GET) ---
    
    // 1. Carregar Config
    $stmtUser = $pdo->prepare("SELECT alert_config FROM saas_users WHERE id = ?");
    $stmtUser->execute([$user_id]);
    $userConfig = json_decode($stmtUser->fetchColumn(), true) ?? [];

    // 2. Mapeamento de Motoristas (RFID -> Nome)
    // Busca todos os motoristas do tenant que têm tag RFID
    $stmtDrivers = $pdo->prepare("SELECT rfid_tag, name FROM saas_drivers WHERE tenant_id = ? AND rfid_tag IS NOT NULL AND rfid_tag != ''");
    $stmtDrivers->execute([$tenant_id]);
    $rfidMap = $stmtDrivers->fetchAll(PDO::FETCH_KEY_PAIR); // Array ['12345' => 'João', 'ABC' => 'Maria']

    // 3. Filtros
    $fromRaw = $_GET['from'] ?? date('Y-m-d H:i:s', strtotime('-24 hours'));
    $toRaw   = $_GET['to'] ?? date('Y-m-d H:i:s');
    $from = date('Y-m-d H:i:s', strtotime($fromRaw));
    $to   = date('Y-m-d H:i:s', strtotime($toRaw));
    $deviceId = isset($_GET['deviceId']) && is_numeric($_GET['deviceId']) ? $_GET['deviceId'] : 0;
    
    $deviceFilter = "";
    if ($deviceId > 0) $deviceFilter = "AND t.deviceid = " . intval($deviceId);

    // 4. SQL Principal
    $sql = "
        SELECT 
            t.id, t.type, t.eventtime, t.deviceid, 
            CAST(t.attributes AS TEXT) as attributes_json, 
            t.geofenceid,
            v.name as vehicle_name, v.plate,
            a.notes, u.name as resolved_by, a.created_at as resolved_at
        FROM tc_events t
        JOIN saas_vehicles v ON t.deviceid = v.traccar_device_id
        LEFT JOIN saas_event_actions a ON t.id = a.event_id
        LEFT JOIN saas_users u ON a.user_id = u.id
        WHERE v.tenant_id = :tid
          AND t.eventtime BETWEEN :from AND :to
          $deviceFilter
        ORDER BY t.eventtime DESC
        LIMIT 500
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['tid' => $tenant_id, 'from' => $from, 'to' => $to]);
    $events = $stmt->fetchAll();

    // 5. Processamento
    $finalEvents = [];
    $categoryMap = [
        'ignitionOn' => 'ignition', 'ignitionOff' => 'ignition',
        'deviceOverspeed' => 'speed',
        'geofenceEnter' => 'fence', 'geofenceExit' => 'fence',
        'powerCut' => 'battery', 'lowBattery' => 'battery',
        'sos' => 'sos', 'alarm' => 'sos',
        'driverChanged' => 'driver',
        'deviceOnline' => 'connection', 'deviceOffline' => 'connection', 'deviceUnknown' => 'connection'
    ];

    foreach ($events as $evt) {
        $type = $evt['type'];
        
        // Filtro de Preferência
        if (isset($categoryMap[$type])) {
            $cat = $categoryMap[$type];
            if (isset($userConfig[$cat]) && $userConfig[$cat] === false) continue;
        }

        // Atributos
        $attrs = [];
        if (!empty($evt['attributes_json'])) {
            $decoded = json_decode($evt['attributes_json'], true);
            if (json_last_error() === JSON_ERROR_NONE) $attrs = $decoded;
        }

        // --- IDENTIFICAÇÃO DO MOTORISTA ---
        $driverName = '-';
        // Se houver driverUniqueId nos atributos (padrão Traccar para RFID/iButton)
        if (isset($attrs['driverUniqueId'])) {
            $rfid = (string)$attrs['driverUniqueId'];
            // Tenta achar o nome no nosso mapa, senão mostra o código
            $driverName = $rfidMap[$rfid] ?? "RFID: $rfid";
        }

        // --- AJUSTE DE FUSO HORÁRIO ---
        // O banco retorna UTC sem timezone (ex: 2023-01-01 12:00:00).
        // Adicionamos 'Z' para o JS converter corretamente.
        $utcTime = str_replace(' ', 'T', $evt['eventtime']);
        if (substr($utcTime, -1) != 'Z') $utcTime .= 'Z';

        $finalEvents[] = [
            'id' => $evt['id'],
            'type' => $type,
            'eventTime' => $utcTime,
            'deviceId' => $evt['deviceid'],
            'geofenceId' => $evt['geofenceid'],
            'vehicle_name' => $evt['vehicle_name'],
            'driver_name' => $driverName, // Nome processado
            'status' => $evt['resolved_by'] ? 'resolved' : 'pending',
            'resolved_by' => $evt['resolved_by'],
            'notes' => $evt['notes'],
            'attributes' => $attrs
        ];
    }

    echo json_encode($finalEvents);

} catch (Exception $e) {
    sendError("Erro: " . $e->getMessage());
}
?>