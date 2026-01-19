<?php
// Inclui a conexão centralizada
require 'db.php';

// Verifica sessão
if (!isset($_SESSION['user_id'])) exit;

$user_id = $_SESSION['user_id'];
$tenant_id = $_SESSION['tenant_id'];
$user_role = $_SESSION['user_role'] ?? 'user';
$user_email = $_SESSION['user_email'] ?? '';

// --- 1. SEGURANÇA: IDENTIFICA CLIENTE FINAL ---
$logged_client_id = null;
if ($user_role !== 'admin' && $user_role !== 'superadmin') {
    $stmtMe = $pdo->prepare("SELECT customer_id FROM saas_users WHERE id = ?");
    $stmtMe->execute([$user_id]);
    $logged_client_id = $stmtMe->fetchColumn();
}

// --- 2. BUSCA VEÍCULOS E ÍCONES (CORREÇÃO DE ACESSO) ---
// Agora busca se o veículo é do CLIENTE vinculado OU se foi atribuído DIRETAMENTE ao USUÁRIO
$sql = "
    SELECT v.traccar_device_id, i.url 
    FROM saas_vehicles v 
    LEFT JOIN saas_custom_icons i ON CAST(v.category AS VARCHAR) = CAST(i.id AS VARCHAR) 
    WHERE v.tenant_id = ?
";
$params = [$tenant_id];

// Lógica de Permissão Híbrida (Cliente OU Usuário Específico)
if ($user_role !== 'admin' && $user_role !== 'superadmin') {
    $conditions = ["v.user_id = ?"]; // Sempre pode ver veículos atribuídos diretamente
    $params[] = $user_id;

    if ($logged_client_id) {
        $conditions[] = "v.client_id = ?"; // Também pode ver veículos do cliente vinculado
        $params[] = $logged_client_id;
    }
    
    // Adiciona parenteses para o OR funcionar corretamente com o AND do tenant
    $sql .= " AND (" . implode(' OR ', $conditions) . ")";
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$vehiclesData = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // Retorna array [device_id => icon_url]

// Prepara dados para o JavaScript
$jsIconMap = json_encode($vehiclesData);
$jsAllowedIds = json_encode(array_keys($vehiclesData)); // Lista de IDs permitidos

// --- 3. BUSCA NOMES DE CLIENTES (PARA A LISTA) ---
$sqlCust = "SELECT v.traccar_device_id, c.name 
            FROM saas_vehicles v 
            LEFT JOIN saas_customers c ON v.client_id = c.id 
            WHERE v.tenant_id = ?";
$paramsCust = [$tenant_id];

if ($user_role !== 'admin' && $user_role !== 'superadmin') {
    $conditionsCust = ["v.user_id = ?"];
    $paramsCust[] = $user_id;
    if ($logged_client_id) {
        $conditionsCust[] = "v.client_id = ?";
        $paramsCust[] = $logged_client_id;
    }
    $sqlCust .= " AND (" . implode(' OR ', $conditionsCust) . ")";
}

$stmtCust = $pdo->prepare($sqlCust);
$stmtCust->execute($paramsCust);
$jsCustomerMap = json_encode($stmtCust->fetchAll(PDO::FETCH_KEY_PAIR));

// --- 4. BUSCA MOTORISTAS VINCULADOS ---
$jsCurrentDrivers = '{}';
try {
    // Verifica se a tabela de motoristas existe antes de consultar
    $check = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_name = 'saas_drivers'")->fetch();
    if ($check) {
        $sqlCD = "SELECT v.traccar_device_id, d.name 
                  FROM saas_vehicles v 
                  JOIN saas_drivers d ON v.current_driver_id = d.id 
                  WHERE v.tenant_id = ?";
        $paramsCD = [$tenant_id];

        if ($user_role !== 'admin' && $user_role !== 'superadmin') {
            $conditionsCD = ["v.user_id = ?"];
            $paramsCD[] = $user_id;
            if ($logged_client_id) {
                $conditionsCD[] = "v.client_id = ?";
                $paramsCD[] = $logged_client_id;
            }
            $sqlCD .= " AND (" . implode(' OR ', $conditionsCD) . ")";
        }

        $stmtCD = $pdo->prepare($sqlCD);
        $stmtCD->execute($paramsCD);
        $jsCurrentDrivers = json_encode($stmtCD->fetchAll(PDO::FETCH_KEY_PAIR));
    }
} catch(Exception $e) { /* Ignora se tabela não existir */ }

// --- 5. AUTENTICAÇÃO NO TRACCAR (PROXY) ---
$TRACCAR_HOST = 'http://127.0.0.1:8082/api';
$ADMIN_USER = 'admin'; 
$ADMIN_PASS = 'admin'; 

$ch = curl_init("$TRACCAR_HOST/session");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, "email=".urlencode($ADMIN_USER)."&password=".urlencode($ADMIN_PASS));
curl_setopt($ch, CURLOPT_COOKIEJAR, '/tmp/traccar_cookie.txt'); 
$resp = curl_exec($ch);
curl_close($ch);

$cookieData = @file_get_contents('/tmp/traccar_cookie.txt');
$sessionToken = '';
if ($cookieData && preg_match('/JSESSIONID\s+([^\s]+)/', $cookieData, $matches)) {
    $sessionToken = $matches[1];
}

$wsProtocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'wss' : 'ws';
$wsUrl = "$wsProtocol://" . $_SERVER['HTTP_HOST'] . "/api/socket";
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<style>
    /* Estilos Específicos do Mapa */
    .map-btn { width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; border-radius: 8px; background: white; color: #475569; box-shadow: 0 2px 5px rgba(0,0,0,0.15); border: 1px solid #e2e8f0; cursor: pointer; transition: all 0.2s; font-size: 16px; }
    .map-btn:hover { background: #f8fafc; color: var(--primary); }
    .map-btn.active { background: var(--primary); color: white; border-color: var(--primary); }
    
    .map-table th { white-space: nowrap; background: #f8fafc; color: #475569; font-size: 0.65rem; font-weight: 700; text-transform: uppercase; padding: 10px; border-bottom: 2px solid #e2e8f0; position: sticky; top: 0; z-index: 10; }
    .map-table td { padding: 8px 10px; font-size: 0.75rem; color: #334155; border-bottom: 1px solid #f1f5f9; white-space: nowrap; vertical-align: middle; }
    .map-table tr:hover { background: #eff6ff; cursor: pointer; }
    .row-selected { background: #dbeafe !important; border-left: 3px solid var(--primary); }
    
    .btn-lock { background: #fee2e2; color: #ef4444; border: 1px solid #fecaca; padding: 4px 8px; border-radius: 4px; font-weight: bold; transition: all 0.2s; font-size: 10px; }
    .btn-lock:hover { background: #ef4444; color: white; }
    .btn-unlock { background: #dcfce7; color: #16a34a; border: 1px solid #bbf7d0; padding: 4px 8px; border-radius: 4px; font-weight: bold; transition: all 0.2s; font-size: 10px; }
    .btn-unlock:hover { background: #16a34a; color: white; }
    
    .drawer-open { height: 45vh !important; }
    .drawer-closed { height: 45px !important; }
    
    .leaflet-popup-content-wrapper { border-radius: 8px; padding: 0; overflow: hidden; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.2); }
    .leaflet-popup-content { margin: 0; width: 280px !important; }
    .leaflet-popup-tip { background: white; }
    
    .status-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
    .status-ws { background-color: #22c55e; box-shadow: 0 0 5px #22c55e; } 
    .status-http { background-color: #f97316; } 
    .status-off { background-color: #ef4444; }
</style>

<div class="relative w-full h-full overflow-hidden flex flex-col">
    <div id="map" class="absolute inset-0 z-0 bg-gray-200"></div>

    <div class="absolute top-4 left-4 z-[500] w-72">
        <div class="bg-white rounded-lg shadow-md border border-gray-200 p-2 flex items-center gap-2">
            <i class="fas fa-search text-gray-400 ml-2"></i>
            
            <input type="text" name="fake_user_field" style="display:none" aria-hidden="true">
            <input type="password" name="fake_password_field" style="display:none" aria-hidden="true">
            
            <input type="text" id="map-search" onkeyup="filterMap()" placeholder="Buscar Veículo, Placa..." class="w-full text-sm outline-none text-gray-700 bg-transparent" autocomplete="off" name="search_vehicles_map">
            <span id="follow-badge" class="hidden text-[10px] font-bold bg-blue-600 text-white px-2 py-1 rounded uppercase animate-pulse">SEGUINDO</span>
            <div id="ws-status" class="status-dot status-http" title="Status Conexão"></div>
        </div>
    </div>

    <div class="absolute top-4 right-4 z-[500] flex flex-col gap-2">
        <div class="bg-white p-1 rounded-lg shadow-md border border-gray-200 flex flex-col gap-1">
            <button onclick="setLayer('streets')" class="map-btn active" id="btn-streets" title="Ruas"><i class="fas fa-road"></i></button>
            <button onclick="setLayer('satellite')" class="map-btn" id="btn-sat" title="Satélite"><i class="fas fa-satellite"></i></button>
            <button onclick="setLayer('traffic')" class="map-btn" id="btn-traffic" title="Trânsito"><i class="fas fa-traffic-light"></i></button>
        </div>
        <button onclick="stopFollowing(); fitAll()" class="map-btn" title="Ver Todos"><i class="fas fa-expand-arrows-alt"></i></button>
    </div>

    <div id="bottom-drawer" class="absolute bottom-0 left-0 right-0 z-[600] bg-white rounded-t-xl flex flex-col transition-all duration-300 shadow-[0_-5px_20px_rgba(0,0,0,0.1)] drawer-closed">
        <div class="h-[45px] border-b border-gray-200 flex justify-between items-center px-4 cursor-pointer hover:bg-gray-50 transition" onclick="toggleDrawer()">
            <div class="flex items-center gap-3">
                <i class="fas fa-chevron-up text-gray-400 transition-transform duration-300" id="drawer-icon"></i>
                <h3 class="font-bold text-gray-700 text-sm">Lista de Veículos (<span id="total-count">0</span>)</h3>
            </div>
            <div class="flex gap-4 text-xs font-mono">
                <span class="text-green-600 font-bold"><i class="fas fa-wifi text-[8px] mr-1"></i> <span id="cnt-on">0</span></span>
                <span class="text-red-500 font-bold"><i class="fas fa-power-off text-[8px] mr-1"></i> <span id="cnt-off">0</span></span>
            </div>
        </div>
        <div class="flex-1 overflow-auto bg-white">
            <table class="w-full text-left map-table">
                <thead>
                    <tr>
                        <th class="w-8 text-center">St</th>
                        <th>Veículo</th>
                        <th>Cliente</th>
                        <th>Motorista</th>
                        <th>Protocolo</th>
                        <th>Endereço Atual</th>
                        <th class="text-center">Data</th> <th class="text-center">Vel.</th>
                        <th class="text-center">Ign.</th>
                        <th class="text-center">Fonte</th>
                        <th class="text-center">Bat.</th>
                        <th class="text-center">Sats</th>
                        <th class="text-center">Bloq.</th>
                    </tr>
                </thead>
                <tbody id="grid-body"><tr><td colspan="13" class="p-8 text-center text-gray-400">Carregando dados...</td></tr></tbody>
            </table>
        </div>
    </div>
</div>

<div id="modal-security" class="fixed inset-0 bg-black/60 hidden z-[9999] flex items-center justify-center backdrop-blur-sm p-4">
    <div class="bg-white rounded-xl shadow-2xl w-96 overflow-hidden animate-in fade-in zoom-in duration-200">
        <div class="bg-red-50 p-4 border-b border-red-100 flex justify-between items-center">
            <h3 class="text-red-700 font-bold flex items-center gap-2"><i class="fas fa-shield-alt"></i> Segurança</h3>
            <button onclick="closeSecModal()" class="text-gray-400 hover:text-gray-600">&times;</button>
        </div>
        <div class="p-6">
            <p class="text-sm text-gray-600 mb-4">Confirmar comando: <strong id="sec-cmd-name" class="uppercase"></strong> para <strong id="sec-veh-name"></strong>?</p>
            <input type="hidden" id="sec-dev-id"><input type="hidden" id="sec-cmd-type">
            <label class="text-xs font-bold text-gray-500 uppercase mb-1 block">Sua Senha</label>
            <input type="password" id="sec-password" class="w-full border p-2 rounded mb-4 outline-none focus:border-red-500 transition" placeholder="••••••••">
            <button onclick="executeCommand()" id="btn-sec-confirm" class="w-full bg-red-600 text-white font-bold py-2 rounded shadow hover:bg-red-700 transition">Confirmar</button>
        </div>
    </div>
</div>

<script>
    // --- CONFIGURAÇÃO E DADOS ---
    const WS_URL = "<?php echo $wsUrl; ?>";
    const SESSION_TOKEN = "<?php echo $sessionToken; ?>"; 
    
    // Dados injetados pelo PHP (já filtrados por cliente/usuário)
    const iconData = <?php echo $jsIconMap ?: '{}'; ?>;
    const customerData = <?php echo $jsCustomerMap ?: '{}'; ?>;
    const allowedDeviceIds = <?php echo $jsAllowedIds ?: '[]'; ?>.map(Number);
    const dbCurrentDrivers = <?php echo $jsCurrentDrivers ?: '{}'; ?>;

    let map, markers = {};
    let vehicleState = JSON.parse(localStorage.getItem('fleetVehicleState') || '{}');
    let geoCache = JSON.parse(sessionStorage.getItem('geoCache') || '{}'), geoQueue = [];
    let isDrawerOpen = false, followingId = null;
    let socket, pollingInterval = null; 
    let lastDevices = [], positionsMap = {};

    // 1. INICIALIZAÇÃO MAPA
    const street = L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', {maxZoom:20});
    const sat = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {maxZoom:19});
    const traffic = L.tileLayer('https://{s}.google.com/vt/lyrs=m,traffic&x={x}&y={y}&z={z}', {maxZoom:20, subdomains:['mt0','mt1','mt2','mt3']});

    map = L.map('map', {layers: [street], zoomControl: false}).setView([-14.2350, -51.9253], 4);
    L.control.zoom({position: 'bottomright'}).addTo(map);
    map.on('dragstart', () => { if(followingId) { followingId = null; document.getElementById('follow-badge').classList.add('hidden'); } });
    
    initLayer();

    // 2. PARSER DE TELEMETRIA
    function parseTelemetria(p) {
        const attr = p.attributes || {};
        let ign = attr.ignition; if (ign === undefined) ign = attr.motion;
        
        let bat = null;
        if (attr.batteryLevel !== undefined) bat = attr.batteryLevel;
        else if (attr.battery !== undefined) {
            let v = attr.battery > 50 ? attr.battery/1000 : attr.battery;
            if(v > 0) bat = Math.max(0, Math.min(100, ((v - 3.6)/(4.2 - 3.6))*100)).toFixed(0);
        }

        let power = null;
        if (attr.power !== undefined) power = attr.power;
        else if (attr.adc1 !== undefined) power = attr.adc1;
        else if (attr.extBatt !== undefined) power = attr.extBatt;
        if (power === null && attr.charge === true) power = 12.0;

        let driverId = attr.driverUniqueId;
        if (!driverId && attr.serial) {
            const parts = attr.serial.split('|');
            if (parts.length > 4 && parts[4].length > 3) driverId = parts[4];
        }

        return { ignition: ign, battery: bat, power: power, sat: attr.sat, blocked: attr.blocked, alarm: attr.alarm, driverId: driverId };
    }

    function updatePositions(newPositions) {
        newPositions.forEach(p => {
            // Filtro de Segurança Frontend (Extra)
            if (!allowedDeviceIds.includes(p.deviceId)) return;

            positionsMap[p.deviceId] = p;
            if(!vehicleState[p.deviceId]) vehicleState[p.deviceId] = {};
            
            if (!vehicleState[p.deviceId].driverName && dbCurrentDrivers[p.deviceId]) {
                vehicleState[p.deviceId].driverName = dbCurrentDrivers[p.deviceId];
            }

            const parsed = parseTelemetria(p);
            
            if (parsed.ignition === false) {
                if (vehicleState[p.deviceId].driverName || vehicleState[p.deviceId].lastRfid) {
                    vehicleState[p.deviceId].driverName = null;
                    vehicleState[p.deviceId].lastRfid = null;
                }
            }

            if (parsed.ignition !== undefined) vehicleState[p.deviceId].ignition = parsed.ignition;
            if (parsed.battery !== null) vehicleState[p.deviceId].battery = parsed.battery;
            if (parsed.power !== null) vehicleState[p.deviceId].power = parsed.power;
            if (parsed.sat !== undefined) vehicleState[p.deviceId].sat = parsed.sat;
            if (parsed.blocked !== undefined) vehicleState[p.deviceId].blocked = parsed.blocked;
            if (parsed.alarm !== undefined) vehicleState[p.deviceId].alarm = parsed.alarm;
            
            if (parsed.driverId && parsed.ignition !== false) {
                if (vehicleState[p.deviceId].lastRfid !== parsed.driverId) {
                    vehicleState[p.deviceId].lastRfid = parsed.driverId;
                }
            }
        });
        localStorage.setItem('fleetVehicleState', JSON.stringify(vehicleState));
    }

    // 3. CONEXÃO (WEBSOCKET + POLLING)
    function startPolling() { if(pollingInterval) return; document.getElementById('ws-status').className = "status-dot status-http"; loadDataHttp(); pollingInterval = setInterval(loadDataHttp, 4000); }
    function stopPolling() { if(pollingInterval) { clearInterval(pollingInterval); pollingInterval = null; } }

    function connectWS() {
        const statusEl = document.getElementById('ws-status');
        if (SESSION_TOKEN) document.cookie = "JSESSIONID=" + SESSION_TOKEN + "; path=/; SameSite=Lax";
        try {
            socket = new WebSocket(WS_URL);
            socket.onopen = function() { console.log('WS Conectado'); statusEl.className = "status-dot status-ws"; stopPolling(); loadDataHttp(); };
            socket.onmessage = function(event) {
                const data = JSON.parse(event.data);
                if (data.devices) {
                    const filteredDevs = data.devices.filter(d => allowedDeviceIds.includes(d.id));
                    if(filteredDevs.length > 0) { lastDevices = updateArray(lastDevices, filteredDevs); renderMap(); }
                }
                if (data.positions) {
                    const filteredPos = data.positions.filter(p => allowedDeviceIds.includes(p.deviceId));
                    if(filteredPos.length > 0) { updatePositions(filteredPos); renderMap(); }
                }
            };
            socket.onclose = function() { if(socket.readyState === WebSocket.CLOSED) startPolling(); };
        } catch(e) { startPolling(); }
    }

    function updateArray(current, updates) { const map = new Map(current.map(i => [i.id, i])); updates.forEach(u => map.set(u.id, u)); return Array.from(map.values()); }

    async function loadDataHttp() {
        try {
            const [devRes, posRes] = await Promise.all([ fetch('/api_dados.php?endpoint=/devices'), fetch('/api_dados.php?endpoint=/positions') ]);
            if(devRes.ok && posRes.ok) {
                const devs = await devRes.json();
                const pos = await posRes.json();
                lastDevices = devs.filter(d => allowedDeviceIds.includes(d.id));
                const filteredPos = pos.filter(p => allowedDeviceIds.includes(p.deviceId));
                updatePositions(filteredPos);
                renderMap();
            }
        } catch(e) { console.error("Erro HTTP", e); }
    }

    // 4. RENDERIZAÇÃO
    function renderMap() {
        const tbody = document.getElementById('grid-body');
        const searchInput = document.getElementById('map-search');
        if(!tbody || !searchInput) return;
        const filter = (searchInput.value || '').toLowerCase();
        let html = '', on = 0, off = 0;

        lastDevices.forEach(d => {
            if (!allowedDeviceIds.includes(d.id)) return;
            const p = positionsMap[d.id]; if(!p) return; 
            const st = vehicleState[d.id] || {};
            
            const searchStr = (d.name + (customerData[d.id]||'') + (st.driverName||'')).toLowerCase();
            if(filter && !searchStr.includes(filter) && !d.uniqueId.includes(filter)) return;

            if(d.status === 'online') on++; else off++;
            const speed = (p.speed * 1.852).toFixed(0);
            const dateObj = new Date(p.fixTime);
            const dateFull = dateObj.toLocaleString('pt-BR'); 
            const ignHtml = st.ignition ? '<span class="text-green-600 font-bold text-[10px]">ON</span>' : '<span class="text-gray-400 text-[10px]">OFF</span>';
            const proto = p.protocol ? p.protocol.toUpperCase() : '-';
            
            let batHtml = '<span class="text-gray-300 text-xs">-</span>';
            if (st.battery !== undefined && st.battery !== null) {
                const bVal = parseInt(st.battery);
                if (bVal > 20) batHtml = `<div class="flex items-center justify-center gap-1 text-green-600 font-bold text-xs"><i class="fas fa-battery-full"></i> ${bVal}%</div>`;
                else batHtml = `<div class="flex items-center justify-center gap-1 text-red-500 font-bold text-xs animate-pulse"><i class="fas fa-battery-empty"></i> ${bVal}%</div>`;
            }

            let pwrHtml = '<span class="text-gray-300 text-xs">-</span>';
            if (st.power !== undefined && st.power !== null) {
                const pVal = parseFloat(st.power);
                if (pVal < 1 || st.alarm === 'powerCut') pwrHtml = `<div class="flex items-center justify-center gap-1 text-red-500 font-bold text-xs"><i class="fas fa-plug"></i> OFF</div>`;
                else pwrHtml = `<div class="flex items-center justify-center gap-1 text-green-600 font-bold text-xs"><i class="fas fa-plug"></i> ${pVal.toFixed(1)}V</div>`;
            }

            let driverHtml = '<span class="text-gray-300 text-[10px]">-</span>';
            if (st.driverName) {
                driverHtml = `<div class="flex items-center gap-1 text-indigo-600 font-bold text-[10px]"><i class="fas fa-id-card"></i> ${st.driverName}</div>`;
            }

            const custName = customerData[d.id] ? `<span class="text-slate-600 font-bold text-[10px]"><i class="fas fa-user-tag mr-1 text-slate-400"></i>${customerData[d.id]}</span>` : '<span class="text-gray-300 text-[10px]">-</span>';
            const k = p.latitude.toFixed(4)+','+p.longitude.toFixed(4);
            if(!geoCache[k]) geoQueue.push({id:d.id, lat:p.latitude, lon:p.longitude});
            const addr = geoCache[k] || '...';
            const isSel = (followingId === d.id) ? 'row-selected' : '';
            
            const lockBtn = st.blocked 
                ? `<button onclick="openLockModal(event, ${d.id}, '${d.name}', true)" class="btn-unlock"><i class="fas fa-unlock"></i> Liberar</button>` 
                : `<button onclick="openLockModal(event, ${d.id}, '${d.name}', false)" class="btn-lock"><i class="fas fa-lock"></i> Bloquear</button>`;

            // GRID CORRIGIDA COM COLUNA DE DATA
            html += `<tr id="row-${d.id}" onclick="focusDev(${p.latitude}, ${p.longitude}, ${d.id})" class="transition border-b ${isSel}">
                <td class="text-center"><div class="w-2 h-2 rounded-full mx-auto ${d.status==='online'?'bg-green-500':'bg-red-500'}"></div></td>
                <td class="font-bold text-gray-700 text-xs">${d.name}</td>
                <td>${custName}</td>
                <td>${driverHtml}</td>
                <td class="text-xs font-mono text-gray-500">${proto}</td>
                <td class="text-xs text-gray-600 truncate max-w-[150px]" id="addr-${d.id}">${addr}</td>
                <td class="text-center text-xs font-mono">${dateFull}</td>
                <td class="text-center text-xs font-bold text-blue-600">${speed} km/h</td>
                <td class="text-center">${ignHtml}</td>
                <td class="text-center">${pwrHtml}</td>
                <td class="text-center">${batHtml}</td>
                <td class="text-center text-xs">${st.sat||0}</td>
                <td class="text-center">${lockBtn}</td>
            </tr>`;

            updateMarker(d, p, speed, st.ignition, st.battery, st.sat, st.blocked, addr, dateFull, st.power, st.alarm, st.driverName);
            if(followingId === d.id) map.panTo([p.latitude, p.longitude], {animate:true, duration:0.5});
        });

        if(html === '') html = '<tr><td colspan="13" class="p-8 text-center text-gray-400">Nenhum veículo encontrado.</td></tr>';
        tbody.innerHTML = html;
        document.getElementById('total-count').innerText = lastDevices.length;
        document.getElementById('cnt-on').innerText = on;
        document.getElementById('cnt-off').innerText = off;
    }

    function updateMarker(d, p, speed, ign, bat, sats, blocked, addr, dateFull, power, alarm, driverName) {
        const latlng = [p.latitude, p.longitude];
        let iconHtml;
        
        if(iconData[d.id]) {
            iconHtml = `<div style="transform: rotate(${p.course}deg); transition: transform 0.3s ease; filter: drop-shadow(0 3px 5px rgba(0,0,0,0.3));">
                            <img src="${iconData[d.id]}" style="width:40px; height:40px; object-fit:contain;">
                        </div>`;
        } else {
            const color = d.status==='online'?'#22c55e':'#ef4444';
            iconHtml = `<div style="transform: rotate(${p.course}deg); background:${color}; width:30px; height:30px; border-radius:50%; border:2px solid white; display:flex; justify-content:center; align-items:center; box-shadow:0 2px 5px rgba(0,0,0,0.3);">
                            <i class="fas fa-chevron-up text-white text-[10px]"></i>
                        </div>`;
        }
        
        const icon = L.divIcon({className:'bg-transparent border-0', html:iconHtml, iconSize:[40,40], iconAnchor:[20,20]});
        const statusBadge = d.status==='online' ? '<span class="bg-green-100 text-green-700 text-[10px] font-bold px-2 py-0.5 rounded">ONLINE</span>' : '<span class="bg-red-100 text-red-700 text-[10px] font-bold px-2 py-0.5 rounded">OFFLINE</span>';
        
        let driverInfo = '';
        if (driverName) driverInfo = `<div class="mt-2 pt-2 border-t border-dashed text-indigo-600 font-bold text-xs flex items-center gap-2"><i class="fas fa-id-card"></i> ${driverName}</div>`;

        const popup = `
            <div class="font-sans text-sm text-gray-700">
                <div class="bg-gray-50 p-3 border-b flex justify-between items-center">
                    <div><div class="font-bold text-gray-800">${d.name}</div><div class="text-[10px] text-gray-400 font-mono">${d.uniqueId}</div></div>
                    ${statusBadge}
                </div>
                <div class="p-3 space-y-2">
                    <div class="grid grid-cols-2 gap-2 text-xs">
                        <div><span class="text-gray-400 text-[9px] uppercase font-bold">Velocidade</span><br><span class="font-bold text-blue-600 text-lg">${speed}</span> <small>km/h</small></div>
                        <div><span class="text-gray-400 text-[9px] uppercase font-bold">Status</span><br>${blocked ? '<span class="text-red-500 font-bold">BLOQUEADO</span>' : '<span class="text-green-500 font-bold">LIBERADO</span>'}</div>
                    </div>
                    <div class="border-t border-dashed my-2"></div>
                    <div class="grid grid-cols-3 gap-y-1 text-xs text-gray-600">
                        <div class="flex items-center"><i class="fas fa-plug ${(power<1||alarm=='powerCut')?'text-red-500':'text-green-600'} w-4"></i> ${power?parseFloat(power).toFixed(1)+'V':'-'}</div>
                        <div class="flex items-center"><i class="fas fa-satellite text-blue-400 w-4"></i> ${sats||0}</div>
                        <div class="flex items-center"><i class="fas fa-key ${ign?'text-green-500':'text-gray-400'} w-4"></i> ${ign?'ON':'OFF'}</div>
                    </div>
                    ${driverInfo}
                    <div class="bg-blue-50 p-2 rounded text-[10px] mt-2 border border-blue-100">
                        <div class="text-blue-800 font-bold mb-1 truncate" id="pop-addr-${d.id}">${addr}</div>
                        <div class="text-blue-500 text-right font-mono">${dateFull}</div>
                    </div>
                </div>
            </div>`;

        if(markers[d.id]) { markers[d.id].setLatLng(latlng).setIcon(icon); if (markers[d.id].isPopupOpen()) markers[d.id].setPopupContent(popup); else markers[d.id].bindPopup(popup); } 
        else { markers[d.id] = L.marker(latlng, {icon:icon}).addTo(map).bindPopup(popup); }
    }

    function initLayer() { const s = localStorage.getItem('fleetMapLayer')||'streets'; setLayer(s); }
    function setLayer(l) {
        document.querySelectorAll('.map-btn').forEach(b => b.classList.remove('active'));
        map.removeLayer(street); map.removeLayer(sat);
        if(l==='streets') { map.addLayer(street); document.getElementById('btn-streets').classList.add('active'); }
        if(l==='satellite') { map.addLayer(sat); document.getElementById('btn-sat').classList.add('active'); }
        if(l==='traffic') { 
            if(!map.hasLayer(street) && !map.hasLayer(sat)) map.addLayer(street);
            if(map.hasLayer(traffic)) { map.removeLayer(traffic); document.getElementById('btn-traffic').classList.remove('active'); }
            else { map.addLayer(traffic); document.getElementById('btn-traffic').classList.add('active'); }
        } else { localStorage.setItem('fleetMapLayer', l); }
    }

    function toggleDrawer() { const d = document.getElementById('bottom-drawer'); const i = document.getElementById('drawer-icon'); isDrawerOpen = !isDrawerOpen; if(isDrawerOpen) { d.classList.replace('drawer-closed', 'drawer-open'); i.style.transform = 'rotate(180deg)'; } else { d.classList.replace('drawer-open', 'drawer-closed'); i.style.transform = 'rotate(0deg)'; } setTimeout(() => map.invalidateSize(), 300); }
    function fitAll() { followingId = null; document.getElementById('follow-badge').classList.add('hidden'); const bounds = new L.featureGroup(Object.values(markers)); if(bounds.getLayers().length > 0) map.fitBounds(bounds.getBounds(), {padding:[50,50]}); }
    function stopFollowing() { followingId = null; document.getElementById('follow-badge').classList.add('hidden'); }
    function focusDev(lat, lon, id) { followingId = id; document.getElementById('follow-badge').classList.remove('hidden'); map.flyTo([lat, lon], 17); if(markers[id]) markers[id].openPopup(); document.querySelectorAll('.row-selected').forEach(r => r.classList.remove('row-selected')); const r = document.getElementById(`row-${id}`); if(r) r.classList.add('row-selected'); }
    function filterMap() { renderMap(); }
    function openLockModal(e, id, name, isBlocked) { e.stopPropagation(); document.getElementById('modal-security').classList.remove('hidden'); document.getElementById('sec-dev-id').value = id; document.getElementById('sec-veh-name').innerText = name; const btn = document.getElementById('btn-sec-confirm'); document.getElementById('sec-cmd-type').value = isBlocked ? 'unlock' : 'lock'; btn.className = isBlocked ? "w-full bg-green-600 text-white font-bold py-2 rounded shadow hover:bg-green-700" : "w-full bg-red-600 text-white font-bold py-2 rounded shadow hover:bg-red-700"; btn.innerText = isBlocked ? "Confirmar Desbloqueio" : "Confirmar Bloqueio"; document.getElementById('sec-password').value = ''; document.getElementById('sec-password').focus(); }
    function closeSecModal() { document.getElementById('modal-security').classList.add('hidden'); }
    async function executeCommand() { const id = document.getElementById('sec-dev-id').value; const type = document.getElementById('sec-cmd-type').value; const pass = document.getElementById('sec-password').value; const btn = document.getElementById('btn-sec-confirm'); if(!pass) return alert('Digite sua senha!'); btn.disabled = true; btn.innerText = 'Processando...'; try { const res = await fetch('/api_dados.php?action=secure_command', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ deviceId: id, type: type, password: pass }) }); const data = await res.json(); if(res.ok) { alert(data.message); closeSecModal(); if(!vehicleState[id]) vehicleState[id] = {}; vehicleState[id].blocked = (type === 'lock'); renderMap(); } else { alert('Erro: ' + (data.error || 'Falha desconhecida')); } } catch(e) { alert('Erro de conexão.'); } finally { btn.disabled = false; } }
    function updateDOM(id, txt) { const el = document.getElementById('addr-'+id); if(el) el.innerText = txt; const pop = document.getElementById('pop-addr-'+id); if(pop) pop.innerText = txt; }

    setInterval(() => { if(geoQueue.length > 0) { const t = geoQueue.shift(); const k = t.lat.toFixed(4)+','+t.lon.toFixed(4); if(geoCache[k]) updateDOM(t.id, geoCache[k]); else fetch(`/api_dados.php?type=geocode&lat=${t.lat}&lon=${t.lon}`).then(r=>r.json()).then(d=>{const a = (d.address.road||'') + ', ' + (d.address.suburb||d.address.city||''); geoCache[k] = a || '...'; sessionStorage.setItem('geoCache', JSON.stringify(geoCache)); updateDOM(t.id, a);}).catch(()=>{}); } }, 1500);

    startPolling();
    connectWS();
</script>