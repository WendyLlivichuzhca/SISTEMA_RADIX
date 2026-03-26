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

        #loading { position: fixed; inset: 0; background: #000; z-index: 1000; display: flex; align-items: center; justify-content: center; font-weight: 800; color: var(--gold); }
        #clon-result { font-size:0.8rem; margin-top:10px; min-height:18px; }

        @media(max-width:900px) { .metrics { grid-template-columns:1fr 1fr; } .layout-3,.layout-2 { grid-template-columns:1fr; } }
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
            <div class="sub">Fondos para inyectar clones</div>
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
            <h4>GANANCIA MASTER</h4>
            <div class="value" id="stat-master">$0.00</div>
            <div class="sub">Utilidad acumulada ID#1</div>
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

            <h3>Ratio Reales / Clones</h3>
            <div style="display:flex;justify-content:space-between;font-size:0.8rem;margin-bottom:8px;">
                <span style="color:var(--accent);">👤 Reales: <strong id="stat-reales">0</strong></span>
                <span style="color:var(--primary);">🤖 Clones: <strong id="stat-clones">0</strong></span>
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

</main>

<script>
let _chartInstance = null;

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

    } catch (error) {
        console.error('Error administrativo:', error);
    } finally {
        document.getElementById('loading').style.display = 'none';
    }
}

// Botón manual activar clon (MEJORA #8)
async function activarClonManual() {
    const resultEl = document.getElementById('clon-result');
    resultEl.style.color = '#aaa';
    resultEl.innerText = '⏳ Ejecutando activación...';

    try {
        const res  = await fetch('radix_api/admin_activar_clon.php', { method: 'POST' });
        const data = await res.json();

        resultEl.style.color = data.success ? '#00e676' : '#ff5252';
        resultEl.innerText   = data.resultado || data.error;

        if (data.success) setTimeout(() => loadAdminStats(), 1500);

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

window.onload = loadAdminStats;
</script>
</body>
</html>
