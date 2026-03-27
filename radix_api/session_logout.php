<?php
/**
 * session_logout.php - Cierra la sesión RADIX del usuario y redirige al inicio.
 */
require_once 'config.php'; // Necesario para sendResponse() en respuestas AJAX
session_start();
session_unset();
session_destroy();

// Si es llamada AJAX devolver JSON, si es link directo redirigir
$es_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) ||
           str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

if ($es_ajax) {
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
} else {
    header('Location: ../index.html');
}
exit;
