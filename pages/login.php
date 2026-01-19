<?php
// pages/login.php
if (count(get_included_files()) == 1) header('Location: /');

// Helper para caminhos absolutos
function fixLoginPath($path) {
    if (empty($path)) return '';
    if (strpos($path, 'http') === 0) return $path;
    $cleanPath = ltrim($path, '/');
    if (strpos($cleanPath, 'uploads/') !== 0) $cleanPath = 'uploads/' . $cleanPath;
    return APP_URL . '/' . $cleanPath;
}

$tenant = $tenant ?? []; // Previne erro se $tenant não estiver definido
$logoUrl = fixLoginPath($tenant['logo_url'] ?? '');
$bgUrl = fixLoginPath($tenant['background_url'] ?? ($tenant['login_bg_url'] ?? ($tenant['bg_url'] ?? '')));
$tenantName = $tenant['name'] ?? 'FleetVision';
$primaryColor = $tenant['primary_color'] ?? '#3b82f6'; 
$secondaryColor = $tenant['secondary_color'] ?? '#1e293b';
$loginMessage = $_GET['login_message'] ?? '';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | <?php echo htmlspecialchars($tenantName); ?></title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/style.css?v=<?php echo time(); ?>">

    <style>
        :root { --brand-primary: <?php echo $primaryColor; ?>; --brand-secondary: <?php echo $secondaryColor; ?>; }
        body {
            <?php if ($bgUrl): ?>
            background-image: linear-gradient(rgba(0,0,0,0.6), rgba(0,0,0,0.8)), url('<?php echo $bgUrl; ?>');
            <?php else: ?>
            background-image: linear-gradient(135deg, var(--brand-secondary) 0%, #0f172a 100%);
            <?php endif; ?>
            background-size: cover; background-position: center; background-repeat: no-repeat;
            background-attachment: fixed; font-family: 'Inter', sans-serif;
        }
        .login-card { background: rgba(255,255,255,0.95); backdrop-filter: blur(10px); border-top: 4px solid var(--brand-primary); }
        .btn-brand { background-color: var(--brand-primary); color: white; }
        .btn-brand:hover { filter: brightness(0.9); }
        .text-brand { color: var(--brand-primary); }
        .focus-brand:focus { border-color: var(--brand-primary); box-shadow: 0 0 0 2px <?php echo $primaryColor; ?>33; }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen px-4">

    <div class="w-full max-w-md p-8 login-card rounded-2xl shadow-2xl animate-in fade-in zoom-in duration-500">
        <div class="text-center mb-8">
            <?php if(!empty($logoUrl)): ?>
                <img src="<?php echo $logoUrl; ?>" alt="Logo" class="mx-auto h-20 w-auto mb-4 object-contain drop-shadow-sm">
            <?php else: ?>
                <div class="mx-auto h-16 w-16 bg-gray-100 rounded-2xl flex items-center justify-center text-brand text-3xl font-bold shadow-inner mb-4"><i class="fas fa-truck-fast"></i></div>
                <h2 class="text-2xl font-bold text-gray-800"><?php echo htmlspecialchars($tenantName); ?></h2>
            <?php endif; ?>
            <p class="text-sm text-gray-500 font-medium mt-2">Bem-vindo! Acesse sua conta.</p>
        </div>

        <?php if ($loginMessage): ?><div class="mb-5 bg-blue-50 border-l-4 border-blue-500 text-blue-700 px-4 py-3 rounded text-sm shadow-sm flex items-center gap-3"><i class="fas fa-info-circle text-lg"></i> <?php echo htmlspecialchars($loginMessage); ?></div><?php endif; ?>
        
        <form id="login-form" class="space-y-5">
            <div>
                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5 ml-1">E-mail</label>
                <div class="relative group"><div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400 group-focus-within:text-brand transition-colors"><i class="fas fa-envelope"></i></div>
                <input type="email" id="email" name="email" required class="w-full pl-10 pr-4 py-2.5 rounded-lg border border-gray-300 focus:outline-none focus-brand bg-white/50 focus:bg-white transition-all text-sm font-medium text-gray-700" placeholder="exemplo@empresa.com"></div>
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-1.5 ml-1">Senha</label>
                <div class="relative group"><div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400 group-focus-within:text-brand transition-colors"><i class="fas fa-lock"></i></div>
                <input type="password" id="password" name="password" required class="w-full pl-10 pr-4 py-2.5 rounded-lg border border-gray-300 focus:outline-none focus-brand bg-white/50 focus:bg-white transition-all text-sm font-medium text-gray-700" placeholder="••••••••"></div>
            </div>
            <div class="flex items-center justify-between pt-1">
                <label class="flex items-center cursor-pointer group"><input type="checkbox" class="h-4 w-4 text-brand focus:ring-opacity-50 border-gray-300 rounded cursor-pointer transition"><span class="ml-2 text-sm text-gray-500 group-hover:text-gray-700 transition">Lembrar-me</span></label>
                <a href="#" class="text-sm font-bold text-brand hover:underline">Esqueceu a senha?</a>
            </div>
            <button type="submit" class="w-full flex justify-center items-center py-3 px-4 border border-transparent rounded-lg shadow-md text-sm font-bold text-white btn-brand focus:outline-none focus:ring-2 focus:ring-offset-2 transition-all transform hover:scale-[1.02] mt-6">Acessar Plataforma <i class="fas fa-arrow-right ml-2"></i></button>
        </form>
        <div class="mt-8 pt-6 border-t border-gray-100 text-center"><p class="text-xs text-gray-400">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($tenantName); ?>.</p></div>
    </div>
    <div id="toast-container"></div>
    
    <script>
        const CONFIG = { 
            apiUrl: '<?php echo APP_URL; ?>/api.php', 
            tenantSlug: '<?php echo $slug; ?>' 
        };
    </script>
    <script src="<?php echo APP_URL; ?>/assets/js/utils.js?v=<?php echo time(); ?>"></script>
    <script src="<?php echo APP_URL; ?>/assets/js/main.js?v=<?php echo time(); ?>"></script>
    
    <script>
        document.getElementById('login-form').addEventListener('submit', async function(e) {
            e.preventDefault();
            const btn = this.querySelector('button[type="submit"]');
            const original = btn.innerHTML;
            try {
                btn.disabled = true; btn.innerHTML = '<i class="fas fa-circle-notch fa-spin text-lg"></i>';
                const email = document.getElementById('email').value;
                const password = document.getElementById('password').value;
                
                const res = await apiRequest('login', { email, password }, 'POST');
                
                if (res.success) {
                    showToast('Login realizado!', 'success');
                    let target = res.redirect || '/<?php echo $slug; ?>/dashboard';
                    setTimeout(() => window.location.href = target, 800);
                } else {
                    showToast(res.message || 'Erro', 'error');
                    btn.disabled = false; btn.innerHTML = original;
                }
            } catch (err) {
                console.error(err);
                showToast('Erro de conexão.', 'error');
                btn.disabled = false; btn.innerHTML = original;
            }
        });
    </script>
</body>
</html>