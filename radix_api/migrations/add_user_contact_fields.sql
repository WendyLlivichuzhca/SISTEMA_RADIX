ALTER TABLE usuarios
ADD COLUMN nombre_completo VARCHAR(150) NULL AFTER nickname,
ADD COLUMN telefono VARCHAR(40) NULL AFTER nombre_completo,
ADD COLUMN correo_electronico VARCHAR(150) NULL AFTER telefono;
