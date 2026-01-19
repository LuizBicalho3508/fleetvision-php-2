<?php
/**
 * DashboardController - Gerencia dados do Painel Principal
 * Responsável por KPIs, Lista de Veículos e Status da Frota
 */

// Proteção contra acesso direto
if (count(get_included_files()) == 1) exit('Acesso direto não permitido');

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../db.php';

class DashboardController {
    private $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance()->getConnection();
    }

    /**
     * Retorna os KPIs (Indicadores) do Dashboard
     * Otimizado para fazer apenas 1 consulta ao banco em vez de 3
     */
    public function getKPIs($tenantId, $userId, $userRole, $userEmail) {
        try {
            // 1. Obtém filtros de segurança (ACL)
            $acl = $this->getVehicleFilters($tenantId, $userId, $userRole, $userEmail);
            
            // 2. Query Otimizada (Single Pass)
            // Calcula Total, Online e Movimento em uma única passagem
            $sql = "
                SELECT 
                    COUNT(v.id) as total,
                    COUNT(CASE 
                        WHEN d.lastupdate > NOW() - INTERVAL '10 minutes' THEN 1 
                        ELSE NULL 
                    END) as online,
                    COUNT(CASE 
                        WHEN d.lastupdate > NOW() - INTERVAL '10 minutes' AND p.speed > 1 THEN 1 
                        ELSE NULL 
                    END) as moving
                FROM saas_vehicles v
                LEFT JOIN tc_devices d ON v.traccar_device_id = d.id
                LEFT JOIN tc_positions p ON d.positionid = p.id
                WHERE v.status = 'active' 
                {$acl['sql']}
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($acl['params']);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            // 3. Cálculos Derivados (Matemática simples no PHP é mais rápida que no DB)
            $total  = (int)$result['total'];
            $online = (int)$result['online'];
            $moving = (int)$result['moving'];
            
            $stopped = max(0, $online - $moving);
            $offline = max(0, $total - $online);

            return [
                'success' => true,
                'data' => [
                    'total_vehicles' => $total,
                    'online'         => $online,
                    'moving'         => $moving,
                    'stopped'        => $stopped,
                    'offline'        => $offline,
                    // Futuro: Implementar cálculo real de distância se necessário
                    'total_distance_today' => 0 
                ]
            ];

        } catch (Exception $e) {
            error_log("Erro Dashboard::getKPIs: " . $e->getMessage());
            return ['success' => false, 'error' => 'Erro ao carregar indicadores.'];
        }
    }

    /**
     * Retorna a lista detalhada de veículos (Tabela/Mapa)
     */
    public function getVehicleList($tenantId, $userId, $userRole, $userEmail, $filterType = 'online') {
        try {
            // 1. Obtém filtros de segurança
            $acl = $this->getVehicleFilters($tenantId, $userId, $userRole, $userEmail);
            
            // 2. Monta a Query Principal
            $sql = "
                SELECT 
                    v.id, 
                    v.name, 
                    v.plate, 
                    v.traccar_device_id as deviceid, 
                    t.lastupdate, 
                    t.positionid, 
                    p.speed, 
                    p.address,
                    p.latitude,
                    p.longitude,
                    p.attributes
                FROM saas_vehicles v 
                LEFT JOIN tc_devices t ON v.traccar_device_id = t.id 
                LEFT JOIN tc_positions p ON t.positionid = p.id
                WHERE v.status = 'active' 
                {$acl['sql']}
            ";

            // 3. Aplica Filtros de Visualização (Online/Offline/All)
            if ($filterType === 'offline') {
                $sql .= " AND (t.lastupdate < NOW() - INTERVAL '24 hours' OR t.lastupdate IS NULL)";
            } elseif ($filterType === 'online') {
                // Consideramos "Online" para a lista qualquer um que comunicou nas últimas 24h
                // Para KPI usamos 10min (tempo real), para lista usamos 24h (ativos recentes)
                $sql .= " AND t.lastupdate >= NOW() - INTERVAL '24 hours'";
            }

            $sql .= " ORDER BY t.lastupdate DESC NULLS LAST";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($acl['params']);
            $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 4. Processamento de Dados (Formatter)
            foreach ($vehicles as &$v) {
                // Converte Nós para Km/h
                $v['speed_kmh'] = round(($v['speed'] ?? 0) * 1.852);
                
                // Formata Data
                $v['last_comm'] = $v['lastupdate'] 
                    ? date('d/m/Y H:i', strtotime($v['lastupdate'])) 
                    : 'Sem comunicação';
                
                // Status Humanizado
                $isOnline = $v['lastupdate'] && (strtotime($v['lastupdate']) > (time() - 600)); // 10 min
                $isMoving = $v['speed_kmh'] > 1;
                
                if (!$isOnline) {
                    $v['status_label'] = 'Offline';
                    $v['status_color'] = 'gray';
                } elseif ($isMoving) {
                    $v['status_label'] = 'Em Movimento';
                    $v['status_color'] = 'green';
                } else {
                    $v['status_label'] = 'Parado';
                    $v['status_color'] = 'blue';
                }
                
                // Limpeza de JSON (Atributos do Traccar vêm como JSON string)
                if (isset($v['attributes']) && is_string($v['attributes'])) {
                    $v['attributes'] = json_decode($v['attributes'], true);
                }
            }

            return ['success' => true, 'data' => $vehicles];

        } catch (Exception $e) {
            error_log("Erro Dashboard::getVehicleList: " . $e->getMessage());
            return ['success' => false, 'error' => 'Erro ao carregar lista de veículos.'];
        }
    }

    /**
     * HELPER: Gera as cláusulas WHERE baseadas no perfil do usuário (ACL)
     * Centraliza a lógica de "quem pode ver o que".
     */
    private function getVehicleFilters($tenantId, $userId, $userRole, $userEmail) {
        $sql = " AND v.tenant_id = :tenant_id ";
        $params = ['tenant_id' => $tenantId];

        // Se for Admin ou Superadmin, vê tudo do tenant
        if ($userRole === 'admin' || $userRole === 'superadmin') {
            return ['sql' => $sql, 'params' => $params];
        }

        // --- Lógica para Usuário Comum ---
        
        // 1. Verifica se tem cliente vinculado na tabela saas_users
        $stmt = $this->pdo->prepare("SELECT customer_id FROM saas_users WHERE id = ?");
        $stmt->execute([$userId]);
        $customerId = $stmt->fetchColumn();

        // 2. Fallback: Verifica vínculo por email na tabela saas_customers
        if (!$customerId && !empty($userEmail)) {
            $stmtEmail = $this->pdo->prepare("SELECT id FROM saas_customers WHERE email = ? AND tenant_id = ?");
            $stmtEmail->execute([$userEmail, $tenantId]);
            $customerId = $stmtEmail->fetchColumn();
        }

        if ($customerId) {
            // Usuário vinculado a um Cliente: Vê carros do cliente OU carros atribuídos diretamente a ele
            $sql .= " AND (v.client_id = :customer_id OR v.user_id = :user_id) ";
            $params['customer_id'] = $customerId;
            $params['user_id'] = $userId;
        } else {
            // Usuário Avulso: Vê apenas carros atribuídos diretamente a ele
            $sql .= " AND v.user_id = :user_id ";
            $params['user_id'] = $userId;
        }

        return ['sql' => $sql, 'params' => $params];
    }
}
?>