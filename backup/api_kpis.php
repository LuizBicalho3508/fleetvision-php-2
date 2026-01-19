<?php
session_start();
header('Content-Type: application/json');
ini_set('display_errors', 0);

if (!isset($_SESSION['user_id'])) exit(json_encode(['error' => 'Auth required']));

$tenant_id = $_SESSION['tenant_id'];
$user_email = $_SESSION['user_email'];
$user_role = $_SESSION['user_role'] ?? 'user';

try {
    $pdo = new PDO("pgsql:host=127.0.0.1;port=5432;dbname=traccar", "traccar", "traccar");
    
    // 1. Identificar Cliente (Segurança)
    $loggedCustomerId = null;
    if ($user_role != 'admin' && $user_role != 'superadmin') {
        $stmtMe = $pdo->prepare("SELECT id FROM saas_customers WHERE email = ? AND tenant_id = ?");
        $stmtMe->execute([$user_email, $tenant_id]);
        $loggedCustomerId = $stmtMe->fetchColumn();
        if (!$loggedCustomerId) exit(json_encode([]));
    }

    // 2. Buscar Veículos e Últimas Posições
    // Precisamos do traccar_device_id para linkar com a tabela tc_positions
    $sql = "SELECT v.id, v.traccar_device_id, v.plate, v.name 
            FROM saas_vehicles v 
            WHERE v.tenant_id = :tid";
    
    $params = ['tid' => $tenant_id];
    if ($loggedCustomerId) {
        $sql .= " AND v.customer_id = :cid";
        $params['cid'] = $loggedCustomerId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Lista de IDs do Traccar
    $traccarIds = array_column($vehicles, 'traccar_device_id');
    if (empty($traccarIds)) {
        echo json_encode(['total'=>0, 'power_on'=>0, 'power_off'=>0, 'ign_on'=>0, 'ign_off'=>0, 'gps_on'=>0, 'gps_off'=>0]); 
        exit;
    }

    // 3. Buscar Últimas Posições no Traccar (tc_positions + tc_devices para status online)
    $idsStr = implode(',', array_map('intval', $traccarIds));
    
    $sqlPos = "
        SELECT p.deviceid, p.attributes, p.speed, p.fixtime, d.status as dev_status, d.lastupdate
        FROM tc_devices d
        LEFT JOIN tc_positions p ON d.positionid = p.id
        WHERE d.id IN ($idsStr)
    ";
    
    $positions = $pdo->query($sqlPos)->fetchAll(PDO::FETCH_ASSOC);

    // 4. Calcular KPIs com Normalização
    $stats = [
        'total' => count($vehicles),
        'online' => 0,      // Comunicando
        'offline' => 0,     // Sem sinal
        'ign_on' => 0,      // Ignição Ligada
        'ign_off' => 0,     // Ignição Desligada
        'power_on' => 0,    // Alimentação Conectada
        'power_off' => 0,   // Corte/Falha Bateria
        'battery_low' => 0  // Bateria Interna Fraca
    ];

    foreach ($positions as $pos) {
        $attr = json_decode($pos['attributes'], true) ?? [];
        
        // A. Status Online (Considerando delay de 10 min como offline ou status do traccar)
        $isOnline = ($pos['dev_status'] === 'online');
        // Opcional: Reforçar com lastupdate
        if ((time() - strtotime($pos['lastupdate'])) > 600) $isOnline = false;

        if ($isOnline) $stats['online']++; else $stats['offline']++;

        // B. Ignição (Ignition vs Motion)
        $ign = $attr['ignition'] ?? $attr['motion'] ?? false;
        if ($ign) $stats['ign_on']++; else $stats['ign_off']++;

        // C. Alimentação Externa (A LÓGICA QUE VOCÊ PEDIU)
        $voltage = 0;
        if (isset($attr['power'])) $voltage = $attr['power'];
        elseif (isset($attr['adc1'])) $voltage = $attr['adc1'];
        elseif (isset($attr['extBatt'])) $voltage = $attr['extBatt'];

        // Normalização de Voltagem (Alguns mandam em milivolts, mas a maioria é V)
        // Se for muito alto (> 1000), divide por 1000.
        if ($voltage > 100) $voltage = $voltage / 1000;

        // Regra: Se voltagem < 2V ou Alarme de Corte, considera desconectado
        $hasPowerCut = (isset($attr['alarm']) && $attr['alarm'] === 'powerCut');
        
        if ($voltage > 5.0 && !$hasPowerCut) {
            $stats['power_on']++;
        } else {
            $stats['power_off']++;
        }

        // D. Bateria Interna (Backup)
        $batInt = $attr['batteryLevel'] ?? 0;
        if ($batInt > 0 && $batInt < 30) $stats['battery_low']++;
    }

    echo json_encode($stats);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>