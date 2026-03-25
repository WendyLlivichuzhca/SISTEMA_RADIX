<?php
/**
 * admin_login.php — RADIX Phase 0
 * Interfaz de acceso para el panel administrativo.
 */
session_start();

// Si ya tiene sesión, mandarlo al dashboard directamente
if (!empty($_SESSION['radix_admin_id'])) {
    header('Location: admin_dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RADIX — Acceso Administrativo</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #030305; --gold: #ffcc00; --primary: #9d00ff;
            --border: rgba(255,204,0,0.1); --card: rgba(255,255,255,0.02);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: var(--bg); color: #fff; font-family: 'Outfit', sans-serif;
            display: flex; align-items: center; justify-content: center; min-height: 100vh;
            background-image: radial-gradient(circle at 50% -20%, #1a0a2e, transparent);
        }
        .login-card {
            width: 100%; max-width: 400px; padding: 40px; background: var(--card);
            border: 1px solid var(--border); border-radius: 24px; backdrop-filter: blur(10px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.4); text-align: center;
        }
        .logo { font-size: 1.8rem; font-weight: 800; color: var(--gold); margin-bottom: 30px; letter-spacing: 2px; }
        .logo span { color: #fff; }
        
        h2 { font-size: 1.2rem; margin-bottom: 10px; font-weight: 600; }
        p { font-size: 0.85rem; color: #666; margin-bottom: 30px; }

        .form-group { margin-bottom: 20px; text-align: left; }
        label { display: block; font-size: 0.75rem; text-transform: uppercase; color: #888; margin-bottom: 8px; letter-spacing: 1px; }
        input {
            width: 100%; padding: 14px 18px; background: rgba(255,255,255,0.03); border: 1px solid var(--border);
            border-radius: 12px; color: #fff; font-family: inherit; font-size: 0.95rem; transition: 0.3s;
        }
        input:focus { outline: none; border-color: var(--gold); background: rgba(255,255,255,0.06); }

        .btn-login {
            width: 100%; padding: 14px; background: var(--gold); color: #000; border: none;
            border-radius: 12px; font-weight: 800; font-size: 0.9rem; cursor: pointer; transition: 0.3s;
            margin-top: 10px; text-transform: uppercase; letter-spacing: 1px;
        }
        .btn-login:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(255,204,0,0.3); opacity: 0.9; }
        .btn-login:disabled { opacity: 0.5; cursor: not-allowed; }

        #msg { margin-top: 20px; font-size: 0.85rem; min-height: 20px; }
        .err { color: #ff4444; }
        .ok { color: #00e676; }

        .footer-link { margin-top: 30px; font-size: 0.75rem; color: #444; }
        .footer-link a { color: #888; text-decoration: none; }
    </style>
</head>
<body>

    <div class="login-card">
        <div class="logo">RA<span>DIX</span></div>
        <h2>Panel Administrativo</h2>
        <p>Introduce tus credenciales de acceso</p>

        <form id="login-form">
            <div class="form-group">
                <label>Usuario</label>
                <input type="text" id="user" required placeholder="Ingresa tu usuario">
            </div>
            <div class="form-group">
                <label>Contraseña</label>
                <input type="password" id="pass" required placeholder="••••••••">
            </div>

            <button type="submit" class="btn-login" id="btn">Entrar al Panel</button>
        </form>

        <div id="msg"></div>

        <div class="footer-link">
            <a href="index.html">← Volver al sitio</a>
        </div>
    </div>

    <script>
        const form = document.getElementById('login-form');
        const msg  = document.getElementById('msg');
        const btn  = document.getElementById('btn');

        form.onsubmit = async (e) => {
            e.preventDefault();
            msg.className = '';
            msg.innerText = '⌛ Verificando...';
            btn.disabled = true;

            const formData = new FormData();
            formData.append('user', document.getElementById('user').value);
            formData.append('pass', document.getElementById('pass').value);

            try {
                const res  = await fetch('radix_api/admin_login.php', { method: 'POST', body: formData });
                const data = await res.json();

                if (data.success) {
                    msg.className = 'ok';
                    msg.innerText = '✅ Acceso concedido. Redirigiendo...';
                    setTimeout(() => window.location.href = 'admin_dashboard.php', 1000);
                } else {
                    msg.className = 'err';
                    msg.innerText = '❌ ' + (data.error || 'Credenciales incorrectas');
                    btn.disabled = false;
                }
            } catch (err) {
                msg.className = 'err';
                msg.innerText = '❌ Error de conexión con el servidor';
                btn.disabled = false;
            }
        };
    </script>
</body>
</html>
