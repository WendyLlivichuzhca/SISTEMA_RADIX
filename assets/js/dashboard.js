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
let _masterUserList     = [];
let _masterRetirosList  = [];
let _masterAuditoria    = [];
let _chartInstance      = null;
let _lastEventTimestamp = Math.floor(Date.now() / 1000);
let _profileSnapshot    = null;

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

function setProfileStatus(targetId, message = '', color = '#7f86a7') {
    const el = document.getElementById(targetId);
    if (!el) return;
    el.textContent = message;
    el.style.color = color;
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

    if (welcomeEl && displayName) welcomeEl.textContent = `Hola, ${displayName}`;
    if (avatarCircle) avatarCircle.textContent = initials;
    if (sidebarNick && displayName) sidebarNick.textContent = displayName;
    if (sidebarAvatar) sidebarAvatar.textContent = initials.charAt(0);
    if (summaryAvatar) summaryAvatar.textContent = initials;
    if (summaryName && displayName) summaryName.textContent = displayName;
    if (summaryNick) summaryNick.textContent = nickname ? `@${nickname}` : 'Sin nick';
    if (summaryWallet && wallet) summaryWallet.textContent = wallet;
    if (wallet) updateSidebarWallet(wallet);
}

function fillProfileForm(profile) {
    if (!profile) return;

    const nicknameInput = document.getElementById('profile-nickname');
    const walletInput = document.getElementById('profile-wallet');
    const nombreInput = document.getElementById('profile-nombre');
    const telefonoInput = document.getElementById('profile-telefono');
    const correoInput = document.getElementById('profile-correo');
    const passwordBadge = document.getElementById('profile-password-badge');
    const currentPasswordInput = document.getElementById('profile-current-password');

    if (nicknameInput) nicknameInput.value = profile.nickname || '';
    if (walletInput) walletInput.value = profile.wallet || '';
    if (nombreInput) nombreInput.value = profile.nombre_completo || '';
    if (telefonoInput) telefonoInput.value = profile.telefono || '';
    if (correoInput) correoInput.value = profile.correo_electronico || '';

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

    if (!nombreInput || !telefonoInput || !correoInput) return;

    const nombre = nombreInput.value.trim();
    const telefono = telefonoInput.value.trim();
    const correo = correoInput.value.trim();

    if (!nombre || !telefono || !correo) {
        setProfileStatus('profile-status', 'Completa nombre, teléfono y correo.', '#ff5252');
        return;
    }

    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(correo)) {
        setProfileStatus('profile-status', 'El correo electrónico no es válido.', '#ff5252');
        return;
    }

    setProfileStatus('profile-status', 'Guardando cambios...', '#7f86a7');

    try {
        const formData = new FormData();
        formData.append('nombre_completo', nombre);
        formData.append('telefono', telefono);
        formData.append('correo_electronico', correo);

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
            loadMasterAdvancedData();
        } else {
            // USER MODE
            _saldoActual      = data.earnings || 0;
            _fase0Completada  = data.fase0_completada || false;
            _historialData    = data.historial || [];
            animateValue(document.getElementById('val-balance'),      _saldoActual,                    '$', '', true);
            animateValue(document.getElementById('val-clones'),       data.user.clones_count || 0,     '',  '', false);
            // Tablero label is text — set directly
            if (document.getElementById('val-fase')) {
                const fase0Completa = data.user.nivel === 'FASE0_COMPLETADA';
                const tableroTxt = fase0Completa
                    ? `C${data.user.ciclo} · Fase 0`
                    : `C${data.user.ciclo} · ${data.tablero?.tipo || data.user.nivel}`;
                document.getElementById('val-fase').innerText = tableroTxt;
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
                const fase0Completa = data.user.nivel === 'FASE0_COMPLETADA';
                const nivelMap = {'A': 0, 'B': 1, 'C': 2, 'FASE0_COMPLETADA': 3};
                const nivelIdx = nivelMap[data.user.nivel] ?? 0;
                const pctMap   = {'A': '0%', 'B': '50%', 'C': '100%', 'FASE0_COMPLETADA': '100%'};
                fill.style.width = pctMap[data.user.nivel] || '0%';
                ['node-a','node-b','node-c'].forEach((id, i) => {
                    const n = document.getElementById(id);
                    if (!n) return;
                    if (fase0Completa || i < nivelIdx) n.className = 'phase-node completed';
                    else if (i === nivelIdx)           n.className = 'phase-node current';
                    else                               n.className = 'phase-node';
                });
            }
            renderUserTeam(data.referidos, data.equipo_ciclo, data.reservas, data.tablero);
            renderHistorial();
            renderNetworkTree();
            mostrarOnboardingSiNuevo(data);
            actualizarEstadoTelegram(data.user.has_telegram || false);
            await loadProfilePanel();
        }

        // Common components
        if (data.user.pago_pendiente) mostrarPagoPendiente(data.pago_pendiente);

    } catch (e) { console.error(e); }
    finally { if(document.getElementById('loading-overlay')) document.getElementById('loading-overlay').style.display='none'; }
}

async function loadMasterAdvancedData() {
    try {
        const res = await fetch('radix_api/admin_global_stats.php');
        const data = await res.json();
        if (!data.success) return;

        animateValue(document.getElementById('val-master-earnings'),    data.master_id1_earnings || 0,   '$', '', true);
        animateValue(document.getElementById('val-total-blockchain'),  data.total_blockchain || 0,      '$', '', true);
        animateValue(document.getElementById('val-pendiente-dist'),    data.pendiente_distribuir || 0,  '$', '', true);
        animateValue(document.getElementById('val-usuarios-reales'),   data.usuarios?.reales || 0,      '',  '', false);
        animateValue(document.getElementById('val-balance'),           data.tesoreria || 0,             '$', '', true);
        animateValue(document.getElementById('val-fase'),              data.fase1_pool || 0,            '$', '', true);
        
        renderMasterCharts(data.crecimiento_diario || []);
        
        // Distribution Bars
        const d = data.distribucion_tableros || {A:0,B:0,C:0};
        const max = Math.max(d.A, d.B, d.C, 1);
        ['a','b','c'].forEach(t => {
            const l = document.getElementById(`dist-${t}-val`);
            const b = document.getElementById(`dist-${t}-bar`);
            if(l) l.innerText = d[t.toUpperCase()];
            if(b) b.style.width = (d[t.toUpperCase()]/max*100)+'%';
        });

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

        // C. Actividad Reciente del Sistema (todos los tipos de acción, no solo clones)
        const activityBody = document.getElementById('master-activity-body');
        if (activityBody) {
            const logs = (data.logs_actividad || []).slice(0, 8);
            const accionColor = (a) => {
                if (!a) return '#888';
                if (a.includes('CLON')) return '#9d00ff';
                if (a.includes('AVANCE') || a.includes('CICLO')) return '#00e676';
                if (a.includes('REGISTRO')) return '#00d2ff';
                if (a.includes('RETIRO')) return '#ffb300';
                return '#aaa';
            };
            activityBody.innerHTML = logs.map(l => `
                <tr>
                    <td style="padding:8px 10px;">
                        <span style="color:${accionColor(l.accion)}; font-weight:700; font-size:0.78rem;">${l.accion || '—'}</span>
                        ${l.nickname ? `<span style="color:#555; font-size:0.7rem; margin-left:6px;">(${l.nickname})</span>` : ''}
                    </td>
                    <td style="color:#666; font-size:0.78rem; padding:8px 10px;">${(l.detalles || '').substring(0, 60)}${l.detalles && l.detalles.length > 60 ? '…' : ''}</td>
                    <td style="color:#444; font-size:0.72rem; padding:8px 10px; white-space:nowrap;">${(l.fecha || '').split(' ')[0]}</td>
                </tr>`).join('') || '<tr><td colspan="3" style="color:#444; padding:15px; text-align:center;">Sin actividad registrada</td></tr>';
        }

        _masterUserList     = data.lista_usuarios || [];
        _masterRetirosList  = data.retiros_pendientes || [];
        _masterAuditoria    = data.logs_actividad || [];

    } catch (e) { 
        console.error("Error cargando datos master:", e); 
    }
}

function abrirRetiro() {
    if (!_fase0Completada) {
        mostrarToast("⏳ Debes completar la Fase 0 (Tableros A → B → C) para poder retirar.", "#ffb300");
        return;
    }
    if (_saldoActual < 10) {
        mostrarToast("Saldo insuficiente (mínimo $10.00)", "#ff5252");
        return;
    }
    // Mostrar saldo disponible en el modal
    const saldoEl = document.getElementById('retiro-saldo');
    if (saldoEl) saldoEl.innerText = `$${_saldoActual.toFixed(2)} USDT`;
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
    const btn = document.getElementById('btn-solicitar-retiro');
    const statusEl = document.getElementById('retiro-status');
    if (btn) btn.disabled = true;
    if (statusEl) statusEl.innerText = '⏳ Procesando...';
    try {
        const res = await fetch('radix_api/solicitar_retiro.php', { method: 'POST' });
        const data = await res.json();
        if (data.success) {
            mostrarToast("✅ Solicitud enviada. Procesada en < 24h.", "#00e676");
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
    const nav = document.getElementById(`nav-${tabName}`);
    const sec = document.getElementById(`section-${tabName}`);
    if (nav) nav.classList.add('active');
    if (sec) sec.classList.add('active');

    if (tabName === 'dashboard') loadMasterAdvancedData();
    if (tabName === 'usuarios')  renderMasterUsers();
    if (tabName === 'retiros')   renderMasterRetirosFull();
    if (tabName === 'clones')    renderMasterClonesFull();
    if (tabName === 'auditoria') renderMasterAuditoriaFull();
}

function renderMasterUsers() {
    const body = document.getElementById('master-users-body');
    if (!body) return;
    const paymentBadge = (estado) => {
        if (estado === 'completado') {
            return '<span style="color:#00e676; font-weight:800;">✓ Pagado</span>';
        }
        if (estado === 'pendiente') {
            return '<span style="color:#ffb300; font-weight:800;">⏳ Pendiente</span>';
        }
        return '<span style="color:#666;">Sin registro</span>';
    };

    body.innerHTML = _masterUserList.map(u => `
        <tr>
            <td>#${u.id}</td>
            <td style="color:#fff; font-weight:700;">${u.nombre_completo || 'Sin dato'}</td>
            <td style="color:#ddd; font-weight:700;">${u.nickname || 'Sin dato'}</td>
            <td style="color:#bbb;">${u.telefono || 'Sin dato'}</td>
            <td style="color:#bbb;">${u.correo_electronico || 'Sin dato'}</td>
            <td>${paymentBadge(u.pago_estado)}</td>
            <td style="font-family:monospace; color:#888;">${u.wallet_address}</td>
        </tr>
    `).join('');
}

function renderMasterRetirosFull() {
    const box = document.getElementById('master-retiros-full-list');
    if (!box) return;
    box.innerHTML = _masterRetirosList.map(r => `<div style="background:rgba(255,255,255,0.02); padding:15px; border-radius:10px; margin-bottom:10px; display:flex; justify-content:space-between;"><div><strong>${r.nickname}</strong><br><small>${r.wallet_destino}</small></div><div style="color:var(--accent);">$${parseFloat(r.monto).toFixed(2)}</div></div>`).join('') || 'Sin retiros.';
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

function renderMasterAuditoriaFull() {
    const body = document.getElementById('master-auditoria-full-body');
    if (!body) return;
    body.innerHTML = _masterAuditoria.map(l => `<tr><td>${l.detalles}</td><td>${l.fecha}</td></tr>`).join('');
}

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

function renderUserTeam(refs, equipoCiclo = null, reservas = null, tablero = null) {
    const box = document.getElementById('team-list');
    if (!box) return;
    const resumen = `
        <div style="background:rgba(255,255,255,0.02); border:1px solid #1a1a28; border-radius:10px; padding:10px 12px; margin-bottom:12px;">
            <div style="display:flex; justify-content:space-between; gap:10px; font-size:0.72rem; color:#777; flex-wrap:wrap;">
                <span>Ciclo ${equipoCiclo?.ciclo ?? tablero?.ciclo ?? 1}</span>
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
    const res = await fetch('radix_api/admin_activar_clon.php', { method: 'POST' });
    const data = await res.json();
    mostrarToast(data.success ? '🤖 Agente Inyectado' : '❌ Error');
    if (data.success) setTimeout(() => location.reload(), 1500);
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

async function renderNetworkTree() {
    const container = document.getElementById('network-tree');
    if (!container) return;

    container.innerHTML = `<p style="color:#444; font-size:0.8rem;">Cargando red...</p>`;

    try {
        const res  = await fetch('radix_api/network_tree.php');
        const data = await res.json();
        if (!data.success || !data.arbol) {
            container.innerHTML = `<p style="color:#444; font-size:0.8rem; text-align:center;">Sin datos de red aún.</p>`;
            return;
        }

        // Convertir árbol plano a jerarquía D3
        const root = d3.hierarchy(data.arbol, d => d.hijos && d.hijos.length ? d.hijos : null);
        const isMobile = window.innerWidth <= 768;
        const leafCount = Math.max(root.leaves().length, 1);
        const branchCount = Math.max(root.children ? root.children.length : 0, leafCount, 1);
        const depthCount = Math.max(root.height + 1, 1);
        const rootRadius = isMobile ? 20 : 24;
        const childRadius = isMobile ? 15 : 18;
        const verticalGap = isMobile ? 120 : 150;
        const horizontalPadding = isMobile ? 26 : 40;

        container.innerHTML = '';
        container.style.overflowX = 'auto';
        container.style.padding   = isMobile ? '14px 8px' : '24px 18px';

        const baseWidth = container.clientWidth || container.offsetWidth || 320;
        const W = isMobile
            ? Math.max(baseWidth - 12, Math.min(branchCount * 150, 620))
            : Math.max(baseWidth, branchCount * 220, 760);
        const H = Math.max(isMobile ? 280 : 360, depthCount * verticalGap + (isMobile ? 120 : 150));

        const treeLayout = d3.tree().size([
            Math.max(W - horizontalPadding * 2, 240),
            Math.max(H - 140, 170)
        ]);
        treeLayout(root);
        root.each(d => {
            d.y = d.depth * verticalGap;
        });

        const svg = d3.select(container)
            .append('svg')
            .attr('width', W)
            .attr('height', H)
            .attr('viewBox', `0 0 ${W} ${H}`)
            .style('width', `${W}px`)
            .style('min-width', `${W}px`)
            .style('overflow', 'visible');

        const g = svg.append('g').attr('transform', `translate(${horizontalPadding}, 48)`);

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
            const tableroLabel = root.data.tablero_actual === 'FASE0_COMPLETADA'
                ? '✅ Fase 0 Completa'
                : `Tablero ${root.data.tablero_actual}`;
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
        const legG = svg.append('g').attr('transform', `translate(${legendX}, ${H - 24})`);
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
        const label = m.tipo_label || m.tipo || m.tipo;
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
