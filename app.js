/**
 * app.js - RADIX Phase 0 Connection & Auto-Registration Logic
 * Gestiona la conexión real de MetaMask/SafePal, el registro automático y el acceso.
 */

const WALLET_SETUP_MODAL_KEY = 'radix_wallet_setup_modal_dismissed';
const PASSWORD_MIN_LENGTH = 8;

function openWalletSetupModal() {
    if (localStorage.getItem(WALLET_SETUP_MODAL_KEY) === '1') return;
    const setupModal = document.getElementById('wallet-setup-modal');
    if (setupModal) {
        setupModal.style.cssText = 'display:flex;position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.88);z-index:99999;align-items:center;justify-content:center;padding:20px;box-sizing:border-box;';
    }
}

function closeWalletSetupModal(remember = true) {
    const setupModal = document.getElementById('wallet-setup-modal');
    if (setupModal) {
        setupModal.style.display = 'none';
    }
    if (remember) {
        localStorage.setItem(WALLET_SETUP_MODAL_KEY, '1');
    }
}

window.closeWalletSetupModal = closeWalletSetupModal;

function solicitarDatosContacto(walletAddress) {
    const storageKey = `radix_contact_${walletAddress}`;
    const saved = localStorage.getItem(storageKey);
    if (saved) {
        try {
            const parsed = JSON.parse(saved);
            if (parsed.nombre_completo && parsed.telefono && parsed.correo_electronico) {
                return parsed;
            }
        } catch (e) {}
    }

    const pedir = (label, validator = null) => {
        while (true) {
            const value = window.prompt(label);
            if (value === null) return null;
            const clean = value.trim();
            if (!clean) {
                mostrarToastLanding("❌ Este dato es obligatorio para completar tu registro.");
                continue;
            }
            if (validator && !validator(clean)) {
                mostrarToastLanding("❌ Revisa el formato e inténtalo de nuevo.");
                continue;
            }
            return clean;
        }
    };

    const nombre_completo = pedir("Ingresa tu nombre completo:");
    if (!nombre_completo) return null;

    const telefono = pedir("Ingresa tu número de teléfono:");
    if (!telefono) return null;

    const correo_electronico = pedir(
        "Ingresa tu correo electrónico:",
        (value) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)
    );
    if (!correo_electronico) return null;

    const contactData = { nombre_completo, telefono, correo_electronico };
    localStorage.setItem(storageKey, JSON.stringify(contactData));
    return contactData;
}

function solicitarDatosContactoModal(walletAddress) {
    const storageKey = `radix_contact_${walletAddress}`;
    const saved = localStorage.getItem(storageKey);
    let localContactData = null;
    if (saved) {
        try {
            const parsed = JSON.parse(saved);
            if (parsed.nombre_completo && parsed.telefono && parsed.correo_electronico) {
                localContactData = parsed;
            }
        } catch (e) {}
    }

    const modal = document.getElementById('contact-modal');
    const form = document.getElementById('contact-modal-form');
    const closeBtn = document.getElementById('contact-modal-close');
    const statusEl = document.getElementById('contact-modal-status');
    const nombreInput = document.getElementById('contact-nombre');
    const telefonoInput = document.getElementById('contact-telefono');
    const correoInput = document.getElementById('contact-correo');
    const passwordWrap = document.getElementById('contact-password-wrap');
    const passwordConfirmWrap = document.getElementById('contact-password-confirm-wrap');
    const passwordHint = document.getElementById('contact-password-hint');
    const passwordInput = document.getElementById('contact-password');
    const passwordConfirmInput = document.getElementById('contact-password-confirm');

    if (!modal || !form || !closeBtn || !statusEl || !nombreInput || !telefonoInput || !correoInput || !passwordWrap || !passwordConfirmWrap || !passwordHint || !passwordInput || !passwordConfirmInput) {
        mostrarToastLanding("âŒ Falta el formulario de contacto en la pÃ¡gina.");
        return Promise.resolve(null);
    }

    return new Promise((resolve) => {
        let requiresPassword = true;

        const setPasswordVisibility = (visible) => {
            passwordWrap.style.display = visible ? 'block' : 'none';
            passwordConfirmWrap.style.display = visible ? 'block' : 'none';
            passwordHint.style.display = visible ? 'block' : 'none';
            passwordInput.required = visible;
            passwordConfirmInput.required = visible;
            if (!visible) {
                passwordInput.value = '';
                passwordConfirmInput.value = '';
            }
        };

        const openModal = () => {
            nombreInput.value = localContactData?.nombre_completo || '';
            telefonoInput.value = localContactData?.telefono || '';
            correoInput.value = localContactData?.correo_electronico || '';
            statusEl.textContent = '';
            setPasswordVisibility(requiresPassword);
            modal.style.cssText = 'display:flex;position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.88);z-index:99999;align-items:center;justify-content:center;padding:20px;box-sizing:border-box;';
            if (requiresPassword && localContactData?.nombre_completo) {
                passwordInput.focus();
            } else {
                nombreInput.focus();
            }
        };

        fetch(`radix_api/check_contact_fields.php?wallet=${encodeURIComponent(walletAddress)}`)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    if (data.contact) {
                        localContactData = data.contact;
                        localStorage.setItem(storageKey, JSON.stringify(data.contact));
                    }
                    const supportsPasswordLogin = !!data.supports_password_login;
                    if (data.has_contact_data && (!supportsPasswordLogin || data.has_password) && data.contact) {
                        resolve(data.contact);
                        return;
                    }
                    requiresPassword = supportsPasswordLogin && !data.has_password;
                }
                openModal();
            })
            .catch(() => {
                openModal();
            });

        const cleanup = () => {
            modal.style.display = 'none';
            form.removeEventListener('submit', handleSubmit);
            closeBtn.removeEventListener('click', handleClose);
        };

        const handleClose = () => {
            cleanup();
            resolve(null);
        };

        const handleSubmit = (event) => {
            event.preventDefault();

            const nombre_completo = nombreInput.value.trim();
            const telefono = telefonoInput.value.trim();
            const correo_electronico = correoInput.value.trim();
            const password = passwordInput.value;
            const passwordConfirm = passwordConfirmInput.value;

            if (!nombre_completo || !telefono || !correo_electronico) {
                statusEl.textContent = 'Todos los campos son obligatorios.';
                return;
            }

            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(correo_electronico)) {
                statusEl.textContent = 'Revisa el formato del correo electrÃ³nico.';
                return;
            }

            if (requiresPassword) {
                if (password.length < PASSWORD_MIN_LENGTH) {
                    statusEl.textContent = 'Tu contraseÃ±a debe tener al menos 8 caracteres.';
                    return;
                }
                if (password !== passwordConfirm) {
                    statusEl.textContent = 'Las contraseÃ±as no coinciden.';
                    return;
                }
            }

            const contactData = { nombre_completo, telefono, correo_electronico };
            localStorage.setItem(storageKey, JSON.stringify(contactData));
            cleanup();
            resolve(requiresPassword ? { ...contactData, password } : contactData);
        };

        closeBtn.addEventListener('click', handleClose);
        form.addEventListener('submit', handleSubmit);
    });
}

function inicializarLoginUsuario() {
    const openBtn = document.getElementById('open-login-modal');
    const modal = document.getElementById('user-login-modal');
    const closeBtn = document.getElementById('user-login-close');
    const form = document.getElementById('user-login-form');
    const statusEl = document.getElementById('user-login-status');
    const loginInput = document.getElementById('user-login-identifier');
    const passwordInput = document.getElementById('user-login-password');

    if (!openBtn || !modal || !closeBtn || !form || !statusEl || !loginInput || !passwordInput) {
        return;
    }

    const openModal = () => {
        form.reset();
        statusEl.textContent = '';
        statusEl.style.color = '#888';
        modal.style.cssText = 'display:flex;position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.88);z-index:99999;align-items:center;justify-content:center;padding:20px;box-sizing:border-box;';
        loginInput.focus();
    };

    const closeModal = () => {
        modal.style.display = 'none';
        statusEl.textContent = '';
    };

    openBtn.addEventListener('click', openModal);
    closeBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeModal();
        }
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        statusEl.style.color = '#888';
        statusEl.textContent = 'Validando acceso...';

        const formData = new FormData();
        formData.append('login', loginInput.value.trim());
        formData.append('password', passwordInput.value);

        try {
            const res = await fetch('radix_api/user_login.php', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();

            if (!data.success) {
                statusEl.style.color = '#ff6b6b';
                statusEl.textContent = data.error || 'No se pudo iniciar sesiÃ³n.';
                return;
            }

            statusEl.style.color = '#00e676';
            statusEl.textContent = 'Acceso concedido. Redirigiendo...';
            setTimeout(() => {
                window.location.href = 'dashboard.php';
            }, 500);
        } catch (error) {
            statusEl.style.color = '#ff6b6b';
            statusEl.textContent = 'Error de conexiÃ³n con el servidor.';
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    const connectBtn = document.getElementById('connect-wallet');
    inicializarLoginUsuario();

    if (connectBtn && !connectBtn.dataset.listenerAttached) {
        connectBtn.dataset.listenerAttached = 'true';
        connectBtn.addEventListener('click', async () => {
            connectBtn.innerText = "CONECTANDO...";
            connectBtn.disabled = true;

            // ─── Helper: tronLink.request con timeout de 6 s ─────
            const tronRequestWithTimeout = (method = 'tron_requestAccounts', ms = 6000) =>
                Promise.race([
                    window.tronLink.request({ method }),
                    new Promise((_, reject) =>
                        setTimeout(() => reject(new Error('timeout')), ms)
                    )
                ]);

            // ─── 1. Detectar TronWeb (SafePal o TronLink) ──────
            if (typeof window.tronWeb === 'undefined' || window.tronWeb === null) {
                // Si existe tronLink pero no tronWeb, pedir conexión
                if (window.tronLink) {
                    try {
                        await tronRequestWithTimeout();
                        // Esperar brevemente a que tronWeb se inicialice
                        await new Promise(resolve => setTimeout(resolve, 800));
                    } catch (initErr) {
                        connectBtn.innerText = "Conectar Billetera";
                        connectBtn.disabled = false;
                        const msg = (initErr?.message || '').toLowerCase();
                        if (msg === 'timeout' || msg.includes('at least one account') || msg.includes('no account')) {
                            openWalletSetupModal();
                        } else {
                            mostrarToastLanding("❌ SafePal no respondió. Desbloquea la extensión e intenta de nuevo.");
                        }
                        return;
                    }
                } else {
                   const wModal = document.getElementById('wallet-modal');
                   wModal.style.cssText = 'display:flex; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.88); z-index:99999; align-items:center; justify-content:center; padding:20px; box-sizing:border-box;';
                   connectBtn.innerText = "Conectar Billetera";
                   connectBtn.disabled = false;
                   return;
                }
            }

            try {
                // ─── 2. Obtener la cuenta de Tron ──────────────────────────
                if (!window.tronWeb.defaultAddress || !window.tronWeb.defaultAddress.base58) {
                    if (window.tronLink) {
                        try {
                            await tronRequestWithTimeout();
                        } catch(reqErr) {
                            const msg = (reqErr?.message || '').toLowerCase();
                            connectBtn.innerText = "Conectar Billetera";
                            connectBtn.disabled = false;
                            if (msg === 'timeout' || msg.includes('at least one account') || msg.includes('no account')) {
                                openWalletSetupModal();
                                return;
                            }
                            throw new Error("SafePal no pudo conectarse. Desbloquea la extensión e intenta de nuevo.");
                        }
                        await new Promise(resolve => setTimeout(resolve, 800));
                    }
                }

                const walletAddress = window.tronWeb.defaultAddress?.base58;

                if (!walletAddress) {
                    connectBtn.innerText = "Conectar Billetera";
                    connectBtn.disabled = false;
                    openWalletSetupModal();
                    return;
                }

                // ─── 3. Obtener nonce y FIRMAR obligatoriamente ─────────────
                const nonceRes  = await fetch(`radix_api/get_nonce.php?wallet=${walletAddress}`);
                const nonceData = await nonceRes.json();
                if (!nonceData.success) {
                    throw new Error("No se pudo obtener el desafío de verificación. Inténtalo de nuevo.");
                }

                let firma;
                try {
                    // signMessageV2 es el método correcto para SafePal y TronLink modernos
                    // Muestra el popup de firma en la extensión/app
                    if (typeof window.tronWeb.trx.signMessageV2 === 'function') {
                        firma = await window.tronWeb.trx.signMessageV2(nonceData.mensaje);
                    } else {
                        // Fallback para versiones antiguas de TronLink
                        firma = await window.tronWeb.trx.sign(window.tronWeb.toHex(nonceData.mensaje));
                    }
                } catch(e) {
                    if (e && (e.message || '').toLowerCase().includes('cancel')) {
                        throw new Error("Cancelaste la firma. Vuelve a intentarlo y aprueba la solicitud en SafePal.");
                    }
                    throw new Error("Abre SafePal y aprueba el mensaje de verificación para continuar.");
                }
                if (!firma) {
                    throw new Error("La firma fue rechazada. Aprueba la solicitud en tu billetera SafePal.");
                }

                // ─── 4 & 5. Registro / Login unificado vía registro.php ───────
                // registro.php verifica la firma tanto para usuarios nuevos como
                // para usuarios existentes, garantizando prueba de ownership.
                const contactData = await solicitarDatosContactoModal(walletAddress);
                if (!contactData) {
                    connectBtn.innerText = "Conectar Billetera";
                    connectBtn.disabled = false;
                    return;
                }

                const formData = new FormData();
                formData.append('wallet',    walletAddress);
                formData.append('nickname',  "TRON_" + walletAddress.substring(0, 4));
                formData.append('signature', firma);
                formData.append('message',   nonceData.mensaje);
                formData.append('nombre_completo', contactData.nombre_completo);
                formData.append('telefono', contactData.telefono);
                formData.append('correo_electronico', contactData.correo_electronico);
                if (contactData.password) {
                    formData.append('password', contactData.password);
                }

                const urlParams = new URLSearchParams(window.location.search);
                const ref = urlParams.get('ref');
                if (ref) formData.append('patrocinador', ref);

                const regRes = await fetch('radix_api/registro.php', {
                    method: 'POST',
                    body: formData
                });
                const regData = await regRes.json();

                if (!regData.success) {
                    throw new Error(regData.error || "Error en la verificación de identidad.");
                }

                // ─── 6. Iniciar sesión segura ───
                const loginForm = new FormData();
                loginForm.append('wallet', walletAddress);

                const loginRes = await fetch('radix_api/session_login.php', {
                    method: 'POST',
                    body: loginForm
                });
                const loginData = await loginRes.json();

                if (!loginData.success) {
                    throw new Error(loginData.error || "Error de sesión desconocido.");
                }

                window.location.href = 'dashboard.php';

            } catch (error) {
                console.error("Error en el acceso:", error);
                // Mostrar toast en vez del alert feo del browser
                mostrarToastLanding("❌ " + (error.message || "Error al conectar. Intenta de nuevo."));
                connectBtn.innerText = "Conectar Billetera";
                connectBtn.disabled = false;
            }
        });
    }

    // ─── Detectar cambio de cuenta ────────────
    // TronLink/SafePal no expone eventos nativos de desconexión; se maneja
    // vía la sesión del servidor (session_logout.php) y la recarga de página.

    // Funcionalidad: Carga de estadísticas públicas en el Home
    async function loadHomeStats() {
        try {
            const res = await fetch('radix_api/public_stats.php');
            const data = await res.json();
            if (data.success) {
                if (document.getElementById('total-users')) document.getElementById('total-users').innerText = data.total_usuarios;
                if (document.getElementById('total-rewards')) document.getElementById('total-rewards').innerText = `$${Number(data.total_pagado).toFixed(2)} USDT`;
            }
        } catch (e) {
            console.log("Stats no disponibles aún.");
        }
    }

    loadHomeStats();

    // ─── Contador "Días en Funcionamiento" ────────────────────
    const launchDate = new Date('2026-04-01'); // ← ajusta al día real de lanzamiento
    const daysSinceLaunch = document.getElementById('total-days');
    if (daysSinceLaunch) {
        const today = new Date();
        const diff = Math.max(0, Math.floor((today - launchDate) / (1000 * 60 * 60 * 24)));
        daysSinceLaunch.innerText = diff;
    }

    // ─── Animación reveal-on-scroll para secciones ROI ────────
    const revealEls = document.querySelectorAll('.reveal-on-scroll');
    if (revealEls.length > 0) {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                    observer.unobserve(entry.target); // una sola vez
                }
            });
        }, { threshold: 0.15 });
        revealEls.forEach(el => observer.observe(el));
    }

    // ─── Botón "Saber más" → scroll suave ─────────────────────
    const saberMasBtn = document.querySelector('.secondary-btn');
    if (saberMasBtn) {
        saberMasBtn.addEventListener('click', () => {
            document.getElementById('how-it-works')?.scrollIntoView({ behavior: 'smooth' });
        });
    }
});

// ─── Banner de referido ─────────────────────────────────────
(async function mostrarBannerReferido() {
    const params  = new URLSearchParams(window.location.search);
    const refWallet = params.get('ref');
    if (!refWallet) return;

    const banner   = document.getElementById('ref-banner');
    const nickEl   = document.getElementById('ref-nickname');
    if (!banner || !nickEl) return;

    // Mostrar banner inmediatamente con la wallet truncada
    nickEl.innerText = refWallet.substring(0, 6) + '...' + refWallet.slice(-4);
    banner.style.display = 'block';

    // Ajustar el header para que no quede debajo del banner
    const header = document.querySelector('header');
    if (header) header.style.marginTop = '46px';

    // Intentar obtener el nickname real desde la API
    try {
        const res  = await fetch(`radix_api/public_stats.php?ref_wallet=${encodeURIComponent(refWallet)}`);
        const data = await res.json();
        if (data.display_name || data.nickname) {
            nickEl.innerText = data.display_name || data.nickname;
        }
    } catch(e) { /* silencioso — ya se muestra la wallet truncada */ }
})();

// ─── Toast de error/info para la landing page ──────────────
function mostrarToastLanding(msg, color = '#ff5252') {
    // Crear contenedor si no existe
    let c = document.getElementById('landing-toast-container');
    if (!c) {
        c = document.createElement('div');
        c.id = 'landing-toast-container';
        c.style.cssText = 'position:fixed;bottom:24px;left:50%;transform:translateX(-50%);z-index:99999;display:flex;flex-direction:column;align-items:center;gap:8px;pointer-events:none;';
        document.body.appendChild(c);
    }
    const t = document.createElement('div');
    t.style.cssText = `background:#111;color:#fff;padding:13px 22px;border-radius:12px;border-left:3px solid ${color};font-size:0.9rem;font-weight:600;box-shadow:0 8px 30px rgba(0,0,0,0.5);pointer-events:auto;max-width:380px;text-align:center;line-height:1.4;`;
    t.innerText = msg;
    c.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; t.style.transition = 'opacity 0.4s'; setTimeout(() => t.remove(), 400); }, 5000);
}
