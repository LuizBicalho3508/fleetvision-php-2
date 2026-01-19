<?php
// config/session.php
// Gerenciador Central de Sessão

// Evita iniciar sessão duas vezes
if (session_status() === PHP_SESSION_NONE) {
    
    // Configurações do Cookie de Sessão
    // Força o cookie a valer para todo o domínio ('/')
    session_set_cookie_params([
        'lifetime' => 86400,          // 24 horas
        'path' => '/',                // IMPORTANTE: Vale para todo o site
        'domain' => '',               // Vazio = domínio atual
        'secure' => isset($_SERVER['HTTPS']), // Apenas HTTPS se disponível
        'httponly' => true,           // Protege contra roubo via JS
        'samesite' => 'Lax'           // Permite redirecionamentos seguros
    ]);

    // Define um nome único para a sessão do projeto
    session_name('FLEETVISION_SESSID');
    
    // Inicia a sessão
    session_start();
}
?>