<?php
if (!isset($_SESSION['user_id'])) exit;
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet.draw/1.0.4/leaflet.draw.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet.draw/1.0.4/leaflet.draw.js"></script>

<style>
    /* Ajustes do Mapa e Lista */
    .geofence-list-item { transition: all 0.2s; border-left: 4px solid transparent; cursor: pointer; }
    .geofence-list-item:hover { background-color: #f8fafc; border-left-color: #cbd5e1; }
    .geofence-list-item.active { background-color: #eff6ff; border-left-color: #3b82f6; }
    .leaflet-draw-toolbar a { background-color: white; border-color: #e2e8f0; color: #475569; }
    .leaflet-draw-toolbar a:hover { background-color: #f1f5f9; color: #3b82f6; }
</style>

<div class="flex h-screen bg-slate-50 overflow-hidden">
    
    <div class="w-96 bg-white border-r border-gray-200 flex flex-col shadow-lg z-10 flex-shrink-0">
        
        <div class="p-5 border-b border-gray-100 bg-slate-50">
            <h2 class="text-xl font-bold text-slate-800 flex items-center gap-2">
                <i class="fas fa-draw-polygon text-blue-600"></i> Cercas Virtuais
            </h2>
            <p class="text-xs text-slate-500 mt-1">Gerencie áreas de risco e pátios.</p>
            
            <div class="mt-4 relative">
                <input type="text" id="search-geo" onkeyup="filterGeofences()" class="input-std pl-9" placeholder="Buscar cerca...">
                <i class="fas fa-search absolute left-3 top-2.5 text-gray-400"></i>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto" id="geofence-list">
            <div class="p-8 text-center text-gray-400">
                <i class="fas fa-circle-notch fa-spin text-2xl"></i>
                <p class="text-xs mt-2">Carregando...</p>
            </div>
        </div>

        <div class="p-4 border-t border-gray-100 bg-gray-50 text-center">
            <p class="text-[10px] text-gray-400">Use as ferramentas no mapa para criar.</p>
        </div>
    </div>

    <div class="flex-1 relative bg-gray-200">
        <div id="map-geofence" class="absolute inset-0 z-0"></div>
        
        <div id="drawing-hint" class="absolute top-4 left-14 right-4 z-[500] hidden">
            <div class="bg-blue-600 text-white px-4 py-2 rounded shadow-lg text-sm font-bold flex items-center justify-between animate-in fade-in slide-in-from-top-2">
                <span><i class="fas fa-pencil-alt mr-2"></i> Desenhe a área no mapa e solte para salvar.</span>
                <button onclick="cancelDrawing()" class="text-white hover:text-blue-200"><i class="fas fa-times"></i></button>
            </div>
        </div>
    </div>

</div>

<div id="modal-save-geo" class="fixed inset-0 bg-black/60 hidden z-[9999] flex items-center justify-center backdrop-blur-sm p-4">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-sm animate-in zoom-in duration-200">
        <div class="p-5 border-b border-gray-100">
            <h3 class="font-bold text-lg text-slate-800">Nova Cerca</h3>
        </div>
        <form id="form-geo" onsubmit="saveGeofence(event)" class="p-6 space-y-4">
            <input type="hidden" id="geo-area"> <div>
                <label class="block text-xs font-bold text-gray-500 mb-1 uppercase">Nome da Cerca</label>
                <input type="text" id="geo-name" class="input-std" required placeholder="Ex: Matriz, Casa Cliente X...">
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-500 mb-1 uppercase">Descrição (Opcional)</label>
                <input type="text" id="geo-desc" class="input-std" placeholder="Ex: Área de risco alto">
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="closeModal()" class="btn btn-secondary">Cancelar</button>
                <button type="submit" class="btn btn-primary px-6">Salvar</button>
            </div>
        </form>
    </div>
</div>

<script>
    // --- VARIÁVEIS GLOBAIS ---
    let map, drawnItems, drawControl;
    let geofences = [];
    let currentLayer = null; // Layer sendo desenhado atualmente

    // 1. INICIALIZAÇÃO
    document.addEventListener('DOMContentLoaded', () => {
        initMap();
        loadGeofences();
    });

    function initMap() {
        // Mapa base
        map = L.map('map-geofence', { zoomControl: false }).setView([-14.2350, -51.9253], 4);
        L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', { maxZoom: 19 }).addTo(map);
        L.control.zoom({ position: 'topright' }).addTo(map);

        // FeatureGroup para armazenar itens desenhados
        drawnItems = new L.FeatureGroup();
        map.addLayer(drawnItems);

        // Controles de Desenho (Traccar suporta Polígono, Círculo e Linha, vamos focar em Polígono e Círculo)
        drawControl = new L.Control.Draw({
            position: 'topleft',
            draw: {
                polygon: { allowIntersection: false, showArea: true, shapeOptions: { color: '#3b82f6' } },
                circle: { shapeOptions: { color: '#ef4444' } },
                marker: false,
                circlemarker: false,
                polyline: false, // Traccar suporta, mas é menos comum para cercas de área
                rectangle: false // Converte para polígono
            },
            edit: false // Edição faremos deletando e recriando por simplicidade inicial
        });
        map.addControl(drawControl);

        // Evento: Desenho Criado
        map.on(L.Draw.Event.CREATED, function (e) {
            const type = e.layerType;
            const layer = e.layer;

            // Converter para WKT do Traccar
            let wkt = '';
            if (type === 'circle') {
                const lat = layer.getLatLng().lat.toFixed(6);
                const lng = layer.getLatLng().lng.toFixed(6);
                const rad = layer.getRadius().toFixed(0);
                wkt = `CIRCLE (${lat} ${lng}, ${rad})`;
            } else if (type === 'polygon') {
                const latlngs = layer.getLatLngs()[0]; // Pega o primeiro anel
                let coords = latlngs.map(p => `${p.lat.toFixed(6)} ${p.lng.toFixed(6)}`).join(', ');
                // Fecha o polígono repetindo o primeiro ponto
                coords += `, ${latlngs[0].lat.toFixed(6)} ${latlngs[0].lng.toFixed(6)}`;
                wkt = `POLYGON ((${coords}))`;
            }

            if (wkt) {
                currentLayer = layer;
                drawnItems.addLayer(layer);
                openModal(wkt);
            }
        });
    }

    // 2. CARREGAR CERCAS
    async function loadGeofences() {
        const listEl = document.getElementById('geofence-list');
        try {
            const res = await fetch('/api_dados.php?endpoint=/geofences'); // Endpoint Proxy
            if (!res.ok) throw new Error("Erro API");
            geofences = await res.json();

            renderList();
            renderMapShapes();

        } catch (e) {
            listEl.innerHTML = `<div class="p-8 text-center text-red-500">Erro ao carregar cercas.</div>`;
        }
    }

    // 3. RENDERIZAR LISTA
    function renderList() {
        const listEl = document.getElementById('geofence-list');
        const search = document.getElementById('search-geo').value.toLowerCase();
        
        let html = '';
        geofences.forEach(geo => {
            if (search && !geo.name.toLowerCase().includes(search)) return;

            // Determina ícone baseado no tipo (simplificado pela string area)
            let icon = 'draw-polygon';
            let color = 'text-blue-600';
            if (geo.area.includes('CIRCLE')) { icon = 'dot-circle'; color = 'text-red-500'; }

            html += `
            <div onclick="focusGeofence(${geo.id})" class="geofence-list-item p-4 border-b border-gray-50 flex justify-between items-center group">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded bg-slate-100 flex items-center justify-center ${color}">
                        <i class="fas fa-${icon}"></i>
                    </div>
                    <div>
                        <div class="font-bold text-slate-700 text-sm">${geo.name}</div>
                        <div class="text-[10px] text-gray-400 max-w-[150px] truncate">${geo.description || 'Sem descrição'}</div>
                    </div>
                </div>
                <button onclick="deleteGeofence(event, ${geo.id})" class="text-gray-300 hover:text-red-500 transition px-2">
                    <i class="fas fa-trash"></i>
                </button>
            </div>`;
        });

        if (!html) html = '<div class="p-8 text-center text-gray-400 italic">Nenhuma cerca encontrada.</div>';
        listEl.innerHTML = html;
    }

    // 4. RENDERIZAR NO MAPA
    function renderMapShapes() {
        drawnItems.clearLayers();
        
        geofences.forEach(geo => {
            const wkt = geo.area;
            let layer;

            try {
                if (wkt.startsWith('CIRCLE')) {
                    // Parse CIRCLE (lat lon, radius)
                    const content = wkt.match(/\(([^)]+)\)/)[1];
                    const [lat, lon, rad] = content.replace(/,/g, '').split(' ');
                    layer = L.circle([parseFloat(lat), parseFloat(lon)], { radius: parseFloat(rad), color: '#ef4444', weight: 2 });
                } else if (wkt.startsWith('POLYGON')) {
                    // Parse POLYGON ((lat lon, lat lon...))
                    const content = wkt.match(/\(\(([^)]+)\)\)/)[1];
                    const points = content.split(',').map(pair => {
                        const [lat, lon] = pair.trim().split(' ');
                        return [parseFloat(lat), parseFloat(lon)];
                    });
                    layer = L.polygon(points, { color: '#3b82f6', weight: 2 });
                }

                if (layer) {
                    layer.geoId = geo.id;
                    layer.bindTooltip(`<b>${geo.name}</b>`, { direction: 'center', permanent: false });
                    drawnItems.addLayer(layer);
                }
            } catch (e) { console.error("Erro parse WKT", wkt); }
        });
        
        // Fit bounds se houver cercas
        if (geofences.length > 0) map.fitBounds(drawnItems.getBounds(), { padding: [50, 50] });
    }

    // 5. SALVAR NOVA CERCA
    async function saveGeofence(e) {
        e.preventDefault();
        const name = document.getElementById('geo-name').value;
        const desc = document.getElementById('geo-desc').value;
        const area = document.getElementById('geo-area').value;
        
        if (!area) return alert("Erro no desenho.");

        // Estrutura Traccar
        const payload = {
            name: name,
            description: desc,
            area: area,
            attributes: {} // Tenant ID injetado no PHP se necessário, ou aqui se tiver acesso
        };

        try {
            const res = await fetch('/api_dados.php?endpoint=/geofences', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            if (res.ok) {
                closeModal();
                loadGeofences(); // Recarrega tudo
                showToast("Cerca criada com sucesso!", "success");
            } else {
                throw new Error("Falha ao salvar");
            }
        } catch (err) {
            alert("Erro ao salvar cerca.");
        }
    }

    // 6. DELETAR CERCA
    async function deleteGeofence(e, id) {
        e.stopPropagation();
        if (!confirm("Tem certeza que deseja excluir esta cerca?")) return;

        try {
            const res = await fetch(`/api_dados.php?endpoint=/geofences/${id}`, { method: 'DELETE' });
            if (res.ok) {
                loadGeofences();
                showToast("Cerca removida.", "blue");
            }
        } catch (err) { alert("Erro ao excluir."); }
    }

    // 7. INTERAÇÕES
    function focusGeofence(id) {
        const layer = Object.values(drawnItems._layers).find(l => l.geoId === id);
        if (layer) {
            map.flyToBounds(layer.getBounds(), { maxZoom: 16, padding: [50, 50] });
            layer.openTooltip();
        }
        // Highlight na lista
        // (Pode adicionar classe 'active' aqui se desejar)
    }

    function filterGeofences() { renderList(); }

    function openModal(wkt) {
        document.getElementById('modal-save-geo').classList.remove('hidden');
        document.getElementById('geo-area').value = wkt;
        document.getElementById('geo-name').focus();
    }

    function closeModal() {
        document.getElementById('modal-save-geo').classList.add('hidden');
        document.getElementById('form-geo').reset();
        if (currentLayer && !currentLayer.geoId) {
            drawnItems.removeLayer(currentLayer); // Remove desenho não salvo
        }
    }
    
    function cancelDrawing() {
        // Se houver desenho em progresso, cancela (drawControl cuida disso nativamente, aqui é só UI)
        document.getElementById('drawing-hint').classList.add('hidden');
    }
</script>