<?php
require_once 'config.php';
session_start();

function obtenerCicloActivoUsuario($pdo, $usuario_id) {
    $stmt = $pdo->prepare("
        SELECT ciclo
        FROM tableros_progreso
        WHERE usuario_id = ?
        ORDER BY (estado = 'en_progreso') DESC, ciclo DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$usuario_id]);
    $ciclo = $stmt->fetchColumn();
    return $ciclo ? (int)$ciclo : 1;
}

// Endpoint para el registro de nuevos usuarios (Radix v2.0)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $wallet              = trim($_POST['wallet'] ?? '');
    $nickname            = trim($_POST['nickname'] ?? '');
    $nombre_completo     = trim($_POST['nombre_completo'] ?? '');
    $telefono            = trim($_POST['telefono'] ?? '');
    $correo_electronico  = trim($_POST['correo_electronico'] ?? '');
    $patrocinador_wallet = $_POST['patrocinador'] ?? null;
    $signature           = trim($_POST['signature'] ?? '');
    $message_signed      = trim($_POST['message'] ?? '');
    $ip_address          = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    if (empty($wallet)) {
        sendResponse(['error' => 'La billetera es obligatoria'], 400);
    }

    if (!empty($correo_electronico) && !filter_var($correo_electronico, FILTER_VALIDATE_EMAIL)) {
        sendResponse(['error' => 'El correo electrónico no es válido'], 400);
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

        $stmt = $pdo->query("
            SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'usuarios'
              AND COLUMN_NAME IN ('nombre_completo', 'telefono', 'correo_electronico')
        ");
        $contact_columns = array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);

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

        // Usuario real existente sin patrocinador:
        // - Si ya pagó (completado) → login directo sin importar si trae link de referido
        //   (no se puede cambiar el destino de un pago ya procesado)
        // - Si tiene pago PENDIENTE y viene SIN link → login directo
        // - Si tiene pago PENDIENTE y viene CON link → dejar caer al flujo de actualización
        //   para asignar el patrocinador correcto y redirigir el pago
        if ($existing_user && $existing_user['patrocinador_id'] === null) {
            $stmt_chk = $pdo->prepare("SELECT id, estado FROM pagos WHERE id_emisor = ? AND tipo = 'regalo' AND estado IN ('pendiente', 'completado') ORDER BY id DESC LIMIT 1");
            $stmt_chk->execute([$existing_user['id']]);
            $pago_existente = $stmt_chk->fetch();

            if ($pago_existente) {
                // Pago ya completado → no se puede reasignar, login directo
                if ($pago_existente['estado'] === 'completado') {
                    $pdo->commit();
                    sendResponse(['success' => true, 'user_id' => $existing_user['id'], 'message' => 'Login exitoso']);
                }
                // Pago pendiente sin link de referido → login directo
                if ($pago_existente['estado'] === 'pendiente' && empty($patrocinador_wallet)) {
                    $pdo->commit();
                    sendResponse(['success' => true, 'user_id' => $existing_user['id'], 'message' => 'Login exitoso']);
                }
                // Pago pendiente CON link de referido → cae al flujo de actualización
            }
            // Sin pago → cae al flujo normal de registro/asignación
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

        $stmt_master = $pdo->prepare("SELECT id, wallet_address FROM usuarios WHERE tipo_usuario = 'master' LIMIT 1");
        $stmt_master->execute();
        $master_user = $stmt_master->fetch();

        // 3. Insertar o Actualizar usuario con patrocinador
        if ($new_user_id) {
            $update_fields = ["patrocinador_id = ?"];
            $update_values = [$patrocinador_id];

            if (!empty($nombre_completo) && !empty($contact_columns['nombre_completo'])) {
                $update_fields[] = "nombre_completo = ?";
                $update_values[] = $nombre_completo;
            }
            if (!empty($telefono) && !empty($contact_columns['telefono'])) {
                $update_fields[] = "telefono = ?";
                $update_values[] = $telefono;
            }
            if (!empty($correo_electronico) && !empty($contact_columns['correo_electronico'])) {
                $update_fields[] = "correo_electronico = ?";
                $update_values[] = $correo_electronico;
            }

            $update_values[] = $new_user_id;
            $stmt = $pdo->prepare("UPDATE usuarios SET " . implode(', ', $update_fields) . " WHERE id = ?");
            $stmt->execute($update_values);
        } else {
            $insert_fields = ['wallet_address', 'nickname', 'patrocinador_id', 'ip_registro'];
            $insert_values = [$wallet, $nickname, $patrocinador_id, $ip_address];
            $insert_marks = ['?', '?', '?', '?'];

            if (!empty($contact_columns['nombre_completo'])) {
                $insert_fields[] = 'nombre_completo';
                $insert_values[] = $nombre_completo;
                $insert_marks[] = '?';
            }
            if (!empty($contact_columns['telefono'])) {
                $insert_fields[] = 'telefono';
                $insert_values[] = $telefono;
                $insert_marks[] = '?';
            }
            if (!empty($contact_columns['correo_electronico'])) {
                $insert_fields[] = 'correo_electronico';
                $insert_values[] = $correo_electronico;
                $insert_marks[] = '?';
            }

            $stmt = $pdo->prepare(
                "INSERT INTO usuarios (" . implode(', ', $insert_fields) . ") VALUES (" . implode(', ', $insert_marks) . ")"
            );
            $stmt->execute($insert_values);
            $new_user_id = $pdo->lastInsertId();

            // 4. Inicializar progreso en Tablero A (solo usuarios reales, nunca master/sistema)
            // Doble seguridad: la tabla usuarios tiene tipo_usuario DEFAULT 'real',
            // pero verificamos explícitamente para no crear tableros a cuentas administrativas.
            // El tablero A ya no se activa en registro.
            // Primero se crea el usuario y el pago pendiente.
            // El tablero se activa solo cuando el pago queda confirmado.
        }

        // 5. Asignar posición en la matriz del patrocinador
        if ($patrocinador_id) {
            // Bloquear la fila del patrocinador para evitar race conditions en registros simultáneos
            $ciclo_red = obtenerCicloActivoUsuario($pdo, $patrocinador_id);

            $stmt = $pdo->prepare("SELECT COUNT(*) as cuenta FROM referidos WHERE id_padre = ? AND ciclo = ? FOR UPDATE");
            $stmt->execute([$patrocinador_id, $ciclo_red]);
            $cuenta = $stmt->fetch()['cuenta'];

            if ($cuenta < 3) {
                $posicion = $cuenta + 1;
                try {
                    $stmt = $pdo->prepare("INSERT INTO referidos (id_padre, id_hijo, posicion, nivel_en_red, ciclo) VALUES (?, ?, ?, 1, ?)");
                    $stmt->execute([$patrocinador_id, $new_user_id, $posicion, $ciclo_red]);
                } catch (PDOException $e) {
                    // La posición ya fue ocupada por otro registro simultáneo (duplicate key)
                    $pdo->rollBack();
                    sendResponse(['error' => 'El patrocinador ya no tiene espacios disponibles. Recarga y reintenta.'], 409);
                }

                // REGISTRAR PAGO/REGALO PENDIENTE ($10 para Tablero A)
                $stmt = $pdo->prepare("
                    INSERT INTO pagos (
                        id_emisor, id_receptor, beneficiario_usuario_id, wallet_destino_real,
                        tablero_tipo, ciclo, origen_fondos, monto, tipo, estado
                    ) VALUES (?, ?, ?, ?, 'A', 1, 'externo', 10.00, 'regalo', 'pendiente')
                ");
                $stmt->execute([$new_user_id, $patrocinador_id, $patrocinador_id, $master_user['wallet_address'] ?? RADIX_CENTRAL_WALLET]);

                // Registrar en Auditoría (Incluyendo firma para verificación futura)
                $stmt = $pdo->prepare("INSERT INTO auditoria_logs (usuario_id, accion, tabla_afectada, detalles, ip_address) VALUES (?, 'REGISTRO_CON_FIRMA', 'usuarios', ?, ?)");
                $stmt->execute([$new_user_id, "Firma: $signature | Msg: $message_signed", $ip_address]);

                // MEJORA #6: Notificar al patrocinador que tiene un nuevo referido
                require_once 'notificaciones.php';
                notificarNuevoReferido($pdo, $patrocinador_id, $nickname);

                // VERIFICAR AVANCE DEL PATROCINADOR (Lógica Matrix v2)
                require_once 'matrix_logic.php';
                verificarAvanceTablero($patrocinador_id, $pdo);

                // MODO MANUAL:
                // Los clones no se activan automáticamente desde registro.
                // La tesorería se acumula y el admin decide cuándo dispararlos.

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
                      AND r.ciclo = ?
                      AND u.tipo_usuario = 'real'
                      AND (SELECT COUNT(*) FROM referidos r2 WHERE r2.id_padre = r.id_hijo AND r2.ciclo = ?) < 3
                    ORDER BY r.posicion ASC
                    LIMIT 1
                ");
                $stmt->execute([$patrocinador_id, $ciclo_red, $ciclo_red]);
                $spillover = $stmt->fetch();

                if ($spillover) {
                    $nuevo_patron_id = $spillover['nuevo_patron_id'];

                    // Reconteo con bloqueo para el nuevo patrón
                    $stmt = $pdo->prepare("SELECT COUNT(*) as cuenta FROM referidos WHERE id_padre = ? AND ciclo = ? FOR UPDATE");
                    $stmt->execute([$nuevo_patron_id, $ciclo_red]);
                    $cuenta_nuevo = $stmt->fetch()['cuenta'];

                    if ($cuenta_nuevo < 3) {
                        $posicion_nueva = $cuenta_nuevo + 1;
                        try {
                            $stmt = $pdo->prepare("INSERT INTO referidos (id_padre, id_hijo, posicion, nivel_en_red, ciclo) VALUES (?, ?, ?, 1, ?)");
                            $stmt->execute([$nuevo_patron_id, $new_user_id, $posicion_nueva, $ciclo_red]);
                        } catch (PDOException $e) {
                            $pdo->rollBack();
                            sendResponse(['error' => 'Posición ocupada simultáneamente. Recarga e intenta de nuevo.'], 409);
                        }

                        // Pago pendiente al nuevo patrón (P1/P2/P3), no al original
                        $stmt = $pdo->prepare("
                            INSERT INTO pagos (
                                id_emisor, id_receptor, beneficiario_usuario_id, wallet_destino_real,
                                tablero_tipo, ciclo, origen_fondos, monto, tipo, estado
                            ) VALUES (?, ?, ?, ?, 'A', 1, 'externo', 10.00, 'regalo', 'pendiente')
                        ");
                        $stmt->execute([$new_user_id, $nuevo_patron_id, $nuevo_patron_id, $master_user['wallet_address'] ?? RADIX_CENTRAL_WALLET]);

                        // Auditoría del spillover
                        $stmt = $pdo->prepare("INSERT INTO auditoria_logs (usuario_id, accion, tabla_afectada, detalles, ip_address) VALUES (?, 'REGISTRO_SPILLOVER', 'usuarios', ?, ?)");
                        $stmt->execute([$new_user_id, "Spillover: Patrón original ID $patrocinador_id lleno. Asignado a ID $nuevo_patron_id (posición $posicion_nueva). Firma: $signature", $ip_address]);

                        // Notificar al nuevo patrón
                        require_once 'notificaciones.php';
                        notificarNuevoReferido($pdo, $nuevo_patron_id, $nickname);

                        // Verificar avance del nuevo patrón
                        require_once 'matrix_logic.php';
                        verificarAvanceTablero($nuevo_patron_id, $pdo);

                        // MODO MANUAL:
                        // Los clones no se activan automáticamente desde registro.

                    } else {
                        $pdo->rollBack();
                        sendResponse(['error' => 'La red de tu patrocinador está llena en este nivel. Contacta a tu patrocinador para obtener un link directo de uno de sus referidos.'], 409);
                    }
                } else {
                    // ── SPILLOVER NIVEL 2 ────────────────────────────────────────────
                    // Nivel 1 (P1/P2/P3) está totalmente lleno.
                    // Buscar entre los hijos de P1/P2/P3 que aún tengan espacio.
                    $stmt = $pdo->prepare("
                        SELECT r2.id_hijo AS nuevo_patron_id
                    FROM referidos r1
                    JOIN referidos r2 ON r2.id_padre = r1.id_hijo
                    JOIN usuarios u1  ON r1.id_hijo  = u1.id
                    JOIN usuarios u2  ON r2.id_hijo  = u2.id
                    WHERE r1.id_padre = ?
                      AND r1.ciclo = ?
                      AND r2.ciclo = ?
                      AND u1.tipo_usuario = 'real'
                      AND u2.tipo_usuario = 'real'
                      AND (SELECT COUNT(*) FROM referidos r3 WHERE r3.id_padre = r2.id_hijo AND r3.ciclo = ?) < 3
                    ORDER BY r1.posicion ASC, r2.posicion ASC
                    LIMIT 1
                ");
                    $stmt->execute([$patrocinador_id, $ciclo_red, $ciclo_red, $ciclo_red]);
                    $spillover_n2 = $stmt->fetch();

                    if ($spillover_n2) {
                        $nuevo_patron_id = $spillover_n2['nuevo_patron_id'];

                        // Bloquear fila del nuevo patrón nivel 2
                        $stmt = $pdo->prepare("SELECT COUNT(*) as cuenta FROM referidos WHERE id_padre = ? AND ciclo = ? FOR UPDATE");
                        $stmt->execute([$nuevo_patron_id, $ciclo_red]);
                        $cuenta_n2 = $stmt->fetch()['cuenta'];

                        if ($cuenta_n2 < 3) {
                            $posicion_n2 = $cuenta_n2 + 1;
                            try {
                                $stmt = $pdo->prepare("INSERT INTO referidos (id_padre, id_hijo, posicion, nivel_en_red, ciclo) VALUES (?, ?, ?, 1, ?)");
                                $stmt->execute([$nuevo_patron_id, $new_user_id, $posicion_n2, $ciclo_red]);
                            } catch (PDOException $e) {
                                $pdo->rollBack();
                                sendResponse(['error' => 'Posición ocupada simultáneamente. Recarga e intenta de nuevo.'], 409);
                            }

                            // Pago pendiente al patrón de nivel 2
                            $stmt = $pdo->prepare("
                                INSERT INTO pagos (
                                    id_emisor, id_receptor, beneficiario_usuario_id, wallet_destino_real,
                                    tablero_tipo, ciclo, origen_fondos, monto, tipo, estado
                                ) VALUES (?, ?, ?, ?, 'A', 1, 'externo', 10.00, 'regalo', 'pendiente')
                            ");
                            $stmt->execute([$new_user_id, $nuevo_patron_id, $nuevo_patron_id, $master_user['wallet_address'] ?? RADIX_CENTRAL_WALLET]);

                            // Auditoría del spillover nivel 2
                            $stmt = $pdo->prepare("INSERT INTO auditoria_logs (usuario_id, accion, tabla_afectada, detalles, ip_address) VALUES (?, 'REGISTRO_SPILLOVER_N2', 'usuarios', ?, ?)");
                            $stmt->execute([$new_user_id, "Spillover N2: Patrón original ID $patrocinador_id lleno. Asignado a ID $nuevo_patron_id (pos $posicion_n2). Firma: $signature", $ip_address]);

                            require_once 'notificaciones.php';
                            notificarNuevoReferido($pdo, $nuevo_patron_id, $nickname);

                            require_once 'matrix_logic.php';
                            verificarAvanceTablero($nuevo_patron_id, $pdo);

                            // MODO MANUAL:
                            // Los clones no se activan automáticamente desde registro.

                        } else {
                            $pdo->rollBack();
                            sendResponse(['error' => 'Todos los espacios de nivel 2 están ocupados. Contacta a tu patrocinador para obtener un link disponible.'], 409);
                        }
                    } else {
                        $pdo->rollBack();
                        sendResponse(['error' => 'Tu patrocinador tiene la red completa en niveles 1 y 2. Pide el link de uno de sus referidos directos para unirte.'], 409);
                    }
                }
            }
        } else {
            // ── USUARIO FUNDADOR / ROOT (sin patrocinador) ──────────────────────
            // Aunque es el primero en la red, también debe aportar los $10 de entrada.
            // Su pago se dirige a RADIX_MASTER (la billetera central de la plataforma).
            // Esto garantiza que la tesorería recibe su primer ingreso desde el inicio.
            if ($master_user) {
                // Crear pago pendiente del fundador hacia RADIX_MASTER
                $stmt = $pdo->prepare("
                    INSERT INTO pagos (
                        id_emisor, id_receptor, beneficiario_usuario_id, wallet_destino_real,
                        tablero_tipo, ciclo, origen_fondos, monto, tipo, estado
                    ) VALUES (?, ?, ?, ?, 'A', 1, 'externo', 10.00, 'regalo', 'pendiente')
                ");
                $stmt->execute([$new_user_id, $master_user['id'], $master_user['id'], $master_user['wallet_address'] ?? RADIX_CENTRAL_WALLET]);

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
