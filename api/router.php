<?php
/**
 * Router Central da API
 */

// 1. Inicia Sessão (Padronizada)
require_once __DIR__ . '/../config/session.php';

// Oculta erros do PHP na saída JSON
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Define cabeçalhos JSON e CORS
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); 
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 2. Carrega Configurações e DB
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../db.php';

// 3. Carrega Controllers
// Ajuste os caminhos conforme sua estrutura real
require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/DashboardController.php';
require_once __DIR__ . '/controllers/FinancialController.php';
require_once __DIR__ . '/controllers/TraccarProxy.php';
require_once __DIR__ . '/controllers/ManagementController.php';
require_once __DIR__ . '/controllers/DeviceController.php';

$action = $_REQUEST['action'] ?? '';
$endpoint = $_REQUEST['endpoint'] ?? ''; 

// 4. Rota de Login (Pública)
if ($action === 'login') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) $input = $_POST;
    
    $email = $input['email'] ?? '';
    $password = $input['password'] ?? '';
    
    $auth = new AuthController();
    echo json_encode($auth->login($email, $password));
    exit;
}

// 5. Verifica Sessão
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Sessão expirada.']);
    exit;
}

// Contexto do Usuário
$tenantId  = $_SESSION['tenant_id'];
$userId    = $_SESSION['user_id'];
$userRole  = $_SESSION['user_role'] ?? 'user';
$userEmail = $_SESSION['user_email'] ?? '';

// 6. Proxy Traccar
if (!empty($endpoint)) {
    $proxy = new TraccarProxy();
    $proxy->handleRequest($endpoint);
    exit;
}

// 7. Rotas da API
try {
    switch ($action) {
        case 'logout':
            $auth = new AuthController();
            echo json_encode(['success' => true, 'redirect' => $auth->logout()]);
            break;
        case 'update_profile':
            $auth = new AuthController();
            echo json_encode($auth->updateProfile($userId, $_POST, $_FILES));
            break;
        case 'get_kpis':
            $dash = new DashboardController();
            $result = $dash->getKPIs($tenantId, $userId, $userRole, $userEmail);
            echo json_encode($result['data'] ?? $result);
            break;
        case 'get_dashboard_data':
            $type = $_REQUEST['type'] ?? 'online';
            $dash = new DashboardController();
            $result = $dash->getVehicleList($tenantId, $userId, $userRole, $userEmail, $type);
            echo json_encode($result['data'] ?? []);
            break;
        case 'get_users':
            $mgmt = new ManagementController();
            echo json_encode($mgmt->getUsers($tenantId));
            break;
        case 'save_user':
            $mgmt = new ManagementController();
            $input = json_decode(file_get_contents('php://input'), true);
            echo json_encode($mgmt->saveUser($tenantId, $input));
            break;
        case 'delete_user':
            $mgmt = new ManagementController();
            $input = json_decode(file_get_contents('php://input'), true);
            echo json_encode($mgmt->deleteUser($tenantId, $input['id'] ?? 0, $userId));
            break;
        case 'get_profiles':
            $mgmt = new ManagementController();
            echo json_encode($mgmt->getProfiles($tenantId));
            break;
        case 'save_profile':
            $mgmt = new ManagementController();
            $input = json_decode(file_get_contents('php://input'), true);
            echo json_encode($mgmt->saveProfile($tenantId, $input));
            break;
        case 'delete_profile':
            $mgmt = new ManagementController();
            $input = json_decode(file_get_contents('php://input'), true);
            echo json_encode($mgmt->deleteProfile($tenantId, $input['id'] ?? 0));
            break;
        case 'secure_command':
            $dev = new DeviceController();
            $input = json_decode(file_get_contents('php://input'), true);
            $result = $dev->sendCommand($tenantId, $userId, $input);
            if (!$result['success']) http_response_code($result['http_code'] ?? 400);
            echo json_encode($result);
            break;
        case 'geocode':
            $dev = new DeviceController();
            $lat = $_GET['lat'] ?? 0;
            $lon = $_GET['lon'] ?? 0;
            echo json_encode($dev->geocode($lat, $lon));
            break;
        case 'get_ranking':
             $dev = new DeviceController();
             echo json_encode($dev->getRanking($tenantId));
             break;
        case 'asaas_save_config':
            $fin = new FinancialController();
            $input = json_decode(file_get_contents('php://input'), true);
            echo json_encode($fin->saveConfig($input['apiKey'] ?? '', $userRole));
            break;
        case 'asaas_get_config':
            $fin = new FinancialController();
            echo json_encode($fin->getConfigStatus());
            break;
        case 'asaas_proxy':
            $fin = new FinancialController();
            $endpoint = $_REQUEST['asaas_endpoint'] ?? '';
            $method = $_SERVER['REQUEST_METHOD'];
            $data = json_decode(file_get_contents('php://input'), true) ?? [];
            $result = $fin->proxyRequest($endpoint, $method, $data);
            if (isset($result['http_code'])) http_response_code($result['http_code']);
            echo json_encode($result);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Ação inválida: ' . $action]);
            break;
    }
} catch (Exception $e) {
    error_log("Erro API: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno.']);
}
?>