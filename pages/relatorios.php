<?php
if (!isset($_SESSION['user_id'])) exit;

// --- 1. CONFIGURAÇÃO E CONTEXTO ---
$tenant_id  = $_SESSION['tenant_id'];
$user_id    = $_SESSION['user_id'];
$user_role  = $_SESSION['user_role'] ?? 'user';
$user_email = $_SESSION['user_email'] ?? ''; // Correção do Warning

// --- 2. LÓGICA DE FILTRO DE VEÍCULOS (Mesma do api_dados.php) ---
$restrictionSQL = "";
$params = ['tid' => $tenant_id];

if ($user_role != 'admin' && $user_role != 'superadmin') {
    // Verifica se o usuário está vinculado a um cliente
    $stmtUserCheck = $pdo->prepare("SELECT customer_id FROM saas_users WHERE id = ?");
    $stmtUserCheck->execute([$user_id]);
    $userDirectCustomer = $stmtUserCheck->fetchColumn();

    // Se não achou por ID, tenta pelo email (fallback)
    if (!$userDirectCustomer && !empty($user_email)) {
        $stmtEmail = $pdo->prepare("SELECT id FROM saas_customers WHERE email = ? AND tenant_id = ?");
        $stmtEmail->execute([$user_email, $tenant_id]);
        $userDirectCustomer = $stmtEmail->fetchColumn();
    }

    if ($userDirectCustomer) {
        // Usuário de Cliente: Vê veículos do Cliente OU atribuídos a ele diretamente
        // Nota: A coluna no banco geralmente é 'client_id' para vínculo com saas_customers
        $restrictionSQL = " AND (v.client_id = :cid OR v.user_id = :uid)";
        $params['cid'] = $userDirectCustomer;
        $params['uid'] = $user_id;
    } else {
        // Usuário Comum (sem cliente): Vê apenas veículos atribuídos a ele
        $restrictionSQL = " AND v.user_id = :uid";
        $params['uid'] = $user_id;
    }
}

// --- 3. BUSCA VEÍCULOS ---
$sqlV = "SELECT v.traccar_device_id, v.name, v.plate, v.speed_limit, v.fuel_consumption 
         FROM saas_vehicles v 
         WHERE v.tenant_id = :tid $restrictionSQL 
         ORDER BY v.name ASC";

$stmt = $pdo->prepare($sqlV);
$stmt->execute($params);
$vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

<div class="flex flex-col h-screen bg-slate-50 font-inter">
    
    <div class="bg-white border-b border-gray-200 px-6 py-4 shadow-sm z-20 shrink-0">
        <div class="flex flex-col md:flex-row md:items-end gap-4">
            <div>
                <label class="block text-xs font-bold text-gray-500 mb-1 uppercase">Tipo de Relatório</label>
                <select id="report-type" class="w-full md:w-48 pl-3 pr-8 py-2 rounded-lg border border-slate-200 text-sm font-bold text-slate-700 focus:border-indigo-500 outline-none bg-white">
                    <option value="summary">Resumo Diário</option>
                    <option value="map_route">Trajeto no Mapa</option>
                    <option value="trips">Viagens (Trajetos)</option>
                    <option value="route">Posições Detalhadas</option>
                    <option value="speed">Excesso de Velocidade</option>
                </select>
            </div>
            
            <div>
                <label class="block text-xs font-bold text-gray-500 mb-1 uppercase">Veículo</label>
                <select id="device-id" class="w-full md:w-56 pl-3 pr-8 py-2 rounded-lg border border-slate-200 text-sm bg-white focus:border-indigo-500 outline-none">
                    <?php if (empty($vehicles)): ?>
                        <option value="">Nenhum veículo disponível</option>
                    <?php else: ?>
                        <?php foreach($vehicles as $v): ?>
                            <option value="<?php echo $v['traccar_device_id']; ?>" 
                                    data-limit="<?php echo $v['speed_limit'] ?: 80; ?>" 
                                    data-fuel="<?php echo $v['fuel_consumption'] ?: 10; ?>">
                                <?php echo $v['name']; ?> (<?php echo $v['plate'] ?? '-'; ?>)
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>

            <div><label class="block text-xs font-bold text-gray-500 mb-1 uppercase">Início</label><input type="datetime-local" id="date-from" class="px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white outline-none" value="<?php echo date('Y-m-d 00:00'); ?>"></div>
            <div><label class="block text-xs font-bold text-gray-500 mb-1 uppercase">Fim</label><input type="datetime-local" id="date-to" class="px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white outline-none" value="<?php echo date('Y-m-d 23:59'); ?>"></div>
            
            <button onclick="generateReport()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-2 rounded-lg text-sm font-bold shadow-sm transition flex items-center gap-2 h-[38px]">
                <i class="fas fa-search"></i> Gerar
            </button>
        </div>
    </div>

    <div id="report-dashboard" class="hidden px-6 py-6 bg-slate-50 border-b border-gray-200 animate-in fade-in slide-in-from-top-4 duration-500 shrink-0">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-white p-5 rounded-2xl shadow-sm border border-gray-100 flex items-center justify-between relative overflow-hidden">
                <div class="absolute right-0 top-0 w-24 h-24 bg-blue-50 rounded-full -mr-10 -mt-10 opacity-50"></div>
                <div><p class="text-xs text-gray-400 font-bold uppercase">Distância</p><h3 class="text-2xl font-bold text-slate-800 mt-1" id="kpi-dist">0 km</h3></div>
                <div class="w-12 h-12 rounded-xl bg-blue-100 text-blue-600 flex items-center justify-center text-xl shadow-sm"><i class="fas fa-road"></i></div>
            </div>
            <div class="bg-white p-5 rounded-2xl shadow-sm border border-gray-100 flex items-center justify-between relative overflow-hidden">
                <div class="absolute right-0 top-0 w-24 h-24 bg-red-50 rounded-full -mr-10 -mt-10 opacity-50"></div>
                <div><p class="text-xs text-gray-400 font-bold uppercase">Vel. Máx.</p><h3 class="text-2xl font-bold text-slate-800 mt-1" id="kpi-max-speed">0 km/h</h3></div>
                <div class="w-12 h-12 rounded-xl bg-red-100 text-red-600 flex items-center justify-center text-xl shadow-sm"><i class="fas fa-tachometer-alt"></i></div>
            </div>
            <div class="bg-white p-5 rounded-2xl shadow-sm border border-gray-100 flex items-center justify-between relative overflow-hidden">
                <div class="absolute right-0 top-0 w-24 h-24 bg-green-50 rounded-full -mr-10 -mt-10 opacity-50"></div>
                <div><p class="text-xs text-gray-400 font-bold uppercase">Consumo Est.</p><h3 class="text-2xl font-bold text-slate-800 mt-1" id="kpi-fuel">0 L</h3></div>
                <div class="w-12 h-12 rounded-xl bg-green-100 text-green-600 flex items-center justify-center text-xl shadow-sm"><i class="fas fa-gas-pump"></i></div>
            </div>
            <div class="bg-white p-5 rounded-2xl shadow-sm border border-gray-100 flex items-center justify-between relative overflow-hidden">
                <div class="absolute right-0 top-0 w-24 h-24 bg-orange-50 rounded-full -mr-10 -mt-10 opacity-50"></div>
                <div><p class="text-xs text-gray-400 font-bold uppercase">Eventos</p><h3 class="text-2xl font-bold text-slate-800 mt-1" id="kpi-violations">0</h3></div>
                <div class="w-12 h-12 rounded-xl bg-orange-100 text-orange-500 flex items-center justify-center text-xl shadow-sm"><i class="fas fa-exclamation-triangle"></i></div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 hidden" id="chart-section">
            <div class="md:col-span-2 bg-white p-5 rounded-2xl shadow-sm border border-gray-100 relative">
                <h4 class="text-xs font-bold text-gray-400 uppercase mb-4">Perfil de Velocidade</h4>
                <div class="w-full h-64 relative"><canvas id="speedChart"></canvas></div>
            </div>
            <div class="bg-white p-5 rounded-2xl shadow-sm border border-gray-100 relative">
                <h4 class="text-xs font-bold text-gray-400 uppercase mb-4">Status</h4>
                <div class="w-full h-48 relative flex justify-center"><canvas id="statusChart"></canvas></div>
            </div>
        </div>
    </div>

    <div class="bg-white px-6 py-2 border-b border-gray-200 flex justify-between items-center hidden shadow-sm z-10 shrink-0" id="toolbar">
        <div class="text-sm text-gray-500"><span class="font-bold text-slate-700" id="total-rows">0</span> registros encontrados</div>
        <div class="flex gap-2">
            <button onclick="exportExcel()" class="bg-emerald-600 text-white hover:bg-emerald-700 px-3 py-1.5 rounded-lg text-xs font-bold shadow-sm transition flex items-center gap-1"><i class="fas fa-file-excel"></i> Excel</button>
            <button onclick="exportPDF()" class="bg-red-600 text-white hover:bg-red-700 px-3 py-1.5 rounded-lg text-xs font-bold shadow-sm transition flex items-center gap-1"><i class="fas fa-file-pdf"></i> PDF</button>
        </div>
    </div>

    <div class="flex-1 overflow-auto p-6 bg-slate-50 relative custom-scroll" id="report-container">
        <div class="flex flex-col items-center justify-center h-full text-gray-400">
            <i class="fas fa-file-alt text-6xl mb-4 opacity-20"></i>
            <p>Selecione os filtros acima e clique em <strong>Gerar</strong>.</p>
        </div>
    </div>
</div>

<div id="modal-map" class="fixed inset-0 bg-black/80 hidden z-[9999] flex items-center justify-center p-4 backdrop-blur-sm">
    <div class="bg-white w-full max-w-6xl h-[85vh] rounded-xl flex flex-col shadow-2xl overflow-hidden relative">
        <div class="h-14 border-b flex justify-between items-center px-4 bg-slate-50">
            <h3 class="font-bold text-slate-700">Detalhes da Viagem</h3>
            <button onclick="document.getElementById('modal-map').classList.add('hidden')" class="w-8 h-8 rounded-full bg-white hover:bg-red-50 text-gray-400 hover:text-red-500 shadow flex items-center justify-center"><i class="fas fa-times"></i></button>
        </div>
        <div id="map-modal-content" class="flex-1 bg-gray-100 relative z-0"></div>
    </div>
</div>

<script>
    // --- CORREÇÃO ROTA API ---
    const API_URL = '../api_dados.php';
    const TENANT_NAME = "<?php echo htmlspecialchars($_SESSION['tenant_name'] ?? 'Relatório'); ?>";
    
    let currentData = [], map = null, embeddedMap = null, speedChart = null, statusChart = null;
    let addressQueue = [], isProcessingQueue = false;

    // --- GEOCODING QUEUE ---
    async function processAddressQueue() {
        if (isProcessingQueue || addressQueue.length === 0) return;
        isProcessingQueue = true;
        const task = addressQueue.shift();
        try {
            const res = await fetch(`${API_URL}?type=geocode&lat=${task.lat}&lon=${task.lon}`);
            const data = await res.json();
            const el = document.getElementById(task.id);
            if (el && data.address) {
                el.innerText = (data.address.split(',')[0] || data.address); // Simplifica endereço
                el.title = data.address;
                el.classList.remove('animate-pulse');
            } else if(el) el.innerText = "-";
        } catch (e) {}
        setTimeout(() => { isProcessingQueue = false; processAddressQueue(); }, 300);
    }
    function queueAddress(lat, lon, elemId) {
        if (!lat || !lon) return;
        addressQueue.push({ lat, lon, id: elemId });
        processAddressQueue();
    }

    // --- GERAR RELATÓRIO ---
    async function generateReport() {
        const type = document.getElementById('report-type').value;
        const deviceId = document.getElementById('device-id').value;
        const from = new Date(document.getElementById('date-from').value).toISOString();
        const to = new Date(document.getElementById('date-to').value).toISOString();
        
        if(!deviceId) return alert("Selecione um veículo.");

        const devSelect = document.getElementById('device-id');
        const limit = parseInt(devSelect.options[devSelect.selectedIndex].getAttribute('data-limit')) || 80;
        const fuelAvg = parseFloat(devSelect.options[devSelect.selectedIndex].getAttribute('data-fuel')) || 10;

        // Reset UI
        document.getElementById('report-container').innerHTML = '<div class="flex flex-col items-center justify-center h-full text-indigo-500"><i class="fas fa-circle-notch fa-spin text-4xl mb-4"></i><p class="font-medium">Carregando dados...</p></div>';
        addressQueue = [];
        if(embeddedMap) { embeddedMap.remove(); embeddedMap = null; }

        // Define Endpoint Traccar
        let endpoint = '/reports/route'; 
        if(type === 'trips') endpoint = '/reports/trips';
        if(type === 'summary') endpoint = '/reports/summary';
        
        try {
            // Chama API
            const url = `${API_URL}?endpoint=${endpoint}&deviceId=${deviceId}&from=${from}&to=${to}`;
            const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
            
            if(!res.ok) throw new Error(`Erro API: ${res.status}`);
            
            currentData = await res.json();
            
            if (Array.isArray(currentData) && currentData.length > 0) {
                document.getElementById('report-dashboard').classList.remove('hidden');
                document.getElementById('toolbar').classList.remove('hidden');
                
                // Filtro para rel de velocidade
                let processedData = currentData;
                if(type === 'speed') processedData = currentData.filter(d => (d.speed * 1.852) > (limit * 0.9));

                document.getElementById('total-rows').innerText = processedData.length;
                updateDashboard(currentData, limit, fuelAvg, type);
                
                // Renderização Específica
                if(type === 'map_route') renderDailyRouteMap(currentData);
                else if(type === 'trips') renderTrips(currentData, deviceId);
                else if(type === 'route') renderPositions(currentData);
                else if(type === 'speed') renderSpeedTable(processedData, limit);
                else renderSummary(currentData);

            } else {
                document.getElementById('report-dashboard').classList.add('hidden');
                document.getElementById('toolbar').classList.add('hidden');
                document.getElementById('report-container').innerHTML = '<div class="flex flex-col items-center justify-center h-full text-gray-400"><i class="fas fa-search text-4xl mb-4 opacity-20"></i><p>Nenhum registro encontrado neste período.</p></div>';
            }
        } catch (e) {
            document.getElementById('report-container').innerHTML = `<div class="p-8 text-center text-red-500 bg-red-50 rounded border border-red-200">Erro ao carregar relatório: ${e.message}</div>`;
        }
    }

    // --- DASHBOARD UPDATE ---
    function updateDashboard(data, limit, fuelAvg, type) {
        let totalDist = 0, maxSpd = 0, violations = 0;
        let moving = 0, stopped = 0;

        data.forEach(r => {
            let d = r.distance || 0;
            let s = r.speed ? r.speed * 1.852 : (r.maxSpeed ? r.maxSpeed * 1.852 : 0);
            
            totalDist += d;
            if (s > maxSpd) maxSpd = s;
            
            if (['route', 'speed', 'map_route'].includes(type)) {
                if (s > limit) violations++;
                if (s > 2) moving++; else stopped++;
            }
        });

        document.getElementById('kpi-dist').innerText = (totalDist/1000).toFixed(2) + ' km';
        document.getElementById('kpi-max-speed').innerText = maxSpd.toFixed(0) + ' km/h';
        document.getElementById('kpi-fuel').innerText = (totalDist/1000/fuelAvg).toFixed(1) + ' L';
        
        const kpiVio = document.getElementById('kpi-violations');
        kpiVio.innerText = violations;
        kpiVio.className = violations > 0 ? "text-2xl font-bold text-red-600 mt-1" : "text-2xl font-bold text-green-600 mt-1";

        const chartBox = document.getElementById('chart-section');
        if (['route', 'speed'].includes(type)) {
            chartBox.classList.remove('hidden');
            renderSpeedChart(data, limit);
            renderStatusChart(moving, stopped);
        } else {
            chartBox.classList.add('hidden');
        }
    }

    function renderSpeedChart(data, limit) {
        const ctx = document.getElementById('speedChart').getContext('2d');
        if (speedChart) speedChart.destroy();
        const sampling = data.length > 400 ? Math.ceil(data.length/400) : 1;
        const chartData = data.filter((_, i) => i % sampling === 0).map(r => ({
            x: new Date(r.fixTime || r.serverTime).toLocaleString('pt-BR', {hour:'2-digit', minute:'2-digit'}),
            y: (r.speed * 1.852).toFixed(0)
        }));

        speedChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: chartData.map(d => d.x),
                datasets: [{ label: 'Velocidade', data: chartData.map(d => d.y), borderColor: '#3b82f6', backgroundColor: 'rgba(59, 130, 246, 0.1)', borderWidth: 2, fill: true, tension: 0.3, pointRadius: 0 },
                { label: 'Limite', data: Array(chartData.length).fill(limit), borderColor: '#ef4444', borderWidth: 1, borderDash: [5, 5], pointRadius: 0, fill: false }]
            },
            options: { responsive: true, maintainAspectRatio: false, interaction: { intersect: false, mode: 'index' }, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
        });
    }

    function renderStatusChart(moving, stopped) {
        const ctx = document.getElementById('statusChart').getContext('2d');
        if (statusChart) statusChart.destroy();
        if(moving === 0 && stopped === 0) stopped = 1;
        statusChart = new Chart(ctx, { type: 'doughnut', data: { labels: ['Movimento', 'Parado'], datasets: [{ data: [moving, stopped], backgroundColor: ['#3b82f6', '#cbd5e1'], borderWidth: 0 }] }, options: { responsive: true, maintainAspectRatio: false, cutout: '70%', plugins: { legend: { position: 'bottom' } } } });
    }

    function renderDailyRouteMap(data) {
        const container = document.getElementById('report-container');
        container.innerHTML = `<div id="embedded-map" class="w-full h-[600px] rounded-xl shadow-inner border border-gray-200"></div>`;
        
        if (embeddedMap) embeddedMap.remove();
        embeddedMap = L.map('embedded-map').setView([0, 0], 2);
        L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png').addTo(embeddedMap);

        if (data.length > 0) {
            const latlngs = data.map(p => [p.latitude, p.longitude]);
            const polyline = L.polyline(latlngs, {color: '#2563eb', weight: 5, opacity: 0.8}).addTo(embeddedMap);
            
            const startIcon = L.divIcon({html: '<i class="fas fa-play-circle text-green-600 text-3xl drop-shadow-md bg-white rounded-full"></i>', className: 'bg-transparent', iconSize: [30, 30], iconAnchor: [15, 15]});
            const endIcon = L.divIcon({html: '<i class="fas fa-stop-circle text-red-600 text-3xl drop-shadow-md bg-white rounded-full"></i>', className: 'bg-transparent', iconSize: [30, 30], iconAnchor: [15, 15]});

            L.marker(latlngs[0], {icon: startIcon}).addTo(embeddedMap).bindPopup(`<b>Início</b><br>${new Date(data[0].fixTime).toLocaleString()}`);
            L.marker(latlngs[latlngs.length - 1], {icon: endIcon}).addTo(embeddedMap).bindPopup(`<b>Fim</b><br>${new Date(data[data.length-1].fixTime).toLocaleString()}`);

            embeddedMap.fitBounds(polyline.getBounds(), {padding: [50, 50]});
        }
        setTimeout(() => embeddedMap.invalidateSize(), 300);
    }

    // --- TABELAS ---
    function renderSummary(data) {
        let html = `<div class="bg-white rounded-xl shadow-sm overflow-hidden"><table class="w-full text-sm text-left"><thead class="bg-slate-50 text-slate-500 font-bold uppercase border-b border-slate-200"><tr><th class="p-4">Veículo</th><th class="p-4">Data</th><th class="p-4">Distância</th><th class="p-4">Horas</th><th class="p-4">Vel Max</th><th class="p-4">Combustível</th></tr></thead><tbody class="divide-y divide-slate-100">`;
        data.forEach(r => {
            const dist = (r.distance/1000).toFixed(2);
            const spd = (r.maxSpeed * 1.852).toFixed(0);
            html += `<tr class="hover:bg-slate-50"><td class="p-4 font-bold text-slate-700">${r.deviceName || 'Veículo'}</td><td class="p-4">${new Date(r.startTime).toLocaleDateString('pt-BR')}</td><td class="p-4 font-mono font-bold text-indigo-600">${dist} km</td><td class="p-4">${msToTime(r.engineHours || 0)}</td><td class="p-4 text-red-500 font-bold">${spd} km/h</td><td class="p-4">${r.spentFuel?r.spentFuel.toFixed(1)+'L':'-'}</td></tr>`;
        });
        html += '</tbody></table></div>';
        document.getElementById('report-container').innerHTML = html;
    }

    function renderTrips(data, deviceId) {
        let html = `<div class="bg-white rounded-xl shadow-sm overflow-hidden"><table class="w-full text-xs text-left"><thead class="bg-slate-50 text-slate-500 font-bold uppercase border-b border-slate-200"><tr><th class="p-3">Início</th><th class="p-3">Fim</th><th class="p-3">Duração</th><th class="p-3">Distância</th><th class="p-3">Vel. Max</th><th class="p-3">Origem</th><th class="p-3">Destino</th><th class="p-3 text-center">Mapa</th></tr></thead><tbody class="divide-y divide-slate-100">`;
        data.forEach((r, i) => {
            const idS = `ts-${i}`, idE = `te-${i}`;
            queueAddress(r.startLat, r.startLon, idS); queueAddress(r.endLat, r.endLon, idE);
            const spd = (r.maxSpeed * 1.852).toFixed(0);
            html += `<tr class="hover:bg-indigo-50 transition"><td class="p-3 font-mono text-slate-600">${fmtDate(r.startTime)}</td><td class="p-3 font-mono text-slate-600">${fmtDate(r.endTime)}</td><td class="p-3 font-bold">${msToTime(r.duration)}</td><td class="p-3">${(r.distance/1000).toFixed(2)} km</td><td class="p-3 font-bold text-red-500">${spd} km/h</td><td class="p-3 truncate max-w-[150px] text-gray-400 animate-pulse" id="${idS}">...</td><td class="p-3 truncate max-w-[150px] text-gray-400 animate-pulse" id="${idE}">...</td><td class="p-3 text-center"><button onclick="openTripMap('${r.startTime}','${r.endTime}',${deviceId})" class="text-indigo-600 hover:bg-indigo-100 p-2 rounded-lg transition"><i class="fas fa-map-marked-alt"></i></button></td></tr>`;
        });
        html += '</tbody></table></div>';
        document.getElementById('report-container').innerHTML = html;
    }

    function renderPositions(data) {
        let html = `<div class="bg-white rounded-xl shadow-sm overflow-hidden"><table class="w-full text-[11px] text-left border-collapse"><thead class="bg-slate-800 text-white font-bold uppercase"><tr><th class="p-3">Data/Hora</th><th class="p-3">Lat/Lon</th><th class="p-3">Velocidade</th><th class="p-3 text-center">Ign</th><th class="p-3">Odômetro</th><th class="p-3">Endereço</th></tr></thead><tbody class="divide-y divide-slate-100 text-slate-600">`;
        data.forEach((r, i) => {
            const spd = (r.speed * 1.852).toFixed(0);
            const ign = r.attributes.ignition ? '<span class="text-green-600 font-bold bg-green-50 px-1.5 py-0.5 rounded">ON</span>' : '<span class="text-gray-400">OFF</span>';
            const odo = r.attributes.totalDistance ? (r.attributes.totalDistance/1000).toFixed(2) + ' km' : '-';
            const idA = `pa-${i}`;
            if(i%10===0) queueAddress(r.latitude, r.longitude, idA);
            html += `<tr class="hover:bg-yellow-50"><td class="p-3 font-mono">${fmtDate(r.fixTime)}</td><td class="p-3 text-blue-600 cursor-pointer hover:underline" onclick="openMapLink(${r.latitude},${r.longitude})">${r.latitude.toFixed(5)}, ${r.longitude.toFixed(5)}</td><td class="p-3 font-bold ${spd>80?'text-red-600':''}">${spd} km/h</td><td class="p-3 text-center">${ign}</td><td class="p-3 font-mono">${odo}</td><td class="p-3 truncate max-w-[250px] text-gray-400" id="${idA}">...</td></tr>`;
        });
        html += '</tbody></table></div>';
        document.getElementById('report-container').innerHTML = html;
    }

    function renderSpeedTable(data, limit) {
        let html = `<div class="bg-white rounded-xl shadow-sm overflow-hidden"><table class="w-full text-xs text-left"><thead class="bg-red-50 text-red-800 font-bold uppercase border-b border-red-100"><tr><th class="p-3">Data/Hora</th><th class="p-3">Localização</th><th class="p-3">Velocidade</th><th class="p-3">Limite</th><th class="p-3">Excesso</th><th class="p-3 text-center">Ver</th></tr></thead><tbody class="divide-y divide-red-50">`;
        data.forEach((r, i) => {
            const spd = (r.speed * 1.852).toFixed(0);
            const excess = spd - limit;
            const idA = `sp-${i}`;
            if(excess > 0) queueAddress(r.latitude, r.longitude, idA);
            if(spd > (limit * 0.9)) {
                html += `<tr class="hover:bg-red-50 transition"><td class="p-3 font-mono">${fmtDate(r.fixTime)}</td><td class="p-3 truncate max-w-[200px] text-gray-500" id="${idA}">...</td><td class="p-3 font-bold ${excess>0?'text-red-600':'text-orange-500'}">${spd} km/h</td><td class="p-3 text-gray-500">${limit} km/h</td><td class="p-3 font-bold text-red-700">+${excess > 0 ? excess : 0} km/h</td><td class="p-3 text-center"><button onclick="openMapLink(${r.latitude},${r.longitude})" class="text-blue-600 hover:bg-blue-100 p-1.5 rounded"><i class="fas fa-map-marker-alt"></i></button></td></tr>`;
            }
        });
        html += '</tbody></table></div>';
        document.getElementById('report-container').innerHTML = html;
    }

    // --- UTILS ---
    function fmtDate(d) { return new Date(d).toLocaleString('pt-BR'); }
    function msToTime(d) { var m=Math.floor((d/(1000*60))%60), h=Math.floor((d/(1000*60*60))%24); return (h<10?"0"+h:h)+":"+(m<10?"0"+m:m); }
    function openMapLink(lat, lon) { window.open(`http://maps.google.com/maps?q=${lat},${lon}`, '_blank'); }
    
    async function openTripMap(start, end, deviceId) {
        document.getElementById('modal-map').classList.remove('hidden');
        if(!map) { map = L.map('map-modal-content').setView([0,0],2); L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png').addTo(map); }
        setTimeout(()=>map.invalidateSize(), 300);
        
        const dS = new Date(start); dS.setMinutes(dS.getMinutes()-2);
        const dE = new Date(end); dE.setMinutes(dE.getMinutes()+2);

        try {
            // Limpa Layers
            map.eachLayer(l => { if(l instanceof L.Marker || l instanceof L.Polyline) map.removeLayer(l); });
            
            const url = `${API_URL}?endpoint=/reports/route&deviceId=${deviceId}&from=${dS.toISOString()}&to=${dE.toISOString()}`;
            const res = await fetch(url, {headers:{'Accept':'application/json'}});
            const pts = await res.json();
            
            if(pts.length) {
                const latlngs = pts.map(p=>[p.latitude, p.longitude]);
                L.polyline(latlngs, {color:'#3b82f6', weight:5}).addTo(map);
                L.marker(latlngs[0]).addTo(map).bindPopup("Início");
                L.marker(latlngs[latlngs.length-1]).addTo(map).bindPopup("Fim");
                map.fitBounds(L.polyline(latlngs).getBounds(), {padding:[50,50]});
            } else { alert("Sem dados de rota."); }
        } catch(e) { console.error(e); }
    }

    function exportExcel() { if(!currentData.length) return alert('Sem dados'); const ws = XLSX.utils.json_to_sheet(currentData.map(r=>{let f={...r, ...r.attributes}; delete f.attributes; return f;})); const wb = XLSX.utils.book_new(); XLSX.utils.book_append_sheet(wb, ws, "Relatório"); XLSX.writeFile(wb, `${TENANT_NAME}_Export.xlsx`); }
    function exportPDF() { if(!currentData.length) return alert('Sem dados'); const { jsPDF } = window.jspdf; const doc = new jsPDF('l','mm','a4'); doc.text(TENANT_NAME, 14, 15); doc.autoTable({ html: document.querySelector('table'), startY: 25 }); doc.save(`${TENANT_NAME}_Relatorio.pdf`); }
</script>