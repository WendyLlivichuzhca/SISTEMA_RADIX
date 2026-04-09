<?php
require_once 'config.php';

header('Content-Type: text/plain');

$maintenance_key = $_GET['key'] ?? '';
define('RADIX_MAINTENANCE_KEY', $_ENV['RADIX_MAINTENANCE_KEY'] ?? (getenv('RADIX_MAINTENANCE_KEY') ?: 'radix_tools_2026'));

if ($maintenance_key !== RADIX_MAINTENANCE_KEY) {
    http_response_code(403);
    die(
        '<h2>403 - Acceso denegado.</h2><p>Usa: run_add_retiro_credito_consumido_support.php?key='
        . htmlspecialchars(RADIX_MAINTENANCE_KEY, ENT_QUOTES, 'UTF-8')
        . '</p>'
    );
}

require __DIR__ . '/migrations/add_retiro_credito_consumido_support.php';
?>
