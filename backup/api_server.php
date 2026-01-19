<?php
session_start();
// Configurações de segurança
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json');

// 1. Proteção de Acesso
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'superadmin') {
    http_response_code(403);
    echo json_encode(['error' => 'Acesso negado']);
    exit;
}

try {
    // 2. Coleta de Dados
    
    // Uptime
    $uptimeStr = "N/A";
    $uptime = @file_get_contents('/proc/uptime');
    if ($uptime) {
        $sec = (int)explode(" ", $uptime)[0];
        $days = floor($sec / 86400);
        $hours = floor(($sec % 86400) / 3600);
        $mins = floor(($sec % 3600) / 60);
        $uptimeStr = "{$days}d {$hours}h {$mins}m";
    }

    // CPU Load (Real-time snapshot)
    $cpuPercent = 0;
    if (is_readable('/proc/stat')) {
        $stat1 = file_get_contents('/proc/stat');
        usleep(100000); // 100ms de intervalo
        $stat2 = file_get_contents('/proc/stat');
        
        $info1 = explode(" ", preg_replace("!cpu +!", "", explode("\n", $stat1)[0]));
        $info2 = explode(" ", preg_replace("!cpu +!", "", explode("\n", $stat2)[0]));
        
        $dif = []; $total = 0;
        foreach($info1 as $k=>$v) {
            $dif[$k] = $info2[$k] - $v;
            $total += $dif[$k];
        }
        // Idle é geralmente o índice 3
        $cpuPercent = ($total > 0) ? round(100 * ($total - $dif[3]) / $total, 1) : 0;
    } else {
        // Fallback load avg
        $load = sys_getloadavg();
        $cpuPercent = $load[0] * 10; // Estimativa
    }

    // RAM
    $memTotal = 0; $memAvail = 0;
    if ($fh = @fopen('/proc/meminfo', 'r')) {
        while ($line = fgets($fh)) {
            if (preg_match('/^MemTotal:\s+(\d+)\skB$/', $line, $p)) $memTotal = $p[1];
            if (preg_match('/^MemAvailable:\s+(\d+)\skB$/', $line, $p)) $memAvail = $p[1];
        }
        fclose($fh);
    }
    $memUsed = $memTotal - $memAvail;
    $memPercent = ($memTotal > 0) ? round(($memUsed / $memTotal) * 100, 1) : 0;

    // Disco
    $ds = disk_total_space("/");
    $df = disk_free_space("/");
    $du = $ds - $df;
    $diskPercent = round(($du / $ds) * 100, 1);

    // Serviços (Check Portas)
    function checkPort($host, $port) {
        $c = @fsockopen($host, $port, $en, $es, 0.5); // Timeout rápido
        if (is_resource($c)) { fclose($c); return true; }
        return false;
    }

    $services = [
        'traccar' => checkPort('127.0.0.1', 8082),
        'postgres' => checkPort('127.0.0.1', 5432)
    ];

    // Retorno JSON Limpo
    echo json_encode([
        'uptime' => $uptimeStr,
        'cpu' => $cpuPercent,
        'ram_used' => round($memUsed / 1024), // MB
        'ram_total' => round($memTotal / 1024), // MB
        'ram_pct' => $memPercent,
        'disk_used' => round($du / 1024 / 1024 / 1024, 1), // GB
        'disk_total' => round($ds / 1024 / 1024 / 1024, 1), // GB
        'disk_pct' => $diskPercent,
        'services' => $services
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
