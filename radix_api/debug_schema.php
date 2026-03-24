<?php
require_once 'config.php';

echo "🔍 Verificando Estructura de Base de Datos para Fase 0...\n";

$tablas_necesarias = [
    'usuarios' => ['id', 'wallet_address', 'tipo_usuario', 'patrocinador_id'],
    'tableros_progreso' => ['usuario_id', 'tablero_tipo', 'ciclo', 'estado'],
    'referidos' => ['id_padre', 'id_hijo', 'posicion'],
    'pagos' => ['id_emisor', 'id_receptor', 'monto', 'tipo', 'estado'],
    'sistema_config' => ['clave', 'valor_decimal']
];

foreach ($tablas_necesarias as $tabla => $columnas) {
    try {
        $q = $pdo->query("DESCRIBE $tabla");
        $cols = $q->fetchAll(PDO::FETCH_COLUMN);
        
        echo "✅ Tabla '$tabla': ";
        foreach ($columnas as $col) {
            if (in_array($col, $cols)) {
                echo "[$col ✔️] ";
            } else {
                echo "[$col ❌] ";
            }
        }
        echo "\n";
    } catch (Exception $e) {
        echo "❌ Error en tabla '$tabla': " . $e->getMessage() . "\n";
    }
}
?>
