<?php
/**
 * fix_master_tablero.php — Migración de un solo uso.
 *
 * PROBLEMA: RADIX_MASTER (id=1) y SISTEMA_RADIX (id=1000) tienen registros
 * en tableros_progreso que los hacen participar en la matriz como si fueran
 * usuarios reales. Esto puede asignarles clones y ganancias incorrectamente.
 *
 * SOLUCIÓN: Eliminar esos registros y agregar una restricción para que nunca
 * se vuelvan a crear.
 *
 * USO: Ejecutar UNA SOLA VEZ desde CLI o navegando a esta URL (con CLI es más seguro):
 *   php radix_api/migrations/fix_master_tablero.php
 *
 * SEGURIDAD: Después de ejecutar, eliminar o mover este archivo fuera del web root.
 */

// Solo accesible desde CLI para mayor seguridad
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("Este script solo puede ejecutarse desde línea de comandos.\n");
}

require_once __DIR__ . '/../config.php';

echo "=== RADIX — Migración fix_master_tablero ===\n\n";

try {
    $pdo->beginTransaction();

    // 1. Ver cuántos registros existen para cuentas master/sistema
    $stmt = $pdo->query("
        SELECT tp.id, tp.usuario_id, u.nickname, u.tipo_usuario, tp.tablero_tipo, tp.estado
        FROM tableros_progreso tp
        JOIN usuarios u ON tp.usuario_id = u.id
        WHERE u.tipo_usuario IN ('master', 'sistema')
    ");
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($registros)) {
        echo "✅ No se encontraron registros problemáticos. Nada que limpiar.\n";
        $pdo->rollBack();
        exit(0);
    }

    echo "⚠️  Registros encontrados para eliminar:\n";
    foreach ($registros as $r) {
        echo "   - tableros_progreso.id={$r['id']} | usuario_id={$r['usuario_id']} ({$r['nickname']}) | tipo={$r['tipo_usuario']} | tablero={$r['tablero_tipo']} | estado={$r['estado']}\n";
    }
    echo "\n";

    // 2. Eliminar los registros
    $stmt = $pdo->prepare("
        DELETE tp FROM tableros_progreso tp
        JOIN usuarios u ON tp.usuario_id = u.id
        WHERE u.tipo_usuario IN ('master', 'sistema')
    ");
    $stmt->execute();
    $eliminados = $stmt->rowCount();

    // 3. Registrar en auditoría
    $stmt = $pdo->prepare("
        INSERT INTO auditoria_logs (usuario_id, accion, tabla_afectada, detalles)
        VALUES (1000, 'MIGRACION_LIMPIEZA', 'tableros_progreso', ?)
    ");
    $stmt->execute(["fix_master_tablero.php: Se eliminaron $eliminados registros de tableros_progreso para cuentas master/sistema."]);

    $pdo->commit();

    echo "✅ Eliminados $eliminados registros de tableros_progreso.\n";
    echo "✅ Entrada de auditoría registrada.\n";
    echo "\n⚠️  IMPORTANTE: Elimina o mueve este archivo del servidor después de ejecutarlo.\n";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
?>
