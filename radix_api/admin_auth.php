<?php
/**
 * admin_auth.php — Verificación de sesión administrativa.
 * Incluir con require_once en TODOS los endpoints y páginas de admin.
 */
if (session_status() === PHP_SESSION_NONE) session_start();

function requireAdminSession(): void {
    // 1. Verificar si hay sesión administrativa tradicional
    $admin_ok = !empty($_SESSION['radix_admin_id']) && !empty($_SESSION['radix_admin_rol']);
    
    // 2. Verificar si es el usuario MASTER desde el dashboard unificado
    $master_ok = !empty($_SESSION['tipo_usuario']) && $_SESSION['tipo_usuario'] === 'master';

    if (!$admin_ok && !$master_ok) {
        // Si es petición AJAX → devolver JSON
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) ||
            str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
            header('Content-Type: application/json');
            http_response_code(401);
            echo json_encode(['error' => 'Sesión admin no autorizada.']);
        } else {
            // Si es página HTML → redirigir al login (detectando subcarpeta)
            $ruta_base = rtrim(dirname($_SERVER['SCRIPT_NAME'], 2), '/\\');
            header("Location: {$ruta_base}/admin_login.php");
        }
        exit;
    }
}

function requireSuperAdmin(): void {
    requireAdminSession();
    if (($_SESSION['radix_admin_rol'] ?? '') !== 'superadmin') {
        header('HTTP/1.1 403 Forbidden');
        echo json_encode(['error' => 'Acceso denegado. Se requiere rol superadmin.']);
        exit;
    }
}
?>