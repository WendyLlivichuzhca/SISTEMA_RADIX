-- RADIX
-- Amplia tipos de pago para soportar saltos de fase posteriores.

START TRANSACTION;

ALTER TABLE pagos
  MODIFY COLUMN tipo ENUM(
    'regalo',
    'ganancia_tablero',
    'tesoreria_clon',
    'salto_fase_1',
    'salto_fase_2',
    'salto_fase_3',
    'reentrada'
  ) NOT NULL;

COMMIT;
