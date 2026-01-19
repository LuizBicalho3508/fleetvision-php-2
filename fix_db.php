<?php
// fix_db.php - Versão Corrigida para JSON
require_once 'config/app.php';
require_once 'db.php';

echo "<h2>Reparando Banco de Dados...</h2>";

try {
    $pdo = Database::getInstance()->getConnection();

    // 1. Corrige a tabela de usuários
    try {
        $pdo->exec("ALTER TABLE saas_users ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL;");
        echo "✓ Coluna 'deleted_at' verificada.<br>";
        
        $pdo->exec("ALTER TABLE saas_users ADD COLUMN IF NOT EXISTS active BOOLEAN DEFAULT TRUE;");
        echo "✓ Coluna 'active' verificada.<br>";
    } catch (Exception $e) {
        echo "Aviso (Users): " . $e->getMessage() . "<br>";
    }

    // 2. Corrige a tabela de Roles (Perfis)
    // Tenta criar se não existir (se já existir como JSON, o comando abaixo será ignorado)
    $pdo->exec("CREATE TABLE IF NOT EXISTS saas_roles (
        id SERIAL PRIMARY KEY,
        name VARCHAR(50) UNIQUE NOT NULL,
        permissions JSONB
    )");
    
    // 3. Insere o Admin com permissão em formato JSON Válido
    // A correção principal está aqui: '[\"*\"]' é um JSON válido
    $stmt = $pdo->prepare("INSERT INTO saas_roles (name, permissions) VALUES ('admin', :perms) ON CONFLICT (name) DO NOTHING");
    $stmt->execute(['perms' => '["*"]']);
    
    echo "✓ Role 'admin' criada/verificada com sucesso.<br>";

    // 4. Garante que o usuário admin tenha a role correta
    // Pega o ID da role admin
    $roleStmt = $pdo->query("SELECT id FROM saas_roles WHERE name = 'admin'");
    $roleId = $roleStmt->fetchColumn();

    if ($roleId) {
        // Atualiza usuários admin sem role
        $pdo->exec("UPDATE saas_users SET role_id = $roleId WHERE role_id IS NULL");
        echo "✓ Usuários vinculados ao perfil Admin.<br>";
    }

    echo "<h3 style='color:green; margin-top:20px'>Correção concluída! Tente fazer login novamente.</h3>";
    echo "<a href='/login.php' style='background:#3b82f6; color:white; padding:10px 20px; text-decoration:none; border-radius:5px;'>Ir para Login</a>";

} catch (Exception $e) {
    echo "<h3 style='color:red'>Erro Fatal: " . $e->getMessage() . "</h3>";
    // Debug extra se falhar
    echo "<pre>" . print_r($pdo->errorInfo(), true) . "</pre>";
}
?>