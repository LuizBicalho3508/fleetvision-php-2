<?php
/**
 * db.php - Conexão de Base de Dados Centralizada
 * Implementa padrão Singleton e carrega configurações globais.
 */

// 1. Carrega as configurações (se ainda não estiverem carregadas)
// Utiliza __DIR__ para garantir que encontra o ficheiro na pasta config
if (!defined('DB_HOST')) {
    $configPath = __DIR__ . '/config/app.php';
    if (file_exists($configPath)) {
        require_once $configPath;
    } else {
        // Fallback de segurança caso o config/app.php não exista
        http_response_code(500);
        die(json_encode(['error' => 'Configuração de sistema não encontrada (config/app.php).']));
    }
}

class Database {
    private static $instance = null;
    private $pdo;

    private function __construct() {
        try {
            // Monta a string de conexão (DSN) para PostgreSQL
            $dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME;

            // Opções de otimização e tratamento de erros
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Lança exceções em caso de erro
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,     // Retorna arrays associativos
                PDO::ATTR_EMULATE_PREPARES   => false,                // Usa prepared statements reais
                PDO::ATTR_PERSISTENT         => true,                 // Mantém a conexão viva (performance)
                PDO::ATTR_TIMEOUT            => 5                     // Timeout de conexão
            ];

            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

        } catch (PDOException $e) {
            // Regista o erro no log do servidor (não mostra a password ao utilizador)
            error_log("Erro Crítico de DB: " . $e->getMessage());
            
            // Retorna um JSON de erro genérico
            http_response_code(500);
            die(json_encode(['error' => 'Falha na conexão com a base de dados.']));
        }
    }

    /**
     * Retorna a instância única da classe (Singleton)
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Retorna o objeto PDO ativo
     */
    public function getConnection() {
        return $this->pdo;
    }
}

// --- COMPATIBILIDADE LEGADA ---
// Instancia a variável global $pdo automaticamente.
// Isto é CRÍTICO para que os seus ficheiros antigos (admin_*.php, dashboards, etc.)
// continuem a funcionar sem precisar de refatoração imediata.
try {
    $pdo = Database::getInstance()->getConnection();
} catch (Exception $e) {
    die("Erro ao inicializar conexão global.");
}
?>