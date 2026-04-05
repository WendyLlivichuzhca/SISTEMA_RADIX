<?php
/**
 * Migración: Agrega 'inactivo' al ENUM tipo_usuario de la tabla usuarios.
 * Ejecuta este archivo UNA SOLA VEZ desde el navegador o terminal.
 * URL: https://tu-hosting.com/radix_api/migrations/add_inactivo_tipo_usuario.php
 */
require_once __DIR__ . '/../config.php';

try {
    $pdo->exec("
        ALTER TABLE usuarios
        MODIFY COLUMN tipo_usuario
        ENUM('real', 'clon', 'master', 'sistema', 'inactivo')
        NOT NULL DEFAULT 'real'
    ");
    echo "<h2 style='color:green'>✅ Migración exitosa</h2>";
    echo "<p>La columna <strong>tipo_usuario</strong> ahora acepta el valor <strong>'inactivo'</strong>.</p>";
    echo "<p>Ya puedes usar la función de Reemplazar usuario por Agente IA sin errores.</p>";
} catch (Exception $e) {
    echo "<h2 style='color:red'>❌ Error en la migración</h2>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}
