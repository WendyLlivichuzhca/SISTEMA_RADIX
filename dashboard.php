<?php
// dashboard.php — RADIX Phase 0
session_start();
require_once 'radix_api/config.php';

if (empty($_SESSION['radix_wallet'])) {
    header("Location: index.html");
    exit;
}
$user_wallet = $_SESSION['radix_wallet'];

// Obtener datos del usuario
$stmt = $pdo->prepare("SELECT id, tipo_usuario, nickname FROM usuarios WHERE wallet_address = ?");
$stmt->execute([$user_wallet]);
$user_info = $stmt->fetch();
$es_master = ($user_info && $user_info['tipo_usuario'] === 'master');
$nickname = $user_info ? $user_info['nickname'] : 'Socio';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RADIX — Panel de Control</title>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <style>
        /* RADIX V3.2 — Estilos Premium Restaurados */
        .dashboard-container { max-width: 1100px; margin: 0 auto; }
        
        .scoreboard { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 30px; }
        .sb { background: #12121a; border: 1px solid #2a2a3a; border-radius: 16px; padding: 20px; text-align: center; transition: 0.3s; position: relative; overflow: hidden; }
        .sb:hover { border-color: var(--primary); transform: translateY(-3px); box-shadow: 0 10px 20px rgba(0,0,0,0.4); }
        .sb .lbl { font-size: 0.65rem; color: #555; text-transform: uppercase; letter-spacing: 1.5px; margin-bottom: 8px; display: block; }
        .sb .num { font-size: 2rem; font-weight: 800; line-height: 1; margin-bottom: 5px; }
        
        .sb-purple { border-left: 3px solid var(--primary); } .sb-purple .num { color: var(--primary); }
        .sb-cyan   { border-left: 3px solid var(--secondary); } .sb-cyan .num { color: var(--secondary); }
        .sb-green  { border-left: 3px solid var(--accent); } .sb-green .num { color: var(--accent); }
        .sb-white  { border-left: 3px solid #fff; } .sb-white .num { color: #fff; }

        .master-card { background: #12121a; border: 1px solid #2a2a3a; border-radius: 18px; padding: 25px; margin-bottom: 20px; }
        .master-card h3 { font-size: 0.9rem; color: #fff; margin-bottom: 20px; text-transform: uppercase; letter-spacing: 1px; display: flex; align-items: center; gap: 10px; }
        .master-card h3::before { content: ''; width: 4px; height: 16px; background: var(--primary); border-radius: 10px; }

        /* Progreso Circular/Línea Refinado */
        .progress-container { position: relative; padding: 40px 10%; }
        .progress-track { height: 4px; background: #222; border-radius: 10px; position: relative; }
        .progress-bar-fill { position: absolute; height: 100%; background: linear-gradient(90deg, var(--primary), var(--secondary)); border-radius: 10px; width: 0%; transition: 1s ease; box-shadow: 0 0 15px var(--primary); }
        .nodes-row { display: flex; justify-content: space-between; margin-top: -22px; position: relative; width: 100%; }
        
        .phase-node { width: 44px; height: 44px; border-radius: 50%; background: #0a0a0f; border: 2px solid #2a2a3a; display: flex; align-items: center; justify-content: center; font-weight: 800; transition: 0.5s; z-index: 10; color: #444; }
        .phase-node.current { background: var(--primary); border-color: #fff; color: #fff; box-shadow: 0 0 20px var(--primary); animation: pulse 2s infinite; }
        .phase-node.completed { background: var(--secondary); border-color: #fff; color: #fff; }
        
        @keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(157,0,255,0.7); } 70% { box-shadow: 0 0 0 10px rgba(157,0,255,0); } 100% { box-shadow: 0 0 0 0 rgba(157,0,255,0); } }

        <?php if ($es_master): ?>
        /* Solo para Master */
        .widgets-master { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .master-grid-layout { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; }
        .master-badge { background: linear-gradient(90deg, var(--primary), var(--secondary)); color: #000; padding: 6px 14px; border-radius: 8px; font-weight: 800; font-size: 0.65rem; text-transform: uppercase; }
        .master-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        .master-table th { text-align: left; padding: 12px; color: #555; border-bottom: 1px solid #2a2a3a; }
        .master-table td { padding: 14px 12px; border-bottom: 1px solid #1a1a24; color: #ccc; }
        <?php endif; ?>
    </style>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/d3/7.8.5/d3.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body>

    <div id="toast-container" style="position:fixed; top:20px; right:20px; z-index:9999; display:flex; flex-direction:column; gap:10px; pointer-events:none;"></div>
    <div id="loading-overlay">Cargando Sistema RADIX...</div>

    <!-- MODAL DE RETIRO -->
    <div id="retiro-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.85); z-index:1000; align-items:center; justify-content:center;">
        <div style="background:#12121a; border:1px solid rgba(157,0,255,0.3); border-radius:20px; padding:36px; max-width:460px; width:90%; position:relative;">
            <button onclick="cerrarRetiro()" style="position:absolute;top:16px;right:16px;background:none;border:none;color:#555;font-size:1.4rem;cursor:pointer;">✕</button>
            <h3 style="font-size:1.1rem; margin-bottom:6px; color:#fff;">💸 Solicitar Retiro</h3>
            <p style="font-size:0.8rem; color:#666; margin-bottom:20px;">Retiro manual vía USDT TRC-20.</p>
            <div style="background:#0a0a12; border-radius:12px; padding:16px; margin-bottom:20px;">
                <div style="font-size:0.7rem; color:#555; text-transform:uppercase; margin-bottom:4px;">Saldo disponible</div>
                <div id="retiro-saldo" style="font-size:1.8rem; font-weight:800; color:var(--accent);">$0.00 USDT</div>
            </div>
            <div id="historial-list" style="max-height:180px; overflow-y:auto; margin-bottom:20px; font-size:0.8rem;"></div>
            <button id="btn-solicitar-retiro" onclick="solicitarRetiro()" style="width:100%; padding:14px; background:var(--accent); border:none; border-radius:12px; color:#000; font-weight:800; font-size:0.95rem; cursor:pointer;">CONFIRMAR RETIRO</button>
            <div id="retiro-status" style="margin-top:10px; font-size:0.8rem; text-align:center;"></div>
        </div>
    </div>

    <!-- MODAL DE ONBOARDING (3 pasos) -->
    <div id="onboarding-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.92); z-index:2000; align-items:center; justify-content:center;">
        <div style="background:#0d0d18; border:1px solid rgba(157,0,255,0.3); border-radius:24px; padding:36px; max-width:480px; width:92%; position:relative;">
            <button onclick="cerrarOnboarding()" style="position:absolute;top:14px;right:14px;background:none;border:none;color:#555;font-size:1.4rem;cursor:pointer;">✕</button>

            <!-- Indicadores de progreso -->
            <div style="display:flex; justify-content:center; gap:8px; margin-bottom:28px;">
                <span id="ob-dot-1" style="width:9px;height:9px;border-radius:50%;background:var(--primary);transition:0.3s;"></span>
                <span id="ob-dot-2" style="width:9px;height:9px;border-radius:50%;background:#2a2a3a;transition:0.3s;"></span>
                <span id="ob-dot-3" style="width:9px;height:9px;border-radius:50%;background:#2a2a3a;transition:0.3s;"></span>
            </div>

            <!-- Paso 1: Bienvenida -->
            <div id="ob-step-1" class="ob-step">
                <div style="text-align:center; font-size:3rem; margin-bottom:14px;">🌱</div>
                <h3 style="text-align:center; color:#fff; margin-bottom:10px;">¡Bienvenido a RADIX!</h3>
                <p style="text-align:center; color:#888; line-height:1.7;">Eres parte de una red <strong style="color:#9d00ff;">3×1</strong> en TRON blockchain.<br>Cada persona que invites activa tu ciclo de ganancias en USDT.</p>
            </div>

            <!-- Paso 2: Cómo funciona -->
            <div id="ob-step-2" class="ob-step" style="display:none;">
                <div style="text-align:center; font-size:2.5rem; margin-bottom:14px;">📊</div>
                <h3 style="text-align:center; color:#fff; margin-bottom:14px;">¿Cómo funciona?</h3>
                <div style="background:#0a0a12; border-radius:14px; padding:6px 12px;">
                    <!-- Tablero A -->
                    <div style="display:flex; justify-content:space-between; align-items:center; padding:11px 4px; border-bottom:1px solid #1a1a28;">
                        <div style="display:flex; align-items:center; gap:8px;">
                            <span style="background:rgba(157,0,255,0.15); border:1px solid rgba(157,0,255,0.4); color:#9d00ff; font-size:0.7rem; font-weight:900; padding:2px 8px; border-radius:6px;">A</span>
                            <span style="color:#ddd; font-weight:700; font-size:0.9rem;">Tablero A</span>
                        </div>
                        <div style="text-align:right;">
                            <div style="color:#aaa; font-size:0.75rem;">Invita 3 personas</div>
                            <div style="color:#00e676; font-weight:800; font-size:0.95rem;">+$10 USDT</div>
                        </div>
                    </div>
                    <!-- Tablero B -->
                    <div style="display:flex; justify-content:space-between; align-items:center; padding:11px 4px; border-bottom:1px solid #1a1a28;">
                        <div style="display:flex; align-items:center; gap:8px;">
                            <span style="background:rgba(0,210,255,0.12); border:1px solid rgba(0,210,255,0.35); color:#00d2ff; font-size:0.7rem; font-weight:900; padding:2px 8px; border-radius:6px;">B</span>
                            <span style="color:#ddd; font-weight:700; font-size:0.9rem;">Tablero B</span>
                        </div>
                        <div style="text-align:right;">
                            <div style="color:#aaa; font-size:0.75rem;">Sus 3 también invitan</div>
                            <div style="color:#00e676; font-weight:800; font-size:0.95rem;">+$20 USDT</div>
                        </div>
                    </div>
                    <!-- Tablero C -->
                    <div style="display:flex; justify-content:space-between; align-items:center; padding:11px 4px;">
                        <div style="display:flex; align-items:center; gap:8px;">
                            <span style="background:rgba(0,230,118,0.12); border:1px solid rgba(0,230,118,0.35); color:#00e676; font-size:0.7rem; font-weight:900; padding:2px 8px; border-radius:6px;">C</span>
                            <span style="color:#ddd; font-weight:700; font-size:0.9rem;">Tablero C</span>
                        </div>
                        <div style="text-align:right;">
                            <div style="color:#aaa; font-size:0.75rem;">Red completa</div>
                            <div style="color:#00e676; font-weight:800; font-size:0.95rem;">+$40 USDT neto</div>
                        </div>
                    </div>
                </div>
                <!-- Total -->
                <div style="display:flex; justify-content:space-between; align-items:center; background:rgba(157,0,255,0.08); border:1px solid rgba(157,0,255,0.2); border-radius:10px; padding:10px 14px; margin-top:10px;">
                    <span style="color:#aaa; font-size:0.82rem;">💰 Ganancia total por ciclo</span>
                    <span style="color:#fff; font-weight:900; font-size:1rem;">$70 USDT</span>
                </div>
                <p style="text-align:center; color:#555; font-size:0.75rem; margin-top:10px;">🤖 El sistema activa Agentes IA si hay huecos en tu red.</p>
            </div>

            <!-- Paso 3: Primer pago -->
            <div id="ob-step-3" class="ob-step" style="display:none;">
                <div style="text-align:center; font-size:3rem; margin-bottom:14px;">💸</div>
                <h3 style="text-align:center; color:#fff; margin-bottom:10px;">Activa tu posición</h3>
                <p style="text-align:center; color:#888; line-height:1.7;">Envía <strong style="color:#00e676;">10 USDT (TRC-20)</strong> a la wallet central de RADIX cuando tu patrocinador te registre.</p>
                <p style="text-align:center; color:#555; font-size:0.78rem; margin-top:10px;">Aparecerá un aviso de &ldquo;Pago Pendiente&rdquo; en tu panel cuando tu posición esté lista.</p>
            </div>

            <!-- Botones de navegación -->
            <div style="display:flex; gap:10px; margin-top:28px;">
                <button id="ob-btn-back" onclick="obNavegar(-1)" style="flex:1; padding:12px; background:#1a1a28; border:1px solid #333; border-radius:12px; color:#aaa; cursor:pointer; display:none;">← Atrás</button>
                <button id="ob-btn-next" onclick="obNavegar(1)" style="flex:2; padding:12px; background:var(--primary); border:none; border-radius:12px; color:#fff; font-weight:700; cursor:pointer;">Siguiente →</button>
            </div>
        </div>
    </div>

    <!-- SIDEBAR -->
    <aside>
        <div class="logo">RADIX SYSTEM</div>
        <nav>
            <a href="#" class="nav-item active" id="nav-dashboard" onclick="switchMasterSection('dashboard')">📊 Dashboard</a>
            <?php if ($es_master): ?>
                <a href="#" class="nav-item" id="nav-usuarios" onclick="switchMasterSection('usuarios')">👥 Usuarios Reales</a>
                <a href="#" class="nav-item" id="nav-retiros" onclick="switchMasterSection('retiros')">💰 Pagos Pendientes</a>
                <a href="#" class="nav-item" id="nav-clones" onclick="switchMasterSection('clones')">🤖 Control de Clones</a>
                <a href="#" class="nav-item" id="nav-auditoria" onclick="switchMasterSection('auditoria')">📜 Registro de Auditoría</a>
            <?php else: ?>
                <a href="#" class="nav-item" onclick="document.getElementById('team-list')?.closest('.master-card')?.scrollIntoView({behavior:'smooth'}); return false;">👥 Mi Equipo</a>
                <a href="#" class="nav-item" onclick="document.getElementById('val-clones')?.closest('.sb')?.scrollIntoView({behavior:'smooth'}); return false;">🤖 Mis Agentes IA</a>
            <?php endif; ?>
            <a href="radix_api/session_logout.php" class="nav-item" style="margin-top:auto; color:#ff4444;">🚪 Cerrar Sesión</a>
        </nav>
    </aside>

    <!-- CONTENT -->
    <main>
        <header>
            <div>
                <h2>Hola, <?php echo htmlspecialchars($nickname); ?></h2>
                <div style="display:flex; align-items:center; gap:10px;">
                    <p id="wallet-address-display" style="color:#666; font-size:0.8rem;"></p>
                    <?php if ($es_master): ?> <span class="master-badge">Modo Tesorería Central</span> <?php endif; ?>
                </div>
            </div>
            <div class="user-info">
                <?php if (!$es_master): ?> <button class="btn-withdraw" id="btn-retiro" onclick="abrirRetiro()">RETIRAR</button> <?php endif; ?>
                <div class="avatar" id="avatar-circle">?</div>
            </div>
        </header>

        <div id="section-dashboard" class="master-section active">
            <?php if ($es_master): ?>
                <!-- MASTER V3 LAYOUT -->
                <div class="widgets-master">
                    <div class="widget"><h4>Tesorería (Agentes IA)</h4><div id="val-balance" class="value">$0.00</div><div class="trend">💰 Fondo Clones</div></div>
                    <div class="widget"><h4>Reserva Fase 1 (Pool)</h4><div id="val-fase" class="value">$0.00</div><div class="trend">Acumulado Saltos</div></div>
                    <div class="widget"><h4>Usuarios Reales</h4><div id="val-usuarios-reales" class="value">0</div><div class="trend">Crecimiento Orgánico</div></div>
                    <div class="widget"><h4>Ganancia Master</h4><div id="val-master-earnings" class="value">$0.00</div><div class="trend">Utilidad ID #1</div></div>
                </div>

                <div class="master-grid-top">
                    <div class="master-card">
                        <h4>Crecimiento Diario</h4>
                        <div style="height:300px;"><canvas id="grafica-crecimiento"></canvas></div>
                    </div>
                    <div style="display:flex; flex-direction:column; gap:20px;">
                        <div class="master-card" style="flex:1;">
                            <h4>Distribución</h4>
                            <div style="margin-bottom:10px;"><div style="display:flex; justify-content:space-between; font-size:0.7rem;"><span>Tablero A</span><span id="dist-a-val">0</span></div><div style="height:4px; background:#222;"><div id="dist-a-bar" style="height:100%; background:#9d00ff; width:0%;"></div></div></div>
                            <div style="margin-bottom:10px;"><div style="display:flex; justify-content:space-between; font-size:0.7rem;"><span>Tablero B</span><span id="dist-b-val">0</span></div><div style="height:4px; background:#222;"><div id="dist-b-bar" style="height:100%; background:#00d2ff; width:0%;"></div></div></div>
                            <div style="margin-bottom:10px;"><div style="display:flex; justify-content:space-between; font-size:0.7rem;"><span>Tablero C</span><span id="dist-c-val">0</span></div><div style="height:4px; background:#222;"><div id="dist-c-bar" style="height:100%; background:#00e676; width:0%;"></div></div></div>
                            <h4 style="margin-top:20px;">Ratio Reales/Clones</h4>
                            <div style="height:8px; background:#222; border-radius:4px;"><div id="reales-clones-bar" style="height:100%; background:var(--primary); width:50%;"></div></div>
                        </div>
                        <div class="master-card">
                            <h4>Control</h4>
                            <button class="btn-master" onclick="activarClonManual()">🚀 ACTIVAR AGENTE IA</button>
                            <div id="clon-result" style="margin-top:10px; font-size:0.7rem;"></div>
                        </div>
                    </div>
                </div>

                <div class="master-card" style="margin-bottom:20px;">
                    <h4>📜 Libro Mayor de Tesorería</h4>
                    <div style="overflow-x:auto;"><table class="master-table"><thead><tr><th>Fecha</th><th>Concepto</th><th>Monto</th><th>Estado</th></tr></thead><tbody id="master-ledger-body"></tbody></table></div>
                </div>

                <div class="master-grid-bottom">
                    <div class="master-card"><h4>🤖 Historial IA</h4><table class="master-table"><thead><tr><th>Beneficiario</th><th>Costo</th><th>Fecha</th></tr></thead><tbody id="master-clones-history-body"></tbody></table></div>
                    <div class="master-card"><h4>💸 Retiros Pendientes</h4><div id="master-retiros-mini-list"></div></div>
                </div>

                <div class="master-card"><h4>📊 Actividad del Sistema</h4><table class="master-table"><thead><tr><th>Acción</th><th>Detalles</th><th>Fecha</th></tr></thead><tbody id="master-activity-body"></tbody></table></div>

            <?php else: ?>
                <!-- USER LAYOUT V3.2 — PREMIUM RESTORATION -->
                <div id="pago-pendiente-box" class="pago-pendiente-box" style="display:none;">
                    <h4>⏳ Pago pendiente</h4><div class="radix-wallet" id="pp-wallet-patron">...</div>
                    <div class="tx-input-row">
                        <input type="text" id="tx-hash-input" placeholder="Hash TXID (0x...)">
                        <button onclick="confirmarPago()">VERIFICAR</button>
                    </div>
                </div>

                <div class="scoreboard">
                    <div class="sb sb-cyan"><span class="lbl">SALDO ACTUAL</span><div id="val-balance" class="num">$0.00</div></div>
                    <div class="sb sb-cyan"><span class="lbl">RESERVA FASE 1</span><div id="val-reserva" class="num">$0.00</div></div>
                    <div class="sb sb-purple"><span class="lbl">AGENTES IA</span><div id="val-clones" class="num">0</div></div>
                    <div class="sb sb-white"><span class="lbl">TABLERO ACTUAL</span><div id="val-fase" class="num" style="font-size:1.4rem;">...</div></div>
                    <div class="sb sb-green"><span class="lbl">EQUIPO DIRECTO</span><div id="val-equipo-count" class="num">0</div></div>
                    <div class="sb sb-white"><span class="lbl">LINK DE REFERIDO</span>
                        <div style="display:flex; gap:8px; margin-top:5px;">
                            <input type="text" id="ref-link-input" readonly style="background:rgba(0,0,0,0.3); border:1px solid #2a2a3a; color:#888; padding:8px; border-radius:6px; flex:1; font-size:0.7rem;">
                            <button onclick="copyRefLink()" style="background:var(--primary); color:#fff; border:none; border-radius:6px; cursor:pointer; font-size:0.6rem; font-weight:800; padding:0 10px;">COPIAR</button>
                        </div>
                    </div>
                </div>

                <div class="master-card">
                    <h3>Progreso de Niveles</h3>
                    <div class="progress-container">
                        <div class="progress-track">
                            <div id="progress-fill" class="progress-bar-fill"></div>
                        </div>
                        <div class="nodes-row">
                            <div id="node-a" class="phase-node">A</div>
                            <div id="node-b" class="phase-node">B</div>
                            <div id="node-c" class="phase-node">C</div>
                        </div>
                    </div>
                </div>

                <div style="display:grid; grid-template-columns: 1fr 1.5fr; gap:20px;">
                    <div class="master-card" style="height:fit-content;">
                        <h3>Equipo Reciente</h3>
                        <div id="team-list" style="max-height:220px; overflow-y:auto; font-size:0.85rem;"></div>
                    </div>
                    <div class="master-card">
                        <h3>Estructura de Red Visual</h3>
                        <div id="network-tree" style="min-height:300px; background:rgba(0,0,0,0.2); border-radius:15px; border:1px dashed #2a2a3a; display:flex; align-items:center; justify-content:center; color:#333;"></div>
                    </div>
                </div>

                <!-- SECCIÓN TELEGRAM -->
                <div class="master-card" style="margin-top:20px;">
                    <h3>🔔 Notificaciones Telegram</h3>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; align-items:start;">

                        <!-- Instrucciones -->
                        <div style="background:#0a0a12; border-radius:14px; padding:18px;">
                            <div style="font-size:1.8rem; text-align:center; margin-bottom:10px;">📱</div>
                            <p style="color:#aaa; font-size:0.82rem; line-height:1.7; margin-bottom:12px;">
                                Recibe alertas automáticas en Telegram cuando:
                            </p>
                            <ul style="list-style:none; padding:0; margin:0; display:flex; flex-direction:column; gap:8px;">
                                <li style="display:flex; align-items:center; gap:8px; color:#ccc; font-size:0.8rem;">
                                    <span style="background:rgba(157,0,255,0.2); border-radius:50%; width:24px; height:24px; display:flex; align-items:center; justify-content:center; font-size:0.75rem;">🏆</span>
                                    Completes un Tablero
                                </li>
                                <li style="display:flex; align-items:center; gap:8px; color:#ccc; font-size:0.8rem;">
                                    <span style="background:rgba(0,210,255,0.15); border-radius:50%; width:24px; height:24px; display:flex; align-items:center; justify-content:center; font-size:0.75rem;">👤</span>
                                    Un referido se una a tu red
                                </li>
                                <li style="display:flex; align-items:center; gap:8px; color:#ccc; font-size:0.8rem;">
                                    <span style="background:rgba(0,230,118,0.15); border-radius:50%; width:24px; height:24px; display:flex; align-items:center; justify-content:center; font-size:0.75rem;">🤖</span>
                                    Se active un Agente IA para ti
                                </li>
                            </ul>
                        </div>

                        <!-- Formulario de vinculación -->
                        <div>
                            <!-- Estado: no vinculado -->
                            <div id="tg-no-vinculado">
                                <p style="color:#888; font-size:0.8rem; line-height:1.7; margin-bottom:14px;">
                                    <strong style="color:#fff;">Paso 1:</strong> Abre Telegram y busca
                                    <a href="https://t.me/RADIXNotificaciones_bot" target="_blank" style="color:#9d00ff; text-decoration:none; font-weight:700;">@RADIXNotificaciones_bot</a><br>
                                    <strong style="color:#fff;">Paso 2:</strong> Escribe <code style="background:#1a1a28; padding:2px 6px; border-radius:4px; color:#00d2ff;">/start</code> — el bot te dará tu ID.<br>
                                    <strong style="color:#fff;">Paso 3:</strong> Pega ese número aquí abajo:
                                </p>
                                <div style="display:flex; gap:10px; margin-bottom:10px;">
                                    <input type="text" id="tg-chat-id-input" placeholder="Ej: 123456789"
                                        style="flex:1; background:#0a0a12; border:1px solid #2a2a3a; color:#fff; padding:12px 14px; border-radius:10px; font-size:0.9rem; outline:none;"
                                        oninput="this.style.borderColor='#9d00ff'">
                                    <button onclick="vincularTelegram()"
                                        style="background:linear-gradient(135deg,#9d00ff,#00d2ff); border:none; border-radius:10px; color:#fff; font-weight:800; font-size:0.82rem; padding:0 18px; cursor:pointer; white-space:nowrap; transition:0.3s;"
                                        onmouseover="this.style.opacity='0.85'" onmouseout="this.style.opacity='1'">
                                        VINCULAR
                                    </button>
                                </div>
                                <div id="tg-status" style="font-size:0.78rem; color:#555; min-height:16px;"></div>
                            </div>

                            <!-- Estado: ya vinculado -->
                            <div id="tg-vinculado" style="display:none;">
                                <div style="background:rgba(0,230,118,0.08); border:1px solid rgba(0,230,118,0.25); border-radius:14px; padding:18px; text-align:center;">
                                    <div style="font-size:2rem; margin-bottom:8px;">✅</div>
                                    <p style="color:#00e676; font-weight:700; margin-bottom:4px;">¡Telegram vinculado!</p>
                                    <p style="color:#555; font-size:0.75rem; margin-bottom:14px;">Recibirás notificaciones automáticamente.</p>
                                    <button onclick="desvincularTelegram()"
                                        style="background:transparent; border:1px solid #333; border-radius:8px; color:#555; font-size:0.72rem; padding:6px 14px; cursor:pointer;">
                                        Desvincular
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

<?php endif; ?>
        </div>

        <?php if ($es_master): ?>
            <!-- SPA SECTIONS FOR MASTER -->
            <div id="section-usuarios" class="master-section"><div class="master-card"><h4>👥 Gestión Usuarios</h4><table class="master-table"><thead><tr><th>ID</th><th>Nick</th><th>Wallet</th></tr></thead><tbody id="master-users-body"></tbody></table></div></div>
            <div id="section-clones" class="master-section"><div class="master-card"><h4>🤖 Todos los Agentes</h4><table class="master-table"><thead><tr><th>ID</th><th>Beneficiario</th><th>Fecha</th></tr></thead><tbody id="master-clones-full-body"></tbody></table></div></div>
            <div id="section-retiros" class="master-section"><div class="master-card"><h4>💰 Retiros Full</h4><div id="master-retiros-full-list"></div></div></div>
            <div id="section-auditoria" class="master-section"><div class="master-card"><h4>📜 Auditoría Completa</h4><table class="master-table"><thead><tr><th>Acción</th><th>Fecha</th></tr></thead><tbody id="master-auditoria-full-body"></tbody></table></div></div>
        <?php endif; ?>
    </main>

    <script src="assets/js/dashboard.js?v=<?php echo time(); ?>"></script>
</body>
</html>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     