<?php
if (!isset($_SESSION['user_id'])) exit;
?>

<div class="flex flex-col h-screen bg-slate-50 overflow-hidden">
    
    <div class="bg-white border-b border-gray-200 px-8 py-5 flex justify-between items-center flex-shrink-0">
        <div>
            <h2 class="text-2xl font-bold text-slate-800 flex items-center gap-2">
                <i class="fas fa-stopwatch text-blue-600"></i> Controle de Jornada
            </h2>
            <p class="text-sm text-slate-500">Monitoramento Lei 13.103 em Tempo Real</p>
        </div>
        <div class="flex gap-2">
            <div class="text-right mr-4 hidden md:block">
                <p class="text-[10px] font-bold text-gray-400 uppercase">Tempo Contínuo Max</p>
                <p class="text-sm font-bold text-slate-700">5h 30m</p>
            </div>
            <div class="text-right mr-4 hidden md:block">
                <p class="text-[10px] font-bold text-gray-400 uppercase">Jornada Diária Max</p>
                <p class="text-sm font-bold text-slate-700">10h 00m</p>
            </div>
            <button onclick="loadJornada()" class="btn btn-primary bg-blue-600 text-white hover:bg-blue-700">
                <i class="fas fa-sync-alt"></i>
            </button>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 px-8 py-6 flex-shrink-0">
        <div class="bg-white p-4 rounded-xl shadow-sm border-l-4 border-green-500 flex justify-between items-center">
            <div>
                <p class="text-xs font-bold text-gray-400 uppercase">Motoristas Regulares</p>
                <h3 class="text-2xl font-bold text-green-600" id="count-ok">0</h3>
            </div>
            <i class="fas fa-check-circle text-green-100 text-3xl"></i>
        </div>
        <div class="bg-white p-4 rounded-xl shadow-sm border-l-4 border-yellow-500 flex justify-between items-center">
            <div>
                <p class="text-xs font-bold text-gray-400 uppercase">Em Atenção (>4h)</p>
                <h3 class="text-2xl font-bold text-yellow-600" id="count-warn">0</h3>
            </div>
            <i class="fas fa-exclamation-triangle text-yellow-100 text-3xl"></i>
        </div>
        <div class="bg-white p-4 rounded-xl shadow-sm border-l-4 border-red-500 flex justify-between items-center">
            <div>
                <p class="text-xs font-bold text-gray-400 uppercase">Violações Hoje</p>
                <h3 class="text-2xl font-bold text-red-600" id="count-crit">0</h3>
            </div>
            <i class="fas fa-ban text-red-100 text-3xl"></i>
        </div>
    </div>

    <div class="flex-1 overflow-auto px-8 pb-8">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <table class="w-full text-left border-collapse">
                <thead class="bg-slate-50 text-slate-500 font-bold text-xs uppercase tracking-wider border-b border-gray-200 sticky top-0 z-10">
                    <tr>
                        <th class="px-6 py-4">Motorista / Veículo Atual</th>
                        <th class="px-6 py-4 text-center">Status</th>
                        <th class="px-6 py-4 w-1/4">Tempo Contínuo (Descanso)</th>
                        <th class="px-6 py-4 w-1/4">Jornada Total (Dia)</th>
                        <th class="px-6 py-4 text-center">Situação</th>
                    </tr>
                </thead>
                <tbody id="jornada-list" class="divide-y divide-gray-100 text-sm text-slate-600">
                    <tr><td colspan="5" class="p-8 text-center text-gray-400"><i class="fas fa-circle-notch fa-spin"></i> Carregando dados...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function secToTime(seconds) {
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    return `${h}h ${m < 10 ? '0'+m : m}m`;
}

function getProgressBar(seconds, limit, colorClass) {
    let pct = (seconds / limit) * 100;
    if (pct > 100) pct = 100;
    return `
        <div class="flex justify-between text-[10px] font-bold text-gray-500 mb-1">
            <span>${secToTime(seconds)}</span>
            <span>${Math.round(pct)}%</span>
        </div>
        <div class="w-full bg-gray-100 rounded-full h-2 overflow-hidden">
            <div class="${colorClass} h-full rounded-full transition-all duration-500" style="width: ${pct}%"></div>
        </div>
    `;
}

async function loadJornada() {
    try {
        const res = await fetch('/api_jornada.php');
        const data = await res.json();
        
        const tbody = document.getElementById('jornada-list');
        tbody.innerHTML = '';

        let ok = 0, warn = 0, crit = 0;
        const LIMIT_CONT = 5.5 * 3600;
        const LIMIT_DAY = 10 * 3600;

        if (data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="p-8 text-center text-gray-400 italic">Nenhuma jornada registrada hoje.</td></tr>';
            return;
        }

        data.forEach(d => {
            // Contadores KPI
            if (d.health === 'ok') ok++;
            else if (d.health === 'warning') warn++;
            else crit++;

            // Status Badge
            let statusHtml = '';
            if (d.status === 'dirigindo') {
                statusHtml = `<span class="inline-flex items-center gap-1 px-2 py-1 rounded bg-green-50 text-green-700 text-xs font-bold border border-green-200"><span class="w-2 h-2 rounded-full bg-green-500 animate-pulse"></span> EM MOVIMENTO</span>`;
            } else {
                statusHtml = `<span class="inline-flex items-center gap-1 px-2 py-1 rounded bg-gray-100 text-gray-500 text-xs font-bold border border-gray-200"><i class="fas fa-parking"></i> DESCANSO</span>`;
            }

            // Situação Geral
            let sitBadge = '';
            if (d.violations.length > 0) {
                sitBadge = `<div class="text-red-600 font-bold text-xs flex flex-col items-center"><i class="fas fa-times-circle text-lg mb-1"></i> ${d.violations[0]}</div>`;
            } else {
                sitBadge = `<div class="text-green-600 font-bold text-xs flex flex-col items-center"><i class="fas fa-check-circle text-lg mb-1"></i> REGULAR</div>`;
            }

            // Cores das Barras
            const contColor = d.continuous_driving > (LIMIT_CONT * 0.9) ? 'bg-red-500' : (d.continuous_driving > (LIMIT_CONT * 0.7) ? 'bg-yellow-500' : 'bg-blue-500');
            const dayColor  = d.total_driving > (LIMIT_DAY * 0.9) ? 'bg-red-500' : (d.total_driving > (LIMIT_DAY * 0.8) ? 'bg-yellow-500' : 'bg-indigo-500');

            const html = `
                <tr class="hover:bg-slate-50 transition border-b border-gray-100">
                    <td class="px-6 py-4">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-slate-200 text-slate-500 flex items-center justify-center font-bold text-sm">
                                ${d.name.substring(0,2).toUpperCase()}
                            </div>
                            <div>
                                <div class="font-bold text-slate-800">${d.name}</div>
                                <div class="text-xs text-slate-500"><i class="fas fa-truck text-xs mr-1"></i> ${d.current_vehicle}</div>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4 text-center">
                        ${statusHtml}
                    </td>
                    <td class="px-6 py-4">
                        ${getProgressBar(d.continuous_driving, LIMIT_CONT, contColor)}
                    </td>
                    <td class="px-6 py-4">
                        ${getProgressBar(d.total_driving, LIMIT_DAY, dayColor)}
                    </td>
                    <td class="px-6 py-4 text-center">
                        ${sitBadge}
                    </td>
                </tr>
            `;
            tbody.innerHTML += html;
        });

        document.getElementById('count-ok').innerText = ok;
        document.getElementById('count-warn').innerText = warn;
        document.getElementById('count-crit').innerText = crit;

    } catch(e) { console.error("Erro jornada:", e); }
}

// Inicia
loadJornada();
setInterval(loadJornada, 30000); // Atualiza a cada 30s
</script>