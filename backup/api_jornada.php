<?php
session_start();
header('Content-Type: application/json');
ini_set('display_errors', 0);

if (!isset($_SESSION['user_id'])) { http_response_code(403); exit(json_encode([])); }

$tenant_id = $_SESSION['tenant_id'];
$user_email = $_SESSION['user_email'] ?? '';
$user_role = $_SESSION['user_role'] ?? 'user';

// Configurações Lei 13.103
$MAX_CONTINUOUS = 5.5 * 3600; // 5h30
$MAX_DAILY = 10 * 3600;       // 10h
$MIN_REST = 30 * 60;          // 30min descanso

try {
    $pdo = new PDO("pgsql:host=127.0.0.1;port=5432;dbname=traccar", "traccar", "traccar");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1. FILTRO DE SEGURANÇA (ADMIN vs CLIENTE)
    $customerFilter = "";
    $params = ['tid' => $tenant_id];

    if ($user_role != 'admin' && $user_role != 'superadmin') {
        $stmtMe = $pdo->prepare("SELECT id FROM saas_customers WHERE email = ? AND tenant_id = ?");
        $stmtMe->execute([$user_email, $tenant_id]);
        $customerId = $stmtMe->fetchColumn();

        if ($customerId) {
            $customerFilter = " AND d.customer_id = :cid";
            $params['cid'] = $customerId;
        } else {
            echo json_encode([]); 
            exit;
        }
    }

    // 2. BUSCA JORNADAS (LÓGICA CORRIGIDA PARA VIRADA DE NOITE E SOMBRA)
    // - Pega jornadas que começaram hoje
    // - OU jornadas que terminaram hoje
    // - OU jornadas que estão ABERTAS (NULL) independente da data (resolve o problema de viagem iniciada ontem)
    $sql = "
        SELECT 
            j.driver_id, d.name as driver_name, d.cnh_number, d.rfid_tag,
            j.vehicle_id, v.name as vehicle_name, v.plate,
            j.start_time, 
            j.end_time,
            -- Se acabou, pega a duração real. Se está aberta, pega a diferença até AGORA.
            EXTRACT(EPOCH FROM (COALESCE(j.end_time, NOW()) - j.start_time)) as duration,
            CASE WHEN j.end_time IS NULL THEN 1 ELSE 0 END as is_active
        FROM saas_driver_journeys j
        JOIN saas_drivers d ON j.driver_id = d.id
        JOIN saas_vehicles v ON j.vehicle_id = v.id
        WHERE j.tenant_id = :tid
          $customerFilter
          AND (
              DATE(j.start_time) = CURRENT_DATE 
              OR DATE(j.end_time) = CURRENT_DATE 
              OR j.end_time IS NULL
          )
        ORDER BY j.driver_id, j.start_time ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rawJourneys = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. PROCESSAMENTO LÓGICO (Agrupar por Motorista)
    $drivers = [];

    foreach ($rawJourneys as $row) {
        $did = $row['driver_id'];
        
        if (!isset($drivers[$did])) {
            $drivers[$did] = [
                'id' => $did,
                'name' => $row['driver_name'],
                'cnh' => $row['cnh_number'] ?? 'N/A',
                'status' => 'descanso', // Padrão
                'current_vehicle' => '-',
                'total_driving' => 0,
                'continuous_driving' => 0,
                'violations' => [],
                'journeys_count' => 0,
                'last_end_time' => 0
            ];
        }

        $duration = floatval($row['duration']);
        $startTime = strtotime($row['start_time']);
        $endTime = $row['end_time'] ? strtotime($row['end_time']) : time(); // Se NULL, considera agora
        
        // Soma ao total do dia (mesmo que tenha começado ontem, conta para fadiga atual se contínuo)
        // Nota: Para cálculo fiscal exato precisaria quebrar a meia-noite, mas para alerta de fadiga conta o acumulado.
        $drivers[$did]['total_driving'] += $duration;
        $drivers[$did]['journeys_count']++;

        // Verifica Intervalos de Descanso (>30min zera contínuo)
        // Apenas se houver uma jornada anterior registrada na lista
        if ($drivers[$did]['last_end_time'] > 0) {
            $restTime = $startTime - $drivers[$did]['last_end_time'];
            if ($restTime >= $MIN_REST) {
                $drivers[$did]['continuous_driving'] = 0; // Zerou a fadiga contínua
            }
        }
        
        $drivers[$did]['continuous_driving'] += $duration;
        $drivers[$did]['last_end_time'] = $endTime;

        // Se esta jornada específica está aberta (is_active = 1), define status atual
        if ($row['is_active'] == 1) {
            $drivers[$did]['status'] = 'dirigindo';
            $drivers[$did]['current_vehicle'] = $row['vehicle_name'] . ' (' . ($row['plate']?:'SEM PLACA') . ')';
            
            // Tratamento de Sombra:
            // Se a "última atualização" (neste caso simulada pelo NOW) for muito distante do start_time 
            // e não tivermos update recente no banco, poderia ser sombra. 
            // Mas aqui assumimos que se está NULL no banco, o motorista ainda está na viagem.
        }
    }

    // 4. APLICA REGRAS DE INFRAÇÃO (LEI 13.103)
    foreach ($drivers as &$d) {
        if ($d['total_driving'] > $MAX_DAILY) $d['violations'][] = 'Excedeu 10h totais';
        if ($d['continuous_driving'] > $MAX_CONTINUOUS) $d['violations'][] = 'Excedeu 5h30m sem descanso';
        
        // Define Saúde do Motorista
        if (empty($d['violations'])) {
            $d['health'] = 'ok';
        } elseif (count($d['violations']) == 1 && $d['total_driving'] < $MAX_DAILY) {
            // Se excedeu contínuo mas não o diário -> Atenção
            $d['health'] = 'warning';
        } else {
            // Excedeu diário ou múltiplos -> Crítico
            $d['health'] = 'critical';
        }
    }

    echo json_encode(array_values($drivers));

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>