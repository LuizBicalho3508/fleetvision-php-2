<script>
    // --- ESTADO GLOBAL ---
    let map, markers = {}, vehicleState = {};
    let geoCache = JSON.parse(sessionStorage.getItem('geoCache') || '{}'), geoQueue = [];
    let isDrawerOpen = false, followingId = null;
    let socket;
    
    let lastDevices = []; 
    let lastPositions = [];
    let positionsMap = {}; // Mapa rápido ID -> Posição

    // --- INICIALIZAÇÃO DO MAPA ---
    const street = L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', {maxZoom:20});
    const sat = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {maxZoom:19});
    const traffic = L.tileLayer('https://{s}.google.com/vt/lyrs=m,traffic&x={x}&y={y}&z={z}', {maxZoom:20, subdomains:['mt0','mt1','mt2','mt3']});

    map = L.map('map', {layers: [street], zoomControl: false}).setView([-14.2350, -51.9253], 4);
    L.control.zoom({position: 'bottomright'}).addTo(map);

    map.on('dragstart', () => { 
        if(followingId) { followingId = null; document.getElementById('follow-badge').classList.add('hidden'); } 
    });

    // --- WEBSOCKET CONNECTION ---
    function connectWS() {
        const statusEl = document.getElementById('ws-status');
        
        // Se usar porta direta (8082), o cookie pode não ser enviado automaticamente
        // Tenta enviar via query string se suportado, ou depende do Cookie do browser
        let url = WS_URL;
        // Hack: Se não estivermos usando proxy reverso (mesmo dominio), precisamos passar o JSESSIONID
        if (SESSION_TOKEN) {
            document.cookie = "JSESSIONID=" + SESSION_TOKEN + "; path=/";
        }

        socket = new WebSocket(url);

        socket.onopen = function() {
            console.log('WS Conectado');
            statusEl.className = "w-2 h-2 rounded-full bg-green-500 shadow-[0_0_5px_rgba(34,197,94,0.8)]";
            // Ao conectar, pedimos a carga inicial via API para garantir
            loadInitialData();
        };

        socket.onmessage = function(event) {
            const data = JSON.parse(event.data);
            
            if (data.devices) {
                lastDevices = updateArray(lastDevices, data.devices);
                renderMap();
            }
            if (data.positions) {
                lastPositions = updateArray(lastPositions, data.positions);
                // Atualiza mapa rápido
                data.positions.forEach(p => positionsMap[p.deviceId] = p);
                renderMap();
            }
            // Eventos (opcional: mostrar toast)
            if (data.events) {
                data.events.forEach(e => {
                    // Exemplo: Alerta de ignição ou cerca
                    console.log('Evento:', e);
                });
            }
        };

        socket.onclose = function() {
            console.log('WS Desconectado. Reconectando em 3s...');
            statusEl.className = "w-2 h-2 rounded-full bg-red-500";
            setTimeout(connectWS, 3000);
        };

        socket.onerror = function(err) {
            console.error('WS Erro:', err);
            socket.close();
        };
    }

    // Helper para atualizar arrays sem duplicar (Traccar manda só o delta as vezes)
    function updateArray(current, updates) {
        const map = new Map(current.map(i => [i.id, i]));
        updates.forEach(u => map.set(u.id, u));
        return Array.from(map.values());
    }

    // Carga inicial via HTTP (pra não esperar o primeiro evento do socket)
    async function loadInitialData() {
        try {
            const [devRes, posRes] = await Promise.all([ 
                fetch('/api_dados.php?endpoint=/devices'), 
                fetch('/api_dados.php?endpoint=/positions') 
            ]);
            if(devRes.ok && posRes.ok) {
                lastDevices = await devRes.json();
                lastPositions = await posRes.json();
                lastPositions.forEach(p => positionsMap[p.deviceId] = p);
                renderMap();
            }
        } catch(e) { console.error("Erro carga inicial", e); }
    }

    // --- RENDERIZAÇÃO ---
    function renderMap() {
        const tbody = document.getElementById('grid-body');
        const searchInput = document.getElementById('map-search');
        const filter = (searchInput.value || '').toLowerCase();
        
        let html = '', on = 0, off = 0;

        lastDevices.forEach(d => {
            // Se tiver filtro e não der match, pula
            if(filter && !d.name.toLowerCase().includes(filter) && !d.uniqueId.includes(filter)) return;

            const p = positionsMap[d.id];
            if(!p) return; // Sem posição, não mostra

            // Estado (Ignition, etc)
            if(!vehicleState[d.id]) vehicleState[d.id] = {};
            const attr = p.attributes || {};
            const getVal = (k,v) => (v!==undefined && v!==null) ? (vehicleState[d.id][k]=v) : vehicleState[d.id][k];
            
            const ign = getVal('ignition', attr.ignition);
            const bat = getVal('batteryLevel', attr.batteryLevel);
            const sats = getVal('sat', attr.sat);
            const blocked = getVal('blocked', attr.blocked);

            // Contadores
            if(d.status === 'online') on++; else off++;

            // Formatações
            const speed = (p.speed * 1.852).toFixed(0); // Knots to KM/h
            const dateObj = new Date(p.fixTime);
            const dateFull = dateObj.toLocaleString('pt-BR'); 
            const ignHtml = ign ? '<span class="text-green-600 font-bold text-[10px]">ON</span>' : '<span class="text-gray-400 text-[10px]">OFF</span>';
            const proto = p.protocol ? p.protocol.toUpperCase() : '-';
            
            // Geocoding Cache
            const k = p.latitude.toFixed(4)+','+p.longitude.toFixed(4);
            if(!geoCache[k]) geoQueue.push({id:d.id, lat:p.latitude, lon:p.longitude});
            const addr = geoCache[k] || '...';

            // HTML da Tabela
            const isSel = (followingId === d.id) ? 'row-selected' : '';
            const lockBtn = blocked 
                ? `<button onclick="openLockModal(event, ${d.id}, '${d.name}', true)" class="btn-unlock"><i class="fas fa-unlock"></i> Liberar</button>`
                : `<button onclick="openLockModal(event, ${d.id}, '${d.name}', false)" class="btn-lock"><i class="fas fa-lock"></i> Bloquear</button>`;

            html += `
                <tr id="row-${d.id}" onclick="focusDev(${p.latitude}, ${p.longitude}, ${d.id})" class="transition border-b ${isSel}">
                    <td class="text-center"><div class="w-2 h-2 rounded-full mx-auto ${d.status==='online'?'bg-green-500':'bg-red-500'}"></div></td>
                    <td class="font-bold text-gray-700 text-xs">${d.name}</td>
                    <td class="text-xs font-mono text-gray-500">${proto}</td>
                    <td class="text-xs text-gray-600 truncate max-w-[150px]" id="addr-${d.id}">${addr}</td>
                    <td class="text-center text-xs font-mono">${dateFull}</td>
                    <td class="text-center text-xs font-bold text-blue-600">${speed} km/h</td>
                    <td class="text-center">${ignHtml}</td>
                    <td class="text-center text-xs">${bat||'-'}%</td>
                    <td class="text-center text-xs">${sats||0}</td>
                    <td class="text-center">${lockBtn}</td>
                </tr>`;

            updateMarker(d, p, speed, ign, bat, sats, blocked, addr, dateFull);
            
            // Seguir Veículo
            if(followingId === d.id) map.panTo([p.latitude, p.longitude], {animate:true, duration:0.5});
        });

        if(html === '') html = '<tr><td colspan="10" class="p-8 text-center text-gray-400">Nenhum veículo encontrado.</td></tr>';
        tbody.innerHTML = html;
        
        // Atualiza cabeçalho
        document.getElementById('total-count').innerText = lastDevices.length;
        document.getElementById('cnt-on').innerText = on;
        document.getElementById('cnt-off').innerText = off;
    }

    function updateMarker(d, p, speed, ign, bat, sats, blocked, addr, dateFull) {
        const latlng = [p.latitude, p.longitude];
        
        // Ícone Personalizado vs Padrão
        let iconHtml;
        if(iconData[d.id]) {
            iconHtml = `<div style="transform: rotate(${p.course}deg); transition: transform 0.3s ease; filter: drop-shadow(0 3px 5px rgba(0,0,0,0.3));"><img src="${iconData[d.id]}" style="width:40px;"></div>`;
        } else {
            const color = d.status==='online'?'#22c55e':'#ef4444';
            const lockBadge = blocked ? '<div style="position:absolute;top:-5px;right:-5px;background:red;color:white;border-radius:50%;width:12px;height:12px;font-size:8px;display:flex;align-items:center;justify-content:center"><i class="fas fa-lock"></i></div>' : '';
            iconHtml = `<div style="transform: rotate(${p.course}deg); background:${color}; width:30px; height:30px; border-radius:50%; border:2px solid white; display:flex; justify-content:center; align-items:center; box-shadow:0 2px 5px rgba(0,0,0,0.3); position:relative"><i class="fas fa-chevron-up text-white text-[10px]"></i>${lockBadge}</div>`;
        }
        
        const icon = L.divIcon({className:'bg-transparent border-0', html:iconHtml, iconSize:[40,40], iconAnchor:[20,20]});
        const statusBadge = d.status==='online' ? '<span class="bg-green-100 text-green-700 text-[10px] font-bold px-2 py-0.5 rounded">ONLINE</span>' : '<span class="bg-red-100 text-red-700 text-[10px] font-bold px-2 py-0.5 rounded">OFFLINE</span>';
        const lockStatus = blocked ? '<span class="text-red-500 font-bold"><i class="fas fa-lock"></i> BLOQUEADO</span>' : '<span class="text-green-500 font-bold"><i class="fas fa-lock-open"></i> LIBERADO</span>';
        
        const popup = `
            <div class="font-sans text-sm text-gray-700">
                <div class="bg-gray-50 p-3 border-b flex justify-between items-center">
                    <div><div class="font-bold text-gray-800">${d.name}</div><div class="text-[10px] text-gray-400 font-mono">${d.uniqueId}</div></div>
                    ${statusBadge}
                </div>
                <div class="p-3 space-y-2">
                    <div class="grid grid-cols-2 gap-2 text-xs">
                        <div><span class="text-gray-400 text-[9px] uppercase font-bold">Velocidade</span><br><span class="font-bold text-blue-600 text-lg">${speed}</span> <small>km/h</small></div>
                        <div><span class="text-gray-400 text-[9px] uppercase font-bold">Status</span><br>${lockStatus}</div>
                    </div>
                    <div class="border-t border-dashed my-2"></div>
                    <div class="grid grid-cols-3 gap-y-1 text-xs text-gray-600">
                        <div class="flex items-center"><i class="fas fa-bolt text-yellow-500 w-4"></i> ${bat||'-'}%</div>
                        <div class="flex items-center"><i class="fas fa-satellite text-blue-400 w-4"></i> ${sats||0}</div>
                        <div class="flex items-center"><i class="fas fa-key ${ign?'text-green-500':'text-gray-400'} w-4"></i> ${ign?'ON':'OFF'}</div>
                    </div>
                    <div class="bg-blue-50 p-2 rounded text-[10px] mt-2 border border-blue-100">
                        <div class="text-blue-800 font-bold mb-1 truncate" id="pop-addr-${d.id}">${addr}</div>
                        <div class="text-blue-500 text-right font-mono">${dateFull}</div>
                    </div>
                </div>
            </div>`;

        if(markers[d.id]) { 
            markers[d.id].setLatLng(latlng).setIcon(icon);
            // Só atualiza popup se estiver aberto pra não piscar
            if (markers[d.id].isPopupOpen()) {
                // Aqui podemos usar setPopupContent mas cuidado pra não fechar interação
                // Simplificação: Atualiza conteúdo
                 markers[d.id].setPopupContent(popup);
            } else {
                 markers[d.id].bindPopup(popup);
            }
        } else { 
            markers[d.id] = L.marker(latlng, {icon:icon}).addTo(map).bindPopup(popup); 
        }
    }

    // --- GEOCODE WORKER ---
    // Processa fila de endereços para não estourar limite da API
    setInterval(() => {
        if(geoQueue.length > 0) {
            const t = geoQueue.shift();
            const k = t.lat.toFixed(4)+','+t.lon.toFixed(4);
            if(geoCache[k]) {
                updateDOM(t.id, geoCache[k]);
            } else {
                fetch(`/api_dados.php?type=geocode&lat=${t.lat}&lon=${t.lon}`)
                    .then(r=>r.json())
                    .then(d=>{
                        const a = (d.address.road||'') + ', ' + (d.address.suburb||d.address.city||'');
                        geoCache[k] = a || 'Endereço não encontrado';
                        sessionStorage.setItem('geoCache', JSON.stringify(geoCache));
                        updateDOM(t.id, a);
                    }).catch(()=>{});
            }
        }
    }, 1500);

    function updateDOM(id, txt) { 
        const el = document.getElementById('addr-'+id); if(el) el.innerText = txt; 
        const pop = document.getElementById('pop-addr-'+id); if(pop) pop.innerText = txt; 
    }

    // --- OUTROS ---
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
    
    function toggleDrawer() {
        const d = document.getElementById('bottom-drawer');
        const i = document.getElementById('drawer-icon');
        isDrawerOpen = !isDrawerOpen;
        if(isDrawerOpen) {
            d.classList.replace('drawer-closed', 'drawer-open'); i.style.transform = 'rotate(180deg)';
        } else {
            d.classList.replace('drawer-open', 'drawer-closed'); i.style.transform = 'rotate(0deg)';
        }
        setTimeout(() => map.invalidateSize(), 300);
    }
    function fitAll() {
        followingId = null; document.getElementById('follow-badge').classList.add('hidden');
        const bounds = new L.featureGroup(Object.values(markers));
        if(bounds.getLayers().length > 0) map.fitBounds(bounds.getBounds(), {padding:[50,50]});
    }
    function stopFollowing() { followingId = null; document.getElementById('follow-badge').classList.add('hidden'); }
    function focusDev(lat, lon, id) {
        followingId = id; document.getElementById('follow-badge').classList.remove('hidden');
        map.flyTo([lat, lon], 17);
        if(markers[id]) markers[id].openPopup();
        document.querySelectorAll('.row-selected').forEach(r => r.classList.remove('row-selected'));
        const r = document.getElementById(`row-${id}`); if(r) r.classList.add('row-selected');
    }
    function filterMap() { renderMap(); }

    // Bloqueio Seguro
    function openLockModal(e, id, name, isBlocked) {
        e.stopPropagation(); document.getElementById('modal-security').classList.remove('hidden');
        document.getElementById('sec-dev-id').value = id;
        document.getElementById('sec-veh-name').innerText = name;
        const btn = document.getElementById('btn-sec-confirm');
        document.getElementById('sec-cmd-type').value = isBlocked ? 'unlock' : 'lock';
        document.getElementById('sec-cmd-name').innerText = isBlocked ? 'Desbloquear' : 'Bloquear';
        btn.className = isBlocked ? "w-full bg-green-600 text-white font-bold py-2 rounded shadow hover:bg-green-700" : "w-full bg-red-600 text-white font-bold py-2 rounded shadow hover:bg-red-700";
        btn.innerText = isBlocked ? "Confirmar Desbloqueio" : "Confirmar Bloqueio";
        document.getElementById('sec-password').value = ''; document.getElementById('sec-password').focus();
    }
    function closeSecModal() { document.getElementById('modal-security').classList.add('hidden'); }
    async function executeCommand() {
        const id = document.getElementById('sec-dev-id').value;
        const type = document.getElementById('sec-cmd-type').value;
        const pass = document.getElementById('sec-password').value;
        const btn = document.getElementById('btn-sec-confirm');
        if(!pass) return alert('Digite sua senha!');
        btn.disabled = true; btn.innerText = 'Processando...';
        try {
            const res = await fetch('/api_dados.php?action=secure_command', {
                method: 'POST', headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ deviceId: id, type: type, password: pass })
            });
            const data = await res.json();
            if(res.ok) {
                alert(data.message); closeSecModal();
                if(!vehicleState[id]) vehicleState[id] = {};
                vehicleState[id].blocked = (type === 'lock');
                renderMap(); // Re-renderiza para atualizar botão
            } else { alert('Erro: ' + (data.error || 'Falha desconhecida')); }
        } catch(e) { alert('Erro de conexão.'); } 
        finally { btn.disabled = false; }
    }

    // Inicializa
    initLayer();
    connectWS();
</script>