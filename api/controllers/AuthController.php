<?php
/**
 * AuthController - Gerencia Autenticação e Perfil de Usuário
 * Responsável por Login, Logout, Sessão e Atualização de Dados.
 */

// Garante que o arquivo não seja acessado diretamente
if (count(get_included_files()) == 1) exit('Acesso direto não permitido');

// Importa dependências
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../db.php';

class AuthController {
    private $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance()->getConnection();
    }

    /**
     * Processa o Login do Usuário
     * @param string $email
     * @param string $password
     * @return array
     */
    public function login($email, $password) {
        if (empty($email) || empty($password)) {
            return ['success' => false, 'message' => 'Preencha todos os campos.'];
        }

        try {
            // Busca usuário e dados do tenant/role
            // Query ajustada para ser mais robusta
            $stmt = $this->pdo->prepare("
                SELECT u.*, t.slug as tenant_slug, r.name as role_name 
                FROM saas_users u
                JOIN saas_tenants t ON u.tenant_id = t.id
                LEFT JOIN saas_roles r ON u.role_id = r.id
                WHERE u.email = :email LIMIT 1
            ");
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // 1. Verifica se usuário existe
            if (!$user) {
                usleep(rand(100000, 300000)); 
                return ['success' => false, 'message' => 'E-mail não encontrado.'];
            }

            // 2. Verifica se foi deletado (Soft Delete) - Verificação no código para evitar erro SQL se coluna faltar
            if (isset($user['deleted_at']) && !empty($user['deleted_at'])) {
                 return ['success' => false, 'message' => 'Conta desativada.'];
            }

            // 3. Verifica se está ativo
            $isActive = ($user['active'] === true || $user['active'] === 'true' || $user['active'] == 1);
            if (!$isActive) {
                return ['success' => false, 'message' => 'Conta inativa. Contate o suporte.'];
            }

            // 4. Verifica Senha (Hash)
            if (password_verify($password, $user['password'])) {
                // --- LOGIN SUCESSO ---
                
                // Tenta regenerar ID da sessão (com silenciador @ para evitar warning de headers sent)
                @session_regenerate_id(true);

                // Define Slug com segurança
                $tenantSlug = !empty($user['tenant_slug']) ? $user['tenant_slug'] : 'admin';

                // Define variáveis de sessão
                $_SESSION['user_id']    = $user['id'];
                $_SESSION['user_name']  = $user['name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['tenant_id']  = $user['tenant_id'];
                $_SESSION['tenant_slug']= $tenantSlug;
                $_SESSION['user_role']  = $user['role_name'] ?? 'user';
                $_SESSION['logged_in']  = true;
                
                if (empty($_SESSION['csrf_token'])) {
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                }

                return [
                    'success' => true, 
                    'message' => 'Login realizado com sucesso!',
                    'redirect' => '/' . $tenantSlug . '/dashboard'
                ];
            } else {
                return ['success' => false, 'message' => 'Senha incorreta.'];
            }

        } catch (Exception $e) {
            error_log("Erro no Login: " . $e->getMessage());
            // CORREÇÃO: Retorna o erro exato para você ver no popup
            return ['success' => false, 'message' => 'Erro SQL: ' . $e->getMessage()];
        }
    }

    /**
     * Processa Logout
     */
    public function logout() {
        $slug = $_SESSION['tenant_slug'] ?? 'admin';
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
        return '/' . $slug . '/login';
    }

    /**
     * Atualiza Perfil (Nome, Senha, Avatar)
     */
    public function updateProfile($userId, $data, $files) {
        if ($userId != ($_SESSION['user_id'] ?? null)) {
            return ['success' => false, 'message' => 'Ação não autorizada.'];
        }

        $name = trim($data['name'] ?? '');
        $password = $data['password'] ?? '';
        
        if (empty($name)) {
            return ['success' => false, 'message' => 'Nome é obrigatório.'];
        }

        try {
            $this->pdo->beginTransaction();

            // 1. Upload de Avatar
            $avatarUrl = null;
            if (isset($files['avatar']) && $files['avatar']['error'] === UPLOAD_ERR_OK) {
                $uploadResult = $this->handleAvatarUpload($files['avatar'], $userId);
                if ($uploadResult['success']) {
                    $avatarUrl = $uploadResult['path'];
                } else {
                    $this->pdo->rollBack();
                    return $uploadResult;
                }
            }

            // 2. Monta Query
            $sql = "UPDATE saas_users SET name = :name";
            $params = ['name' => $name, 'id' => $userId];

            if (!empty($password)) {
                $sql .= ", password = :password";
                $params['password'] = password_hash($password, PASSWORD_DEFAULT);
            }

            if ($avatarUrl) {
                $sql .= ", avatar_url = :avatar";
                $params['avatar'] = $avatarUrl;
                $_SESSION['user_avatar'] = $avatarUrl; 
            }

            $sql .= " WHERE id = :id";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            $this->pdo->commit();
            $_SESSION['user_name'] = $name;

            return ['success' => true, 'message' => 'Perfil atualizado com sucesso!'];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'message' => 'Erro: ' . $e->getMessage()];
        }
    }

    private function handleAvatarUpload($file, $userId) {
        $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, $allowedExts)) return ['success' => false, 'message' => 'Formato inválido.'];
        if ($file['size'] > 5 * 1024 * 1024) return ['success' => false, 'message' => 'Imagem muito grande.'];

        $baseDir = defined('UPLOADS_PATH') ? UPLOADS_PATH : __DIR__ . '/../../uploads';
        $targetDir = $baseDir . '/avatars';
        
        if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);

        $fileName = "avatar_{$userId}_" . time() . ".{$ext}";
        $targetPath = $targetDir . '/' . $fileName;
        $dbPath = 'uploads/avatars/' . $fileName;

        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            return ['success' => true, 'path' => $dbPath];
        }
        return ['success' => false, 'message' => 'Falha ao salvar imagem.'];
    }

    public static function checkCSRF($token) {
        if (!isset($_SESSION['csrf_token']) || empty($token)) return false;
        return hash_equals($_SESSION['csrf_token'], $token);
    }
}
?>