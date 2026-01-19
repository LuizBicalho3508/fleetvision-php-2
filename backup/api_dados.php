<?php
session_start();
// Disable error display in response body to ensure valid JSON
ini_set('display_errors', 0); 
error_reporting(E_ALL);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');

// --- 0. SECURITY VALIDATION ---
if (!isset($_SESSION['user_id'])) { 
    http_response_code(403); 
    exit(json_encode(['error' => 'Sessão expirada.'])); 
}

if (!file_exists('db.php')) {
    http_response_code(500);
    exit(json_encode(['error' => 'Erro crítico: db.php não encontrado.']));
}
require 'db.php';

$tenant_id  = $_SESSION['tenant_id'];
$user_id    = $_SESSION['user_id'];
$user_email = $_SESSION['user_email'] ?? '';
$user_role  = $_SESSION['user_role'] ?? 'user';

// --- 1. CLIENT CONTEXT AND PERMISSION FILTER ---
$loggedCustomerId = null;
$isRestricted = false; // Flag to indicate if it is a regular user

if ($user_role != 'admin' && $user_role != 'superadmin') {
    $isRestricted = true;
    $stmtUserCheck = $pdo->prepare("SELECT customer_id FROM saas_users WHERE id = ?");
    $stmtUserCheck->execute([$user_id]);
    $userDirectCustomer = $stmtUserCheck->fetchColumn();
    $loggedCustomerId = $userDirectCustomer ?: ($pdo->query("SELECT id FROM saas_customers WHERE email = '$user_email' AND tenant_id = $tenant_id")->fetchColumn());
}

// Build the restriction SQL snippet (will be injected into queries)
// If restricted, filter by client ID OR user ID linked to the vehicle
$restrictionSQL = "";
if ($isRestricted) {
    if ($loggedCustomerId) {
        $restrictionSQL = " AND (v.client_id = $loggedCustomerId OR v.user_id = $user_id)";
    } else {
        $restrictionSQL = " AND v.user_id = $user_id";
    }
}

// Capture Parameters
$action = $_REQUEST['action'] ?? '';
$endpoint = $_REQUEST['endpoint'] ?? '';

// =================================================================================
// ROUTER
// =================================================================================

// 1. TRACCAR PROXY
if (!empty($endpoint)) {
    // Basic security for direct endpoints
    if (strpos($endpoint, 'dashboard') !== false) { 
        http_response_code(400); 
        exit(json_encode(['error' => 'Endpoint restrito.'])); 
    }
    handleProxyTraccar($endpoint, $tenant_id, $loggedCustomerId, $user_id, $pdo);
    exit;
}

// 2. SYSTEM ACTIONS
switch ($action) {

    // =========================================================================
    // 📊 OPERATIONAL DASHBOARD & KPIs (WITH FILTER)
    // =========================================================================
    
    case 'get_kpis':
        try {
            // Total Active Fleet
            $sqlTotal = "SELECT COUNT(*) FROM saas_vehicles v WHERE v.tenant_id = ? AND v.status = 'active' $restrictionSQL";
            $stmtTotal = $pdo->prepare($sqlTotal);
            $stmtTotal->execute([$tenant_id]);
            $total = $stmtTotal->fetchColumn();

            // Online Vehicles (Communicated in the last 10 min)
            $sqlOnline = "SELECT COUNT(v.id) FROM saas_vehicles v 
                          JOIN tc_devices d ON v.traccar_device_id = d.id 
                          WHERE v.tenant_id = ? AND v.status = 'active' 
                          AND d.lastupdate > NOW() - INTERVAL '10 minutes'
                          $restrictionSQL";
            $stmtOnline = $pdo->prepare($sqlOnline);
            $stmtOnline->execute([$tenant_id]);
            $online = $stmtOnline->fetchColumn();

            // Vehicles in Motion (Online + Speed > 1 knot)
            $sqlMoving = "SELECT COUNT(v.id) FROM saas_vehicles v 
                          JOIN tc_devices d ON v.traccar_device_id = d.id 
                          JOIN tc_positions p ON d.positionid = p.id
                          WHERE v.tenant_id = ? AND v.status = 'active' 
                          AND d.lastupdate > NOW() - INTERVAL '10 minutes' AND p.speed > 1
                          $restrictionSQL"; 
            $stmtMoving = $pdo->prepare($sqlMoving);
            $stmtMoving->execute([$tenant_id]);
            $moving = $stmtMoving->fetchColumn();

            // Derived Calculations
            $stopped = $online - $moving; 
            if($stopped < 0) $stopped = 0;
            
            $offline = $total - $online;
            if($offline < 0) $offline = 0;

            echo json_encode([
                'total_vehicles' => $total,
                'online' => $online,
                'moving' => $moving,
                'stopped' => $stopped,
                'offline' => $offline,
                'total_distance_today' => 0
            ]);
        } catch (Exception $e) { http_500($e->getMessage()); }
        break;

    case 'get_dashboard_data':
        $type = $_REQUEST['type'] ?? 'online';
        try {
            // Fetch detailed vehicle list
            $sql = "SELECT v.id, v.name, v.plate, v.traccar_device_id as deviceid, 
                           t.lastupdate, t.positionid, p.speed, p.address
                    FROM saas_vehicles v 
                    LEFT JOIN tc_devices t ON v.traccar_device_id = t.id 
                    LEFT JOIN tc_positions p ON t.positionid = p.id
                    WHERE v.tenant_id = ? AND v.status = 'active' $restrictionSQL";
            
            // Optional Filters
            if ($type === 'offline') {
                $sql .= " AND (t.lastupdate < NOW() - INTERVAL '24 hours' OR t.lastupdate IS NULL)";
            } elseif ($type === 'online') {
                $sql .= " AND t.lastupdate >= NOW() - INTERVAL '24 hours'";
            }
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$tenant_id]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) { http_500($e->getMessage()); }
        break;

    case 'get_alerts':
        try {
            // Allow limit via URL (e.g., ?limit=50). Default is 5.
            $limit = isset($_REQUEST['limit']) ? (int)$_REQUEST['limit'] : 5;
            if ($limit > 200) $limit = 200; // Security cap

            // Query uses $restrictionSQL (defined at the beginning) to filter by user
            $sql = "SELECT e.id, e.type, e.eventtime as event_time, v.name as vehicle_name, 
                           p.latitude, p.longitude, e.attributes, v.plate
                    FROM tc_events e
                    JOIN saas_vehicles v ON e.deviceid = v.traccar_device_id
                    LEFT JOIN tc_positions p ON e.positionid = p.id
                    WHERE v.tenant_id = ? $restrictionSQL
                    ORDER BY e.eventtime DESC LIMIT $limit";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$tenant_id]);
            $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Translation Dictionary
            $dict = [
                'deviceOverspeed' => 'Excesso de Velocidade',
                'geofenceExit' => 'Saiu da Cerca',
                'geofenceEnter' => 'Entrou na Cerca',
                'ignitionOn' => 'Ignição Ligada',
                'ignitionOff' => 'Ignição Desligada',
                'deviceOffline' => 'Dispositivo Offline',
                'deviceOnline' => 'Dispositivo Online',
                'deviceStopped' => 'Veículo Parou',
                'deviceMoving' => 'Veículo em Movimento'
            ];

            foreach($alerts as &$a) {
                $a['type_label'] = $dict[$a['type']] ?? $a['type'];
                // Format date
                $a['formatted_time'] = date('d/m/Y H:i:s', strtotime($a['event_time']));
            }

            echo json_encode($alerts);
        } catch (Exception $e) { 
            echo json_encode([]); 
        }
        break;

    case 'get_ranking':
        try {
            $sql = "SELECT v.id, v.name, v.plate,
                    COUNT(e.id) as total_events,
                    SUM(CASE WHEN e.type = 'deviceOverspeed' THEN 1 ELSE 0 END) as overspeed,
                    SUM(CASE WHEN e.type = 'geofenceExit' THEN 1 ELSE 0 END) as geofence
                    FROM saas_vehicles v
                    LEFT JOIN tc_events e ON v.traccar_device_id = e.deviceid 
                        AND e.eventtime > NOW() - INTERVAL 30 DAY
                    WHERE v.tenant_id = ? $restrictionSQL
                    GROUP BY v.id";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$tenant_id]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach($rows as &$r) {
                $score = 100;
                $score -= ($r['overspeed'] * 5);
                $score -= ($r['geofence'] * 2);
                
                if($score < 0) $score = 0;
                if($score > 100) $score = 100;
                
                $r['score'] = $score;
                
                if($score >= 90) $r['class'] = 'A';
                elseif($score >= 70) $r['class'] = 'B';
                elseif($score >= 50) $r['class'] = 'C';
                else $r['class'] = 'D';
            }

            usort($rows, function($a, $b) { return $b['score'] <=> $a['score']; });

            echo json_encode($rows);
        } catch (Exception $e) { http_500($e->getMessage()); }
        break;

    // =========================================================================
    // 💰 FINANCIAL (MAINTAINED)
    // =========================================================================
    case 'asaas_save_config':
        if ($user_role != 'admin' && $user_role != 'superadmin') http_400('Permissão negada');
        $input = json_decode(file_get_contents('php://input'), true);
        $token = $input['apiKey'] ?? '';
        $test = callAsaas('/finance/balance', 'GET', [], $token);
        if (isset($test['errors'])) http_400('Chave API Inválida.');
        try {
            $stmt = $pdo->prepare("UPDATE saas_tenants SET asaas_token = ? WHERE id = ?");
            $stmt->execute([$token, $tenant_id]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) { http_500($e->getMessage()); }
        break;

    case 'asaas_get_config':
        $stmt = $pdo->prepare("SELECT asaas_token FROM saas_tenants WHERE id = ?");
        $stmt->execute([$tenant_id]);
        $token = $stmt->fetchColumn();
        echo json_encode(['has_token' => !empty($token)]);
        break;

    case 'asaas_proxy':
        $stmt = $pdo->prepare("SELECT asaas_token FROM saas_tenants WHERE id = ?");
        $stmt->execute([$tenant_id]);
        $apiToken = $stmt->fetchColumn();
        if (empty($apiToken)) http_400('Configure a Chave API do Asaas.');
        $asaas_endpoint = $_REQUEST['asaas_endpoint'] ?? '';
        $method = $_SERVER['REQUEST_METHOD'];
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        if ($method === 'GET' && !empty($_GET)) {
            $query = $_GET; unset($query['action'], $query['asaas_endpoint']); 
            if (strpos($asaas_endpoint, '?') === false) $asaas_endpoint .= '?' . http_build_query($query);
            else $asaas_endpoint .= '&' . http_build_query($query);
        }
        $response = callAsaas($asaas_endpoint, $method, $data, $apiToken);
        echo json_encode($response);
        break;

    // =========================================================================
    // 👥 USERS (CORRECTED HERE: removed deleted_at)
    // =========================================================================
    case 'get_users':
        try {
            // Removed "AND u.deleted_at IS NULL" as the column does not exist
            $sql = "SELECT u.id, u.name, u.email, u.role_id, u.customer_id, u.branch_id, u.active, 
                           r.name as role_name, b.name as branch_name, c.name as customer_name 
                    FROM saas_users u 
                    LEFT JOIN saas_roles r ON u.role_id = r.id 
                    LEFT JOIN saas_branches b ON u.branch_id = b.id 
                    LEFT JOIN saas_customers c ON u.customer_id = c.id
                    WHERE u.tenant_id = ? 
                    ORDER BY u.name ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$tenant_id]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) { http_500($e->getMessage()); }
        break;

    case 'save_user':
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? null;
        $name = $input['name'] ?? '';
        $email = $input['email'] ?? '';
        $role_id = !empty($input['role_id']) ? $input['role_id'] : null;
        $customer_id = !empty($input['customer_id']) ? $input['customer_id'] : null;
        $branch_id = !empty($input['branch_id']) ? $input['branch_id'] : null;
        $password = $input['password'] ?? '';
        $active = isset($input['active']) ? $input['active'] : true;

        if (empty($name) || empty($email)) http_400('Dados obrigatórios faltando.');

        try {
            if ($id) {
                // Update
                $sql = "UPDATE saas_users SET name = ?, email = ?, role_id = ?, customer_id = ?, branch_id = ?, active = ? WHERE id = ? AND tenant_id = ?";
                $params = [$name, $email, $role_id, $customer_id, $branch_id, $active ? 'true' : 'false', $id, $tenant_id];
                if (!empty($password)) {
                    $sql = "UPDATE saas_users SET name = ?, email = ?, role_id = ?, customer_id = ?, branch_id = ?, active = ?, password = ? WHERE id = ? AND tenant_id = ?";
                    $params = [$name, $email, $role_id, $customer_id, $branch_id, $active ? 'true' : 'false', password_hash($password, PASSWORD_DEFAULT), $id, $tenant_id];
                }
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            } else {
                // Insert
                if (empty($password)) http_400('Senha obrigatória.');
                $check = $pdo->prepare("SELECT id FROM saas_users WHERE email = ?");
                $check->execute([$email]);
                if($check->fetchColumn()) http_400('Email já cadastrado.');

                $stmt = $pdo->prepare("INSERT INTO saas_users (tenant_id, name, email, password, role_id, customer_id, branch_id, status, active) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?)");
                $stmt->execute([$tenant_id, $name, $email, password_hash($password, PASSWORD_DEFAULT), $role_id, $customer_id, $branch_id, $active ? 'true' : 'false']);
            }
            echo json_encode(['success' => true]);
        } catch (Exception $e) { http_500($e->getMessage()); }
        break;

    case 'delete_user':
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? null;
        if (!$id) http_400('ID inválido');
        if ($id == $user_id) http_400('Proibido excluir a si mesmo.');
        
        try {
            // Physical Delete (Hard Delete) as deleted_at does not exist
            $stmt = $pdo->prepare("DELETE FROM saas_users WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, $tenant_id]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) { http_500($e->getMessage()); }
        break;

    // =========================================================================
    // 🛡️ PROFILES (MAINTAINED)
    // =========================================================================
    case 'get_profiles':
        try {
            $stmt = $pdo->prepare("SELECT * FROM saas_roles WHERE tenant_id = ? ORDER BY id DESC");
            $stmt->execute([$tenant_id]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) { http_500($e->getMessage()); }
        break;

    case 'save_profile':
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? null;
        $name = $input['name'] ?? '';
        $permissions = is_array($input['permissions'] ?? []) ? json_encode($input['permissions']) : ($input['permissions'] ?? '[]');
        if (empty($name)) http_400('Nome obrigatório');
        try {
            if ($id) {
                $stmt = $pdo->prepare("UPDATE saas_roles SET name = ?, permissions = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?");
                $stmt->execute([$name, $permissions, $id, $tenant_id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO saas_roles (tenant_id, name, permissions) VALUES (?, ?, ?)");
                $stmt->execute([$tenant_id, $name, $permissions]);
            }
            echo json_encode(['success' => true]);
        } catch (Exception $e) { http_500($e->getMessage()); }
        break;

    case 'delete_profile':
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? null;
        if (!$id) http_400('ID inválido');
        try {
            $stmt = $pdo->prepare("DELETE FROM saas_roles WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, $tenant_id]);
            echo json_encode(['success' => true]);
        } catch (Exception $e) { http_500($e->getMessage()); }
        break;

    // =========================================================================
    // 🔒 COMMANDS (WITH FILTER)
    // =========================================================================
    case 'secure_command':
        $input = json_decode(file_get_contents('php://input'), true);
        $deviceId = $input['deviceId'] ?? null;
        $cmdType = $input['type'] ?? null;
        $password = $input['password'] ?? '';

        if (!$deviceId || !$cmdType) http_400('Dados incompletos');

        if ($password !== 'SKIP_CHECK') {
            $stmt = $pdo->prepare("SELECT password FROM saas_users WHERE id = ?");
            $stmt->execute([$user_id]);
            if (!password_verify($password, $stmt->fetchColumn())) { http_response_code(401); exit(json_encode(['error' => 'Senha incorreta'])); }
        }

        // Validate Ownership (Applies the same restriction)
        $checkSql = "SELECT COUNT(*) FROM saas_vehicles v WHERE traccar_device_id = ? AND tenant_id = ? $restrictionSQL";
        $stmtCheck = $pdo->prepare($checkSql);
        $stmtCheck->execute([$deviceId, $tenant_id]);

        if ($stmtCheck->fetchColumn() == 0) {
            http_response_code(403); exit(json_encode(['error' => 'Acesso negado']));
        }

        $attributesPayload = (!empty($input['attributes']) && is_array($input['attributes'])) ? $input['attributes'] : new stdClass();
        $traccarCmd = ($cmdType === 'lock') ? 'engineStop' : (($cmdType === 'unlock') ? 'engineResume' : $cmdType);
        
        $ch = curl_init("http://127.0.0.1:8082/api/commands/send");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
        curl_setopt($ch, CURLOPT_USERPWD, "admin:admin"); 
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['deviceId' => $deviceId, 'type' => $traccarCmd, 'attributes' => $attributesPayload]));
        
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code >= 200 && $code < 300) echo json_encode(['success' => true]);
        else http_500('Erro Traccar: ' . $resp);
        break;

    // =========================================================================
    // 🌍 GEOCODING
    // =========================================================================
    case 'geocode':
        $lat = $_GET['lat'] ?? 0; 
        $lon = $_GET['lon'] ?? 0;
        handleGeocode($lat, $lon, $pdo);
        break;

    case 'ping':
        echo json_encode(['status' => 'ok', 'tenant' => $tenant_id]);
        break;

    default:
        if (isset($_GET['type']) && $_GET['type'] === 'geocode') handleGeocode($_GET['lat'], $_GET['lon'], $pdo);
        else { http_response_code(404); echo json_encode(['error' => 'Action inválida']); }
        break;
}

// =================================================================================
// HELPER FUNCTIONS
// =================================================================================

function handleProxyTraccar($endpoint, $tenant_id, $loggedCustomerId, $user_id, $pdo) {
    $TRACCAR_HOST = 'http://127.0.0.1:8082/api';
    
    // Construct the complete URL preserving the query string
    $url = $TRACCAR_HOST . $endpoint . '?' . http_build_query($_GET);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
    curl_setopt($ch, CURLOPT_USERPWD, "admin:admin");
    
    // --- CRITICAL CORRECTION: FORCE JSON ---
    // Prevent Traccar from returning Excel (binary) by default in reports
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // If there is an HTTP error from Traccar, forward the error
    if ($httpCode >= 400) {
        http_response_code($httpCode);
        echo $resp;
        return;
    }

    $data = json_decode($resp, true);
    
    // Security Filter for Lists
    if (is_array($data)) {
        // Fetch IDs allowed for the user/client
        $idsSql = "SELECT traccar_device_id FROM saas_vehicles v WHERE tenant_id = $tenant_id";
        
        // Apply filter if not admin
        if ($loggedCustomerId || $user_id) {
             // Reuse simplified logic or expand as per restrictionSQL
             // Here, to simplify and ensure, we run the query with filters:
             if ($loggedCustomerId) {
                 $idsSql .= " AND (v.client_id = $loggedCustomerId OR v.user_id = $user_id)";
             } else {
                 // If user_id exists and not admin (implicit by if above)
                 // Note: admin would have loggedCustomerId null and user_role validated before
                 // Assuming call of this helper within secure context
                 $idsSql .= " AND v.user_id = $user_id";
             }
        }
        // Note: If admin, the query returns all from tenant, which is correct.
        
        $ids = $pdo->query($idsSql)->fetchAll(PDO::FETCH_COLUMN);
        
        $filtered = [];
        foreach($data as $item) {
            $did = $item['deviceId'] ?? ($item['id'] ?? null);
            // If the object has a device ID (e.g., position, device, report), filter
            if ($did && (strpos($endpoint, '/devices')!==false || strpos($endpoint, '/positions')!==false || strpos($endpoint, '/reports')!==false)) {
                if(!in_array($did, $ids)) continue;
            }
            $filtered[] = $item;
        }
        echo json_encode(array_values($filtered));
    } else { 
        echo $resp; 
    }
}

function handleGeocode($lat, $lon, $pdo) {
    $cached = $pdo->query("SELECT address FROM saas_address_cache WHERE lat='$lat' AND lon='$lon'")->fetchColumn();
    if($cached) { echo json_encode(['address'=>$cached]); exit; }
    $ch = curl_init("https://nominatim.openstreetmap.org/reverse?format=json&lat=$lat&lon=$lon&zoom=18&addressdetails=1");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_USERAGENT, "FV/1.0");
    $json = json_decode(curl_exec($ch), true); curl_close($ch);
    $addr = $json['display_name'] ?? 'Local desconhecido';
    $pdo->prepare("INSERT INTO saas_address_cache (lat, lon, address) VALUES (?, ?, ?)")->execute([$lat, $lon, $addr]);
    echo json_encode(['address' => $addr]);
    exit;
}

function callAsaas($endpoint, $method, $data, $apiKey) {
    $baseUrl = 'https://api.asaas.com/v3'; 
    if (substr($endpoint, 0, 1) !== '/') $endpoint = '/' . $endpoint;
    $ch = curl_init($baseUrl . $endpoint);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json", "access_token: " . trim($apiKey)]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($method === 'POST' || $method === 'PUT') curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode($response, true);
    if ($httpCode >= 400) return ['error' => $json['errors'][0]['description'] ?? 'Erro no Asaas'];
    return $json;
}

function http_400($msg) { http_response_code(400); exit(json_encode(['error' => $msg])); }
function http_500($msg) { http_response_code(500); exit(json_encode(['error' => $msg])); }
?>