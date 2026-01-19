<?php
if (!isset($_SESSION['user_id'])) exit;
// Apenas SuperAdmin ou Admin deve ver a doc técnica
if ($_SESSION['user_role'] != 'superadmin' && $_SESSION['user_role'] != 'admin') exit('Acesso restrito.');

$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
?>

<div class="flex flex-col h-screen bg-slate-50 font-sans">
    <div class="bg-white border-b border-gray-200 px-8 py-5 shadow-sm z-10">
        <div class="flex justify-between items-center">
            <div>
                <h2 class="text-2xl font-bold text-slate-800 flex items-center gap-2">
                    <i class="fas fa-mobile-alt text-indigo-600"></i> API Mobile Full Stack
                </h2>
                <p class="text-sm text-slate-500 mt-1">Documentação completa para desenvolvimento do App (Android/iOS).</p>
            </div>
            <div class="text-right">
                <span class="bg-indigo-100 text-indigo-700 px-3 py-1 rounded text-xs font-bold">v2.0 Mobile Ready</span>
            </div>
        </div>
    </div>

    <div class="flex-1 overflow-auto p-8">
        <div class="max-w-7xl mx-auto space-y-8">

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="bg-slate-800 px-6 py-4 border-b border-slate-700">
                    <h3 class="text-lg font-bold text-white"><i class="fas fa-fingerprint mr-2"></i> 1. Autenticação & Identidade Visual</h3>
                </div>
                <div class="p-6">
                    <p class="text-sm text-slate-600 mb-4">
                        O endpoint de login retorna não apenas o token de sessão, mas também as **cores e logos** do Tenant para personalização imediata do App (White Label).
                    </p>
                    
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <div>
                            <div class="flex items-center gap-3 mb-2">
                                <span class="bg-blue-600 text-white font-bold px-2 py-1 rounded text-xs">POST</span>
                                <code class="text-slate-700 font-mono text-sm">/api_login.php</code>
                            </div>
                            <div class="bg-slate-900 text-slate-300 p-4 rounded-lg font-mono text-xs overflow-x-auto">
<pre>
{
    "email": "motorista@empresa.com",
    "password": "senha_secreta",
    "tenant": "slug_empresa" // Opcional
}
</pre>
                            </div>
                        </div>
                        
                        <div>
                            <div class="flex items-center gap-3 mb-2">
                                <span class="bg-green-600 text-white font-bold px-2 py-1 rounded text-xs">200 OK</span>
                                <span class="text-xs text-slate-500">Retorno Completo</span>
                            </div>
                            <div class="bg-slate-900 text-slate-300 p-4 rounded-lg font-mono text-xs overflow-x-auto h-64">
<pre>
{
    "success": true,
    "session_id": "a1b2c3d4...", // Cookie
    "user": {
        "id": 10,
        "name": "Carlos Silva",
        "role": "user", // admin, superadmin
        "avatar": "/uploads/avatars/10.jpg"
    },
    "tenant": {
        "id": 1,
        "name": "Rastreio VIP",
        "primary_color": "#ed1b24",
        "secondary_color": "#1e293b",
        "logo_url": "/uploads/logo_1.png",
        "bg_url": "/uploads/bg_1.jpg"
    },
    "permissions": {
        "view_reports": true,
        "block_vehicle": false
    }
}
</pre>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="bg-indigo-900 px-6 py-4 border-b border-indigo-800">
                    <h3 class="text-lg font-bold text-white"><i class="fas fa-map-marked-alt mr-2"></i> 2. Mapa & Rastreamento</h3>
                </div>
                <div class="p-6 space-y-6">
                    
                    <div class="endpoint-block">
                        <div class="flex justify-between items-start">
                            <div class="flex-1">
                                <div class="flex items-center gap-3 mb-2">
                                    <span class="bg-green-600 text-white font-bold px-2 py-1 rounded text-xs">GET</span>
                                    <code class="text-slate-700 font-mono text-sm font-bold">/api_dados.php?endpoint=/devices</code>
                                </div>
                                <p class="text-xs text-slate-500">Retorna todos os veículos permitidos para o usuário. Inclui status online/offline e ícone.</p>
                            </div>
                        </div>
                        <div class="mt-2 bg-slate-100 p-3 rounded border border-slate-200 font-mono text-xs text-slate-600">
                            [ { "id": 1, "name": "Caminhão 01", "status": "online", "lastUpdate": "...", "category": "truck" }, ... ]
                        </div>
                    </div>

                    <div class="endpoint-block">
                        <div class="flex items-center gap-3 mb-2">
                            <span class="bg-green-600 text-white font-bold px-2 py-1 rounded text-xs">GET</span>
                            <code class="text-slate-700 font-mono text-sm font-bold">/api_dados.php?endpoint=/positions</code>
                        </div>
                        <p class="text-xs text-slate-500 mb-2">Atualiza a localização no mapa. Chamar a cada 5-10s.</p>
                        <div class="bg-slate-900 text-slate-300 p-3 rounded-lg font-mono text-xs">
                            [ { "deviceId": 1, "latitude": -23.55, "longitude": -46.63, "speed": 40, "course": 180, "attributes": { "ignition": true, "batteryLevel": 100 } } ]
                        </div>
                    </div>

                    <div class="endpoint-block">
                        <div class="flex items-center gap-3 mb-2">
                            <span class="bg-green-600 text-white font-bold px-2 py-1 rounded text-xs">GET</span>
                            <code class="text-slate-700 font-mono text-sm font-bold">/api_dados.php?endpoint=/positions&deviceId=X&from=ISO&to=ISO</code>
                        </div>
                        <p class="text-xs text-slate-500">
                            Histórico de rota. Datas em formato ISO 8601 UTC (ex: <code>2023-10-01T08:00:00Z</code>).
                        </p>
                    </div>

                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="bg-blue-800 px-6 py-4 border-b border-blue-700">
                    <h3 class="text-lg font-bold text-white"><i class="fas fa-chart-pie mr-2"></i> 3. Dashboard, Ranking & Jornada</h3>
                </div>
                <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-8">
                    
                    <div>
                        <h4 class="font-bold text-slate-700 mb-2 text-sm uppercase">Resumo da Frota (Home)</h4>
                        <div class="flex items-center gap-3 mb-2">
                            <span class="bg-green-600 text-white font-bold px-2 py-1 rounded text-xs">GET</span>
                            <code class="text-slate-700 font-mono text-sm">/api_kpis.php</code>
                        </div>
                        <div class="bg-slate-100 p-3 rounded font-mono text-xs text-slate-600">
                            { "total_vehicles": 50, "online": 45, "offline": 5, "alerts_today": 12, "active_drivers": 30 }
                        </div>
                    </div>

                    <div>
                        <h4 class="font-bold text-slate-700 mb-2 text-sm uppercase">Ranking de Motoristas</h4>
                        <div class="flex items-center gap-3 mb-2">
                            <span class="bg-green-600 text-white font-bold px-2 py-1 rounded text-xs">GET</span>
                            <code class="text-slate-700 font-mono text-sm">/api_ranking.php</code>
                        </div>
                        <div class="bg-slate-100 p-3 rounded font-mono text-xs text-slate-600">
                            [ { "driver_name": "João", "score": 98, "violations": 2, "distance": 1500 }, ... ]
                        </div>
                    </div>

                    <div>
                        <h4 class="font-bold text-slate-700 mb-2 text-sm uppercase">Jornada de Trabalho</h4>
                        <div class="flex items-center gap-3 mb-2">
                            <span class="bg-green-600 text-white font-bold px-2 py-1 rounded text-xs">GET</span>
                            <code class="text-slate-700 font-mono text-sm">/api_jornada.php?driver_id=X</code>
                        </div>
                        <div class="bg-slate-100 p-3 rounded font-mono text-xs text-slate-600">
                            { "status": "driving", "start_time": "08:00", "driving_time": "04:30", "rest_time": "00:15" }
                        </div>
                    </div>

                    <div>
                        <h4 class="font-bold text-slate-700 mb-2 text-sm uppercase">Central de Alertas</h4>
                        <div class="flex items-center gap-3 mb-2">
                            <span class="bg-green-600 text-white font-bold px-2 py-1 rounded text-xs">GET</span>
                            <code class="text-slate-700 font-mono text-sm">/api_alertas.php</code>
                        </div>
                        <div class="bg-slate-100 p-3 rounded font-mono text-xs text-slate-600">
                            [ { "id": 101, "type": "overspeed", "vehicle": "ABC-1234", "time": "...", "lat": -23.5, "lon": -46.6 } ]
                        </div>
                    </div>

                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="bg-orange-700 px-6 py-4 border-b border-orange-600">
                    <h3 class="text-lg font-bold text-white"><i class="fas fa-shield-alt mr-2"></i> 4. Cercas & Segurança</h3>
                </div>
                <div class="p-6 space-y-6">
                    
                    <div class="endpoint-block">
                        <div class="flex items-center gap-3 mb-2">
                            <span class="bg-green-600 text-white font-bold px-2 py-1 rounded text-xs">GET</span>
                            <code class="text-slate-700 font-mono text-sm font-bold">/api_dados.php?endpoint=/geofences</code>
                        </div>
                        <p class="text-xs text-slate-500">Retorna polígonos e círculos para desenhar no mapa do App.</p>
                        <div class="bg-slate-900 text-slate-300 p-3 rounded-lg font-mono text-xs">
                            [ { "id": 5, "name": "Garagem", "area": "POLYGON((-23...))" } ]
                        </div>
                    </div>

                    <div class="endpoint-block">
                        <div class="flex items-center gap-3 mb-2">
                            <span class="bg-blue-600 text-white font-bold px-2 py-1 rounded text-xs">POST</span>
                            <code class="text-slate-700 font-mono text-sm font-bold">/api_dados.php?action=secure_command</code>
                        </div>
                        <p class="text-xs text-slate-500">Bloqueio/Desbloqueio com senha de segurança.</p>
                        <div class="bg-slate-100 p-3 rounded border border-slate-200 font-mono text-xs text-slate-600">
                            Payload: { "deviceId": 1, "type": "lock", "password": "user_pass" }
                        </div>
                    </div>

                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="bg-emerald-800 px-6 py-4 border-b border-emerald-700">
                    <h3 class="text-lg font-bold text-white"><i class="fas fa-file-alt mr-2"></i> 5. Relatórios</h3>
                </div>
                <div class="p-6">
                    <p class="text-sm text-slate-600 mb-4">
                        O App pode solicitar relatórios sumarizados (Summary), de rotas (Route), eventos (Events) ou paradas (Stops).
                        O Endpoint é o Proxy do Traccar.
                    </p>
                    <div class="flex items-center gap-3 mb-2">
                        <span class="bg-green-600 text-white font-bold px-2 py-1 rounded text-xs">GET</span>
                        <code class="text-slate-700 font-mono text-sm font-bold">/api_dados.php?endpoint=/reports/summary&deviceId=X&from=...&to=...</code>
                    </div>
                    <div class="bg-slate-100 p-3 rounded border border-slate-200 font-mono text-xs text-slate-600">
                        // Exemplo de Retorno (Resumo)
                        [
                            {
                                "deviceId": 1,
                                "deviceName": "Carro 01",
                                "distance": 150.5, // km
                                "averageSpeed": 45.0,
                                "maxSpeed": 80.0,
                                "engineHours": 18000000 // ms
                            }
                        ]
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>