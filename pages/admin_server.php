<?php
if (!isset($_SESSION['user_id'])) exit;
if ($_SESSION['user_role'] != 'superadmin') exit('Acesso Restrito');
?>

<div class="flex flex-col h-screen bg-slate-50">
    <div class="bg-white border-b border-gray-200 px-8 py-5 flex justify-between items-center shadow-sm z-10">
        <div>
            <h2 class="text-2xl font-bold text-slate-800 flex items-center gap-2">
                <i class="fas fa-server text-indigo-600"></i> Saúde do Servidor
            </h2>
            <p class="text-sm text-slate-500">Monitoramento VPS (Hardware & Serviços)</p>
        </div>
        <div class="flex items-center gap-2 text-xs font-mono text-gray-500 bg-gray-100 px-3 py-1 rounded border">
            <i class="fas fa-clock"></i> Uptime: <span id="val-uptime" class="font-bold text-slate-700">Carregando...</span>
        </div>
    </div>

    <div class="flex-1 overflow-auto p-8">
        
        <h3 class="text-xs font-bold text-slate-400 uppercase mb-4 tracking-wider">Status dos Serviços</h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            
            <div class="bg-white p-5 rounded-xl border border-gray-200 shadow-sm flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center text-xl">
                        <i class="fas fa-map-marked-alt"></i>
                    </div>
                    <div><h4 class="font-bold text-slate-700">Traccar GPS</h4><p class="text-xs text-gray-400">Porta 8082</p></div>
                </div>
                <span id="svc-traccar" class="badge-loading">...</span>
            </div>

            <div class="bg-white p-5 rounded-xl border border-gray-200 shadow-sm flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center text-xl">
                        <i class="fas fa-database"></i>
                    </div>
                    <div><h4 class="font-bold text-slate-700">PostgreSQL</h4><p class="text-xs text-gray-400">Porta 5432</p></div>
                </div>
                <span id="svc-postgres" class="badge-loading">...</span>
            </div>

            <div class="bg-white p-5 rounded-xl border border-gray-200 shadow-sm flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-purple-50 text-purple-600 flex items-center justify-center text-xl">
                        <i class="fas fa-globe"></i>
                    </div>
                    <div><h4 class="font-bold text-slate-700">Webserver</h4><p class="text-xs text-gray-400">Nginx/PHP</p></div>
                </div>
                <span class="badge-online"><span class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></span> Online</span>
            </div>
        </div>

        <h3 class="text-xs font-bold text-slate-400 uppercase mb-4 tracking-wider">Recursos de Hardware</h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            
            <div class="bg-white p-6 rounded-xl border border-gray-200 shadow-sm">
                <div class="flex justify-between items-center mb-4">
                    <h4 class="font-bold text-slate-700 flex items-center gap-2"><i class="fas fa-microchip text-slate-400"></i> CPU</h4>
                    <span id="txt-cpu" class="text-2xl font-bold text-slate-700">0%</span>
                </div>
                <div class="w-full bg-slate-100 rounded-full h-3 overflow-hidden">
                    <div id="bar-cpu" class="h-full bg-blue-500 transition-all duration-1000" style="width: 0%"></div>
                </div>
                <p class="text-xs text-gray-400 mt-2">Carga de processamento.</p>
            </div>

            <div class="bg-white p-6 rounded-xl border border-gray-200 shadow-sm">
                <div class="flex justify-between items-center mb-4">
                    <h4 class="font-bold text-slate-700 flex items-center gap-2"><i class="fas fa-memory text-slate-400"></i> Memória RAM</h4>
                    <span id="txt-ram" class="text-2xl font-bold text-slate-700">0%</span>
                </div>
                <div class="w-full bg-slate-100 rounded-full h-3 overflow-hidden">
                    <div id="bar-ram" class="h-full bg-purple-500 transition-all duration-1000" style="width: 0%"></div>
                </div>
                <div class="flex justify-between mt-2 text-xs text-gray-500 font-mono">
                    <span>Uso: <span id="val-ram-used">0</span> MB</span>
                    <span>Total: <span id="val-ram-total">0</span> MB</span>
                </div>
            </div>

            <div class="bg-white p-6 rounded-xl border border-gray-200 shadow-sm">
                <div class="flex justify-between items-center mb-4">
                    <h4 class="font-bold text-slate-700 flex items-center gap-2"><i class="fas fa-hdd text-slate-400"></i> Armazenamento</h4>
                    <span id="txt-disk" class="text-2xl font-bold text-slate-700">0%</span>
                </div>
                <div class="w-full bg-slate-100 rounded-full h-3 overflow-hidden">
                    <div id="bar-disk" class="h-full bg-orange-500 transition-all duration-1000" style="width: 0%"></div>
                </div>
                <div class="flex justify-between mt-2 text-xs text-gray-500 font-mono">
                    <span>Uso: <span id="val-disk-used">0</span> GB</span>
                    <span>Total: <span id="val-disk-total">0</span> GB</span>
                </div>
            </div>

        </div>
    </div>
</div>

<style>
    .badge-loading { background: #f3f4f6; color: #9ca3af; padding: 2px 8px; border-radius: 99px; font-size: 10px; font-weight: bold; }
    .badge-online { background: #dcfce7; color: #166534; padding: 4px 12px; border-radius: 99px; font-size: 11px; font-weight: bold; border: 1px solid #bbf7d0; display: flex; align-items: center; gap: 4px; }
    .badge-offline { background: #fee2e2; color: #991b1b; padding: 4px 12px; border-radius: 99px; font-size: 11px; font-weight: bold; border: 1px solid #fecaca; display: flex; align-items: center; gap: 4px; }
</style>

<script>
    async function updateMetrics() {
        try {
            // SOLUÇÃO DO ERRO: Chama o arquivo dedicado na raiz
            const res = await fetch('/api_server.php'); 
            
            if (!res.ok) throw new Error('Falha HTTP: ' + res.status);
            
            const data = await res.json(); // Agora deve ser JSON puro sem HTML

            // Uptime
            document.getElementById('val-uptime').innerText = data.uptime;

            // Barras
            updateBar('cpu', data.cpu, '%');
            updateBar('ram', data.ram_pct, '%');
            updateBar('disk', data.disk_pct, '%');

            // Valores Texto
            document.getElementById('val-ram-used').innerText = data.ram_used;
            document.getElementById('val-ram-total').innerText = data.ram_total;
            document.getElementById('val-disk-used').innerText = data.disk_used;
            document.getElementById('val-disk-total').innerText = data.disk_total;

            // Serviços
            updateService('traccar', data.services.traccar);
            updateService('postgres', data.services.postgres);

        } catch(e) {
            console.error("Erro no Dashboard:", e);
            document.getElementById('val-uptime').innerText = "Erro de Conexão";
        }
    }

    function updateBar(id, val, suffix) {
        const bar = document.getElementById('bar-'+id);
        const txt = document.getElementById('txt-'+id);
        
        // Garante que é número
        val = parseFloat(val) || 0;
        
        bar.style.width = val + '%';
        txt.innerText = val + suffix;

        // Cores Dinâmicas
        bar.className = 'h-full transition-all duration-1000 ';
        if(val < 60) bar.className += 'bg-green-500';
        else if(val < 85) bar.className += 'bg-yellow-500';
        else bar.className += 'bg-red-500';
    }

    function updateService(id, isOnline) {
        const el = document.getElementById('svc-'+id);
        if(isOnline) {
            el.className = 'badge-online';
            el.innerHTML = '<span class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></span> Online';
        } else {
            el.className = 'badge-offline';
            el.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Parado';
        }
    }

    // Inicia
    updateMetrics();
    setInterval(updateMetrics, 5000);
</script>
