<?php
// config/app.php
// Configurações Globais e Detecção de Tenant

// URL Base do Sistema (Ajuste conforme seu domínio real)
define('APP_URL', 'https://fleetvision.com.br');

// Definições de Caminhos
define('ROOT_PATH', dirname(__DIR__));
define('UPLOADS_PATH', ROOT_PATH . '/uploads');

// Conexão com Banco de Dados (Necessária para buscar o Tenant)
require_once __DIR__ . '/db.php';

// Variável Global de Tenant (Inicia vazia/padrão)
$tenant = [
    'id' => null,
    'name' => 'FleetVision',
    'slug' => 'admin',
    'primary_color' => '#3b82f6', // Azul Padrão
    'secondary_color' => '#1e293b', // Escuro Padrão
    'logo_url' => '',
    'background_url' => ''
];

try {
    $pdo = Database::getInstance()->getConnection();

    // 1. Detecta o Slug da URL
    // Ex: fleetvision.com.br/CocaCola/login -> Slug = CocaCola
    $requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $pathParts = explode('/', trim($requestUri, '/'));
    $slug = !empty($pathParts[0]) ? $pathParts[0] : 'admin';

    // Evita buscar slugs de sistema
    if (!in_array($slug, ['api', 'assets', 'uploads', 'index.php'])) {
        
        // 2. Busca o Tenant no Banco
        $stmt = $pdo->prepare("SELECT * FROM saas_tenants WHERE slug = :slug LIMIT 1");
        $stmt->execute(['slug' => $slug]);
        $dbTenant = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($dbTenant) {
            // Se achou, substitui a configuração padrão
            $tenant = array_merge($tenant, $dbTenant);
            
            // Salva na sessão para persistência
            if (session_status() === PHP_SESSION_NONE) session_start();
            $_SESSION['tenant'] = $tenant;
            $_SESSION['tenant_id'] = $tenant['id'];
            $_SESSION['tenant_slug'] = $tenant['slug'];
        }
    }

} catch (Exception $e) {
    // Falha silenciosa na conexão (usa padrões)
    error_log("Erro ao carregar tenant: " . $e->getMessage());
}

// Configurações de Fuso Horário
date_default_timezone_set('America/Sao_Paulo');
?>