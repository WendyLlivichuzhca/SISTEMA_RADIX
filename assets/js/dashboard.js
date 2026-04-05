/**
 * dashboard.js — RADIX V3 — Command Center Logic
 */

/* ── PREMIUM HELPERS — V4.0 ──────────────────────────────────── */

/**
 * animateValue — smooth count-up animation for numeric elements
 * @param {HTMLElement} el   - target element
 * @param {number}      end  - final value
 * @param {string}      pre  - prefix (e.g. "$")
 * @param {string}      suf  - suffix (e.g. " USDT")
 * @param {boolean}     dec  - show 2 decimal places
 */
function animateValue(el, end, pre = '', suf = '', dec = false) {
    if (!el) return;
    const start    = 0;
    const duration = 900;
    const startTs  = performance.now();
    const fmt = v => dec ? v.toFixed(2) : Math.floor(v).toString();
    function step(ts) {
        const elapsed  = ts - startTs;
        const progress = Math.min(elapsed / duration, 1);
        // ease-out cubic
        const eased = 1 - Math.pow(1 - progress, 3);
        el.innerText = pre + fmt(start + (end - start) * eased) + suf;
        if (progress < 1) requestAnimationFrame(step);
        else {
            el.innerText = pre + fmt(end) + suf;
            el.classList.add('num-pop');
            setTimeout(() => el.classList.remove('num-pop'), 500);
        }
    }
    requestAnimationFrame(step);
}

/**
 * updateSidebarWallet — show truncated wallet in sidebar once data loads
 */
function updateSidebarWallet(wallet) {
    const el = document.getElementById('sidebar-wallet-short');
    if (!el || !wallet) return;
    el.textContent = wallet.length > 12
        ? wallet.substring(0, 6) + '…' + wallet.substring(wallet.length - 4)
        : wallet;
}

/* ─────────────────────────────────────────────────────────────── */

let _saldoActual        = 0;
let _fase0Completada    = false;
let _historialData      = [];
let _dashboardContext   = null;
let _masterUserList     = [];
let _masterRetirosList  = [];
let _masterAuditoria    = [];
let _chartInstance      = null;
let _lastEventTimestamp = Math.floor(Date.now() / 1000);
let _profileSnapshot    = null;
let _masterTreeZoom     = null;
let _masterTreeSvg      = null;
let _masterTreeInitialTransform = null;
let _activeMasterToolPanel = null;
let _masterStatsFilterState = {
    fase_numero: 'all',
    tablero_tipo: 'all',
    ciclo: 'all',
    tipo_usuario: 'all'
};

// ── NAVEGACIÓN POR SECCIONES DE USUARIO ──────────────────────────────

let _currentUserSection  = 'overview';
let _phaseOverviewCache  = [];
let _earningsPorFase     = [];   // [{fase_numero, saldo_disponible, tablero_c_ok, puede_retirar, …}]
let _retiroFaseActual    = 0;    // Fase activa cuando el modal de retiro está abierto

/**
 * Cambia entre las secciones del panel de usuario:
 * 'overview' | 'fase-0' | 'fase-1' | 'fase-2' | 'fase-3'
 */
function switchUserSection(section) {
    _currentUserSection = section;

    // Ocultar secciones de fase
    [0,1,2,3].forEach(fn => {
        const el = document.getElementById(`section-fase-${fn}`);
        if (el) el.style.display = 'none';
    });

    // Mostrar/ocultar sección overview (los elementos normales del dashboard)
    const overviewEls = document.querySelectorAll('.user-overview-block');
    overviewEls.forEach(el => {
        el.style.display = (section === 'overview') ? '' : 'none';
    });

    // Si es una fase específica, mostrar esa sección
    if (section !== 'overview') {
        const fn = parseInt(section.replace('fase-', ''), 10);
        const sectionEl = document.getElementById(`section-fase-${fn}`);
        if (sectionEl) {
            sectionEl.style.display = 'block';
            // Render del árbol de red solo la primera vez (lazy)
            if (!sectionEl.dataset.treeLoaded) {
                sectionEl.dataset.treeLoaded = '1';
                renderFaseNetworkTree(fn);
            }
        }
    }

    // Actualizar nav activo
    document.querySelectorAll('.user-dashboard-shell .nav-item').forEach(el => el.classList.remove('active'));
    const navId = section === 'overview' ? 'nav-user-overview' : `nav-user-fase-${section.replace('fase-','')}`;
    const navEl = document.getElementById(navId);
    if (navEl) navEl.classList.add('active');
}

/**
 * Renderiza la sección de una fase específica usando los datos de phase_overview.
 */
function renderFaseSection(faseData, earnings, reservas = {}) {
    if (!faseData) return;
    const fn  = Number(faseData.fase_numero);
    const hasActivity = faseData.has_activity;

    // Badge de estado
    const badge = document.getElementById(`fase-badge-${fn}`);
    if (badge) {
        const stateMap = {
            'en_progreso': 'En Progreso',
            'completada':  'Completada',
            'historial':   'Historial',
            'sin_iniciar': faseData.activa_config ? 'Preparada' : 'Próximamente',
        };
        badge.textContent = stateMap[faseData.estado_usuario] || 'Preparada';
        badge.className   = 'fase-section-badge fase-badge-' + (faseData.estado_usuario || 'sin_iniciar');
    }

    // Título de fase con nombre
    const title = document.getElementById(`fase-title-${fn}`);
    if (title) title.textContent = faseData.fase_nombre || `Fase ${fn}`;

    // Saldo real por fase — se toma de _earningsPorFase para todas las fases
    const balEl = document.getElementById(`f${fn}-balance`);
    if (balEl) {
        const fasePay = _earningsPorFase.find(e => e.fase_numero === fn);
        const saldoFase = fasePay ? fasePay.saldo_disponible : 0;
        animateValue(balEl, saldoFase, '$', '', true);
    }

    // Tablero actual
    const tableroEl = document.getElementById(`f${fn}-tablero`);
    if (tableroEl) {
        if (faseData.current_board) {
            tableroEl.textContent = `C${faseData.current_board.ciclo}-${faseData.current_board.tablero_tipo}`;
        } else {
            tableroEl.textContent = hasActivity ? 'Completado' : '—';
        }
    }

    // Agentes IA
    const clonesEl = document.getElementById(`f${fn}-clones`);
    if (clonesEl) animateValue(clonesEl, faseData.team_clones || 0, '', '', false);

    // Equipo
    const equipoEl = document.getElementById(`f${fn}-equipo`);
    if (equipoEl) animateValue(equipoEl, faseData.team_reales || 0, '', '', false);

    // Reserva — muestra la semilla acumulada hacia la SIGUIENTE fase
    // Fase 0 reserva → hacia Fase 1 (reservas.fase1)
    // Fase 1 reserva → hacia Fase 2 (reservas.fase2)
    // Fase 2 reserva → hacia Fase 3 (reservas.fase3)
    const reservaEl = document.getElementById(`f${fn}-reserva`);
    if (reservaEl) {
        const reservaKeyMap = { 0: 'fase1', 1: 'fase2', 2: 'fase3' };
        const reservaKey  = reservaKeyMap[fn];
        const reservaReal = reservaKey ? parseFloat(reservas[reservaKey] || 0) : 0;
        if (reservaReal > 0) {
            reservaEl.style.color    = '';
            reservaEl.style.fontSize = '';
            animateValue(reservaEl, reservaReal, '$', '', true);
        } else {
            reservaEl.textContent    = 'Pendiente';
            reservaEl.style.color    = '#666';
            reservaEl.style.fontSize = '0.85rem';
        }
    }

    // Ciclos completados
    const ciclosEl = document.getElementById(`f${fn}-ciclos`);
    if (ciclosEl) animateValue(ciclosEl, faseData.completed_cycles || 0, '', '', false);

    // Barra de progreso
    const fill     = document.getElementById(`f${fn}-progress-fill`);
    const pctLabel = document.getElementById(`f${fn}-progress-pct`);
    const pct      = faseData.board_progress_percent || 0;
    if (fill) fill.style.width = pct + '%';
    if (pctLabel) pctLabel.textContent = pct + '%';

    // Nodos A/B/C
    const board = faseData.current_board?.tablero_tipo;
    ['a','b','c'].forEach((letter, i) => {
        const node = document.getElementById(`f${fn}-node-${letter}`);
        if (!node) return;
        const idx = {'A':0,'B':1,'C':2}[board] ?? -1;
        if (faseData.estado_usuario === 'completada' || i < idx) node.className = 'phase-node completed';
        else if (i === idx) node.className = 'phase-node current';
        else node.className = 'phase-node';
    });

    // Botón RETIRAR: mostrar si el usuario puede retirar de esta fase específica
    const retiroArea = document.getElementById(`f${fn}-retiro-area`);
    if (retiroArea) {
        const fasePay = _earningsPorFase.find(e => e.fase_numero === fn);
        const puedeRetirar = fasePay ? fasePay.puede_retirar : false;
        retiroArea.style.display = puedeRetirar ? 'block' : 'none';

        // Actualizar saldo visible en el área de retiro (etiqueta f{n}-balance-retiro si existe)
        if (fasePay) {
            const retSaldoEl = document.getElementById(`f${fn}-balance-retiro`);
            if (retSaldoEl) retSaldoEl.innerText = `$${fasePay.saldo_disponible.toFixed(2)} USDT`;

            // Mensaje si tiene retiro pendiente
            const retMsg = document.getElementById(`f${fn}-retiro-msg`);
            if (retMsg) {
                if (fasePay.tiene_pendiente) {
                    retMsg.innerText = '⏳ Ya tienes un retiro pendiente en esta fase.';
                    retMsg.style.display = 'block';
                } else {
                    retMsg.style.display = 'none';
                }
            }
        }
    }

    // Coming soon: si no hay actividad
    const cs = document.getElementById(`f${fn}-coming-soon`);
    const sb = document.getElementById(`sb-fase-${fn}`);
    const prog = document.querySelector(`#section-fase-${fn} .user-feature-card`);
    const grid = document.querySelector(`#section-fase-${fn} .user-main-grid`);
    if (!hasActivity && faseData.estado_usuario === 'sin_iniciar') {
        if (cs) cs.style.display = 'flex';
        if (sb) sb.style.display = 'none';
        if (prog) prog.style.display = 'none';
        if (grid) grid.style.display = 'none';
    } else {
        if (cs) cs.style.display = 'none';
        if (sb) sb.style.display = '';
        if (prog) prog.style.display = '';
        if (grid) grid.style.display = '';
    }
}

/**
 * drawNetworkTreeInContainer — dibuja el árbol de red en un contenedor dado.
 * Reutiliza la misma lógica de renderNetworkTree() pero acepta el contenedor
 * como parámetro, permitiendo usarlo en las secciones de fase individuales.
 */
function drawNetworkTreeInContainer(container, data) {
    if (!data.success || !data.arbol) {
        container.innerHTML = '<div style="color:#444; font-size:0.85rem; text-align:center; padding:40px;">Sin datos de red aún.</div>';
        return;
    }

    // Convertir árbol plano a jerarquía D3
    const root = d3.hierarchy(data.arbol, d => d.hijos && d.hijos.length ? d.hijos : null);
    const isMobile = window.innerWidth <= 768;
    const leafCount = Math.max(root.leaves().length, 1);
    const depthCount = Math.max(root.height + 1, 1);
    const compactMode = leafCount >= 5;
    const rootRadius = isMobile ? 20 : (compactMode ? 22 : 24);
    const childRadius = isMobile ? 14 : (compactMode ? 16 : 18);
    const verticalGap = isMobile ? 150 : (compactMode ? 165 : 185);
    const horizontalPadding = isMobile ? 18 : (compactMode ? 28 : 40);
    const topMargin = isMobile ? 78 : 96;
    const bottomMargin = isMobile ? 120 : 138;
    const allowHorizontalScroll = isMobile;

    container.innerHTML = '';
    container.style.display = 'block';
    container.style.alignItems = 'stretch';
    container.style.justifyContent = 'flex-start';
    container.style.overflow = 'hidden';
    container.style.padding = '0';

    const scroller = document.createElement('div');
    scroller.className = 'network-tree-scroller';
    scroller.style.overflowX = allowHorizontalScroll ? 'auto' : 'hidden';
    scroller.style.overflowY = 'hidden';
    scroller.style.width = '100%';
    scroller.style.padding = isMobile ? '14px 10px 18px' : '20px 18px 22px';
    scroller.style.boxSizing = 'border-box';
    container.appendChild(scroller);

    const baseWidth = scroller.clientWidth || container.clientWidth || 320;
    const innerWidth = allowHorizontalScroll
        ? Math.max(baseWidth - 6, leafCount * 120)
        : Math.max(baseWidth - horizontalPadding * 2, 280);
    const innerHeight = Math.max((depthCount - 1) * verticalGap, isMobile ? 220 : 270);

    const treeLayout = d3.tree().size([innerWidth, innerHeight]);
    treeLayout(root);

    const W = allowHorizontalScroll
        ? innerWidth + horizontalPadding * 2
        : baseWidth;
    const H = innerHeight + topMargin + bottomMargin;
    const offsetX = horizontalPadding;
    const offsetY = topMargin;

    const svg = d3.select(scroller)
        .append('svg')
        .attr('width', W)
        .attr('height', H)
        .attr('viewBox', `0 0 ${W} ${H}`)
        .style('width', allowHorizontalScroll ? `${W}px` : '100%')
        .style('min-width', allowHorizontalScroll ? `${W}px` : '100%')
        .style('display', 'block')
        .style('overflow', 'visible');

    const g = svg.append('g').attr('transform', `translate(${offsetX}, ${offsetY})`);

    // Degradado de líneas (ID único para evitar conflictos entre renders)
    const defs = svg.append('defs');
    const gradId = 'linkGrad_fase_' + Date.now();
    const grad = defs.append('linearGradient')
        .attr('id', gradId)
        .attr('gradientUnits', 'userSpaceOnUse')
        .attr('x1', 0).attr('y1', 0)
        .attr('x2', 0).attr('y2', H);
    grad.append('stop').attr('offset','0%').attr('stop-color','#9d00ff').attr('stop-opacity', 0.9);
    grad.append('stop').attr('offset','100%').attr('stop-color','#00d2ff').attr('stop-opacity', 0.9);

    // Links (líneas)
    const nodeRadius = (nodeData) => nodeData.data.es_raiz ? rootRadius : childRadius;
    const linkSegments = [];

    root.descendants().forEach(parent => {
        const children = parent.children || [];
        if (!children.length) return;

        const parentBottomY = parent.y + nodeRadius(parent) + 2;
        const childTopYs = children.map(child => child.y - nodeRadius(child) - 8);

        if (children.length === 1) {
            linkSegments.push({ x1: parent.x, y1: parentBottomY, x2: children[0].x, y2: childTopYs[0] });
            return;
        }

        let branchY = parentBottomY + (isMobile ? 16 : 20);
        const highestChildTop = Math.min(...childTopYs);
        if (branchY > highestChildTop - 18) {
            branchY = parentBottomY + Math.max(10, (highestChildTop - parentBottomY) * 0.35);
        }

        const childXs = children.map(child => child.x);
        linkSegments.push({ x1: parent.x, y1: parentBottomY, x2: parent.x, y2: branchY });
        linkSegments.push({ x1: Math.min(...childXs), y1: branchY, x2: Math.max(...childXs), y2: branchY });
        children.forEach((child, index) => {
            linkSegments.push({ x1: child.x, y1: branchY, x2: child.x, y2: childTopYs[index] });
        });
    });

    g.selectAll('.link-segment')
        .data(linkSegments)
        .enter().append('path')
        .attr('class', 'link-segment')
        .attr('d', d => `M${d.x1},${d.y1} L${d.x2},${d.y2}`)
        .attr('fill', 'none')
        .attr('stroke', () => `url(#${gradId})`)
        .attr('stroke-width', isMobile ? 2.4 : 2.8)
        .attr('stroke-linecap', 'round')
        .attr('opacity', 0.96);

    // Nodos
    const node = g.selectAll('.node')
        .data(root.descendants())
        .enter().append('g')
        .attr('class', 'node')
        .attr('transform', d => `translate(${d.x},${d.y})`);

    const getColor = (d) => {
        if (d.data.es_raiz)                      return '#9d00ff';
        if (d.data.tipo_usuario === 'clon')       return '#ff9800';
        if (d.data.pago_estado === 'completado')  return '#00e676';
        if (d.data.pago_estado === 'pendiente')   return '#ff5252';
        return '#00d2ff';
    };

    // Círculo con glow
    node.append('circle')
        .attr('r', 0)
        .attr('fill', d => getColor(d))
        .attr('stroke', '#0a0a12')
        .attr('stroke-width', 2)
        .style('filter', d => `drop-shadow(0 0 10px ${getColor(d)})`)
        .transition().duration(500).delay((d, i) => i * 120)
        .attr('r', d => nodeRadius(d));

    // Inicial del nickname dentro del círculo
    node.append('text')
        .attr('text-anchor', 'middle')
        .attr('dy', '0.35em')
        .attr('font-size', d => d.data.es_raiz ? (isMobile ? '8px' : '9px') : (isMobile ? '7px' : '8px'))
        .attr('font-weight', '800')
        .attr('fill', '#000')
        .text(d => getDisplayInitials(d.data));

    // Nickname debajo del nodo
    node.append('text')
        .attr('text-anchor', 'middle')
        .attr('dy', d => d.data.es_raiz ? '44px' : '36px')
        .attr('font-size', isMobile ? '8px' : '10px')
        .attr('fill', '#c7ccda')
        .text(d => {
            const nick = getDisplayName(d.data);
            const maxLen = isMobile ? 11 : 14;
            return nick.length > maxLen ? nick.substring(0, maxLen) + '…' : nick;
        });

    // Tablero badge encima del nodo raíz
    if (root.data.tablero_actual) {
        const phaseLabel = data.fase_nombre || root.data.fase_nombre || `Fase ${data.fase_numero ?? root.data.fase_numero ?? 0}`;
        const tableroLabel = (root.data.tablero_actual === 'FASE_COMPLETADA' || root.data.tablero_actual === 'FASE0_COMPLETADA')
            ? `${phaseLabel} completa`
            : `${phaseLabel} · Tablero ${root.data.tablero_actual}`;
        g.select('.node')
         .append('text')
         .attr('text-anchor', 'middle')
         .attr('dy', isMobile ? '-30px' : '-34px')
         .attr('font-size', isMobile ? '8px' : '9px')
         .attr('fill', '#9d00ff')
         .text(tableroLabel);
    }

    // Leyenda
    const leyenda = [
        { color: '#9d00ff', label: 'Tú' },
        { color: '#00e676', label: 'Pagó' },
        { color: '#ff5252', label: 'Pendiente' },
        { color: '#ff9800', label: 'Agente IA' },
        { color: '#00d2ff', label: 'Nuevo' },
    ];
    const legendItems = isMobile ? leyenda.slice(0, 4) : leyenda;
    const legendSpacing = isMobile ? 76 : 90;
    const legendWidth = Math.max((legendItems.length - 1) * legendSpacing + 72, 120);
    const legendX = Math.max((W - legendWidth) / 2, 12);
    const legG = svg.append('g').attr('transform', `translate(${legendX}, ${H - (isMobile ? 24 : 28)})`);
    legendItems.forEach((l, i) => {
        legG.append('circle').attr('cx', i * legendSpacing).attr('cy', 0).attr('r', isMobile ? 4.5 : 5).attr('fill', l.color);
        legG.append('text')
            .attr('x', i * legendSpacing + 10)
            .attr('y', 4)
            .attr('font-size', isMobile ? '8px' : '9px')
            .attr('fill', '#666')
            .text(l.label);
    });
}

/**
 * Carga el árbol de red para una fase específica (lazy load).
 */
async function renderFaseNetworkTree(fn) {
    const container = document.getElementById(`f${fn}-network-tree`);
    if (!container) return;
    container.innerHTML = '<div style="color:#555; font-size:0.85rem; text-align:center; padding:40px;">Cargando red...</div>';
    try {
        const params = new URLSearchParams({ fase_numero: fn });
        const res = await fetch(`radix_api/network_tree.php?${params}`);
        const data = await res.json();
        if (data && data.success && data.arbol) {
            drawNetworkTreeInContainer(container, data);
        } else {
            container.innerHTML = '<div style="color:#444; font-size:0.85rem; text-align:center; padding:40px;">Sin red disponible para esta fase.</div>';
        }
    } catch(e) {
        container.innerHTML = '<div style="color:#555; font-size:0.85rem; text-align:center; padding:40px;">No se pudo cargar la red.</div>';
    }
}

// ── FIN NAVEGACIÓN POR FASES ─────────────────────────────────────────

function getDisplayName(entity) {
    if (!entity) return '';
    return entity.display_name || entity.nombre_completo || entity.nickname || '';
}

function getDisplayInitials(entity) {
    const label = getDisplayName(entity).trim();
    if (!label) return '?';

    const parts = label
        .split(/[\s_-]+/)
        .map(part => part.trim())
        .filter(Boolean);

    if (parts.length >= 2) {
        return (parts[0][0] + parts[1][0]).toUpperCase();
    }

    return label.substring(0, 2).toUpperCase();
}

function normalizeDashboardCopy() {
    const userHeroTitle = document.querySelector('.user-hero-copy h3');
    const userHeroText = document.querySelector('.user-hero-copy p');
    const userHeroBadges = document.querySelectorAll('.user-hero-badge');

    if (userHeroTitle) {
        userHeroTitle.textContent = 'Tu panel ya está listo para operar y crecer dentro de la red.';
    }

    if (userHeroText) {
        userHeroText.textContent = 'Desde aquí puedes seguir tu activación, revisar tu tablero actual, monitorear tu equipo y entender visualmente en qué parte del ciclo te encuentras.';
    }

    userHeroBadges.forEach((badge) => {
        const label = badge.querySelector('.user-hero-badge-label')?.textContent?.trim();
        const value = badge.querySelector('strong');
        if (!value) return;

        if (label === 'Wallet Real') {
            value.textContent = 'RADIX_MASTER';
        }

        if (label === 'Modelo') {
            value.textContent = 'Red 3x1 por ciclos';
        }
    });
}

function ensurePhaseContextNote() {
    let note = document.getElementById('phase-context-note');
    if (note) return note;

    const anchor = document.querySelector('.user-hero-copy p');
    if (!anchor || !anchor.parentNode) return null;

    note = document.createElement('div');
    note.id = 'phase-context-note';
    note.style.marginTop = '12px';
    note.style.color = '#7f86a7';
    note.style.fontSize = '0.78rem';
    note.style.lineHeight = '1.6';
    anchor.insertAdjacentElement('afterend', note);
    return note;
}

function getDashboardContext(data) {
    const fallbackPhase = Number(data?.tablero?.fase_numero ?? data?.user?.fase_numero ?? 0);
    const fallbackCycle = Number(data?.tablero?.ciclo ?? data?.user?.ciclo ?? 1);
    const fallbackLevel = data?.tablero?.tipo || data?.user?.nivel || 'A';
    const context = data?.dashboard_context || {};
    const faseNumero = Number(context.fase_numero ?? fallbackPhase);

    return {
        fase_numero: faseNumero,
        fase_nombre: context.fase_nombre || data?.tablero?.fase_nombre || data?.user?.fase_nombre || `Fase ${faseNumero}`,
        ciclo: Number(context.ciclo ?? fallbackCycle),
        nivel: context.nivel || fallbackLevel,
        tablero_tipo: context.tablero_tipo || data?.tablero?.tipo || null,
        eyebrow: context.eyebrow || `RADIX PHASE ${faseNumero}`
    };
}

function formatBoardContextLabel(context) {
    if (!context) return 'F0 · C1-A';

    const faseNumero = Number(context.fase_numero ?? 0);
    const ciclo = Number(context.ciclo ?? 1);
    const nivel = context.tablero_tipo || context.nivel || 'A';

    if (nivel === 'FASE_COMPLETADA' || nivel === 'FASE0_COMPLETADA') {
        return `F${faseNumero} · C${ciclo} completa`;
    }

    return `F${faseNumero} · C${ciclo}-${nivel}`;
}

function updateDashboardPhaseCopy(data) {
    const context = _dashboardContext || getDashboardContext(data);
    const fn      = Number(context.fase_numero || 0);
    const board   = (context.tablero_tipo || context.nivel || 'A').toUpperCase();

    // ── Colores por fase ─────────────────────────────────────────
    const faseColors = {
        0: '#00d2ff',
        1: '#9d00ff',
        2: '#00e676',
        3: '#ffb300'
    };
    const color = faseColors[fn] || '#00d2ff';

    // ── Badge de fase ─────────────────────────────────────────────
    const faseDot   = document.getElementById('hero-fase-dot');
    const faseLabel = document.getElementById('hero-fase-label');
    if (faseDot)   { faseDot.style.background = color; faseDot.style.boxShadow = `0 0 8px ${color}80`; }
    if (faseLabel) faseLabel.textContent = `Fase ${fn} · Tablero ${board}`;

    // ── Nodos A / B / C ──────────────────────────────────────────
    const nodes   = { A: 'a', B: 'b', C: 'c' };
    const order   = ['A', 'B', 'C'];
    const current = order.indexOf(board); // 0=A, 1=B, 2=C

    order.forEach((ltr, idx) => {
        const circle = document.getElementById(`hero-node-${nodes[ltr]}-circle`);
        if (!circle) return;
        circle.classList.remove('node-done', 'node-active');
        if (idx < current) {
            circle.classList.add('node-done');
            circle.style.borderColor = color;
            circle.style.color       = color;
            circle.style.background  = `${color}1a`;
            circle.style.boxShadow   = `0 0 12px ${color}40`;
        } else if (idx === current) {
            circle.classList.add('node-active');
            circle.style.borderColor = color;
            circle.style.color       = color;
            circle.style.background  = `${color}22`;
            circle.style.boxShadow   = `0 0 16px ${color}55`;
        } else {
            circle.style.borderColor = '';
            circle.style.color       = '';
            circle.style.background  = '';
            circle.style.boxShadow   = '';
        }
    });

    // Tracks A→B y B→C
    const trackAB = document.getElementById('hero-track-ab');
    const trackBC = document.getElementById('hero-track-bc');
    if (trackAB) { trackAB.classList.toggle('filled', current >= 1); }
    if (trackBC) { trackBC.classList.toggle('filled', current >= 2); }

    // ── Pills de stats ────────────────────────────────────────────
    // Equipo: suma de slots ocupados en la fase activa
    const fasePhases = data.phase_overview || [];
    const faseData   = fasePhases.find(p => Number(p.fase_numero) === fn);
    const equipoTotal = faseData ? (Number(faseData.slots_ocupados_a || 0) + Number(faseData.slots_ocupados_b || 0) + Number(faseData.slots_ocupados_c || 0)) : 0;

    const pillEquipo  = document.getElementById('hero-pill-equipo');
    const pillSaldo   = document.getElementById('hero-pill-saldo');
    const pillTablero = document.getElementById('hero-pill-tablero');

    if (pillEquipo)  pillEquipo.textContent  = equipoTotal > 0 ? equipoTotal : '0';
    if (pillSaldo)   pillSaldo.textContent   = `$${parseFloat(data.earnings || 0).toFixed(2)}`;
    if (pillTablero) pillTablero.textContent = `${board} · F${fn}`;
}

function setProfileStatus(targetId, message = '', color = '#7f86a7') {
    const el = document.getElementById(targetId);
    if (!el) return;
    el.textContent = message;
    el.style.color = color;
}

function normalizeTelegramUsernameValue(value) {
    return (value || '').replace(/\s+/g, '').replace(/^@+/, '');
}

function telegramUsernameValueIsValid(value) {
    return value === '' || /^[A-Za-z0-9_]{5,32}$/.test(value);
}

function profilePhoneValueIsValid(value) {
    const digits = (value || '').replace(/\D+/g, '');
    return digits.length >= 7 && digits.length <= 20;
}

function openProfileModal() {
    const modal = document.getElementById('profile-modal');
    if (!modal) return;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    loadProfilePanel();
}

function closeProfileModal() {
    const modal = document.getElementById('profile-modal');
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
}

function wireProfileTriggers() {
    const avatar = document.getElementById('avatar-circle');
    if (avatar && !avatar.dataset.profileBound) {
        avatar.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                openProfileModal();
            }
        });
        avatar.dataset.profileBound = '1';
    }

    document.querySelectorAll('a.nav-item').forEach((link) => {
        if ((link.textContent || '').includes('Mi Perfil')) {
            link.onclick = (event) => {
                event.preventDefault();
                openProfileModal();
                return false;
            };
        }
    });
}

window.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        closeProfileModal();
    }
});

function applyProfileVisuals(profile) {
    if (!profile) return;

    const displayName = (profile.display_name || profile.nombre_completo || profile.nickname || '').trim();
    const nickname = profile.nickname || '';
    const wallet = profile.wallet || '';
    const telegramUsername = normalizeTelegramUsernameValue(profile.telegram_username || '');
    const initials = getDisplayInitials({
        display_name: displayName,
        nickname,
    });

    const welcomeEl = document.getElementById('welcome-msg');
    const avatarCircle = document.getElementById('avatar-circle');
    const sidebarNick = document.getElementById('sidebar-nickname-text');
    const sidebarAvatar = document.getElementById('sidebar-avatar-big');
    const summaryAvatar = document.getElementById('profile-summary-avatar');
    const summaryName = document.getElementById('profile-summary-name');
    const summaryNick = document.getElementById('profile-summary-nick');
    const summaryWallet = document.getElementById('profile-summary-wallet');
    const summaryTelegram = document.getElementById('profile-summary-telegram');

    if (welcomeEl && displayName) welcomeEl.textContent = `Hola, ${displayName}`;
    if (avatarCircle) avatarCircle.textContent = initials;
    if (sidebarNick && displayName) sidebarNick.textContent = displayName;
    if (sidebarAvatar) sidebarAvatar.textContent = initials.charAt(0);
    if (summaryAvatar) summaryAvatar.textContent = initials;
    if (summaryName && displayName) summaryName.textContent = displayName;
    if (summaryNick) summaryNick.textContent = nickname ? `@${nickname}` : 'Sin nick';
    if (summaryWallet && wallet) summaryWallet.textContent = wallet;
    if (summaryTelegram) {
        summaryTelegram.textContent = telegramUsername
            ? `Telegram de perfil: @${telegramUsername}`
            : 'Telegram de perfil: No registrado';
    }
    if (wallet) updateSidebarWallet(wallet);
}

function fillProfileForm(profile) {
    if (!profile) return;

    const nicknameInput = document.getElementById('profile-nickname');
    const walletInput = document.getElementById('profile-wallet');
    const nombreInput = document.getElementById('profile-nombre');
    const telefonoInput = document.getElementById('profile-telefono');
    const correoInput = document.getElementById('profile-correo');
    const telegramInput = document.getElementById('profile-telegram');
    const passwordBadge = document.getElementById('profile-password-badge');
    const currentPasswordInput = document.getElementById('profile-current-password');

    if (nicknameInput) nicknameInput.value = profile.nickname || '';
    if (walletInput) walletInput.value = profile.wallet || '';
    if (nombreInput) nombreInput.value = profile.nombre_completo || '';
    if (telefonoInput) telefonoInput.value = profile.telefono || '';
    if (correoInput) correoInput.value = profile.correo_electronico || '';
    if (telegramInput) {
        const telegramUsername = normalizeTelegramUsernameValue(profile.telegram_username || '');
        telegramInput.value = telegramUsername ? `@${telegramUsername}` : '';
    }

    if (passwordBadge) {
        passwordBadge.textContent = profile.has_password ? 'Protegido' : 'Sin contraseña';
        passwordBadge.style.color = profile.has_password ? 'var(--secondary)' : '#ffb300';
        passwordBadge.style.borderColor = profile.has_password ? 'rgba(0,210,255,0.18)' : 'rgba(255,179,0,0.18)';
        passwordBadge.style.background = profile.has_password ? 'rgba(0,210,255,0.08)' : 'rgba(255,179,0,0.08)';
    }

    if (currentPasswordInput) {
        currentPasswordInput.placeholder = profile.has_password
            ? 'Ingresa tu contraseña actual'
            : 'No tienes contraseña previa';
    }

    applyProfileVisuals(profile);
}

async function loadProfilePanel(forceReload = false) {
    const panel = document.getElementById('profile-panel');
    if (!panel) return;

    if (_profileSnapshot && !forceReload) {
        fillProfileForm(_profileSnapshot);
        return;
    }

    setProfileStatus('profile-status', 'Cargando perfil...', '#7f86a7');

    try {
        const res = await fetch('radix_api/profile_get.php');
        const data = await res.json();

        if (!data.success || !data.profile) {
            setProfileStatus('profile-status', data.error || 'No se pudo cargar el perfil.', '#ff5252');
            return;
        }

        _profileSnapshot = data.profile;
        fillProfileForm(_profileSnapshot);
        setProfileStatus('profile-status', 'Perfil listo para editar.', '#7f86a7');
    } catch (e) {
        setProfileStatus('profile-status', 'Error al cargar tu perfil.', '#ff5252');
    }
}

function recargarPerfil() {
    if (_profileSnapshot) {
        fillProfileForm(_profileSnapshot);
        setProfileStatus('profile-status', 'Cambios restablecidos.', '#7f86a7');
        return;
    }
    loadProfilePanel(true);
}

async function guardarPerfil() {
    const nombreInput = document.getElementById('profile-nombre');
    const telefonoInput = document.getElementById('profile-telefono');
    const correoInput = document.getElementById('profile-correo');
    const telegramInput = document.getElementById('profile-telegram');

    if (!nombreInput || !telefonoInput || !correoInput || !telegramInput) return;

    const nombre = nombreInput.value.trim();
    const telefono = telefonoInput.value.trim();
    const correo = correoInput.value.trim();
    const telegramUsername = normalizeTelegramUsernameValue(telegramInput.value);

    if (!nombre || !telefono || !correo) {
        setProfileStatus('profile-status', 'Completa nombre, teléfono y correo.', '#ff5252');
        return;
    }

    if (nombre.length < 3) {
        setProfileStatus('profile-status', 'El nombre debe tener al menos 3 caracteres.', '#ff5252');
        return;
    }

    if (!profilePhoneValueIsValid(telefono)) {
        setProfileStatus('profile-status', 'El telefono no es valido.', '#ff5252');
        return;
    }

    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(correo)) {
        setProfileStatus('profile-status', 'El correo electrónico no es válido.', '#ff5252');
        return;
    }

    if (!telegramUsernameValueIsValid(telegramUsername)) {
        setProfileStatus('profile-status', 'El usuario de Telegram no es valido. Usa 5 a 32 letras, numeros o _.', '#ff5252');
        return;
    }

    setProfileStatus('profile-status', 'Guardando cambios...', '#7f86a7');

    try {
        const formData = new FormData();
        formData.append('nombre_completo', nombre);
        formData.append('telefono', telefono);
        formData.append('correo_electronico', correo);
        formData.append('telegram_username', telegramUsername);

        const res = await fetch('radix_api/profile_update.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();

        if (!data.success) {
            setProfileStatus('profile-status', data.error || 'No se pudo actualizar el perfil.', '#ff5252');
            return;
        }

        _profileSnapshot = {
            ...(_profileSnapshot || {}),
            ...(data.profile || {}),
            telegram_username: data.profile && Object.prototype.hasOwnProperty.call(data.profile, 'telegram_username')
                ? data.profile.telegram_username
                : telegramUsername,
            display_name: (data.profile && data.profile.display_name) || nombre,
        };
        fillProfileForm(_profileSnapshot);
        setProfileStatus('profile-status', data.message || 'Perfil actualizado.', '#00e676');
        mostrarToast('✅ Perfil actualizado correctamente.', '#00e676');
    } catch (e) {
        setProfileStatus('profile-status', 'Error al guardar el perfil.', '#ff5252');
    }
}

async function cambiarContrasenaPerfil() {
    const currentInput = document.getElementById('profile-current-password');
    const newInput = document.getElementById('profile-new-password');
    const confirmInput = document.getElementById('profile-confirm-password');

    if (!currentInput || !newInput || !confirmInput) return;

    const currentPassword = currentInput.value;
    const newPassword = newInput.value;
    const confirmPassword = confirmInput.value;

    if (!newPassword || !confirmPassword) {
        setProfileStatus('profile-password-status', 'Completa la nueva contraseña y su confirmación.', '#ff5252');
        return;
    }

    if (newPassword.length < 8) {
        setProfileStatus('profile-password-status', 'La nueva contraseña debe tener al menos 8 caracteres.', '#ff5252');
        return;
    }

    if (newPassword !== confirmPassword) {
        setProfileStatus('profile-password-status', 'La confirmación no coincide.', '#ff5252');
        return;
    }

    setProfileStatus('profile-password-status', 'Actualizando contraseña...', '#7f86a7');

    try {
        const formData = new FormData();
        formData.append('current_password', currentPassword);
        formData.append('new_password', newPassword);
        formData.append('confirm_password', confirmPassword);

        const res = await fetch('radix_api/profile_change_password.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();

        if (!data.success) {
            setProfileStatus('profile-password-status', data.error || 'No se pudo cambiar la contraseña.', '#ff5252');
            return;
        }

        currentInput.value = '';
        newInput.value = '';
        confirmInput.value = '';

        if (_profileSnapshot) {
            _profileSnapshot.has_password = true;
            fillProfileForm(_profileSnapshot);
        }

        setProfileStatus('profile-password-status', data.message || 'Contraseña actualizada.', '#00e676');
        mostrarToast('🔐 Contraseña actualizada correctamente.', '#00e676');
    } catch (e) {
        setProfileStatus('profile-password-status', 'Error al actualizar la contraseña.', '#ff5252');
    }
}

async function loadDashboard() {
    try {
        normalizeDashboardCopy();
        wireProfileTriggers();
        const response = await fetch('radix_api/user_data.php');
        const data     = await response.json();
        if (!data.success) return;
        _dashboardContext = getDashboardContext(data);

        // 1. Basic Info
        const displayName = data.user.display_name || data.user.nickname;
        if(document.getElementById('welcome-msg')) document.getElementById('welcome-msg').innerText = `Hola, ${displayName} 👋`;
        if(document.getElementById('wallet-address-display')) document.getElementById('wallet-address-display').innerText = data.user.wallet;
        if(document.getElementById('avatar-circle')) document.getElementById('avatar-circle').innerText = displayName.substring(0, 2).toUpperCase();
        if(document.getElementById('sidebar-nickname-text')) document.getElementById('sidebar-nickname-text').innerText = displayName;
        // V4 — sidebar wallet short display
        updateSidebarWallet(data.user.wallet);

        // 2. Mode Handling
        if (data.treasury) {
            // MASTER MODE
            actualizarEstadoTelegram(data.user.has_telegram || false);
            animateValue(document.getElementById('val-balance'),          data.treasury.tesoreria_balance, '$', '', true);
            animateValue(document.getElementById('val-fase'),             data.treasury.fase1_pool,        '$', '', true);
            animateValue(document.getElementById('val-usuarios-reales'),  data.treasury.total_reales,      '',  '', false);
            
            // Master Ledger (Libro Mayor)
            const ledgerBody = document.getElementById('master-ledger-body');
            if (ledgerBody && data.treasury.ledger) {
                ledgerBody.innerHTML = data.treasury.ledger.map(row => {
                    const isIngreso = row.tipo === 'ingreso';
                    const color = isIngreso ? '#00e676' : '#ff5252';
                    return `<tr style="border-bottom:1px solid rgba(255,255,255,0.02);">
                        <td style="padding:14px 12px; color:#555;">${row.fecha.split(' ')[0]}</td>
                        <td style="padding:14px 12px; color:#ddd; font-weight:600;">${row.concepto}</td>
                        <td style="padding:14px 12px; color:${color}; font-weight:800;">${isIngreso?'+':'-'}$${parseFloat(row.monto).toFixed(2)}</td>
                        <td style="padding:14px 12px;"><span style="background:${color}15; color:${color}; padding:2px 8px; border-radius:4px; font-size:0.7rem; font-weight:800;">${row.tipo.toUpperCase()}</span></td>
                    </tr>`;
                }).join('') || '<tr><td colspan="4" style="text-align:center;padding:20px;color:#444;">Sin movimientos.</td></tr>';
            }

            const masterPhaseSelect = document.getElementById('master-tree-phase');
            if (masterPhaseSelect && !masterPhaseSelect.dataset.bound) {
                masterPhaseSelect.addEventListener('change', () => loadMasterNetworkTree());
                masterPhaseSelect.dataset.bound = '1';
            }
            const masterCycleSelect = document.getElementById('master-tree-cycle');
            if (masterCycleSelect && !masterCycleSelect.dataset.bound) {
                masterCycleSelect.addEventListener('change', () => loadMasterNetworkTree());
                masterCycleSelect.dataset.bound = '1';
            }
            const masterRootInput = document.getElementById('master-tree-root');
            if (masterRootInput && !masterRootInput.dataset.bound) {
                masterRootInput.addEventListener('keydown', (event) => {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        loadMasterNetworkTree();
                    }
                });
                masterRootInput.dataset.bound = '1';
            }
            loadMasterAdvancedData();
        } else {
            // USER MODE
            _saldoActual      = data.earnings || 0;
            _fase0Completada  = data.fase0_completada || false;
            _historialData    = data.historial || [];
            updateDashboardPhaseCopy(data);
            animateValue(document.getElementById('val-balance'),      _saldoActual,                    '$', '', true);
            animateValue(document.getElementById('val-clones'),       data.user.clones_count || 0,     '',  '', false);
            // Tablero label is text — set directly
            if (document.getElementById('val-fase')) {
                document.getElementById('val-fase').innerText = formatBoardContextLabel(_dashboardContext);
            }

            // Widget: RESERVA FASE 1
            animateValue(
                document.getElementById('val-reserva'),
                (data.reservas?.fase1 ?? data.reserva_fase1) || 0,
                '$', '', true
            );
            // Widget: EQUIPO DIRECTO
            animateValue(
                document.getElementById('val-equipo-count'),
                data.equipo_ciclo?.reales ?? (data.referidos ? data.referidos.length : 0),
                '', '', false
            );
            if(document.getElementById('ref-link-input'))   document.getElementById('ref-link-input').value = `${window.location.href.replace('dashboard.php', '')}?ref=${data.user.wallet}`;

            // User Progress
            const fill = document.getElementById('progress-fill');
            if (fill) {
                const nivelContexto = _dashboardContext?.tablero_tipo || _dashboardContext?.nivel || data.user.nivel || 'A';
                const faseContextoCompleta = nivelContexto === 'FASE_COMPLETADA' || nivelContexto === 'FASE0_COMPLETADA';
                const nivelMap = {'A': 0, 'B': 1, 'C': 2, 'FASE_COMPLETADA': 3, 'FASE0_COMPLETADA': 3};
                const nivelIdx = nivelMap[nivelContexto] ?? 0;
                const pctMap   = {'A': '0%', 'B': '50%', 'C': '100%', 'FASE_COMPLETADA': '100%', 'FASE0_COMPLETADA': '100%'};
                fill.style.width = pctMap[nivelContexto] || '0%';
                ['node-a','node-b','node-c'].forEach((id, i) => {
                    const n = document.getElementById(id);
                    if (!n) return;
                    if (faseContextoCompleta || i < nivelIdx) n.className = 'phase-node completed';
                    else if (i === nivelIdx)           n.className = 'phase-node current';
                    else                               n.className = 'phase-node';
                });
            }
            renderPhaseOverview(data.phase_overview || []);
            renderPhaseComparison(data.phase_overview || []);
            renderOverviewCharts(data.phase_overview || []);
            renderUserTeam(data.referidos, data.equipo_ciclo, data.reservas, data.tablero, _dashboardContext);
            renderHistorial();
            renderNetworkTree(_dashboardContext);
            mostrarOnboardingSiNuevo(data);
            actualizarEstadoTelegram(data.user.has_telegram || false);
            await loadProfilePanel();

            // ── Renderizar secciones por fase ──────────────────────
            window._fase0Completada = data.fase0_completada || false;
            _earningsPorFase    = data.earnings_por_fase || [];
            _phaseOverviewCache = data.phase_overview || [];
            _phaseOverviewCache.forEach(faseData => {
                renderFaseSection(faseData, data.earnings || 0, data.reservas || {});
            });
            // También renderizar el equipo directo en la sección de fase activa principal
            if (_phaseOverviewCache.length > 0) {
                const primaryFase = _phaseOverviewCache.find(f => f.is_primary) || _phaseOverviewCache[0];
                if (primaryFase) {
                    const fn = Number(primaryFase.fase_numero);
                    const teamEl = document.getElementById(`f${fn}-team-list`);
                    if (teamEl && data.referidos?.length > 0) {
                        teamEl.innerHTML = data.referidos.map(r => `
                            <div style="display:flex; align-items:center; gap:10px; padding:10px 0; border-bottom:1px solid #1a1a24;">
                                <div style="width:34px;height:34px;border-radius:50%;background:rgba(157,0,255,0.15);border:1px solid rgba(157,0,255,0.3);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:0.8rem;flex-shrink:0;">
                                    ${escapeHtml(getDisplayName(r).substring(0,2).toUpperCase())}
                                </div>
                                <div style="flex:1;min-width:0;">
                                    <div style="font-weight:600;font-size:0.85rem;color:#ddd;">${escapeHtml(getDisplayName(r))}</div>
                                    <div style="font-size:0.7rem;color:#555;">Pos. ${r.posicion} · ${r.nivel_actual || '?'}</div>
                                </div>
                                <span style="font-size:0.65rem;font-weight:700;padding:3px 8px;border-radius:6px;background:rgba(0,230,118,0.12);color:#00e676;">ACTIVO</span>
                            </div>`).join('') || '<div style="color:#444;padding:20px;text-align:center;">Sin equipo aún.</div>';
                    }
                }
            }
            // ── Fin secciones por fase ─────────────────────────────
        }

        // Common components
        if (data.user.pago_pendiente) mostrarPagoPendiente(data.pago_pendiente);

    } catch (e) { console.error(e); }
    finally { if(document.getElementById('loading-overlay')) document.getElementById('loading-overlay').style.display='none'; }
}

function formatCurrencyValue(value) {
    return `$${parseFloat(value || 0).toFixed(2)}`;
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function getPhasePalette(phaseNumber) {
    const palettes = {
        0: {
            accent: '#00d2ff',
            glow: 'rgba(0, 210, 255, 0.16)',
            soft: 'rgba(0, 210, 255, 0.14)',
            shadow: 'rgba(0, 210, 255, 0.28)'
        },
        1: {
            accent: '#9d00ff',
            glow: 'rgba(157, 0, 255, 0.16)',
            soft: 'rgba(157, 0, 255, 0.14)',
            shadow: 'rgba(157, 0, 255, 0.28)'
        },
        2: {
            accent: '#00e676',
            glow: 'rgba(0, 230, 118, 0.16)',
            soft: 'rgba(0, 230, 118, 0.14)',
            shadow: 'rgba(0, 230, 118, 0.28)'
        },
        3: {
            accent: '#ffb300',
            glow: 'rgba(255, 179, 0, 0.16)',
            soft: 'rgba(255, 179, 0, 0.14)',
            shadow: 'rgba(255, 179, 0, 0.28)'
        }
    };

    return palettes[phaseNumber] || palettes[0];
}

function getPhaseInlineVars(summary) {
    const palette = getPhasePalette(Number(summary?.fase_numero ?? 0));
    return `--phase-accent:${palette.accent}; --phase-glow:${palette.glow}; --phase-soft:${palette.soft}; --phase-shadow:${palette.shadow};`;
}

function getPhaseStateText(summary) {
    if (!summary) return 'sin iniciar';
    if (summary.is_primary && summary.estado_usuario === 'en_progreso') return 'vista principal';
    if (!summary.is_primary && summary.estado_usuario === 'en_progreso') return 'paralela';
    if (summary.estado_usuario === 'en_progreso') return 'activa';
    if (summary.estado_usuario === 'completada') return 'completada';
    if (summary.estado_usuario === 'historial') return 'historial';
    if (summary.activa_config) return 'lista';
    return 'preparada';
}

function getPhaseContextText(summary) {
    const board = summary?.current_board || null;
    if (!board) {
        return summary?.activa_config ? 'Esperando apertura' : 'Sin activar';
    }
    return `C${board.ciclo}-${board.tablero_tipo}`;
}

function getPhaseContextMeta(summary) {
    const board = summary?.current_board || null;
    if (!board) {
        return summary?.activa_config ? 'Fase disponible en el sistema' : 'Estructura futura';
    }
    if (board.estado === 'completado' && board.tablero_tipo === 'C') {
        return 'Ciclo cerrado';
    }
    return `Tablero ${board.tablero_tipo} en ${board.estado === 'en_progreso' ? 'curso' : board.estado}`;
}

function getPhaseHint(summary) {
    const board = summary?.current_board || null;
    const required = Number(summary?.team_required ?? 3);

    if (!board) {
        if (summary?.activa_config) {
            return 'Esta fase ya existe en el sistema y aparecera aqui apenas abras un tablero dentro de ella.';
        }
        return 'Esta fase todavia esta reservada como expansion futura y se mostrara aqui cuando quede habilitada.';
    }

    if (board.tablero_tipo === 'A') {
        return `Necesitas ${required} cupos en este ciclo para abrir el tablero B.`;
    }

    if (board.tablero_tipo === 'B') {
        return `Necesitas ${required} cupos en este ciclo para abrir el tablero C.`;
    }

    if (board.tablero_tipo === 'C' && board.estado === 'completado') {
        return 'El tablero C de esta fase ya se cerro y el sistema ya proceso el salto correspondiente.';
    }

    return 'Estas en el tablero final del ciclo. Al completarlo se dispara el siguiente salto o la reentrada segun la fase.';
}

function renderPhaseOverview(phases = []) {
    // Distribuir cada tarjeta a su sección de fase correspondiente
    if (!Array.isArray(phases) || phases.length === 0) return;

    phases.forEach((summary) => {
        const fn = Number(summary.fase_numero);
        const container = document.getElementById(`f${fn}-phase-card`);
        if (!container) return;

        container.innerHTML = (() => {
        const classes = ['phase-summary-card'];
        if (summary.is_primary) classes.push('is-primary');
        if (!summary.has_activity && !summary.activa_config) classes.push('is-locked');

        const chips = [];
        if (Number(summary.entry_amount || 0) > 0) {
            chips.push(`<span class="phase-chip">Entrada A ${escapeHtml(formatCurrencyValue(summary.entry_amount))}</span>`);
        }
        if (Number(summary.next_seed_amount || 0) > 0) {
            chips.push(`<span class="phase-chip">Semilla ${escapeHtml(formatCurrencyValue(summary.next_seed_amount))}</span>`);
        }
        if (Number(summary.reentry_amount || 0) > 0) {
            chips.push(`<span class="phase-chip">Reentrada ${escapeHtml(formatCurrencyValue(summary.reentry_amount))}</span>`);
        }

        return `
            <article class="${classes.join(' ')}" style="${getPhaseInlineVars(summary)}">
                <div class="phase-summary-top">
                    <div>
                        <div class="phase-summary-name">${escapeHtml(summary.fase_nombre || `Fase ${summary.fase_numero}`)}</div>
                        <div class="phase-summary-subtitle">${escapeHtml(getPhaseHint(summary))}</div>
                    </div>
                    <div class="phase-summary-badges">
                        <span class="phase-chip phase-chip-accent">${escapeHtml(getPhaseStateText(summary))}</span>
                        ${summary.is_primary ? '<span class="phase-chip">principal</span>' : ''}
                    </div>
                </div>

                <div class="phase-context-line">
                    <div class="phase-context-current">${escapeHtml(getPhaseContextText(summary))}</div>
                    <div class="phase-context-meta">${escapeHtml(getPhaseContextMeta(summary))}</div>
                </div>

                <div class="phase-summary-badges" style="justify-content:flex-start; margin-bottom:14px;">
                    ${chips.join('')}
                </div>

                <div class="phase-meters">
                    <div>
                        <div class="phase-meter-label">
                            <span>Ruta del tablero</span>
                            <strong>${escapeHtml(String(summary.board_progress_percent || 0))}%</strong>
                        </div>
                        <div class="phase-meter-track">
                            <div class="phase-meter-fill" style="width:${Math.max(0, Math.min(100, Number(summary.board_progress_percent || 0)))}%"></div>
                        </div>
                    </div>

                    <div>
                        <div class="phase-meter-label">
                            <span>Cupos del ciclo</span>
                            <strong>${escapeHtml(String(summary.team_total || 0))}/${escapeHtml(String(summary.team_required || 3))}</strong>
                        </div>
                        <div class="phase-meter-track">
                            <div class="phase-meter-fill" style="width:${Math.max(0, Math.min(100, Number(summary.team_progress_percent || 0)))}%"></div>
                        </div>
                    </div>
                </div>

                <div class="phase-summary-stats">
                    <div class="phase-stat">
                        <span class="phase-stat-label">Reales</span>
                        <span class="phase-stat-value">${escapeHtml(String(summary.team_reales || 0))}</span>
                    </div>
                    <div class="phase-stat">
                        <span class="phase-stat-label">Clones</span>
                        <span class="phase-stat-value">${escapeHtml(String(summary.team_clones || 0))}</span>
                    </div>
                    <div class="phase-stat">
                        <span class="phase-stat-label">Ciclos cerrados</span>
                        <span class="phase-stat-value">${escapeHtml(String(summary.completed_cycles || 0))}</span>
                    </div>
                </div>
            </article>
        `;
        })(); // fin IIFE
    }); // fin forEach
}

function renderPhaseComparison(phases = []) {
    // Distribuir cada fila de cumplimiento a su sección de fase correspondiente
    if (!Array.isArray(phases) || phases.length === 0) return;

    phases.forEach((summary) => {
        const fn = Number(summary.fase_numero);
        const container = document.getElementById(`f${fn}-compare-row`);
        if (!container) return;

        container.innerHTML = `
        <div class="master-card" style="padding:16px 20px;">
        <div class="phase-compare-row" style="${getPhaseInlineVars(summary)}">
            <div class="phase-compare-head">
                <div>
                    <div class="phase-compare-name">${escapeHtml(summary.fase_nombre || `Fase ${summary.fase_numero}`)}</div>
                    <div class="phase-compare-state">${escapeHtml(getPhaseContextText(summary))} · ${escapeHtml(getPhaseStateText(summary))}</div>
                </div>
                <span class="phase-chip phase-chip-accent">${escapeHtml(String(summary.team_total || 0))}/${escapeHtml(String(summary.team_required || 3))}</span>
            </div>

            <div class="phase-compare-bars">
                <div>
                    <div class="phase-compare-bar-label">
                        <span>Ruta del tablero</span>
                        <strong>${escapeHtml(String(summary.board_progress_percent || 0))}%</strong>
                    </div>
                    <div class="phase-compare-track">
                        <div class="phase-compare-fill" style="width:${Math.max(0, Math.min(100, Number(summary.board_progress_percent || 0)))}%"></div>
                    </div>
                </div>
                <div>
                    <div class="phase-compare-bar-label">
                        <span>Cupos del ciclo</span>
                        <strong>${escapeHtml(String(summary.team_total || 0))}/${escapeHtml(String(summary.team_required || 3))}</strong>
                    </div>
                    <div class="phase-compare-track">
                        <div class="phase-compare-fill" style="width:${Math.max(0, Math.min(100, Number(summary.team_progress_percent || 0)))}%"></div>
                    </div>
                </div>
            </div>
        </div>
        </div>`; // cierra .phase-compare-row y .master-card
    }); // cierra forEach
}

// ── Gráficas del overview de usuario ─────────────────────────────────────────
let _chartOverviewProgress = null;
let _chartOverviewTeam     = null;

function renderOverviewCharts(phases = []) {
    if (!Array.isArray(phases) || phases.length === 0) return;
    if (typeof Chart === 'undefined') return;

    const labels  = phases.map(p => p.fase_nombre || `Fase ${p.fase_numero}`);
    const progPct = phases.map(p => p.board_progress_percent || 0);
    const teamFill = phases.map(p => p.team_total    || 0);
    const teamReq  = phases.map(p => p.team_required || 3);
    const colors   = ['#00d2ff','#9d00ff','#00e676','#ffb300'];

    const darkGrid   = 'rgba(255,255,255,0.06)';
    const tickColor  = '#888';
    const baseFont   = { family: 'inherit', size: 12 };

    Chart.defaults.color = tickColor;

    // — Gráfica 1: Avance por Fase (barras de progreso %) —
    const ctx1 = document.getElementById('chart-phase-progress');
    if (ctx1) {
        if (_chartOverviewProgress) _chartOverviewProgress.destroy();
        _chartOverviewProgress = new Chart(ctx1, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: 'Ruta del tablero (%)',
                    data: progPct,
                    backgroundColor: colors.map(c => c + 'bb'),
                    borderColor: colors,
                    borderWidth: 2,
                    borderRadius: 10,
                    borderSkipped: false,
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: ctx => ` ${ctx.parsed.y}%` } }
                },
                scales: {
                    y: {
                        min: 0, max: 100,
                        ticks: { color: tickColor, font: baseFont, callback: v => v + '%' },
                        grid: { color: darkGrid }
                    },
                    x: { ticks: { color: '#ccc', font: baseFont }, grid: { display: false } }
                }
            }
        });
    }

    // — Gráfica 2: Equipo por Fase (cupos llenos vs requeridos) —
    const ctx2 = document.getElementById('chart-phase-team');
    if (ctx2) {
        if (_chartOverviewTeam) _chartOverviewTeam.destroy();
        _chartOverviewTeam = new Chart(ctx2, {
            type: 'bar',
            data: {
                labels,
                datasets: [
                    {
                        label: 'Cupos llenos',
                        data: teamFill,
                        backgroundColor: colors.map(c => c + 'bb'),
                        borderColor: colors,
                        borderWidth: 2,
                        borderRadius: 10,
                        borderSkipped: false,
                    },
                    {
                        label: 'Requeridos',
                        data: teamReq,
                        backgroundColor: 'rgba(255,255,255,0.06)',
                        borderColor: 'rgba(255,255,255,0.2)',
                        borderWidth: 1,
                        borderRadius: 10,
                        borderSkipped: false,
                    }
                ]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        display: true,
                        labels: { color: '#888', font: baseFont, boxWidth: 12 }
                    }
                },
                scales: {
                    y: {
                        min: 0,
                        ticks: { color: tickColor, font: baseFont, stepSize: 1 },
                        grid: { color: darkGrid }
                    },
                    x: { ticks: { color: '#ccc', font: baseFont }, grid: { display: false } }
                }
            }
        });
    }
}
// ─────────────────────────────────────────────────────────────────────────────

function getMasterStatsFilters() {
    return {
        fase_numero: document.getElementById('master-stats-phase')?.value || _masterStatsFilterState.fase_numero || 'all',
        tablero_tipo: document.getElementById('master-stats-board')?.value || _masterStatsFilterState.tablero_tipo || 'all',
        ciclo: document.getElementById('master-stats-cycle')?.value || _masterStatsFilterState.ciclo || 'all',
        tipo_usuario: document.getElementById('master-stats-user-type')?.value || _masterStatsFilterState.tipo_usuario || 'all'
    };
}

function buildMasterStatsCaption(filters) {
    const phaseLabel = filters.fase_numero === 'all' ? 'todas las fases' : `fase ${filters.fase_numero}`;
    const boardLabel = filters.tablero_tipo === 'all' ? 'todos los tableros' : `tablero ${filters.tablero_tipo}`;
    const cycleLabel = filters.ciclo === 'all' ? 'todos los ciclos' : `ciclo ${filters.ciclo}`;
    const typeMap = {
        all: 'todos los usuarios',
        real: 'solo reales',
        clon: 'solo clones',
        master: 'solo master',
        sistema: 'solo sistema'
    };
    const typeLabel = typeMap[filters.tipo_usuario] || 'todos los usuarios';
    return `Vista actual: ${phaseLabel}, ${boardLabel}, ${cycleLabel}, ${typeLabel}.`;
}

function populateMasterStatsFilters(filters = {}) {
    const phaseSelect = document.getElementById('master-stats-phase');
    const boardSelect = document.getElementById('master-stats-board');
    const cycleSelect = document.getElementById('master-stats-cycle');
    const typeSelect = document.getElementById('master-stats-user-type');

    if (phaseSelect) {
        phaseSelect.innerHTML = ['<option value="all">Todas</option>']
            .concat((filters.fases || []).map(f => `<option value="${f.fase_numero}">${f.nombre || ('Fase ' + f.fase_numero)}</option>`))
            .join('');
        phaseSelect.value = String(filters.fase_numero ?? 'all');
    }

    if (boardSelect) {
        boardSelect.innerHTML = ['<option value="all">Todos</option>']
            .concat((filters.tableros || []).map(board => `<option value="${board}">Tablero ${board}</option>`))
            .join('');
        boardSelect.value = String(filters.tablero_tipo ?? 'all');
    }

    if (cycleSelect) {
        cycleSelect.innerHTML = ['<option value="all">Todos</option>']
            .concat((filters.ciclos || []).map(cycle => `<option value="${cycle}">Ciclo ${cycle}</option>`))
            .join('');
        cycleSelect.value = String(filters.ciclo ?? 'all');
    }

    if (typeSelect) {
        typeSelect.innerHTML = (filters.tipos_usuario || []).map(type => `
            <option value="${type.value}">${type.label}</option>
        `).join('');
        typeSelect.value = String(filters.tipo_usuario ?? 'all');
    }

    _masterStatsFilterState = {
        fase_numero: String(filters.fase_numero ?? 'all'),
        tablero_tipo: String(filters.tablero_tipo ?? 'all'),
        ciclo: String(filters.ciclo ?? 'all'),
        tipo_usuario: String(filters.tipo_usuario ?? 'all')
    };
}

function bindMasterStatsControls() {
    ['master-stats-phase', 'master-stats-board', 'master-stats-cycle', 'master-stats-user-type'].forEach((id) => {
        const el = document.getElementById(id);
        if (!el || el.dataset.bound) return;
        el.addEventListener('change', () => {
            _masterStatsFilterState = getMasterStatsFilters();
            loadMasterAdvancedData();
        });
        el.dataset.bound = '1';
    });
}

function renderMasterDistributionBars(distribution = {}, filters = {}) {
    const current = {
        A: parseInt(distribution.A || 0, 10),
        B: parseInt(distribution.B || 0, 10),
        C: parseInt(distribution.C || 0, 10)
    };
    const max = Math.max(current.A, current.B, current.C, 1);

    ['a', 'b', 'c'].forEach((tab) => {
        const label = document.getElementById(`dist-${tab}-val`);
        const bar = document.getElementById(`dist-${tab}-bar`);
        if (label) label.innerText = current[tab.toUpperCase()];
        if (bar) bar.style.width = `${(current[tab.toUpperCase()] / max) * 100}%`;
    });

    const scope = document.getElementById('master-dist-scope');
    if (scope) scope.innerText = buildMasterStatsCaption(filters);
}

function renderMasterRatio(ratio = {}) {
    const reales = parseInt(ratio.reales || 0, 10);
    const clones = parseInt(ratio.clones || 0, 10);
    const total = Math.max(reales + clones, 1);
    const realPercent = (reales / total) * 100;
    const bar = document.getElementById('reales-clones-bar');
    const label = document.getElementById('reales-clones-label');

    if (bar) bar.style.width = `${realPercent}%`;
    if (label) label.innerText = `Reales ${reales} | Clones ${clones}`;
}

function renderMasterFilteredSummary(summary = {}, filters = {}) {
    const caption = document.getElementById('master-filter-caption');
    if (caption) caption.innerText = buildMasterStatsCaption(filters);

    const totalEl = document.getElementById('master-filter-total');
    const beneficiariosEl = document.getElementById('master-filter-beneficiarios');
    const users10El = document.getElementById('master-filter-users-10');
    const payments10El = document.getElementById('master-filter-payments-10');

    if (totalEl) totalEl.innerText = formatCurrencyValue(summary.total_distribuido || 0);
    if (beneficiariosEl) beneficiariosEl.innerText = parseInt(summary.beneficiarios || 0, 10);
    if (users10El) users10El.innerText = parseInt(summary.beneficiarios_con_diez || 0, 10);
    if (payments10El) payments10El.innerText = parseInt(summary.pagos_de_diez || 0, 10);
}

function renderMasterDistributionDetail(rows = []) {
    const body = document.getElementById('master-distribution-body');
    if (!body) return;

    if (!rows.length) {
        body.innerHTML = '<tr><td colspan="7" style="color:#555; text-align:center; padding:20px;">No hay distribuciones para esos filtros.</td></tr>';
        return;
    }

    body.innerHTML = rows.map((row) => `
        <tr>
            <td>Fase ${row.fase_numero}</td>
            <td>${row.tablero_tipo || '-'}</td>
            <td>${row.ciclo || '-'}</td>
            <td>${parseInt(row.beneficiarios || 0, 10)}</td>
            <td>${parseInt(row.pagos_distribuidos || 0, 10)}</td>
            <td>${formatCurrencyValue(row.total_distribuido || 0)}</td>
            <td>${parseInt(row.beneficiarios_con_diez || 0, 10)}</td>
        </tr>
    `).join('');
}

function renderMasterUsersWithTen(rows = []) {
    const body = document.getElementById('master-ten-users-body');
    if (!body) return;

    if (!rows.length) {
        body.innerHTML = '<tr><td colspan="4" style="color:#555; text-align:center; padding:20px;">Nadie ha cobrado $10 con esos filtros.</td></tr>';
        return;
    }

    body.innerHTML = rows.map((row) => `
        <tr>
            <td style="color:#fff; font-weight:700;">${row.display_name || ('Usuario ' + row.beneficiario_id)}</td>
            <td style="text-transform:capitalize;">${row.tipo_usuario || '-'}</td>
            <td>${parseInt(row.pagos_de_diez || 0, 10)}</td>
            <td>${((row.ultima_fecha || '').split(' ')[0]) || '-'}</td>
        </tr>
    `).join('');
}

function applyMasterStatsFilters() {
    _masterStatsFilterState = getMasterStatsFilters();
    loadMasterAdvancedData();
}

function clearMasterStatsFilters() {
    _masterStatsFilterState = {
        fase_numero: 'all',
        tablero_tipo: 'all',
        ciclo: 'all',
        tipo_usuario: 'all'
    };

    const phaseSelect = document.getElementById('master-stats-phase');
    const boardSelect = document.getElementById('master-stats-board');
    const cycleSelect = document.getElementById('master-stats-cycle');
    const typeSelect = document.getElementById('master-stats-user-type');

    if (phaseSelect) phaseSelect.value = 'all';
    if (boardSelect) boardSelect.value = 'all';
    if (cycleSelect) cycleSelect.value = 'all';
    if (typeSelect) typeSelect.value = 'all';

    loadMasterAdvancedData();
}

function setMasterToolButtonStates(activePanelId = '') {
    document.querySelectorAll('.master-tool-button').forEach((button) => {
        const isActive = button.dataset.masterTool === activePanelId;
        button.style.background = isActive ? 'linear-gradient(135deg,#9d00ff,#00d2ff)' : '#1a1a28';
        button.style.color = '#fff';
        button.style.border = isActive ? 'none' : '1px solid #2a2a3a';
        button.style.boxShadow = isActive ? '0 10px 24px rgba(0,210,255,0.18)' : 'none';
    });
}

function closeMasterToolPanels() {
    document.querySelectorAll('.master-tool-panel').forEach((panel) => {
        panel.style.display = 'none';
    });
    _activeMasterToolPanel = null;
    setMasterToolButtonStates('');
}

function setMasterDashboardHomeVisibility(isVisible = true) {
    const selectors = ['.widgets-master', '.master-grid-top', '#master-telegram-card'];
    selectors.forEach((selector) => {
        document.querySelectorAll(selector).forEach((element) => {
            element.style.display = isVisible ? '' : 'none';
        });
    });
}

function toggleMasterToolPanel(panelId) {
    const panel = document.getElementById(panelId);
    if (!panel) return;

    const samePanelOpen = _activeMasterToolPanel === panelId && panel.style.display !== 'none';
    closeMasterToolPanels();
    if (samePanelOpen) return;

    panel.style.display = 'block';
    _activeMasterToolPanel = panelId;
    setMasterToolButtonStates(panelId);

    if (panelId === 'master-panel-map') {
        setTimeout(() => loadMasterNetworkTree(), 120);
    }

    setTimeout(() => {
        panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, 30);
}

/* ══ CENTRO DE COMANDO ═══════════════════════════════════════════ */

/**
 * Actualiza el semáforo de salud y la barra de métricas rápidas
 */
function renderHealthBar(salud) {
    const dot   = document.getElementById('health-dot');
    const label = document.getElementById('health-label');
    const sub   = document.getElementById('health-sub');
    if (!dot || !label) return;

    const config = {
        ok:          { color: '#00e676', shadow: 'rgba(0,230,118,0.35)', text: '✅  Sistema Saludable', sub: 'Todos los indicadores en verde. Sin acciones requeridas.' },
        advertencia: { color: '#ffb300', shadow: 'rgba(255,179,0,0.35)',  text: '⚠️  Requiere Atención',  sub: 'Hay elementos que necesitan tu revisión pronto.' },
        critico:     { color: '#ef5350', shadow: 'rgba(239,83,80,0.45)',  text: '🚨  Acción Urgente',     sub: 'Hay un problema financiero activo. Actúa de inmediato.' },
    };
    const cfg = config[salud.nivel] || config.ok;

    dot.style.background  = cfg.color;
    dot.style.boxShadow   = `0 0 0 4px ${cfg.shadow}`;
    label.style.color     = cfg.color;
    label.innerText       = cfg.text;
    if (sub) sub.innerText = cfg.sub;

    // Métricas
    const setEl = (id, val, color) => {
        const el = document.getElementById(id);
        if (el) { el.innerText = val; if (color) el.style.color = color; }
    };

    const solv = salud.solvente;
    setEl('hb-tesoreria',  '$' + parseFloat(salud.tesoreria || 0).toFixed(2),                 '#00e676');
    setEl('hb-retiros',    salud.count_retiros_pendientes > 0
                               ? '$' + parseFloat(salud.total_retiros_pendientes || 0).toFixed(2) + ` (${salud.count_retiros_pendientes})`
                               : '$0.00 ✓',
                           salud.count_retiros_pendientes > 0 ? '#ffb300' : '#00e676');
    setEl('hb-solvencia',  solv ? '✅ Cubierto' : '❌ Insuficiente', solv ? '#00e676' : '#ef5350');
    setEl('hb-pagos-sc',   salud.count_pagos_sin_confirmar > 0
                               ? salud.count_pagos_sin_confirmar + ' sin confirmar'
                               : '✓ Al día',
                           salud.count_pagos_sin_confirmar > 0 ? '#ffb300' : '#666');
}

/**
 * Renderiza las tarjetas de alerta en el panel de alertas
 */
function renderAlerts(alertas) {
    const panel = document.getElementById('alerts-panel');
    if (!panel) return;

    if (!alertas || !alertas.length) {
        panel.innerHTML = '';
        return;
    }

    const nivelStyle = {
        critico:     { bg: 'rgba(239,83,80,0.08)',  border: 'rgba(239,83,80,0.35)',  color: '#ef5350',  badge: 'URGENTE' },
        advertencia: { bg: 'rgba(255,179,0,0.06)',  border: 'rgba(255,179,0,0.3)',   color: '#ffb300',  badge: 'ATENCIÓN' },
        info:        { bg: 'rgba(0,210,255,0.05)',  border: 'rgba(0,210,255,0.2)',   color: '#00d2ff',  badge: 'INFO' },
    };

    panel.innerHTML = alertas.map(a => {
        const s = nivelStyle[a.nivel] || nivelStyle.info;
        const accionHtml = a.accion && a.seccion
            ? `<button onclick="switchMasterSection('${a.seccion}')"
                 style="background:${s.color}22; border:1px solid ${s.color}55; color:${s.color}; border-radius:8px; padding:5px 14px; font-size:0.75rem; font-weight:700; cursor:pointer; white-space:nowrap; margin-top:4px;">
                 ${a.accion} →
               </button>` : '';
        return `<div style="display:flex; align-items:flex-start; gap:12px; background:${s.bg}; border:1px solid ${s.border}; border-radius:12px; padding:12px 16px; margin-bottom:10px;">
            <span style="font-size:1.4rem; flex-shrink:0; margin-top:1px;">${a.icono}</span>
            <div style="flex:1; min-width:0;">
                <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px; flex-wrap:wrap;">
                    <span style="background:${s.color}22; color:${s.color}; font-size:0.62rem; font-weight:800; padding:1px 7px; border-radius:6px; letter-spacing:0.8px;">${s.badge}</span>
                    <strong style="color:${s.color}; font-size:0.82rem;">${a.titulo}</strong>
                </div>
                <div style="color:#888; font-size:0.78rem; line-height:1.5;">${a.mensaje}</div>
                ${accionHtml}
            </div>
        </div>`;
    }).join('');
}

/**
 * Actualiza los badges numéricos en los íconos de navegación
 */
function updateNavBadges(salud) {
    const badgeRetiros = document.getElementById('nav-badge-retiros');
    if (badgeRetiros) {
        const cnt = salud.count_retiros_pendientes || 0;
        if (cnt > 0) {
            badgeRetiros.innerText  = cnt;
            badgeRetiros.style.display = 'inline';
            badgeRetiros.style.background = salud.solvente ? '#ffb300' : '#ef5350';
        } else {
            badgeRetiros.style.display = 'none';
        }
    }
}

/* ══ FIN CENTRO DE COMANDO ══════════════════════════════════════ */

async function loadMasterAdvancedData() {
    try {
        bindMasterStatsControls();
        const filters = getMasterStatsFilters();
        _masterStatsFilterState = { ...filters };

        const params = new URLSearchParams();
        params.set('fase_numero', filters.fase_numero || 'all');
        params.set('tablero_tipo', filters.tablero_tipo || 'all');
        params.set('ciclo', filters.ciclo || 'all');
        params.set('tipo_usuario', filters.tipo_usuario || 'all');

        const res = await fetch(`radix_api/admin_global_stats.php?${params.toString()}`);
        const data = await res.json();
        if (!data.success) return;

        // ── CENTRO DE COMANDO — Semáforo + Alertas + Badges ──────
        if (data.salud_financiera) {
            renderHealthBar(data.salud_financiera);
            updateNavBadges(data.salud_financiera);
        }
        renderAlerts(data.alertas || []);
        // ─────────────────────────────────────────────────────────

        animateValue(document.getElementById('val-master-earnings'),    data.master_id1_earnings || 0,   '$', '', true);
        animateValue(document.getElementById('val-total-blockchain'),  data.total_blockchain || 0,      '$', '', true);
        animateValue(document.getElementById('val-pendiente-dist'),    data.pendiente_distribuir || 0,  '$', '', true);
        animateValue(document.getElementById('val-creditos-excedente'), data.creditos_excedente || 0,  '$', '', true);
        animateValue(document.getElementById('val-usuarios-reales'),   data.usuarios?.reales || 0,      '',  '', false);
        animateValue(document.getElementById('val-balance'),           data.tesoreria || 0,             '$', '', true);
        animateValue(document.getElementById('val-fase'),              data.fase1_pool || 0,            '$', '', true);
        
        renderMasterCharts(data.crecimiento_diario || []);

        // ── PANORAMA POR FASE ────────────────────────────────────
        renderPanoramaFases(data);
        // ────────────────────────────────────────────────────────

        populateMasterStatsFilters(data.filtros || {});
        renderMasterDistributionBars(data.distribucion_tableros || { A: 0, B: 0, C: 0 }, data.filtros || filters);
        renderMasterRatio(data.ratio_reales_clones || {});
        renderMasterFilteredSummary(data.resumen_distribucion || {}, data.filtros || filters);
        renderMasterDistributionDetail(data.distribucion_detalle || []);
        renderMasterUsersWithTen(data.usuarios_con_diez || []);

        // A. Historial de Agentes IA
        const clonesBody = document.getElementById('master-clones-history-body');
        if (clonesBody) {
            const logs = (data.logs || []).slice(0, 5);
            clonesBody.innerHTML = logs.map(l => {
                const costo = l.monto ? `$${parseFloat(l.monto).toFixed(2)}` : '$—';
                return `<tr><td><span style="color:#00d2ff;">🤖 Agente IA</span></td><td>${costo}</td><td style="font-size:0.75rem; color:#888;">${(l.fecha||'').split(' ')[0]}</td></tr>`;
            }).join('') || '<tr><td colspan="3">Sin actividad</td></tr>';
        }

        // B. Retiros Pendientes (Mini)
        const retirosMini = document.getElementById('master-retiros-mini-list');
        if (retirosMini) {
            retirosMini.innerHTML = (data.retiros_pendientes || []).slice(0, 3).map(r => `
                <div style="background:rgba(255,255,255,0.02); padding:10px; border-radius:8px; border-left:2px solid var(--accent); margin-bottom:5px;">
                    <div style="display:flex; justify-content:space-between; font-size:0.8rem;">
                        <span style="color:#ddd;">${r.nickname}</span>
                        <span style="color:var(--accent); font-weight:800;">$${parseFloat(r.monto).toFixed(2)}</span>
                    </div>
                </div>`).join('') || '<div style="color:#444; font-size:0.8rem; text-align:center;">Todo al día</div>';
        }

        // C. Actividad Reciente del Sistema — mini widget
        const activityBody = document.getElementById('master-activity-body');
        if (activityBody) {
            const logs = (data.logs_actividad || []).slice(0, 8);
            activityBody.innerHTML = logs.map(l => {
                const m = _auditMeta(l.accion);
                const who = l.nombre_completo || l.nickname || '';
                const faseStr = (l.fase_numero !== null && l.fase_numero !== undefined)
                    ? `<span style="color:#555; font-size:0.68rem;">·F${l.fase_numero}</span>` : '';
                return `<tr>
                    <td style="padding:7px 10px; white-space:nowrap;">
                        <span style="margin-right:4px;">${m.icon}</span>
                        <span style="color:${m.color}; font-weight:700; font-size:0.77rem;">${m.label}</span>
                        ${faseStr}
                    </td>
                    <td style="color:#888; font-size:0.75rem; padding:7px 10px;">
                        ${who ? `<strong style="color:#ccc;">${who}</strong> · ` : ''}${(l.detalles || '').substring(0, 50)}${(l.detalles||'').length > 50 ? '…' : ''}
                    </td>
                    <td style="color:#444; font-size:0.7rem; padding:7px 10px; white-space:nowrap;">${_auditRelTime(l.fecha)}</td>
                </tr>`;
            }).join('') || '<tr><td colspan="3" style="color:#444; padding:15px; text-align:center;">Sin actividad registrada</td></tr>';
        }

        _masterUserList     = data.lista_usuarios || [];
        _masterRetirosList  = data.retiros_pendientes || [];
        _masterAuditoria    = data.logs_actividad || [];
        loadMasterNetworkTree();

    } catch (e) { 
        console.error("Error cargando datos master:", e); 
    }
}

function masterTreeDisplayName(nodeData) {
    return nodeData.display_name || nodeData.nickname || `Usuario ${nodeData.id}`;
}

function masterTreeInitials(nodeData) {
    const source = masterTreeDisplayName(nodeData).trim();
    if (!source) return 'RD';
    const parts = source.split(/\s+/).filter(Boolean);
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
    return (parts[0][0] + parts[1][0]).toUpperCase();
}

function renderMasterTreeSummary(data) {
    const summary = data.resumen || {};
    const filtros = data.filtros || {};
    const root = filtros.root_resuelto || null;
    const box = document.getElementById('master-tree-summary');
    if (!box) return;

    const chips = [
        `<span class="master-tree-chip">Fase <strong>${filtros.fase_numero ?? 0}</strong></span>`,
        `<span class="master-tree-chip">Ciclo <strong>${filtros.ciclo ?? 1}</strong></span>`,
        `<span class="master-tree-chip">Nodos <strong>${summary.nodos ?? 0}</strong></span>`,
        `<span class="master-tree-chip">Reales <strong>${summary.reales ?? 0}</strong></span>`,
        `<span class="master-tree-chip">Clones <strong>${summary.clones ?? 0}</strong></span>`,
        `<span class="master-tree-chip">Niveles <strong>${summary.profundidad ?? 0}</strong></span>`
    ];

    if (root) {
        chips.push(`<span class="master-tree-chip">Raiz actual <strong>${masterTreeDisplayName(root)}</strong> · ID ${root.id}</span>`);
    }

    box.innerHTML = chips.join('');
}

function syncMasterTreeControls(data) {
    const filtros = data.filtros || {};
    const phaseSelect = document.getElementById('master-tree-phase');
    const cycleSelect = document.getElementById('master-tree-cycle');
    const rootInput = document.getElementById('master-tree-root');

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

function getMasterTreeColor(d) {
    if (d.data.es_raiz) return '#9d00ff';
    if (d.data.tipo_usuario === 'clon') return '#ff9800';
    if (d.data.pago_estado === 'completado') return '#00e676';
    if (d.data.pago_estado === 'pendiente') return '#ff5252';
    return '#00d2ff';
}

function renderMasterNetworkTree(treeData) {
    const container = document.getElementById('master-network-tree');
    if (!container) return;

    if (!treeData) {
        container.innerHTML = '<div class="master-tree-empty">No hay estructura disponible para esa fase/ciclo.</div>';
        return;
    }

    container.innerHTML = '';
    container.classList.remove('is-dragging');

    const root = d3.hierarchy(treeData, d => d.hijos && d.hijos.length ? d.hijos : null);
    const leafCount = Math.max(root.leaves().length, 1);
    const depthCount = Math.max(root.height + 1, 1);
    const containerWidth = container.clientWidth || 1180;
    const containerHeight = container.clientHeight || 680;
    const margin = { top: 90, right: 90, bottom: 110, left: 90 };
    const nodeHorizontalSpacing = leafCount > 14 ? 98 : leafCount > 8 ? 118 : 148;
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
    const gradId = 'masterTreeGrad_' + Date.now();
    const gradient = defs.append('linearGradient')
        .attr('id', gradId)
        .attr('gradientUnits', 'userSpaceOnUse')
        .attr('x1', 0).attr('y1', 0)
        .attr('x2', 0).attr('y2', containerHeight);
    gradient.append('stop').attr('offset', '0%').attr('stop-color', '#9d00ff').attr('stop-opacity', 0.92);
    gradient.append('stop').attr('offset', '100%').attr('stop-color', '#00d2ff').attr('stop-opacity', 0.92);

    const viewport = svg.append('g');
    const g = viewport.append('g').attr('transform', `translate(${margin.left}, ${margin.top})`);
    const nodeRadius = node => node.data.es_raiz ? rootRadius : childRadius;
    const linkSegments = [];

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

    g.selectAll('.master-link')
        .data(linkSegments)
        .enter()
        .append('path')
        .attr('class', 'master-link')
        .attr('d', d => `M${d.x1},${d.y1} L${d.x2},${d.y2}`)
        .attr('fill', 'none')
        .attr('stroke', `url(#${gradId})`)
        .attr('stroke-width', 2.8)
        .attr('stroke-linecap', 'round')
        .attr('opacity', 0.95);

    const node = g.selectAll('.master-node')
        .data(root.descendants())
        .enter()
        .append('g')
        .attr('class', 'master-node')
        .attr('transform', d => `translate(${d.x},${d.y})`);

    node.append('circle')
        .attr('r', 0)
        .attr('fill', d => getMasterTreeColor(d))
        .attr('stroke', '#090911')
        .attr('stroke-width', 2)
        .style('filter', d => `drop-shadow(0 0 12px ${getMasterTreeColor(d)})`)
        .transition().duration(450)
        .attr('r', d => nodeRadius(d));

    node.append('text')
        .attr('text-anchor', 'middle')
        .attr('dy', '0.35em')
        .attr('font-size', d => d.data.es_raiz ? '10px' : '8px')
        .attr('font-weight', '800')
        .attr('fill', '#000')
        .text(d => masterTreeInitials(d.data));

    node.append('text')
        .attr('text-anchor', 'middle')
        .attr('dy', d => d.data.es_raiz ? '46px' : '38px')
        .attr('font-size', '10px')
        .attr('fill', '#c7ccda')
        .text(d => {
            const name = masterTreeDisplayName(d.data);
            return name.length > 18 ? `${name.substring(0, 18)}…` : name;
        });

    node.append('text')
        .attr('text-anchor', 'middle')
        .attr('dy', d => d.data.es_raiz ? '-34px' : '-28px')
        .attr('font-size', d => d.data.es_raiz ? '10px' : '8px')
        .attr('fill', d => d.data.es_raiz ? '#9d00ff' : '#888')
        .text(d => d.data.es_raiz ? `Raiz · Tablero ${d.data.tablero_actual || 'A'}` : `ID ${d.data.id}`);

    const legendItems = [
        { color: '#9d00ff', label: 'Raiz' },
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

    _masterTreeZoom = d3.zoom()
        .scaleExtent([0.35, 2.2])
        .on('start', () => container.classList.add('is-dragging'))
        .on('zoom', event => viewport.attr('transform', event.transform))
        .on('end', () => container.classList.remove('is-dragging'));

    svg.call(_masterTreeZoom);
    _masterTreeSvg = svg;

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
    _masterTreeInitialTransform = d3.zoomIdentity.translate(tx, ty).scale(scale);
    svg.call(_masterTreeZoom.transform, _masterTreeInitialTransform);
}

async function loadMasterNetworkTree(options = {}) {
    const container = document.getElementById('master-network-tree');
    const phaseSelect = document.getElementById('master-tree-phase');
    const cycleSelect = document.getElementById('master-tree-cycle');
    const rootInput = document.getElementById('master-tree-root');
    if (!container) return;

    const faseNumero = options.fase_numero ?? (parseInt(phaseSelect?.value || '0', 10) || 0);
    const ciclo = options.ciclo ?? (parseInt(cycleSelect?.value || '1', 10) || 1);
    const root = options.root ?? (rootInput?.value || '').trim();

    container.innerHTML = '<div class="master-tree-empty">Construyendo mapa general de la red...</div>';

    try {
        const params = new URLSearchParams();
        params.set('fase_numero', String(faseNumero));
        params.set('ciclo', String(ciclo));
        if (root) params.set('root', root);

        const res = await fetch(`radix_api/admin_network_tree.php?${params.toString()}`);
        const data = await res.json();

        if (!data.success) {
            container.innerHTML = `<div class="master-tree-empty">${data.error || 'No se pudo cargar el arbol.'}</div>`;
            return;
        }

        syncMasterTreeControls(data);
        renderMasterTreeSummary(data);
        renderMasterNetworkTree(data.arbol);
    } catch (error) {
        console.error('Master network tree error:', error);
        container.innerHTML = '<div class="master-tree-empty">Error al cargar el arbol general.</div>';
    }
}

function aplicarFiltrosArbolMaster() {
    loadMasterNetworkTree();
}

function limpiarFiltroArbolMaster() {
    const rootInput = document.getElementById('master-tree-root');
    if (rootInput) rootInput.value = '';
    loadMasterNetworkTree();
}

function resetZoomMasterTree() {
    if (_masterTreeSvg && _masterTreeZoom && _masterTreeInitialTransform) {
        _masterTreeSvg.transition().duration(350).call(_masterTreeZoom.transform, _masterTreeInitialTransform);
    }
}

/**
 * abrirRetiro(faseNum) — Abre el modal de retiro para una fase específica.
 * @param {number} faseNum  - número de fase (0, 1, 2, 3)
 */
function abrirRetiro(faseNum) {
    faseNum = parseInt(faseNum, 10) || 0;

    // Verificar datos por fase
    const fasePay = _earningsPorFase.find(e => e.fase_numero === faseNum);

    if (!fasePay || !fasePay.tablero_c_ok) {
        mostrarToast(`⏳ Debes completar la Fase ${faseNum} (Tableros A → B → C) para poder retirar.`, "#ffb300");
        return;
    }
    if (fasePay.tiene_pendiente) {
        mostrarToast(`⏳ Ya tienes un retiro pendiente en Fase ${faseNum}. Espera a que sea procesado.`, "#ffb300");
        return;
    }
    if (fasePay.saldo_disponible < 10) {
        mostrarToast(`Saldo insuficiente en Fase ${faseNum} (mínimo $10.00). Disponible: $${fasePay.saldo_disponible.toFixed(2)}`, "#ff5252");
        return;
    }

    // Guardar qué fase se está retirando
    _retiroFaseActual = faseNum;

    // Mostrar saldo disponible en el modal
    const saldoEl = document.getElementById('retiro-saldo');
    if (saldoEl) saldoEl.innerText = `$${fasePay.saldo_disponible.toFixed(2)} USDT`;

    // Mostrar etiqueta de fase en el modal
    const faseLabel = document.getElementById('retiro-fase-label');
    if (faseLabel) faseLabel.innerText = `Fase ${faseNum}`;

    // Limpiar mensaje de estado anterior
    const statusEl = document.getElementById('retiro-status');
    if (statusEl) statusEl.innerText = '';

    // Habilitar botón
    const btn = document.getElementById('btn-solicitar-retiro');
    if (btn) btn.disabled = false;

    // Renderizar historial dentro del modal
    renderHistorial();

    // Abrir modal
    const modal = document.getElementById('retiro-modal');
    if (modal) modal.style.display = 'flex';
}

function cerrarRetiro() {
    const modal = document.getElementById('retiro-modal');
    if (modal) modal.style.display = 'none';
}

async function solicitarRetiro() {
    const btn      = document.getElementById('btn-solicitar-retiro');
    const statusEl = document.getElementById('retiro-status');
    if (btn) btn.disabled = true;
    if (statusEl) statusEl.innerText = '⏳ Procesando...';
    try {
        const formData = new FormData();
        formData.append('fase_numero', _retiroFaseActual);
        const res  = await fetch('radix_api/solicitar_retiro.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            mostrarToast(`✅ Solicitud de retiro Fase ${_retiroFaseActual} enviada. Procesada en < 24h.`, "#00e676");
            cerrarRetiro();
            setTimeout(() => location.reload(), 2000);
        } else {
            if (statusEl) statusEl.innerText = '❌ ' + (data.error || "Error al procesar");
            if (btn) btn.disabled = false;
        }
    } catch (e) {
        if (statusEl) statusEl.innerText = '❌ Error de conexión';
        if (btn) btn.disabled = false;
    }
}

function switchMasterSection(tabName) {
    document.querySelectorAll('.nav-item').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.master-section').forEach(el => el.classList.remove('active'));
    
    // Cerramos paneles internos si quedara alguno, aunque ahora sean secciones
    closeMasterToolPanels();

    const nav = document.getElementById(`nav-${tabName}`);
    const sec = document.getElementById(`section-${tabName}`);

    if (nav) nav.classList.add('active');
    if (sec) sec.classList.add('active');

    // Lógica específica de carga por sección
    if (tabName === 'dashboard') {
        setMasterDashboardHomeVisibility(true);
        loadMasterAdvancedData();
        return;
    }

    // Para cualquier otra herramienta, ocultamos los widgets del home (por seguridad visual)
    setMasterDashboardHomeVisibility(false);

    if (tabName === 'analizador') {
        loadMasterAdvancedData();
    } else if (tabName === 'ledger') {
        loadMasterAdvancedData();
    } else if (tabName === 'mapa') {
        loadMasterNetworkTree();
    } else if (tabName === 'usuarios') {
        renderMasterUsers();
    } else if (tabName === 'retiros') {
        renderMasterRetirosFull();
    } else if (tabName === 'clones') {
        renderMasterClonesFull();
    } else if (tabName === 'auditoria') {
        renderMasterAuditoriaFull();
    }
}

/* ══ FILTROS DE USUARIOS ═════════════════════════════════════════ */

let _userFilters = {
    texto:   '',
    pago:    'all',
    tablero: 'all',
    fase:    'all',
    fecha:   'all',
    orden:   'recientes',
};

function _applyUfStyle(cls, activeVal) {
    const isOrden = cls === 'uf-orden';
    document.querySelectorAll('.' + cls).forEach(b => {
        const on = b.dataset.val === activeVal;
        if (isOrden) {
            // horizontal chip style
            b.style.background  = on ? 'rgba(255,255,255,0.12)' : 'rgba(255,255,255,0.03)';
            b.style.borderColor = on ? '#666' : '#1e1e2e';
            b.style.color       = on ? '#fff' : '#555';
        } else {
            // vertical list-item style
            b.style.background  = on ? 'rgba(157,0,255,0.15)' : 'transparent';
            b.style.borderColor = on ? '#9d00ff66' : 'transparent';
            b.style.color       = on ? '#e040fb' : '#666';
            b.style.fontWeight  = on ? '700' : '400';
        }
    });
}

function setUserFilter(campo, valor) {
    _userFilters[campo] = valor;
    const clsMap = { pago:'uf-pago', tablero:'uf-tablero', fase:'uf-fase', fecha:'uf-fecha', orden:'uf-orden' };
    if (clsMap[campo]) _applyUfStyle(clsMap[campo], valor);
    renderMasterUsers();
}

function clearUserFilters() {
    _userFilters = { texto:'', pago:'all', tablero:'all', fase:'all', fecha:'all', orden:'recientes' };
    const inp = document.getElementById('users-search');
    if (inp) inp.value = '';
    _applyUfStyle('uf-pago',    'all');
    _applyUfStyle('uf-tablero', 'all');
    _applyUfStyle('uf-fase',    'all');
    _applyUfStyle('uf-fecha',   'all');
    _applyUfStyle('uf-orden',   'recientes');
    renderMasterUsers();
}

function applyUserFilters() {
    _userFilters.texto = (document.getElementById('users-search')?.value || '').toLowerCase().trim();
    renderMasterUsers();
}

function _filterAndSortUsers(list) {
    const f   = _userFilters;
    const now = Date.now();
    const DAY = 86400000;

    let result = list.filter(u => {
        // Texto libre
        if (f.texto) {
            const haystack = [u.nombre_completo, u.nickname, u.wallet_address, u.correo_electronico, u.telefono]
                .join(' ').toLowerCase();
            if (!haystack.includes(f.texto)) return false;
        }
        // Estado de pago
        if (f.pago !== 'all') {
            if (f.pago === 'sin_pago' && u.pago_estado) return false;
            if (f.pago === 'completado' && u.pago_estado !== 'completado') return false;
            if (f.pago === 'pendiente'  && u.pago_estado !== 'pendiente')  return false;
        }
        // Tablero actual
        if (f.tablero !== 'all') {
            if (f.tablero === 'sin' && u.tablero_actual) return false;
            if (f.tablero !== 'sin' && u.tablero_actual !== f.tablero) return false;
        }
        // Fase
        if (f.fase !== 'all') {
            if (String(u.fase_actual) !== f.fase) return false;
        }
        // Fecha de registro
        if (f.fecha !== 'all' && u.fecha_registro) {
            const reg = new Date(u.fecha_registro.replace(' ','T')).getTime();
            if (f.fecha === 'week'  && now - reg > 7  * DAY) return false;
            if (f.fecha === 'month' && now - reg > 30 * DAY) return false;
        }
        return true;
    });

    // Orden
    result.sort((a, b) => {
        if (f.orden === 'recientes') return parseInt(b.id) - parseInt(a.id);
        if (f.orden === 'antiguos')  return parseInt(a.id) - parseInt(b.id);
        if (f.orden === 'nombre') {
            const na = (a.nombre_completo || a.nickname || '').toLowerCase();
            const nb = (b.nombre_completo || b.nickname || '').toLowerCase();
            return na.localeCompare(nb);
        }
        return 0;
    });

    return result;
}

function renderMasterUsers() {
    const body  = document.getElementById('master-users-body');
    const empty = document.getElementById('users-empty');
    if (!body) return;

    const filtered = _filterAndSortUsers(_masterUserList);
    const total    = _masterUserList.length;

    // Actualizar contador
    const showEl = document.getElementById('users-showing');
    const totEl  = document.getElementById('users-total');
    if (showEl) showEl.innerText = filtered.length;
    if (totEl)  totEl.innerText  = total;

    // Mostrar/ocultar estado vacío
    if (empty) empty.style.display = filtered.length === 0 ? 'block' : 'none';

    if (filtered.length === 0) { body.innerHTML = ''; return; }

    const tableroChip = (t, f) => {
        if (!t) return '<span style="color:#333; font-size:0.72rem;">—</span>';
        const colors = { A: '#9d00ff', B: '#00d2ff', C: '#00e676' };
        const fc = ['#00d2ff','#9d00ff','#00e676','#ffb300'][parseInt(f)||0] || '#aaa';
        return `<span style="background:${colors[t]||'#333'}22; color:${colors[t]||'#aaa'}; font-size:0.68rem; font-weight:800; padding:2px 7px; border-radius:5px; border:1px solid ${colors[t]||'#333'}44;">
                    F${f}·${t}
                </span>`;
    };

    const payBadge = (e) => {
        if (e === 'completado') return '<span style="color:#00e676; font-size:0.78rem; font-weight:800;">✓ Pagado</span>';
        if (e === 'pendiente')  return '<span style="color:#ffb300; font-size:0.78rem; font-weight:800;">⏳ Pendiente</span>';
        return '<span style="color:#3a3a4a; font-size:0.75rem;">Sin registro</span>';
    };

    const fmtFecha = d => {
        if (!d) return '—';
        const dt = new Date(d.replace(' ','T'));
        return dt.toLocaleDateString('es', { day:'2-digit', month:'short', year:'2-digit' });
    };

    const walletShort = w => w ? w.substring(0,6) + '…' + w.slice(-4) : '—';

    // Highlight de texto buscado
    const highlight = txt => {
        if (!_userFilters.texto || !txt) return txt || '—';
        const re = new RegExp('(' + _userFilters.texto.replace(/[.*+?^${}()|[\]\\]/g,'\\$&') + ')', 'gi');
        return String(txt).replace(re, '<mark style="background:#9d00ff44; color:#e040fb; border-radius:2px;">$1</mark>');
    };

    body.innerHTML = filtered.map(u => {
        const rowBg = u.pago_estado === 'pendiente' ? 'rgba(255,179,0,0.03)' :
                      !u.pago_estado               ? 'rgba(239,83,80,0.02)' : '';
        return `<tr style="background:${rowBg};">
            <td style="color:#555; font-size:0.75rem;">#${u.id}</td>
            <td style="color:#fff; font-weight:700; max-width:160px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                ${highlight(u.nombre_completo) || '<span style="color:#444;">Sin dato</span>'}
            </td>
            <td style="color:#aaa;">${highlight(u.nickname) || '—'}</td>
            <td style="color:#888; font-size:0.78rem;">${highlight(u.telefono) || '<span style="color:#333;">—</span>'}</td>
            <td style="color:#888; font-size:0.75rem; max-width:140px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${highlight(u.correo_electronico) || '<span style="color:#333;">—</span>'}</td>
            <td>${tableroChip(u.tablero_actual, u.fase_actual)}</td>
            <td>${payBadge(u.pago_estado)}</td>
            <td style="color:#555; font-size:0.72rem; white-space:nowrap;">${fmtFecha(u.fecha_registro)}</td>
            <td style="font-family:monospace; color:#444; font-size:0.72rem;" title="${u.wallet_address || ''}">${walletShort(u.wallet_address)}</td>
        </tr>`;
    }).join('');
}

/**
 * Exporta los usuarios filtrados a CSV
 */
function exportarUsuariosCSV() {
    const filtered = _filterAndSortUsers(_masterUserList);
    const headers  = ['ID','Nombre','Nickname','Telefono','Correo','Tablero','Fase','Pago','Registro','Wallet'];
    const rows     = filtered.map(u => [
        u.id,
        u.nombre_completo || '',
        u.nickname || '',
        u.telefono || '',
        u.correo_electronico || '',
        u.tablero_actual || '',
        u.fase_actual ?? '',
        u.pago_estado || 'sin_registro',
        (u.fecha_registro || '').split(' ')[0],
        u.wallet_address || '',
    ].map(v => `"${String(v).replace(/"/g,'""')}"`).join(','));
    const csv  = [headers.join(','), ...rows].join('\n');
    const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a'); a.href = url;
    a.download = `radix_usuarios_${new Date().toISOString().slice(0,10)}.csv`;
    a.click(); URL.revokeObjectURL(url);
}

/* ══ FIN FILTROS DE USUARIOS ═════════════════════════════════════ */

function renderMasterRetirosFull() {
    const box = document.getElementById('master-retiros-full-list');
    if (!box) return;
    if (!_masterRetirosList.length) {
        box.innerHTML = `
            <div style="text-align:center; padding:40px 20px; color:#444;">
                <div style="font-size:2.5rem; margin-bottom:12px;">✅</div>
                <div style="font-size:0.85rem;">Sin solicitudes pendientes.</div>
            </div>`;
        return;
    }
    const faseLabel = fn => { const m = {0:'Fase 0 · $40',1:'Fase 1 · $400',2:'Fase 2',3:'Fase 3'}; return m[fn] || `Fase ${fn}`; };
    const faseColor = fn => { const m = {0:'#00d2ff',1:'#9d00ff',2:'#00e676',3:'#ffb300'}; return m[fn] || '#aaa'; };
    box.innerHTML = _masterRetirosList.map((r, idx) => {
        const nombre = escapeHtml(r.nombre_completo || r.nickname || '—');
        const nick   = escapeHtml(r.nickname || '');
        const wallet = escapeHtml(r.wallet_destino || '');
        const fecha  = (r.fecha_solicitud || '').split(' ')[0];
        const monto  = parseFloat(r.monto).toFixed(2);
        const fn     = parseInt(r.fase_numero ?? 0);
        const fc     = faseColor(fn);
        return `
        <div id="retiro-master-${r.id}" style="background:#0d0d1a; border:1px solid rgba(255,255,255,0.06); border-radius:16px; margin-bottom:14px; overflow:hidden;">
            <!-- Header de la tarjeta -->
            <div style="display:flex; align-items:center; justify-content:space-between; padding:14px 18px; border-bottom:1px solid rgba(255,255,255,0.04); gap:10px; flex-wrap:wrap;">
                <div style="display:flex; align-items:center; gap:12px;">
                    <!-- Avatar -->
                    <div style="width:42px; height:42px; border-radius:50%; background:rgba(157,0,255,0.15); border:2px solid rgba(157,0,255,0.3); display:flex; align-items:center; justify-content:center; font-weight:800; font-size:0.85rem; flex-shrink:0; color:#9d00ff;">
                        ${nombre.substring(0,2).toUpperCase()}
                    </div>
                    <div>
                        <div style="font-weight:800; color:#fff; font-size:0.95rem; line-height:1.2;">${nombre}</div>
                        <div style="font-size:0.7rem; color:#555; margin-top:1px;">@${nick}</div>
                    </div>
                </div>
                <!-- Badge de fase y número -->
                <div style="display:flex; align-items:center; gap:8px;">
                    <span style="background:rgba(0,0,0,0.4); border:1px solid ${fc}33; color:${fc}; font-size:0.65rem; font-weight:700; padding:3px 10px; border-radius:20px; text-transform:uppercase; letter-spacing:1px;">
                        ${faseLabel(fn)}
                    </span>
                    <span style="font-size:0.65rem; color:#444;">#${r.id}</span>
                </div>
            </div>
            <!-- Cuerpo -->
            <div style="padding:14px 18px;">
                <!-- Wallet destino -->
                <div style="margin-bottom:10px;">
                    <div style="font-size:0.6rem; color:#444; text-transform:uppercase; letter-spacing:1px; margin-bottom:3px;">Wallet destino (TRC-20)</div>
                    <div style="font-family:monospace; font-size:0.75rem; color:#00d2ff; word-break:break-all; background:rgba(0,210,255,0.05); border-radius:8px; padding:8px 10px; border:1px solid rgba(0,210,255,0.1);">${wallet}</div>
                </div>
                <!-- Fila: monto + fecha + botones -->
                <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-top:12px;">
                    <div>
                        <div style="font-size:0.6rem; color:#444; text-transform:uppercase; letter-spacing:1px;">Monto a transferir</div>
                        <div style="font-size:1.5rem; font-weight:800; color:#00e676; line-height:1.2;">$${monto} <span style="font-size:0.7rem; font-weight:600; color:#555;">USDT</span></div>
                        <div style="font-size:0.68rem; color:#444; margin-top:2px;">📅 Solicitado: ${fecha}</div>
                    </div>
                    <div style="display:flex; flex-direction:column; gap:8px; align-items:flex-end;">
                        <button onclick="procesarRetiroDashboard(${r.id},'aprobar')"
                            style="background:linear-gradient(135deg,#00e676,#00c853); color:#000; border:none; border-radius:10px; padding:10px 22px; font-size:0.8rem; font-weight:800; cursor:pointer; white-space:nowrap; width:160px;">
                            ✅ APROBAR RETIRO
                        </button>
                        <button onclick="procesarRetiroDashboard(${r.id},'rechazar')"
                            style="background:rgba(255,82,82,0.08); color:#ff5252; border:1px solid rgba(255,82,82,0.25); border-radius:10px; padding:10px 22px; font-size:0.8rem; font-weight:800; cursor:pointer; white-space:nowrap; width:160px;">
                            ❌ RECHAZAR
                        </button>
                    </div>
                </div>
            </div>
        </div>`;
    }).join('');
}

async function procesarRetiroDashboard(retiroId, accion) {
    const etiqueta = accion === 'aprobar' ? 'APROBAR' : 'RECHAZAR';
    let notas = '';
    if (accion === 'rechazar') {
        notas = prompt('Motivo del rechazo (opcional):') || '';
    }
    if (!confirm(`¿Confirmas ${etiqueta} el retiro #${retiroId}?`)) return;

    const el = document.getElementById(`retiro-master-${retiroId}`);
    if (el) el.style.opacity = '0.5';

    const fd = new FormData();
    fd.append('retiro_id', retiroId);
    fd.append('accion', accion);
    fd.append('notas', notas);

    try {
        const res  = await fetch('radix_api/procesar_retiro.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            if (el) el.innerHTML = `<div style="text-align:center; padding:12px; font-size:0.85rem; color:${accion==='aprobar'?'#00e676':'#ff5252'}; font-weight:700;">
                ${accion === 'aprobar' ? '✅ Aprobado y notificado' : '❌ Rechazado y notificado'}
            </div>`;
            mostrarToast(data.mensaje, accion === 'aprobar' ? '#00e676' : '#ff5252');
            // Quitar de la lista interna
            _masterRetirosList = _masterRetirosList.filter(r => r.id !== retiroId);
        } else {
            if (el) el.style.opacity = '1';
            mostrarToast('⚠️ ' + (data.error || 'Error al procesar'), '#ff5252');
        }
    } catch (e) {
        if (el) el.style.opacity = '1';
        mostrarToast('⚠️ Error de conexión', '#ff5252');
    }
}

function renderMasterClonesFull() {
    const body = document.getElementById('master-clones-full-body');
    if (!body) return;
    const cloneLogs = (_masterAuditoria || []).filter(l => l.accion === 'ACTIVACION_CLON');
    body.innerHTML = cloneLogs.length
        ? cloneLogs.map((l, i) => `<tr>
            <td style="color:#aaa;">#${l.id || (i + 1)}</td>
            <td style="color:#00d2ff;">${l.nickname || '—'}</td>
            <td style="color:#555; font-size:0.75rem;">${(l.fecha||'').split(' ')[0]}</td>
          </tr>`).join('')
        : '<tr><td colspan="3" style="color:#444; padding:15px; text-align:center;">Sin agentes activados aún.</td></tr>';
}

/* ── AUDIT HELPERS ────────────────────────────────────────────── */
const _AUDIT_META = {
    // Tableros
    AVANCE_TABLERO_A_A_B:        { icon: '📊', color: '#00d2ff', label: 'Avance A → B',       group: 'tableros' },
    AVANCE_TABLERO_B_A_C:        { icon: '📊', color: '#00aaff', label: 'Avance B → C',       group: 'tableros' },
    CICLO_COMPLETADO_C1:         { icon: '🏆', color: '#00e676', label: 'Ciclo 1 Completado', group: 'tableros' },
    CICLO_COMPLETADO_C2:         { icon: '🏆', color: '#69f0ae', label: 'Ciclo 2 Completado', group: 'tableros' },
    CICLO_COMPLETADO_C3:         { icon: '🏆', color: '#b9f6ca', label: 'Ciclo 3 Completado', group: 'tableros' },
    // Fases
    ACTIVACION_FASE_PARALELA:    { icon: '🚀', color: '#ff6d00', label: 'Fase Paralela Activada', group: 'fases' },
    NUEVA_FASE_ABIERTA:          { icon: '🚀', color: '#ffab40', label: 'Nueva Fase Abierta',   group: 'fases' },
    // Clones
    ACTIVACION_CLON:             { icon: '🤖', color: '#9d00ff', label: 'Agente IA Activado',  group: 'clones' },
    CLON_REEMPLAZADO:            { icon: '🔄', color: '#ce93d8', label: 'Clon Reemplazado',    group: 'clones' },
    // Retiros
    RETIRO_APROBADO:             { icon: '💸', color: '#00e676', label: 'Retiro Aprobado',     group: 'retiros' },
    RETIRO_RECHAZADO:            { icon: '❌', color: '#ef5350', label: 'Retiro Rechazado',    group: 'retiros' },
    SOLICITUD_RETIRO:            { icon: '📤', color: '#ffb300', label: 'Solicitud de Retiro', group: 'retiros' },
    // Usuarios
    REGISTRO_USUARIO:            { icon: '👤', color: '#4fc3f7', label: 'Nuevo Registro',      group: 'usuarios' },
    VINCULAR_TELEGRAM:           { icon: '📱', color: '#29b6f6', label: 'Telegram Vinculado',  group: 'usuarios' },
};

function _auditMeta(accion) {
    if (!accion) return { icon: '📋', color: '#666', label: accion || '—', group: 'otros' };
    // Exact match
    if (_AUDIT_META[accion]) return _AUDIT_META[accion];
    // Partial matches
    if (accion.startsWith('AVANCE_TABLERO')) return { icon: '📊', color: '#00d2ff', label: accion.replace(/_/g,' ').toLowerCase().replace(/\b./,c=>c.toUpperCase()), group: 'tableros' };
    if (accion.startsWith('CICLO_COMPLETADO')) return { icon: '🏆', color: '#00e676', label: 'Ciclo Completado', group: 'tableros' };
    if (accion.includes('CLON'))    return { icon: '🤖', color: '#9d00ff', label: 'Agente IA', group: 'clones' };
    if (accion.includes('RETIRO'))  return { icon: '💰', color: '#ffb300', label: 'Retiro', group: 'retiros' };
    if (accion.includes('FASE'))    return { icon: '🚀', color: '#ff6d00', label: 'Fase', group: 'fases' };
    if (accion.includes('REGISTRO'))return { icon: '👤', color: '#4fc3f7', label: 'Registro', group: 'usuarios' };
    return { icon: '📋', color: '#666', label: accion.replace(/_/g,' '), group: 'otros' };
}

function _auditRelTime(fechaStr) {
    if (!fechaStr) return '—';
    const d = new Date(fechaStr.replace(' ','T'));
    const diff = Math.floor((Date.now() - d.getTime()) / 1000);
    if (diff < 60)   return `hace ${diff}s`;
    if (diff < 3600) return `hace ${Math.floor(diff/60)}m`;
    if (diff < 86400)return `hace ${Math.floor(diff/3600)}h`;
    return fechaStr.split(' ')[0];
}

// Groups consecutive events ≤ CASCADA_UMBRAL_S apart into a single cascade block
const CASCADA_UMBRAL_S = 30; // seconds

function _groupCascadas(logs) {
    if (!logs.length) return [];
    const groups = [];
    let current = [logs[0]];
    const ts = l => new Date((l.fecha||'').replace(' ','T')).getTime() / 1000;
    for (let i = 1; i < logs.length; i++) {
        const diff = Math.abs(ts(logs[i-1]) - ts(logs[i]));
        if (diff <= CASCADA_UMBRAL_S) {
            current.push(logs[i]);
        } else {
            groups.push(current);
            current = [logs[i]];
        }
    }
    groups.push(current);
    return groups;
}

let _auditFiltro = 'all';

function renderMasterAuditoriaFull() {
    const container = document.getElementById('master-auditoria-timeline');
    if (!container) return;

    let filtered = _masterAuditoria;
    if (_auditFiltro !== 'all') {
        filtered = filtered.filter(l => _auditMeta(l.accion).group === _auditFiltro);
    }

    if (!filtered.length) {
        container.innerHTML = `<div style="text-align:center; padding:60px 20px; color:#444;">
            <div style="font-size:2.5rem; margin-bottom:12px;">📭</div>
            <div style="font-size:0.85rem;">Sin actividad registrada.</div>
        </div>`;
        return;
    }

    const groups = _groupCascadas(filtered);

    container.innerHTML = groups.map((group, gi) => {
        const isCascade = group.length > 1;
        const headerLog  = group[0];
        const meta = _auditMeta(headerLog.accion);
        const faseTag = headerLog.fase_numero !== null && headerLog.fase_numero !== undefined
            ? `<span style="background:rgba(255,255,255,0.06); border-radius:4px; padding:1px 6px; font-size:0.7rem; color:#888; margin-left:6px;">Fase ${headerLog.fase_numero}</span>` : '';

        if (isCascade) {
            // Cascade block — show header + collapsed sub-events
            const cascadeItems = group.map((l, li) => {
                const m = _auditMeta(l.accion);
                const who = l.nombre_completo || l.nickname ? `<span style="color:#aaa; font-size:0.72rem;">${l.nombre_completo || l.nickname}</span>` : '';
                const fn = l.fase_numero !== null && l.fase_numero !== undefined
                    ? `<span style="color:#555; font-size:0.7rem; margin-left:4px;">F${l.fase_numero}</span>` : '';
                return `<div style="display:flex; align-items:flex-start; gap:10px; padding:6px 0; border-top: 1px solid rgba(255,255,255,0.04);">
                    <span style="font-size:1rem; min-width:20px;">${m.icon}</span>
                    <div style="flex:1; min-width:0;">
                        <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                            <span style="color:${m.color}; font-size:0.78rem; font-weight:700;">${m.label}</span>
                            ${fn} ${who}
                        </div>
                        <div style="color:#555; font-size:0.72rem; margin-top:3px; word-break:break-word;">${(l.detalles||'').substring(0,120)}${(l.detalles||'').length>120?'…':''}</div>
                    </div>
                    <span style="color:#444; font-size:0.7rem; white-space:nowrap; margin-top:2px;">${_auditRelTime(l.fecha)}</span>
                </div>`;
            }).join('');

            return `<div style="background:rgba(255,107,0,0.05); border:1px solid rgba(255,107,0,0.18); border-radius:12px; margin-bottom:12px; overflow:hidden;">
                <div style="display:flex; align-items:center; gap:10px; padding:12px 14px; cursor:pointer; user-select:none;"
                     onclick="this.nextElementSibling.style.display = this.nextElementSibling.style.display==='none'?'block':'none'; this.querySelector('.cas-arrow').style.transform = this.nextElementSibling.style.display==='block'?'rotate(90deg)':'rotate(0deg)';">
                    <span style="background:rgba(255,107,0,0.15); border-radius:50%; width:32px; height:32px; display:flex; align-items:center; justify-content:center; font-size:1.1rem; flex-shrink:0;">⚡</span>
                    <div style="flex:1;">
                        <div style="font-size:0.82rem; font-weight:800; color:#ff6d00;">CASCADA AUTOMÁTICA — ${group.length} eventos</div>
                        <div style="font-size:0.72rem; color:#777; margin-top:2px;">${_auditRelTime(headerLog.fecha)} · Disparado por ${meta.icon} ${meta.label}${faseTag}</div>
                    </div>
                    <span class="cas-arrow" style="color:#666; font-size:0.9rem; transition:transform 0.2s;">▶</span>
                </div>
                <div style="display:none; padding:10px 14px 12px 14px;">${cascadeItems}</div>
            </div>`;
        }

        // Single event
        const l = group[0];
        const who = l.nombre_completo || l.nickname
            ? `<span style="color:#aaa; font-size:0.75rem; margin-left:6px;">${l.nombre_completo || l.nickname}</span>` : '';
        const tipoUsuario = l.tipo_usuario === 'clon' ? '<span style="color:#9d00ff; font-size:0.68rem; margin-left:4px;">[IA]</span>' : '';

        return `<div style="display:flex; align-items:flex-start; gap:12px; padding:12px 14px; background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.06); border-radius:10px; margin-bottom:8px;">
            <span style="background:rgba(255,255,255,0.05); border-radius:50%; width:34px; height:34px; display:flex; align-items:center; justify-content:center; font-size:1.1rem; flex-shrink:0; border: 1px solid ${meta.color}33;">${meta.icon}</span>
            <div style="flex:1; min-width:0;">
                <div style="display:flex; align-items:center; flex-wrap:wrap; gap:4px; margin-bottom:4px;">
                    <span style="color:${meta.color}; font-weight:700; font-size:0.82rem;">${meta.label}</span>
                    ${faseTag} ${who} ${tipoUsuario}
                </div>
                <div style="color:#666; font-size:0.75rem; word-break:break-word; line-height:1.4;">${(l.detalles||'—').substring(0,140)}${(l.detalles||'').length>140?'…':''}</div>
            </div>
            <span style="color:#444; font-size:0.7rem; white-space:nowrap; margin-top:2px;">${_auditRelTime(l.fecha)}</span>
        </div>`;
    }).join('');
}

function setAuditFiltro(f) {
    _auditFiltro = f;
    document.querySelectorAll('.audit-filter-btn').forEach(b => {
        b.style.background = b.dataset.f === f ? 'rgba(157,0,255,0.25)' : 'rgba(255,255,255,0.05)';
        b.style.color       = b.dataset.f === f ? '#e040fb' : '#888';
        b.style.borderColor = b.dataset.f === f ? '#9d00ff' : '#333';
    });
    renderMasterAuditoriaFull();
}

async function refreshAuditoria() {
    const btn = document.getElementById('audit-refresh-btn');
    if (btn) { btn.style.opacity='0.5'; btn.innerText='⏳'; }
    try {
        const resp = await fetch('radix_api/admin_global_stats.php');
        const data = await resp.json();
        _masterAuditoria = data.logs_actividad || [];
        renderMasterAuditoriaFull();
    } catch(e) { console.error('Refresh auditoria error:', e); }
    if (btn) { btn.style.opacity='1'; btn.innerText='🔄 Actualizar'; }
}

/* ══ PANORAMA POR FASE ═══════════════════════════════════════════ */

const FASE_COLORS = ['#00d2ff', '#9d00ff', '#00e676', '#ffb300'];
const FASE_NAMES  = ['Fase 0', 'Fase 1', 'Fase 2', 'Fase 3'];

/**
 * Renderiza las 4 tarjetas de fase con todos sus datos
 */
function renderFaseCards(fases) {
    const grid = document.getElementById('panorama-fases-grid');
    if (!grid || !fases || !fases.length) return;

    const fmt = n => '$' + parseFloat(n || 0).toFixed(2);

    grid.innerHTML = fases.map(f => {
        const fn    = f.fase_numero;
        const color = FASE_COLORS[fn] || '#aaa';
        const activa = f.activa;
        const totalPersonas = (f.personas_a||0) + (f.personas_b||0) + (f.personas_c||0);
        const totalActivos  = (f.reales_en_a||0)+(f.reales_en_b||0)+(f.reales_en_c||0)
                            + (f.clones_en_a||0)+(f.clones_en_b||0)+(f.clones_en_c||0);

        const boardRow = (tipo, dist, personas, reales, clones) => `
            <div style="display:grid; grid-template-columns:16px 1fr 1fr 1fr; gap:4px; align-items:center; padding:5px 0; border-top:1px solid #1a1a26;">
                <span style="background:${color}22; color:${color}; font-size:0.64rem; font-weight:800; border-radius:4px; text-align:center; padding:1px 0;">${tipo}</span>
                <span style="color:#fff; font-size:0.72rem; font-weight:700;">${fmt(dist)}</span>
                <span style="color:#777; font-size:0.68rem;">${personas} pagados</span>
                <span style="color:#555; font-size:0.66rem;">${reales}r+${clones}c activos</span>
            </div>`;

        return `<div style="background:#0d0d17; border:1px solid ${activa ? color+'44' : '#1e1e2e'}; border-radius:14px; padding:16px; position:relative; overflow:hidden;">
            <!-- Glow decorativo -->
            <div style="position:absolute; top:-20px; right:-20px; width:80px; height:80px; border-radius:50%; background:${color}; opacity:0.04; pointer-events:none;"></div>
            <!-- Header -->
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:12px;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <div style="width:8px; height:8px; border-radius:50%; background:${activa ? color : '#333'}; box-shadow:${activa ? '0 0 0 3px '+color+'33' : 'none'};"></div>
                    <span style="font-size:0.82rem; font-weight:800; color:${activa ? color : '#444'};">${f.nombre || ('Fase '+fn)}</span>
                </div>
                <span style="background:${activa ? color+'22' : 'rgba(255,255,255,0.04)'}; color:${activa ? color : '#444'}; font-size:0.6rem; font-weight:800; padding:2px 8px; border-radius:6px; letter-spacing:0.5px;">
                    ${activa ? 'ACTIVA' : 'INACTIVA'}
                </span>
            </div>

            <!-- KPIs principales -->
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:12px;">
                <div style="background:rgba(255,255,255,0.02); border-radius:8px; padding:8px;">
                    <div style="font-size:0.6rem; color:#555; text-transform:uppercase; letter-spacing:0.8px;">Distribuido total</div>
                    <div style="font-size:1rem; font-weight:800; color:${color}; margin-top:2px;">${fmt(f.dist_total)}</div>
                </div>
                <div style="background:rgba(255,255,255,0.02); border-radius:8px; padding:8px;">
                    <div style="font-size:0.6rem; color:#555; text-transform:uppercase; letter-spacing:0.8px;">Personas pagadas</div>
                    <div style="font-size:1rem; font-weight:800; color:#fff; margin-top:2px;">${totalPersonas}</div>
                </div>
                <div style="background:rgba(255,255,255,0.02); border-radius:8px; padding:8px;">
                    <div style="font-size:0.6rem; color:#555; text-transform:uppercase; letter-spacing:0.8px;">Pool de entrada</div>
                    <div style="font-size:0.85rem; font-weight:700; color:#ffb300; margin-top:2px;">${fmt(f.pool_entrada)}</div>
                </div>
                <div style="background:rgba(255,255,255,0.02); border-radius:8px; padding:8px;">
                    <div style="font-size:0.6rem; color:#555; text-transform:uppercase; letter-spacing:0.8px;">En red ahora</div>
                    <div style="font-size:0.85rem; font-weight:700; color:#fff; margin-top:2px;">${totalActivos} <span style="font-size:0.6rem; color:#555;">(${f.reales_en_a+f.reales_en_b+f.reales_en_c}r+${f.clones_en_a+f.clones_en_b+f.clones_en_c}c)</span></div>
                </div>
            </div>

            <!-- Desglose tableros -->
            <div style="margin-bottom:10px;">
                ${boardRow('A', f.dist_a, f.personas_a, f.reales_en_a, f.clones_en_a)}
                ${boardRow('B', f.dist_b, f.personas_b, f.reales_en_b, f.clones_en_b)}
                ${boardRow('C', f.dist_c, f.personas_c, f.reales_en_c, f.clones_en_c)}
            </div>

            <!-- Footer: ciclos + retiros -->
            <div style="display:flex; justify-content:space-between; align-items:center; padding-top:8px; border-top:1px solid #1a1a26;">
                <div style="font-size:0.68rem; color:#555;">
                    🏆 <span style="color:#888;">${f.ciclos_completados}</span> ciclos completados
                </div>
                <div style="font-size:0.68rem; color:#555;">
                    💸 <span style="color:#888;">${fmt(f.retiros_total)}</span> retirado (${f.retiros_cnt})
                </div>
            </div>
        </div>`;
    }).join('');
}

/**
 * Renderiza la matriz Tablero (A/B/C) × Fase (0/1/2/3)
 */
function renderMatrizTableroFase(fases) {
    const container = document.getElementById('matrix-tablero-fase');
    if (!container || !fases || !fases.length) return;

    const fmt = n => '$' + parseFloat(n || 0).toFixed(2);

    // Build a map: fn → {A,B,C}
    const fMap = {};
    fases.forEach(f => { fMap[f.fase_numero] = f; });

    const fasesOrdenadas = fases.sort((a,b) => a.fase_numero - b.fase_numero);

    const headerCols = fasesOrdenadas.map(f => {
        const c = FASE_COLORS[f.fase_numero] || '#aaa';
        return `<th style="text-align:center; padding:8px 12px; font-size:0.72rem; color:${c}; border-bottom:2px solid ${c}44;">${f.nombre || 'Fase '+f.fase_numero}</th>`;
    }).join('');

    const makeRow = (tablero, distKey, personasKey, realesKey, clonesKey, boardColor) => {
        const cells = fasesOrdenadas.map(f => {
            const dist    = parseFloat(f[distKey]    || 0);
            const personas= parseInt(f[personasKey]  || 0);
            const reales  = parseInt(f[realesKey]    || 0);
            const clones  = parseInt(f[clonesKey]    || 0);
            const isEmpty = dist === 0 && personas === 0 && reales === 0 && clones === 0;
            const fc = FASE_COLORS[f.fase_numero] || '#aaa';
            return isEmpty
                ? `<td style="text-align:center; padding:10px 12px; color:#2a2a3a; font-size:0.72rem;">—</td>`
                : `<td style="text-align:center; padding:10px 12px;">
                    <div style="font-size:0.82rem; font-weight:800; color:${fc};">${fmt(dist)}</div>
                    <div style="font-size:0.68rem; color:#666; margin-top:2px;">${personas} personas</div>
                    <div style="font-size:0.64rem; color:#444;">${reales}r · ${clones}c activos</div>
                  </td>`;
        }).join('');
        return `<tr>
            <td style="padding:10px 14px; font-weight:800; font-size:0.78rem; color:${boardColor}; white-space:nowrap; border-right:1px solid #1a1a26;">
                Tablero ${tablero}
            </td>
            ${cells}
        </tr>`;
    };

    container.innerHTML = `
        <table style="width:100%; border-collapse:collapse; background:#0a0a12; border-radius:12px; overflow:hidden;">
            <thead>
                <tr>
                    <th style="padding:10px 14px; text-align:left; font-size:0.65rem; color:#444; text-transform:uppercase; letter-spacing:1px; border-right:1px solid #1a1a26; border-bottom:1px solid #1a1a26; background:#080810;"></th>
                    ${headerCols.replace(/border-bottom:2px/g, 'border-bottom:1px').replace(/<th /g,'<th style="background:#080810;" ')}
                </tr>
            </thead>
            <tbody>
                ${makeRow('A', 'dist_a', 'personas_a', 'reales_en_a', 'clones_en_a', '#9d00ff')}
                ${makeRow('B', 'dist_b', 'personas_b', 'reales_en_b', 'clones_en_b', '#00d2ff')}
                ${makeRow('C', 'dist_c', 'personas_c', 'reales_en_c', 'clones_en_c', '#00e676')}
            </tbody>
        </table>`;
}

/**
 * Renderiza el embudo de conversión de usuarios
 */
function renderEmbudo(embudo) {
    const el = document.getElementById('embudo-content');
    if (!el || !embudo) return;

    const steps = [
        { label: 'Registrados',      val: embudo.registrados,   color: '#aaa' },
        { label: 'Pagaron entrada',  val: embudo.pagaron,       color: '#00d2ff' },
        { label: 'En Tablero A',     val: embudo.en_a,          color: '#9d00ff' },
        { label: 'En Tablero B',     val: embudo.en_b,          color: '#00d2ff' },
        { label: 'En Tablero C',     val: embudo.en_c,          color: '#00e676' },
        { label: 'Completaron ciclo',val: embudo.completaron,   color: '#ffb300' },
        { label: 'Han retirado',     val: embudo.retiraron,     color: '#ef5350' },
    ];

    const maxVal = Math.max(...steps.map(s => s.val || 0), 1);

    el.innerHTML = steps.map((s, i) => {
        const pct = Math.round(((s.val || 0) / maxVal) * 100);
        const convPct = i > 0 && steps[i-1].val > 0
            ? Math.round(((s.val||0) / steps[i-1].val) * 100) : null;
        return `<div style="margin-bottom:8px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:3px;">
                <span style="font-size:0.71rem; color:#888;">${s.label}</span>
                <div style="display:flex; align-items:center; gap:6px;">
                    ${convPct !== null ? `<span style="font-size:0.62rem; color:${convPct < 50 ? '#ef5350' : convPct < 80 ? '#ffb300' : '#00e676'};">${convPct}%</span>` : ''}
                    <strong style="font-size:0.8rem; color:${s.color};">${s.val || 0}</strong>
                </div>
            </div>
            <div style="height:3px; background:#1a1a26; border-radius:2px;">
                <div style="height:100%; width:${pct}%; background:${s.color}; border-radius:2px; transition:width 0.8s ease;"></div>
            </div>
        </div>`;
    }).join('');
}

/**
 * Renderiza la comparativa semanal
 */
function renderVelocidad(v) {
    const el = document.getElementById('velocidad-content');
    if (!el || !v) return;

    const arrow = (actual, prev) => {
        if (!prev) return '';
        const diff = actual - prev;
        const pct  = Math.abs(Math.round((diff / prev) * 100));
        const up   = diff >= 0;
        return `<span style="color:${up ? '#00e676' : '#ef5350'}; font-size:0.65rem; margin-left:4px;">${up ? '▲' : '▼'} ${pct}%</span>`;
    };

    const fmt = n => '$' + parseFloat(n || 0).toFixed(2);

    const items = [
        { label: 'Nuevos usuarios', actual: v.usuarios_esta_semana, prev: v.usuarios_semana_pasada, display: n => n, color: '#00d2ff' },
        { label: 'Distribuido',     actual: v.dist_esta_semana,     prev: v.dist_semana_pasada,     display: fmt,    color: '#00e676' },
        { label: 'Ciclos completados',actual:v.tableros_completados_semana,prev:null,               display: n => n, color: '#9d00ff' },
    ];

    el.innerHTML = items.map(item => `
        <div style="display:flex; justify-content:space-between; align-items:center; padding:9px 0; border-bottom:1px solid #1a1a26;">
            <span style="font-size:0.72rem; color:#666;">${item.label}</span>
            <div style="text-align:right;">
                <strong style="font-size:0.88rem; color:${item.color};">${item.display(item.actual)}</strong>
                ${item.prev !== null ? `<div style="font-size:0.64rem; color:#444;">ant. ${item.display(item.prev)}${arrow(item.actual, item.prev)}</div>` : ''}
            </div>
        </div>`).join('');
}

/**
 * Verifica que los números financieros cuadren
 * Blockchain recibido = Distribuido + Tesorería + Pools + Pendiente
 */
function renderIntegridad(data) {
    const el = document.getElementById('integridad-content');
    if (!el) return;

    const blockchain  = parseFloat(data.total_blockchain         || 0);
    const distribuido = parseFloat(data.master_id1_earnings      || 0);
    const tesoreria   = parseFloat(data.tesoreria                || 0);
    const fase1Pool   = parseFloat(data.fase1_pool               || 0);
    const reentrada   = parseFloat(data.reentrada_pool           || 0);
    const creditos    = parseFloat(data.creditos_excedente       || 0);
    const pendiente   = parseFloat(data.pendiente_distribuir     || 0);

    const suma    = distribuido + tesoreria + fase1Pool + reentrada + creditos + pendiente;
    const diff    = Math.abs(blockchain - suma);
    const ok      = diff < 0.05; // tolerancia de 5 centavos
    const fmt     = n => '$' + parseFloat(n).toFixed(2);

    el.innerHTML = `
        <div style="background:${ok ? 'rgba(0,230,118,0.06)' : 'rgba(239,83,80,0.06)'}; border:1px solid ${ok ? 'rgba(0,230,118,0.25)' : 'rgba(239,83,80,0.3)'}; border-radius:8px; padding:10px 12px; margin-bottom:12px; text-align:center;">
            <div style="font-size:1.4rem; margin-bottom:4px;">${ok ? '✅' : '⚠️'}</div>
            <div style="font-size:0.78rem; font-weight:800; color:${ok ? '#00e676' : '#ffb300'};">${ok ? 'Números cuadran' : 'Diferencia detectada'}</div>
            ${!ok ? `<div style="font-size:0.68rem; color:#888; margin-top:3px;">Δ ${fmt(diff)} sin explicación</div>` : ''}
        </div>
        <div style="font-size:0.68rem; color:#555; margin-bottom:6px;">Blockchain total recibido: <strong style="color:#00d2ff;">${fmt(blockchain)}</strong></div>
        ${[
            ['Distribuido red',  distribuido, '#9d00ff'],
            ['Tesorería',        tesoreria,   '#00e676'],
            ['Pool Fase 1',      fase1Pool,   '#ffb300'],
            ['Pool Reentrada',   reentrada,   '#ff6d00'],
            ['Créditos excedente',creditos,   '#4fc3f7'],
            ['Por distribuir',   pendiente,   '#ef5350'],
        ].map(([lbl, val, col]) => `
            <div style="display:flex; justify-content:space-between; padding:4px 0;">
                <span style="font-size:0.68rem; color:#555;">+ ${lbl}</span>
                <span style="font-size:0.7rem; color:${col}; font-weight:700;">${fmt(val)}</span>
            </div>`).join('')}
        <div style="border-top:1px solid #1a1a26; margin-top:6px; padding-top:6px; display:flex; justify-content:space-between;">
            <span style="font-size:0.7rem; color:#666; font-weight:700;">= SUMA</span>
            <span style="font-size:0.75rem; color:${ok ? '#00e676' : '#ffb300'}; font-weight:800;">${fmt(suma)}</span>
        </div>`;
}

/**
 * Punto de entrada: llama a todas las funciones del panorama
 */
function renderPanoramaFases(data) {
    const fases = data.fase_breakdown || [];
    renderFaseCards(fases);
    renderMatrizTableroFase([...fases]);
    renderEmbudo(data.embudo || {});
    renderVelocidad(data.velocidad || {});
    renderIntegridad(data);
}

/* ══ FIN PANORAMA POR FASE ════════════════════════════════════════ */

function renderMasterCharts(growthData) {
    const ctx = document.getElementById('grafica-crecimiento');
    if (!ctx) return;
    if (_chartInstance) _chartInstance.destroy();
    _chartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: growthData.map(d => d.dia),
            datasets: [{ label: 'Nuevos', data: growthData.map(d => d.nuevos), backgroundColor: 'rgba(157,0,255,0.4)', borderColor: '#9d00ff', borderWidth: 2 }]
        },
        options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
    });
}

function renderUserTeam(refs = [], equipoCiclo = null, reservas = null, tablero = null, context = null) {
    const box = document.getElementById('team-list');
    if (!box) return;
    const phaseLabel = context?.fase_nombre || tablero?.fase_nombre || `Fase ${context?.fase_numero ?? tablero?.fase_numero ?? 0}`;
    const cycleLabel = context?.ciclo ?? equipoCiclo?.ciclo ?? tablero?.ciclo ?? 1;
    const resumen = `
        <div style="background:rgba(255,255,255,0.02); border:1px solid #1a1a28; border-radius:10px; padding:10px 12px; margin-bottom:12px;">
            <div style="display:flex; justify-content:space-between; gap:10px; font-size:0.72rem; color:#777; flex-wrap:wrap;">
                <span>${phaseLabel} · Ciclo ${cycleLabel}</span>
                <span>Reales: <strong style="color:#ddd;">${equipoCiclo?.reales ?? refs.length ?? 0}</strong></span>
                <span>Clones: <strong style="color:#ffb300;">${equipoCiclo?.clones ?? 0}</strong></span>
            </div>
            <div style="display:flex; justify-content:space-between; gap:10px; font-size:0.7rem; color:#555; margin-top:8px; flex-wrap:wrap;">
                <span>A→B: $${parseFloat(reservas?.a_b ?? 0).toFixed(2)}</span>
                <span>B→C: $${parseFloat(reservas?.b_c ?? 0).toFixed(2)}</span>
                <span>Reentrada A: $${parseFloat(reservas?.reentrada_a ?? 0).toFixed(2)}</span>
            </div>
        </div>`;
    const equipoHtml = refs.length ? refs.map(r => {
        const estado = r.pago_estado === 'completado' ? '<span style="color:#00e676;">✓ Pagado</span>' :
                       r.pago_estado === 'pendiente'  ? '<span style="color:#ffb300;">⏳ Pendiente</span>' :
                                                        '<span style="color:#666;">Sin pago</span>';
        const nivel = r.nivel_actual ? ` · Tablero ${r.nivel_actual}` : '';
        return `<div style="padding:10px 0; border-bottom:1px solid #1a1a28; display:flex; justify-content:space-between; align-items:center;">
            <strong style="color:#ddd;">${r.display_name || r.nickname}</strong>
            <span style="font-size:0.78rem;">${estado}${nivel}</span>
        </div>`;
    }).join('') : '<div style="color:#444; text-align:center; padding:10px;">Sin equipo aún.</div>';
    box.innerHTML = resumen + equipoHtml;
}

async function activarClonManual() {
    const nickname  = (document.getElementById('clon-nickname-input')?.value || '').trim();
    const resultEl  = document.getElementById('clon-result');

    if (!nickname) {
        if (resultEl) { resultEl.style.color = '#ff5252'; resultEl.innerText = '⚠️ Escribe el nickname del beneficiario.'; }
        return;
    }

    if (resultEl) { resultEl.style.color = '#aaa'; resultEl.innerText = '⏳ Activando Agente IA...'; }

    const fd = new FormData();
    fd.append('nickname', nickname);

    const res  = await fetch('radix_api/admin_activar_clon.php', { method: 'POST', body: fd });
    const data = await res.json();

    if (resultEl) {
        resultEl.style.color = data.success ? '#00e676' : '#ff5252';
        resultEl.innerText   = data.resultado || data.error || '';
    }
    mostrarToast(
        data.success ? '🤖 ' + (data.resultado || 'Agente Inyectado') : '❌ ' + (data.error || 'Error'),
        data.success ? '#00e676' : '#ff5252'
    );
    if (data.success) {
        document.getElementById('clon-nickname-input').value = '';
        setTimeout(() => location.reload(), 2000);
    }
}

// ── Reemplazo de usuario por Agente IA ────────────────────────────────────────
let _reemplazoNicknameActual = null;

async function buscarUsuarioParaReemplazar() {
    const nickname = (document.getElementById('reemplazo-nickname-input')?.value || '').trim();
    const resultEl  = document.getElementById('reemplazo-result');
    const previewEl = document.getElementById('reemplazo-preview');
    const infoEl    = document.getElementById('reemplazo-preview-info');

    if (!nickname) {
        resultEl.style.color = '#ff5252';
        resultEl.innerText = '⚠️ Escribe el nickname del usuario a reemplazar.';
        return;
    }

    resultEl.style.color = '#aaa';
    resultEl.innerText = '🔍 Buscando...';
    if (previewEl) previewEl.style.display = 'none';

    try {
        const fd = new FormData();
        fd.append('nickname', nickname);
        // Sin confirmar = solo previsualización
        const res  = await fetch('radix_api/admin_reemplazar_con_clon.php', {
            method: 'POST',
            headers: { 'Accept': 'application/json' },
            body: fd
        });
        const data = await res.json();

        if (!data.success) {
            resultEl.style.color = '#ff5252';
            resultEl.innerText = '❌ ' + (data.error || 'Error al buscar usuario.');
            return;
        }

        // Mostrar previsualización completa
        _reemplazoNicknameActual = nickname;
        resultEl.innerText = '';

        const u        = data.usuario;
        const pos      = data.posicion;
        const pagos    = data.pagos;
        const fondosOk = data.fondos_ok;

        // Formatear fecha de registro
        const fechaReg = u.fecha_registro && u.fecha_registro !== '—'
            ? new Date(u.fecha_registro).toLocaleDateString('es-ES', { day:'2-digit', month:'short', year:'numeric' })
            : '—';

        // Estado de pagos badge
        const estadoPago = pagos.total === 0
            ? `<span style="background:#333;color:#aaa;padding:2px 8px;border-radius:10px;font-size:0.7rem;">Sin pagos registrados</span>`
            : pagos.completados > 0
                ? `<span style="background:#1a3a2a;color:#00e676;padding:2px 8px;border-radius:10px;font-size:0.7rem;">✅ ${pagos.completados} pago(s) completado(s)</span>`
                : `<span style="background:#3a2a1a;color:#ffb300;padding:2px 8px;border-radius:10px;font-size:0.7rem;">⏳ ${pagos.pendientes} pago(s) pendiente(s)</span>`;

        // Historial de pagos (últimos 3)
        const ultimosPagos = (pagos.historial || []).slice(0, 3).map(p => `
            <div style="display:flex;justify-content:space-between;align-items:center;padding:5px 0;border-bottom:1px solid #222;">
                <span style="font-size:0.7rem;color:#aaa;">${p.tipo}</span>
                <span style="font-size:0.7rem;color:#fff;">$${parseFloat(p.monto||0).toFixed(2)}</span>
                <span style="font-size:0.68rem;color:${p.estado==='completado'?'#00e676':p.estado==='pendiente'?'#ffb300':'#ff5252'};">${p.estado}</span>
            </div>
        `).join('') || '<div style="font-size:0.7rem;color:#666;text-align:center;padding:6px 0;">Sin historial de pagos</div>';

        infoEl.innerHTML = `
            <!-- Datos del usuario -->
            <div style="margin-bottom:12px;">
                <div style="font-size:0.65rem;text-transform:uppercase;color:#666;letter-spacing:1px;margin-bottom:6px;">Datos del usuario</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;">
                    <div style="background:#111;border-radius:8px;padding:8px;">
                        <div style="font-size:0.65rem;color:#666;">Nickname</div>
                        <div style="font-size:0.82rem;color:#fff;font-weight:600;">${u.nickname}</div>
                    </div>
                    <div style="background:#111;border-radius:8px;padding:8px;">
                        <div style="font-size:0.65rem;color:#666;">Tipo</div>
                        <div style="font-size:0.82rem;color:#9d00ff;font-weight:600;">${u.tipo}</div>
                    </div>
                    <div style="background:#111;border-radius:8px;padding:8px;">
                        <div style="font-size:0.65rem;color:#666;">Registro</div>
                        <div style="font-size:0.78rem;color:#fff;">${fechaReg}</div>
                    </div>
                    <div style="background:#111;border-radius:8px;padding:8px;">
                        <div style="font-size:0.65rem;color:#666;">Patrocinador</div>
                        <div style="font-size:0.78rem;color:#00d2ff;">${u.patrocinador}</div>
                    </div>
                    <div style="background:#111;border-radius:8px;padding:8px;">
                        <div style="font-size:0.65rem;color:#666;">Referidos propios</div>
                        <div style="font-size:0.82rem;color:#fff;">${u.total_referidos}</div>
                    </div>
                    <div style="background:#111;border-radius:8px;padding:8px;">
                        <div style="font-size:0.65rem;color:#666;">Wallet</div>
                        <div style="font-size:0.65rem;color:#aaa;word-break:break-all;">${u.wallet ? u.wallet.substring(0,16)+'...' : '—'}</div>
                    </div>
                </div>
            </div>

            <!-- Posición en la matriz -->
            <div style="margin-bottom:12px;">
                <div style="font-size:0.65rem;text-transform:uppercase;color:#666;letter-spacing:1px;margin-bottom:6px;">Posición en la matriz</div>
                <div style="background:#111;border-radius:8px;padding:10px;display:flex;gap:12px;flex-wrap:wrap;">
                    <div><span style="color:#666;font-size:0.7rem;">Bajo: </span><span style="color:#fff;font-size:0.8rem;font-weight:600;">${pos.padre_nickname}</span></div>
                    <div><span style="color:#666;font-size:0.7rem;">Fase: </span><span style="color:#00d2ff;font-size:0.8rem;font-weight:600;">${pos.fase_numero}</span></div>
                    <div><span style="color:#666;font-size:0.7rem;">Tablero: </span><span style="color:#9d00ff;font-size:0.8rem;font-weight:600;">${pos.tablero_tipo}</span></div>
                    <div><span style="color:#666;font-size:0.7rem;">Posición: </span><span style="color:#fff;font-size:0.8rem;font-weight:600;">#${pos.posicion}</span></div>
                </div>
            </div>

            <!-- Estado de pagos -->
            <div style="margin-bottom:12px;">
                <div style="font-size:0.65rem;text-transform:uppercase;color:#666;letter-spacing:1px;margin-bottom:6px;">Estado de pagos ${estadoPago}</div>
                <div style="background:#111;border-radius:8px;padding:10px;">
                    ${ultimosPagos}
                </div>
            </div>

            <!-- Costo del reemplazo -->
            <div style="background:#0d1f0d;border:1px solid ${fondosOk ? '#00e676' : '#ff5252'};border-radius:8px;padding:10px;margin-bottom:4px;">
                <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
                    <span style="color:#aaa;font-size:0.75rem;">Costo del Agente IA:</span>
                    <span style="color:#fff;font-size:0.82rem;font-weight:600;">$${parseFloat(data.monto_clon).toFixed(2)} USDT</span>
                </div>
                <div style="display:flex;justify-content:space-between;">
                    <span style="color:#aaa;font-size:0.75rem;">Tesorería disponible:</span>
                    <span style="color:${fondosOk ? '#00e676' : '#ff5252'};font-size:0.82rem;font-weight:600;">$${parseFloat(data.balance_actual).toFixed(2)} USDT</span>
                </div>
                ${!fondosOk ? '<div style="margin-top:6px;color:#ff5252;font-size:0.72rem;">❌ Fondos insuficientes en tesorería.</div>' : ''}
            </div>
        `;

        const confirmBtn = previewEl.querySelector('button');
        if (confirmBtn) confirmBtn.disabled = !fondosOk;
        previewEl.style.display = 'block';

    } catch (e) {
        resultEl.style.color = '#ff5252';
        resultEl.innerText = '❌ Error de conexión. Intenta de nuevo.';
    }
}

async function confirmarReemplazo() {
    if (!_reemplazoNicknameActual) return;

    const resultEl  = document.getElementById('reemplazo-result');
    const previewEl = document.getElementById('reemplazo-preview');

    resultEl.style.color = '#aaa';
    resultEl.innerText = '⏳ Ejecutando reemplazo...';
    previewEl.style.display = 'none';

    try {
        const fd = new FormData();
        fd.append('nickname', _reemplazoNicknameActual);
        fd.append('confirmar', 'si');
        const res  = await fetch('radix_api/admin_reemplazar_con_clon.php', {
            method: 'POST',
            headers: { 'Accept': 'application/json' },
            body: fd
        });
        const data = await res.json();

        if (data.success) {
            resultEl.style.color = '#00e676';
            resultEl.innerText = data.mensaje || '✅ Reemplazo exitoso.';
            mostrarToast('🤖 ' + (data.mensaje || 'Usuario reemplazado por Agente IA'), '#00e676');
            document.getElementById('reemplazo-nickname-input').value = '';
            _reemplazoNicknameActual = null;
            setTimeout(() => location.reload(), 2500);
        } else {
            resultEl.style.color = '#ff5252';
            resultEl.innerText = '❌ ' + (data.error || 'Error al ejecutar el reemplazo.');
        }
    } catch (e) {
        resultEl.style.color = '#ff5252';
        resultEl.innerText = '❌ Error de conexión. Intenta de nuevo.';
    }
}

function cancelarReemplazo() {
    _reemplazoNicknameActual = null;
    const previewEl = document.getElementById('reemplazo-preview');
    const resultEl  = document.getElementById('reemplazo-result');
    if (previewEl) previewEl.style.display = 'none';
    if (resultEl) resultEl.innerText = '';
    const input = document.getElementById('reemplazo-nickname-input');
    if (input) input.value = '';
}

function mostrarToast(msg, color = 'var(--primary)') {
    const c = document.getElementById('toast-container');
    const t = document.createElement('div');
    t.style.cssText = `background:#000; color:#fff; padding:12px 20px; border-radius:10px; border-left:3px solid ${color}; margin-bottom:10px; animation: fadeIn 0.3s;`;
    t.innerText = msg;
    c.appendChild(t);
    setTimeout(() => t.remove(), 4000);
}

var _pagoPendienteId = null;

function mostrarPagoPendiente(p) {
    const box = document.getElementById('pago-pendiente-box');
    const walletEl = document.getElementById('pp-wallet-patron');
    if (box) box.style.display = 'block';
    if (walletEl) {
        // Show wallet address + copy button
        const wallet = p.wallet_patron || '—';
        walletEl.innerHTML = `${wallet}
            <button onclick="navigator.clipboard.writeText('${wallet}').then(()=>mostrarToast('✅ Dirección copiada','#00e676'))"
                style="display:inline-block; margin-left:10px; background:rgba(0,210,255,0.12); border:1px solid rgba(0,210,255,0.3); color:#00d2ff; border-radius:6px; padding:3px 10px; font-size:0.65rem; cursor:pointer; vertical-align:middle;">COPIAR</button>`;
    }
    // Update amount label in box
    const monto = p.monto ? parseFloat(p.monto).toFixed(2) : '10.00';
    const montoLabel = document.querySelector('#pago-pendiente-box strong[data-monto]');
    if (montoLabel) montoLabel.textContent = `$${monto} USDT (TRC-20)`;
    _pagoPendienteId = p.id;
}

async function confirmarPago() {
    const tx = document.getElementById('tx-hash-input').value.trim();
    if (!tx || tx.length < 60) {
        mostrarToast("Hash TXID inválido", "#ff5252");
        return;
    }
    try {
        mostrarToast("⏳ Verificando transacción...", "#00d2ff");
        const res = await fetch('radix_api/verificar_pago.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `tx_hash=${tx}&pago_id=${_pagoPendienteId}`
        });
        const data = await res.json();

        if (data.success) {
            // ✅ Pago completo
            mostrarToast("✅ Pago Verificado con éxito!", "#00e676");
            setTimeout(() => location.reload(), 2000);

        } else if (data.parcial) {
            // ⚠️ Pago parcial — mostrar cuánto recibió y cuánto falta
            mostrarToast("⚠️ Pago parcial registrado", "#ff9800");

            // Mostrar alerta visual detallada debajo del campo de hash
            const inputBox = document.getElementById('tx-hash-input');
            if (inputBox) {
                // Limpiar alerta anterior si existe
                const prev = document.getElementById('parcial-alert');
                if (prev) prev.remove();

                const alerta = document.createElement('div');
                alerta.id = 'parcial-alert';
                alerta.style.cssText = `
                    background: rgba(255,152,0,0.1);
                    border: 1px solid rgba(255,152,0,0.5);
                    border-radius: 10px;
                    padding: 14px 16px;
                    margin-top: 12px;
                    color: #ffb74d;
                    font-size: 0.85rem;
                    line-height: 1.6;
                `;
                alerta.innerHTML = `
                    <div style="font-weight:800; font-size:1rem; margin-bottom:8px;">⚠️ Pago Parcial Registrado</div>
                    <div>✅ Recibido: <strong style="color:#fff;">$${data.monto_recibido} USDT</strong></div>
                    <div>❌ Faltante: <strong style="color:#ff5252;">$${data.monto_faltante} USDT</strong></div>
                    <div style="margin-top:8px; color:#aaa;">
                        Envía los <strong style="color:#ff5252;">$${data.monto_faltante} USDT</strong> restantes
                        a la misma wallet oficial y pega el nuevo hash aquí abajo.
                    </div>
                `;
                inputBox.parentNode.insertBefore(alerta, inputBox.nextSibling);
                inputBox.value = ''; // Limpiar campo para el segundo hash
                inputBox.focus();
            }

        } else {
            // ❌ Error
            mostrarToast("❌ " + (data.error || "No se pudo verificar"), "#ff5252");
        }

    } catch (e) {
        mostrarToast("❌ Error de comunicación", "#ff5252");
    }
}

async function renderNetworkTree(context = null) {
    const container = document.getElementById('network-tree');
    if (!container) return;

    container.innerHTML = `<p style="color:#444; font-size:0.8rem;">Cargando red...</p>`;

    try {
        const params = new URLSearchParams();
        if (context?.fase_numero !== undefined && context?.fase_numero !== null) {
            params.set('fase_numero', String(context.fase_numero));
        }
        if (context?.ciclo !== undefined && context?.ciclo !== null) {
            params.set('ciclo', String(context.ciclo));
        }

        const endpoint = params.toString()
            ? `radix_api/network_tree.php?${params.toString()}`
            : 'radix_api/network_tree.php';

        const res  = await fetch(endpoint);
        const data = await res.json();
        if (!data.success || !data.arbol) {
            container.innerHTML = `<p style="color:#444; font-size:0.8rem; text-align:center;">Sin datos de red aún.</p>`;
            return;
        }

        // Convertir árbol plano a jerarquía D3
        const root = d3.hierarchy(data.arbol, d => d.hijos && d.hijos.length ? d.hijos : null);
        const isMobile = window.innerWidth <= 768;
        const leafCount = Math.max(root.leaves().length, 1);
        const depthCount = Math.max(root.height + 1, 1);
        const compactMode = leafCount >= 5;
        const rootRadius = isMobile ? 20 : (compactMode ? 22 : 24);
        const childRadius = isMobile ? 14 : (compactMode ? 16 : 18);
        const verticalGap = isMobile ? 150 : (compactMode ? 165 : 185);
        const horizontalPadding = isMobile ? 18 : (compactMode ? 28 : 40);
        const topMargin = isMobile ? 78 : 96;
        const bottomMargin = isMobile ? 120 : 138;
        const allowHorizontalScroll = isMobile;

        container.innerHTML = '';
        container.style.display = 'block';
        container.style.alignItems = 'stretch';
        container.style.justifyContent = 'flex-start';
        container.style.overflow = 'hidden';
        container.style.padding = '0';

        const scroller = document.createElement('div');
        scroller.className = 'network-tree-scroller';
        scroller.style.overflowX = allowHorizontalScroll ? 'auto' : 'hidden';
        scroller.style.overflowY = 'hidden';
        scroller.style.width = '100%';
        scroller.style.padding = isMobile ? '14px 10px 18px' : '20px 18px 22px';
        scroller.style.boxSizing = 'border-box';
        container.appendChild(scroller);

        const baseWidth = scroller.clientWidth || container.clientWidth || 320;
        const innerWidth = allowHorizontalScroll
            ? Math.max(baseWidth - 6, leafCount * 120)
            : Math.max(baseWidth - horizontalPadding * 2, 280);
        const innerHeight = Math.max((depthCount - 1) * verticalGap, isMobile ? 220 : 270);

        const treeLayout = d3.tree().size([innerWidth, innerHeight]);
        treeLayout(root);

        const W = allowHorizontalScroll
            ? innerWidth + horizontalPadding * 2
            : baseWidth;
        const H = innerHeight + topMargin + bottomMargin;
        const offsetX = horizontalPadding;
        const offsetY = topMargin;

        const svg = d3.select(scroller)
            .append('svg')
            .attr('width', W)
            .attr('height', H)
            .attr('viewBox', `0 0 ${W} ${H}`)
            .style('width', allowHorizontalScroll ? `${W}px` : '100%')
            .style('min-width', allowHorizontalScroll ? `${W}px` : '100%')
            .style('display', 'block')
            .style('overflow', 'visible');

        const g = svg.append('g').attr('transform', `translate(${offsetX}, ${offsetY})`);

        // Degradado de líneas
        // IMPORTANTE: usar gradientUnits="userSpaceOnUse" con coordenadas absolutas.
        // El default "objectBoundingBox" hace invisible las líneas verticales porque
        // su bounding box tiene ancho=0, lo que degenera el gradiente a transparente.
        const defs = svg.append('defs');
        const gradId = 'linkGrad_' + Date.now(); // ID único para evitar conflictos entre renders
        const grad = defs.append('linearGradient')
            .attr('id', gradId)
            .attr('gradientUnits', 'userSpaceOnUse')
            .attr('x1', 0).attr('y1', 0)
            .attr('x2', 0).attr('y2', H); // de arriba (púrpura) hacia abajo (cyan)
        grad.append('stop').attr('offset','0%').attr('stop-color','#9d00ff').attr('stop-opacity', 0.9);
        grad.append('stop').attr('offset','100%').attr('stop-color','#00d2ff').attr('stop-opacity', 0.9);

        // Links (líneas)
        const nodeRadius = (nodeData) => nodeData.data.es_raiz ? rootRadius : childRadius;
        const linkSegments = [];

        root.descendants().forEach(parent => {
            const children = parent.children || [];
            if (!children.length) return;

            const parentBottomY = parent.y + nodeRadius(parent) + 2;
            const childTopYs = children.map(child => child.y - nodeRadius(child) - 8);

            if (children.length === 1) {
                linkSegments.push({
                    x1: parent.x,
                    y1: parentBottomY,
                    x2: children[0].x,
                    y2: childTopYs[0]
                });
                return;
            }

            let branchY = parentBottomY + (isMobile ? 16 : 20);
            const highestChildTop = Math.min(...childTopYs);

            if (branchY > highestChildTop - 18) {
                branchY = parentBottomY + Math.max(10, (highestChildTop - parentBottomY) * 0.35);
            }

            const childXs = children.map(child => child.x);

            linkSegments.push({
                x1: parent.x,
                y1: parentBottomY,
                x2: parent.x,
                y2: branchY
            });

            linkSegments.push({
                x1: Math.min(...childXs),
                y1: branchY,
                x2: Math.max(...childXs),
                y2: branchY
            });

            children.forEach((child, index) => {
                linkSegments.push({
                    x1: child.x,
                    y1: branchY,
                    x2: child.x,
                    y2: childTopYs[index]
                });
            });
        });

        g.selectAll('.link-segment')
            .data(linkSegments)
            .enter().append('path')
            .attr('class', 'link-segment')
            .attr('d', d => `M${d.x1},${d.y1} L${d.x2},${d.y2}`)
            .attr('fill', 'none')
            .attr('stroke', () => `url(#${gradId})`)
            .attr('stroke-width', isMobile ? 2.4 : 2.8)
            .attr('stroke-linecap', 'round')
            .attr('opacity', 0.96);

        // Nodos
        const node = g.selectAll('.node')
            .data(root.descendants())
            .enter().append('g')
            .attr('class', 'node')
            .attr('transform', d => `translate(${d.x},${d.y})`);

        // Color por tipo
        const getColor = (d) => {
            if (d.data.es_raiz)                      return '#9d00ff';
            if (d.data.tipo_usuario === 'clon')       return '#ff9800';
            if (d.data.pago_estado === 'completado')  return '#00e676';
            if (d.data.pago_estado === 'pendiente')   return '#ff5252';
            return '#00d2ff';
        };

        // Círculo con glow
        node.append('circle')
            .attr('r', 0)
            .attr('fill', d => getColor(d))
            .attr('stroke', '#0a0a12')
            .attr('stroke-width', 2)
            .style('filter', d => `drop-shadow(0 0 10px ${getColor(d)})`)
            .transition().duration(500).delay((d, i) => i * 120)
            .attr('r', d => nodeRadius(d));

        // Inicial del nickname dentro del círculo
        node.append('text')
            .attr('text-anchor', 'middle')
            .attr('dy', '0.35em')
            .attr('font-size', d => d.data.es_raiz ? (isMobile ? '8px' : '9px') : (isMobile ? '7px' : '8px'))
            .attr('font-weight', '800')
            .attr('fill', '#000')
            .text(d => getDisplayInitials(d.data));

        // Nickname debajo del nodo
        node.append('text')
            .attr('text-anchor', 'middle')
            .attr('dy', d => d.data.es_raiz ? '44px' : '36px')
            .attr('font-size', isMobile ? '8px' : '10px')
            .attr('fill', '#c7ccda')
            .text(d => {
                const nick = getDisplayName(d.data);
                const maxLen = isMobile ? 11 : 14;
                return nick.length > maxLen ? nick.substring(0, maxLen) + '…' : nick;
            });

        // Tablero badge encima del nodo raíz
        if (root.data.tablero_actual) {
            const phaseLabel = data.fase_nombre || root.data.fase_nombre || `Fase ${data.fase_numero ?? root.data.fase_numero ?? 0}`;
            const tableroLabel = (root.data.tablero_actual === 'FASE_COMPLETADA' || root.data.tablero_actual === 'FASE0_COMPLETADA')
                ? `${phaseLabel} completa`
                : `${phaseLabel} · Tablero ${root.data.tablero_actual}`;
            g.select('.node')
             .append('text')
             .attr('text-anchor', 'middle')
             .attr('dy', isMobile ? '-30px' : '-34px')
             .attr('font-size', isMobile ? '8px' : '9px')
             .attr('fill', '#9d00ff')
             .text(tableroLabel);
        }

        // Leyenda
        const leyenda = [
            { color: '#9d00ff', label: 'Tú' },
            { color: '#00e676', label: 'Pagó' },
            { color: '#ff5252', label: 'Pendiente' },
            { color: '#ff9800', label: 'Agente IA' },
            { color: '#00d2ff', label: 'Nuevo' },
        ];
        const legendItems = isMobile ? leyenda.slice(0, 4) : leyenda;
        const legendSpacing = isMobile ? 76 : 90;
        const legendWidth = Math.max((legendItems.length - 1) * legendSpacing + 72, 120);
        const legendX = Math.max((W - legendWidth) / 2, 12);
        const legG = svg.append('g').attr('transform', `translate(${legendX}, ${H - (isMobile ? 24 : 28)})`);
        legendItems.forEach((l, i) => {
            legG.append('circle').attr('cx', i * legendSpacing).attr('cy', 0).attr('r', isMobile ? 4.5 : 5).attr('fill', l.color);
            legG.append('text')
                .attr('x', i * legendSpacing + 10)
                .attr('y', 4)
                .attr('font-size', isMobile ? '8px' : '9px')
                .attr('fill', '#666')
                .text(l.label);
        });

    } catch(e) {
        container.innerHTML = `<p style="color:#444; font-size:0.8rem; text-align:center;">Error al cargar red.</p>`;
        console.error('NetworkTree error:', e);
    }
}

function copyRefLink() {
    const input = document.getElementById('ref-link-input');
    if (!input) return;
    input.select();
    input.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(input.value).then(() => {
        mostrarToast("🔗 Enlace copiado al portapapeles", "#00d2ff");
    }).catch(() => {
        // Fallback
        document.execCommand('copy');
        mostrarToast("🔗 Enlace copiado", "#00d2ff");
    });
}

// ─── HISTORIAL DE GANANCIAS Y RETENCIONES (Issue 18) ───────────────────────
// Diferencia ganancias de tablero de retenciones automáticas del sistema.
function renderHistorial() {
    const box = document.getElementById('historial-list');
    if (!box) return;
    if (!_historialData || _historialData.length === 0) {
        box.innerHTML = '<div style="color:#444; text-align:center; padding:10px;">Sin movimientos aún.</div>';
        return;
    }
    box.innerHTML = _historialData.map(m => {
        const esIngreso = m.direccion === 'ingreso';
        const color = esIngreso ? '#00e676' : '#ff7043';
        const signo = esIngreso ? '+' : '−';
        const rawLabel = m.tipo_label || m.tipo || '';
        // Agregar emojis en JS para evitar problemas de encoding en MySQL
        let label = rawLabel;
        if (rawLabel.startsWith('Ganancia Tablero'))      label = '✅ ' + rawLabel;
        else if (rawLabel === 'Reserva automatica Fase 1') label = '🔒 Reserva automática → Fase 1';
        else if (rawLabel === 'Reentrada ciclo siguiente') label = '🔄 Reentrada ciclo siguiente';
        const fecha  = (m.fecha || '').split(' ')[0];
        return `<div style="display:flex; justify-content:space-between; align-items:center; padding:8px 0; border-bottom:1px solid #1a1a28;">
            <div>
                <div style="color:#ddd; font-size:0.82rem; font-weight:600;">${label}</div>
                <div style="color:#444; font-size:0.72rem;">${fecha}</div>
            </div>
            <div style="color:${color}; font-weight:800; font-size:0.9rem;">${signo}$${parseFloat(m.monto).toFixed(2)}</div>
        </div>`;
    }).join('');
}

// ─── ONBOARDING MULTI-PASO (Issue 15) ──────────────────────────────────────
let _obStep = 1;
const OB_TOTAL = 3;

function obNavegar(dir) {
    const siguiente = _obStep + dir;
    if (siguiente > OB_TOTAL) {
        cerrarOnboarding();
        return;
    }
    if (siguiente < 1) return;

    const stepActual = document.getElementById(`ob-step-${_obStep}`);
    const dotActual  = document.getElementById(`ob-dot-${_obStep}`);
    if (stepActual) stepActual.style.display = 'none';
    if (dotActual)  dotActual.style.background = '#333';

    _obStep = siguiente;

    const stepNuevo = document.getElementById(`ob-step-${_obStep}`);
    const dotNuevo  = document.getElementById(`ob-dot-${_obStep}`);
    if (stepNuevo) stepNuevo.style.display = 'block';
    if (dotNuevo)  dotNuevo.style.background = 'var(--primary)';

    const btnBack = document.getElementById('ob-btn-back');
    const btnNext = document.getElementById('ob-btn-next');
    if (btnBack) btnBack.style.display = _obStep > 1 ? 'block' : 'none';
    if (btnNext) btnNext.innerText = _obStep === OB_TOTAL ? '¡Entendido! ✓' : 'Siguiente →';
}

function cerrarOnboarding() {
    const modal = document.getElementById('onboarding-modal');
    if (modal) modal.style.display = 'none';
    try { localStorage.setItem('radix_ob_done', '1'); } catch(e) {}
}

function mostrarOnboardingSiNuevo(userData) {
    // Mostrar onboarding si: el usuario no tiene ganancias y aún no lo vio
    let done = false;
    try { done = !!localStorage.getItem('radix_ob_done'); } catch(e) {}
    if (!done && (!userData.earnings || userData.earnings === 0)) {
        const modal = document.getElementById('onboarding-modal');
        if (modal) modal.style.display = 'flex';
    }
}

window.onload = loadDashboard;

// ─── POLLING DE EVENTOS EN TIEMPO REAL (check_events.php cada 30s) ──────────
// Muestra toasts dentro del dashboard cuando hay nuevos referidos,
// tableros completados o clones activados — sin necesidad de recargar la página.
(function iniciarPollingEventos() {
    async function verificarEventos() {
        try {
            const res = await fetch(`radix_api/check_events.php?since=${_lastEventTimestamp}`);
            if (!res.ok) return; // silencioso si el servidor falla (no romper nada)
            const data = await res.json();
            if (!data || !Array.isArray(data.eventos)) return;

            // Mostrar un toast por cada evento nuevo
            data.eventos.forEach(e => {
                if (e.mensaje) mostrarToast(e.mensaje, e.color || '#00d2ff');
            });

            // Actualizar el timestamp para el próximo ciclo
            if (data.timestamp) _lastEventTimestamp = data.timestamp;
        } catch (_) {
            // Red caída o sesión expirada — no hacer nada, el polling sigue corriendo
        }
    }

    // Esperar 35 segundos antes del primer check (el dashboard ya cargó los datos iniciales)
    setTimeout(() => {
        verificarEventos(); // primer check
        setInterval(verificarEventos, 30000); // cada 30s después
    }, 35000);
})();

// ─── TELEGRAM ───────────────────────────────────────────────────────────────

function actualizarEstadoTelegram(hastelegram) {
    const noVinc = document.getElementById('tg-no-vinculado');
    const vinc   = document.getElementById('tg-vinculado');
    if (!noVinc || !vinc) return;
    if (hastelegram) {
        noVinc.style.display = 'none';
        vinc.style.display   = 'block';
    } else {
        noVinc.style.display = 'block';
        vinc.style.display   = 'none';
    }
}

async function vincularTelegram() {
    const input    = document.getElementById('tg-chat-id-input');
    const statusEl = document.getElementById('tg-status');
    const chatId   = (input?.value || '').trim();

    if (!chatId) {
        statusEl.style.color = '#ff4444';
        statusEl.innerText   = '⚠️ Ingresa tu Chat ID de Telegram.';
        return;
    }

    statusEl.style.color = '#888';
    statusEl.innerText   = 'Vinculando...';

    try {
        const fd = new FormData();
        fd.append('chat_id', chatId);
        const res  = await fetch('radix_api/vincular_telegram.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.success) {
            actualizarEstadoTelegram(true);
            mostrarToast('✅ ¡Telegram vinculado! Revisa tu chat.', '#00e676');
        } else if (data.advertencia) {
            statusEl.style.color = '#ffaa00';
            statusEl.innerText   = '⚠️ ' + data.advertencia;
        } else {
            statusEl.style.color = '#ff4444';
            statusEl.innerText   = '❌ ' + (data.error || 'Error al vincular.');
        }
    } catch (e) {
        statusEl.style.color = '#ff4444';
        statusEl.innerText   = '❌ Error de conexión. Intenta de nuevo.';
    }
}

async function desvincularTelegram() {
    if (!confirm('¿Desvincular Telegram? Dejarás de recibir notificaciones.')) return;
    try {
        const fd = new FormData();
        fd.append('chat_id', '');
        fd.append('desvincular', '1');
        const res  = await fetch('radix_api/vincular_telegram.php', { method: 'POST', body: fd });
        const data = await res.json();
        actualizarEstadoTelegram(false);
        mostrarToast('Telegram desvinculado.', '#888');
    } catch(e) {
        mostrarToast('Error al desvincular.', '#ff4444');
    }
}
