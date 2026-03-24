<?php
// dashboard.php - RADIX Phase 0 Premium Interface
$user_wallet = $_GET['wallet'] ?? '';
if (empty($user_wallet)) {
    die("Acceso denegado: Se requiere una billetera.");
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RADIX — Panel de Control Premium</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #030305;
            --primary: #9d00ff;
            --secondary: #00d2ff;
            --accent: #00e676;
            --card: rgba(255,255,255,0.03);
            --border: rgba(255,255,255,0.08);
            --shadow: 0 10px 30px rgba(0,0,0,0.5);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: var(--bg);
            color: #fff;
            font-family: 'Outfit', sans-serif;
            display: flex; min-height: 100vh;
            overflow-x: hidden;
        }

        /* ======= SIDEBAR ======= */
        aside {
            width: 280px;
            background: rgba(0,0,0,0.5);
            border-right: 1px solid var(--border);
            padding: 40px 20px;
            display: flex; flex-direction: column;
            backdrop-filter: blur(20px);
            z-index: 100;
        }
        .logo { font-size: 1.5rem; font-weight: 800; margin-bottom: 50px; text-align: center; background: linear-gradient(90deg, var(--secondary), var(--primary)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .nav-item { padding: 15px 20px; border-radius: 12px; color: #888; text-decoration: none; margin-bottom: 10px; transition: 0.3s; display: flex; align-items: center; gap: 15px; }
        .nav-item:hover, .nav-item.active { background: var(--card); color: #fff; border: 1px solid var(--border); }

        /* ======= MAIN CONTENT ======= */
        main { flex: 1; padding: 40px; }
        header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 40px; }
        .user-info { display: flex; align-items: center; gap: 15px; }
        .avatar { width: 45px; height: 45px; border-radius: 50%; background: var(--primary); display: flex; align-items: center; justify-content: center; font-weight: 800; border: 2px solid var(--border); }

        /* ======= WIDGETS ======= */
        .widgets { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 40px; }
        .widget { background: var(--card); border: 1px solid var(--border); border-radius: 20px; padding: 30px; position: relative; overflow: hidden; transition: 0.3s; }
        .widget h4 { font-size: 0.85rem; color: #888; text-transform: uppercase; margin-bottom: 10px; }
        .widget .value { font-size: 2rem; font-weight: 800; margin-bottom: 5px; }
        .widget .trend { font-size: 0.8rem; font-weight: 600; color: var(--accent); }

        /* ======= PROGRESS BOARDS ======= */
        .boards-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; }
        .board-container { background: var(--card); border: 1px solid var(--border); border-radius: 20px; padding: 30px; }
        .board-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        
        .progress-visual { display: flex; justify-content: space-between; position: relative; padding: 20px 0; margin-top: 40px; }
        .progress-line { position: absolute; height: 2px; background: var(--border); top: 50%; left: 0; width: 100%; transform: translateY(-50%); z-index: 1; }
        .progress-fill { position: absolute; height: 2px; background: var(--secondary); top: 50%; left: 0; width: 0%; transform: translateY(-50%); z-index: 2; box-shadow: 0 0 10px var(--secondary); transition: 1s ease; }
        
        .phase-node { width: 40px; height: 40px; border-radius: 50%; background: #111; border: 2px solid var(--border); z-index: 5; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.8rem; position: relative; transition: 0.5s; }
        .phase-node.completed { background: var(--secondary); border-color: #fff; box-shadow: 0 0 15px var(--secondary); }
        .phase-node.current { background: var(--primary); border-color: #fff; box-shadow: 0 0 15px var(--primary); }
        .phase-label { position: absolute; top: 50px; font-size: 0.75rem; color: #888; white-space: nowrap; left: 50%; transform: translateX(-50%); }

        .clones-container { background: var(--card); border: 1px solid var(--border); border-radius: 20px; padding: 30px; }
        .btn-withdraw { background: var(--accent); color: #000; padding: 12px 25px; border-radius: 10px; border: none; font-weight: 800; cursor: pointer; transition: 0.3s; }

        #loading-overlay { position: fixed; inset: 0; background: var(--bg); display: flex; align-items: center; justify-content: center; z-index: 999; }
    </style>
</head>
<body>

    <div id="loading-overlay">Cargando Sistema RADIX...</div>

    <aside>
        <div class="logo">RADIX SYSTEM</div>
        <nav>
            <a href="#" class="nav-item active">Dashboard</a>
            <a href="#" class="nav-item">Mi Equipo</a>
            <a href="#" class="nav-item">Mis Agentes IA</a>
            <a href="#" class="nav-item">Mi Wallet</a>
        </nav>
    </aside>

    <main>
        <header>
            <div>
                <h2 id="welcome-msg">Hola, ...</h2>
                <p id="wallet-address-display" style="color:#666; font-size:0.8rem;"></p>
            </div>
            <div class="user-info">
                <button class="btn-withdraw">RETIRAR SALDO</button>
                <div class="avatar" id="avatar-circle">?</div>
            </div>
        </header>

        <div class="widgets">
            <div class="widget">
                <h4>SALDO DISPONIBLE</h4>
                <div class="value" id="val-balance">$0.00 <span style="font-size:0.8rem; color:#555;">USDT</span></div>
                <div class="trend" id="val-trend">Esperando Ciclo</div>
            </div>
            <div class="widget">
                <h4>AGENTES IA ACTIVOS</h4>
                <div class="value" id="val-clones">0</div>
                <div class="trend" style="color:var(--primary);">Poder de Ayuda</div>
            </div>
            <div class="widget">
                <h4>FASE ACTUAL</h4>
                <div class="value" id="val-fase">Fase 0</div>
                <div class="trend" style="color:var(--secondary);" id="val-nivel">Cargando...</div>
            </div>
        </div>

        <div class="boards-grid">
            <div class="board-container">
                <div class="board-header">
                    <h3>Progreso de Camino</h3>
                    <div id="live-status" style="font-size:0.75rem; border:1px solid var(--accent); color:var(--accent); padding:2px 10px; border-radius:10px;">EN VIVO</div>
                </div>
                
                <div class="progress-visual">
                    <div class="progress-line"></div>
                    <div id="progress-fill" class="progress-fill"></div>
                    
                    <div id="node-a" class="phase-node">A <div class="phase-label">Tablero A</div></div>
                    <div id="node-b" class="phase-node">B <div class="phase-label">Tablero B</div></div>
                    <div id="node-c" class="phase-node">C <div class="phase-label">Tablero C</div></div>
                    <div id="node-f1" class="phase-node" style="border-style:dashed;">1 <div class="phase-label">Fase 1</div></div>
                </div>

                <div style="margin-top:100px; padding:20px; background:rgba(255,255,255,0.01); border-radius:15px; border:1px solid var(--border);">
                    <h5 style="color:var(--secondary);">PRÓXIMO OBJETIVO</h5>
                    <p id="objective-text" style="font-size:0.85rem; color:#888; margin-top:5px;">Cargando tus metas...</p>
                </div>
            </div>

            <div class="clones-container">
                <h3>Agentes IA en Red</h3>
                <p style="font-size:0.8rem; color:#555; margin-bottom:20px;">Tu éxito personal genera ayuda colectiva.</p>
                <div id="clones-list-summary">
                    <!-- Aquí se inyectarán detalles si los hubiera -->
                </div>
                <p id="no-clones-msg" style="font-size:0.8rem; color:#444; text-align:center;">Completa tableros para activar tus agentes.</p>
            </div>
        </div>
    </main>

    <script>
        const wallet = "<?php echo $user_wallet; ?>";

        async function loadDashboard() {
            try {
                const response = await fetch(`radix_api/user_data.php?wallet=${wallet}`);
                const data = await response.json();

                if (data.success) {
                    // 1. Datos personales
                    document.getElementById('welcome-msg').innerText = `Hola, ${data.user.nickname} 👋`;
                    document.getElementById('wallet-address-display').innerText = data.user.wallet;
                    document.getElementById('avatar-circle').innerText = data.user.nickname.substring(0,2).toUpperCase();
                    
                    // 2. Widgets
                    document.getElementById('val-balance').innerHTML = `$${data.earnings.toFixed(2)} <span style="font-size:0.8rem; color:#555;">USDT</span>`;
                    document.getElementById('val-clones').innerText = data.user.clones_count;
                    document.getElementById('val-nivel').innerText = `Nivel ${data.user.nivel} (Ciclo ${data.user.ciclo})`;
                    
                    if (data.earnings > 0) {
                        document.getElementById('val-trend').innerText = "+ Utilidad Neta";
                        document.getElementById('val-trend').style.color = "var(--accent)";
                    }

                    // 3. Progreso Visual
                    const nivel = data.user.nivel;
                    const fill = document.getElementById('progress-fill');
                    
                    if (nivel === 'A') {
                        fill.style.width = "0%";
                        document.getElementById('node-a').classList.add('current');
                        document.getElementById('objective-text').innerText = "Trae a tus 3 referidos para saltar al Tablero B y activar tu primer Agente IA.";
                    } else if (nivel === 'B') {
                        fill.style.width = "33%";
                        document.getElementById('node-a').classList.add('completed');
                        document.getElementById('node-b').classList.add('current');
                        document.getElementById('objective-text').innerText = "Tus 3 referidos deben saltar al Tablero B para llevarte al Tablero C.";
                    } else if (nivel === 'C') {
                        fill.style.width = "66%";
                        document.getElementById('node-a').classList.add('completed');
                        document.getElementById('node-b').classList.add('completed');
                        document.getElementById('node-c').classList.add('current');
                        document.getElementById('objective-text').innerText = "¡Meta final! Al completar el C, saltas automáticamente a Fase 1 con $100 y cobras tus $40.";
                    }

                    if (data.user.clones_count > 0) {
                        document.getElementById('no-clones-msg').style.display = "none";
                        document.getElementById('clones-list-summary').innerHTML = `<p style='color:var(--accent); font-size:rem;'>✅ Tienes ${data.user.clones_count} agentes inyectando liquidez en la red.</p>`;
                    }

                } else {
                    alert("Error al cargar datos: " + data.error);
                }
            } catch (error) {
                console.error("Error:", error);
            } finally {
                document.getElementById('loading-overlay').style.display = "none";
            }
        }

        window.onload = loadDashboard;
    </script>
</body>
</html>
