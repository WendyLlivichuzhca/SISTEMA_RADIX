<?php
/**
 * admin_reemplazar_con_clon.php — RADIX Multi-Fase
 * Reemplaza un usuario registrado que nunca pagó por un Clon (Agente IA).
 * Solo el master puede ejecutar esta acción.
 *
 * POST params:
 *   nickname  — Nickname del usuario a reemplazar (para buscar)
 *   confirmar — "si" para ejecutar el reemplazo, omitir para solo previsualizar
 */
require_once 'config.php';
require_once 'admin_auth.php';
require_once 'clon_logic.php';
requireAdminSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(['error' => 'Método no permitido'], 405);
}

$nickname  = trim($_POST['nickname'] ?? '');
$confirmar = trim($_POST['confirmar'] ?? '');

if (empty($nickname)) {
    sendResponse(['error' => 'Debes indicar el nickname del usuario a reemplazar.'], 400);
}

try {
    // ── 1. Buscar el usuario por nickname (con fecha de registro) ──────────
    $stmt = $pdo->prepare("
        SELECT id, nickname, wallet_address, tipo_usuario, patrocinador_id,
               fecha_registro
        FROM usuarios
        WHERE nickname = ?
        LIMIT 1
    ");
    $stmt->execute([$nickname]);
    $usuario = $stmt->fetch();

    if (!$usuario) {
        sendResponse(['error' => "No se encontró ningún usuario con el nickname \"$nickname\"."], 404);
    }

    // ── 2. Validaciones de seguridad ──────────────────────────────────────

    // No permitir reemplazar master o sistema
    if (in_array($usuario['tipo_usuario'], ['master', 'sistema', 'clon'])) {
        sendResponse(['error' => "No se puede reemplazar un usuario de tipo \"{$usuario['tipo_usuario']}\"."], 403);
    }

    // No permitir reemplazar si ya pagó (tiene tablero activo o completado)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM tableros_progreso
        WHERE usuario_id = ?
          AND estado IN ('en_progreso', 'completado')
    ");
    $stmt->execute([$usuario['id']]);
    $tiene_tablero = (int)$stmt->fetchColumn();

    if ($tiene_tablero > 0) {
        sendResponse([
            'error' => "El usuario \"{$usuario['nickname']}\" ya tiene tableros activos o completados. Solo se pueden reemplazar usuarios que nunca pagaron."
        ], 403);
    }

    // Verificar que el usuario está en referidos (ocupa una posición)
    $stmt = $pdo->prepare("
        SELECT r.id, r.id_padre, r.fase_numero, r.posicion, r.ciclo,
               p.nickname AS padre_nickname
        FROM referidos r
        JOIN usuarios p ON r.id_padre = p.id
        WHERE r.id_hijo = ?
        LIMIT 1
    ");
    $stmt->execute([$usuario['id']]);
    $posicion_actual = $stmt->fetch();

    if (!$posicion_actual) {
        sendResponse([
            'error' => "El usuario \"{$usuario['nickname']}\" no ocupa ninguna posición en la matriz. No hay nada que reemplazar."
        ], 404);
    }

    // ── 3. Verificar fondos de tesorería ──────────────────────────────────
    $stmt = $pdo->prepare("SELECT valor_decimal FROM sistema_config WHERE clave = 'tesoreria_balance'");
    $stmt->execute();
    $balance = (float)($stmt->fetch()['valor_decimal'] ?? 0);

    // Obtener config del tablero del padre para saber el clon_monto
    $fase_ref    = (int)$posicion_actual['fase_numero'];
    $padre_id    = (int)$posicion_actual['id_padre'];
    $posicion_n  = (int)$posicion_actual['posicion'];
    $ciclo_ref   = (int)$posicion_actual['ciclo'];

    // Buscar tablero activo del padre para saber qué tipo es
    $stmt = $pdo->prepare("
        SELECT tablero_tipo FROM tableros_progreso
        WHERE usuario_id = ?
          AND fase_numero = ?
          AND ciclo = ?
          AND estado = 'en_progreso'
        LIMIT 1
    ");
    $stmt->execute([$padre_id, $fase_ref, $ciclo_ref]);
    $tablero_padre = $stmt->fetch();
    $tablero_tipo  = $tablero_padre ? $tablero_padre['tablero_tipo'] : 'A';

    $cfg_tablero = getPhaseBoardConfig($pdo, $fase_ref, $tablero_tipo);
    $monto_clon  = (float)($cfg_tablero['clon_monto'] ?? 0);

    // ── 4. Datos adicionales para previsualización ────────────────────────

    // Patrocinador del usuario
    $patrocinador_nickname = '—';
    if ($usuario['patrocinador_id']) {
        $stmt = $pdo->prepare("SELECT nickname FROM usuarios WHERE id = ? LIMIT 1");
        $stmt->execute([$usuario['patrocinador_id']]);
        $patrocinador_nickname = $stmt->fetchColumn() ?: '—';
    }

    // Historial de pagos del usuario
    $stmt = $pdo->prepare("
        SELECT tipo, monto, estado, fecha_pago
        FROM pagos
        WHERE id_emisor = ? OR id_receptor = ?
        ORDER BY fecha_pago DESC
        LIMIT 10
    ");
    $stmt->execute([$usuario['id'], $usuario['id']]);
    $pagos_historial = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Resumen de pagos
    $total_pagos     = count($pagos_historial);
    $pagos_completados = array_filter($pagos_historial, function($p) { return $p['estado'] === 'completado'; });
    $pagos_pendientes  = array_filter($pagos_historial, function($p) { return $p['estado'] === 'pendiente'; });

    // Cuántos referidos tiene ese usuario en su red
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM referidos WHERE id_padre = ?");
    $stmt->execute([$usuario['id']]);
    $total_referidos = (int)$stmt->fetchColumn();

    // ── 5. Modo previsualización (sin confirmar) ──────────────────────────
    if ($confirmar !== 'si') {
        sendResponse([
            'success'        => true,
            'preview'        => true,
            'usuario'        => [
                'id'              => $usuario['id'],
                'nickname'        => $usuario['nickname'],
                'tipo'            => $usuario['tipo_usuario'],
                'wallet'          => $usuario['wallet_address'],
                'fecha_registro'  => $usuario['fecha_registro'] ?? '—',
                'patrocinador'    => $patrocinador_nickname,
                'total_referidos' => $total_referidos,
            ],
            'pagos'          => [
                'total'       => $total_pagos,
                'completados' => count($pagos_completados),
                'pendientes'  => count($pagos_pendientes),
                'historial'   => array_values($pagos_historial),
            ],
            'posicion'       => [
                'padre_nickname' => $posicion_actual['padre_nickname'],
                'fase_numero'    => $fase_ref,
                'tablero_tipo'   => $tablero_tipo,
                'posicion'       => $posicion_n,
                'ciclo'          => $ciclo_ref,
            ],
            'monto_clon'     => $monto_clon,
            'balance_actual' => $balance,
            'fondos_ok'      => $balance >= $monto_clon,
            'mensaje'        => "Se reemplazará a \"{$usuario['nickname']}\" (posición $posicion_n bajo \"{$posicion_actual['padre_nickname']}\" en Fase $fase_ref Tablero $tablero_tipo) por un Agente IA usando \$$monto_clon USDT de tesorería.",
        ]);
    }

    // ── 5. Ejecutar reemplazo ─────────────────────────────────────────────
    // Pre-check rápido antes de abrir la transacción (falla rápido sin lock)
    if ($balance < $monto_clon) {
        sendResponse([
            'error' => "Fondos insuficientes. Tesorería tiene \$$balance pero el clon necesita \$$monto_clon."
        ], 400);
    }

    if ($monto_clon <= 0) {
        sendResponse([
            'error' => "El monto del clon para este tablero es \$0. Verifica la configuración de fases_tableros_config."
        ], 400);
    }

    $pdo->beginTransaction();

    // ── GUARD DE BALANCE: re-verificar tesorería dentro de la transacción ──
    // El balance leído arriba puede haber cambiado entre la lectura y aquí.
    // Con FOR UPDATE bloqueamos la fila para que ninguna otra solicitud
    // simultánea pueda descontar antes de que terminemos.
    $stmt = $pdo->prepare("
        SELECT valor_decimal
        FROM sistema_config
        WHERE clave = 'tesoreria_balance'
        FOR UPDATE
    ");
    $stmt->execute();
    $balance_confirmado = (float)($stmt->fetchColumn() ?? 0);

    if ($balance_confirmado < $monto_clon) {
        $pdo->rollBack();
        sendResponse([
            'error' => "Fondos insuficientes (verificación final). Tesorería tiene \$$balance_confirmado pero el clon necesita \$$monto_clon."
        ], 400);
    }
    // ── FIN GUARD ────────────────────────────────────────────────────────

    // 5a. Quitar al usuario de referidos
    $stmt = $pdo->prepare("DELETE FROM referidos WHERE id_hijo = ?");
    $stmt->execute([$usuario['id']]);

    // 5b. Marcar al usuario como inactivo (no se elimina, queda registrado)
    $stmt = $pdo->prepare("UPDATE usuarios SET tipo_usuario = 'inactivo' WHERE id = ?");
    $stmt->execute([$usuario['id']]);

    // 5c. Crear el clon
    $clon_wallet   = "0xCLON_" . bin2hex(random_bytes(4));
    $clon_nickname = "RADIX_CLON_" . rand(1000, 9999);

    $stmt = $pdo->prepare("INSERT INTO usuarios (wallet_address, nickname, tipo_usuario, patrocinador_id) VALUES (?, ?, 'clon', ?)");
    $stmt->execute([$clon_wallet, $clon_nickname, $padre_id]);
    $clon_id = (int)$pdo->lastInsertId();

    // 5d. Insertar clon en la misma posición que tenía el usuario removido
    $stmt = $pdo->prepare("
        INSERT INTO referidos (id_padre, id_hijo, fase_numero, posicion, nivel_en_red, ciclo)
        VALUES (?, ?, ?, ?, 1, ?)
    ");
    $stmt->execute([$padre_id, $clon_id, $fase_ref, $posicion_n, $ciclo_ref]);

    // 5e. Insertar tablero_progreso para el clon
    $stmt = $pdo->prepare("
        INSERT INTO tableros_progreso (usuario_id, fase_numero, tablero_tipo, ciclo, estado)
        VALUES (?, ?, ?, ?, 'en_progreso')
    ");
    $stmt->execute([$clon_id, $fase_ref, $tablero_tipo, $ciclo_ref]);

    // 5f. Registrar pago del clon (desde tesorería)
    $stmt = $pdo->prepare("
        INSERT INTO pagos (
            id_emisor, id_receptor, beneficiario_usuario_id, fase_numero, propietario_flujo,
            wallet_destino_real, tablero_tipo, ciclo, origen_fondos, monto, monto_pagado,
            tipo, estado, fecha_confirmacion
        ) VALUES (?, ?, ?, ?, 'sistema', NULL, ?, ?, 'tesoreria', ?, ?, 'regalo', 'completado', NOW())
    ");
    $stmt->execute([$clon_id, $padre_id, $padre_id, $fase_ref, $tablero_tipo, $ciclo_ref, $monto_clon, $monto_clon]);

    // 5g. Descontar de tesorería
    $stmt = $pdo->prepare("UPDATE sistema_config SET valor_decimal = valor_decimal - ? WHERE clave = 'tesoreria_balance'");
    $stmt->execute([$monto_clon]);

    // 5h. Registrar movimiento de tesorería
    $stmt = $pdo->prepare("
        INSERT INTO tesoreria_movimientos (tipo, monto, motivo, relacion_id)
        VALUES ('egreso', ?, ?, ?)
    ");
    $stmt->execute([
        $monto_clon,
        "Reemplazo de usuario '{$usuario['nickname']}' por Clon $clon_nickname para padre ID $padre_id en Fase $fase_ref",
        $padre_id
    ]);

    // 5i. Log de auditoría
    $stmt = $pdo->prepare("
        INSERT INTO auditoria_logs (usuario_id, fase_numero, accion, tabla_afectada, detalles)
        VALUES (?, ?, 'REEMPLAZO_USUARIO_CON_CLON', 'referidos', ?)
    ");
    $stmt->execute([
        $padre_id,
        $fase_ref,
        "Usuario '{$usuario['nickname']}' (ID {$usuario['id']}) reemplazado por Clon $clon_nickname (ID $clon_id) en posición $posicion_n, Fase $fase_ref Tablero $tablero_tipo."
    ]);

    $pdo->commit();

    // 5j. Verificar si el padre avanzó de tablero
    require_once 'matrix_logic.php';
    verificarAvanceTablero($padre_id, $pdo, false, $fase_ref, $ciclo_ref);
    verificarCadenaAscendente($padre_id, $pdo, 10, $fase_ref, $ciclo_ref);

    sendResponse([
        'success'  => true,
        'preview'  => false,
        'mensaje'  => "✅ Usuario \"{$usuario['nickname']}\" reemplazado por el Agente IA $clon_nickname en posición $posicion_n bajo \"{$posicion_actual['padre_nickname']}\" (Fase $fase_ref Tablero $tablero_tipo). Se usaron \$$monto_clon USDT de tesorería.",
        'clon'     => ['id' => $clon_id, 'nickname' => $clon_nickname],
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("RADIX admin_reemplazar_con_clon ERROR: " . $e->getMessage());
    sendResponse(['error' => 'Error del servidor: ' . $e->getMessage()], 500);
}
