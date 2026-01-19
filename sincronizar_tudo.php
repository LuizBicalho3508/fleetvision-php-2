<?php
// sincronizar_tudo.php - Aplica as regras do JSON em TODOS os veículos existentes
session_start();
require_once 'TraccarApi.php';
$traccar = new TraccarApi();

// Configuração DB
$pdo = new PDO("pgsql:host=127.0.0.1;port=5432;dbname=traccar", "traccar", "traccar");

echo "<h1>🔄 Sincronizador de Regras Globais</h1>";

if (!file_exists('traccar_config.json')) {
    die("❌ Erro: Arquivo 'traccar_config.json' não encontrado. Rode o setup_padrao.php primeiro.");
}

$config = json_decode(file_get_contents('traccar_config.json'), true);
echo "<div style='background:#f0f9ff; padding:10px; border:1px solid #bae6fd; border-radius:5px;'>";
echo "<strong>Regras Carregadas:</strong><br>";
echo "• Atributo Ignição ID: " . ($config['global_ignition_attr_id'] ?? 'N/A') . "<br>";
echo "• Notificações IDs: " . implode(", ", $config['global_notification_ids'] ?? []) . "<br>";
echo "</div><br>";

// Busca TODOS os veículos do banco (de todos os tenants)
$vehicles = $pdo->query("SELECT id, name, traccar_device_id FROM saas_vehicles")->fetchAll(PDO::FETCH_ASSOC);

echo "<h3>Processando " . count($vehicles) . " veículos...</h3>";
echo "<ul style='font-family:monospace;'>";

foreach ($vehicles as $v) {
    $tid = $v['traccar_device_id'];
    echo "<li>Veículo: <strong>{$v['name']}</strong> (ID Traccar: $tid)... ";
    
    // 1. Aplica Atributo
    if (!empty($config['global_ignition_attr_id'])) {
        $traccar->curl('/permissions', 'POST', ['deviceId' => $tid, 'attributeId' => $config['global_ignition_attr_id']]);
    }

    // 2. Aplica Notificações
    if (!empty($config['global_notification_ids'])) {
        foreach ($config['global_notification_ids'] as $nid) {
            $traccar->curl('/permissions', 'POST', ['deviceId' => $tid, 'notificationId' => $nid]);
        }
    }
    
    echo "<span style='color:green'>OK (Regras Aplicadas)</span></li>";
    flush(); // Força saída visual
}

echo "</ul>";
echo "<h2>✅ Sincronização Concluída!</h2>";
?>