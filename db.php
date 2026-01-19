<?php
/**
 * db.php - Conexão de Banco de Dados (Singleton)
 * Atualizado para trabalhar com a nova estrutura.
 */

// Se as constantes não estiverem definidas, carrega do config
if (!defined('DB_HOST')) {
    $configPath = __DIR__ . '/config/app.php';
    if (file_exists($configPath)) {
        require_once $configPath;
    } else {
        // Fallback de segurança se o config não existir
        die(json_encode(['error' => 'Configuração (config/app.php) não encontrada.']));
    }
}

class Database {
    private static $instance = null;
    private $pdo;

    private function __construct() {
        try {
            $dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME;
            
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_PERSISTENT         => true,
                PDO::ATTR_TIMEOUT            => 5
            ];

            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

        } catch (PDOException $e) {
            error_log("Erro Conexão DB: " . $e->getMessage());
            http_response_code(500);
            die(json_encode(['error' => 'Falha na conexão com o banco de dados.']));
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->pdo;
    }
}

// --- COMPATIBILIDADE PARA CÓDIGOS ANTIGOS ---
// Cria a variável $pdo globalmente para que arquivos não refatorados continuem funcionando
try {
    $pdo = Database::getInstance()->getConnection();
} catch (Exception $e) {
    // Silencia erro na inicialização global para não vazar info
}
?>