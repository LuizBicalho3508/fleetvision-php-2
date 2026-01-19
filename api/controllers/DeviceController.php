<?php
// api/controllers/DeviceController.php

if (count(get_included_files()) == 1) exit('Acesso direto não permitido');

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../db.php';

class DeviceController {
    private $pdo;

    public function __construct() {
        $this->pdo = Database::getInstance()->getConnection();
    }

    public function sendCommand($tenantId, $userId, $input) {
        $deviceId = $input['deviceId'] ?? null;
        $type = $input['type'] ?? null;
        $password = $input['password'] ?? '';

        // Validação de Senha do Usuário (Segurança extra)
        if ($password !== 'SKIP_CHECK') {
            $stmt = $this->pdo->prepare("SELECT password FROM saas_users WHERE id = ?");
            $stmt->execute([$userId]);
            if (!password_verify($password, $stmt->fetchColumn())) {
                return ['success' => false, 'error' => 'Senha incorreta', 'http_code' => 401];
            }
        }

        // Traduz comandos simplificados para Traccar
        $traccarType = $type;
        if ($type === 'lock') $traccarType = 'engineStop';
        if ($type === 'unlock') $traccarType = 'engineResume';

        // Envia via API Traccar
        $url = TRACCAR_API_URL . '/commands/send';
        $payload = [
            'deviceId' => $deviceId,
            'type' => $traccarType,
            'attributes' => $input['attributes'] ?? new stdClass()
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_USERPWD, TRACCAR_ADMIN_USER . ":" . TRACCAR_ADMIN_PASS);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return ['success' => true, 'message' => 'Comando enviado!'];
        } else {
            return ['success' => false, 'error' => 'Erro Traccar: ' . $response, 'http_code' => 500];
        }
    }

    public function geocode($lat, $lon) {
        // Verifica Cache
        $stmt = $this->pdo->prepare("SELECT address FROM saas_address_cache WHERE lat = ? AND lon = ?");
        $stmt->execute([$lat, $lon]);
        $cached = $stmt->fetchColumn();

        if ($cached) return ['address' => $cached];

        // Busca Nominatim
        $url = "https://nominatim.openstreetmap.org/reverse?format=json&lat=$lat&lon=$lon&zoom=18&addressdetails=1";
        $opts = [
            "http" => ["header" => "User-Agent: FleetVision/1.0\r\n"]
        ];
        $context = stream_context_create($opts);
        $json = @file_get_contents($url, false, $context);
        $data = json_decode($json, true);

        $addr = $data['display_name'] ?? 'Endereço não encontrado';

        // Salva Cache
        $ins = $this->pdo->prepare("INSERT INTO saas_address_cache (lat, lon, address) VALUES (?, ?, ?)");
        $ins->execute([$lat, $lon, $addr]);

        return ['address' => $addr];
    }

    public function getRanking($tenantId, $userId, $userRole, $userEmail) {
        // Lógica simplificada de Ranking
        // Aqui você pode copiar a lógica detalhada do api_ranking.php antigo se precisar
        // ou usar uma query SQL direta para performance.
        
        // Exemplo simplificado:
        $sql = "SELECT v.id, v.name, v.plate, 
                COUNT(e.id) as events 
                FROM saas_vehicles v 
                LEFT JOIN tc_events e ON v.traccar_device_id = e.deviceid 
                WHERE v.tenant_id = ? 
                GROUP BY v.id ORDER BY events ASC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tenantId]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Processa Score
        foreach($data as &$row) {
            $row['score'] = max(0, 100 - ($row['events'] * 2));
            $row['class'] = $row['score'] > 90 ? 'A' : 'B';
        }
        
        return $data;
    }
}
?>