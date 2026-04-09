<?php
// dashboard.php — RADIX Phase 0
header('Content-Type: text/html; charset=UTF-8');
session_start();
require_once 'radix_api/config.php';

if (empty($_SESSION['radix_wallet'])) {
    header("Location: index.php");
    exit;
}
$user_wallet = $_SESSION['radix_wallet'];

// Hacer tolerante el dashboard si la migración de nombre_completo aún no existe
$stmt_cols = $pdo->prepare("
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'usuarios'
      AND COLUMN_NAME = 'nombre_completo'
");
$stmt_cols->execute();
$has_nombre_completo = (bool)$stmt_cols->fetchColumn();
$displayNameSelect = $has_nombre_completo
    ? "COALESCE(NULLIF(nombre_completo, ''), nickname) AS display_name"
    : "nickname AS display_name";

// Obtener datos del usuario
$stmt = $pdo->prepare("
    SELECT
        id,
        tipo_usuario,
        nickname,
        {$displayNameSelect}
    FROM usuarios
    WHERE wallet_address = ?
");
$stmt->execute([$user_wallet]);
$user_info = $stmt->fetch();
$es_master = ($user_info && $user_info['tipo_usuario'] === 'master');
$nickname = $user_info ? ($user_info['display_name'] ?: $user_info['nickname']) : 'Socio';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RADIX — Panel de Control</title>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/assets/css/dashboard.css'); ?>">
    <style>
        /* RADIX V3.2 — Estilos Premium Restaurados */
        .dashboard-container { max-width: 1100px; margin: 0 auto; }
        
        /* Scoreboard — 5 cards normales + 1 ref-link ancha */
        .scoreboard {
            display: grid;
            grid-template-columns: repeat(5, 1fr) 1.4fr;
            gap: 12px;
            margin-bottom: 30px;
        }
        .sb { background: #12121a; border: 1px solid #2a2a3a; border-radius: 16px; padding: 20px; text-align: center; transition: 0.3s; position: relative; overflow: hidden; }
        .sb:hover { border-color: var(--primary); transform: translateY(-3px); box-shadow: 0 10px 20px rgba(0,0,0,0.4); }
        .sb .lbl { font-size: 0.65rem; color: #555; text-transform: uppercase; letter-spacing: 1.5px; margin-bottom: 8px; display: block; }
        .sb .num { font-size: 2rem; font-weight: 800; line-height: 1; margin-bottom: 5px; }
        .sb-purple { border-left: 3px solid var(--primary); } .sb-purple .num { color: var(--primary); }
        .sb-cyan   { border-left: 3px solid var(--secondary); } .sb-cyan .num { color: var(--secondary); }
        .sb-green  { border-left: 3px solid var(--accent); } .sb-green .num { color: var(--accent); }
        .sb-white  { border-left: 3px solid #fff; } .sb-white .num { color: #fff; }

        /* Móvil: 2 columnas para las primeras 4 cards, ref-link ocupa todo el ancho */
        @media (max-width: 900px) {
            .scoreboard {
                grid-template-columns: 1fr 1fr;
            }
            .sb-ref-link {
                grid-column: 1 / -1; /* Ocupa todo el ancho */
            }
        }
        @media (max-width: 480px) {
            .scoreboard {
                grid-template-columns: 1fr 1fr;
                gap: 8px;
            }
            .sb { padding: 14px 12px; }
            .sb .num { font-size: 1.5rem; }
            .sb .lbl { font-size: 0.58rem; }
        }

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

        /* ── Pago Pendiente — Pasos ──────────────────────────────── */
        @keyframes pulseOrange { 0% { box-shadow: 0 0 0 0 rgba(255,107,53,0.6); } 70% { box-shadow: 0 0 0 10px rgba(255,107,53,0); } 100% { box-shadow: 0 0 0 0 rgba(255,107,53,0); } }
        .pp-step { background: #0a0a12; border: 1px solid #2a2a3a; border-radius: 12px; padding: 14px 10px; text-align: center; transition: 0.3s; }
        .pp-step-icon { font-size: 1.4rem; margin-bottom: 6px; }
        .pp-step-num { font-size: 0.6rem; color: #555; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px; }
        .pp-step-label { font-size: 0.72rem; font-weight: 600; color: #555; }
        .pp-step-done { border-color: rgba(0,230,118,0.4); background: rgba(0,230,118,0.04); }
        .pp-step-done .pp-step-label { color: #00e676; }
        .pp-step-active { border-color: rgba(255,107,53,0.5); background: rgba(255,107,53,0.06); animation: ppBorderPulse 2s infinite; }
        .pp-step-active .pp-step-label { color: #ff6b35; }
        .pp-step-pending .pp-step-label { color: #444; }
        @keyframes ppBorderPulse { 0%, 100% { border-color: rgba(255,107,53,0.5); } 50% { border-color: rgba(255,107,53,0.15); } }
        @media (max-width: 480px) {
            .pp-step { padding: 10px 6px; }
            .pp-step-icon { font-size: 1.1rem; }
            .pp-step-label { font-size: 0.65rem; }
        }

        <?php if ($es_master): ?>
        /* Solo para Master */
        .widgets-master { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 25px; }
        .master-grid-layout { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; }
        .master-badge { background: linear-gradient(90deg, var(--primary), var(--secondary)); color: #000; padding: 6px 14px; border-radius: 8px; font-weight: 800; font-size: 0.65rem; text-transform: uppercase; }
        .master-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        .master-table th { text-align: left; padding: 12px; color: #555; border-bottom: 1px solid #2a2a3a; }
        .master-table td { padding: 14px 12px; border-bottom: 1px solid #1a1a24; color: #ccc; }
        .master-tree-toolbar { display:grid; grid-template-columns: 160px 160px minmax(220px, 1fr) auto; gap:14px; align-items:end; margin-bottom:18px; }
        .master-tree-control label { display:block; font-size:0.72rem; color:#666; margin-bottom:8px; text-transform:uppercase; letter-spacing:1px; }
        .master-tree-control select,
        .master-tree-control input {
            width:100%;
            background:rgba(255,255,255,0.03);
            border:1px solid #2a2a3a;
            color:#fff;
            border-radius:12px;
            padding:12px 14px;
            font-size:0.86rem;
            outline:none;
        }
        .master-tree-control input::placeholder { color:#555; }
        .master-tree-control select:focus,
        .master-tree-control input:focus {
            border-color:var(--secondary);
            box-shadow:0 0 0 3px rgba(0,210,255,0.08);
        }
        .master-tree-actions { display:flex; gap:10px; flex-wrap:wrap; justify-content:flex-end; }
        .master-tree-chip-row { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:16px; }
        .master-tree-chip {
            padding:8px 12px;
            border-radius:999px;
            border:1px solid rgba(255,255,255,0.08);
            background:rgba(255,255,255,0.02);
            font-size:0.78rem;
            color:#aaa;
        }
        .master-tree-chip strong { color:#fff; }
        .master-tree-stage { margin-bottom:14px; font-size:0.8rem; color:#666; }
        .master-tree-stage strong { color:var(--secondary); }
        .master-network-tree {
            position:relative;
            min-height:680px;
            border-radius:22px;
            border:1px solid rgba(255,255,255,0.06);
            background:radial-gradient(circle at top, rgba(157,0,255,0.06), transparent 42%), rgba(255,255,255,0.01);
            overflow:hidden;
        }
        .master-network-tree svg { width:100%; height:680px; display:block; cursor:grab; }
        .master-network-tree.is-dragging svg { cursor:grabbing; }
        .master-tree-empty { color:#555; font-size:0.84rem; text-align:center; padding:90px 20px; }
        @media (max-width: 1080px) {
            .master-tree-toolbar { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 900px) {
            .master-tree-toolbar { grid-template-columns: 1fr; }
            .master-network-tree { min-height: 560px; }
            .master-network-tree svg { height: 560px; }
        }
        <?php endif; ?>
    </style>
    <!-- D3.js: necesario para el árbol de red (todos los usuarios) -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/d3/7.8.5/d3.min.js"></script>
    <!-- Chart.js: gráficas del dashboard (master y usuario) -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body>

    <div id="toast-container" style="position:fixed; top:20px; right:20px; z-index:9999; display:flex; flex-direction:column; gap:10px; pointer-events:none;"></div>
    <div id="loading-overlay">Cargando Sistema RADIX...</div>

    <!-- MODAL DE RETIRO (por fase) -->
    <div id="retiro-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.85); z-index:1000; align-items:center; justify-content:center;">
        <div style="background:#12121a; border:1px solid rgba(157,0,255,0.3); border-radius:20px; padding:36px; max-width:460px; width:90%; position:relative;">
            <button onclick="cerrarRetiro()" style="position:absolute;top:16px;right:16px;background:none;border:none;color:#555;font-size:1.4rem;cursor:pointer;">✕</button>
            <h3 style="font-size:1.1rem; margin-bottom:4px; color:#fff;">💸 Solicitar Retiro</h3>
            <p style="font-size:0.75rem; color:var(--accent); font-weight:700; margin-bottom:16px;">
                <span id="retiro-fase-label">Fase 0</span>
            </p>
            <p style="font-size:0.8rem; color:#666; margin-bottom:20px;">Retiro manual vía USDT TRC-20. Procesado en menos de 24h.</p>
            <div style="background:#0a0a12; border-radius:12px; padding:16px; margin-bottom:20px;">
                <div style="font-size:0.7rem; color:#555; text-transform:uppercase; margin-bottom:4px;">Saldo disponible en esta fase</div>
                <div id="retiro-saldo" style="font-size:1.8rem; font-weight:800; color:var(--accent);">$0.00 USDT</div>
            </div>
            <div id="historial-list" style="max-height:160px; overflow-y:auto; margin-bottom:20px; font-size:0.8rem;"></div>
            <!-- Monto personalizado (opcional) -->
            <div style="margin-bottom:18px;">
                <label style="font-size:0.68rem; color:#555; text-transform:uppercase; letter-spacing:1px; display:block; margin-bottom:8px;">Monto a retirar — opcional (vacío = todo el saldo)</label>
                <div style="position:relative;">
                    <span style="position:absolute; left:13px; top:50%; transform:translateY(-50%); color:#666; font-size:0.95rem; pointer-events:none;">$</span>
                    <input type="number" id="retiro-monto-input" min="10" step="0.01" placeholder="Ej: 25.00"
                        style="width:100%; background:#0a0a12; border:1px solid #2a2a3a; color:#fff; padding:11px 14px 11px 28px; border-radius:10px; font-size:0.92rem; outline:none; box-sizing:border-box; transition:border-color 0.2s;"
                        oninput="this.style.borderColor='var(--accent)'">
                </div>
                <div style="font-size:0.68rem; color:#444; margin-top:5px;">Mínimo $10.00 · Máximo = saldo disponible de esta fase</div>
            </div>
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

        <!-- Profile Card -->
        <div class="sidebar-profile">
            <div class="sidebar-avatar-big" id="sidebar-avatar-big">
                <?php echo strtoupper(substr($nickname, 0, 1)); ?>
            </div>
            <div class="sidebar-nickname" id="sidebar-nickname-text">
                <?php echo htmlspecialchars($nickname); ?>
            </div>
            <div class="sidebar-wallet-short" id="sidebar-wallet-short">●●●●●●</div>
            <div class="sidebar-status-dot">
                <?php echo $es_master ? 'Tesorería Central' : 'Activo en Red'; ?>
            </div>
        </div>

        <nav>
            <?php if ($es_master): ?>
            <a href="#" class="nav-item active" id="nav-dashboard" onclick="switchMasterSection('dashboard')">📊 Dashboard</a>
                <a href="#" class="nav-item" id="nav-analizador" onclick="switchMasterSection('analizador')">🧮 Analizador</a>
                <a href="#" class="nav-item" id="nav-ledger" onclick="switchMasterSection('ledger')">📒 Libro Mayor</a>
                <a href="#" class="nav-item" id="nav-mapa" onclick="switchMasterSection('mapa')">🗺️ Mapa de Red</a>
                <a href="#" class="nav-item" id="nav-usuarios" onclick="switchMasterSection('usuarios')">👥 Usuarios Reales</a>
                <a href="#" class="nav-item" id="nav-retiros" onclick="switchMasterSection('retiros')">💰 Pagos Pendientes <span id="nav-badge-retiros" style="display:none; background:#ef5350; color:#fff; border-radius:12px; font-size:0.65rem; font-weight:800; padding:1px 7px; margin-left:4px; vertical-align:middle;"></span></a>
                <a href="#" class="nav-item" id="nav-clones" onclick="switchMasterSection('clones')">🤖 Control de Cuentas Espejo</a>
                <a href="#" class="nav-item" id="nav-auditoria" onclick="switchMasterSection('auditoria')">📜 Registro de Auditoría</a>
            <?php else: ?>
                <div class="user-dashboard-shell">
                <a href="#" class="nav-item active" id="nav-user-overview" onclick="switchUserSection('overview'); return false;">📊 Dashboard</a>
                <a href="#" class="nav-item" id="nav-user-fase-0" onclick="switchUserSection('fase-0'); return false;"><span class="nav-fase-dot" style="background:#00d2ff;"></span> Fase 0</a>
                <a href="#" class="nav-item" id="nav-user-fase-1" onclick="switchUserSection('fase-1'); return false;"><span class="nav-fase-dot" style="background:#9d00ff;"></span> Fase 1</a>
                <a href="#" class="nav-item" id="nav-user-fase-2" onclick="switchUserSection('fase-2'); return false;"><span class="nav-fase-dot" style="background:#00e676;"></span> Fase 2</a>
                <a href="#" class="nav-item" id="nav-user-fase-3" onclick="switchUserSection('fase-3'); return false;"><span class="nav-fase-dot" style="background:#ffb300;"></span> Fase 3</a>
                <a href="#" class="nav-item" onclick="openProfileModal(); return false;">👤 Mi Perfil</a>
                <a href="https://t.me/+jxoT_lB6Wm82Njgx" target="_blank" rel="noopener noreferrer" class="nav-item" style="margin-top:8px; background:linear-gradient(135deg,#0088cc22,#0088cc11); border:1px solid #0088cc55; border-radius:8px; color:#29b6f6; font-weight:700; text-align:center; padding:10px 8px;">
                    📢 Comunidad Telegram
                </a>
                </div>
            <?php endif; ?>
            <a href="radix_api/session_logout.php" class="nav-item" style="margin-top:auto; color:#ff4444;">🚪 Cerrar Sesión</a>
        </nav>
    </aside>

    <!-- CONTENT -->
    <main>
        <header>
            <div>
                <h2 id="welcome-msg">Hola, <?php echo htmlspecialchars($nickname); ?></h2>
                <div style="display:flex; align-items:center; gap:10px;">
                    <p id="wallet-address-display" style="color:#666; font-size:0.8rem;"></p>
                    <?php if ($es_master): ?> <span class="master-badge">Modo Tesorería Central</span> <?php endif; ?>
                </div>
            </div>
            <div class="user-info">
                <?php /* Botón RETIRAR movido a sección de cada fase — ver f{n}-retiro-area */ ?>
                <div class="avatar avatar-profile-trigger" id="avatar-circle" onclick="openProfileModal()" title="Abrir perfil" role="button" tabindex="0">?</div>
            </div>
        </header>

        <div id="section-dashboard" class="master-section active">
            <?php if ($es_master): ?>
                <!-- MASTER V3 LAYOUT -->

                <!-- ══ CENTRO DE COMANDO ══════════════════════════════ -->
                <div id="command-center" style="margin-bottom:22px;">
                    <!-- Semáforo de Salud Financiera -->
                    <div id="health-bar" style="display:flex; align-items:center; gap:14px; background:#0d0d17; border:1px solid #1e1e2e; border-radius:14px; padding:14px 18px; margin-bottom:14px; flex-wrap:wrap;">
                        <div id="health-indicator" style="display:flex; align-items:center; gap:10px; flex:1; min-width:200px;">
                            <div id="health-dot" style="width:14px; height:14px; border-radius:50%; background:#555; flex-shrink:0; box-shadow:0 0 0 4px rgba(85,85,85,0.2); transition:all 0.4s;"></div>
                            <div>
                                <div id="health-label" style="font-size:0.82rem; font-weight:800; color:#aaa;">Verificando sistema…</div>
                                <div id="health-sub" style="font-size:0.7rem; color:#555; margin-top:1px;"></div>
                            </div>
                        </div>
                        <!-- Métricas rápidas -->
                        <div style="display:flex; gap:18px; flex-wrap:wrap; align-items:center;">
                            <div style="text-align:center;">
                                <div style="font-size:0.62rem; color:#555; text-transform:uppercase; letter-spacing:1px;">Tesorería clones</div>
                                <div id="hb-tesoreria" style="font-size:1rem; font-weight:800; color:#00e676;">$—</div>
                            </div>
                            <div style="width:1px; height:30px; background:#1e1e2e;"></div>
                            <div style="text-align:center;">
                                <div style="font-size:0.62rem; color:#555; text-transform:uppercase; letter-spacing:1px;">Retiros pendientes</div>
                                <div id="hb-retiros" style="font-size:1rem; font-weight:800; color:#ffb300;">$—</div>
                            </div>
                            <div style="width:1px; height:30px; background:#1e1e2e;"></div>
                            <div style="text-align:center;">
                                <div style="font-size:0.62rem; color:#555; text-transform:uppercase; letter-spacing:1px;">Referencia</div>
                                <div id="hb-solvencia" style="font-size:0.78rem; font-weight:800; color:#555;">—</div>
                            </div>
                            <div style="width:1px; height:30px; background:#1e1e2e;"></div>
                            <div style="text-align:center;">
                                <div style="font-size:0.62rem; color:#555; text-transform:uppercase; letter-spacing:1px;">Pagos sin confirmar</div>
                                <div id="hb-pagos-sc" style="font-size:1rem; font-weight:800; color:#555;">—</div>
                            </div>
                        </div>
                        <!-- Botón de recarga -->
                        <button onclick="loadMasterAdvancedData()" title="Actualizar estado"
                            style="background:transparent; border:1px solid #2a2a3a; border-radius:8px; color:#555; font-size:0.78rem; padding:5px 11px; cursor:pointer; white-space:nowrap;">
                            🔄 Actualizar
                        </button>
                    </div>

                    <!-- Panel de Alertas -->
                    <div id="alerts-panel"></div>
                </div>
                <!-- ══ FIN CENTRO DE COMANDO ═══════════════════════════ -->

                <div class="widgets-master">
                    <div class="widget"><h4>Tesorería (Agentes IA)</h4><div id="val-balance" class="value">$0.00</div><div class="trend">💰 Fondo Cuentas Espejo</div></div>
                    <div class="widget"><h4>Pools de Fase (1-3)</h4><div id="val-fase" class="value">$0.00</div><div class="trend">Saltos acumulados</div></div>
                    <div class="widget"><h4>Usuarios Reales</h4><div id="val-usuarios-reales" class="value">0</div><div class="trend">Crecimiento Orgánico</div></div>
                    <div class="widget"><h4>📊 Ganancia Red</h4><div id="val-master-earnings" class="value">$0.00</div><div class="trend">Generado por la red (ref.)</div></div>
                    <div class="widget" style="border-left: 3px solid #00d2ff;"><h4>💎 Total Blockchain</h4><div id="val-total-blockchain" class="value">$0.00</div><div class="trend">Recibido en tu wallet</div></div>
                    <div class="widget" style="border-left: 3px solid #00bcd4;"><h4>Saldo Wallet Estimado</h4><div id="val-wallet-estimado" class="value">$0.00</div><div class="trend">Total blockchain - retiros pagados</div></div>
                    <div class="widget" style="border-left: 3px solid #ffab00;"><h4>⏳ Saldo Adeudado</h4><div id="val-pendiente-dist" class="value">$0.00</div><div class="trend">Usuarios aún no retiran</div></div>
                    <div class="widget" style="border-left: 3px solid #39d98a;"><h4>🧾 Créditos Excedente</h4><div id="val-creditos-excedente" class="value">$0.00</div><div class="trend">Saldo a favor de usuarios</div></div>
                </div>

                <!-- ══ PANORAMA POR FASE ══════════════════════════════ -->
                <div id="panorama-fases-section" style="margin-bottom:24px;">

                    <div style="display:flex; align-items:center; gap:10px; margin-bottom:14px;">
                        <span style="font-size:1.1rem;">🌐</span>
                        <h3 style="margin:0; font-size:0.85rem; color:#777; text-transform:uppercase; letter-spacing:1.5px; font-weight:700;">Panorama por Fase</h3>
                        <div style="flex:1; height:1px; background:#1a1a26;"></div>
                    </div>

                    <!-- Tarjetas de Fase (1 por fase) -->
                    <div id="panorama-fases-grid" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(230px, 1fr)); gap:14px; margin-bottom:18px;">
                        <div style="text-align:center; padding:40px; color:#333; grid-column:1/-1; font-size:0.8rem;">Cargando panorama de fases…</div>
                    </div>

                    <!-- Matriz Tablero × Fase -->
                    <div style="margin-bottom:16px;">
                        <div style="font-size:0.68rem; color:#555; text-transform:uppercase; letter-spacing:1.2px; margin-bottom:10px;">
                            💰 Dinero distribuido + personas por Tablero × Fase
                        </div>
                        <div id="matrix-tablero-fase" style="overflow-x:auto;"></div>
                    </div>

                    <!-- Fila inferior: Embudo + Velocidad + Integridad -->
                    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:14px;">
                        <div class="master-card" style="padding:18px;">
                            <div style="font-size:0.72rem; color:#666; text-transform:uppercase; letter-spacing:1px; margin-bottom:12px;">🚰 Embudo de Usuarios</div>
                            <div id="embudo-content"><div style="color:#444; font-size:0.78rem;">Cargando…</div></div>
                        </div>
                        <div class="master-card" style="padding:18px;">
                            <div style="font-size:0.72rem; color:#666; text-transform:uppercase; letter-spacing:1px; margin-bottom:12px;">⚡ Esta Semana vs Anterior</div>
                            <div id="velocidad-content"><div style="color:#444; font-size:0.78rem;">Cargando…</div></div>
                        </div>
                        <div class="master-card" style="padding:18px;">
                            <div style="font-size:0.72rem; color:#666; text-transform:uppercase; letter-spacing:1px; margin-bottom:12px;">🔐 Integridad Financiera</div>
                            <div id="integridad-content"><div style="color:#444; font-size:0.78rem;">Cargando…</div></div>
                        </div>
                    </div>

                </div>
                <!-- ══ FIN PANORAMA POR FASE ══════════════════════════ -->

                <div class="master-grid-top">
                    <div class="master-card">
                        <h4>Crecimiento Diario</h4>
                        <div style="height:300px;"><canvas id="grafica-crecimiento"></canvas></div>
                    </div>
                    <div style="display:flex; flex-direction:column; gap:20px;">
                        <div class="master-card" style="flex:1;">
                            <h4>Distribución</h4>
                            <div id="master-dist-scope" style="font-size:0.72rem; color:#666; margin:-8px 0 14px 0;">Vista general de tableros en progreso.</div>
                            <div style="margin-bottom:10px;"><div style="display:flex; justify-content:space-between; font-size:0.7rem;"><span>Tablero A</span><span id="dist-a-val">0</span></div><div style="height:4px; background:#222;"><div id="dist-a-bar" style="height:100%; background:#9d00ff; width:0%;"></div></div></div>
                            <div style="margin-bottom:10px;"><div style="display:flex; justify-content:space-between; font-size:0.7rem;"><span>Tablero B</span><span id="dist-b-val">0</span></div><div style="height:4px; background:#222;"><div id="dist-b-bar" style="height:100%; background:#00d2ff; width:0%;"></div></div></div>
                            <div style="margin-bottom:10px;"><div style="display:flex; justify-content:space-between; font-size:0.7rem;"><span>Tablero C</span><span id="dist-c-val">0</span></div><div style="height:4px; background:#222;"><div id="dist-c-bar" style="height:100%; background:#00e676; width:0%;"></div></div></div>
                            <h4 style="margin-top:20px;">Ratio Reales/Cuentas Espejo</h4>
                            <div style="height:8px; background:#222; border-radius:4px;"><div id="reales-clones-bar" style="height:100%; background:var(--primary); width:50%;"></div></div>
                            <div id="reales-clones-label" style="font-size:0.72rem; color:#666; margin-top:8px;">Reales 0 | Clones 0</div>
                        </div>
                    </div>
                </div>



                <div id="master-panel-retiros" class="master-tool-panel" style="display:none; margin-bottom:20px;">
                    <div class="master-card"><h4>Retiros Pendientes</h4><div id="master-retiros-mini-list"></div></div>
                </div>

                <div id="master-panel-activity" class="master-tool-panel" style="display:none; margin-bottom:20px;">
                    <div class="master-card"><h4>Actividad del Sistema</h4><table class="master-table"><thead><tr><th>Acción</th><th>Detalles</th><th>Fecha</th></tr></thead><tbody id="master-activity-body"></tbody></table></div>
                </div>

                <!-- TELEGRAM MASTER -->
                <div id="master-telegram-card" class="master-card" style="margin-top:20px; margin-bottom:20px;">
                    <h3>🔔 Notificaciones Telegram — Master</h3>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; align-items:start;">
                        <div style="background:#0a0a12; border-radius:14px; padding:18px;">
                            <div style="font-size:1.6rem; text-align:center; margin-bottom:10px;">📡</div>
                            <p style="color:#aaa; font-size:0.82rem; line-height:1.7;">Recibe alertas de administración cuando:</p>
                            <ul style="list-style:none; padding:0; margin-top:10px; display:flex; flex-direction:column; gap:8px;">
                                <li style="display:flex; align-items:center; gap:8px; color:#ccc; font-size:0.8rem;">
                                    <span style="background:rgba(255,204,0,0.15); border-radius:50%; width:24px; height:24px; display:flex; align-items:center; justify-content:center;">👤</span>
                                    Alguien nuevo se registra
                                </li>
                                <li style="display:flex; align-items:center; gap:8px; color:#ccc; font-size:0.8rem;">
                                    <span style="background:rgba(0,230,118,0.15); border-radius:50%; width:24px; height:24px; display:flex; align-items:center; justify-content:center;">💸</span>
                                    Hay una solicitud de retiro
                                </li>
                                <li style="display:flex; align-items:center; gap:8px; color:#ccc; font-size:0.8rem;">
                                    <span style="background:rgba(157,0,255,0.2); border-radius:50%; width:24px; height:24px; display:flex; align-items:center; justify-content:center;">🤖</span>
                                    Se confirma un pago en blockchain
                                </li>
                            </ul>
                        </div>
                        <div>
                            <div id="tg-no-vinculado">
                                <p style="color:#888; font-size:0.8rem; line-height:1.7; margin-bottom:14px;">
                                    <strong style="color:#fff;">Paso 1:</strong> Busca en Telegram
                                    <a href="https://t.me/RADIXNotificaciones_bot" target="_blank" style="color:#ffcc00; text-decoration:none; font-weight:700;">@RADIXNotificaciones_bot</a><br>
                                    <strong style="color:#fff;">Paso 2:</strong> Escribe <code style="background:#1a1a28; padding:2px 6px; border-radius:4px; color:#00d2ff;">/start</code><br>
                                    <strong style="color:#fff;">Paso 3:</strong> Pega tu Chat ID aquí:
                                </p>
                                <div style="display:flex; gap:10px; margin-bottom:10px;">
                                    <input type="text" id="tg-chat-id-input" placeholder="Ej: 123456789"
                                        style="flex:1; background:#0a0a12; border:1px solid #2a2a3a; color:#fff; padding:12px 14px; border-radius:10px; font-size:0.9rem; outline:none;"
                                        oninput="this.style.borderColor='#ffcc00'">
                                    <button onclick="vincularTelegram()"
                                        style="background:linear-gradient(135deg,#ffcc00,#ff9800); border:none; border-radius:10px; color:#000; font-weight:800; font-size:0.82rem; padding:0 18px; cursor:pointer;">
                                        VINCULAR
                                    </button>
                                </div>
                                <div id="tg-status" style="font-size:0.78rem; color:#555; min-height:16px;"></div>
                            </div>
                            <div id="tg-vinculado" style="display:none;">
                                <div style="background:rgba(0,230,118,0.08); border:1px solid rgba(0,230,118,0.25); border-radius:14px; padding:18px; text-align:center;">
                                    <div style="font-size:2rem; margin-bottom:8px;">✅</div>
                                    <p style="color:#00e676; font-weight:700; margin-bottom:4px;">¡Telegram vinculado!</p>
                                    <p style="color:#555; font-size:0.75rem; margin-bottom:14px;">Recibirás alertas del sistema automáticamente.</p>
                                    <button onclick="desvincularTelegram()" style="background:transparent; border:1px solid #333; border-radius:8px; color:#555; font-size:0.72rem; padding:6px 14px; cursor:pointer;">Desvincular</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <!-- USER LAYOUT V3.2 — PREMIUM RESTORATION -->
                <!-- ── PAGO PENDIENTE (mejorado) ─────────────────────── -->
                <!-- ── HERO CARD V2 — Personalizada ───────────────────── -->
                <section class="user-hero-card-v2">
                    <!-- Glow de fondo decorativo -->
                    <div class="hero-v2-glow"></div>

                    <!-- Fila superior: saludo + badge de fase -->
                    <div class="hero-v2-top">
                        <div class="hero-v2-greeting">
                            <span class="hero-v2-hi">¡Hola,</span>
                            <span class="hero-v2-name" id="hero-user-name"><?php echo htmlspecialchars(explode(' ', $nickname)[0]); ?></span>
                            <span class="hero-v2-hi">👋</span>
                        </div>
                        <div class="hero-v2-fase-badge" id="hero-fase-badge">
                            <span class="hero-v2-fase-dot" id="hero-fase-dot"></span>
                            <span id="hero-fase-label">Fase 0</span>
                        </div>
                    </div>

                    <!-- Mini barra de progreso A → B → C -->
                    <div class="hero-v2-progress-wrap">
                        <span class="hero-v2-progress-label">Tu tablero actual</span>
                        <div class="hero-v2-nodes">
                            <div class="hero-v2-node" id="hero-node-a">
                                <div class="hero-v2-node-circle" id="hero-node-a-circle">A</div>
                                <div class="hero-v2-node-txt">Tablero A</div>
                            </div>
                            <div class="hero-v2-track"><div class="hero-v2-track-fill" id="hero-track-ab"></div></div>
                            <div class="hero-v2-node" id="hero-node-b">
                                <div class="hero-v2-node-circle" id="hero-node-b-circle">B</div>
                                <div class="hero-v2-node-txt">Tablero B</div>
                            </div>
                            <div class="hero-v2-track"><div class="hero-v2-track-fill" id="hero-track-bc"></div></div>
                            <div class="hero-v2-node" id="hero-node-c">
                                <div class="hero-v2-node-circle" id="hero-node-c-circle">C</div>
                                <div class="hero-v2-node-txt">Tablero C</div>
                            </div>
                        </div>
                    </div>

                    <!-- Pills de stats -->
                    <div class="hero-v2-pills">
                        <div class="hero-v2-pill">
                            <span class="hero-v2-pill-icon">👥</span>
                            <div>
                                <div class="hero-v2-pill-val" id="hero-pill-equipo">—</div>
                                <div class="hero-v2-pill-lbl">Equipo</div>
                            </div>
                        </div>
                        <div class="hero-v2-pill">
                            <span class="hero-v2-pill-icon">💰</span>
                            <div>
                                <div class="hero-v2-pill-val" id="hero-pill-saldo">—</div>
                                <div class="hero-v2-pill-lbl">Saldo</div>
                            </div>
                        </div>
                        <div class="hero-v2-pill">
                            <span class="hero-v2-pill-icon">📋</span>
                            <div>
                                <div class="hero-v2-pill-val" id="hero-pill-tablero">—</div>
                                <div class="hero-v2-pill-lbl">Tablero activo</div>
                            </div>
                        </div>
                    </div>
                </section>
                <div id="pago-pendiente-box" class="pago-pendiente-box" style="display:none;">
                    <!-- Header -->
                    <div style="display:flex; align-items:center; gap:12px; margin-bottom:18px;">
                        <div style="width:40px; height:40px; border-radius:50%; background:rgba(255,107,53,0.15); border:2px solid rgba(255,107,53,0.5); display:flex; align-items:center; justify-content:center; font-size:1.2rem; flex-shrink:0; animation:pulseOrange 2s infinite;">⏳</div>
                        <div>
                            <div style="color:#ff6b35; font-weight:800; font-size:1rem; letter-spacing:0.5px;">PAGO DE ACTIVACIÓN PENDIENTE</div>
                            <div style="color:#666; font-size:0.72rem; margin-top:2px;">Completa el pago para activar tu cuenta en la red</div>
                        </div>
                        <div style="margin-left:auto; background:rgba(255,107,53,0.12); border:1px solid rgba(255,107,53,0.3); border-radius:20px; padding:4px 12px; font-size:0.65rem; color:#ff6b35; font-weight:700; letter-spacing:1px; white-space:nowrap;">EN ESPERA</div>
                    </div>

                    <!-- Pasos -->
                    <div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:10px; margin-bottom:20px;">
                        <!-- Paso 1 -->
                        <div class="pp-step pp-step-done">
                            <div class="pp-step-icon">✅</div>
                            <div class="pp-step-num">Paso 1</div>
                            <div class="pp-step-label">Wallet conectada</div>
                        </div>
                        <!-- Paso 2 -->
                        <div class="pp-step pp-step-active">
                            <div class="pp-step-icon">💸</div>
                            <div class="pp-step-num">Paso 2</div>
                            <div class="pp-step-label">Enviar $10 USDT</div>
                        </div>
                        <!-- Paso 3 -->
                        <div class="pp-step pp-step-pending">
                            <div class="pp-step-icon">🚀</div>
                            <div class="pp-step-num">Paso 3</div>
                            <div class="pp-step-label">Cuenta activada</div>
                        </div>
                    </div>

                    <!-- Dirección destino -->
                    <div style="background:rgba(0,0,0,0.35); border:1px solid rgba(255,107,53,0.25); border-radius:12px; padding:14px 16px; margin-bottom:16px;">
                        <div style="font-size:0.65rem; color:#666; text-transform:uppercase; letter-spacing:1px; margin-bottom:6px;">Enviar exactamente <strong data-monto style="color:#ff6b35;">$10.00 USDT (TRC-20)</strong> a:</div>
                        <div id="pp-wallet-patron" style="font-family:monospace; font-size:0.8rem; color:#00d2ff; word-break:break-all; line-height:1.6; user-select:all;">...</div>
                    </div>

                    <!-- Aviso red -->
                    <div style="background:rgba(255,193,7,0.06); border:1px solid rgba(255,193,7,0.2); border-radius:8px; padding:10px 14px; margin-bottom:16px; display:flex; gap:10px; align-items:center;">
                        <span style="font-size:1rem;">⚠️</span>
                        <span style="color:#aaa; font-size:0.73rem; line-height:1.5;">Asegúrate de enviar por la red <strong style="color:#ffc107;">TRON (TRC-20)</strong>. Enviar por otra red resultará en pérdida del pago.</span>
                    </div>

                    <!-- Input de verificación -->
                    <div style="font-size:0.7rem; color:#666; text-transform:uppercase; letter-spacing:1px; margin-bottom:8px;">Después de enviar, pega aquí el TXID de la transacción:</div>
                    <div class="tx-input-row">
                        <input type="text" id="tx-hash-input" placeholder="Ej: a1b2c3d4e5f6... (64+ caracteres)">
                        <button onclick="confirmarPago()">VERIFICAR</button>
                    </div>
                    <div style="font-size:0.65rem; color:#555; margin-top:8px; text-align:center;">Puedes encontrar el TXID en el historial de tu billetera TronLink o en <a href="https://tronscan.org" target="_blank" style="color:#00d2ff; text-decoration:none;">TronScan.org</a></div>
                </div>

                <div id="profile-modal" class="profile-modal" aria-hidden="true">
                    <div class="profile-modal-backdrop" onclick="closeProfileModal()"></div>
                    <div class="profile-modal-dialog">
                <div class="master-card profile-shell" id="profile-panel">
                    <div class="profile-shell-head">
                        <h3>Mi Perfil</h3>
                        <button type="button" class="profile-close-btn" onclick="closeProfileModal()" aria-label="Cerrar perfil">×</button>
                    </div>
                    <div class="profile-grid">
                        <div class="profile-summary-card">
                            <div class="profile-summary-badge">Cuenta Activa</div>
                            <div class="profile-summary-avatar" id="profile-summary-avatar">?</div>
                            <div class="profile-summary-name" id="profile-summary-name"><?php echo htmlspecialchars($nickname); ?></div>
                            <div class="profile-summary-sub" id="profile-summary-nick">@<?php echo htmlspecialchars($user_info['nickname'] ?? ''); ?></div>
                            <div class="profile-summary-wallet" id="profile-summary-wallet"><?php echo htmlspecialchars($user_wallet); ?></div>
                            <div class="profile-summary-note" id="profile-summary-telegram">Telegram de perfil: No registrado</div>
                            <div class="profile-summary-note">Este dato es solo informativo y no reemplaza la vinculacion del bot para notificaciones.</div>
                        </div>

                        <div class="profile-form-card">
                            <div class="profile-form-grid">
                                <div class="profile-field">
                                    <label for="profile-nickname">Nick de usuario</label>
                                    <input id="profile-nickname" type="text" readonly>
                                </div>
                                <div class="profile-field">
                                    <label for="profile-wallet">Wallet</label>
                                    <input id="profile-wallet" type="text" readonly>
                                </div>
                                <div class="profile-field">
                                    <label for="profile-nombre">Nombre completo</label>
                                    <input id="profile-nombre" type="text" autocomplete="name">
                                </div>
                                <div class="profile-field">
                                    <label for="profile-telefono">Teléfono</label>
                                    <input id="profile-telefono" type="tel" autocomplete="tel">
                                </div>
                                <div class="profile-field profile-field-full">
                                    <label for="profile-correo">Correo electrónico</label>
                                    <input id="profile-correo" type="email" autocomplete="email">
                                </div>
                                <div class="profile-field profile-field-full">
                                    <label for="profile-telegram">Usuario de Telegram</label>
                                    <input id="profile-telegram" type="text" autocomplete="off" placeholder="@tuusuario">
                                </div>
                            </div>
                            <div class="profile-actions">
                                <button type="button" class="btn-master profile-save-btn" onclick="guardarPerfil()">Guardar Cambios</button>
                                <button type="button" class="profile-secondary-btn" onclick="recargarPerfil()">Restablecer</button>
                            </div>
                            <div id="profile-status" class="profile-status"></div>
                        </div>
                    </div>

                    <div class="profile-password-card">
                        <div class="profile-password-head">
                            <div>
                                <h4>Seguridad de Acceso</h4>
                                <p>Cambia tu contraseña cuando lo necesites.</p>
                            </div>
                            <span id="profile-password-badge" class="profile-password-badge">Protegido</span>
                        </div>
                        <div class="profile-password-grid">
                            <div class="profile-field">
                                <label for="profile-current-password">Contraseña actual</label>
                                <input id="profile-current-password" type="password" autocomplete="current-password">
                            </div>
                            <div class="profile-field">
                                <label for="profile-new-password">Nueva contraseña</label>
                                <input id="profile-new-password" type="password" autocomplete="new-password">
                            </div>
                            <div class="profile-field">
                                <label for="profile-confirm-password">Confirmar nueva contraseña</label>
                                <input id="profile-confirm-password" type="password" autocomplete="new-password">
                            </div>
                        </div>
                        <div class="profile-actions">
                            <button type="button" class="btn-master profile-password-btn" onclick="cambiarContrasenaPerfil()">Actualizar Contraseña</button>
                        </div>
                        <div id="profile-password-status" class="profile-status"></div>
                    </div>
                </div>
                    </div>
                </div>

                <!-- Solo link de referido en el overview -->
                <div class="master-card user-overview-block" style="display:flex; align-items:center; gap:14px; padding:16px 20px;">
                    <span style="font-size:1.4rem;">🔗</span>
                    <div style="flex:1; min-width:0;">
                        <div style="font-size:0.65rem; color:#666; text-transform:uppercase; letter-spacing:1px; margin-bottom:6px;">Tu link de referido</div>
                        <div style="display:flex; gap:8px;">
                            <input type="text" id="ref-link-input" readonly style="background:rgba(0,0,0,0.3); border:1px solid #2a2a3a; color:#aaa; padding:8px 12px; border-radius:8px; flex:1; font-size:0.75rem; min-width:0;">
                            <button onclick="copyRefLink()" style="background:var(--primary); color:#fff; border:none; border-radius:8px; cursor:pointer; font-size:0.7rem; font-weight:800; padding:0 16px; white-space:nowrap;">COPIAR</button>
                        </div>
                    </div>
                </div>

                <!-- Gráficas del overview -->
                <div class="user-charts-grid user-overview-block">
                    <div class="master-card">
                        <h3>Avance por Fase</h3>
                        <canvas id="chart-phase-progress" height="200"></canvas>
                    </div>
                    <div class="master-card">
                        <h3>Equipo por Fase</h3>
                        <canvas id="chart-phase-team" height="200"></canvas>
                    </div>
                </div>

                <!-- Contenedores ocultos requeridos por JS -->
                <div style="display:none;">
                    <div id="team-list"></div>
                    <div id="network-tree"></div>
                    <div id="progress-fill"></div>
                    <div id="node-a"></div><div id="node-b"></div><div id="node-c"></div>
                    <div id="val-balance"></div>
                    <div id="val-reserva"></div>
                    <div id="val-clones"></div>
                    <div id="val-fase"></div>
                    <div id="val-equipo-count"></div>
                </div>

                <!-- ══════════════════════════════════════════════
                     SECCIONES POR FASE — se muestran/ocultan con switchUserSection()
                     ══════════════════════════════════════════════ -->
                <?php foreach ([0,1,2,3] as $fn):
                    $colors = ['#00d2ff','#9d00ff','#00e676','#ffb300'];
                    $fc = $colors[$fn];
                ?>
                <div id="section-fase-<?= $fn ?>" class="user-fase-section" style="display:none;">

                    <!-- Header de fase -->
                    <div class="fase-section-header" style="border-left:4px solid <?= $fc ?>;">
                        <div>
                            <span class="fase-section-eyebrow" style="color:<?= $fc ?>;">RADIX · FASE <?= $fn ?></span>
                            <h2 class="fase-section-title" id="fase-title-<?= $fn ?>">Fase <?= $fn ?></h2>
                        </div>
                        <div class="fase-section-badge" id="fase-badge-<?= $fn ?>">Cargando...</div>
                    </div>

                    <!-- Tarjeta resumen de esta fase (ex Mapa de Fases) -->
                    <div id="f<?= $fn ?>-phase-card" class="fase-phase-card-wrap" style="margin-bottom:20px;"></div>

                    <!-- Fila de cumplimiento de esta fase (ex Cumplimiento por Fase) -->
                    <div id="f<?= $fn ?>-compare-row" style="margin-bottom:20px;"></div>

                    <!-- Scoreboard de fase -->
                    <div class="scoreboard scoreboard-fase" id="sb-fase-<?= $fn ?>">
                        <div class="sb" style="border-left:3px solid <?= $fc ?>;"><span class="lbl">SALDO FASE <?= $fn ?></span><div id="f<?= $fn ?>-balance" class="num">$0.00</div></div>
                        <div class="sb sb-white"><span class="lbl">TABLERO ACTUAL</span><div id="f<?= $fn ?>-tablero" class="num" style="font-size:1.3rem;">—</div></div>
                        <div class="sb sb-purple"><span class="lbl">AGENTES IA</span><div id="f<?= $fn ?>-clones" class="num">0</div></div>
                        <div class="sb sb-green"><span class="lbl">EQUIPO</span><div id="f<?= $fn ?>-equipo" class="num">0</div></div>
                        <div class="sb" style="border-left:3px solid #9d00ff;"><span class="lbl">RESERVA</span><div id="f<?= $fn ?>-reserva" class="num">$0.00</div></div>
                        <div class="sb sb-white"><span class="lbl">CICLOS COMPLETADOS</span><div id="f<?= $fn ?>-ciclos" class="num">0</div></div>
                    </div>

                    <!-- Barra de progreso A→B→C -->
                    <div class="master-card user-feature-card">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                            <h3 style="margin:0;">Progreso · Fase <?= $fn ?></h3>
                            <span id="f<?= $fn ?>-progress-pct" style="font-size:0.95rem;font-weight:700;color:#aaa;">0%</span>
                        </div>
                        <div class="progress-container">
                            <div class="progress-track">
                                <div id="f<?= $fn ?>-progress-fill" class="progress-bar-fill" style="background:linear-gradient(90deg,<?= $fc ?>,<?= $colors[min($fn+1,3)] ?>);"></div>
                            </div>
                            <div class="nodes-row">
                                <div id="f<?= $fn ?>-node-a" class="phase-node">A</div>
                                <div id="f<?= $fn ?>-node-b" class="phase-node">B</div>
                                <div id="f<?= $fn ?>-node-c" class="phase-node">C</div>
                            </div>
                        </div>
                    </div>

                    <!-- Equipo + árbol visual -->
                    <div class="user-main-grid">
                        <div class="master-card" style="height:fit-content;">
                            <h3>Equipo · Fase <?= $fn ?></h3>
                            <div id="f<?= $fn ?>-team-list" style="max-height:220px; overflow-y:auto; font-size:0.85rem;"></div>
                        </div>
                        <div class="master-card">
                            <h3>Red Visual · Fase <?= $fn ?></h3>
                            <div id="f<?= $fn ?>-network-tree" style="min-height:300px; background:rgba(0,0,0,0.2); border-radius:15px; border:1px dashed #2a2a3a; display:flex; align-items:center; justify-content:center; color:#333;"></div>
                        </div>
                    </div>

                    <!-- Botón RETIRAR de esta fase (visible solo cuando puede_retirar = true) -->
                    <div id="f<?= $fn ?>-retiro-area" style="margin-top:20px; display:none; text-align:center;">
                        <div id="f<?= $fn ?>-retiro-msg" style="display:none; font-size:0.82rem; color:#ffb300; margin-bottom:10px;"></div>
                        <div style="font-size:0.75rem; color:#555; margin-bottom:8px; text-transform:uppercase;">Saldo disponible en Fase <?= $fn ?>:</div>
                        <div id="f<?= $fn ?>-balance-retiro" style="font-size:1.4rem; font-weight:800; color:<?= $fc ?>; margin-bottom:16px;">$0.00 USDT</div>
                        <button onclick="abrirRetiro(<?= $fn ?>)" class="btn-withdraw" style="background:linear-gradient(135deg,<?= $fc ?>,<?= $colors[min($fn+1,3)] ?>); color:#000; padding:16px 40px; font-size:1rem; border-radius:14px; border:none; cursor:pointer; font-weight:800; width:100%;">
                            💸 RETIRAR GANANCIAS FASE <?= $fn ?>
                        </button>
                    </div>

                    <!-- Placeholder si la fase no tiene actividad -->
                    <div id="f<?= $fn ?>-coming-soon" class="fase-coming-soon" style="display:none;">
                        <div style="font-size:3rem; margin-bottom:16px;">🔒</div>
                        <h3>Fase <?= $fn ?> — Preparada</h3>
                        <p>Esta fase se activará cuando completes la fase anterior y el sistema te posicione aquí automáticamente.</p>
                    </div>

                </div>
                <?php endforeach; ?>

                <!-- SECCIÓN TELEGRAM -->
                <div class="master-card user-overview-block" style="margin-top:20px;">
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
            <div id="section-usuarios" class="master-section">

                    <!-- ── ENCABEZADO ───────────────────────────────────── -->
                    <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:16px;">
                        <div>
                            <h4 style="margin:0 0 2px 0;">👥 Gestión de Usuarios Reales</h4>
                            <div style="font-size:0.72rem; color:#555;">Filtra, busca y exporta tu red de usuarios</div>
                        </div>
                        <div style="display:flex; align-items:center; gap:10px;">
                            <span id="users-counter" style="font-size:0.78rem; color:#777; background:rgba(255,255,255,0.04); border:1px solid #222; border-radius:10px; padding:6px 14px;">
                                <strong id="users-showing" style="color:#fff; font-size:1rem;">0</strong>
                                <span style="color:#555;"> / </span>
                                <strong id="users-total" style="color:#aaa;">0</strong>
                                <span style="color:#555; font-size:0.68rem;"> usuarios</span>
                            </span>
                            <button onclick="exportarUsuariosCSV()"
                                style="background:rgba(0,230,118,0.1); border:1px solid rgba(0,230,118,0.3); color:#00e676; border-radius:10px; padding:7px 16px; cursor:pointer; font-size:0.78rem; font-weight:800; display:flex; align-items:center; gap:6px;">
                                ⬇ Exportar CSV
                            </button>
                        </div>
                    </div>

                    <!-- ── BARRA DE BÚSQUEDA ────────────────────────────── -->
                    <div style="position:relative; margin-bottom:16px;">
                        <span style="position:absolute; left:14px; top:50%; transform:translateY(-50%); color:#555; font-size:1.1rem; pointer-events:none;">🔍</span>
                        <input id="users-search" type="text"
                            placeholder="Buscar por nombre, nick, wallet, correo o teléfono…"
                            oninput="applyUserFilters()"
                            style="width:100%; background:#0a0a14; border:2px solid #1e1e2e; color:#fff; padding:12px 16px 12px 42px; border-radius:12px; font-size:0.85rem; outline:none; box-sizing:border-box; transition:border-color 0.2s;"
                            onfocus="this.style.borderColor='#9d00ff'" onblur="this.style.borderColor='#1e1e2e'">
                    </div>

                    <!-- ── TARJETAS DE FILTRO ────────────────────────────── -->
                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:12px; margin-bottom:16px;">

                        <!-- TARJETA: Estado de Pago -->
                        <div style="background:#0d0d18; border:1px solid #1e1e2e; border-radius:14px; padding:14px 16px;">
                            <div style="display:flex; align-items:center; gap:7px; margin-bottom:10px;">
                                <span style="font-size:1rem;">💳</span>
                                <span style="font-size:0.7rem; font-weight:800; color:#888; text-transform:uppercase; letter-spacing:1.2px;">Estado de Pago</span>
                            </div>
                            <div style="display:flex; flex-direction:column; gap:6px;">
                                <?php foreach([
                                    ['val'=>'all',       'label'=>'Todos los estados', 'dot'=>'#555'],
                                    ['val'=>'completado','label'=>'✓  Pagado',          'dot'=>'#00e676'],
                                    ['val'=>'pendiente', 'label'=>'⏳  Pendiente',       'dot'=>'#ffb300'],
                                    ['val'=>'sin_pago',  'label'=>'✗  Sin registro',    'dot'=>'#ef5350'],
                                ] as $f): ?>
                                <button class="uf-pago" data-val="<?= $f['val'] ?>"
                                    onclick="setUserFilter('pago','<?= $f['val'] ?>')"
                                    style="display:flex; align-items:center; gap:8px; background:<?= $f['val']==='all'?'rgba(255,255,255,0.08)':'transparent' ?>; border:1px solid <?= $f['val']==='all'?'#444':'transparent' ?>; color:<?= $f['val']==='all'?'#fff':'#666' ?>; border-radius:8px; padding:7px 10px; cursor:pointer; font-size:0.78rem; text-align:left; width:100%; transition:all 0.15s;">
                                    <span style="width:8px; height:8px; border-radius:50%; background:<?= $f['dot'] ?>; flex-shrink:0;"></span>
                                    <?= $f['label'] ?>
                                </button>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- TARJETA: Tablero Actual -->
                        <div style="background:#0d0d18; border:1px solid #1e1e2e; border-radius:14px; padding:14px 16px;">
                            <div style="display:flex; align-items:center; gap:7px; margin-bottom:10px;">
                                <span style="font-size:1rem;">📊</span>
                                <span style="font-size:0.7rem; font-weight:800; color:#888; text-transform:uppercase; letter-spacing:1.2px;">Tablero Actual</span>
                            </div>
                            <div style="display:flex; flex-direction:column; gap:6px;">
                                <?php foreach([
                                    ['val'=>'all','label'=>'Todos los tableros','dot'=>'#555'],
                                    ['val'=>'A',  'label'=>'Tablero A',         'dot'=>'#9d00ff'],
                                    ['val'=>'B',  'label'=>'Tablero B',         'dot'=>'#00d2ff'],
                                    ['val'=>'C',  'label'=>'Tablero C',         'dot'=>'#00e676'],
                                    ['val'=>'sin','label'=>'Sin tablero aún',   'dot'=>'#333'],
                                ] as $f): ?>
                                <button class="uf-tablero" data-val="<?= $f['val'] ?>"
                                    onclick="setUserFilter('tablero','<?= $f['val'] ?>')"
                                    style="display:flex; align-items:center; gap:8px; background:<?= $f['val']==='all'?'rgba(255,255,255,0.08)':'transparent' ?>; border:1px solid <?= $f['val']==='all'?'#444':'transparent' ?>; color:<?= $f['val']==='all'?'#fff':'#666' ?>; border-radius:8px; padding:7px 10px; cursor:pointer; font-size:0.78rem; text-align:left; width:100%; transition:all 0.15s;">
                                    <span style="width:8px; height:8px; border-radius:50%; background:<?= $f['dot'] ?>; flex-shrink:0;"></span>
                                    <?= $f['label'] ?>
                                </button>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- TARJETA: Fase -->
                        <div style="background:#0d0d18; border:1px solid #1e1e2e; border-radius:14px; padding:14px 16px;">
                            <div style="display:flex; align-items:center; gap:7px; margin-bottom:10px;">
                                <span style="font-size:1rem;">🚀</span>
                                <span style="font-size:0.7rem; font-weight:800; color:#888; text-transform:uppercase; letter-spacing:1.2px;">Fase</span>
                            </div>
                            <div style="display:flex; flex-direction:column; gap:6px;">
                                <?php foreach([
                                    ['val'=>'all','label'=>'Todas las fases','dot'=>'#555'],
                                    ['val'=>'0',  'label'=>'Fase 0 · $10',  'dot'=>'#00d2ff'],
                                    ['val'=>'1',  'label'=>'Fase 1 · $100', 'dot'=>'#9d00ff'],
                                    ['val'=>'2',  'label'=>'Fase 2 · $1K',  'dot'=>'#00e676'],
                                    ['val'=>'3',  'label'=>'Fase 3 · $10K', 'dot'=>'#ffb300'],
                                ] as $f): ?>
                                <button class="uf-fase" data-val="<?= $f['val'] ?>"
                                    onclick="setUserFilter('fase','<?= $f['val'] ?>')"
                                    style="display:flex; align-items:center; gap:8px; background:<?= $f['val']==='all'?'rgba(255,255,255,0.08)':'transparent' ?>; border:1px solid <?= $f['val']==='all'?'#444':'transparent' ?>; color:<?= $f['val']==='all'?'#fff':'#666' ?>; border-radius:8px; padding:7px 10px; cursor:pointer; font-size:0.78rem; text-align:left; width:100%; transition:all 0.15s;">
                                    <span style="width:8px; height:8px; border-radius:50%; background:<?= $f['dot'] ?>; flex-shrink:0;"></span>
                                    <?= $f['label'] ?>
                                </button>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- TARJETA: Fecha de Registro + Orden -->
                        <div style="background:#0d0d18; border:1px solid #1e1e2e; border-radius:14px; padding:14px 16px;">
                            <div style="display:flex; align-items:center; gap:7px; margin-bottom:10px;">
                                <span style="font-size:1rem;">📅</span>
                                <span style="font-size:0.7rem; font-weight:800; color:#888; text-transform:uppercase; letter-spacing:1.2px;">Fecha de Registro</span>
                            </div>
                            <div style="display:flex; flex-direction:column; gap:6px; margin-bottom:14px;">
                                <?php foreach([
                                    ['val'=>'all',  'label'=>'Todo el tiempo','dot'=>'#555'],
                                    ['val'=>'week', 'label'=>'Esta semana',   'dot'=>'#00d2ff'],
                                    ['val'=>'month','label'=>'Este mes',      'dot'=>'#9d00ff'],
                                ] as $f): ?>
                                <button class="uf-fecha" data-val="<?= $f['val'] ?>"
                                    onclick="setUserFilter('fecha','<?= $f['val'] ?>')"
                                    style="display:flex; align-items:center; gap:8px; background:<?= $f['val']==='all'?'rgba(255,255,255,0.08)':'transparent' ?>; border:1px solid <?= $f['val']==='all'?'#444':'transparent' ?>; color:<?= $f['val']==='all'?'#fff':'#666' ?>; border-radius:8px; padding:7px 10px; cursor:pointer; font-size:0.78rem; text-align:left; width:100%; transition:all 0.15s;">
                                    <span style="width:8px; height:8px; border-radius:50%; background:<?= $f['dot'] ?>; flex-shrink:0;"></span>
                                    <?= $f['label'] ?>
                                </button>
                                <?php endforeach; ?>
                            </div>
                            <!-- Ordenar (dentro de la misma tarjeta) -->
                            <div style="border-top:1px solid #1a1a26; padding-top:12px;">
                                <div style="display:flex; align-items:center; gap:7px; margin-bottom:8px;">
                                    <span style="font-size:0.9rem;">↕️</span>
                                    <span style="font-size:0.68rem; font-weight:800; color:#666; text-transform:uppercase; letter-spacing:1px;">Ordenar por</span>
                                </div>
                                <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                    <?php foreach([
                                        ['val'=>'recientes','label'=>'↓ Recientes'],
                                        ['val'=>'antiguos', 'label'=>'↑ Antiguos'],
                                        ['val'=>'nombre',   'label'=>'A–Z'],
                                    ] as $f): ?>
                                    <button class="uf-orden" data-val="<?= $f['val'] ?>"
                                        onclick="setUserFilter('orden','<?= $f['val'] ?>')"
                                        style="background:<?= $f['val']==='recientes'?'rgba(255,255,255,0.1)':'rgba(255,255,255,0.03)' ?>; border:1px solid <?= $f['val']==='recientes'?'#555':'#1e1e2e' ?>; color:<?= $f['val']==='recientes'?'#fff':'#555' ?>; border-radius:7px; padding:5px 10px; cursor:pointer; font-size:0.72rem; white-space:nowrap; transition:all 0.15s;">
                                        <?= $f['label'] ?>
                                    </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                    </div><!-- fin grid tarjetas -->

                    <!-- ── BOTÓN LIMPIAR FILTROS ────────────────────────── -->
                    <div style="display:flex; justify-content:flex-end; margin-bottom:16px;">
                        <button onclick="clearUserFilters()"
                            style="background:transparent; border:1px solid #2a2a3a; color:#555; border-radius:8px; padding:6px 16px; cursor:pointer; font-size:0.75rem; display:flex; align-items:center; gap:6px; transition:all 0.15s;"
                            onmouseover="this.style.borderColor='#555';this.style.color='#aaa'" onmouseout="this.style.borderColor='#2a2a3a';this.style.color='#555'">
                            ✕ Limpiar todos los filtros
                        </button>
                    </div>

                    <!-- ── TABLA DE RESULTADOS ─────────────────────────── -->
                    <div class="master-card" style="padding:0; overflow:hidden;">
                        <div style="overflow-x:auto;">
                            <table class="master-table" style="margin:0;">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Nombre Completo</th>
                                        <th>Nick</th>
                                        <th>Teléfono</th>
                                        <th>Correo</th>
                                        <th>Tablero</th>
                                        <th>Pago</th>
                                        <th>Registro</th>
                                        <th>Wallet</th>
                                    </tr>
                                </thead>
                                <tbody id="master-users-body"></tbody>
                            </table>
                        </div>

                        <!-- SIN RESULTADOS -->
                        <div id="users-empty" style="display:none; text-align:center; padding:50px 20px; color:#444;">
                            <div style="font-size:2.5rem; margin-bottom:12px;">🔎</div>
                            <div style="font-size:0.85rem; margin-bottom:14px;">Ningún usuario coincide con los filtros aplicados.</div>
                            <button onclick="clearUserFilters()" style="background:rgba(255,255,255,0.05); border:1px solid #333; color:#888; border-radius:8px; padding:7px 18px; cursor:pointer; font-size:0.78rem;">✕ Limpiar filtros</button>
                        </div>
                    </div>

            </div>
            <div id="section-analizador" class="master-section">
                <div id="master-panel-stats" style="margin-bottom:20px;">
                    <div class="master-card" style="margin-bottom:20px;">
                        <h4>🧮 Analizador de distribución</h4>
                        <div class="master-tree-stage">
                            Filtra por fase, tablero, ciclo y tipo de usuario para saber a cuántos ya se les distribuyeron los $10 y cómo se mueve la red.
                        </div>
                        <div class="master-tree-toolbar" style="margin-bottom:20px;">
                            <div class="master-tree-control">
                                <label for="master-stats-phase">Fase</label>
                                <select id="master-stats-phase"></select>
                            </div>
                            <div class="master-tree-control">
                                <label for="master-stats-board">Tablero</label>
                                <select id="master-stats-board"></select>
                            </div>
                            <div class="master-tree-control">
                                <label for="master-stats-cycle">Ciclo</label>
                                <select id="master-stats-cycle"></select>
                            </div>
                            <div class="master-tree-control">
                                <label for="master-stats-user-type">Tipo usuario</label>
                                <select id="master-stats-user-type"></select>
                            </div>
                        </div>
                        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; margin-bottom:20px;">
                            <div style="background:rgba(255,255,255,0.02); border:1px solid #1a1a24; border-radius:14px; padding:16px;">
                                <div style="font-size:0.68rem; color:#666; text-transform:uppercase; letter-spacing:1px; margin-bottom:8px;">Total filtrado</div>
                                <div id="master-filter-total" style="font-size:1.8rem; font-weight:800; color:#fff;">$0.00</div>
                            </div>
                            <div style="background:rgba(255,255,255,0.02); border:1px solid #1a1a24; border-radius:14px; padding:16px;">
                                <div style="font-size:0.68rem; color:#666; text-transform:uppercase; letter-spacing:1px; margin-bottom:8px;">Beneficiarios</div>
                                <div id="master-filter-beneficiarios" style="font-size:1.8rem; font-weight:800; color:#00d2ff;">0</div>
                            </div>
                            <div style="background:rgba(255,255,255,0.02); border:1px solid #1a1a24; border-radius:14px; padding:16px;">
                                <div style="font-size:0.68rem; color:#666; text-transform:uppercase; letter-spacing:1px; margin-bottom:8px;">Usuarios con $10</div>
                                <div id="master-filter-users-10" style="font-size:1.8rem; font-weight:800; color:#00e676;">0</div>
                            </div>
                            <div style="background:rgba(255,255,255,0.02); border:1px solid #1a1a24; border-radius:14px; padding:16px;">
                                <div style="font-size:0.68rem; color:#666; text-transform:uppercase; letter-spacing:1px; margin-bottom:8px;">Pagos de $10</div>
                                <div id="master-filter-payments-10" style="font-size:1.8rem; font-weight:800; color:#ffb300;">0</div>
                            </div>
                        </div>
                        <div style="display:flex; justify-content:space-between; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:14px;">
                            <div id="master-filter-caption" style="font-size:0.78rem; color:#888;">Vista actual: todas las fases, todos los tableros y todos los ciclos.</div>
                            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                                <button class="btn-master" type="button" onclick="applyMasterStatsFilters()">Aplicar filtros</button>
                                <button class="btn-master" type="button" onclick="clearMasterStatsFilters()" style="background:#1a1a28; color:#fff; border:1px solid #2a2a3a;">Limpiar filtros</button>
                            </div>
                        </div>
                        <div style="display:grid; grid-template-columns:1.35fr 1fr; gap:18px;">
                            <div style="overflow-x:auto;">
                                <table class="master-table">
                                    <thead>
                                        <tr>
                                            <th>Fase</th>
                                            <th>Tablero</th>
                                            <th>Ciclo</th>
                                            <th>Beneficiarios</th>
                                            <th>Pagos</th>
                                            <th>Total</th>
                                            <th>Usuarios $10</th>
                                        </tr>
                                    </thead>
                                    <tbody id="master-distribution-body"></tbody>
                                </table>
                            </div>
                            <div style="overflow-x:auto;">
                                <table class="master-table">
                                    <thead>
                                        <tr>
                                            <th>Usuario</th>
                                            <th>Tipo</th>
                                            <th>Veces</th>
                                            <th>Ultima</th>
                                        </tr>
                                    </thead>
                                    <tbody id="master-ten-users-body"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="section-ledger" class="master-section">
                <div id="master-panel-ledger" style="margin-bottom:20px;">
                    <div class="master-card" style="margin-bottom:20px;">
                        <h4>📒 Libro Mayor de Tesorería</h4>
                        <div style="overflow-x:auto;"><table class="master-table"><thead><tr><th>Fecha</th><th>Concepto</th><th>Monto</th><th>Estado</th></tr></thead><tbody id="master-ledger-body"></tbody></table></div>
                    </div>
                </div>
            </div>

            <div id="section-mapa" class="master-section">
                <div id="master-panel-map" style="margin-bottom:20px;">
                    <div class="master-card" style="margin-bottom:20px;">
                        <h4>🗺️ Mapa General de Red</h4>
                        <div class="master-tree-stage">
                            Vista global de la red por fase y ciclo. Puedes centrar una rama escribiendo el ID, nombre, nickname o wallet del usuario.
                        </div>

                        <div class="master-tree-toolbar">
                            <div class="master-tree-control">
                                <label for="master-tree-phase">Fase</label>
                                <select id="master-tree-phase"></select>
                            </div>
                            <div class="master-tree-control">
                                <label for="master-tree-cycle">Ciclo</label>
                                <select id="master-tree-cycle"></select>
                            </div>
                            <div class="master-tree-control">
                                <label for="master-tree-root">Raiz o busqueda</label>
                                <input id="master-tree-root" type="text" placeholder="Ej: 1001, Wendy, TRON_TQ2R o wallet">
                            </div>
                            <div class="master-tree-actions">
                                <button class="btn-master" type="button" onclick="aplicarFiltrosArbolMaster()">Ver arbol</button>
                                <button class="btn-master" type="button" onclick="resetZoomMasterTree()" style="background:#1a1a28; color:#fff; border:1px solid #2a2a3a;">Reset vista</button>
                                <button class="btn-master" type="button" onclick="limpiarFiltroArbolMaster()" style="background:rgba(255,255,255,0.08); color:#fff; border:1px solid rgba(255,255,255,0.1);">Vista general</button>
                            </div>
                        </div>

                        <div id="master-tree-summary" class="master-tree-chip-row">
                            <span class="master-tree-chip">Cargando arbol general...</span>
                        </div>

                        <div id="master-network-tree" class="master-network-tree">
                            <div class="master-tree-empty">Cargando red general...</div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="section-clones" class="master-section">
                <div class="master-grid-bottom">
                    <div class="master-card">
                        <h4>Control de IA</h4>
                        <p style="font-size:0.72rem; color:#999; margin-bottom:12px;">Escribe el nickname del usuario que recibirá el Agente IA.</p>
                        <input type="text" id="clon-nickname-input" placeholder="Nickname del beneficiario" style="width:100%; padding:9px 12px; background:#111; border:1px solid #333; border-radius:8px; color:#fff; font-size:0.8rem; margin-bottom:10px; box-sizing:border-box;">
                        <button class="btn-master" onclick="activarClonManual()">⚡ ACTIVAR AGENTE IA</button>
                        <div id="clon-result" style="margin-top:10px; font-size:0.7rem;"></div>
                        <hr style="border-color:#333; margin:18px 0;">
                        <h4 style="margin-bottom:10px;">🔄 Reemplazar usuario por Agente IA</h4>
                        <p style="font-size:0.72rem; color:#999; margin-bottom:12px;">Busca un usuario que se registró pero nunca pagó y reemplázalo por un Agente IA en su posición.</p>
                        <input type="text" id="reemplazo-nickname-input" placeholder="Nickname del usuario a reemplazar" style="width:100%; padding:9px 12px; background:#111; border:1px solid #333; border-radius:8px; color:#fff; font-size:0.8rem; margin-bottom:10px; box-sizing:border-box;">
                        <button class="btn-master" style="background:linear-gradient(135deg,#ff6b35,#ff4500); width:100%;" onclick="buscarUsuarioParaReemplazar()">🔍 BUSCAR USUARIO</button>
                        <div id="reemplazo-preview" style="display:none; margin-top:14px; background:#1a1a1a; border:1px solid #333; border-radius:10px; padding:14px;">
                            <div id="reemplazo-preview-info" style="font-size:0.78rem; color:#ccc; margin-bottom:12px; line-height:1.6;"></div>
                            <div style="display:flex; gap:8px;">
                                <button class="btn-master" style="background:linear-gradient(135deg,#00e676,#00b248); flex:1;" onclick="confirmarReemplazo()">✅ CONFIRMAR REEMPLAZO</button>
                                <button class="btn-master" style="background:#333; flex:1;" onclick="cancelarReemplazo()">✖ CANCELAR</button>
                            </div>
                        </div>
                        <div id="reemplazo-result" style="margin-top:10px; font-size:0.73rem;"></div>
                    </div>
                    <div class="master-card">
                        <h4>Historial IA</h4>
                        <table class="master-table">
                            <thead>
                                <tr><th>Beneficiario</th><th>Costo</th><th>Fecha</th></tr>
                            </thead>
                            <tbody id="master-clones-history-body"></tbody>
                        </table>
                    </div>
                </div>
                <div class="master-card" style="margin-top:20px;">
                    <h4>🤖 Registro de Agentes</h4>
                        <table class="master-table">
                            <thead>
                                <tr><th>ID</th><th>Beneficiario</th><th>Fecha</th></tr>
                            </thead>
                            <tbody id="master-clones-full-body"></tbody>
                        </table>
                </div>
            </div>
            <div id="section-retiros" class="master-section"><div class="master-card"><h3>💰 Retiros Full</h3><div id="master-retiros-full-list"></div></div></div>
            <div id="section-auditoria" class="master-section">
                <div class="master-card">
                    <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:16px;">
                        <h4 style="margin:0;">📜 Centro de Actividad del Sistema</h4>
                        <button id="audit-refresh-btn" onclick="refreshAuditoria()"
                            style="background:rgba(255,255,255,0.06); border:1px solid #333; color:#aaa; border-radius:8px; padding:6px 14px; cursor:pointer; font-size:0.8rem;">
                            🔄 Actualizar
                        </button>
                    </div>
                    <!-- Filtros -->
                    <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px;">
                        <?php
                        $filtros = [
                            ['f'=>'all',      'label'=>'🗂 Todos'],
                            ['f'=>'tableros', 'label'=>'📊 Tableros'],
                            ['f'=>'clones',   'label'=>'🤖 Agentes IA'],
                            ['f'=>'retiros',  'label'=>'💸 Retiros'],
                            ['f'=>'fases',    'label'=>'🚀 Fases'],
                            ['f'=>'usuarios', 'label'=>'👤 Usuarios'],
                        ];
                        foreach($filtros as $fi): ?>
                        <button class="audit-filter-btn" data-f="<?= $fi['f'] ?>"
                            onclick="setAuditFiltro('<?= $fi['f'] ?>')"
                            style="background:<?= $fi['f']==='all'?'rgba(157,0,255,0.25)':'rgba(255,255,255,0.05)' ?>; border:1px solid <?= $fi['f']==='all'?'#9d00ff':'#333' ?>; color:<?= $fi['f']==='all'?'#e040fb':'#888' ?>; border-radius:8px; padding:5px 12px; cursor:pointer; font-size:0.78rem; white-space:nowrap;">
                            <?= $fi['label'] ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <!-- Leyenda de cascada -->
                    <div style="background:rgba(255,107,0,0.04); border:1px solid rgba(255,107,0,0.12); border-radius:8px; padding:8px 12px; margin-bottom:14px; font-size:0.74rem; color:#888; line-height:1.5;">
                        <strong style="color:#ff6d00;">⚡ Cascada automática</strong> — cuando varios eventos ocurren en menos de 30 segundos entre sí se agrupan como una cascada. Haz clic para expandir y ver cada paso.
                    </div>
                    <!-- Timeline -->
                    <div id="master-auditoria-timeline" style="max-height:600px; overflow-y:auto; padding-right:4px;">
                        <div style="text-align:center; padding:40px; color:#444;">Cargando actividad…</div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <script src="assets/js/dashboard.js?v=<?php echo filemtime(__DIR__ . '/assets/js/dashboard.js'); ?>"></script>
</body>
</html>
                                                            
