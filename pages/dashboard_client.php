<?php
// Evita acesso direto sem passar pelo index.php
if (!isset($_SESSION['user_id'])) exit;
?>

<div class="h-full flex flex-col bg-slate-50 relative overflow-hidden font-inter">
    
    <div class="px-8 py-6 bg-white border-b border-slate-200 flex justify-between items-center shadow-sm z-20 shrink-0">
        <div>
            <h1 class="text-2xl font-bold text-slate-800 flex items-center gap-3">
                <div class="p-2 bg-indigo-50 rounded-lg text-indigo-600"><i class="fas fa-tachometer-alt"></i></div>
                Visão Operacional
            </h1>
            <p class="text-sm text-slate-500 mt-1 ml-11">Monitoramento em tempo real da frota e logística.</p>
        </div>
        <div class="flex items-center gap-3">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider bg-white border border-slate-200 px-3 py-1.5 rounded-full shadow-sm flex items-center gap-2">
                <div class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></div>
                Atualizado: <span id="last-update">...</span>
            </span>
            <button onclick="loadDashboardData()" class="p-2.5 text-slate-400 hover:text-indigo-600 bg-white border border-slate-200 hover:border-indigo-200 rounded-lg transition shadow-sm" title="Atualizar Agora">
                <i class="fas fa-sync-alt"></i>
            </button>
        </div>
    </div>

    <div class="flex-1 overflow-y-auto custom-scroll p-8">
        <div class="max-w-[1600px] mx-auto space-y-6">

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-5">
                
                <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm relative overflow-hidden group hover:border-indigo-300 transition">
                    <div class="absolute right-0 top-0 p-4 opacity-5 group-hover:opacity-10 transition text-indigo-600"><i class="fas fa-truck text-6xl"></i></div>
                    <div class="relative z-10">
                        <p class="text-slate-500 text-xs font-bold uppercase tracking-wider">Frota Total</p>
                        <h3 class="text-3xl font-bold text-slate-800 mt-2" id="kpi-total">-</h3>
                        <div class="mt-4 flex items-center gap-2 text-xs font-medium text-slate-400">
                            <i class="fas fa-database"></i> Veículos Cadastrados
                        </div>
                    </div>
                </div>

                <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm relative overflow-hidden group hover:border-emerald-300 transition">
                    <div class="absolute right-0 top-0 p-4 opacity-5 group-hover:opacity-10 transition text-emerald-500"><i class="fas fa-road text-6xl"></i></div>
                    <div class="relative z-10">
                        <p class="text-slate-500 text-xs font-bold uppercase tracking-wider">Em Operação</p>
                        <h3 class="text-3xl font-bold text-slate-800 mt-2" id="kpi-moving">-</h3>
                        <div class="mt-4 flex items-center gap-1.5 text-xs font-bold text-emerald-700 bg-emerald-50 w-fit px-2.5 py-1 rounded-md">
                            <i class="fas fa-play"></i> Rodando Agora
                        </div>
                    </div>
                </div>

                <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm relative overflow-hidden group hover:border-amber-300 transition">
                    <div class="absolute right-0 top-0 p-4 opacity-5 group-hover:opacity-10 transition text-amber-500"><i class="fas fa-parking text-6xl"></i></div>
                    <div class="relative z-10">
                        <p class="text-slate-500 text-xs font-bold uppercase tracking-wider">Parados / Ignição Off</p>
                        <h3 class="text-3xl font-bold text-slate-800 mt-2" id="kpi-stopped">-</h3>
                        <div class="mt-4 flex items-center gap-1.5 text-xs font-bold text-amber-700 bg-amber-50 w-fit px-2.5 py-1 rounded-md">
                            <i class="fas fa-clock"></i> Aguardando
                        </div>
                    </div>
                </div>

                <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm relative overflow-hidden group hover:border-slate-300 transition">
                    <div class="absolute right-0 top-0 p-4 opacity-5 group-hover:opacity-10 transition text-slate-500"><i class="fas fa-wifi-slash text-6xl"></i></div>
                    <div class="relative z-10">
                        <p class="text-slate-500 text-xs font-bold uppercase tracking-wider">Sem Comunicação</p>
                        <h3 class="text-3xl font-bold text-slate-800 mt-2" id="kpi-offline">-</h3>
                        <div class="mt-4 flex items-center gap-1.5 text-xs font-bold text-slate-600 bg-slate-100 w-fit px-2.5 py-1 rounded-md">
                            <i class="fas fa-plug"></i> Offline (+24h)
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                
                <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm flex flex-col min-h-[350px]">
                    <h3 class="font-bold text-slate-800 mb-6 flex items-center gap-2 text-sm uppercase tracking-wide border-b border-slate-100 pb-3">
                        <i class="fas fa-chart-pie text-indigo-500"></i> Status da Frota
                    </h3>
                    <div class="flex-1 relative flex items-center justify-center">
                        <div id="chart-status" class="w-full"></div>
                    </div>
                </div>

                <div class="lg:col-span-2 bg-white p-0 rounded-2xl border border-slate-200 shadow-sm flex flex-col min-h-[350px] overflow-hidden">
                    <div class="px-6 py-5 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                        <h3 class="font-bold text-slate-800 flex items-center gap-2 text-sm uppercase tracking-wide">
                            <i class="fas fa-bell text-red-500"></i> Alertas Recentes
                        </h3>
                        <a href="alertas.php" class="text-xs font-bold text-indigo-600 hover:text-indigo-800 bg-white border border-slate-200 px-3 py-1.5 rounded-lg hover:bg-indigo-50 transition">Ver Todos</a>
                    </div>
                    
                    <div class="flex-1 overflow-auto custom-scroll">
                        <table class="w-full text-left border-collapse">
                            <thead class="bg-white text-[10px] uppercase text-slate-400 font-bold sticky top-0 z-10">
                                <tr>
                                    <th class="p-4 pl-6 border-b border-slate-100">Veículo</th>
                                    <th class="p-4 border-b border-slate-100">Ocorrência</th>
                                    <th class="p-4 border-b border-slate-100">Horário</th>
                                    <th class="p-4 pr-6 text-right border-b border-slate-100">Local</th>
                                </tr>
                            </thead>
                            <tbody id="alerts-list" class="text-sm divide-y divide-slate-50">
                                <tr><td colspan="4" class="p-8 text-center text-slate-400"><i class="fas fa-spinner fa-spin"></i> Carregando...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="px-6 py-5 border-b border-slate-100 flex justify-between items-center bg-slate-50/30">
                    <h3 class="font-bold text-slate-800 flex items-center gap-2 text-sm uppercase tracking-wide">
                        <i class="fas fa-play-circle text-emerald-500"></i> Veículos em Movimento
                    </h3>
                    <div class="flex items-center gap-2">
                        <span class="relative flex h-3 w-3">
                          <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                          <span class="relative inline-flex rounded-full h-3 w-3 bg-emerald-500"></span>
                        </span>
                        <span class="text-xs text-slate-500 font-medium">Tempo Real</span>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead class="text-[10px] uppercase text-slate-400 font-bold border-b border-slate-100 bg-white">
                            <tr>
                                <th class="p-4 pl-6">Veículo / Placa</th>
                                <th class="p-4">Velocidade</th>
                                <th class="p-4">Motorista</th>
                                <th class="p-4">Última Posição</th>
                                <th class="p-4 pr-6 text-right">Acompanhar</th>
                            </tr>
                        </thead>
                        <tbody id="moving-vehicles-list" class="text-sm text-slate-600 divide-y divide-slate-50">
                            <tr><td colspan="5" class="p-8 text-center text-slate-400">Carregando frota...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
    // URL DA API (Rota ajustada para raiz)
    const API_URL = '../api_dados.php';

    document.addEventListener('DOMContentLoaded', () => {
        loadDashboardData();
        // Atualização automática a cada 30s
        setInterval(loadDashboardData, 30000);
    });

    async function loadDashboardData() {
        const btnIcon = document.querySelector('button[title="Atualizar Agora"] i');
        if(btnIcon) btnIcon.classList.add('fa-spin');

        try {
            // 1. Busca KPIs Totais
            const resKpis = await fetch(`${API_URL}?action=get_kpis`);
            const kpis = await resKpis.json();

            // 2. Busca Veículos (Para filtrar os que estão movendo)
            const resVehicles = await fetch(`${API_URL}?action=get_dashboard_data&type=online`);
            const vehicles = await resVehicles.json();

            // 3. Busca Alertas Recentes
            const resAlerts = await fetch(`${API_URL}?action=get_alerts`);
            const alerts = await resAlerts.json();

            updateKPIs(kpis);
            updateChart(kpis);
            updateAlertsTable(alerts);
            updateMovingTable(vehicles);

            document.getElementById('last-update').innerText = new Date().toLocaleTimeString('pt-BR', {hour: '2-digit', minute:'2-digit'});

        } catch (error) {
            console.error('Erro dashboard:', error);
        } finally {
            if(btnIcon) btnIcon.classList.remove('fa-spin');
        }
    }

    function updateKPIs(kpis) {
        animateValue('kpi-total', 0, kpis.total_vehicles || 0, 1000);
        animateValue('kpi-moving', 0, kpis.moving || 0, 1000);
        animateValue('kpi-stopped', 0, kpis.stopped || 0, 1000);
        animateValue('kpi-offline', 0, kpis.offline || 0, 1000);
    }

    function updateChart(kpis) {
        const options = {
            series: [parseInt(kpis.moving||0), parseInt(kpis.stopped||0), parseInt(kpis.offline||0)],
            chart: { type: 'donut', height: 280, fontFamily: 'Inter, sans-serif' },
            labels: ['Em Movimento', 'Parados', 'Offline'],
            colors: ['#10b981', '#f59e0b', '#94a3b8'],
            plotOptions: {
                pie: { 
                    donut: { 
                        size: '70%',
                        labels: {
                            show: true,
                            name: { show: true, fontSize: '12px', fontFamily: 'Inter, sans-serif', color: '#64748b' },
                            value: { show: true, fontSize: '24px', fontFamily: 'Inter, sans-serif', fontWeight: 600, color: '#1e293b' },
                            total: { show: true, label: 'Total', fontSize: '12px', color: '#64748b', formatter: () => kpis.total_vehicles || 0 }
                        }
                    } 
                }
            },
            dataLabels: { enabled: false },
            legend: { position: 'bottom', markers: { radius: 12 }, itemMargin: { horizontal: 10, vertical: 5 } },
            stroke: { show: false },
            tooltip: { enabled: true, theme: 'light' }
        };

        const container = document.querySelector("#chart-status");
        container.innerHTML = ''; 
        new ApexCharts(container, options).render();
    }

    function updateAlertsTable(alerts) {
        const tbody = document.getElementById('alerts-list');
        if (!alerts || alerts.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" class="p-8 text-center text-slate-400 text-sm italic">Nenhum alerta recente registrado.</td></tr>';
            return;
        }

        tbody.innerHTML = alerts.map(a => `
            <tr class="hover:bg-slate-50 transition border-b border-slate-50 last:border-0 group">
                <td class="p-4 pl-6 font-bold text-slate-700">${a.vehicle_name || 'Desconhecido'}</td>
                <td class="p-4">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-red-50 text-red-600 border border-red-100">
                        ${a.type_label || a.type}
                    </span>
                </td>
                <td class="p-4 text-slate-500 text-xs font-mono"><i class="far fa-clock mr-1"></i> ${new Date(a.event_time).toLocaleTimeString()}</td>
                <td class="p-4 pr-6 text-right">
                    <a href="mapa.php?lat=${a.latitude}&lon=${a.longitude}" class="text-indigo-500 hover:text-indigo-700 bg-indigo-50 hover:bg-indigo-100 p-2 rounded-lg transition inline-flex items-center justify-center">
                        <i class="fas fa-map-marker-alt"></i>
                    </a>
                </td>
            </tr>
        `).join('');
    }

    function updateMovingTable(vehicles) {
        const tbody = document.getElementById('moving-vehicles-list');
        
        // Filtra apenas veículos com velocidade > 5 km/h (aprox 2.7 nós)
        // O Traccar geralmente manda em Nós. Se sua API já converte, ajuste. 
        // Assumindo que API retorna em Nós: 5 km/h ~= 2.7 knots.
        const moving = vehicles.filter(v => v.speed > 2);

        if (moving.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="p-8 text-center text-slate-400 text-sm italic flex flex-col items-center gap-2"><i class="fas fa-parking text-3xl text-slate-200"></i> Nenhum veículo em movimento no momento.</td></tr>';
            return;
        }

        tbody.innerHTML = moving.slice(0, 5).map(v => {
            // Conversão de Nós para Km/h se necessário (1 nó = 1.852 km/h)
            const speedKmh = Math.round(v.speed * 1.852);
            
            return `
            <tr class="hover:bg-slate-50 transition border-b border-slate-50 last:border-0">
                <td class="p-4 pl-6">
                    <div class="font-bold text-slate-700 text-sm">${v.plate || 'S/ Placa'}</div>
                    <div class="text-[10px] text-slate-400 font-medium">${v.name}</div>
                </td>
                <td class="p-4">
                    <span class="font-mono text-emerald-600 font-bold bg-emerald-50 px-2 py-1 rounded border border-emerald-100 text-xs">
                        ${speedKmh} km/h
                    </span>
                </td>
                <td class="p-4 text-slate-500 text-sm">${v.driver_name || '-'}</td>
                <td class="p-4 text-slate-400 text-xs font-mono">
                    ${new Date(v.lastupdate).toLocaleTimeString()}
                </td>
                <td class="p-4 pr-6 text-right">
                    <a href="mapa.php?device=${v.deviceid}" class="inline-flex items-center gap-1 text-indigo-600 bg-white border border-indigo-100 hover:bg-indigo-50 px-3 py-1.5 rounded-lg text-xs font-bold transition shadow-sm">
                        <i class="fas fa-crosshairs"></i> Rastrear
                    </a>
                </td>
            </tr>
        `}).join('');
    }

    function animateValue(id, start, end, duration) {
        const obj = document.getElementById(id);
        if(!obj) return;
        let startTimestamp = null;
        const step = (timestamp) => {
            if (!startTimestamp) startTimestamp = timestamp;
            const progress = Math.min((timestamp - startTimestamp) / duration, 1);
            obj.innerHTML = Math.floor(progress * (end - start) + start);
            if (progress < 1) window.requestAnimationFrame(step);
        };
        window.requestAnimationFrame(step);
    }
</script>