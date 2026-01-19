<?php if (!isset($_SESSION['user_id'])) exit; ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="flex flex-col h-screen bg-slate-50 overflow-hidden font-sans">
    
    <div class="bg-white border-b border-gray-200 px-8 py-5 flex justify-between items-center shadow-sm z-10">
        <div>
            <h1 class="text-2xl font-bold text-slate-800 flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-yellow-100 flex items-center justify-center text-yellow-600 shadow-sm">
                    <i class="fas fa-trophy"></i>
                </div>
                Ranking e Performance
            </h1>
            <p class="text-sm text-slate-500 mt-1 ml-14">Avaliação comportamental e gamificação da frota.</p>
        </div>
        
        <div class="flex items-center gap-3">
            <button onclick="openRules()" class="flex items-center gap-2 text-slate-500 hover:text-blue-600 transition px-3 py-2 rounded-lg hover:bg-slate-50 border border-transparent hover:border-slate-200 text-sm font-medium">
                <i class="fas fa-sliders-h"></i> Configurar Regras
            </button>
            <div class="h-8 w-px bg-gray-200 mx-1"></div>
            <div class="flex bg-white border border-gray-200 rounded-lg p-1 shadow-sm">
                <input type="date" id="rank-start" class="text-xs border-none focus:ring-0 text-slate-600 bg-transparent" value="<?php echo date('Y-m-01'); ?>">
                <span class="text-gray-300 mx-1 self-center">-</span>
                <input type="date" id="rank-end" class="text-xs border-none focus:ring-0 text-slate-600 bg-transparent" value="<?php echo date('Y-m-d'); ?>">
            </div>
            <button onclick="loadRanking()" class="btn btn-primary shadow-lg shadow-blue-200"><i class="fas fa-sync-alt mr-2"></i> Atualizar</button>
        </div>
    </div>

    <div class="px-8 pt-6">
        <div class="flex gap-6 border-b border-gray-200">
            <button onclick="switchTab('drivers')" id="tab-drivers" class="pb-3 px-2 text-sm font-bold text-blue-600 border-b-2 border-blue-600 transition flex items-center gap-2">
                <i class="fas fa-user-astronaut"></i> Ranking Motoristas
            </button>
            <button onclick="switchTab('vehicles')" id="tab-vehicles" class="pb-3 px-2 text-sm font-bold text-gray-400 border-b-2 border-transparent hover:text-gray-600 hover:border-gray-300 transition flex items-center gap-2">
                <i class="fas fa-truck-monster"></i> Ranking Veículos
            </button>
        </div>
    </div>

    <div class="flex-1 overflow-y-auto p-8 space-y-8 relative scroll-smooth">
        
        <div id="loading" class="absolute inset-0 bg-slate-50/90 z-50 flex flex-col items-center justify-center hidden backdrop-blur-sm transition-opacity">
            <div class="w-16 h-16 border-4 border-blue-100 border-t-blue-600 rounded-full animate-spin"></div>
            <p class="mt-4 text-slate-500 font-medium animate-pulse">Calculando pontuações...</p>
        </div>

        <div id="view-drivers" class="space-y-8 animate-in fade-in slide-in-from-bottom-4 duration-500">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6" id="podium-drivers"></div>
            
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="px-8 py-5 border-b border-gray-100 bg-gray-50/50 flex justify-between items-center">
                    <h3 class="font-bold text-slate-700">Detalhamento de Infrações</h3>
                    <span class="text-xs text-gray-400 bg-white px-2 py-1 rounded border border-gray-200">Base: 100 pontos</span>
                </div>
                <table class="w-full text-left">
                    <thead class="text-xs text-gray-400 font-bold uppercase bg-white border-b border-gray-100">
                        <tr>
                            <th class="px-8 py-4 w-20">Pos.</th>
                            <th class="px-6 py-4">Motorista</th>
                            <th class="px-6 py-4 w-48">Score Geral</th>
                            <th class="px-6 py-4 text-center">Jornada</th>
                            <th class="px-6 py-4 text-center">Velocidade</th>
                            <th class="px-6 py-4 text-center">Ociosidade</th>
                        </tr>
                    </thead>
                    <tbody id="table-drivers" class="divide-y divide-gray-50 text-sm"></tbody>
                </table>
            </div>
        </div>

        <div id="view-vehicles" class="space-y-8 hidden animate-in fade-in slide-in-from-bottom-4 duration-500">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6" id="podium-vehicles"></div>
            
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="px-8 py-5 border-b border-gray-100 bg-gray-50/50"><h3 class="font-bold text-slate-700">Saúde da Frota</h3></div>
                <table class="w-full text-left">
                    <thead class="text-xs text-gray-400 font-bold uppercase bg-white border-b border-gray-100">
                        <tr>
                            <th class="px-8 py-4 w-20">Pos.</th>
                            <th class="px-6 py-4">Veículo / Placa</th>
                            <th class="px-6 py-4 w-48">Score Geral</th>
                            <th class="px-6 py-4 text-center">Velocidade</th>
                            <th class="px-6 py-4 text-center">Ociosidade</th>
                            <th class="px-6 py-4 text-center">Vel. Máx</th>
                        </tr>
                    </thead>
                    <tbody id="table-vehicles" class="divide-y divide-gray-50 text-sm"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div id="modal-rules" class="fixed inset-0 bg-black/40 hidden z-[9999] flex items-center justify-center backdrop-blur-sm p-4 transition-opacity">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg transform scale-100 transition-transform duration-300">
        <div class="p-6 border-b border-gray-100 flex justify-between items-center bg-gray-50/50 rounded-t-2xl">
            <div>
                <h3 class="font-bold text-xl text-slate-800">Regras de Pontuação</h3>
                <p class="text-xs text-slate-500 mt-1">Defina os pesos para o cálculo automático.</p>
            </div>
            <button onclick="document.getElementById('modal-rules').classList.add('hidden')" class="w-8 h-8 rounded-full hover:bg-red-50 text-gray-400 hover:text-red-500 flex items-center justify-center transition"><i class="fas fa-times"></i></button>
        </div>
        
        <div class="p-6 space-y-6 max-h-[70vh] overflow-y-auto">
            <div class="group">
                <div class="flex items-center gap-2 mb-3">
                    <div class="w-8 h-8 rounded bg-orange-100 text-orange-600 flex items-center justify-center"><i class="fas fa-tachometer-alt"></i></div>
                    <h4 class="text-sm font-bold text-slate-700">Excesso de Velocidade</h4>
                </div>
                <div class="grid grid-cols-2 gap-4 pl-10">
                    <div>
                        <label class="block text-[10px] uppercase font-bold text-slate-400 mb-1">Limite (km/h)</label>
                        <input type="number" id="rule-speed-limit" class="input-std font-mono" placeholder="Ex: 110">
                    </div>
                    <div>
                        <label class="block text-[10px] uppercase font-bold text-red-400 mb-1">Pontos (por evento)</label>
                        <input type="number" id="rule-speed-penalty" class="input-std font-mono border-red-100 focus:border-red-500" placeholder="-Pts">
                    </div>
                </div>
            </div>

            <hr class="border-gray-100">

            <div class="group">
                <div class="flex items-center gap-2 mb-3">
                    <div class="w-8 h-8 rounded bg-blue-100 text-blue-600 flex items-center justify-center"><i class="fas fa-clock"></i></div>
                    <h4 class="text-sm font-bold text-slate-700">Motor Ocioso (Parado)</h4>
                </div>
                <div class="grid grid-cols-2 gap-4 pl-10">
                    <div>
                        <label class="block text-[10px] uppercase font-bold text-slate-400 mb-1">Intervalo (minutos)</label>
                        <input type="number" id="rule-idle-interval" class="input-std font-mono" placeholder="Ex: 30">
                    </div>
                    <div>
                        <label class="block text-[10px] uppercase font-bold text-red-400 mb-1">Pontos (por intervalo)</label>
                        <input type="number" id="rule-idle-penalty" class="input-std font-mono border-red-100 focus:border-red-500" placeholder="-Pts">
                    </div>
                </div>
            </div>

            <hr class="border-gray-100">

            <div class="group">
                <div class="flex items-center gap-2 mb-3">
                    <div class="w-8 h-8 rounded bg-purple-100 text-purple-600 flex items-center justify-center"><i class="fas fa-business-time"></i></div>
                    <h4 class="text-sm font-bold text-slate-700">Lei da Jornada (13.103)</h4>
                </div>
                <div class="grid grid-cols-2 gap-4 pl-10">
                    <div>
                        <label class="block text-[10px] uppercase font-bold text-red-400 mb-1">Infração Contínua</label>
                        <input type="number" id="rule-journey-cont" class="input-std font-mono border-red-100" placeholder="-Pts">
                        <p class="text-[9px] text-gray-400 mt-1">Dirigir > 5.5h sem parar</p>
                    </div>
                    <div>
                        <label class="block text-[10px] uppercase font-bold text-red-400 mb-1">Infração Diária</label>
                        <input type="number" id="rule-journey-daily" class="input-std font-mono border-red-100" placeholder="-Pts">
                        <p class="text-[9px] text-gray-400 mt-1">Jornada total > 10h</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="p-6 border-t border-gray-100 bg-gray-50 rounded-b-2xl">
            <button onclick="saveRules()" class="w-full btn btn-primary py-3 shadow-lg shadow-blue-200 transform active:scale-95 transition">Salvar Configurações</button>
        </div>
    </div>
</div>

<script>
    // --- FUNÇÕES DE REGRAS (CORRIGIDAS) ---
    async function openRules() {
        // Mostra Loading no botão se necessário ou apenas abre
        try {
            const res = await fetch('/api_ranking.php?action=get_rules');
            if(!res.ok) throw new Error("Erro API");
            
            const r = await res.json();
            
            // Popula inputs (com fallback visual)
            document.getElementById('rule-speed-limit').value = r.speed_limit || 110;
            document.getElementById('rule-speed-penalty').value = r.speed_penalty || 5;
            document.getElementById('rule-idle-interval').value = r.idle_interval || 30;
            document.getElementById('rule-idle-penalty').value = r.idle_penalty || 2;
            document.getElementById('rule-journey-cont').value = r.journey_continuous_penalty || 10;
            document.getElementById('rule-journey-daily').value = r.journey_daily_penalty || 20;

            document.getElementById('modal-rules').classList.remove('hidden');
        } catch(e) {
            alert("Não foi possível carregar as configurações.");
            console.error(e);
        }
    }

    async function saveRules() {
        const rules = {
            speed_limit: document.getElementById('rule-speed-limit').value,
            speed_penalty: document.getElementById('rule-speed-penalty').value,
            idle_interval: document.getElementById('rule-idle-interval').value,
            idle_penalty: document.getElementById('rule-idle-penalty').value,
            journey_continuous_penalty: document.getElementById('rule-journey-cont').value,
            journey_daily_penalty: document.getElementById('rule-journey-daily').value
        };

        try {
            await fetch('/api_ranking.php?action=save_rules', { method: 'POST', body: JSON.stringify(rules) });
            document.getElementById('modal-rules').classList.add('hidden');
            loadRanking();
        } catch(e) { alert("Erro ao salvar."); }
    }

    // --- RENDERIZADORES UI ---
    function renderDrivers(list) {
        const podium = document.getElementById('podium-drivers');
        const tbody = document.getElementById('table-drivers');
        podium.innerHTML = ''; tbody.innerHTML = '';

        // Top 3 Podium
        list.slice(0, 3).forEach((d, i) => {
            let styles = i===0 
                ? 'from-yellow-100 to-yellow-50 border-yellow-200 text-yellow-800 ring-4 ring-yellow-50' 
                : (i===1 ? 'from-slate-100 to-slate-50 border-slate-200 text-slate-700' : 'from-orange-100 to-orange-50 border-orange-200 text-orange-800');
            
            let icon = i===0 ? 'fa-crown text-yellow-500' : (i===1 ? 'fa-medal text-slate-400' : 'fa-award text-orange-500');

            podium.innerHTML += `
                <div class="relative bg-gradient-to-br ${styles} border rounded-2xl p-6 shadow-sm flex flex-col items-center justify-center text-center overflow-hidden group hover:-translate-y-1 transition duration-300">
                    <div class="absolute top-0 right-0 p-4 opacity-10 text-6xl group-hover:scale-110 transition"><i class="fas ${icon}"></i></div>
                    <div class="w-16 h-16 rounded-full bg-white/60 backdrop-blur shadow-sm flex items-center justify-center text-2xl mb-3 font-bold border border-white/50">
                        ${i+1}º
                    </div>
                    <h3 class="font-bold text-lg leading-tight mb-1 truncate w-full" title="${d.name}">${d.name}</h3>
                    <div class="text-4xl font-bold mt-2 tracking-tight">${d.score}</div>
                    <span class="text-[10px] uppercase tracking-widest opacity-60">Pontos</span>
                </div>`;
        });

        // Tabela
        list.forEach((d, i) => {
            const barWidth = d.score + '%';
            let barColor = d.score > 80 ? 'bg-green-500' : (d.score > 50 ? 'bg-yellow-500' : 'bg-red-500');
            
            tbody.innerHTML += `
                <tr class="hover:bg-slate-50 transition group">
                    <td class="px-8 py-4 font-bold text-slate-300 group-hover:text-blue-500 text-lg transition-colors">${i+1}</td>
                    <td class="px-6 py-4">
                        <div class="font-bold text-slate-700">${d.name}</div>
                    </td>
                    <td class="px-6 py-4">
                        <div class="flex items-center gap-3">
                            <span class="font-bold text-slate-700 w-8 text-right">${d.score}</span>
                            <div class="flex-1 h-2 bg-gray-100 rounded-full overflow-hidden">
                                <div class="h-full ${barColor} rounded-full" style="width: ${barWidth}"></div>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4 text-center">
                        ${(d.stats.count_j_cont + d.stats.count_j_daily) > 0 ? `<span class="badge-red">${d.stats.count_j_cont + d.stats.count_j_daily}</span>` : '<span class="text-gray-300">-</span>'}
                    </td>
                    <td class="px-6 py-4 text-center">
                        ${d.stats.count_speed > 0 ? `<span class="badge-orange">${d.stats.count_speed}</span>` : '<span class="text-gray-300">-</span>'}
                    </td>
                    <td class="px-6 py-4 text-center">
                        ${d.stats.count_idle > 0 ? `<div class="text-xs text-slate-600 font-mono"><span class="font-bold text-blue-600">${d.stats.count_idle}x</span> (${d.fmt_idle})</div>` : '<span class="text-gray-300">-</span>'}
                    </td>
                </tr>`;
        });
    }

    function renderVehicles(list) {
        const podium = document.getElementById('podium-vehicles');
        const tbody = document.getElementById('table-vehicles');
        podium.innerHTML = ''; tbody.innerHTML = '';

        list.slice(0, 3).forEach((v, i) => {
            let styles = 'bg-white border-gray-200 text-slate-700';
            if(i===0) styles = 'bg-gradient-to-br from-blue-50 to-white border-blue-200 text-blue-800';
            
            podium.innerHTML += `
                <div class="bg-white border ${i===0?'border-blue-200 ring-4 ring-blue-50':''} rounded-2xl p-6 shadow-sm flex justify-between items-center relative overflow-hidden group">
                    <div class="z-10">
                        <span class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-1 block">${i+1}º LUGAR</span>
                        <h3 class="text-xl font-bold truncate w-40" title="${v.name}">${v.name}</h3>
                        <p class="text-xs text-slate-500 mt-1">${v.plate || 'Sem Placa'}</p>
                    </div>
                    <div class="text-right z-10">
                        <span class="text-4xl font-bold ${i===0?'text-blue-600':'text-slate-800'}">${v.score}</span>
                    </div>
                </div>`;
        });

        list.forEach((v, i) => {
            const barWidth = v.score + '%';
            let barColor = v.score > 80 ? 'bg-green-500' : (v.score > 50 ? 'bg-yellow-500' : 'bg-red-500');

            tbody.innerHTML += `
                <tr class="hover:bg-slate-50 transition group">
                    <td class="px-8 py-4 font-bold text-slate-300 group-hover:text-blue-500 text-lg">${i+1}</td>
                    <td class="px-6 py-4">
                        <div class="font-bold text-slate-700">${v.name}</div>
                        <div class="text-xs text-slate-400">${v.plate || ''}</div>
                    </td>
                    <td class="px-6 py-4">
                        <div class="flex items-center gap-3">
                            <span class="font-bold text-slate-700 w-8 text-right">${v.score}</span>
                            <div class="flex-1 h-2 bg-gray-100 rounded-full overflow-hidden">
                                <div class="h-full ${barColor} rounded-full" style="width: ${barWidth}"></div>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4 text-center">
                        ${v.stats.count_speed > 0 ? `<span class="badge-orange">${v.stats.count_speed}</span>` : '<span class="text-gray-300">-</span>'}
                    </td>
                    <td class="px-6 py-4 text-center">
                        ${v.stats.count_idle > 0 ? `<div class="text-xs text-slate-600 font-mono"><span class="font-bold text-blue-600">${v.stats.count_idle}x</span> (${v.fmt_idle})</div>` : '<span class="text-gray-300">-</span>'}
                    </td>
                    <td class="px-6 py-4 text-center font-mono text-slate-500 text-xs">
                        ${Math.round(v.stats.max_speed)} km/h
                    </td>
                </tr>`;
        });
    }

    // --- CORE ---
    function switchTab(t) {
        document.getElementById('tab-drivers').className="pb-3 px-2 text-sm font-bold text-gray-400 border-b-2 border-transparent hover:text-gray-600 transition flex items-center gap-2";
        document.getElementById('tab-vehicles').className="pb-3 px-2 text-sm font-bold text-gray-400 border-b-2 border-transparent hover:text-gray-600 transition flex items-center gap-2";
        document.getElementById('view-drivers').classList.add('hidden');
        document.getElementById('view-vehicles').classList.add('hidden');
        
        document.getElementById('tab-'+t).className="pb-3 px-2 text-sm font-bold text-blue-600 border-b-2 border-blue-600 transition flex items-center gap-2";
        document.getElementById('view-'+t).classList.remove('hidden');
    }

    async function loadRanking() {
        const s = document.getElementById('rank-start').value;
        const e = document.getElementById('rank-end').value;
        document.getElementById('loading').classList.remove('hidden');
        try {
            const res = await fetch(`/api_ranking.php?from=${s} 00:00:00&to=${e} 23:59:59`);
            const d = await res.json();
            if(d.error) throw new Error(d.error);
            renderDrivers(d.drivers);
            renderVehicles(d.vehicles);
        } catch(err){ console.error(err); alert("Erro ao atualizar."); }
        finally { document.getElementById('loading').classList.add('hidden'); }
    }
    
    document.addEventListener('DOMContentLoaded', loadRanking);
</script>

<style>
    .badge-red { @apply bg-red-100 text-red-600 px-2 py-1 rounded font-bold text-xs border border-red-100; }
    .badge-orange { @apply bg-orange-100 text-orange-600 px-2 py-1 rounded font-bold text-xs border border-orange-100; }
    .input-std { @apply w-full rounded-lg border-gray-300 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-200 transition; }
</style>