<?php
/**
 * FinancialController - Gestão Financeira e Integração Asaas
 * Responsável por configurar chaves, proxy de API e gestão de faturas.
 */

// Proteção contra acesso direto
if (count(get_included_files()) == 1) exit('Acesso direto não permitido');

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../db.php';

class FinancialController {
    private $pdo;
    private $tenantId;

    // URL base da API do Asaas (Sandbox ou Produção)
    // Para produção use: https://api.asaas.com/v3
    // Para sandbox use: https://sandbox.asaas.com/api/v3
    const ASAAS_BASE_URL = 'https://api.asaas.com/v3';

    public function __construct() {
        $this->pdo = Database::getInstance()->getConnection();
        $this->tenantId = $_SESSION['tenant_id'] ?? null;
    }

    /**
     * Salva a Chave de API do Asaas no banco
     * Apenas ADMIN pode fazer isso.
     */
    public function saveConfig($apiKey, $userRole) {
        if ($userRole !== 'admin' && $userRole !== 'superadmin') {
            http_response_code(403);
            return ['success' => false, 'error' => 'Permissão negada. Apenas administradores podem configurar o financeiro.'];
        }

        if (empty($apiKey)) {
            return ['success' => false, 'error' => 'A Chave API é obrigatória.'];
        }

        // Testa a chave antes de salvar (Chamada leve ao endpoint de saldo)
        $test = $this->makeAsaasRequest('/finance/balance', 'GET', [], $apiKey);
        
        if (isset($test['errors'])) {
            return ['success' => false, 'error' => 'Chave API Inválida: ' . ($test['errors'][0]['description'] ?? 'Erro desconhecido')];
        }

        try {
            $stmt = $this->pdo->prepare("UPDATE saas_tenants SET asaas_token = ? WHERE id = ?");
            $stmt->execute([$apiKey, $this->tenantId]);
            return ['success' => true, 'message' => 'Configuração salva com sucesso!'];
        } catch (Exception $e) {
            error_log("Erro Financial::saveConfig: " . $e->getMessage());
            return ['success' => false, 'error' => 'Erro ao salvar no banco de dados.'];
        }
    }

    /**
     * Verifica se existe uma chave configurada (sem retornar a chave real por segurança)
     */
    public function getConfigStatus() {
        try {
            $stmt = $this->pdo->prepare("SELECT asaas_token FROM saas_tenants WHERE id = ?");
            $stmt->execute([$this->tenantId]);
            $token = $stmt->fetchColumn();
            
            return ['success' => true, 'has_token' => !empty($token)];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Erro ao verificar configuração.'];
        }
    }

    /**
     * Proxy para chamadas do Frontend ao Asaas
     * O frontend pede, o backend busca a chave e repassa a chamada.
     */
    public function proxyRequest($endpoint, $method = 'GET', $data = []) {
        // 1. Busca a chave no banco
        $stmt = $this->pdo->prepare("SELECT asaas_token FROM saas_tenants WHERE id = ?");
        $stmt->execute([$this->tenantId]);
        $apiToken = $stmt->fetchColumn();

        if (empty($apiToken)) {
            http_response_code(400);
            return ['success' => false, 'error' => 'Integração Asaas não configurada. Acesse as configurações.'];
        }

        // 2. Limpa e valida o endpoint
        // Remove parâmetros de query que já foram processados pelo router (como ?action=...)
        // Mas se o endpoint original tinha query string (ex: /payments?limit=10), precisamos manter/reconstruir
        
        // Se for GET, os parâmetros vieram na URL principal ($_GET), precisamos filtrar
        if ($method === 'GET' && !empty($_GET)) {
            $query = $_GET;
            // Remove chaves internas do nosso router
            unset($query['action'], $query['asaas_endpoint'], $query['endpoint']);
            
            if (!empty($query)) {
                $separator = (strpos($endpoint, '?') === false) ? '?' : '&';
                $endpoint .= $separator . http_build_query($query);
            }
        }

        // 3. Faz a chamada real
        return $this->makeAsaasRequest($endpoint, $method, $data, $apiToken);
    }

    /**
     * Função interna para realizar a requisição cURL
     */
    private function makeAsaasRequest($endpoint, $method, $data, $apiKey) {
        // Garante barra inicial
        if (substr($endpoint, 0, 1) !== '/') {
            $endpoint = '/' . $endpoint;
        }

        $url = self::ASAAS_BASE_URL . $endpoint;
        
        $ch = curl_init($url);
        
        $headers = [
            "Content-Type: application/json",
            "access_token: " . trim($apiKey),
            "User-Agent: FleetVision-SaaS/1.0"
        ];

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        
        // Timeout para não travar o PHP
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if (($method === 'POST' || $method === 'PUT') && !empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        
        curl_close($ch);

        if ($curlError) {
            error_log("Erro cURL Asaas: $curlError");
            return ['success' => false, 'error' => 'Falha na comunicação com o Gateway de Pagamento.'];
        }

        $json = json_decode($response, true);

        // Se o Asaas retornou erro HTTP (4xx, 5xx)
        if ($httpCode >= 400) {
            $msg = $json['errors'][0]['description'] ?? 'Erro desconhecido no Asaas.';
            return ['success' => false, 'error' => $msg, 'http_code' => $httpCode];
        }

        return $json;
    }
}
?>