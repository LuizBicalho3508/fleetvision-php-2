<?php
/**
 * API Entry Point
 * Este arquivo resolve o problema de roteamento em subpastas.
 * Ele recebe as requisições e as passa para o router real.
 */
define('IS_API', true);
require_once __DIR__ . '/api/router.php';
?>