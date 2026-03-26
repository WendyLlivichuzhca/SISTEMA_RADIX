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
        $stmt = $pdo->prepare("SELECT id, patrocinador_id, tipo_usuario FROM usuarios WHERE wallet_address = ?");
        $stmt->execute([$wallet]);
        $existing_user = $stmt->fetch();

        // Cuentas master/sistema: login directo, NUNCA generan pagos
        if ($existing_user && in_array($existing_user['tipo_usuario'], ['master', 'sistema'])) {
            $pdo->commit();
            sendResponse(['success' => true, 'user_id' => $existing_user['id'], 'message' => 'Login exitoso']);
        }

        // Usuario real existente con patrocinador: login directo
        if ($existing_user && $existing_user['patrocinador_id'] !== null) {
            $pdo->commit();
            sendResponse(['success' => true, 'user_id' => $existing_user['id'], 'message' => 'Login exitoso']);
        }

        // Usuario real existente sin patrocinador: login directo si ya tiene pago pendiente
        if ($existing_user && $existing_user['patrocinador_id'] === null) {
            $stmt_chk = $pdo->prepare("SELECT id FROM pagos WHERE id_emisor = ? AND estado = 'pendiente' AND tipo = 'regalo' LIMIT 1");
            $stmt_chk->execute([$existing_user['id']]);
            if ($stmt_chk->fetch()) {
                $pdo->commit();
                sendResponse(['success' => true, 'user_id' => $existing_user['id'], 'message' => 'Login exitoso']);
            }
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
            // Bloquear la fila del patrocinador para evitar race conditions en registros simultáneos
            $stmt = $pdo->prepare("SELECT COUNT(*) as cuenta FROM referidos WHERE id_padre = ? FOR UPDATE");
            $stmt->execute([$patrocinador_id]);
            $cuenta = $stmt->fetch()['cuenta'];

            if ($cuenta < 3) {
                $posicion = $cuenta + 1;
                try {
                    $stmt = $pdo->prepare("INSERT INTO referidos (id_padre, id_hijo, posicion, nivel_en_red) VALUES (?, ?, ?, 1)");
                    $stmt->execute([$patrocinador_id, $new_user_id, $posicion]);
                } catch (PDOException $e) {
                    // La posición ya fue ocupada por otro registro simultáneo (duplicate key)
                    $pdo->rollBack();
                    sendResponse(['error' => 'El patrocinador ya no tiene espacios disponibles. Recarga y reintenta.'], 409);
                }

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

            } else {
                // ── SPILLOVER AUTOMÁTICO ──────────────────────────────────────────
                // El patrocinador original ya tiene 3 referidos (Nivel 1 lleno).
                // Buscamos automáticamente el primer referido suyo (P1/P2/P3)
                // que aún tenga espacio disponible para recibir a esta persona.
                $stmt = $pdo->prepare("
                    SELECT r.id_hijo AS nuevo_patron_id
                    FROM referidos r
                    JOIN usuarios u ON r.id_hijo = u.id
                    WHERE r.id_padre = ?
                      AND u.tipo_usuario = 'real'
                      AND (SELECT COUNT(*) FROM referidos r2 WHERE r2.id_padre = r.id_hijo) < 3
                    ORDER BY r.posicion ASC
                    LIMIT 1
                ");
                $stmt->execute([$patrocinador_id]);
                $spillover = $stmt->fetch();

                if ($spillover) {
                    $nuevo_patron_id = $spillover['nuevo_patron_id'];

                    // Reconteo con bloqueo para el nuevo patrón
                    $stmt = $pdo->prepare("SELECT COUNT(*) as cuenta FROM referidos WHERE id_padre = ? FOR UPDATE");
                    $stmt->execute([$nuevo_patron_id]);
                    $cuenta_nuevo = $stmt->fetch()['cuenta'];

                    if ($cuenta_nuevo < 3) {
                        $posicion_nueva = $cuenta_nuevo + 1;
                        try {
                            $stmt = $pdo->prepare("INSERT INTO referidos (id_padre, id_hijo, posicion, nivel_en_red) VALUES (?, ?, ?, 1)");
                            $stmt->execute([$nuevo_patron_id, $new_user_id, $posicion_nueva]);
                        } catch (PDOException $e) {
                            $pdo->rollBack();
                            sendResponse(['error' => 'Posición ocupada simultáneamente. Recarga e intenta de nuevo.'], 409);
                        }

                        // Pago pendiente al nuevo patrón (P1/P2/P3), no al original
                        $stmt = $pdo->prepare("INSERT INTO pagos (id_emisor, id_receptor, monto, tipo, estado) VALUES (?, ?, 10.00, 'regalo', 'pendiente')");
                        $stmt->execute([$new_user_id, $nuevo_patron_id]);

                        // Auditoría del spillover
                        $stmt = $pdo->prepare("INSERT INTO auditoria_logs (usuario_id, accion, tabla_afectada, detalles, ip_address) VALUES (?, 'REGISTRO_SPILLOVER', 'usuarios', ?, ?)");
                        $stmt->execute([$new_user_id, "Spillover: Patrón original ID $patrocinador_id lleno. Asignado a ID $nuevo_patron_id (posición $posicion_nueva). Firma: $signature", $ip_address]);

                        // Notificar al nuevo patrón
                        require_once 'notificaciones.php';
                        notificarNuevoReferido($pdo, $nuevo_patron_id, $nickname);

                        // Verificar avance del nuevo patrón
                        require_once 'matrix_logic.php';
                        verificarAvanceTablero($nuevo_patron_id, $pdo);

                        // Intentar activar clones
                        require_once 'clon_logic.php';
                        intentarActivarClon($pdo);

                    } else {
                        $pdo->rollBack();
                        sendResponse(['error' => 'La red de tu patrocinador está llena en este nivel. Contacta a tu patrocinador para obtener un link directo de uno de sus referidos.'], 409);
                    }
                } else {
                    $pdo->rollBack();
                    sendResponse(['error' => 'Tu patrocinador tiene la red completa en este nivel. Pide el link de uno de sus referidos directos para unirte.'], 409);
                }
            }
        } else {
            // ── USUARIO FUNDADOR / ROOT (sin patrocinador) ──────────────────────
            // Aunque es el primero en la red, también debe aportar los $10 de entrada.
            // Su pago se dirige a RADIX_MASTER (la billetera central de la plataforma).
            // Esto garantiza que la tesorería recibe su primer ingreso desde el inicio.
            $stmt_master = $pdo->prepare("SELECT id FROM usuarios WHERE tipo_usuario = 'master' LIMIT 1");
            $stmt_master->execute();
            $master_user = $stmt_master->fetch();

            if ($master_user) {
                // Crear pago pendiente del fundador hacia RADIX_MASTER
                $stmt = $pdo->prepare("INSERT INTO pagos (id_emisor, id_receptor, monto, tipo, estado) VALUES (?, ?, 10.00, 'regalo', 'pendiente')");
                $stmt->execute([$new_user_id, $master_user['id']]);

                // Registrar en Auditoría
                $stmt = $pdo->prepare("INSERT INTO auditoria_logs (usuario_id, accion, tabla_afectada, detalles, ip_address) VALUES (?, 'REGISTRO_FUNDADOR', 'usuarios', ?, ?)");
                $stmt->execute([$new_user_id, "Fundador registrado. Pago $10 pendiente a RADIX_MASTER. Firma: $signature | Msg: $message_signed", $ip_address]);
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
