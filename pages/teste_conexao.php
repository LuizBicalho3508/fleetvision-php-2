<?php
$host = 'http://127.0.0.1:8082/api/server';
$user = 'admin';
$pass = 'admin';

$ch = curl_init($host);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERPWD, "$user:$pass");
curl_setopt($ch, CURLOPT_TIMEOUT, 5);

echo "\n>>> TENTANDO CONECTAR EM $host ...\n";
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($http_code == 200) {
    echo "✅ SUCESSO! Traccar respondeu (Conexão OK, Senha OK).\n";
    echo "Resposta: " . substr($response, 0, 100) . "...\n";
} elseif ($http_code == 401) {
    echo "❌ ERRO DE SENHA: O Traccar recusou o usuário/senha ($user/$pass).\n";
} elseif ($http_code == 0) {
    echo "❌ ERRO DE REDE: O PHP não achou o Traccar. Erro: $error\n";
} else {
    echo "⚠️ RESPOSTA ESTRANHA ($http_code): $response\n";
}
echo "\n";
?>
