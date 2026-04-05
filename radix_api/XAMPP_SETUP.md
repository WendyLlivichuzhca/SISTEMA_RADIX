# Guía de Configuración para RADIX (XAMPP / cPanel)

Sigue estos pasos para que tu sitio **RADIX** funcione con la base de datos MySQL.

### 1. Configurar la Base de Datos en el Hosting
1.  Entra a tu **cPanel** y ve a **phpMyAdmin**.
2.  Selecciona la base de datos `corporat_radix_db`.
3.  Ve a la pestaña **"Importar"** y selecciona el archivo `database/schema.sql`.
4.  Haz clic en **"Importar"** para crear todas las tablas (Radix v2.0).

### 2. Configuración del Servidor
Ya he configurado los archivos para conectarse a tu base de datos:
*   **Usuario:** `corporat_RADIX-user`
*   **Base de Datos:** `corporat_radix_db`

3.  Crear tu primer Usuario Administrador
Para poder entrar al panel (que desarrollaremos a continuación), necesitas crear una cuenta:
1.  Sube los archivos a tu hosting.
2.  En tu navegador, visita: `http://tu-dominio.com/radix_api/create_admin.php`.
3.  Verás un mensaje confirmando que el administrador fue creado.
4.  **IMPORTANTE:** Borra el archivo `radix_api/create_admin.php` de tu servidor inmediatamente después de usarlo por seguridad.

### 4. Probar el Funcionamiento
1.  Ve a la página principal de **RADIX**.
2.  Conecta una billetera (se registrará automáticamente en la nueva base de datos).
3.  Verifica en phpMyAdmin que los datos aparezcan en las tablas `usuarios` y `tableros_progreso`.
