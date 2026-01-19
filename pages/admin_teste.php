<?php
require 'db.php';

// Segurança
if (!isset($_SESSION['user_id'])) exit;
$user_role = $_SESSION['user_role'] ?? 'user';
if ($user_role == 'user') exit("Acesso negado.");

$tenant_id = $_SESSION['tenant_id'];

// --- BUSCA RASTREADORES ---
$search = $_GET['search'] ?? '';
$sql = "
    SELECT 
        s.id as stock_id, s.identifier, s.model, s.traccar_device_id,
        t.status as connection_status, t.lastupdate,
        p.latitude, p.longitude, p.attributes, p.speed, p.protocol
    FROM saas_stock s
    LEFT JOIN tc_devices t ON s.traccar_device_id = t.id
    LEFT JOIN tc_positions p ON t.positionid = p.id
    WHERE s.tenant_id = ?
";
$params = [$tenant_id];

if (!empty($search)) {
    $cleanSearch = preg_replace('/[^0-9a-zA-Z]/', '', $search);
    $sql .= " AND (s.identifier ILIKE ? OR s.model ILIKE ?)";
    $params[] = "%$cleanSearch%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY t.lastupdate DESC NULLS LAST LIMIT 50";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- AUTH TRACCAR ---
$TRACCAR_HOST = 'http://127.0.0.1:8082/api';
$ADMIN_USER = 'admin'; 
$ADMIN_PASS = 'admin';

$ch = curl_init("$TRACCAR_HOST/session");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, "email=".urlencode($ADMIN_USER)."&password=".urlencode($ADMIN_PASS));
curl_setopt($ch, CURLOPT_COOKIEJAR, '/tmp/traccar_cookie.txt'); 
curl_exec($ch);
curl_close($ch);

$cookieData = @file_get_contents('/tmp/traccar_cookie.txt');
$sessionToken = '';
if ($cookieData && preg_match('/JSESSIONID\s+([^\s]+)/', $cookieData, $matches)) {
    $sessionToken = $matches[1];
}

$wsProtocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'wss' : 'ws';
$wsUrl = "$wsProtocol://" . $_SERVER['HTTP_HOST'] . "/api/socket";

function timeAgo($date) {
    if (!$date) return '-';
    $diff = time() - strtotime($date);
    if ($diff < 60) return 'Agora';
    if ($diff < 3600) return floor($diff/60).'m atrás';
    return floor($diff/3600).'h atrás';
}
?>

<div class="flex flex-col h-screen bg-slate-50 font-sans text-slate-800">
    
    <div class="bg-white px-8 py-5 border-b border-slate-200 flex justify-between items-center shadow-sm z-20">
        <div>
            <h1 class="text-2xl font-bold text-slate-800 flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-indigo-600 text-white flex items-center justify-center text-lg shadow-lg shadow-indigo-200">
                    <i class="fas fa-microchip"></i>
                </div>
                Lab. de Testes
            </h1>
            <p class="text-sm text-slate-500 mt-1 ml-14">Diagnóstico técnico e validação de hardware.</p>
        </div>
        
        <div class="relative w-96 group">
            <i class="fas fa-search absolute left-4 top-3.5 text-slate-400 group-focus-within:text-indigo-500 transition-colors"></i>
            <input type="text" id="search-input" value="<?php echo htmlspecialchars($search); ?>" 
                   onkeydown="if(event.key==='Enter') window.location.href='?page=teste&search='+this.value" 
                   class="w-full pl-11 pr-12 py-2.5 rounded-xl border border-slate-200 bg-slate-50 focus:bg-white focus:border-indigo-500 focus:ring-4 focus:ring-indigo-50 outline-none transition-all shadow-sm font-medium text-sm" 
                   placeholder="Buscar IMEI ou Modelo...">
            
            <div class="absolute right-4 top-3" title="Status da Conexão Global">
                <div id="ws-status" class="w-3 h-3 rounded-full bg-slate-300"></div>
            </div>
        </div>
    </div>

    <div class="flex-1 overflow-y-auto p-8 bg-slate-50">
        <?php if(empty($devices)): ?>
            <div class="flex flex-col items-center justify-center h-full text-slate-400">
                <div class="w-20 h-20 bg-white rounded-full flex items-center justify-center mb-4 shadow-sm">
                    <i class="fas fa-search text-3xl text-slate-300"></i>
                </div>
                <p class="font-medium">Nenhum equipamento encontrado.</p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                <?php foreach($devices as $d): 
                    $hasSync = !empty($d['traccar_device_id']);
                    $isOnline = $d['connection_status'] === 'online';
                    $statusColor = $isOnline ? 'bg-emerald-500 shadow-emerald-200' : 'bg-slate-300';
                    $lastUpdate = timeAgo($d['lastupdate']);
                    
                    $jsonObj = [
                        'id' => $d['traccar_device_id'],
                        'imei' => $d['identifier'],
                        'model' => $d['model'],
                        'proto' => $d['protocol'],
                        'lat' => $d['latitude'],
                        'lon' => $d['longitude'],
                        'speed' => $d['speed'],
                        'lastUpdate' => $d['lastupdate'],
                        'attributes' => json_decode($d['attributes'] ?? '{}', true)
                    ];
                ?>
                <div class="bg-white rounded-2xl p-5 border border-slate-200 shadow-sm hover:shadow-lg hover:-translate-y-1 transition-all duration-300 group cursor-default relative overflow-hidden" id="row-<?php echo $d['traccar_device_id']; ?>">
                    <div class="absolute left-0 top-0 bottom-0 w-1.5 <?php echo $isOnline ? 'bg-emerald-500' : 'bg-slate-200'; ?>"></div>
                    <div class="flex justify-between items-start mb-4 pl-3">
                        <div>
                            <h3 class="font-bold text-slate-800 font-mono text-lg tracking-tight"><?php echo $d['identifier']; ?></h3>
                            <span class="text-xs font-bold text-slate-500 bg-slate-100 px-2 py-0.5 rounded uppercase tracking-wide"><?php echo $d['model']; ?></span>
                        </div>
                        <div class="w-3 h-3 rounded-full <?php echo $statusColor; ?> shadow-lg"></div>
                    </div>
                    <div class="pl-3 mb-6">
                        <div class="flex items-center text-xs text-slate-500 mb-1">
                            <i class="far fa-clock w-5 text-center mr-1"></i>
                            <span id="time-<?php echo $d['traccar_device_id']; ?>"><?php echo $lastUpdate; ?></span>
                        </div>
                        <div class="flex items-center text-xs text-slate-500">
                            <i class="fas fa-network-wired w-5 text-center mr-1"></i>
                            <span><?php echo $d['protocol'] ? strtoupper($d['protocol']) : '-'; ?></span>
                        </div>
                    </div>
                    <div class="pl-3">
                        <?php if($hasSync): ?>
                            <button onclick='openDiag(<?php echo json_encode($jsonObj); ?>)' class="w-full py-2.5 rounded-xl bg-slate-900 text-white font-bold text-sm hover:bg-indigo-600 transition-colors shadow-lg shadow-slate-200 group-hover:shadow-indigo-200 flex items-center justify-center gap-2">
                                <i class="fas fa-terminal text-xs"></i> Abrir Console
                            </button>
                        <?php else: ?>
                            <button disabled class="w-full py-2.5 rounded-xl bg-slate-100 text-slate-400 font-bold text-sm cursor-not-allowed">
                                Sem Vínculo
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div id="modal-diag" class="fixed inset-0 bg-black/80 backdrop-blur-md hidden z-[9999] flex items-center justify-center transition-opacity opacity-0 duration-300">
    <div class="bg-[#0f172a] w-full max-w-6xl h-[90vh] rounded-2xl shadow-2xl border border-slate-700 flex flex-col overflow-hidden transform scale-95 transition-transform duration-300" id="modal-content">
        
        <div class="bg-[#1e293b] px-6 py-4 border-b border-slate-700 flex justify-between items-center shrink-0">
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 rounded-lg bg-indigo-500/10 text-indigo-400 flex items-center justify-center text-xl border border-indigo-500/20">
                    <i class="fas fa-laptop-code"></i>
                </div>
                <div>
                    <h3 class="font-bold text-lg text-white flex items-center gap-3">
                        <span id="diag-title">...</span>
                        <span id="live-tag" class="text-[10px] bg-emerald-500/10 text-emerald-400 px-2 py-0.5 rounded border border-emerald-500/20 flex items-center gap-1.5 animate-pulse hidden">
                            <span class="w-1.5 h-1.5 bg-emerald-400 rounded-full"></span> LIVE
                        </span>
                    </h3>
                    <p class="text-slate-400 text-xs mt-0.5 font-mono opacity-70">
                        DEVICE ID: <span id="diag-id">...</span> | PROTO: <span id="diag-proto">...</span>
                    </p>
                </div>
            </div>
            
            <div class="flex bg-slate-800/50 rounded-lg p-1 border border-slate-700">
                <button onclick="switchTab('live')" id="btn-tab-live" class="px-5 py-1.5 rounded-md text-xs font-bold text-white bg-indigo-600 shadow-lg shadow-indigo-900/20 transition-all">Monitor</button>
                <button onclick="switchTab('cmds')" id="btn-tab-cmds" class="px-5 py-1.5 rounded-md text-xs font-bold text-slate-400 hover:text-white hover:bg-slate-700 transition-all">Comandos</button>
                <button onclick="switchTab('logs')" id="btn-tab-logs" class="px-5 py-1.5 rounded-md text-xs font-bold text-slate-400 hover:text-white hover:bg-slate-700 transition-all">Logs</button>
            </div>

            <button onclick="closeDiag()" class="w-8 h-8 rounded-full bg-slate-800 text-slate-400 hover:bg-red-500/10 hover:text-red-500 flex items-center justify-center transition-colors">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div id="tab-live-content" class="flex-1 flex flex-col md:flex-row overflow-hidden h-full">
            <div class="w-full md:w-80 bg-[#0f172a] border-r border-slate-800 flex flex-col p-5 gap-5 overflow-y-auto shrink-0">
                <div class="grid grid-cols-2 gap-3">
                    <div class="bg-slate-800/50 p-3 rounded-xl border border-slate-700/50">
                        <span class="text-[10px] text-slate-400 font-bold uppercase tracking-wider block mb-1">Ignição</span>
                        <div id="d-ign" class="font-mono text-white text-lg">-</div>
                    </div>
                    <div class="bg-slate-800/50 p-3 rounded-xl border border-slate-700/50">
                        <span class="text-[10px] text-slate-400 font-bold uppercase tracking-wider block mb-1">Velocidade</span>
                        <div id="d-spd" class="font-mono text-cyan-400 text-lg shadow-cyan-500/20 drop-shadow-sm">-</div>
                    </div>
                    <div class="bg-slate-800/50 p-3 rounded-xl border border-slate-700/50">
                        <span class="text-[10px] text-slate-400 font-bold uppercase tracking-wider block mb-1">Bateria</span>
                        <div id="d-bat" class="font-mono text-white text-lg">-</div>
                    </div>
                    <div class="bg-slate-800/50 p-3 rounded-xl border border-slate-700/50">
                        <span class="text-[10px] text-slate-400 font-bold uppercase tracking-wider block mb-1">Voltagem</span>
                        <div id="d-pwr" class="font-mono text-white text-lg">-</div>
                    </div>
                </div>
                <div class="flex-1 bg-slate-900 rounded-xl border border-slate-700 relative overflow-hidden min-h-[200px]">
                    <div id="mini-map" class="w-full h-full opacity-60 hover:opacity-100 transition-opacity duration-500"></div>
                    <div class="absolute bottom-2 left-2 text-[9px] text-emerald-400 font-mono bg-black/80 px-2 py-0.5 rounded border border-emerald-900/50 z-[1000]" id="d-last">Waiting for Sync...</div>
                </div>
            </div>
            
            <div class="flex-1 bg-[#0b1120] relative flex flex-col">
                <div class="h-8 bg-[#1e293b] border-b border-slate-700 flex justify-between items-center px-4">
                    <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Payload Stream</span>
                    <button onclick="copyJson('d-json')" class="text-[10px] text-indigo-400 hover:text-white transition"><i class="far fa-copy mr-1"></i> Copy Raw</button>
                </div>
                <div class="flex-1 overflow-auto p-6 font-mono text-xs leading-relaxed">
                    <pre id="d-json" class="text-slate-300"></pre>
                </div>
            </div>
        </div>

        <div id="tab-cmds-content" class="hidden flex-1 bg-[#0f172a] p-10 flex flex-col items-center justify-start overflow-y-auto">
            <div class="w-full max-w-lg mb-8 grid grid-cols-2 gap-4">
                <button onclick="enviarComandoRapido('engineStop')" class="bg-red-500/10 border border-red-500/20 hover:bg-red-500/20 hover:border-red-500/50 text-red-400 p-4 rounded-xl flex flex-col items-center transition-all group">
                    <i class="fas fa-lock text-2xl mb-2 group-hover:scale-110 transition-transform"></i>
                    <span class="text-xs font-bold uppercase">Bloquear Motor</span>
                </button>
                <button onclick="enviarComandoRapido('engineResume')" class="bg-emerald-500/10 border border-emerald-500/20 hover:bg-emerald-500/20 hover:border-emerald-500/50 text-emerald-400 p-4 rounded-xl flex flex-col items-center transition-all group">
                    <i class="fas fa-unlock text-2xl mb-2 group-hover:scale-110 transition-transform"></i>
                    <span class="text-xs font-bold uppercase">Desbloquear Motor</span>
                </button>
            </div>
            <div class="w-full max-w-lg bg-[#1e293b] rounded-xl border border-slate-700 p-6 shadow-2xl">
                <h4 class="text-slate-200 font-bold mb-4 flex items-center gap-2 text-sm uppercase tracking-wider"><i class="fas fa-terminal text-indigo-500"></i> Envio Manual</h4>
                <div class="space-y-4">
                    <div>
                        <label class="text-[10px] text-slate-400 uppercase font-bold block mb-1.5">Tipo de Comando</label>
                        <div class="relative">
                            <select id="cmd-select" class="w-full bg-[#0f172a] border border-slate-600 text-white p-2.5 rounded-lg focus:border-indigo-500 outline-none text-sm" onchange="checkCustomCmd()">
                                <option value="positionPeriodic">📍 Requisitar Posição</option>
                                <option value="rebootDevice">🔄 Reiniciar Rastreador</option>
                                <option value="custom">🛠️ Comando Customizado (GPRS)</option>
                            </select>
                            <i class="fas fa-chevron-down absolute right-3 top-3.5 text-slate-500 text-xs pointer-events-none"></i>
                        </div>
                    </div>
                    <div id="custom-cmd-input" class="hidden">
                        <label class="text-[10px] text-indigo-400 uppercase font-bold block mb-1.5">String do Comando</label>
                        <input type="text" id="cmd-val" class="w-full bg-[#0f172a] border border-indigo-500/50 text-white p-2.5 rounded-lg font-mono text-sm" placeholder="Ex: reboot">
                    </div>
                    <button onclick="enviarComando()" id="btn-send" class="w-full bg-indigo-600 hover:bg-indigo-500 text-white font-bold py-3 rounded-lg shadow-lg shadow-indigo-900/50 transition-all flex items-center justify-center gap-2 mt-4"><i class="fas fa-paper-plane"></i> Enviar Comando</button>
                </div>
            </div>
            <div class="w-full max-w-lg mt-6">
                <div class="text-[10px] font-bold text-slate-500 uppercase mb-2">Terminal Output</div>
                <div id="cmd-log" class="bg-black rounded-lg border border-slate-800 h-32 overflow-y-auto font-mono text-xs p-3 space-y-1 text-slate-400"><span class="opacity-50">// Ready.</span></div>
            </div>
        </div>

        <div id="tab-logs-content" class="hidden flex-1 bg-[#0f172a] flex overflow-hidden">
            <div class="w-80 border-r border-slate-800 p-4 flex flex-col shrink-0">
                <div class="flex gap-2 mb-4">
                    <input type="date" id="hist-date" class="bg-[#1e293b] text-white border border-slate-600 rounded-lg p-2 text-sm flex-1 outline-none focus:border-indigo-500">
                    <button onclick="buscarHistorico()" class="bg-indigo-600 hover:bg-indigo-500 text-white px-3 rounded-lg"><i class="fas fa-search"></i></button>
                </div>
                <div id="hist-list" class="space-y-1 flex-1 overflow-y-auto pr-1 custom-scroll">
                    <p class="text-slate-600 text-center text-xs mt-10">Selecione uma data.</p>
                </div>
            </div>
            <div class="flex-1 bg-[#0b1120] p-6 overflow-auto">
                <pre id="hist-json-view" class="font-mono text-xs text-slate-300 whitespace-pre-wrap break-all"></pre>
            </div>
        </div>

    </div>
</div>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
    const WS_URL = "<?php echo $wsUrl; ?>";
    const SESSION_TOKEN = "<?php echo $sessionToken; ?>";
    
    let socket, pollingInterval;
    let selectedDeviceId = null;
    let miniMap, diagMarker;
    let isModalOpen = false;

    // --- ENGINE (HÍBRIDA) ---
    function processIncomingData(positions) {
        if (!positions || !Array.isArray(positions)) return;
        positions.forEach(pos => {
            const timeEl = document.getElementById('time-' + pos.deviceId);
            if(timeEl) {
                timeEl.innerText = new Date(pos.fixTime).toLocaleTimeString();
                const row = document.getElementById('row-' + pos.deviceId);
                if(row) { row.querySelector('.rounded-full').className = "w-3 h-3 rounded-full bg-emerald-500 shadow-lg shadow-emerald-500/50 transition-all"; }
            }
            if (isModalOpen && selectedDeviceId && pos.deviceId == selectedDeviceId) updateModalUI(pos);
        });
    }

    async function performPolling() {
        if(!document.hidden) {
            try {
                const res = await fetch('/api_dados.php?endpoint=/positions'); 
                if(res.ok) {
                    processIncomingData(await res.json());
                    updateStatus('polling');
                }
            } catch(e) { updateStatus('error'); }
        }
    }

    function startPolling() {
        if(pollingInterval) return;
        performPolling();
        pollingInterval = setInterval(performPolling, 4000);
    }

    function stopPolling() {
        if(pollingInterval) { clearInterval(pollingInterval); pollingInterval = null; }
    }

    function connectWS() {
        if (SESSION_TOKEN) document.cookie = "JSESSIONID=" + SESSION_TOKEN + "; path=/; SameSite=Lax";
        try {
            socket = new WebSocket(WS_URL);
            socket.onopen = function() { console.log("WS Open"); stopPolling(); updateStatus('ws'); };
            socket.onmessage = function(e) { const data = JSON.parse(e.data); if (data.positions) processIncomingData(data.positions); };
            socket.onclose = function() { console.log("WS Close -> Fallback"); startPolling(); setTimeout(connectWS, 10000); };
            socket.onerror = function() { socket.close(); };
        } catch(e) { startPolling(); }
    }

    function updateStatus(mode) {
        const el = document.getElementById('ws-status');
        if(mode === 'ws') el.className = "w-3 h-3 rounded-full bg-emerald-500 shadow-[0_0_8px_#10b981] animate-pulse";
        else if(mode === 'polling') el.className = "w-3 h-3 rounded-full bg-amber-500 animate-pulse";
        else el.className = "w-3 h-3 rounded-full bg-red-500";
    }

    // --- UI MODAL ---
    function openDiag(device) {
        selectedDeviceId = device.id; isModalOpen = true;
        const m = document.getElementById('modal-diag'), c = document.getElementById('modal-content');
        m.classList.remove('hidden'); setTimeout(() => { m.classList.remove('opacity-0'); c.classList.remove('scale-95'); c.classList.add('scale-100'); }, 10);
        document.getElementById('diag-title').innerText = `${device.model} - ${device.imei}`;
        document.getElementById('diag-id').innerText = device.id; document.getElementById('diag-proto').innerText = device.proto || 'N/A';
        updateModalUI({deviceId: device.id, latitude: device.lat, longitude: device.lon, speed: device.speed, attributes: device.attributes, fixTime: device.lastUpdate});
        switchTab('live');
        setTimeout(() => {
            if(!miniMap) { miniMap = L.map('mini-map', {zoomControl:false, attributionControl:false}).setView([0,0], 13); L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png').addTo(miniMap); }
            miniMap.invalidateSize();
            if(device.lat) { const ll = [device.lat, device.lon]; miniMap.setView(ll); if(diagMarker) miniMap.removeLayer(diagMarker); diagMarker = L.marker(ll).addTo(miniMap); }
        }, 300);
    }

    function closeDiag() {
        isModalOpen = false; selectedDeviceId = null;
        const m = document.getElementById('modal-diag'), c = document.getElementById('modal-content');
        c.classList.remove('scale-100'); c.classList.add('scale-95'); m.classList.add('opacity-0');
        setTimeout(() => m.classList.add('hidden'), 300);
    }

    function updateModalUI(pos) {
        const attr = pos.attributes || {};
        const ign = attr.ignition ?? attr.motion ?? false;
        document.getElementById('live-tag').classList.remove('hidden');
        document.getElementById('d-ign').innerHTML = ign 
            ? '<span class="text-emerald-400 font-bold"><i class="fas fa-bolt mr-1"></i> ON</span>' 
            : '<span class="text-slate-500 font-bold"><i class="fas fa-power-off mr-1"></i> OFF</span>';
        document.getElementById('d-spd').innerText = (pos.speed * 1.852).toFixed(0) + ' km/h';
        const bat = attr.batteryLevel ?? 0;
        document.getElementById('d-bat').innerHTML = `<span class="${bat>20?'text-emerald-400':'text-red-400'}">${bat}%</span>`;
        document.getElementById('d-pwr').innerText = parseFloat(attr.power || attr.adc1 || 0).toFixed(1) + 'V';
        document.getElementById('d-last').innerText = 'Last: ' + new Date().toLocaleTimeString();
        document.getElementById('d-json').innerHTML = syntaxHighlight(pos);
        if(miniMap && pos.latitude) {
            const ll = [pos.latitude, pos.longitude];
            miniMap.setView(ll);
            if(diagMarker) miniMap.removeLayer(diagMarker);
            diagMarker = L.marker(ll).addTo(miniMap);
        }
    }

    // --- COMANDOS ---
    function checkCustomCmd() { document.getElementById('custom-cmd-input').classList.toggle('hidden', document.getElementById('cmd-select').value !== 'custom'); }
    function enviarComandoRapido(type) { document.getElementById('cmd-select').value = type; checkCustomCmd(); enviarComando(type); }

    async function enviarComando(typeOverride = null) {
        if (!selectedDeviceId) return alert("Erro: ID inválido");
        const type = typeOverride || document.getElementById('cmd-select').value;
        const btn = document.getElementById('btn-send');
        const log = document.getElementById('cmd-log');
        let payload = { deviceId: parseInt(selectedDeviceId), type: type, password: 'SKIP_CHECK' };
        if(type === 'custom') payload.attributes = { data: document.getElementById('cmd-val').value };

        btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin animate-spin"></i> Enviando...';
        try {
            const res = await fetch('/api_dados.php?action=secure_command', {method:'POST', body:JSON.stringify(payload)});
            const txt = await res.text();
            let msg = res.ok ? 'Sucesso' : `Erro: ${txt}`;
            if(txt.includes("not supported")) msg = "Comando não suportado pelo protocolo.";
            log.innerHTML = `<div class="mb-1 border-l-2 ${res.ok?'border-emerald-500 text-emerald-400':'border-red-500 text-red-400'} pl-2 text-[10px]"><span class="opacity-50">[${new Date().toLocaleTimeString()}]</span> <b>${type}</b>: ${msg}</div>` + log.innerHTML;
        } catch(e) { log.innerHTML = `<div class="text-red-500">Erro: ${e.message}</div>`; }
        btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> Enviar Comando';
    }

    // --- HISTÓRICO (AJUSTADO) ---
    async function buscarHistorico() {
        const date = document.getElementById('hist-date').value;
        if(!date || !selectedDeviceId) return;
        const from = new Date(date + 'T00:00:00').toISOString();
        const to = new Date(date + 'T23:59:59').toISOString();
        const list = document.getElementById('hist-list');
        list.innerHTML = '<div class="text-center text-slate-500 pt-10"><i class="fas fa-spinner fa-spin mb-2"></i><br>Carregando...</div>';
        
        try {
            const res = await fetch(`/api_dados.php?endpoint=/positions&deviceId=${selectedDeviceId}&from=${from}&to=${to}`);
            const data = await res.json();
            list.innerHTML = '';
            if(!data.length) { list.innerHTML = '<div class="text-center text-slate-600 pt-10">Vazio.</div>'; return; }
            
            data.reverse().forEach(pos => {
                const div = document.createElement('div');
                div.className = "p-3 border-b border-slate-800/50 cursor-pointer hover:bg-slate-800 hover:border-l-4 hover:border-indigo-500 transition rounded-lg mb-1 group";
                
                // IGNIÇÃO: Verde (ON) / Vermelho (OFF)
                const ignIcon = (pos.attributes.ignition) 
                    ? '<i class="fas fa-key text-emerald-400" title="Ligado"></i>' 
                    : '<i class="fas fa-key text-red-500" title="Desligado"></i>';
                
                // TIPO: String Original (STT, UEX, ALT, etc)
                const rawType = pos.attributes.type || pos.attributes.alarm || 'POS';
                const typeLabel = `<span class="text-xs font-mono font-bold text-indigo-300 bg-indigo-900/30 px-1 rounded">${rawType}</span>`;

                div.innerHTML = `
                    <div class="flex justify-between items-center mb-1">
                        <div class="flex items-center gap-2">
                            <span class="font-mono text-xs text-white font-bold">${new Date(pos.fixTime).toLocaleTimeString()}</span>
                            ${ignIcon}
                        </div>
                        ${typeLabel}
                    </div>
                    <div class="flex justify-between text-[10px]">
                        <span class="text-slate-500 font-mono">${pos.latitude.toFixed(5)}, ${pos.longitude.toFixed(5)}</span>
                        <span class="text-emerald-400 font-bold">${(pos.speed*1.852).toFixed(0)} km/h</span>
                    </div>
                `;
                
                div.onclick = function() {
                    document.querySelectorAll('#hist-list > div').forEach(el => { el.classList.remove('bg-indigo-900/30', 'border-l-4', 'border-indigo-500'); });
                    this.classList.add('bg-indigo-900/30', 'border-l-4', 'border-indigo-500');
                    document.getElementById('hist-json-view').innerHTML = syntaxHighlight(pos);
                };
                
                list.appendChild(div);
            });
        } catch(e) { list.innerHTML = '<div class="text-center text-red-500 pt-10">Erro.</div>'; }
    }

    function syntaxHighlight(json) {
        if (typeof json != 'string') json = JSON.stringify(json, undefined, 2);
        return json.replace(/("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g, function (match) {
            var cls = 'text-[#b5cea8]'; 
            if (/^"/.test(match)) { if (/:$/.test(match)) cls = 'text-[#9cdcfe] font-bold'; else cls = 'text-[#ce9178]'; } 
            else if (/true|false/.test(match)) cls = 'text-[#569cd6] font-bold'; 
            return '<span class="' + cls + '">' + match + '</span>';
        });
    }

    function copyJson(id) {
        const txt = document.getElementById(id).innerText;
        navigator.clipboard.writeText(txt);
        const btn = document.querySelector(`button[onclick="copyJson('${id}')"]`);
        const original = btn.innerText;
        btn.innerText = "Copiado!";
        setTimeout(() => btn.innerText = original, 1000);
    }

    function switchTab(tab) {
        ['live', 'cmds', 'logs'].forEach(t => {
            document.getElementById(`tab-${t}-content`).classList.add('hidden');
            document.getElementById(`btn-tab-${t}`).className = "px-5 py-1.5 rounded-md text-xs font-bold text-slate-400 hover:text-white hover:bg-slate-700 transition-all";
        });
        document.getElementById(`tab-${tab}-content`).classList.remove('hidden');
        document.getElementById(`btn-tab-${tab}`).className = "px-5 py-1.5 rounded-md text-xs font-bold text-white bg-indigo-600 shadow-lg shadow-indigo-900/20 transition-all";
        if(tab === 'live') setTimeout(() => { if(miniMap) miniMap.invalidateSize(); }, 100);
    }

    startPolling();
    connectWS();
</script>

<style>
    .custom-scroll::-webkit-scrollbar { width: 6px; }
    .custom-scroll::-webkit-scrollbar-track { background: #0f172a; }
    .custom-scroll::-webkit-scrollbar-thumb { background: #334155; border-radius: 3px; }
    .custom-scroll::-webkit-scrollbar-thumb:hover { background: #475569; }
</style>