/**
 * app.js - RADIX Phase 0 Connection & Auto-Registration Logic
 * Gestiona la conexión real de MetaMask/SafePal, el registro automático y el acceso.
 */

document.addEventListener('DOMContentLoaded', () => {
    const connectBtn = document.getElementById('connect-wallet');

    if (connectBtn && !connectBtn.dataset.listenerAttached) {
        connectBtn.dataset.listenerAttached = 'true';
        connectBtn.addEventListener('click', async () => {
            connectBtn.innerText = "CONECTANDO...";
            connectBtn.disabled = true;

            // ─── 1. Detectar TronWeb (SafePal o TronLink) ──────
            if (typeof window.tronWeb === 'undefined' || window.tronWeb === null) {
                // Si existe tronLink pero no tronWeb, pedir conexión
                if (window.tronLink) {
                   await window.tronLink.request({ method: 'tron_requestAccounts' });
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
                            await window.tronLink.request({ method: 'tron_requestAccounts' });
                        } catch(reqErr) {
                            const msg = (reqErr?.message || '').toLowerCase();
                            if (msg.includes('at least one account') || msg.includes('no account')) {
                                throw new Error("Tu billetera SafePal no tiene cuentas. Abre SafePal, crea o importa una wallet y vuelve a intentarlo.");
                            }
                            throw new Error("SafePal no pudo conectarse. Desbloquea la extensión e intenta de nuevo.");
                        }
                        await new Promise(resolve => setTimeout(resolve, 800));
                    }
                }

                const walletAddress = window.tronWeb.defaultAddress?.base58;

                if (!walletAddress) {
                    throw new Error("No se encontró ninguna cuenta. Abre SafePal, crea una wallet Tron e intenta de nuevo.");
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
                const formData = new FormData();
                formData.append('wallet',    walletAddress);
                formData.append('nickname',  "TRON_" + walletAddress.substring(0, 4));
                formData.append('signature', firma);
                formData.append('message',   nonceData.mensaje);

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
    if (typeof window.tronWeb !== 'undefined') {
        // En TronWeb el evento es distinto o se monitorea por polling
        setInterval(() => {
            if (window.tronWeb.defaultAddress.base58 === false) {
                 // Desconectado
            }
        }, 5000);
    }

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
        if (data.nickname) {
            nickEl.innerText = data.nickname;
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
