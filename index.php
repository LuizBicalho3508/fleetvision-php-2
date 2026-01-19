<?php
// index.php - Roteador Frontend Completo
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/app.php';

// 1. Processamento da URL
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$scriptName = $_SERVER['SCRIPT_NAME'];
if (strpos($requestUri, 'index.php') !== false) {
    $requestUri = str_replace('index.php', '', $requestUri);
}
$route = trim($requestUri, '/');
$segments = $route ? explode('/', $route) : [];

$slug = !empty($segments[0]) ? $segments[0] : 'admin';
$page = !empty($segments[1]) ? $segments[1] : '';

// 2. Correção de Loop de Login
if (strpos($slug, 'login') !== false && $slug !== 'login') {
    header("Location: /admin/login");
    exit;
}

// 3. Rota de Login (Sem Layout)
if (!isset($_SESSION['user_id']) || $page === 'login') {
    if (isset($_SESSION['user_id']) && $page === 'login') {
        header("Location: /$slug/dashboard");
        exit;
    }
    // Define página padrão para login se não estiver logado
    if ($page !== 'login' && $slug !== 'api' && $slug !== 'assets') {
        header("Location: /$slug/login");
        exit;
    }
    if ($page === 'login') {
        require_once __DIR__ . '/pages/login.php';
        exit;
    }
}

// 4. Carregamento de Páginas Internas (Com Layout)
if (empty($page)) $page = 'dashboard';
$pageFile = preg_replace('/[^a-zA-Z0-9_-]/', '', $page);
$path = __DIR__ . "/pages/{$pageFile}.php";

// Variáveis Globais para as Views
$tenantId = $_SESSION['tenant_id'] ?? 0;
$userId   = $_SESSION['user_id'] ?? 0;
$userRole = $_SESSION['user_role'] ?? 'user';
$tenant   = $_SESSION['tenant'] ?? []; // Garante que $tenant exista

if (file_exists($path)) {
    // --- INÍCIO DO LAYOUT HTML ---
    ?>
    <!DOCTYPE html>
    <html lang="pt-br">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>FleetVision | <?php echo ucfirst($page); ?></title>
        <script src="https://cdn.tailwindcss.com"></script>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/style.css?v=<?php echo time(); ?>">
        <script>
            const CONFIG = { apiUrl: '<?php echo APP_URL; ?>/api.php', tenantSlug: '<?php echo $slug; ?>' };
        </script>
    </head>
    <body class="bg-slate-50 font-sans text-slate-800 antialiased overflow-hidden">
        
        <div class="flex h-screen w-full">
            <?php include __DIR__ . '/includes/sidebar.php'; ?>
            
            <div id="main-wrapper" class="relative flex flex-col flex-1 overflow-y-auto overflow-x-hidden transition-all duration-300">
                <?php include __DIR__ . '/includes/header.php'; ?>
                
                <main class="flex-grow">
                    <?php require_once $path; ?>
                </main>
            </div>
        </div>

        <script src="<?php echo APP_URL; ?>/assets/js/utils.js"></script>
        <script src="<?php echo APP_URL; ?>/assets/js/main.js"></script>
        <div id="toast-container"></div>
    </body>
    </html>
    <?php
    // --- FIM DO LAYOUT ---

} else {
    http_response_code(404);
    echo "<h1>Erro 404</h1><p>Página '$page' não encontrada.</p><a href='/$slug/dashboard'>Voltar</a>";
}
?>