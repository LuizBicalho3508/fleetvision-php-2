<?php
// api_login.php - Endpoint de Autenticação para Mobile/App

// 1. Configurações de Cabeçalho (CORS e JSON)
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Em produção, troque * pelo domínio do seu app
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Tratamento para pre-flight request (OPTIONS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 2. Conexão com Banco
require 'db.php';

// 3. Processamento do Login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Lê o JSON enviado pelo App
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);

        // Validação básica
        if (empty($input['email']) || empty($input['password'])) {
            throw new Exception("Email e senha são obrigatórios.", 400);
        }

        $email = trim($input['email']);
        $password = $input['password'];
        $tenantSlug = $input['tenant'] ?? 'admin'; // Opcional: slug da empresa

        // Busca o Tenant (Empresa) para garantir contexto
        $stmtTenant = $pdo->prepare("SELECT id FROM saas_tenants WHERE slug = ? LIMIT 1");
        $stmtTenant->execute([$tenantSlug]);
        $tenantId = $stmtTenant->fetchColumn();

        if (!$tenantId) {
            // Tenta fallback para o admin se não achou
            $stmtTenant->execute(['admin']);
            $tenantId = $stmtTenant->fetchColumn();
        }

        // Busca o Usuário
        $stmtUser = $pdo->prepare("SELECT id, name, password, role, active, customer_id FROM saas_users WHERE email = ? AND tenant_id = ?");
        $stmtUser->execute([$email, $tenantId]);
        $user = $stmtUser->fetch();

        // Verifica Senha e Status
        if ($user && password_verify($password, $user['password'])) {
            
            if (!$user['active'] || $user['active'] === 'f') {
                throw new Exception("Usuário inativo ou bloqueado.", 403);
            }

            // --- SUCESSO: INICIA SESSÃO ---
            session_start();
            
            // Salva dados na sessão (igual ao login.php web)
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['tenant_id'] = $tenantId;
            
            // Se for cliente final, salva também
            if ($user['customer_id']) {
                $_SESSION['customer_id'] = $user['customer_id'];
            }

            // Retorna JSON de Sucesso para o App
            echo json_encode([
                'success' => true,
                'message' => 'Login realizado com sucesso.',
                'session_id' => session_id(), // O App pode usar isso como Cookie
                'user' => [
                    'id' => $user['id'],
                    'name' => $user['name'],
                    'role' => $user['role'],
                    'tenant_id' => $tenantId,
                    'is_customer' => !empty($user['customer_id'])
                ]
            ]);

        } else {
            throw new Exception("Credenciais inválidas.", 401);
        }

    } catch (Exception $e) {
        $code = $e->getCode() ?: 500;
        if ($code < 100 || $code > 599) $code = 500; // Garante código HTTP válido
        
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
} else {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['error' => 'Método não permitido. Use POST.']);
}
?>