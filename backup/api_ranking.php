<?php
session_start();
header('Content-Type: application/json');
ini_set('display_errors', 0);

if (!isset($_SESSION['user_id'])) { http_response_code(403); exit(json_encode(['error' => 'Acesso negado'])); }

// --- CONTEXTO DE SEGURANÇA ---
require 'db.php';

$tenant_id  = $_SESSION['tenant_id'];
$user_id    = $_SESSION['user_id'];
$user_email = $_SESSION['user_email'] ?? '';
$user_role  = $_SESSION['user_role'] ?? 'user';

// Determina restrições
$loggedCustomerId = null;
$isRestricted = false;

if ($user_role != 'admin' && $user_role != 'superadmin') {
    $isRestricted = true;
    $stmtUserCheck = $pdo->prepare("SELECT customer_id FROM saas_users WHERE id = ?");
    $stmtUserCheck->execute([$user_id]);
    $userDirectCustomer = $stmtUserCheck->fetchColumn();
    $loggedCustomerId = $userDirectCustomer ?: ($pdo->query("SELECT id FROM saas_customers WHERE email = '$user_email' AND tenant_id = $tenant_id")->fetchColumn());
}

// Filtro SQL para VEÍCULOS (Este funciona pois a tabela tem as colunas)
$restrictionSQL = "";
if ($isRestricted) {
    if ($loggedCustomerId) {
        $restrictionSQL = " AND (client_id = $loggedCustomerId OR user_id = $user_id)";
    } else {
        $restrictionSQL = " AND user_id = $user_id";
    }
}

try {
    // --- PADRÕES DO SISTEMA ---
    $defaultRules = [
        'speed_limit' => 110,
        'speed_penalty' => 5,
        'idle_interval' => 30,
        'idle_penalty' => 2,
        'journey_continuous_penalty' => 10,
        'journey_daily_penalty' => 20
    ];

    // --- AÇÃO: SALVAR REGRAS ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save_rules') {
        if ($isRestricted) { http_response_code(403); exit(json_encode(['error' => 'Sem permissão'])); }
        
        $input = file_get_contents('php://input');
        if(!json_decode($input)) throw new Exception("JSON inválido");
        
        $stmt = $pdo->prepare("INSERT INTO saas_ranking_config (tenant_id, rules) VALUES (?, ?) 
                               ON CONFLICT (tenant_id) DO UPDATE SET rules = EXCLUDED.rules, updated_at = NOW()");
        $stmt->execute([$tenant_id, $input]);
        echo json_encode(['success' => true]);
        exit;
    }

    // --- AÇÃO: LER REGRAS ---
    $stmtCfg = $pdo->prepare("SELECT rules FROM saas_ranking_config WHERE tenant_id = ?");
    $stmtCfg->execute([$tenant_id]);
    $dbJson = $stmtCfg->fetchColumn();
    $dbRules = $dbJson ? json_decode($dbJson, true) : [];
    $currentRules = array_merge($defaultRules, $dbRules ?? []);

    if (isset($_GET['action']) && $_GET['action'] === 'get_rules') {
        echo json_encode($currentRules);
        exit;
    }

    // --- CÁLCULO DO RANKING ---
    $start = $_GET['from'] ?? date('Y-m-01 00:00:00');
    $end   = $_GET['to'] ?? date('Y-m-d 23:59:59');

    // 1. JORNADA (Busca violações globais do tenant, filtro será aplicado depois)
    $sqlJourney = "SELECT driver_id, 
                   SUM(CASE WHEN duration > (5.5 * 3600) THEN 1 ELSE 0 END) as violations_continuous,
                   SUM(CASE WHEN duration > (10 * 3600) THEN 1 ELSE 0 END) as violations_daily
                   FROM (SELECT driver_id, EXTRACT(EPOCH FROM (COALESCE(end_time, NOW()) - start_time)) as duration 
                   FROM saas_driver_journeys WHERE tenant_id = :tid AND start_time BETWEEN :start AND :end) sub GROUP BY driver_id";
    $stmtJ = $pdo->prepare($sqlJourney);
    $stmtJ->execute(['tid' => $tenant_id, 'start' => $start, 'end' => $end]);
    $jStats = $stmtJ->fetchAll(PDO::FETCH_ASSOC);
    
    $jPenalties = [];
    $jCounts = [];
    foreach($jStats as $r) {
        $jPenalties[$r['driver_id']] = ($r['violations_continuous'] * $currentRules['journey_continuous_penalty']) + 
                                       ($r['violations_daily'] * $currentRules['journey_daily_penalty']);
        $jCounts[$r['driver_id']] = ['cont' => $r['violations_continuous'], 'daily' => $r['violations_daily']];
    }

    // 2. MOTORISTAS (Busca TODOS do tenant, pois não temos client_id na tabela)
    // A filtragem será feita logicamente: se o motorista dirigiu um veículo "seu", ele aparece.
    $stmtD = $pdo->prepare("SELECT id, name, rfid_tag FROM saas_drivers WHERE tenant_id = ?");
    $stmtD->execute([$tenant_id]);
    
    $drivers = [];
    $rfidMap = [];
    
    foreach($stmtD->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $drivers[$d['id']] = [
            'id' => $d['id'], 
            'name' => $d['name'], 
            'score' => 100 - ($jPenalties[$d['id']] ?? 0),
            'stats' => [
                'idle_time'=>0, 'max_speed'=>0, 'count_speed'=>0, 'count_idle'=>0, 
                'count_j_cont'=>($jCounts[$d['id']]['cont']??0), 
                'count_j_daily'=>($jCounts[$d['id']]['daily']??0)
            ],
            'relevant' => false // Flag para saber se deve exibir
        ];
        if($d['rfid_tag']) $rfidMap[$d['rfid_tag']] = $d['id'];
    }

    // 3. VEÍCULOS (JÁ FILTRADOS POR PERMISSÃO)
    $sqlV = "SELECT id, name, traccar_device_id, idle_threshold, plate FROM saas_vehicles WHERE tenant_id = ? $restrictionSQL";
    $stmtV = $pdo->prepare($sqlV);
    $stmtV->execute([$tenant_id]);
    
    $rankVehicles = [];

    // Loop apenas nos veículos permitidos ao usuário
    foreach($stmtV->fetchAll(PDO::FETCH_ASSOC) as $veh) {
        $threshold = ($veh['idle_threshold'] ?: 5) * 60;
        
        $stmtPos = $pdo->prepare("SELECT servertime, speed, attributes::json->>'ignition' as ign, attributes::json->>'driverUniqueId' as rfid 
                                  FROM tc_positions WHERE deviceid = ? AND servertime BETWEEN ? AND ? ORDER BY servertime ASC");
        $stmtPos->execute([$veh['traccar_device_id'], $start, $end]);
        $positions = $stmtPos->fetchAll(PDO::FETCH_ASSOC);

        $vStats = ['idle_time'=>0, 'max_speed'=>0, 'count_speed'=>0, 'count_idle'=>0];
        $idleStart = null; 
        $isSpeeding = false;

        foreach($positions as $pos) {
            $speed = $pos['speed'] * 1.852; 
            $ign = ($pos['ign'] === 'true' || $pos['ign'] === true || $pos['ign'] === '1');
            $time = strtotime($pos['servertime']);
            
            if($speed > $vStats['max_speed']) $vStats['max_speed'] = $speed;

            // Identifica Motorista e marca como RELEVANTE (Visível)
            $drvId = null;
            if(!empty($pos['rfid']) && isset($rfidMap[$pos['rfid']])) {
                $drvId = $rfidMap[$pos['rfid']];
                $drivers[$drvId]['relevant'] = true; // <--- AGORA ELE APARECE NO RANKING
                
                if($speed > $drivers[$drvId]['stats']['max_speed']) $drivers[$drvId]['stats']['max_speed'] = $speed;
            }

            // Velocidade
            if($speed > $currentRules['speed_limit']) {
                if(!$isSpeeding) {
                    $vStats['count_speed']++; 
                    $isSpeeding = true;
                    if($drvId) $drivers[$drvId]['stats']['count_speed']++;
                }
            } else $isSpeeding = false;

            // Ociosidade
            if($ign && $speed < 2) {
                if($idleStart === null) $idleStart = $time;
            } else {
                if($idleStart !== null) {
                    $dur = $time - $idleStart;
                    if($dur >= $threshold) {
                        $vStats['idle_time'] += $dur;
                        $vStats['count_idle']++;
                        if($drvId) {
                            $drivers[$drvId]['stats']['idle_time'] += $dur;
                            $drivers[$drvId]['stats']['count_idle']++;
                        }
                    }
                    $idleStart = null;
                }
            }
        }
        
        if($idleStart !== null) {
            $dur = min(strtotime($end), time()) - $idleStart;
            if($dur >= $threshold) {
                $vStats['idle_time'] += $dur;
                $vStats['count_idle']++;
            }
        }

        // Score Veículo
        $vScore = 100 - ($vStats['count_idle'] * $currentRules['idle_penalty']) - ($vStats['count_speed'] * $currentRules['speed_penalty']);
        $rankVehicles[] = [
            'name' => $veh['name'],
            'plate' => $veh['plate'],
            'score' => max(0, $vScore),
            'stats' => $vStats,
            'fmt_idle' => gmdate("H:i:s", $vStats['idle_time'])
        ];
    }

    // Monta Lista Final de Motoristas
    $rankDrivers = [];
    foreach($drivers as $d) {
        // Se for usuário restrito, só mostra se for relevante (dirigiu um dos seus carros)
        // Se for admin, mostra todos
        if ($isRestricted && !$d['relevant']) continue;

        $s = $d['stats'];
        $penalty = ($s['count_idle'] * $currentRules['idle_penalty']) + ($s['count_speed'] * $currentRules['speed_penalty']);
        $d['score'] = max(0, $d['score'] - $penalty);
        $d['fmt_idle'] = gmdate("H:i:s", $s['idle_time']);
        
        // Classificação
        if($d['score'] >= 90) $d['class'] = 'A';
        elseif($d['score'] >= 70) $d['class'] = 'B';
        elseif($d['score'] >= 50) $d['class'] = 'C';
        else $d['class'] = 'D';

        $rankDrivers[] = $d;
    }

    usort($rankVehicles, fn($a,$b) => $b['score'] <=> $a['score']);
    usort($rankDrivers, fn($a,$b) => $b['score'] <=> $a['score']);

    echo json_encode(['vehicles' => $rankVehicles, 'drivers' => $rankDrivers]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>