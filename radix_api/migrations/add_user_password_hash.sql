ALTER TABLE usuarios
ADD COLUMN IF NOT EXISTS password_hash VARCHAR(255) NULL AFTER correo_electronico;
