<?php
require_once 'config.php';
session_start();

// Endpoint para el login de administradores
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim($_POST['user'] ?? '');
    $pass = trim($_POST['pass'] ?? '');

    if (empty($user) || empty($pass)) {
        sendResponse(['error' => 'Usuario y contraseña requeridos'], 400);
    }

    // ── Protección contra fuerza bruta (rate limiting por IP) ──────────────
    // Máximo 5 intentos fallidos por IP en una ventana de 10 minutos.
    $ip           = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $clave_ip     = 'admin_fail_' . md5($ip);
    $clave_tiempo = 'admin_fail_time_' . md5($ip);

    $intentos    = (int)($_SESSION[$clave_ip]     ?? 0);
    $primer_fail = (int)($_SESSION[$clave_tiempo] ?? 0);
    $ventana     = 10 * 60; // 10 minutos en segundos
    $max_intentos = 5;

    // Si la ventana ya expiró, reiniciar contadores
    if ($primer_fail > 0 && (time() - $primer_fail) > $ventana) {
        $intentos = 0;
        unset($_SESSION[$clave_ip], $_SESSION[$clave_tiempo]);
    }

    if ($intentos >= $max_intentos) {
        $restante = $ventana - (time() - $primer_fail);
        $minutos  = ceil($restante / 60);
        error_log("RADIX admin_login BLOQUEADO: IP $ip — $intentos intentos fallidos.");
        sendResponse(['error' => "Demasiados intentos fallidos. Espera {$minutos} minuto(s) e intenta de nuevo."], 429);
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM administradores WHERE usuario = ?");
        $stmt->execute([$user]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($pass, $admin['password_hash'])) {
            // Login exitoso — limpiar contadores de intentos fallidos
            unset($_SESSION[$clave_ip], $_SESSION[$clave_tiempo]);

            // Guardar sesión admin (nunca exponer password_hash al cliente)
            $_SESSION['radix_admin_id']   = $admin['id'];
            $_SESSION['radix_admin_user'] = $admin['usuario'];
            $_SESSION['radix_admin_rol']  = $admin['rol'];

            // Actualizar última conexión
            $stmt = $pdo->prepare("UPDATE administradores SET ultima_conexion = NOW() WHERE id = ?");
            $stmt->execute([$admin['id']]);

            sendResponse([
                'success' => true,
                'message' => 'Login exitoso',
                'role'    => $admin['rol'],
                'user'    => $admin['usuario']
            ]);
        } else {
            // Credenciales incorrectas — registrar intento fallido
            if ($intentos === 0) {
                $_SESSION[$clave_tiempo] = time(); // Marcar inicio de la ventana
            }
            $_SESSION[$clave_ip] = $intentos + 1;
            $restantes = $max_intentos - ($intentos + 1);
            $msg = $restantes > 0
                ? "Credenciales inválidas. Te quedan $restantes intento(s)."
                : "Credenciales inválidas. Has agotado los intentos. Espera 10 minutos.";
            sendResponse(['error' => $msg], 401);
        }

    } catch (PDOException $e) {
        error_log("RADIX admin_login ERROR: " . $e->getMessage());
        sendResponse(['error' => 'Error en el servidor. Intenta de nuevo.'], 500);
    }
}
?>
