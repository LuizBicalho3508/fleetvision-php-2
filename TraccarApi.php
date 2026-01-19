<?php
class TraccarApi {
    // Configurações do Traccar
    private $host = 'http://127.0.0.1:8082/api';
    private $user = 'admin';
    private $pass = 'admin'; 

    /**
     * Método genérico público para chamadas API
     * Necessário para o script setup_padrao.php e api_dados.php
     */
    public function curl($endpoint, $method = 'GET', $data = null) {
        $ch = curl_init($this->host . $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, "$this->user:$this->pass");
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Timeout maior para operações em lote
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Se o setup_padrao espera a string bruta JSON, retornamos ela.
        // Se der erro de conexão, retorna vazio ou erro JSON.
        if ($httpCode === 0) {
            return json_encode(['error' => 'Falha de conexão com Traccar']);
        }

        return $response;
    }

    /**
     * Wrapper para adicionar dispositivo (Compatível com frota.php)
     */
    public function addDevice($name, $uniqueId) {
        $data = [
            'name' => $name,
            'uniqueId' => $uniqueId
        ];
        
        $jsonResponse = $this->curl('/devices', 'POST', $data);
        $body = json_decode($jsonResponse, true);
        
        // Verifica sucesso básico (se tem ID)
        $code = (isset($body['id'])) ? 200 : 400;
        
        return ['code' => $code, 'body' => $body];
    }

    /**
     * Wrapper para atualizar dispositivo
     */
    public function updateDevice($id, $name, $uniqueId) {
        $data = [
            'id' => $id,
            'name' => $name,
            'uniqueId' => $uniqueId
        ];
        return $this->curl('/devices/' . $id, 'PUT', $data);
    }

    /**
     * Wrapper para deletar dispositivo
     */
    public function deleteDevice($id) {
        return $this->curl('/devices/' . $id, 'DELETE');
    }
}
?>