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

    // ── Protección contra fuerza bruta — Rate limiting por IP en DB ─────────
    // Ventaja sobre SESSION: persiste entre sesiones, ventanas privadas y
    // distintos navegadores. Un atacante no puede evadir el bloqueo borrando
    // cookies o abriendo una ventana de incógnito.
    //
    // Máximo 5 intentos fallidos por IP en una ventana de 10 minutos.
    $ip          = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $ventana_seg = 10 * 60; // 10 minutos
    $max_intentos = 5;

    try {
        // Limpiar registros viejos (fuera de la ventana de 10 min) para esta IP
        $pdo->prepare("
            DELETE FROM login_intentos
            WHERE ip = ? AND endpoint = 'admin'
              AND primer_fallo < NOW() - INTERVAL ? SECOND
        ")->execute([$ip, $ventana_seg]);

        // Leer intentos actuales para esta IP
        $stmt = $pdo->prepare("
            SELECT intentos, primer_fallo
            FROM login_intentos
            WHERE ip = ? AND endpoint = 'admin'
            LIMIT 1
        ");
        $stmt->execute([$ip]);
        $registro = $stmt->fetch();

        $intentos_actuales = $registro ? (int)$registro['intentos'] : 0;

        if ($intentos_actuales >= $max_intentos) {
            $primer_fallo_ts = strtotime($registro['primer_fallo']);
            $restante        = $ventana_seg - (time() - $primer_fallo_ts);
            $minutos         = max(1, (int)ceil($restante / 60));

            error_log("RADIX admin_login BLOQUEADO: IP $ip — $intentos_actuales intentos fallidos.");
            sendResponse([
                'error' => "Demasiados intentos fallidos. Espera {$minutos} minuto(s) e intenta de nuevo.",
            ], 429);
        }

        // ── Verificar credenciales ───────────────────────────────────────────
        $stmt = $pdo->prepare("SELECT * FROM administradores WHERE usuario = ?");
        $stmt->execute([$user]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($pass, $admin['password_hash'])) {
            // ✅ Login exitoso — borrar historial de intentos fallidos de esta IP
            $pdo->prepare("
                DELETE FROM login_intentos WHERE ip = ? AND endpoint = 'admin'
            ")->execute([$ip]);

            // Guardar sesión admin
            $_SESSION['radix_admin_id']   = $admin['id'];
            $_SESSION['radix_admin_user'] = $admin['usuario'];
            $_SESSION['radix_admin_rol']  = $admin['rol'];

            // Actualizar última conexión
            $pdo->prepare("UPDATE administradores SET ultima_conexion = NOW() WHERE id = ?")
                ->execute([$admin['id']]);

            sendResponse([
                'success' => true,
                'message' => 'Login exitoso',
                'role'    => $admin['rol'],
                'user'    => $admin['usuario'],
            ]);

        } else {
            // ❌ Credenciales incorrectas — registrar intento fallido en DB
            if ($intentos_actuales === 0) {
                // Primera falla: insertar registro
                $pdo->prepare("
                    INSERT INTO login_intentos (ip, endpoint, intentos)
                    VALUES (?, 'admin', 1)
                ")->execute([$ip]);
            } else {
                // Falla adicional: incrementar contador
                $pdo->prepare("
                    UPDATE login_intentos
                    SET intentos = intentos + 1
                    WHERE ip = ? AND endpoint = 'admin'
                ")->execute([$ip]);
            }

            $restantes = $max_intentos - ($intentos_actuales + 1);
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
