<?php
require_once 'config.php';
require_once 'matrix_logic.php';

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
        // REGLA: Debe estar en Tablero A, B o C y tener < 3 referidos.
        // REGLA DE ORO: Solo usuarios que ya completaron al menos un ciclo o que están en Tablero B/C 
        // (lo cual implica que ya trajeron 3 humanos para llegar allí).
        $stmt = $pdo->prepare("
            SELECT tp.usuario_id, tp.tablero_tipo, u.wallet_address,
                   (SELECT COUNT(*) FROM referidos r WHERE r.id_padre = tp.usuario_id) as cuenta_referidos
            FROM tableros_progreso tp
            JOIN usuarios u ON tp.usuario_id = u.id
            WHERE tp.estado = 'en_progreso'
              AND (tp.tablero_tipo IN ('B', 'C') OR tp.ciclo > 1)
              AND (SELECT COUNT(*) FROM referidos r WHERE r.id_padre = tp.usuario_id) < 3
            ORDER BY tp.id ASC
            LIMIT 1
        ");
        $stmt->execute();
        $beneficiario = $stmt->fetch();

        if (!$beneficiario) return "No hay usuarios elegibles para recibir clones en este momento.";

        $padre_id = $beneficiario['usuario_id'];
        $monto_clon = 10; // Base para Tablero A
        if ($beneficiario['tablero_tipo'] === 'B') $monto_clon = 20;
        if ($beneficiario['tablero_tipo'] === 'C') $monto_clon = 40;

        if ($balance < $monto_clon) return "Tesorería tiene $balance, pero el clon para Tablero " . $beneficiario['tablero_tipo'] . " necesita $monto_clon.";

        // 3. Crear el CLON
        $clon_wallet = "0xCLON_" . bin2hex(random_bytes(4));
        $clon_nickname = "RADIX_CLON_" . rand(1000, 9999);
        
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("INSERT INTO usuarios (wallet_address, nickname, tipo_usuario, patrocinador_id) VALUES (?, ?, 'clon', ?)");
        $stmt->execute([$clon_wallet, $clon_nickname, $padre_id]);
        $clon_id = $pdo->lastInsertId();

        // 4. Asignar como referido
        $posicion = $beneficiario['cuenta_referidos'] + 1;
        $stmt = $pdo->prepare("INSERT INTO referidos (id_padre, id_hijo, posicion, nivel_en_red) VALUES (?, ?, ?, 2)");
        $stmt->execute([$padre_id, $clon_id, $posicion]);

        // 5. REGISTRAR PAGO COMPLETADO (Tesorería paga al beneficiario)
        $stmt = $pdo->prepare("INSERT INTO pagos (id_emisor, id_receptor, monto, tipo, estado) VALUES (?, ?, ?, 'regalo', 'completado')");
        $stmt->execute([$clon_id, $padre_id, $monto_clon]);

        // 6. Descontar de tesorería
        $stmt = $pdo->prepare("UPDATE sistema_config SET valor_decimal = valor_decimal - ? WHERE clave = 'tesoreria_balance'");
        $stmt->execute([$monto_clon]);

        // 6. Verificar avance del beneficiario
        verificarAvanceTablero($padre_id, $pdo);

        // 7. Auditoría
        $stmt = $pdo->prepare("INSERT INTO auditoria_logs (usuario_id, accion, tabla_afectada, detalles) VALUES (?, 'ACTIVACION_CLON', 'usuarios', ?)");
        $stmt->execute([$padre_id, "Clon $clon_nickname generado con $$monto_clon de tesorería."]);

        $pdo->commit();
        return "✅ Clon activado exitosamente para " . $beneficiario['wallet_address'];

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return "❌ Error: " . $e->getMessage();
    }
}
?>
