<?php
require_once 'config.php';
require_once 'matrix_logic.php';
require_once 'notificaciones.php'; // MEJORA #6: Notificaciones Telegram

/**
 * Intenta activar un clon usando los fondos de la tesorería.
 * Sigue la Regla de Oro: Clones solo para Nivel 2+ (Usuarios que ya trajeron sus 3 humanos).
 */
function intentarActivarClon($pdo) {
    try {
        // 1. Verificar balance de tesorería
        $stmt = $pdo->prepare("SELECT valor_decimal FROM sistema_config WHERE clave = 'tesoreria_balance'");
        $stmt->execute();
        $balance = $stmt->fetch()['valor_decimal'];

        if ($balance < 10) return "Fondos insuficientes en tesorería ($balance).";

        // 2. Buscar un usuario elegible para recibir un clon
        //
        // REGLA DE ORO: "Nivel 1 solo humanos — clones van al Nivel 2"
        //   • El beneficiario DEBE tener un patrocinador directo (padre) que ya completó
        //     su Nivel 1 con 3 referidos HUMANOS (tipo_usuario = 'real').
        //   • Esto garantiza que el clon rellena un slot de NIVEL 2 del líder,
        //     NO un slot de Nivel 1 (que está reservado para humanos).
        //   • El beneficiario tiene aún < 3 referidos propios (hay un hueco).
        //   • Anti-duplicado: no ha recibido ya un clon en este tablero activo.
        $stmt = $pdo->prepare("
            SELECT tp.usuario_id, tp.tablero_tipo, tp.ciclo, u.wallet_address,
                   (SELECT COUNT(*) FROM referidos r WHERE r.id_padre = tp.usuario_id AND r.ciclo = tp.ciclo) as cuenta_referidos
            FROM tableros_progreso tp
            JOIN usuarios u ON tp.usuario_id = u.id
            WHERE tp.estado = 'en_progreso'
              AND u.tipo_usuario NOT IN ('master', 'sistema')
              -- REGLA PRINCIPAL: el patrocinador del beneficiario tiene exactamente 3 referidos reales
              -- (Nivel 1 del líder está completo → el clon irá al Nivel 2 del líder)
              AND (
                SELECT COUNT(*)
                FROM referidos r_padre
                JOIN usuarios u_padre ON r_padre.id_hijo = u_padre.id
                WHERE r_padre.id_padre = u.patrocinador_id
                  AND u_padre.tipo_usuario = 'real'
              ) >= 3
              -- El beneficiario tiene hueco para un referido más (Nivel 2 incompleto)
              AND (SELECT COUNT(*) FROM referidos r WHERE r.id_padre = tp.usuario_id AND r.ciclo = tp.ciclo) < 3
              -- Anti-duplicado: máximo 1 clon por tablero activo del beneficiario
              AND (SELECT COUNT(*) FROM referidos r2
                   JOIN usuarios u2 ON r2.id_hijo = u2.id
                   WHERE r2.id_padre = tp.usuario_id
                   AND r2.ciclo = tp.ciclo
                   AND u2.tipo_usuario = 'clon'
                   AND r2.fecha_union >= tp.fecha_inicio) = 0
            ORDER BY tp.id ASC
            LIMIT 1
        ");
        $stmt->execute();
        $beneficiario = $stmt->fetch();

        if (!$beneficiario) return "No hay usuarios elegibles para recibir clones en este momento.";

        $padre_id     = (int)$beneficiario['usuario_id'];
        $ciclo_actual = (int)$beneficiario['ciclo'];
        $tablero_tipo = $beneficiario['tablero_tipo'];
        $monto_clon = 10; // Base para Tablero A
        if ($beneficiario['tablero_tipo'] === 'B') $monto_clon = 20;
        if ($beneficiario['tablero_tipo'] === 'C') $monto_clon = 40;

        if ($balance < $monto_clon) return "Tesorería tiene $balance, pero el clon para Tablero " . $beneficiario['tablero_tipo'] . " necesita $monto_clon.";

        // 3. Crear el CLON
        $clon_wallet   = "0xCLON_" . bin2hex(random_bytes(4));
        $clon_nickname = "RADIX_CLON_" . rand(1000, 9999);

        // Usar transacción propia solo si no hay una activa (evita error de transacciones anidadas)
        $propia_tx = !$pdo->inTransaction();
        if ($propia_tx) $pdo->beginTransaction();

        $stmt = $pdo->prepare("INSERT INTO usuarios (wallet_address, nickname, tipo_usuario, patrocinador_id) VALUES (?, ?, 'clon', ?)");
        $stmt->execute([$clon_wallet, $clon_nickname, $padre_id]);
        $clon_id = $pdo->lastInsertId();

        // El clon debe participar del mismo tablero/ciclo del beneficiario para que
        // cuente dentro del motor actual de avance y no quede "huérfano" a nivel lógico.
        $stmt = $pdo->prepare("
            INSERT INTO tableros_progreso (usuario_id, tablero_tipo, ciclo, estado)
            VALUES (?, ?, ?, 'en_progreso')
        ");
        $stmt->execute([$clon_id, $tablero_tipo, $ciclo_actual]);

        // 4. Asignar como referido directo del beneficiario (P1/P2/P3)
        // nivel_en_red = 1 desde la perspectiva del beneficiario (P1/P2/P3).
        // Desde la perspectiva del líder raíz esto es Nivel 2, cumpliendo la regla de oro.
        $posicion = $beneficiario['cuenta_referidos'] + 1;
        $stmt = $pdo->prepare("INSERT INTO referidos (id_padre, id_hijo, posicion, nivel_en_red, ciclo) VALUES (?, ?, ?, 1, ?)");
        $stmt->execute([$padre_id, $clon_id, $posicion, $ciclo_actual]);

        // 5. Registrar pago completado (tesorería paga al beneficiario vía el clon)
        $stmt = $pdo->prepare("
            INSERT INTO pagos (
                id_emisor, id_receptor, beneficiario_usuario_id, wallet_destino_real,
                tablero_tipo, ciclo, origen_fondos, monto, monto_pagado,
                tipo, estado, fecha_confirmacion
            ) VALUES (?, ?, ?, NULL, ?, ?, 'tesoreria', ?, ?, 'regalo', 'completado', NOW())
        ");
        $stmt->execute([$clon_id, $padre_id, $padre_id, $tablero_tipo, $ciclo_actual, $monto_clon, $monto_clon]);

        // 6. Descontar de tesorería
        $stmt = $pdo->prepare("UPDATE sistema_config SET valor_decimal = valor_decimal - ? WHERE clave = 'tesoreria_balance'");
        $stmt->execute([$monto_clon]);

        // 7. Auditoría: movimiento de tesorería (egreso)
        $stmt = $pdo->prepare("INSERT INTO tesoreria_movimientos (tipo, monto, motivo, relacion_id) VALUES ('egreso', ?, ?, ?)");
        $stmt->execute([$monto_clon, "Activación de Clon $clon_nickname para Usuario ID $padre_id", $padre_id]);

        // 8. Auditoría: log de acción
        $stmt = $pdo->prepare("INSERT INTO auditoria_logs (usuario_id, accion, tabla_afectada, detalles) VALUES (?, 'ACTIVACION_CLON', 'usuarios', ?)");
        $stmt->execute([$padre_id, "Clon $clon_nickname generado con \$$monto_clon de tesorería."]);

        if ($propia_tx) $pdo->commit();

        // 9. Notificar al beneficiario (fuera de la transacción para no bloquear)
        notificarClonActivado($pdo, $padre_id, (float)$monto_clon);

        // 10. Verificar si el clon completó el tablero del beneficiario
        // Se llama DESPUÉS del commit para no anidar transacciones.
        // La recursión está acotada: cada clon gasta tesorería, que eventualmente se agota.
        verificarAvanceTablero($padre_id, $pdo);

        return "Clon $clon_nickname activado para usuario ID $padre_id (\$$monto_clon USDT).";

    } catch (Exception $e) {
        if (isset($propia_tx) && $propia_tx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("RADIX clon_logic ERROR: " . $e->getMessage());
        return "Error: " . $e->getMessage();
    }
}
?>
