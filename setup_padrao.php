<?php
// setup_padrao.php - RODE UMA VEZ PARA CONFIGURAR O TRACCAR
require_once 'TraccarApi.php';
$traccar = new TraccarApi();

echo "<h1>Configuração Automática do Servidor Traccar</h1>";
echo "<pre>";

// 1. CRIAR ATRIBUTO CALCULADO GLOBAL (Correção de Ignição)
// Isso faz qualquer rastreador que manda 'io1' ou 'acc' ser entendido como 'ignition'
$ignitionAttr = [
    'description' => 'Ignition Logic (Global)',
    'attribute' => 'ignition',
    'expression' => 'io1 ? true : (acc ? true : ignition)',
    'type' => 'boolean'
];

// Verifica se já existe
$allAttrs = json_decode($traccar->curl('/attributes/computed'), true);
$attrId = null;
foreach($allAttrs as $a) {
    if ($a['description'] == $ignitionAttr['description']) $attrId = $a['id'];
}

if (!$attrId) {
    $res = $traccar->curl('/attributes/computed', 'POST', $ignitionAttr);
    $json = json_decode($res, true);
    $attrId = $json['id'];
    echo "✅ Atributo de Ignição Criado (ID: $attrId)\n";
} else {
    echo "ℹ️ Atributo de Ignição já existe (ID: $attrId)\n";
}

// 2. CRIAR NOTIFICAÇÕES PADRÃO (Globais)
$defaultNotifs = [
    ['type' => 'ignitionOn', 'notificators' => 'web,firebase', 'always' => true],
    ['type' => 'ignitionOff', 'notificators' => 'web,firebase', 'always' => true],
    ['type' => 'deviceOffline', 'notificators' => 'web,firebase', 'always' => true],
    ['type' => 'deviceOverspeed', 'notificators' => 'web,firebase', 'always' => true],
    ['type' => 'alarm', 'notificators' => 'web,firebase', 'always' => true] // Botão de pânico
];

$allNotifs = json_decode($traccar->curl('/notifications'), true);
$notifIds = [];

foreach ($defaultNotifs as $def) {
    $found = false;
    foreach($allNotifs as $n) {
        if ($n['type'] == $def['type']) {
            $notifIds[] = $n['id'];
            $found = true;
            echo "ℹ️ Notificação '{$def['type']}' já existe (ID: {$n['id']})\n";
            break;
        }
    }
    
    if (!$found) {
        $res = $traccar->curl('/notifications', 'POST', $def);
        $json = json_decode($res, true);
        if (isset($json['id'])) {
            $notifIds[] = $json['id'];
            echo "✅ Notificação '{$def['type']}' Criada (ID: {$json['id']})\n";
        } else {
            echo "❌ Erro ao criar '{$def['type']}': $res\n";
        }
    }
}

// SALVAR IDS EM ARQUIVO PARA O SISTEMA USAR
$configData = [
    'global_ignition_attr_id' => $attrId,
    'global_notification_ids' => $notifIds
];
file_put_contents('traccar_config.json', json_encode($configData));
echo "\n✅ Configuração salva em traccar_config.json\n";
echo "Agora o sistema vai aplicar essas regras automaticamente nos novos veículos.";
echo "</pre>";
?>