<?php
/**
 * TraccarProxy - Intermediário Seguro para API do Traccar
 * Responsável por autenticação, proxy de requisições e filtragem de dados (ACL).
 */

// Proteção contra acesso direto
if (count(get_included_files()) == 1) exit('Acesso direto não permitido');

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../db.php';

class TraccarProxy {
    private $pdo;
    private $tenantId;
    private $userId;
    private $userRole;
    private $userEmail;

    public function __construct() {
        $this->pdo = Database::getInstance()->getConnection();
        
        // Carrega contexto da sessão
        $this->tenantId  = $_SESSION['tenant_id'] ?? null;
        $this->userId    = $_SESSION['user_id'] ?? null;
        $this->userRole  = $_SESSION['user_role'] ?? 'user';
        $this->userEmail = $_SESSION['user_email'] ?? '';

        if (!$this->userId) {
            http_response_code(403);
            exit(json_encode(['error' => 'Sessão expirada.']));
        }
    }

    /**
     * Processa a requisição Proxy
     * @param string $endpoint O endpoint do Traccar (ex: /positions, /reports/route)
     */
    public function handleRequest($endpoint) {
        // 1. Segurança Básica de Endpoints
        // Bloqueia tentativas de acessar configurações do servidor Traccar via proxy
        $blocked = ['server', 'users', 'permissions', 'notifications'];
        foreach ($blocked as $b) {
            if (strpos($endpoint, "/$b") === 0 && $this->userRole !== 'superadmin') {
                http_response_code(403);
                exit(json_encode(['error' => 'Acesso negado a este endpoint.']));
            }
        }

        // 2. Prepara URL e Query String
        // Remove parâmetros internos do nosso router para não enviar lixo ao Traccar
        $queryParams = $_GET;
        unset($queryParams['endpoint'], $queryParams['action'], $queryParams['p']);
        
        $url = TRACCAR_API_URL . $endpoint;
        if (!empty($queryParams)) {
            $url .= '?' . http_build_query($queryParams);
        }

        // 3. Executa a Requisição
        $response = $this->makeRequest($url);

        // 4. Processa e Filtra a Resposta
        // Se for JSON válido e for uma lista, aplica o filtro de segurança
        $data = json_decode($response['body'], true);

        if ($response['http_code'] >= 400) {
            http_response_code($response['http_code']);
            echo $response['body']; // Repassa o erro do Traccar
            return;
        }

        if (is_array($data)) {
            // Filtra os dados para garantir que o usuário só veja o que pode
            $filteredData = $this->filterData($data, $endpoint);
            echo json_encode(array_values($filteredData));
        } else {
            // Se não for array (ex: objeto único ou booleano), retorna direto
            // (A menos que seja binário, mas forçamos JSON no header)
            echo $response['body'];
        }
    }

    /**
     * Executa o cURL com credenciais de Admin
     */
    private function makeRequest($url) {
        $ch = curl_init($url);
        
        // Autenticação Admin (definida no config/app.php)
        curl_setopt($ch, CURLOPT_USERPWD, TRACCAR_ADMIN_USER . ":" . TRACCAR_ADMIN_PASS);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        // HEADERS CRÍTICOS
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json', // Força JSON (evita Excel/CSV)
            'Content-Type: application/json'
        ]);

        // Repassa Método (GET, POST, PUT, DELETE)
        $method = $_SERVER['REQUEST_METHOD'];
        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            $inputData = file_get_contents('php://input');
            if (!empty($inputData)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $inputData);
            }
        }

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['body' => $body, 'http_code' => $httpCode];
    }

    /**
     * Filtra os dados retornados baseados nas permissões do usuário (ACL)
     */
    private function filterData($data, $endpoint) {
        // Se for Admin/Superadmin, vê tudo do Tenant
        // (Nota: Em um ambiente multi-tenant real, o Admin do Traccar vê tudo globalmente. 
        // O filtro abaixo restringe ao Tenant atual mesmo para admins, o que é bom).
        
        // Busca a lista de IDs de dispositivos permitidos para este usuário neste tenant
        $allowedIds = $this->getAllowedDeviceIds();

        $filtered = [];
        $endpointsToFilter = ['/devices', '/positions', '/reports', '/events', '/stops', '/trips', '/routes'];
        
        $shouldFilter = false;
        foreach ($endpointsToFilter as $needle) {
            if (strpos($endpoint, $needle) !== false) {
                $shouldFilter = true;
                break;
            }
        }

        if (!$shouldFilter) {
            return $data; // Retorna tudo se não for um endpoint sensível mapeado
        }

        foreach ($data as $item) {
            // Tenta identificar o ID do dispositivo no objeto
            $deviceId = $item['deviceId'] ?? ($item['id'] ?? null);

            // Se o objeto tem deviceId, verificamos se está na lista permitida
            if ($deviceId) {
                // Endpoint /devices retorna o ID no campo 'id', outros no 'deviceId'
                // Se o endpoint for /devices, o $deviceId é o próprio ID do item.
                // Se for /positions, o deviceId é a chave estrangeira.
                
                // Validação:
                // Se for a lista de devices, o ID do item deve estar na whitelist
                // Se for lista de posições/relatórios, o deviceId do item deve estar na whitelist
                if (in_array($deviceId, $allowedIds)) {
                    $filtered[] = $item;
                }
            } else {
                // Se não tem ID associado (ex: dados de servidor), deixa passar
                $filtered[] = $item;
            }
        }

        return $filtered;
    }

    /**
     * Consulta no banco local quais IDs do Traccar este usuário pode ver
     */
    private function getAllowedDeviceIds() {
        $sql = "SELECT traccar_device_id FROM saas_vehicles v WHERE tenant_id = :tenant_id";
        $params = ['tenant_id' => $this->tenantId];

        if ($this->userRole !== 'admin' && $this->userRole !== 'superadmin') {
            // Lógica de ACL para usuário comum
            $stmtCheck = $this->pdo->prepare("SELECT customer_id FROM saas_users WHERE id = ?");
            $stmtCheck->execute([$this->userId]);
            $customerId = $stmtCheck->fetchColumn();

            if (!$customerId && !empty($this->userEmail)) {
                $stmtEmail = $this->pdo->prepare("SELECT id FROM saas_customers WHERE email = ? AND tenant_id = ?");
                $stmtEmail->execute([$this->userEmail, $this->tenantId]);
                $customerId = $stmtEmail->fetchColumn();
            }

            if ($customerId) {
                $sql .= " AND (v.client_id = :client_id OR v.user_id = :user_id)";
                $params['client_id'] = $customerId;
                $params['user_id'] = $this->userId;
            } else {
                $sql .= " AND v.user_id = :user_id";
                $params['user_id'] = $this->userId;
            }
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
?>