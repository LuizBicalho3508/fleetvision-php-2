<?php
require 'db.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['tenant_id'])) {
    header("Location: login.php");
    exit;
}

$tenant_id = $_SESSION['tenant_id'];

// 1. KPIs
$sqlKPI = "
    SELECT 
        (SELECT COUNT(*) FROM saas_customers WHERE tenant_id = ?) as total_clientes,
        (SELECT COUNT(*) FROM saas_vehicles WHERE tenant_id = ? AND status = 'active') as total_veiculos,
        (SELECT COUNT(*) FROM saas_vehicles v 
         LEFT JOIN tc_devices t ON v.traccar_device_id = t.id 
         WHERE v.tenant_id = ? AND v.status = 'active' 
         AND t.lastupdate >= NOW() - INTERVAL '24 hours') as veiculos_online
";
$stmtKPI = $pdo->prepare($sqlKPI);
$stmtKPI->execute([$tenant_id, $tenant_id, $tenant_id]);
$kpis = $stmtKPI->fetch(PDO::FETCH_ASSOC);
$kpis['veiculos_offline'] = $kpis['total_veiculos'] - $kpis['veiculos_online'];

// 2. FATURAMENTO REAL (SEM ESTIMATIVAS)
// Soma apenas o campo monthly_fee. Se for NULL, retorna 0.
try {
    $stmtRev = $pdo->prepare("SELECT SUM(monthly_fee) FROM saas_customers WHERE tenant_id = ? AND (status = 'active' OR status = 'Ativo')");
    $stmtRev->execute([$tenant_id]);
    $receita_total = $stmtRev->fetchColumn() ?: 0.00;
} catch (Exception $e) {
    $receita_total = 0.00;
}

// 3. CRESCIMENTO
$sqlGrowth = "
    SELECT TO_CHAR(created_at, 'YYYY-MM') as mes, COUNT(*) as qtd
    FROM saas_vehicles 
    WHERE tenant_id = ? AND created_at >= NOW() - INTERVAL '12 months'
    GROUP BY 1 ORDER BY 1 ASC
";
$stmtGrowth = $pdo->prepare($sqlGrowth);
$stmtGrowth->execute([$tenant_id]);
$growthData = $stmtGrowth->fetchAll(PDO::FETCH_KEY_PAIR);

$labelsChart = []; $dataChart = [];
for ($i = 11; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $labelsChart[] = date('M/y', strtotime("-$i months"));
    $dataChart[] = $growthData[$m] ?? 0;
}

// 4. TOP CLIENTES
$sqlTop = "SELECT c.name, COUNT(v.id) as total_frota FROM saas_customers c JOIN saas_vehicles v ON c.id = v.client_id WHERE c.tenant_id = ? AND v.status = 'active' GROUP BY c.id, c.name ORDER BY total_frota DESC LIMIT 5";
$stmtTop = $pdo->prepare($sqlTop); $stmtTop->execute([$tenant_id]); $topClients = $stmtTop->fetchAll(PDO::FETCH_ASSOC);
$maxFleet = !empty($topClients) ? $topClients[0]['total_frota'] : 1;
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Executivo</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <style>
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        .card-hover:hover { transform: translateY(-2px); box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); }
    </style>
</head>
<body class="bg-slate-50 font-sans text-slate-800">

    <div class="p-8 max-w-7xl mx-auto space-y-8">
        <div class="flex justify-between items-end border-b border-slate-200 pb-4">
            <div><h1 class="text-3xl font-bold text-slate-800">Visão Geral</h1><p class="text-slate-500 mt-1">Gestão estratégica da central.</p></div>
            <div class="text-right">
                <p class="text-xs font-bold text-slate-400 uppercase">Faturamento Mensal</p>
                <div class="text-2xl font-bold text-emerald-600">R$ <?php echo number_format($receita_total, 2, ',', '.'); ?></div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            <div class="bg-white p-6 rounded-2xl border border-slate-100 shadow-sm transition hover:shadow-md cursor-pointer" onclick="location.href='clientes'">
                <div class="flex items-center justify-between mb-4"><div class="w-12 h-12 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center text-xl"><i class="fas fa-users"></i></div><span class="bg-blue-100 text-blue-700 text-xs font-bold px-2 py-1 rounded-full">Total</span></div>
                <h3 class="text-3xl font-bold text-slate-800"><?php echo $kpis['total_clientes']; ?></h3><p class="text-sm text-slate-500">Clientes Ativos</p>
            </div>
            <div class="bg-white p-6 rounded-2xl border border-slate-100 shadow-sm transition hover:shadow-md cursor-pointer" onclick="location.href='frota'">
                <div class="flex items-center justify-between mb-4"><div class="w-12 h-12 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center text-xl"><i class="fas fa-car"></i></div><span class="bg-indigo-100 text-indigo-700 text-xs font-bold px-2 py-1 rounded-full">+<?php echo end($dataChart); ?> mês</span></div>
                <h3 class="text-3xl font-bold text-slate-800"><?php echo $kpis['total_veiculos']; ?></h3><p class="text-sm text-slate-500">Rastreadores Ativos</p>
            </div>
            <div class="bg-white p-6 rounded-2xl border-l-4 border-l-emerald-500 shadow-sm transition hover:shadow-md cursor-pointer" onclick="loadDetails('online', 'Veículos Online (24h)')">
                <div class="flex items-center justify-between mb-4"><div class="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-xl"><i class="fas fa-wifi"></i></div><span class="text-emerald-600 font-bold text-sm"><?php echo ($kpis['total_veiculos']>0)?number_format(($kpis['veiculos_online']/$kpis['total_veiculos'])*100,0):0; ?>%</span></div>
                <h3 class="text-3xl font-bold text-slate-800"><?php echo $kpis['veiculos_online']; ?></h3><p class="text-sm text-slate-500">Online (24h)</p>
            </div>
            <div class="bg-white p-6 rounded-2xl border-l-4 border-l-red-500 shadow-sm transition hover:shadow-md cursor-pointer" onclick="loadDetails('offline', 'Veículos Offline (+24h)')">
                <div class="flex items-center justify-between mb-4"><div class="w-12 h-12 rounded-xl bg-red-50 text-red-600 flex items-center justify-center text-xl"><i class="fas fa-eye-slash"></i></div><span class="text-red-600 font-bold text-sm"><?php echo ($kpis['total_veiculos']>0)?number_format(($kpis['veiculos_offline']/$kpis['total_veiculos'])*100,0):0; ?>%</span></div>
                <h3 class="text-3xl font-bold text-slate-800"><?php echo $kpis['veiculos_offline']; ?></h3><p class="text-sm text-slate-500">Sem sinal > 24h</p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 h-96">
            <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm flex flex-col"><h3 class="text-lg font-bold text-slate-700 mb-4">Conectividade</h3><div id="chart-connectivity" class="flex-1 flex items-center justify-center"></div></div>
            <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm lg:col-span-2 flex flex-col"><h3 class="text-lg font-bold text-slate-700 mb-4">Crescimento (12 Meses)</h3><div id="chart-growth" class="flex-1 w-full"></div></div>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-slate-50/50"><h3 class="text-lg font-bold text-slate-700">🏆 Top 5 Maiores Frotas</h3><a href="clientes" class="text-sm text-indigo-600 font-bold hover:underline">Ver Todos</a></div>
            <div class="p-6 space-y-5">
                <?php foreach($topClients as $idx => $cl): $pct = ($cl['total_frota'] / $maxFleet) * 100; $colors = ['bg-indigo-600', 'bg-blue-500', 'bg-emerald-500', 'bg-amber-500', 'bg-slate-500']; ?>
                <div><div class="flex justify-between text-sm mb-1"><span class="font-bold text-slate-700"><?php echo htmlspecialchars($cl['name']); ?></span><span class="font-mono font-bold text-slate-600"><?php echo $cl['total_frota']; ?></span></div><div class="w-full bg-slate-100 rounded-full h-2.5 overflow-hidden"><div class="<?php echo $colors[$idx]??'bg-slate-400'; ?> h-2.5 rounded-full" style="width: <?php echo $pct; ?>%"></div></div></div>
                <?php endforeach; if(empty($topClients)): ?><p class="text-center text-slate-400 text-sm">Sem dados.</p><?php endif; ?>
            </div>
        </div>
    </div>

    <div id="modal-details" class="fixed inset-0 bg-black/60 hidden z-50 flex items-center justify-center backdrop-blur-sm transition-opacity opacity-0">
        <div class="bg-white w-full max-w-4xl h-[80vh] rounded-2xl shadow-2xl flex flex-col transform scale-95 transition-transform" id="modal-content">
            <div class="px-6 py-4 border-b border-slate-200 flex justify-between items-center bg-slate-50 rounded-t-2xl"><h3 class="text-xl font-bold text-slate-800" id="modal-title">Detalhes</h3><button onclick="closeModal()" class="text-slate-400 hover:text-red-500 text-2xl">&times;</button></div>
            <div class="flex-1 overflow-y-auto p-0" id="modal-body"><div class="flex flex-col items-center justify-center h-full text-slate-400"><i class="fas fa-spinner fa-spin text-3xl mb-2"></i><p>Carregando...</p></div></div>
            <div class="px-6 py-4 border-t border-slate-200 bg-slate-50 rounded-b-2xl flex justify-end"><button onclick="closeModal()" class="bg-white border border-slate-300 text-slate-600 px-4 py-2 rounded-lg font-bold hover:bg-slate-50">Fechar</button></div>
        </div>
    </div>

    <script>
        // GRÁFICOS
        new ApexCharts(document.querySelector("#chart-connectivity"), {
            series: [<?php echo $kpis['veiculos_online']; ?>, <?php echo $kpis['veiculos_offline']; ?>], labels: ['Online', 'Offline'],
            chart: { type: 'donut', height: 280, events: { dataPointSelection: (e, c, config) => { if(config.dataPointIndex === 0) loadDetails('online', 'Veículos Online'); if(config.dataPointIndex === 1) loadDetails('offline', 'Veículos Offline'); }}},
            colors: ['#10b981', '#ef4444'], legend: { position: 'bottom' }, plotOptions: { pie: { donut: { size: '65%' } } }
        }).render();

        new ApexCharts(document.querySelector("#chart-growth"), {
            series: [{ name: 'Novos', data: <?php echo json_encode($dataChart); ?> }], chart: { type: 'area', height: 300, toolbar: { show: false } },
            colors: ['#6366f1'], stroke: { curve: 'smooth', width: 2 }, xaxis: { categories: <?php echo json_encode($labelsChart); ?> }, yaxis: { show: false }, grid: { borderColor: '#f1f5f9' }
        }).render();

        // MODAL
        const modal = document.getElementById('modal-details'), modalContent = document.getElementById('modal-content');
        function openModal() { modal.classList.remove('hidden'); setTimeout(() => { modal.classList.remove('opacity-0'); modalContent.classList.remove('scale-95'); modalContent.classList.add('scale-100'); }, 10); }
        function closeModal() { modalContent.classList.remove('scale-100'); modalContent.classList.add('scale-95'); modal.classList.add('opacity-0'); setTimeout(() => modal.classList.add('hidden'), 300); }

        async function loadDetails(type, title) {
            openModal(); document.getElementById('modal-title').innerText = title;
            const body = document.getElementById('modal-body');
            body.innerHTML = '<div class="flex flex-col items-center justify-center h-full text-slate-400"><i class="fas fa-spinner fa-spin text-3xl mb-2"></i><p>Carregando...</p></div>';
            
            const url = `api_dados.php?action=get_dashboard_data&type=${type}`;
            console.log("Fetching: " + url); // Debug

            try {
                const res = await fetch(url);
                
                // Se der erro HTTP (ex: 404), lança exceção
                if (!res.ok) throw new Error("Erro HTTP: " + res.status);
                
                const data = await res.json();
                
                if (data.length === 0) { body.innerHTML = '<div class="p-10 text-center text-slate-500">Nenhum registro encontrado.</div>'; return; }
                
                let html = '<table class="w-full text-left border-collapse"><thead class="bg-slate-50 sticky top-0"><tr><th class="p-4 text-xs font-bold text-slate-500 uppercase border-b">Veículo</th><th class="p-4 text-xs font-bold text-slate-500 uppercase border-b">Placa</th><th class="p-4 text-xs font-bold text-slate-500 uppercase border-b">Cliente</th><th class="p-4 text-xs font-bold text-slate-500 uppercase border-b text-right">Última Conexão</th></tr></thead><tbody class="divide-y divide-slate-100">';
                data.forEach(r => {
                    let last = r.lastupdate ? new Date(r.lastupdate.replace(' ','T')).toLocaleString('pt-BR') : 'Nunca';
                    let status = (r.hours_offline && r.hours_offline > 24) ? 'text-red-500 font-bold' : 'text-emerald-600 font-bold';
                    html += `<tr class="hover:bg-slate-50"><td class="p-4 font-bold text-slate-700">${r.name}</td><td class="p-4 text-xs font-mono text-slate-600 bg-slate-50 w-fit rounded">${r.plate||'-'}</td><td class="p-4 text-sm text-slate-600">${r.client_name||'Sem Cliente'}</td><td class="p-4 text-right text-xs ${status}">${last}</td></tr>`;
                });
                html += '</tbody></table>'; body.innerHTML = html;
            } catch (e) { 
                console.error(e);
                body.innerHTML = `<div class="p-10 text-center text-red-500">Erro ao carregar dados.<br><small>${e.message}</small></div>`; 
            }
        }
    </script>
</body>
</html>