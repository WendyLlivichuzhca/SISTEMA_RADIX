<?php
require_once 'radix_api/admin_auth.php';
require_once 'radix_api/config.php';
requireAdminSession(); // Redirige al login si no hay sesion admin

// Nombre del admin de forma dinamica (no hardcodeado)
$admin_display_name = 'Administrador';
$admin_display_id   = '';

// Caso 1: master vio panel via dashboard.php
if (!empty($_SESSION['radix_nickname'])) {
    $admin_display_name = $_SESSION['radix_nickname'];
    $admin_display_id   = 'ID #' . ($_SESSION['radix_user_id'] ?? '1');
}
// Caso 2: admin clasico via admin_login.php
elseif (!empty($_SESSION['radix_admin_id'])) {
    $admin_display_id = 'ID #' . $_SESSION['radix_admin_id'];
    try {
        $stmt = $pdo->prepare("SELECT nickname FROM usuarios WHERE id = ? LIMIT 1");
        $stmt->execute([$_SESSION['radix_admin_id']]);
        $row = $stmt->fetch();
        if ($row) $admin_display_name = $row['nickname'];
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RADIX — Panel Administrativo (Master)</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/d3@7"></script>
    <style>
        :root {
            --bg: #050508; --gold: #ffcc00; --primary: #9d00ff;
            --secondary: #00d2ff; --accent: #00e676;
            --card: rgba(255,204,0,0.03); --border: rgba(255,204,0,0.1);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: var(--bg); color: #fff; font-family: 'Outfit', sans-serif; display: flex; min-height: 100vh; }

        /* SIDEBAR */
        aside { width: 280px; background: #000; border-right: 1px solid var(--border); padding: 40px 20px; display: flex; flex-direction: column; flex-shrink: 0; }
        .logo { font-size: 1.4rem; font-weight: 800; color: var(--gold); margin-bottom: 50px; text-align: center; letter-spacing: 2px; }
        .nav-item { padding: 15px 20px; border-radius: 12px; color: #666; text-decoration: none; margin-bottom: 10px; transition: 0.3s; display: block; font-size: 0.9rem; }
        .nav-item:hover, .nav-item.active { background: var(--card); color: var(--gold); border: 1px solid var(--border); }

        /* MAIN */
        main { flex: 1; padding: 40px; overflow-y: auto; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 40px; border-bottom: 1px solid var(--border); padding-bottom: 20px; }
        .admin-badge { padding: 5px 15px; background: var(--gold); color: #000; border-radius: 5px; font-size: 0.7rem; font-weight: 800; }

        /* MÉTRICAS */
        .metrics { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 30px; }
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 20px; padding: 22px; position: relative; transition: 0.3s; }
        .card:hover { border-color: var(--gold); }
        .card h4 { font-size: 0.72rem; color: #777; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 10px; }
        .card .value { font-size: 1.8rem; font-weight: 800; color: var(--gold); }
        .card .sub { font-size: 0.7rem; color: #555; margin-top: 5px; }

        /* GRIDS */
        .layout-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; margin-bottom: 24px; }
        .layout-2 { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 24px; }
        .section-box { background: rgba(255,255,255,0.01); border: 1px solid var(--border); border-radius: 20px; padding: 24px; }
        .section-box h3 { font-size: 1rem; margin-bottom: 20px; border-left: 4px solid var(--gold); padding-left: 14px; }

        /* TABLE */
        table { width: 100%; border-collapse: collapse; font-size: 0.82rem; }
        th { text-align: left; padding: 10px 12px; font-size: 0.68rem; color: #555; text-transform: uppercase; border-bottom: 1px solid var(--border); }
        td { padding: 12px; font-size: 0.82rem; border-bottom: 1px solid rgba(255,255,255,0.03); color: #aaa; }
        td span.ok  { color: var(--accent); font-weight: 700; }
        td span.pend { color: var(--gold); font-weight: 700; }

        /* TABLERO BAR */
        .dist-bar { display: flex; gap:8px; align-items:stretch; height:60px; margin-top:10px; }
        .dist-col { display:flex; flex-direction:column; align-items:center; justify-content:flex-end; flex:1; gap:4px; }
        .dist-col .bar { width:100%; border-radius:6px 6px 0 0; min-height:4px; transition:height 0.5s; }
        .dist-col .lbl { font-size:0.68rem; color:#555; }
        .dist-col .val { font-size:0.8rem; font-weight:700; }

        /* BOTONES ACCIÓN */
        .btn-action { padding: 10px 18px; border: none; border-radius: 10px; cursor: pointer; font-size: 0.82rem; font-weight: 700; transition: 0.2s; }
        .btn-gold  { background: var(--gold); color: #000; }
        .btn-gold:hover  { opacity: 0.85; }
        .btn-red   { background: #ff444422; color: #ff4444; border: 1px solid #ff444433; }
        .btn-green { background: #00e67622; color: var(--accent); border: 1px solid #00e67633; }
        .btn-green:hover { background: #00e67633; }

        /* RETIROS */
        .retiro-item { display:flex; justify-content:space-between; align-items:center; padding:10px 0; border-bottom:1px solid rgba(255,255,255,0.04); }
        .retiro-item:last-child { border-bottom:none; }

        /* ARBOL ADMIN GENERAL */
        .admin-tree-toolbar {
            display: grid;
            grid-template-columns: 160px 160px minmax(240px, 1fr) auto;
            gap: 14px;
            align-items: end;
            margin-bottom: 18px;
        }
        .admin-tree-control label {
            display: block;
            font-size: 0.72rem;
            color: #666;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .admin-tree-control select,
        .admin-tree-control input {
            width: 100%;
            background: rgba(255,255,255,0.03);
            border: 1px solid var(--border);
            color: #fff;
            border-radius: 12px;
            padding: 12px 14px;
            font-size: 0.86rem;
            outline: none;
        }
        .admin-tree-control input::placeholder { color: #555; }
        .admin-tree-control select:focus,
        .admin-tree-control input:focus {
            border-color: var(--secondary);
            box-shadow: 0 0 0 3px rgba(0,210,255,0.08);
        }
        .admin-tree-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }
        .admin-tree-summary {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }
        .admin-tree-chip {
            padding: 8px 12px;
            border-radius: 999px;
            border: 1px solid rgba(255,255,255,0.08);
            background: rgba(255,255,255,0.02);
            font-size: 0.78rem;
            color: #aaa;
        }
        .admin-tree-chip strong { color: #fff; }
        .admin-tree-stage {
            margin-bottom: 14px;
            font-size: 0.8rem;
            color: #666;
        }
        .admin-tree-stage strong { color: var(--gold); }
        .admin-network-tree {
            position: relative;
            min-height: 720px;
            border-radius: 22px;
            border: 1px solid rgba(255,255,255,0.06);
            background: radial-gradient(circle at top, rgba(157,0,255,0.06), transparent 42%), rgba(255,255,255,0.01);
            overflow: hidden;
        }
        .admin-network-tree svg {
            width: 100%;
            height: 720px;
            display: block;
            cursor: grab;
        }
        .admin-network-tree.is-dragging svg { cursor: grabbing; }
        .admin-tree-empty {
            color: #555;
            font-size: 0.84rem;
            text-align: center;
            padding: 80px 20px;
        }

        #loading { position: fixed; inset: 0; background: #000; z-index: 1000; display: flex; align-items: center; justify-content: center; font-weight: 800; color: var(--gold); }
        #clon-result { font-size:0.8rem; margin-top:10px; min-height:18px; }

        @media(max-width:1100px) {
            .admin-tree-toolbar { grid-template-columns: 1fr 1fr; }
            .admin-tree-actions { justify-content: stretch; }
        }

        @media(max-width:900px) {
            .metrics { grid-template-columns:1fr 1fr; }
            .layout-3,.layout-2 { grid-template-columns:1fr; }
            .admin-tree-toolbar { grid-template-columns: 1fr; }
            .admin-network-tree { min-height: 620px; }
            .admin-network-tree svg { height: 620px; }
        }
    </style>
</head>
<body>

<div id="loading">Sincronizando Nodo Maestro...</div>

<aside>
    <div class="logo">RADIX ADMIN</div>
    <nav>
        <a href="#" class="nav-item active">Vista Global</a>
        <a href="#" class="nav-item">Usuarios Reales</a>
        <a href="#" class="nav-item">Retiros Pendientes</a>
        <a href="#" class="nav-item">Logs de Auditoría</a>
    </nav>
</aside>

<main>
    <header>
        <div>
            <h2 style="font-weight:800;">Panel de Control Administrativo</h2>
            <p style="color:#555;font-size:0.88rem;">RADIX System · Marzo 2026</p>
        </div>
        <div class="admin-badge"><?php echo htmlspecialchars($admin_display_name); ?> (<?php echo htmlspecialchars($admin_display_id); ?>)</div>
    </header>

    <!-- ── MÉTRICAS PRINCIPALES ── -->
    <div class="metrics">
        <div class="card">
            <h4>TESORERÍA (AGENTES IA)</h4>
            <div class="value" id="stat-tesoreria">$0.00</div>
            <div class="sub">Fondos para inyectar cuentas espejo</div>
        </div>
        <div class="card">
            <h4>POOL FASE 1</h4>
            <div class="value" id="stat-fase1">$0.00</div>
            <div class="sub">Acumulado para saltos</div>
        </div>
        <div class="card">
            <h4>USUARIOS REALES</h4>
            <div class="value" id="stat-usuarios">0</div>
            <div class="sub">Crecimiento orgánico</div>
        </div>
        <div class="card">
            <h4>💰 GANANCIA RED</h4>
            <div class="value" id="stat-master">$0.00</div>
            <div class="sub">Distribuido a usuarios</div>
        </div>
    </div>

    <!-- ── FILA: GRÁFICA + DISTRIBUCIÓN + CONTROL CLON ── -->
    <div class="layout-3">

        <!-- Gráfica de crecimiento diario (MEJORA #8) -->
        <div class="section-box" style="grid-column:span 2;">
            <h3>Crecimiento Diario — Últimos 7 días</h3>
            <canvas id="grafica-crecimiento" height="120"></canvas>
        </div>

        <!-- Distribución por tablero + botón clon (MEJORA #8) -->
        <div class="section-box">
            <h3>Distribución por Tablero</h3>
            <div class="dist-bar">
                <div class="dist-col">
                    <div class="val" id="dist-a-val" style="color:#9d00ff;">0</div>
                    <div class="bar" id="dist-a-bar" style="background:#9d00ff;height:10px;"></div>
                    <div class="lbl">Tablero A</div>
                </div>
                <div class="dist-col">
                    <div class="val" id="dist-b-val" style="color:#00d2ff;">0</div>
                    <div class="bar" id="dist-b-bar" style="background:#00d2ff;height:10px;"></div>
                    <div class="lbl">Tablero B</div>
                </div>
                <div class="dist-col">
                    <div class="val" id="dist-c-val" style="color:#00e676;">0</div>
                    <div class="bar" id="dist-c-bar" style="background:#00e676;height:10px;"></div>
                    <div class="lbl">Tablero C</div>
                </div>
            </div>

            <hr style="border:none; border-top:1px solid var(--border); margin:20px 0;">

            <h3>Ratio Reales / Cuentas Espejo</h3>
            <div style="display:flex;justify-content:space-between;font-size:0.8rem;margin-bottom:8px;">
                <span style="color:var(--accent);">👤 Reales: <strong id="stat-reales">0</strong></span>
                <span style="color:var(--primary);">🤖 Cuentas Espejo: <strong id="stat-clones">0</strong></span>
            </div>
            <div style="height:8px;width:100%;background:#222;border-radius:10px;overflow:hidden;">
                <div id="reales-bar" style="height:100%;background:var(--gold);width:50%;transition:width 0.5s;"></div>
            </div>

            <hr style="border:none; border-top:1px solid var(--border); margin:20px 0;">

            <!-- Botón manual activar clon (MEJORA #8) -->
            <h3>Control de Emergencia</h3>
            <p style="font-size:0.75rem;color:#555;margin-bottom:12px;">Forzar activación de un Agente IA manualmente usando fondos de tesorería.</p>
            <button class="btn-action btn-green" style="width:100%;" onclick="activarClonManual()">
                🤖 Activar Agente IA
            </button>
            <div id="clon-result"></div>
        </div>
    </div>

    <!-- ── FILA: HISTORIAL CLONES + RETIROS PENDIENTES ── -->
    <div class="section-box">
        <h3>Mapa General de Red</h3>
        <div class="admin-tree-stage">
            Vista general de la red por fase y ciclo. Puedes centrar una rama escribiendo el ID, nombre, nickname o wallet de un usuario.
        </div>

        <div class="admin-tree-toolbar">
            <div class="admin-tree-control">
                <label for="admin-tree-phase">Fase</label>
                <select id="admin-tree-phase"></select>
            </div>
            <div class="admin-tree-control">
                <label for="admin-tree-cycle">Ciclo</label>
                <select id="admin-tree-cycle"></select>
            </div>
            <div class="admin-tree-control">
                <label for="admin-tree-root">Raiz o busqueda</label>
                <input id="admin-tree-root" type="text" placeholder="Ej: 1001, Wendy, TRON_TQ2R o wallet">
            </div>
            <div class="admin-tree-actions">
                <button class="btn-action btn-green" type="button" onclick="aplicarFiltrosArbolAdmin()">Ver arbol</button>
                <button class="btn-action btn-gold" type="button" onclick="resetZoomAdminTree()">Reset vista</button>
                <button class="btn-action btn-red" type="button" onclick="limpiarFiltroArbolAdmin()">Vista general</button>
            </div>
        </div>

        <div class="admin-tree-summary" id="admin-tree-summary">
            <span class="admin-tree-chip">Cargando arbol general...</span>
        </div>

        <div id="admin-network-tree" class="admin-network-tree">
            <div class="admin-tree-empty">Cargando red general...</div>
        </div>
    </div>

    <div class="layout-2">

        <!-- Historial detallado de clones (MEJORA #8) -->
        <div class="section-box">
            <h3>Historial de Agentes IA Activados</h3>
            <table>
                <thead>
                    <tr><th>Beneficiario</th><th>Costo</th><th>Detalles</th><th>Fecha</th></tr>
                </thead>
                <tbody id="clones-body">
                    <tr><td colspan="4" style="text-align:center;color:#444;">Cargando...</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Retiros pendientes (MEJORA #4 visible en admin) -->
        <div class="section-box">
            <h3>Retiros Pendientes</h3>
            <div id="retiros-list">
                <p style="color:#444;font-size:0.8rem;text-align:center;padding:20px 0;">Sin solicitudes pendientes.</p>
            </div>
        </div>
    </div>

    <!-- ── ACTIVIDAD RECIENTE ── -->
    <div class="section-box">
        <h3>Actividad Reciente del Sistema</h3>
        <table>
            <thead><tr><th>Acción</th><th>Detalles</th><th>Estado</th><th>Fecha</th></tr></thead>
            <tbody id="logs-body">
                <tr><td colspan="4" style="text-align:center;color:#444;">Cargando...</td></tr>
            </tbody>
        </table>
    </div>

    <div class="section-box">
        <h3>Contactos de Usuarios</h3>
        <div id="contactos-list">
            <p style="color:#444;font-size:0.8rem;text-align:center;padding:20px 0;">Cargando contactos...</p>
        </div>
    </div>

</main>

<script>
let _chartInstance = null;
let _adminTreeZoom = null;
let _adminTreeSvg = null;
let _adminTreeInitialTransform = null;

async function loadAdminStats() {
    try {
        const response = await fetch('radix_api/admin_global_stats.php');
        const data = await response.json();

        if (!data.success) return;

        // ── Métricas ──
        document.getElementById('stat-tesoreria').innerText = `$${data.tesoreria.toFixed(2)}`;
        document.getElementById('stat-fase1').innerText     = `$${data.fase1_pool.toFixed(2)}`;
        document.getElementById('stat-usuarios').innerText  = data.usuarios.reales;
        document.getElementById('stat-master').innerText    = `$${data.master_id1_earnings.toFixed(2)}`;
        document.getElementById('stat-reales').innerText    = data.usuarios.reales;
        document.getElementById('stat-clones').innerText    = data.usuarios.clones;

        const total = data.usuarios.reales + data.usuarios.clones;
        const ratio = total > 0 ? (data.usuarios.reales / total) * 100 : 50;
        document.getElementById('reales-bar').style.width = ratio + '%';

        // ── Distribución por tablero ──
        const dist = data.distribucion_tableros || { A:0, B:0, C:0 };
        const maxDist = Math.max(dist.A, dist.B, dist.C, 1);
        ['A','B','C'].forEach(t => {
            document.getElementById(`dist-${t.toLowerCase()}-val`).innerText = dist[t];
            document.getElementById(`dist-${t.toLowerCase()}-bar`).style.height = Math.max(4, (dist[t]/maxDist)*44) + 'px';
        });

        // ── Gráfica de crecimiento Chart.js ──
        const crecimiento = data.crecimiento_diario || [];
        const labels  = crecimiento.map(d => d.dia);
        const valores = crecimiento.map(d => parseInt(d.nuevos));

        if (_chartInstance) _chartInstance.destroy();
        const ctx = document.getElementById('grafica-crecimiento').getContext('2d');
        _chartInstance = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels.length > 0 ? labels : ['Sin datos'],
                datasets: [{
                    label: 'Nuevos usuarios',
                    data: valores.length > 0 ? valores : [0],
                    backgroundColor: 'rgba(157,0,255,0.4)',
                    borderColor: '#9d00ff',
                    borderWidth: 2,
                    borderRadius: 6,
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { labels: { color: '#888', font: { family: 'Outfit' } } }
                },
                scales: {
                    x: { ticks: { color: '#555' }, grid: { color: '#1a1a28' } },
                    y: { ticks: { color: '#555', stepSize: 1 }, grid: { color: '#1a1a28' }, beginAtZero: true }
                }
            }
        });

        // ── Historial de clones ──
        const clonesBody = document.getElementById('clones-body');
        if (data.logs && data.logs.length > 0) {
            clonesBody.innerHTML = data.logs.map(log => `
                <tr>
                    <td>${log.nickname || '—'}</td>
                    <td>${log.monto ? `$${parseFloat(log.monto).toFixed(2)}` : '—'}</td>
                    <td style="color:#888;font-size:0.75rem;">${log.detalles || ''}</td>
                    <td style="font-size:0.72rem;color:#555;">${(log.fecha||'').split(' ')[0]}</td>
                </tr>
            `).join('');
        } else {
            clonesBody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:#444;">No hay activaciones registradas.</td></tr>';
        }

        // ── Retiros pendientes ──
        const retirosEl = document.getElementById('retiros-list');
        if (data.retiros_pendientes && data.retiros_pendientes.length > 0) {
            retirosEl.innerHTML = data.retiros_pendientes.map(r => `
                <div class="retiro-item" id="retiro-${r.id}" style="display:flex;justify-content:space-between;align-items:center;padding:14px 0;border-bottom:1px solid rgba(255,255,255,0.04);gap:10px;">
                    <div style="flex:1;">
                        <div style="font-size:0.85rem;color:#ddd;font-weight:700;">${r.nickname}</div>
                        <div style="font-size:0.7rem;color:#555;margin-top:2px;word-break:break-all;">${r.wallet_destino||''}</div>
                        <div style="font-size:0.68rem;color:#444;margin-top:2px;">${(r.fecha_solicitud||'').split(' ')[0]}</div>
                    </div>
                    <div style="text-align:right;flex-shrink:0;">
                        <div style="font-size:1.1rem;font-weight:800;color:var(--gold);margin-bottom:8px;">$${parseFloat(r.monto).toFixed(2)} USDT</div>
                        <div style="display:flex;gap:6px;justify-content:flex-end;">
                            <button onclick="procesarRetiro(${r.id},'aprobar')"
                                style="background:#00e676;color:#000;border:none;border-radius:8px;padding:6px 14px;font-size:0.72rem;font-weight:800;cursor:pointer;">
                                ✅ APROBAR
                            </button>
                            <button onclick="procesarRetiro(${r.id},'rechazar')"
                                style="background:rgba(255,82,82,0.15);color:#ff5252;border:1px solid rgba(255,82,82,0.3);border-radius:8px;padding:6px 14px;font-size:0.72rem;font-weight:800;cursor:pointer;">
                                ❌ RECHAZAR
                            </button>
                        </div>
                    </div>
                </div>
            `).join('');
        } else {
            retirosEl.innerHTML = '<p style="color:#444;font-size:0.8rem;text-align:center;padding:20px 0;">Sin solicitudes pendientes.</p>';
        }

        // ── Logs de auditoría ──
        const logsBody = document.getElementById('logs-body');
        if (data.logs && data.logs.length > 0) {
            logsBody.innerHTML = data.logs.map(log => `
                <tr>
                    <td>🤖 Activación Agente IA</td>
                    <td style="font-size:0.75rem;">${log.detalles}</td>
                    <td><span class="ok">✅ EJECUTADO</span></td>
                    <td style="font-size:0.7rem;color:#555;">${(log.fecha||'').split(' ')[0]}</td>
                </tr>
            `).join('');
        } else {
            logsBody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:#444;">No hay actividad reciente</td></tr>';
        }

        const contactosEl = document.getElementById('contactos-list');
        if (data.lista_usuarios && data.lista_usuarios.length > 0) {
            contactosEl.innerHTML = data.lista_usuarios.map(user => {
                const nombre = user.nombre_completo || 'Sin nombre registrado';
                const telefono = user.telefono || 'Sin teléfono';
                const correo = user.correo_electronico || 'Sin correo';
                const pago = user.pago_estado === 'completado'
                    ? '<span class="ok">PAGÓ</span>'
                    : '<span class="pend">PENDIENTE</span>';

                return `
                    <div style="display:flex;justify-content:space-between;gap:14px;padding:14px 0;border-bottom:1px solid rgba(255,255,255,0.04);">
                        <div style="flex:1;">
                            <div style="font-size:0.88rem;color:#fff;font-weight:800;">${nombre}</div>
                            <div style="font-size:0.78rem;color:#aaa;margin-top:4px;">${user.nickname || 'Sin nickname'} · ID ${user.id}</div>
                            <div style="font-size:0.72rem;color:#666;margin-top:6px;word-break:break-all;">${user.wallet_address}</div>
                        </div>
                        <div style="flex:1;">
                            <div style="font-size:0.78rem;color:#ddd;">Tel: ${telefono}</div>
                            <div style="font-size:0.78rem;color:#ddd;margin-top:4px;">Correo: ${correo}</div>
                            <div style="font-size:0.75rem;margin-top:8px;">${pago}</div>
                        </div>
                    </div>
                `;
            }).join('');
        } else {
            contactosEl.innerHTML = '<p style="color:#444;font-size:0.8rem;text-align:center;padding:20px 0;">No hay usuarios para mostrar.</p>';
        }

    } catch (error) {
        console.error('Error administrativo:', error);
    } finally {
        document.getElementById('loading').style.display = 'none';
    }
}

// Botón manual activar clon (MEJORA #8)
function adminTreeDisplayName(nodeData) {
    return nodeData.display_name || nodeData.nickname || `Usuario ${nodeData.id}`;
}

function adminTreeInitials(nodeData) {
    const source = adminTreeDisplayName(nodeData).trim();
    if (!source) return 'RD';
    const parts = source.split(/\s+/).filter(Boolean);
    if (parts.length === 1) {
        return parts[0].slice(0, 2).toUpperCase();
    }
    return (parts[0][0] + parts[1][0]).toUpperCase();
}

function renderAdminTreeSummary(data) {
    const summary = data.resumen || {};
    const filtros = data.filtros || {};
    const root = filtros.root_resuelto || null;
    const box = document.getElementById('admin-tree-summary');
    if (!box) return;

    const chips = [
        `<span class="admin-tree-chip">Fase <strong>${filtros.fase_numero ?? 0}</strong></span>`,
        `<span class="admin-tree-chip">Ciclo <strong>${filtros.ciclo ?? 1}</strong></span>`,
        `<span class="admin-tree-chip">Nodos <strong>${summary.nodos ?? 0}</strong></span>`,
        `<span class="admin-tree-chip">Reales <strong>${summary.reales ?? 0}</strong></span>`,
        `<span class="admin-tree-chip">Clones <strong>${summary.clones ?? 0}</strong></span>`,
        `<span class="admin-tree-chip">Niveles <strong>${summary.profundidad ?? 0}</strong></span>`
    ];

    if (root) {
        chips.push(`<span class="admin-tree-chip">Raiz actual <strong>${adminTreeDisplayName(root)}</strong> · ID ${root.id}</span>`);
    }

    box.innerHTML = chips.join('');
}

function syncAdminTreeControls(data) {
    const filtros = data.filtros || {};
    const phaseSelect = document.getElementById('admin-tree-phase');
    const cycleSelect = document.getElementById('admin-tree-cycle');
    const rootInput = document.getElementById('admin-tree-root');

    if (phaseSelect) {
        phaseSelect.innerHTML = (filtros.fases || []).map(f => `
            <option value="${f.fase_numero}">${f.nombre || ('Fase ' + f.fase_numero)}</option>
        `).join('');
        phaseSelect.value = String(filtros.fase_numero ?? 0);
    }

    if (cycleSelect) {
        cycleSelect.innerHTML = (filtros.ciclos || []).map(c => `
            <option value="${c}">Ciclo ${c}</option>
        `).join('');
        cycleSelect.value = String(filtros.ciclo ?? 1);
    }

    if (rootInput && document.activeElement !== rootInput) {
        rootInput.value = filtros.root_query || '';
    }
}

function getAdminTreeColor(d) {
    if (d.data.es_raiz) return '#ffcc00';
    if (d.data.tipo_usuario === 'clon') return '#ff9800';
    if (d.data.pago_estado === 'completado') return '#00e676';
    if (d.data.pago_estado === 'pendiente') return '#ff5252';
    return '#00d2ff';
}

function renderAdminNetworkTree(treeData) {
    const container = document.getElementById('admin-network-tree');
    if (!container) return;

    if (!treeData) {
        container.innerHTML = '<div class="admin-tree-empty">No hay estructura disponible para esa fase/ciclo.</div>';
        return;
    }

    container.innerHTML = '';
    container.classList.remove('is-dragging');

    const root = d3.hierarchy(treeData, d => d.hijos && d.hijos.length ? d.hijos : null);
    const leafCount = Math.max(root.leaves().length, 1);
    const depthCount = Math.max(root.height + 1, 1);
    const containerWidth = container.clientWidth || 1200;
    const containerHeight = container.clientHeight || 720;
    const margin = { top: 90, right: 90, bottom: 120, left: 90 };
    const nodeHorizontalSpacing = leafCount > 14 ? 100 : leafCount > 8 ? 120 : 150;
    const nodeVerticalSpacing = depthCount > 4 ? 145 : 170;
    const layoutWidth = Math.max(720, leafCount * nodeHorizontalSpacing);
    const layoutHeight = Math.max(280, (depthCount - 1) * nodeVerticalSpacing);
    const rootRadius = 26;
    const childRadius = 18;

    const treeLayout = d3.tree().size([layoutWidth, layoutHeight]);
    treeLayout(root);

    const svg = d3.select(container)
        .append('svg')
        .attr('width', '100%')
        .attr('height', containerHeight)
        .attr('viewBox', `0 0 ${containerWidth} ${containerHeight}`)
        .attr('preserveAspectRatio', 'xMidYMid meet');

    const defs = svg.append('defs');
    const gradId = 'adminTreeGrad_' + Date.now();
    const gradient = defs.append('linearGradient')
        .attr('id', gradId)
        .attr('gradientUnits', 'userSpaceOnUse')
        .attr('x1', 0).attr('y1', 0)
        .attr('x2', 0).attr('y2', containerHeight);
    gradient.append('stop').attr('offset', '0%').attr('stop-color', '#9d00ff').attr('stop-opacity', 0.92);
    gradient.append('stop').attr('offset', '100%').attr('stop-color', '#00d2ff').attr('stop-opacity', 0.92);

    const viewport = svg.append('g');
    const g = viewport.append('g').attr('transform', `translate(${margin.left}, ${margin.top})`);

    const linkSegments = [];
    const nodeRadius = node => node.data.es_raiz ? rootRadius : childRadius;

    root.descendants().forEach(parent => {
        const children = parent.children || [];
        if (!children.length) return;

        const parentBottomY = parent.y + nodeRadius(parent) + 6;
        const childTopYs = children.map(child => child.y - nodeRadius(child) - 10);

        if (children.length === 1) {
            linkSegments.push({
                x1: parent.x,
                y1: parentBottomY,
                x2: children[0].x,
                y2: childTopYs[0]
            });
            return;
        }

        let branchY = parentBottomY + 20;
        const highestChildTop = Math.min(...childTopYs);
        if (branchY > highestChildTop - 18) {
            branchY = parentBottomY + Math.max(12, (highestChildTop - parentBottomY) * 0.35);
        }

        const childXs = children.map(child => child.x);
        linkSegments.push({ x1: parent.x, y1: parentBottomY, x2: parent.x, y2: branchY });
        linkSegments.push({ x1: Math.min(...childXs), y1: branchY, x2: Math.max(...childXs), y2: branchY });
        children.forEach((child, index) => {
            linkSegments.push({ x1: child.x, y1: branchY, x2: child.x, y2: childTopYs[index] });
        });
    });

    g.selectAll('.admin-link')
        .data(linkSegments)
        .enter()
        .append('path')
        .attr('class', 'admin-link')
        .attr('d', d => `M${d.x1},${d.y1} L${d.x2},${d.y2}`)
        .attr('fill', 'none')
        .attr('stroke', `url(#${gradId})`)
        .attr('stroke-width', 2.8)
        .attr('stroke-linecap', 'round')
        .attr('opacity', 0.95);

    const node = g.selectAll('.admin-node')
        .data(root.descendants())
        .enter()
        .append('g')
        .attr('class', 'admin-node')
        .attr('transform', d => `translate(${d.x},${d.y})`);

    node.append('circle')
        .attr('r', 0)
        .attr('fill', d => getAdminTreeColor(d))
        .attr('stroke', '#090911')
        .attr('stroke-width', 2)
        .style('filter', d => `drop-shadow(0 0 12px ${getAdminTreeColor(d)})`)
        .transition().duration(450)
        .attr('r', d => nodeRadius(d));

    node.append('text')
        .attr('text-anchor', 'middle')
        .attr('dy', '0.35em')
        .attr('font-size', d => d.data.es_raiz ? '10px' : '8px')
        .attr('font-weight', '800')
        .attr('fill', '#000')
        .text(d => adminTreeInitials(d.data));

    node.append('text')
        .attr('text-anchor', 'middle')
        .attr('dy', d => d.data.es_raiz ? '46px' : '38px')
        .attr('font-size', '10px')
        .attr('fill', '#c7ccda')
        .text(d => {
            const name = adminTreeDisplayName(d.data);
            return name.length > 18 ? name.substring(0, 18) + '…' : name;
        });

    node.append('text')
        .attr('text-anchor', 'middle')
        .attr('dy', d => d.data.es_raiz ? '-34px' : '-28px')
        .attr('font-size', d => d.data.es_raiz ? '10px' : '8px')
        .attr('fill', d => d.data.es_raiz ? '#ffcc00' : '#888')
        .text(d => d.data.es_raiz ? `Raiz · Tablero ${d.data.tablero_actual || 'A'}` : `ID ${d.data.id}`);

    const legendItems = [
        { color: '#ffcc00', label: 'Raiz' },
        { color: '#00e676', label: 'Pago completo' },
        { color: '#ff5252', label: 'Pendiente' },
        { color: '#ff9800', label: 'Clon' },
        { color: '#00d2ff', label: 'Nuevo' }
    ];
    const legendSpacing = 112;
    const legendWidth = (legendItems.length - 1) * legendSpacing + 90;
    const legendX = Math.max((containerWidth - legendWidth) / 2, 20);
    const legend = svg.append('g').attr('transform', `translate(${legendX}, ${containerHeight - 34})`);
    legendItems.forEach((item, index) => {
        legend.append('circle').attr('cx', index * legendSpacing).attr('cy', 0).attr('r', 5).attr('fill', item.color);
        legend.append('text')
            .attr('x', index * legendSpacing + 10)
            .attr('y', 4)
            .attr('font-size', '10px')
            .attr('fill', '#777')
            .text(item.label);
    });

    _adminTreeZoom = d3.zoom()
        .scaleExtent([0.35, 2.2])
        .on('start', () => container.classList.add('is-dragging'))
        .on('zoom', event => viewport.attr('transform', event.transform))
        .on('end', () => container.classList.remove('is-dragging'));

    svg.call(_adminTreeZoom);
    _adminTreeSvg = svg;

    const bounds = g.node().getBBox();
    const paddedWidth = bounds.width + margin.left + margin.right;
    const paddedHeight = bounds.height + margin.top + margin.bottom;
    const scale = Math.min(
        (containerWidth - 40) / Math.max(paddedWidth, 1),
        (containerHeight - 48) / Math.max(paddedHeight, 1),
        1.08
    );
    const tx = (containerWidth - bounds.width * scale) / 2 - bounds.x * scale;
    const ty = Math.max(18, (containerHeight - bounds.height * scale) / 2 - bounds.y * scale - 16);
    _adminTreeInitialTransform = d3.zoomIdentity.translate(tx, ty).scale(scale);
    svg.call(_adminTreeZoom.transform, _adminTreeInitialTransform);
}

async function loadAdminNetworkTree(options = {}) {
    const container = document.getElementById('admin-network-tree');
    const phaseSelect = document.getElementById('admin-tree-phase');
    const cycleSelect = document.getElementById('admin-tree-cycle');
    const rootInput = document.getElementById('admin-tree-root');

    if (!container) return;

    const faseNumero = options.fase_numero ?? (parseInt(phaseSelect?.value || '0', 10) || 0);
    const ciclo = options.ciclo ?? (parseInt(cycleSelect?.value || '1', 10) || 1);
    const root = options.root ?? (rootInput?.value || '').trim();

    container.innerHTML = '<div class="admin-tree-empty">Construyendo mapa general de la red...</div>';

    try {
        const params = new URLSearchParams();
        params.set('fase_numero', String(faseNumero));
        params.set('ciclo', String(ciclo));
        if (root) params.set('root', root);

        const res = await fetch(`radix_api/admin_network_tree.php?${params.toString()}`);
        const data = await res.json();

        if (!data.success) {
            container.innerHTML = `<div class="admin-tree-empty">${data.error || 'No se pudo cargar el arbol.'}</div>`;
            return;
        }

        syncAdminTreeControls(data);
        renderAdminTreeSummary(data);
        renderAdminNetworkTree(data.arbol);
    } catch (error) {
        console.error('Admin network tree error:', error);
        container.innerHTML = '<div class="admin-tree-empty">Error al cargar el arbol administrativo.</div>';
    }
}

function aplicarFiltrosArbolAdmin() {
    loadAdminNetworkTree();
}

function limpiarFiltroArbolAdmin() {
    const rootInput = document.getElementById('admin-tree-root');
    if (rootInput) rootInput.value = '';
    loadAdminNetworkTree();
}

function resetZoomAdminTree() {
    if (_adminTreeSvg && _adminTreeZoom && _adminTreeInitialTransform) {
        _adminTreeSvg.transition().duration(350).call(_adminTreeZoom.transform, _adminTreeInitialTransform);
    }
}

async function activarClonManual() {
    const resultEl = document.getElementById('clon-result');
    resultEl.style.color = '#aaa';
    resultEl.innerText = '⏳ Ejecutando activación...';

    try {
        const res  = await fetch('radix_api/admin_activar_clon.php', { method: 'POST' });
        const data = await res.json();

        resultEl.style.color = data.success ? '#00e676' : '#ff5252';
        resultEl.innerText   = data.resultado || data.error;

        if (data.success) {
            setTimeout(() => {
                loadAdminStats();
                loadAdminNetworkTree();
            }, 1500);
        }

    } catch (e) {
        resultEl.style.color = '#ff5252';
        resultEl.innerText = '❌ Error de conexión.';
    }
}

async function procesarRetiro(retiroId, accion) {
    const etiqueta = accion === 'aprobar' ? 'APROBAR' : 'RECHAZAR';
    let notas = '';

    if (accion === 'rechazar') {
        notas = prompt('Motivo del rechazo (opcional):') || '';
    }

    if (!confirm(`¿Confirmas ${etiqueta} el retiro #${retiroId}?`)) return;

    const el = document.getElementById(`retiro-${retiroId}`);
    if (el) el.style.opacity = '0.4';

    try {
        const fd = new FormData();
        fd.append('retiro_id', retiroId);
        fd.append('accion', accion);
        fd.append('notas', notas);

        const res  = await fetch('radix_api/procesar_retiro.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.success) {
            if (el) {
                el.style.opacity = '1';
                el.innerHTML = `<div style="width:100%;text-align:center;padding:10px 0;font-size:0.82rem;color:${accion==='aprobar'?'#00e676':'#ff5252'};">
                    ${accion === 'aprobar' ? '✅ Aprobado y notificado' : '❌ Rechazado y notificado'}
                </div>`;
            }
            setTimeout(() => loadAdminStats(), 2000);
        } else {
            if (el) el.style.opacity = '1';
            alert('Error: ' + (data.error || 'No se pudo procesar'));
        }
    } catch(e) {
        if (el) el.style.opacity = '1';
        alert('Error de conexión.');
    }
}

window.onload = async () => {
    await loadAdminStats();
    await loadAdminNetworkTree();

    const phaseSelect = document.getElementById('admin-tree-phase');
    const cycleSelect = document.getElementById('admin-tree-cycle');
    const rootInput = document.getElementById('admin-tree-root');

    if (phaseSelect) {
        phaseSelect.addEventListener('change', () => loadAdminNetworkTree({ fase_numero: parseInt(phaseSelect.value || '0', 10) || 0 }));
    }
    if (cycleSelect) {
        cycleSelect.addEventListener('change', () => loadAdminNetworkTree());
    }
    if (rootInput) {
        rootInput.addEventListener('keydown', event => {
            if (event.key === 'Enter') {
                event.preventDefault();
                loadAdminNetworkTree();
            }
        });
    }
};
</script>
</body>
</html>
