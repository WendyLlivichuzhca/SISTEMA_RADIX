<?php
/**
 * test_platinum_flow.php — RADIX Phase 3 Verification
 * Simula el cierre de un Tablero C en Fase 3 para verificar la liquidación final.
 */
require_once 'config.php';
require_once 'matrix_logic.php';

header('Content-Type: text/plain');

try {
    $pdo->beginTransaction();

    // 1. Crear un usuario de prueba (si no existe)
    $test_nickname = "TEST_PLATINUM_USER";
    $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE nickname = ?");
    $stmt->execute([$test_nickname]);
    $user = $stmt->fetch();

    if (!$user) {
        $stmt = $pdo->prepare("INSERT INTO usuarios (nickname, wallet_address, tipo_usuario) VALUES (?, '0xTEST_PLAT_WALLET', 'real')");
        $stmt->execute([$test_nickname]);
        $user_id = (int)$pdo->lastInsertId();
    } else {
        $user_id = (int)$user['id'];
    }

    echo "--- INICIO PRUEBA FASE 3 ---\n";
    echo "Usuario: $test_nickname (ID $user_id)\n";

    // 2. Limpiar registros previos de prueba
    $pdo->prepare("DELETE FROM tableros_progreso WHERE usuario_id = ? AND fase_numero = 3")->execute([$user_id]);
    $pdo->prepare("DELETE FROM referidos WHERE id_padre = ? AND fase_numero = 3")->execute([$user_id]);

    // 3. Colocar al usuario en Fase 3 Tablero C, Ciclo 1 (en progreso)
    $stmt = $pdo->prepare("
        INSERT INTO tableros_progreso (usuario_id, fase_numero, tablero_tipo, ciclo, estado)
        VALUES (?, 3, 'C', 1, 'en_progreso')
    ");
    $stmt->execute([$user_id]);
    echo "Estado: Colocado en Fase 3 tableros C.\n";

    // 4. Crear 3 referidos ficticios que ya terminaron su parte
    for ($i = 1; $i <= 3; $i++) {
        $ref_nick = "REF_PLAT_{$i}";
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE nickname = ?");
        $stmt->execute([$ref_nick]);
        $ref = $stmt->fetch();
        if (!$ref) {
            $stmt = $pdo->prepare("INSERT INTO usuarios (nickname, wallet_address, tipo_usuario) VALUES (?, '0xREF_WALLET_{$i}', 'real')");
            $stmt->execute([$ref_nick]);
            $ref_id = (int)$pdo->lastInsertId();
        } else {
            $ref_id = (int)$ref['id'];
        }

        // Colocarlos en la red del usuario en Fase 3
        $pdo->prepare("DELETE FROM referidos WHERE id_hijo = ? AND fase_numero = 3")->execute([$ref_id]);
        $stmt = $pdo->prepare("INSERT INTO referidos (id_padre, id_hijo, fase_numero, posicion, nivel_en_red, ciclo) VALUES (?, ?, 3, ?, 1, 1)");
        $stmt->execute([$user_id, $ref_id, $i]);

        // Simular que ellos ya pagaron al usuario (regalos)
        $stmt = $pdo->prepare("
            INSERT INTO pagos (id_emisor, id_receptor, beneficiario_usuario_id, fase_numero, tablero_tipo, ciclo, monto, tipo, estado)
            VALUES (?, ?, ?, 3, 'C', 1, 40000, 'regalo', 'completado')
        ");
        $stmt->execute([$ref_id, $user_id, $user_id]);
    }
    echo "Estado: 3 Referidos calificados agregados.\n";

    // 5. DISPARAR LÓGICA DE CIERRE
    echo "Ejecutando verificarAvanceTablero...\n";
    $success = verificarAvanceTablero($user_id, $pdo, true, 3, 1);

    if (!$success) {
        throw new Exception("La función verificarAvanceTablero devolvió FALSE.");
    }

    // 6. VERIFICAR RESULTADOS
    echo "\n--- RESULTADOS ---\n";

    // 6a. ¿Se creó la utilidad master?
    $stmt = $pdo->prepare("SELECT monto, tipo FROM pagos WHERE id_emisor = ? AND fase_numero = 3 AND tipo = 'utilidad_master' LIMIT 1");
    $stmt->execute([$user_id]);
    $pago_master = $stmt->fetch();
    echo "Pago Utilidad Master: " . ($pago_master ? "\$$pago_master[monto] (OK)" : "NO ENCONTRADO (ERROR)") . "\n";

    // 6b. ¿Se creó la reentrada?
    $stmt = $pdo->prepare("SELECT id FROM tableros_progreso WHERE usuario_id = ? AND fase_numero = 3 AND tablero_tipo = 'A' AND ciclo = 2 LIMIT 1");
    $stmt->execute([$user_id]);
    $reentrada = $stmt->fetch();
    echo "Reentrada Ciclo 2: " . ($reentrada ? "EXITOSA (OK)" : "FALLIDA (ERROR)") . "\n";

    // 6c. ¿Se incrementó la tesorería?
    $stmt = $pdo->prepare("SELECT valor_decimal FROM sistema_config WHERE clave = 'tesoreria_balance'");
    $stmt->execute();
    $balance = $stmt->fetchColumn();
    echo "Balance Tesorería (Actual): \$$balance\n";

    echo "\n--- PRUEBA FINALIZADA CON ÉXITO ---\n";

    // Deshacer cambios para no ensuciar la BD real
    $pdo->rollBack();
    echo "Rollback ejecutado (Base de datos limpia).\n";

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "\n❌ ERROR EN LA PRUEBA: " . $e->getMessage() . "\n";
}
