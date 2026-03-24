<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RADIX — Panel Administrativo (Master)</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #050508;
            --gold: #ffcc00;
            --primary: #9d00ff;
            --secondary: #00d2ff;
            --accent: #00e676;
            --card: rgba(255,204,0,0.03);
            --border: rgba(255,204,0,0.1);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: var(--bg);
            color: #fff;
            font-family: 'Outfit', sans-serif;
            display: flex; min-height: 100vh;
        }

        /* ======= SIDEBAR ======= */
        aside {
            width: 280px;
            background: #000;
            border-right: 1px solid var(--border);
            padding: 40px 20px;
            display: flex; flex-direction: column;
        }
        .logo { font-size: 1.4rem; font-weight: 800; color: var(--gold); margin-bottom: 50px; text-align: center; letter-spacing: 2px; }
        .nav-item { padding: 15px 20px; border-radius: 12px; color: #666; text-decoration: none; margin-bottom: 10px; transition: 0.3s; display: flex; align-items: center; gap: 15px; font-size: 0.9rem; }
        .nav-item:hover, .nav-item.active { background: var(--card); color: var(--gold); border: 1px solid var(--border); }

        /* ======= MAIN CONTENT ======= */
        main { flex: 1; padding: 40px; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 40px; border-bottom: 1px solid var(--border); padding-bottom: 20px; }
        .admin-badge { padding: 5px 15px; background: var(--gold); color: #000; border-radius: 5px; font-size: 0.7rem; font-weight: 800; }

        /* ======= CARDS ======= */
        .metrics { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 40px; }
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 20px; padding: 25px; position: relative; transition: 0.3s; }
        .card:hover { border-color: var(--gold); transform: scale(1.02); }
        .card h4 { font-size: 0.75rem; color: #777; text-transform: uppercase; margin-bottom: 10px; }
        .card .value { font-size: 1.8rem; font-weight: 800; color: var(--gold); }
        .card .sub { font-size: 0.7rem; color: #555; margin-top: 5px; }

        /* ======= LISTS ======= */
        .layout { display: grid; grid-template-columns: 2fr 1fr; gap: 30px; }
        .section-box { background: rgba(255,255,255,0.01); border: 1px solid var(--border); border-radius: 20px; padding: 30px; }
        .section-box h3 { font-size: 1.2rem; margin-bottom: 25px; border-left: 4px solid var(--gold); padding-left: 15px; }

        .log-item { display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid rgba(255,255,255,0.05); font-size: 0.85rem; color: #aaa; }
        .log-item span { color: var(--gold); }

        /* TABLE */
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { text-align: left; padding: 12px; font-size: 0.7rem; color: #555; text-transform: uppercase; border-bottom: 1px solid var(--border); }
        td { padding: 15px 12px; font-size: 0.85rem; border-bottom: 1px solid rgba(255,255,255,0.03); }

        #loading { position: fixed; inset: 0; background: #000; z-index: 1000; display: flex; align-items: center; justify-content: center; font-weight: 800; color: var(--gold); }

    </style>
</head>
<body>

    <div id="loading">Sincronizando Nodo Maestro...</div>

    <aside>
        <div class="logo">RADIX ADMIN</div>
        <nav>
            <a href="#" class="nav-item active">Vista Global</a>
            <a href="#" class="nav-item">Tesorería (Clones)</a>
            <a href="#" class="nav-item">Usuarios Reales</a>
            <a href="#" class="nav-item">Configuración Fees</a>
            <a href="#" class="nav-item">Logs de Auditoría</a>
        </nav>
    </aside>

    <main>
        <header>
            <div>
                <h2 style="font-weight: 800;">Panel de Control Administrativo</h2>
                <p style="color:#555; font-size:0.9rem;">Bienvenida, Dueña. El sistema RADIX está operando bajo parámetros normales.</p>
            </div>
            <div class="admin-badge">DUEÑA (ID #1)</div>
        </header>

        <div class="metrics">
            <div class="card">
                <h4>TESORERÍA (AGENTE IA)</h4>
                <div class="value" id="stat-tesoreria">$0.00</div>
                <div class="sub">Fondos para inyectar clones</div>
            </div>
            <div class="card">
                <h4>POOL FASE 1</h4>
                <div class="value" id="stat-fase1">$0.00</div>
                <div class="sub">Acumulado para saltos de fase</div>
            </div>
            <div class="card">
                <h4>USUARIOS REALES</h4>
                <div class="value" id="stat-usuarios">0</div>
                <div class="sub">Crecimiento orgánico</div>
            </div>
            <div class="card">
                <h4>GANANCIA MASTER (ID1)</h4>
                <div class="value" id="stat-master">$0.00</div>
                <div class="sub">Utilidad retenida en cuenta #1</div>
            </div>
        </div>

        <div class="layout">
            <div class="section-box">
                <h3>Actividad Reciente del Sistema</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Acción</th>
                            <th>Usuario / Nodo</th>
                            <th>Monto</th>
                            <th>Fecha</th>
                        </tr>
                    </thead>
                    <tbody id="logs-body">
                        <!-- Carga dinámica -->
                    </tbody>
                </table>
            </div>

            <div class="section-box">
                <h3>Distribución de Red</h3>
                <div style="margin-top:20px;">
                    <p style="font-size:0.85rem; color:#888;">Agentes IA: <span id="stat-clones" style="color:var(--gold)">0</span></p>
                    <div style="height:8px; width:100%; background:#222; border-radius:10px; margin-top:10px; overflow:hidden;">
                        <div id="reales-bar" style="height:100%; background:var(--gold); width:50%;"></div>
                    </div>
                    <p style="font-size:0.65rem; color:#444; margin-top:5px; text-align:right;">Ratio Reales vs Clones</p>
                </div>

                <div style="margin-top:40px; padding:20px; background:rgba(255,204,0,0.05); border-radius:12px; border:1px solid var(--border);">
                    <h5 style="color:var(--gold); margin-bottom:10px;">ACCIONES RÁPIDAS</h5>
                    <button style="width:100%; padding:10px; background:transparent; border:1px solid var(--gold); color:var(--gold); border-radius:8px; cursor:pointer;" onclick="location.reload()">Sincronizar Datos</button>
                    <button style="width:100%; padding:10px; background:var(--gold); border:none; color:#000; border-radius:8px; cursor:pointer; margin-top:10px; font-weight:800;">Retirar Mis Ganancias</button>
                </div>
            </div>
        </div>
    </main>

    <script>
        async function loadAdminStats() {
            try {
                const response = await fetch('radix_api/admin_global_stats.php');
                const data = await response.json();

                if (data.success) {
                    document.getElementById('stat-tesoreria').innerText = `$${data.tesoreria.toFixed(2)}`;
                    document.getElementById('stat-fase1').innerText = `$${data.fase1_pool.toFixed(2)}`;
                    document.getElementById('stat-usuarios').innerText = data.usuarios.reales;
                    document.getElementById('stat-clones').innerText = data.usuarios.clones;
                    document.getElementById('stat-master').innerText = `$${data.master_id1_earnings.toFixed(2)}`;

                    // Ratio Bar
                    const total = data.usuarios.reales + data.usuarios.clones;
                    const ratio = total > 0 ? (data.usuarios.reales / total) * 100 : 50;
                    document.getElementById('reales-bar').style.width = ratio + "%";

                    // Logs
                    const logsTable = document.getElementById('logs-body');
                    logsTable.innerHTML = '';
                    if (data.logs && data.logs.length > 0) {
                        data.logs.forEach(log => {
                            logsTable.innerHTML += `
                                <tr>
                                    <td>AUDITORÍA IA</td>
                                    <td>${log.details}</td>
                                    <td><span style="color:var(--accent)">EJECUTADO</span></td>
                                    <td style="font-size:0.7rem;">${log.created_at}</td>
                                </tr>
                            `;
                        });
                    } else {
                        logsTable.innerHTML = '<tr><td colspan="4" style="text-align:center; color:#444;">No hay actividad reciente</td></tr>';
                    }

                }
            } catch (error) {
                console.error("Error administrativo:", error);
            } finally {
                document.getElementById('loading').style.display = "none";
            }
        }

        window.onload = loadAdminStats;
    </script>
</body>
</html>
