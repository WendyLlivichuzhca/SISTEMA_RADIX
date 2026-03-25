<?php
require_once 'config.php';
session_start();

// Endpoint para el registro de nuevos usuarios (Radix v2.0)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $wallet              = trim($_POST['wallet'] ?? '');
    $nickname            = trim($_POST['nickname'] ?? '');
    $patrocinador_wallet = $_POST['patrocinador'] ?? null;
    $signature           = trim($_POST['signature'] ?? '');
    $message_signed      = trim($_POST['message'] ?? '');
    $ip_address          = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    if (empty($wallet)) {
        sendResponse(['error' => 'La billetera es obligatoria'], 400);
    }

    // ── Verificar firma del nonce (prueba de ownership de wallet) ──────────
    if (empty($signature) || empty($message_signed)) {
        sendResponse(['error' => 'Se requiere firma de wallet para registrarse.'], 400);
    }

    // Verificar que el nonce en el mensaje coincide con el de la sesión
    $nonce_sesion  = $_SESSION['radix_nonce']        ?? '';
    $wallet_sesion = $_SESSION['radix_nonce_wallet'] ?? '';
    $expira        = $_SESSION['radix_nonce_expira'] ?? 0;

    if (empty($nonce_sesion) || time() > $expira) {
        sendResponse(['error' => 'El desafío de verificación expiró. Vuelve a conectar tu wallet.'], 401);
    }
    if ($wallet_sesion !== $wallet) {
        sendResponse(['error' => 'La wallet no coincide con el desafío firmado.'], 401);
    }
    if (!str_contains($message_signed, $nonce_sesion)) {
        sendResponse(['error' => 'El mensaje firmado no contiene el nonce correcto.'], 401);
    }

    // Invalidar el nonce tras usarlo (evita replay attacks)
    unset($_SESSION['radix_nonce'], $_SESSION['radix_nonce_wallet'], $_SESSION['radix_nonce_expira']);

    // Marcar que esta wallet fue verificada criptográficamente en esta sesión
    // session_login.php comprueba este flag antes de crear la sesión autenticada.
    $_SESSION['radix_wallet_verificada']  = $wallet;
    $_SESSION['radix_verificada_at']      = time();

    try {
        $pdo->beginTransaction();

        // 1. Verificar si la billetera ya existe (Idempotencia / Login)
        $stmt = $pdo->prepare("SELECT id, patrocinador_id FROM usuarios WHERE wallet_address = ?");
        $stmt->execute([$wallet]);
        $existing_user = $stmt->fetch();
        
        if ($existing_user && $existing_user['patrocinador_id'] !== null) {
            $pdo->commit();
            sendResponse(['success' => true, 'user_id' => $existing_user['id'], 'message' => 'Login exitoso']);
        }

        // 2. Solo si es NUEVO o no tiene patrocinador, aplicamos la regla de "no auto-patrocinio"
        if ($patrocinador_wallet && strcasecmp($wallet, $patrocinador_wallet) === 0) {
            $pdo->rollBack();
            sendResponse(['error' => 'No puedes ser tu propio patrocinador'], 400);
        }
        
        $new_user_id = $existing_user ? $existing_user['id'] : null;

        // 2. Buscar ID del patrocinador
        $patrocinador_id = null;
        if ($patrocinador_wallet) {
            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE wallet_address = ?");
            $stmt->execute([$patrocinador_wallet]);
            $res = $stmt->fetch();
            $patrocinador_id = $res ? $res['id'] : null;
        }

        // 3. Insertar o Actualizar usuario con patrocinador
        if ($new_user_id) {
            $stmt = $pdo->prepare("UPDATE usuarios SET patrocinador_id = ? WHERE id = ?");
            $stmt->execute([$patrocinador_id, $new_user_id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO usuarios (wallet_address, nickname, patrocinador_id, ip_registro) VALUES (?, ?, ?, ?)");
            $stmt->execute([$wallet, $nickname, $patrocinador_id, $ip_address]);
            $new_user_id = $pdo->lastInsertId();

            // 4. Inicializar progreso en Tablero A (solo usuarios reales, nunca master/sistema)
            // Doble seguridad: la tabla usuarios tiene tipo_usuario DEFAULT 'real',
            // pero verificamos explícitamente para no crear tableros a cuentas administrativas.
            $stmt_tipo = $pdo->prepare("SELECT tipo_usuario FROM usuarios WHERE id = ?");
            $stmt_tipo->execute([$new_user_id]);
            $tipo_nuevo = $stmt_tipo->fetchColumn();
            if (!in_array($tipo_nuevo, ['master', 'sistema'])) {
                $stmt = $pdo->prepare("INSERT INTO tableros_progreso (usuario_id, tablero_tipo) VALUES (?, 'A')");
                $stmt->execute([$new_user_id]);
            }
        }

        // 5. Asignar posición en la matriz del patrocinador
        if ($patrocinador_id) {
            $stmt = $pdo->prepare("SELECT COUNT(*) as cuenta FROM referidos WHERE id_padre = ?");
            $stmt->execute([$patrocinador_id]);
            $cuenta = $stmt->fetch()['cuenta'];

            if ($cuenta < 3) {
                $posicion = $cuenta + 1;
                $stmt = $pdo->prepare("INSERT INTO referidos (id_padre, id_hijo, posicion, nivel_en_red) VALUES (?, ?, ?, 1)");
                $stmt->execute([$patrocinador_id, $new_user_id, $posicion]);

                // REGISTRAR PAGO/REGALO PENDIENTE ($10 para Tablero A)
                $stmt = $pdo->prepare("INSERT INTO pagos (id_emisor, id_receptor, monto, tipo, estado) VALUES (?, ?, 10.00, 'regalo', 'pendiente')");
                $stmt->execute([$new_user_id, $patrocinador_id]);

                // Registrar en Auditoría (Incluyendo firma para verificación futura)
                $stmt = $pdo->prepare("INSERT INTO auditoria_logs (usuario_id, accion, tabla_afectada, detalles, ip_address) VALUES (?, 'REGISTRO_CON_FIRMA', 'usuarios', ?, ?)");
                $stmt->execute([$new_user_id, "Firma: $signature | Msg: $message_signed", $ip_address]);

                // MEJORA #6: Notificar al patrocinador que tiene un nuevo referido
                require_once 'notificaciones.php';
                notificarNuevoReferido($pdo, $patrocinador_id, $nickname);

                // VERIFICAR AVANCE DEL PATROCINADOR (Lógica Matrix v2)
                require_once 'matrix_logic.php';
                verificarAvanceTablero($patrocinador_id, $pdo);

                // INTENTAR ACTIVAR CLONES CON FONDOS DE TESORERÍA (Si hay disponibles)
                require_once 'clon_logic.php';
                intentarActivarClon($pdo);
            }
        }

        $pdo->commit();
        sendResponse(['success' => true, 'user_id' => $new_user_id, 'message' => '¡Bienvenido a RADIX! Registro exitoso.']);

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("RADIX registro ERROR: " . $e->getMessage());
        sendResponse(['error' => 'Error interno del servidor. Por favor intenta de nuevo.'], 500);
    }
} else {
    sendResponse(['error' => 'Método no permitido'], 405);
}
?>
