<?php
// Debug silencioso
ini_set('display_errors', 0); error_reporting(E_ALL);

if (!isset($_SESSION['user_id'])) exit("Sessão expirada.");

$userNameFull = $_SESSION['user_name'] ?? 'Gestor';
// Correção: Garante que $tenant seja um array
$tenant = isset($tenant) && is_array($tenant) ? $tenant : ($_SESSION['tenant'] ?? ['id' => $_SESSION['tenant_id'] ?? 0]);

// --- BUSCA EVENTOS NO BANCO (SQL) ---
try {
    $pdo = Database::getInstance()->getConnection();
    
    $isAdmin = ($_SESSION['user_role'] == 'admin' || $_SESSION['user_role'] == 'superadmin');
    $whereUser = $isAdmin ? "" : " AND v.user_id = " . intval($_SESSION['user_id']);

    // 1. Busca IDs dos veículos do usuário
    $sqlIds = "SELECT v.traccar_device_id FROM saas_vehicles v WHERE v.tenant_id = ? $whereUser";
    $stmtIds = $pdo->prepare($sqlIds);
    $stmtIds->execute([$tenant['id']]);
    $veiculos = $stmtIds->fetchAll(PDO::FETCH_COLUMN);
    
    $events = [];
    $totalAlerts24h = 0;

    if (!empty($veiculos)) {
        // Filtra apenas IDs numéricos válidos
        $validIds = array_filter($veiculos, 'is_numeric');
        
        if(!empty($validIds)){
            $idList = implode(',', $validIds);
            
            // Busca Últimos 7 Eventos
            $sqlEvt = "SELECT e.type, e.eventtime as servertime, v.name as vehicle_name 
                       FROM tc_events e
                       JOIN saas_vehicles v ON e.deviceid = v.traccar_device_id
                       WHERE e.deviceid IN ($idList) 
                       ORDER BY e.eventtime DESC LIMIT 7";
            $events = $pdo->query($sqlEvt)->fetchAll(PDO::FETCH_ASSOC);

            // Conta alertas das últimas 24h
            $sqlCount = "SELECT COUNT(*) FROM tc_events e 
                         WHERE e.deviceid IN ($idList) 
                         AND e.eventtime > NOW() - INTERVAL '24 hours' 
                         AND e.type NOT IN ('deviceOnline', 'deviceOffline')"; 
            $totalAlerts24h = $pdo->query($sqlCount)->fetchColumn();
        }
    }

} catch (Exception $e) {
    $events = [];
    $totalAlerts24h = 0;
    // Opcional: error_log($e->getMessage());
}
?>

<div class="p-6 space-y-6 bg-slate-50 min-h-full font-sans text-slate-800">
    <div class="flex flex-col md:flex-row justify-between items-end mb-2">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Visão Geral</h1>
            <p class="text-sm text-slate-500">Monitoramento em tempo real da operação.</p>
        </div>
        <div class="text-right">
            <span id="last-update" class="text-xs text-slate-400 font-mono">Atualizado: --:--</span>
        </div>
    </div>
    <?php include __DIR__ . '/../includes/dashboard_content_partial.php'; // Ou cole o HTML aqui ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// --- SCRIPT DO DASHBOARD (Mantenha o original) ---
// Cole aqui o script JS que estava no final do seu arquivo dashboard.php original
// ...
</script>