-- RADIX
-- Habilita la infraestructura minima para abrir Fase 1 en paralelo
-- sin bloquear la reentrada continua en Fase 0.

START TRANSACTION;

SET @sql := (
    SELECT IF(
        COUNT(*) > 0,
        'ALTER TABLE referidos DROP INDEX unique_padre_posicion',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'referidos'
      AND INDEX_NAME = 'unique_padre_posicion'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        COUNT(*) > 0,
        'ALTER TABLE referidos DROP INDEX unique_padre_hijo',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'referidos'
      AND INDEX_NAME = 'unique_padre_hijo'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        COUNT(*) > 0,
        'ALTER TABLE referidos DROP INDEX unique_padre_hijo_ciclo',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'referidos'
      AND INDEX_NAME = 'unique_padre_hijo_ciclo'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        COUNT(*) > 0,
        'ALTER TABLE referidos DROP INDEX unique_padre_posicion_ciclo',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'referidos'
      AND INDEX_NAME = 'unique_padre_posicion_ciclo'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE referidos ADD UNIQUE KEY unique_padre_hijo_fase_ciclo (id_padre, id_hijo, fase_numero, ciclo)',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'referidos'
      AND INDEX_NAME = 'unique_padre_hijo_fase_ciclo'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE referidos ADD UNIQUE KEY unique_padre_posicion_fase_ciclo (id_padre, fase_numero, ciclo, posicion)',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'referidos'
      AND INDEX_NAME = 'unique_padre_posicion_fase_ciclo'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE fases_config
SET activa = 1
WHERE fase_numero = 1;

UPDATE fases_tableros_config
SET activa = 1
WHERE fase_numero = 1;

COMMIT;
