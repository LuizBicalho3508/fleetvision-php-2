<?php
if (!isset($_SESSION['user_id'])) exit;

// 1. Busca Veículos Permitidos para o Filtro (Dropdown)
// Usa a mesma lógica do api_dados.php para garantir consistência
$user_id = $_SESSION['user_id'];
$tenant_id = $_SESSION['tenant_id'];
$user_role = $_SESSION['user_role'] ?? 'user';

// Determina restrição
$restrictionSQL = "";
if ($user_role != 'admin' && $user_role != 'superadmin') {
    // Verifica se tem cliente vinculado
    $stmtCheck = $pdo->prepare("SELECT customer_id FROM saas_users WHERE id = ?");
    $stmtCheck->execute([$user_id]);
    $custId = $stmtCheck->fetchColumn();
    
    if($custId) {
        $restrictionSQL = " AND (v.client_id = $custId OR v.user_id = $user_id)";
    } else {
        $restrictionSQL = " AND v.user_id = $user_id";
    }
}

// Busca lista
$sqlV = "SELECT v.traccar_device_id, v.name FROM saas_vehicles v WHERE v.tenant_id = ? $restrictionSQL ORDER BY v.name ASC";
$stmt = $pdo->prepare($sqlV);
$stmt->execute([$tenant_id]);
$vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

<audio id="alert-sound" src="https://actions.google.com/sounds/v1/alarms/beep_short.ogg" preload="auto"></audio>

<div class="flex flex-col h-screen bg-slate-50 relative font-inter">
    
    <div id="toast-area" class="fixed top-20 right-6 z-[9999] flex flex-col gap-3 pointer-events-none w-96"></div>

    <div class="bg-white border-b border-gray-200 px-6 py-4 shadow-sm z-20 flex justify-between items-center">
        <div class="flex items-center gap-4">
            <h2 class="text-xl font-bold text-slate-800 flex items-center gap-2">
                <div class="p-2 bg-red-50 rounded-lg text-red-600"><i class="fas fa-bell"></i></div>
                Central de Alertas
            </h2>
            
            <button onclick="openConfig()" class="text-gray-400 hover:text-blue-600 transition p-2 rounded-full hover:bg-gray-100" title="Configurar">
                <i class="fas fa-cog text-lg"></i>
            </button>

            <div class="flex items-center bg-slate-100 rounded-full p-1 border border-slate-200 ml-2">
                <button onclick="toggleLiveMode(false)" id="btn-hist" class="px-3 py-1.5 rounded-full text-xs font-bold bg-white shadow-sm text-slate-700 transition">Histórico</button>
                <button onclick="toggleLiveMode(true)" id="btn-live" class="px-3 py-1.5 rounded-full text-xs font-bold text-slate-400 hover:text-slate-600 transition flex items-center gap-1"><i class="fas fa-satellite-dish"></i> Tempo Real</button>
            </div>
        </div>

        <div class="flex gap-2" id="filters-container">
            <button id="btn-bulk-resolve" onclick="resolveSelected()" class="hidden btn bg-blue-600 text-white hover:bg-blue-700 py-1.5 px-3 rounded-lg text-xs font-bold shadow transition animate-in fade-in zoom-in">
                <i class="fas fa-check-double mr-1"></i> Tratar (<span id="bulk-count">0</span>)
            </button>

            <select id="filter-device" class="px-3 py-1.5 rounded-lg border border-gray-200 text-xs text-slate-600 focus:border-indigo-500 outline-none w-40">
                <option value="">Todos Veículos</option>
                <?php foreach($vehicles as $v): ?>
                    <option value="<?php echo $v['traccar_device_id']; ?>"><?php echo $v['name']; ?></option>
                <?php endforeach; ?>
            </select>
            <input type="datetime-local" id="date-from" class="px-3 py-1.5 rounded-lg border border-gray-200 text-xs text-slate-600 outline-none" value="<?php echo date('Y-m-d 00:00'); ?>">
            <input type="datetime-local" id="date-to" class="px-3 py-1.5 rounded-lg border border-gray-200 text-xs text-slate-600 outline-none" value="<?php echo date('Y-m-d 23:59'); ?>">
            <button onclick="manualLoad()" class="bg-white hover:bg-slate-50 border border-gray-200 text-slate-500 px-3 py-1.5 rounded-lg text-xs font-bold shadow-sm transition"><i class="fas fa-sync"></i></button>
        </div>
    </div>

    <div class="grid grid-cols-4 gap-4 px-6 py-4 bg-slate-50 border-b border-gray-200">
        <div class="bg-white p-3 rounded-xl border border-gray-200 shadow-sm flex justify-between items-center">
            <div><p class="text-[10px] uppercase font-bold text-gray-400">Pendentes</p><h3 class="text-xl font-bold text-red-600" id="kpi-pending">0</h3></div>
            <div class="w-8 h-8 rounded-full bg-red-50 flex items-center justify-center text-red-500"><i class="fas fa-exclamation"></i></div>
        </div>
        <div class="bg-white p-3 rounded-xl border border-gray-200 shadow-sm flex justify-between items-center">
            <div><p class="text-[10px] uppercase font-bold text-gray-400">Tratados</p><h3 class="text-xl font-bold text-green-600" id="kpi-resolved">0</h3></div>
            <div class="w-8 h-8 rounded-full bg-green-50 flex items-center justify-center text-green-500"><i class="fas fa-check"></i></div>
        </div>
        <div class="bg-white p-3 rounded-xl border border-gray-200 shadow-sm flex justify-between items-center">
            <div><p class="text-[10px] uppercase font-bold text-gray-400">Conexão</p><h3 class="text-xl font-bold text-blue-500" id="kpi-conn">0</h3></div>
            <div class="w-8 h-8 rounded-full bg-blue-50 flex items-center justify-center text-blue-500"><i class="fas fa-wifi"></i></div>
        </div>
        <div class="bg-white p-3 rounded-xl border border-gray-200 shadow-sm flex justify-between items-center">
            <div><p class="text-[10px] uppercase font-bold text-gray-400">Críticos</p><h3 class="text-xl font-bold text-slate-700" id="kpi-crit">0</h3></div>
            <div class="w-8 h-8 rounded-full bg-slate-100 flex items-center justify-center text-slate-500"><i class="fas fa-bolt"></i></div>
        </div>
    </div>

    <div class="flex-1 overflow-auto px-6 pb-6 pt-4">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden flex flex-col h-full">
            <div class="flex-1 overflow-auto custom-scroll">
                <table class="w-full text-left border-collapse">
                    <thead class="bg-gray-50 text-gray-500 font-bold text-[10px] uppercase sticky top-0 z-10 shadow-sm border-b border-gray-200">
                        <tr>
                            <th class="px-4 py-3 text-center w-10"><input type="checkbox" onchange="toggleSelectAll(this)" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 cursor-pointer"></th>
                            <th class="px-6 py-3">Status</th>
                            <th class="px-6 py-3">Data/Hora</th>
                            <th class="px-6 py-3">Veículo</th>
                            <th class="px-6 py-3">Motorista</th> 
                            <th class="px-6 py-3">Evento</th>
                            <th class="px-6 py-3">Detalhes</th>
                            <th class="px-6 py-3 text-right">Ação</th>
                        </tr>
                    </thead>
                    <tbody id="alerts-body" class="divide-y divide-gray-100 text-sm text-slate-600">
                        <tr><td colspan="8" class="p-10 text-center text-gray-400">Carregando...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div id="modal-map" class="fixed inset-0 bg-black/80 hidden z-[9999] flex items-center justify-center p-4 backdrop-blur-sm transition-opacity opacity-0">
    <div class="bg-white w-full max-w-4xl h-[70vh] rounded-xl flex flex-col shadow-2xl overflow-hidden relative transform scale-95 transition-transform duration-200" id="modal-map-content">
        <div class="h-14 border-b flex justify-between items-center px-4 bg-slate-50">
            <h3 class="font-bold text-slate-700 flex items-center gap-2"><i class="fas fa-map-marker-alt text-red-500"></i> Local do Evento</h3>
            <button onclick="closeModal('modal-map')" class="w-8 h-8 rounded-full bg-white hover:bg-red-50 text-gray-400 hover:text-red-500 shadow flex items-center justify-center"><i class="fas fa-times"></i></button>
        </div>
        <div id="map-alert" class="flex-1 bg-gray-100 relative z-0"></div>
    </div>
</div>

<div id="modal-config" class="fixed inset-0 bg-black/60 hidden z-[9999] flex items-center justify-center backdrop-blur-sm p-4 transition-opacity opacity-0">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-sm transform scale-95 transition-transform duration-200 overflow-hidden" id="modal-config-content">
        <div class="p-5 border-b border-gray-100 bg-slate-50 flex justify-between items-center">
            <h3 class="font-bold text-lg text-slate-800">Preferências</h3>
            <button onclick="closeModal('modal-config')" class="text-gray-400 hover:text-red-500"><i class="fas fa-times"></i></button>
        </div>
        <div class="p-6 space-y-4 max-h-[70vh] overflow-y-auto">
            <p class="text-xs text-gray-500 mb-2">Selecione os alertas desejados:</p>
            <?php 
            $opts = [
                'ignition' => ['Ignição (On/Off)', 'key'],
                'speed' => ['Excesso Velocidade', 'tachometer-alt'],
                'fence' => ['Cercas Virtuais', 'draw-polygon'],
                'battery' => ['Bateria / Energia', 'car-battery'],
                'sos' => ['SOS / Pânico', 'exclamation-triangle'],
                'driver' => ['Motorista', 'id-card'],
                'connection' => ['Status Conexão', 'wifi']
            ];
            foreach($opts as $key => $val): ?>
            <div class="flex justify-between items-center">
                <span class="text-sm font-bold text-slate-700 flex items-center gap-2"><i class="fas fa-<?php echo $val[1]; ?> text-gray-400 w-5"></i> <?php echo $val[0]; ?></span>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" id="cfg-<?php echo $key; ?>" class="sr-only peer" checked>
                    <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                </label>
            </div>
            <?php endforeach; ?>
            <div class="pt-4 mt-2 border-t border-gray-100">
                <button onclick="saveConfig()" class="w-full bg-blue-600 text-white font-bold py-3 rounded-lg hover:bg-blue-700 transition">Salvar</button>
            </div>
        </div>
    </div>
</div>

<div id="modal-resolve" class="fixed inset-0 bg-black/60 hidden z-[9999] flex items-center justify-center backdrop-blur-sm p-4 transition-opacity opacity-0">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-md transform scale-95 transition-transform duration-200" id="modal-resolve-content">
        <div class="p-5 border-b border-gray-100 bg-slate-50 flex justify-between items-center">
            <h3 class="font-bold text-lg text-slate-800">Tratar Ocorrência</h3>
            <button onclick="closeModal('modal-resolve')" class="text-gray-400 hover:text-red-500"><i class="fas fa-times"></i></button>
        </div>
        <div class="p-6">
            <input type="hidden" id="res-event-ids">
            <p class="text-sm text-gray-600 mb-4" id="res-msg-single">Veículo: <strong id="res-veh-name"></strong></p>
            <p class="text-sm text-gray-600 mb-4 hidden" id="res-msg-multi">Tratando <strong><span id="res-count"></span> eventos</strong>.</p>
            <label class="block text-xs font-bold text-gray-500 mb-1 uppercase">Observação</label>
            <textarea id="res-notes" class="w-full p-3 border border-gray-300 rounded-lg h-24 resize-none focus:border-blue-500 outline-none text-sm" placeholder="Ex: Ciente, alarme falso, contato realizado..."></textarea>
            <div class="flex justify-end gap-2 mt-4">
                <button onclick="closeModal('modal-resolve')" class="px-4 py-2 border border-gray-300 rounded-lg text-slate-600 hover:bg-slate-50 font-bold text-sm">Cancelar</button>
                <button onclick="confirmResolve()" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 font-bold text-sm">Concluir</button>
            </div>
        </div>
    </div>
</div>

<script>
    let isLive = false, pollInterval = null, processedEvents = new Set(), map = null;
    const sound = document.getElementById('alert-sound');

    // Mapeamento de Tipos
    const EVENT_TYPES = {
        'deviceOverspeed': { label: 'Excesso Velocidade', color: 'text-orange-600', icon: 'tachometer-alt', bg:'bg-orange-50' },
        'geofenceEnter': { label: 'Entrou na Cerca', color: 'text-blue-600', icon: 'sign-in-alt', bg:'bg-blue-50' },
        'geofenceExit': { label: 'Saiu da Cerca', color: 'text-blue-600', icon: 'sign-out-alt', bg:'bg-blue-50' },
        'ignitionOn': { label: 'Ignição Ligada', color: 'text-green-600', icon: 'key', bg:'bg-green-50' },
        'ignitionOff': { label: 'Ignição Desligada', color: 'text-gray-500', icon: 'power-off', bg:'bg-gray-50' },
        'powerCut': { label: 'Bateria Removida', color: 'text-red-600', icon: 'cut', bg:'bg-red-50' },
        'lowBattery': { label: 'Bateria Baixa', color: 'text-red-500', icon: 'battery-quarter', bg:'bg-red-50' },
        'sos': { label: 'SOS / Pânico', color: 'text-red-700', icon: 'exclamation-circle', bg:'bg-red-100' },
        'driverChanged': { label: 'Motorista Identificado', color: 'text-indigo-600', icon: 'id-card', bg:'bg-indigo-50' },
        'deviceOnline': { label: 'Conectado', color: 'text-green-500', icon: 'wifi', bg:'bg-green-50' },
        'deviceOffline': { label: 'Desconectado', color: 'text-gray-400', icon: 'wifi-slash', bg:'bg-gray-50' },
        'deviceUnknown': { label: 'Status Desconhecido', color: 'text-gray-400', icon: 'question', bg:'bg-gray-50' },
        'deviceMoving': { label: 'Em Movimento', color: 'text-blue-500', icon: 'car-side', bg:'bg-blue-50' },
        'deviceStopped': { label: 'Parado', color: 'text-gray-500', icon: 'parking', bg:'bg-gray-50' }
    };

    // --- CARREGAR DADOS ---
    async function fetchAlerts() {
        const dev = document.getElementById('filter-device').value;
        const tbody = document.getElementById('alerts-body');
        
        // Pega data ISO para API
        const from = document.getElementById('date-from').value.replace('T', ' ') + ':00';
        const to = document.getElementById('date-to').value.replace('T', ' ') + ':59';

        // URL AJUSTADA: api_dados.php?action=get_alerts&limit=100
        // (O backend api_dados.php já usa a session para filtrar os dados)
        // Se quiser filtrar por data/device especifico, precisaria adaptar o api_dados.php, 
        // mas para simplificar vamos carregar os últimos 100 e filtrar no front se necessário ou usar o filtro padrão.
        
        try {
            // Nota: Para usar filtros de data customizados, o api_dados.php precisaria aceitar params extra.
            // Aqui vamos usar o get_alerts padrão que traz os últimos X registros.
            // Para "Histórico Completo", o ideal seria paginar no backend.
            
            const res = await fetch(`../api_dados.php?action=get_alerts&limit=200`);
            const data = await res.json();
            
            if (Array.isArray(data)) {
                renderTable(data);
                processNewAlerts(data);
            } else {
                tbody.innerHTML = `<tr><td colspan="8" class="p-10 text-center text-red-500">Erro ao carregar dados.</td></tr>`;
            }

        } catch (e) {
            tbody.innerHTML = `<tr><td colspan="8" class="p-10 text-center text-red-500">${e.message}</td></tr>`;
        }
    }

    function renderTable(data) {
        const tbody = document.getElementById('alerts-body');
        
        // Filtro local simples se o usuário selecionou um device específico no dropdown
        const selectedDev = document.getElementById('filter-device').value; // ID do traccar
        // Nota: O get_alerts retorna 'vehicle_name', não deviceId. 
        // Para filtrar exato, o api_dados deveria retornar deviceId.
        // Vamos assumir que mostra tudo por enquanto ou filtrar por texto se possível.

        if (data.length === 0) { tbody.innerHTML = '<tr><td colspan="8" class="p-10 text-center text-gray-400">Nenhum evento recente.</td></tr>'; return; }

        let html = '';
        data.forEach(evt => {
            const def = EVENT_TYPES[evt.type] || { label: evt.type, icon: 'bell', color: 'text-gray-600', bg: 'bg-gray-100' };
            const date = new Date(evt.event_time).toLocaleString('pt-BR');
            
            // Status simulado (backend não tem tabela de tratativas ainda, então tudo é pendente visualmente)
            let statusBadge = '<span class="bg-red-50 text-red-600 px-2 py-1 rounded text-[10px] font-bold uppercase border border-red-100">Evento</span>';
            let actionBtn = `<button onclick="openResolve(${evt.id}, '${evt.vehicle_name}')" class="text-blue-600 hover:text-blue-800 text-xs font-bold border border-blue-200 hover:bg-blue-50 px-3 py-1 rounded transition">Tratar</button>`;
            
            // Link Mapa
            let details = `<a href="javascript:void(0)" onclick="openEventMap(${evt.latitude}, ${evt.longitude})" class="text-indigo-600 hover:underline flex items-center gap-1 cursor-pointer text-xs font-bold"><i class="fas fa-map-marker-alt"></i> Mapa</a>`;

            let checkbox = `<input type="checkbox" class="evt-check rounded border-gray-300 text-blue-600 cursor-pointer" value="${evt.id}" onclick="updateBulkBtn()">`;

            html += `
            <tr class="bg-white border-b border-gray-50 hover:bg-slate-50 transition group">
                <td class="px-4 py-3 text-center">${checkbox}</td>
                <td class="px-6 py-4">${statusBadge}</td>
                <td class="px-6 py-4 font-mono text-xs text-gray-500">${date}</td>
                <td class="px-6 py-4 font-bold text-slate-700">${evt.vehicle_name}</td>
                <td class="px-6 py-4 text-xs text-gray-400">-</td>
                <td class="px-6 py-4">
                    <div class="flex items-center gap-2">
                        <div class="w-6 h-6 rounded flex items-center justify-center ${def.bg} ${def.color}"><i class="fas fa-${def.icon} text-xs"></i></div>
                        <span class="${def.color} font-bold text-xs">${def.label}</span>
                    </div>
                </td>
                <td class="px-6 py-4 text-xs">${details}</td>
                <td class="px-6 py-4 text-right">${actionBtn}</td>
            </tr>`;
        });
        tbody.innerHTML = html;
        
        // Atualiza KPIs (Simulado com dados visíveis)
        document.getElementById('kpi-crit').innerText = data.filter(d => ['deviceOverspeed', 'sos'].includes(d.type)).length;
        document.getElementById('kpi-pending').innerText = data.length; 
    }

    // --- MAPA E AÇÕES ---
    function openEventMap(lat, lon) {
        const modal = document.getElementById('modal-map');
        modal.classList.remove('hidden');
        setTimeout(() => {
            modal.classList.remove('opacity-0');
            document.getElementById('modal-map-content').classList.remove('scale-95');
            document.getElementById('modal-map-content').classList.add('scale-100');
        }, 10);

        if(!map) { 
            map = L.map('map-alert').setView([0,0], 13); 
            L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png').addTo(map); 
        }
        
        setTimeout(()=>map.invalidateSize(), 300);
        
        map.eachLayer(l => { if(l instanceof L.Marker) map.removeLayer(l); });
        
        if(lat && lon) {
            L.marker([lat, lon]).addTo(map).bindPopup(`<b>Local do Evento</b>`).openPopup();
            map.setView([lat, lon], 16);
        } else {
            alert("Localização não disponível para este evento.");
        }
    }

    // Toasts e Sons
    function processNewAlerts(data) {
        if (!isLive) return;
        let hasNew = false;
        const now = new Date().getTime();
        
        data.forEach(evt => {
            // Verifica se é recente (últimos 5 min) e não processado
            const evtTime = new Date(evt.event_time).getTime();
            if (!processedEvents.has(evt.id) && (now - evtTime) < 5 * 60 * 1000) {
                showToast(evt);
                processedEvents.add(evt.id);
                hasNew = true;
            }
        });
        if (hasNew) sound.play().catch(()=>{});
    }

    function showToast(evt) {
        const area = document.getElementById('toast-area');
        const def = EVENT_TYPES[evt.type] || { label: evt.type, icon: 'bell', color: 'text-gray-600', bg:'bg-gray-100' };
        
        const toast = document.createElement('div');
        toast.className = "bg-white border-l-4 border-red-500 shadow-2xl p-4 rounded-r-lg flex items-start gap-4 animate-in slide-in-from-right duration-500 pointer-events-auto cursor-pointer hover:bg-slate-50 transition transform hover:-translate-x-1 mb-2";
        toast.innerHTML = `
            <div class="w-10 h-10 rounded-full ${def.bg} ${def.color} flex items-center justify-center flex-shrink-0"><i class="fas fa-${def.icon} text-lg"></i></div>
            <div class="flex-1 min-w-0">
                <h4 class="font-bold text-slate-800 text-sm truncate">${evt.vehicle_name}</h4>
                <p class="text-xs font-bold ${def.color} mt-0.5">${def.label}</p>
                <span class="text-[10px] text-gray-400 font-mono">${new Date(evt.event_time).toLocaleTimeString()}</span>
            </div>`;
        
        toast.onclick = () => toast.remove();
        area.appendChild(toast);
        setTimeout(() => toast.remove(), 6000);
    }

    // Modais e Utilitários
    function openResolve(id, name) {
        const modal = document.getElementById('modal-resolve');
        modal.classList.remove('hidden');
        setTimeout(() => { modal.classList.remove('opacity-0'); document.getElementById('modal-resolve-content').classList.remove('scale-95'); document.getElementById('modal-resolve-content').classList.add('scale-100'); }, 10);
        
        document.getElementById('res-veh-name').innerText = name;
        document.getElementById('res-event-ids').value = id;
    }

    function closeModal(id) {
        const content = document.getElementById(id+'-content');
        if(content) { content.classList.remove('scale-100'); content.classList.add('scale-95'); }
        document.getElementById(id).classList.add('opacity-0');
        setTimeout(() => document.getElementById(id).classList.add('hidden'), 300);
    }

    function toggleLiveMode(active) {
        isLive = active;
        const btnLive = document.getElementById('btn-live');
        const btnHist = document.getElementById('btn-hist');
        
        if(isLive) {
            btnLive.className = "px-3 py-1.5 rounded-full text-xs font-bold bg-red-500 text-white shadow-sm transition flex items-center gap-1 animate-pulse";
            btnHist.className = "px-3 py-1.5 rounded-full text-xs font-bold text-slate-400 hover:text-slate-600 transition";
            fetchAlerts();
            pollInterval = setInterval(fetchAlerts, 5000); // Polling 5s
        } else {
            btnLive.className = "px-3 py-1.5 rounded-full text-xs font-bold text-slate-400 hover:text-slate-600 transition flex items-center gap-1";
            btnHist.className = "px-3 py-1.5 rounded-full text-xs font-bold bg-white shadow-sm text-slate-700 transition";
            clearInterval(pollInterval);
        }
    }

    function manualLoad() { fetchAlerts(); }
    function toggleSelectAll(s) { document.querySelectorAll('.evt-check').forEach(c => c.checked = s.checked); updateBulkBtn(); }
    function updateBulkBtn() { 
        const c = document.querySelectorAll('.evt-check:checked').length; 
        const btn = document.getElementById('btn-bulk-resolve');
        document.getElementById('bulk-count').innerText = c;
        if(c > 0) btn.classList.remove('hidden'); else btn.classList.add('hidden');
    }

    // Inicialização
    document.addEventListener('DOMContentLoaded', () => { manualLoad(); });
</script>