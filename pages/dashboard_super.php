<?php
if (!isset($_SESSION['user_id'])) exit;
// Bloqueio Rígido
if ($_SESSION['user_role'] != 'superadmin') {
    header("Location: ?page=dashboard"); 
    exit;
}

// 1. DADOS GLOBAIS (SaaS Metrics)
// CORREÇÃO: Join com tc_devices para lastupdate
$sqlStats = "
    SELECT 
        (SELECT COUNT(*) FROM saas_vehicles) as total_veiculos,
        (SELECT COUNT(*) FROM saas_customers) as total_clientes,
        (SELECT COUNT(*) FROM saas_users) as total_usuarios,
        (SELECT COUNT(*) FROM saas_vehicles v JOIN tc_devices d ON v.traccar_device_id = d.id WHERE d.lastupdate > NOW() - INTERVAL '10 minutes') as veiculos_online,
        (SELECT COUNT(*) FROM saas_vehicles v JOIN tc_devices d ON v.traccar_device_id = d.id WHERE d.lastupdate < NOW() - INTERVAL '7 days') as veiculos_abandonados
";
$stats = $pdo->query($sqlStats)->fetch(PDO::FETCH_ASSOC);

// Cálculo Offline
$veiculos_offline = $stats['total_veiculos'] - $stats['veiculos_online'];

// 2. TOP CLIENTES
$sqlTopClients = "
    SELECT c.name, COUNT(v.id) as qtd 
    FROM saas_customers c 
    LEFT JOIN saas_vehicles v ON v.customer_id = c.id 
    GROUP BY c.id, c.name 
    ORDER BY qtd DESC 
    LIMIT 5
";
$topClients = $pdo->query($sqlTopClients)->fetchAll(PDO::FETCH_ASSOC);

// 3. CHECK DE SAÚDE
$start = microtime(true);
try { $pdo->query("SELECT 1"); $dbLatency = round((microtime(true) - $start) * 1000); } catch(Exception $e) { $dbLatency = 'Erro'; }

// Ping Traccar API
$traccarStatus = 'Offline';
$traccarLatency = 0;
$t_start = microtime(true);
$ch = curl_init('http://127.0.0.1:8082/api/server');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 2);
$res = curl_exec($ch);
if(!curl_errno($ch) && curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200) {
    $traccarStatus = 'Online';
    $traccarLatency = round((microtime(true) - $t_start) * 1000);
}
curl_close($ch);
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="flex flex-col h-screen bg-slate-900 overflow-auto text-slate-100">
    
    <div class="bg-slate-950 border-b border-slate-800 px-8 py-6 flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-white flex items-center gap-3">
                <i class="fas fa-network-wired text-indigo-500"></i> Master Control
            </h1>
            <p class="text-sm text-slate-400">Visão Geral da Infraestrutura</p>
        </div>
        
        <div class="flex gap-4">
            <div class="text-right">
                <p class="text-[10px] uppercase font-bold text-slate-500">Banco de Dados</p>
                <p class="text-xs font-mono text-green-400">● Conectado (<?php echo $dbLatency; ?>ms)</p>
            </div>
            <div class="text-right border-l border-slate-800 pl-4">
                <p class="text-[10px] uppercase font-bold text-slate-500">Traccar Core</p>
                <p class="text-xs font-mono <?php echo $traccarStatus=='Online'?'text-green-400':'text-red-500'; ?>">
                    ● <?php echo $traccarStatus; ?> (<?php echo $traccarLatency; ?>ms)
                </p>
            </div>
        </div>
    </div>

    <div class="p-8 space-y-8">
        
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
            <div class="bg-slate-800 p-6 rounded-2xl border border-slate-700 relative overflow-hidden group">
                <div class="absolute right-0 top-0 p-4 opacity-10 group-hover:opacity-20 transition transform group-hover:scale-110"><i class="fas fa-database text-6xl text-indigo-500"></i></div>
                <p class="text-xs font-bold text-indigo-400 uppercase tracking-wider">Total Rastreadores</p>
                <h3 class="text-4xl font-bold text-white mt-2"><?php echo $stats['total_veiculos']; ?></h3>
                <p class="text-xs text-slate-400 mt-2">Base Instalada</p>
            </div>

            <div class="bg-slate-800 p-6 rounded-2xl border border-slate-700 relative overflow-hidden group">
                <div class="absolute right-0 top-0 p-4 opacity-10 group-hover:opacity-20 transition transform group-hover:scale-110"><i class="fas fa-users text-6xl text-blue-500"></i></div>
                <p class="text-xs font-bold text-blue-400 uppercase tracking-wider">Total Clientes</p>
                <h3 class="text-4xl font-bold text-white mt-2"><?php echo $stats['total_clientes']; ?></h3>
                <p class="text-xs text-slate-400 mt-2">+ Usuários: <?php echo $stats['total_usuarios']; ?></p>
            </div>

            <div class="bg-slate-800 p-6 rounded-2xl border border-slate-700 relative overflow-hidden group">
                <div class="absolute right-0 top-0 p-4 opacity-10 group-hover:opacity-20 transition transform group-hover:scale-110"><i class="fas fa-wifi text-6xl text-green-500"></i></div>
                <p class="text-xs font-bold text-green-400 uppercase tracking-wider">Online Agora</p>
                <h3 class="text-4xl font-bold text-white mt-2"><?php echo $stats['veiculos_online']; ?></h3>
                <?php $percent = $stats['total_veiculos'] > 0 ? ($stats['veiculos_online']/$stats['total_veiculos'])*100 : 0; ?>
                <div class="w-full bg-slate-700 h-1.5 mt-3 rounded-full overflow-hidden">
                    <div class="bg-green-500 h-full" style="width: <?php echo $percent; ?>%"></div>
                </div>
            </div>

            <div class="bg-slate-800 p-6 rounded-2xl border border-slate-700 relative overflow-hidden group">
                <div class="absolute right-0 top-0 p-4 opacity-10 group-hover:opacity-20 transition transform group-hover:scale-110"><i class="fas fa-ghost text-6xl text-red-500"></i></div>
                <p class="text-xs font-bold text-red-400 uppercase tracking-wider">Risco (Offline > 7d)</p>
                <h3 class="text-4xl font-bold text-white mt-2"><?php echo $stats['veiculos_abandonados']; ?></h3>
                <p class="text-xs text-red-300 mt-2">Verificar chips</p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="bg-slate-800 p-6 rounded-2xl border border-slate-700 lg:col-span-2">
                <h4 class="font-bold text-white mb-6">Saúde da Comunicação (Global)</h4>
                <div class="h-64 w-full">
                    <canvas id="chartComm"></canvas>
                </div>
            </div>

            <div class="bg-slate-800 p-6 rounded-2xl border border-slate-700">
                <h4 class="font-bold text-white mb-4">Top 5 Clientes</h4>
                <div class="space-y-4">
                    <?php foreach($topClients as $idx => $client): 
                        $width = ($stats['total_veiculos'] > 0) ? ($client['qtd'] / $stats['total_veiculos']) * 100 : 0;
                    ?>
                    <div>
                        <div class="flex justify-between text-xs mb-1">
                            <span class="font-bold text-slate-300"><?php echo $idx+1; ?>. <?php echo $client['name']; ?></span>
                            <span class="text-indigo-400 font-mono"><?php echo $client['qtd']; ?> un.</span>
                        </div>
                        <div class="w-full bg-slate-700 h-2 rounded-full">
                            <div class="bg-indigo-500 h-full rounded-full" style="width: <?php echo $width * 5; ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="mt-8 pt-4 border-t border-slate-700">
                    <div class="grid grid-cols-2 gap-2">
                        <a href="?page=frota" class="btn bg-slate-700 hover:bg-slate-600 text-xs text-center py-2 rounded text-white border border-slate-600">Gerenciar</a>
                        <a href="?page=usuarios" class="btn bg-slate-700 hover:bg-slate-600 text-xs text-center py-2 rounded text-white border border-slate-600">Acessos</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    const ctxComm = document.getElementById('chartComm');
    new Chart(ctxComm, {
        type: 'bar',
        data: {
            labels: ['Status Atual'],
            datasets: [
                { label: 'Online (< 10min)', data: [<?php echo $stats['veiculos_online']; ?>], backgroundColor: '#22c55e', barThickness: 50 },
                { label: 'Latência (10min - 7d)', data: [<?php echo $veiculos_offline - $stats['veiculos_abandonados']; ?>], backgroundColor: '#eab308', barThickness: 50 },
                { label: 'Abandonados (> 7d)', data: [<?php echo $stats['veiculos_abandonados']; ?>], backgroundColor: '#ef4444', barThickness: 50 }
            ]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            scales: { x: { stacked: true, grid: { color: '#334155' } }, y: { stacked: true, display: false } },
            plugins: { legend: { position: 'bottom', labels: { color: '#cbd5e1' } } }
        }
    });
</script>