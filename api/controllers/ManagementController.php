<?php
// api/controllers/ManagementController.php

if (count(get_included_files()) == 1) exit('Acesso direto não permitido');

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../db.php';

class ManagementController {
    private $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance()->getConnection();
    }

    // --- USUÁRIOS ---
    public function getUsers($tenantId) {
        try {
            $sql = "SELECT u.id, u.name, u.email, u.role_id, u.active, 
                           r.name as role_name 
                    FROM saas_users u 
                    LEFT JOIN saas_roles r ON u.role_id = r.id 
                    WHERE u.tenant_id = ? ORDER BY u.name ASC";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$tenantId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return ['error' => 'Erro ao buscar usuários.'];
        }
    }

    public function saveUser($tenantId, $data) {
        $id = $data['id'] ?? null;
        $name = $data['name'] ?? '';
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';
        $roleId = !empty($data['role_id']) ? $data['role_id'] : null;
        $active = isset($data['active']) && $data['active'] ? 'true' : 'false';

        if (empty($name) || empty($email)) return ['success' => false, 'error' => 'Nome e Email obrigatórios.'];

        try {
            if ($id) {
                // Update
                $sql = "UPDATE saas_users SET name=?, email=?, role_id=?, active=? WHERE id=? AND tenant_id=?";
                $params = [$name, $email, $roleId, $active, $id, $tenantId];
                
                if (!empty($password)) {
                    $sql = "UPDATE saas_users SET name=?, email=?, role_id=?, active=?, password=? WHERE id=? AND tenant_id=?";
                    $params = [$name, $email, $roleId, $active, password_hash($password, PASSWORD_DEFAULT), $id, $tenantId];
                }
                $this->pdo->prepare($sql)->execute($params);
            } else {
                // Insert
                if (empty($password)) return ['success' => false, 'error' => 'Senha obrigatória para novos usuários.'];
                
                // Check Email
                $check = $this->pdo->prepare("SELECT id FROM saas_users WHERE email = ?");
                $check->execute([$email]);
                if($check->fetchColumn()) return ['success' => false, 'error' => 'Email já cadastrado.'];

                $stmt = $this->pdo->prepare("INSERT INTO saas_users (tenant_id, name, email, password, role_id, status, active) VALUES (?, ?, ?, ?, ?, 'active', ?)");
                $stmt->execute([$tenantId, $name, $email, password_hash($password, PASSWORD_DEFAULT), $roleId, $active]);
            }
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function deleteUser($tenantId, $id, $currentUserId) {
        if (!$id || $id == $currentUserId) return ['success' => false, 'error' => 'Operação inválida.'];
        try {
            $stmt = $this->pdo->prepare("DELETE FROM saas_users WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, $tenantId]);
            return ['success' => true];
        } catch (Exception $e) { return ['success' => false, 'error' => $e->getMessage()]; }
    }

    // --- PERFIS (ROLES) ---
    public function getProfiles($tenantId) {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM saas_roles WHERE tenant_id = ? ORDER BY id DESC");
            $stmt->execute([$tenantId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { return []; }
    }

    public function saveProfile($tenantId, $data) {
        $id = $data['id'] ?? null;
        $name = $data['name'] ?? '';
        $perms = json_encode($data['permissions'] ?? []);
        
        if (empty($name)) return ['success' => false, 'error' => 'Nome do perfil obrigatório.'];

        try {
            if ($id) {
                $stmt = $this->pdo->prepare("UPDATE saas_roles SET name=?, permissions=?, updated_at=NOW() WHERE id=? AND tenant_id=?");
                $stmt->execute([$name, $perms, $id, $tenantId]);
            } else {
                $stmt = $this->pdo->prepare("INSERT INTO saas_roles (tenant_id, name, permissions) VALUES (?, ?, ?)");
                $stmt->execute([$tenantId, $name, $perms]);
            }
            return ['success' => true];
        } catch (Exception $e) { return ['success' => false, 'error' => $e->getMessage()]; }
    }

    public function deleteProfile($tenantId, $id) {
        if (!$id) return ['success' => false, 'error' => 'ID inválido.'];
        try {
            $stmt = $this->pdo->prepare("DELETE FROM saas_roles WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$id, $tenantId]);
            return ['success' => true];
        } catch (Exception $e) { return ['success' => false, 'error' => 'Erro ao excluir (perfil pode estar em uso).']; }
    }
}
?>