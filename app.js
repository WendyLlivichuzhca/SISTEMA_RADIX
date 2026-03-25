/**
 * app.js - RADIX Phase 0 Connection & Auto-Registration Logic
 * Gestiona la conexión real de MetaMask/SafePal, el registro automático y el acceso.
 */

document.addEventListener('DOMContentLoaded', () => {
    const connectBtn = document.getElementById('connect-wallet');

    if (connectBtn) {
        connectBtn.addEventListener('click', async () => {
            connectBtn.innerText = "CONECTANDO...";
            connectBtn.disabled = true;

            // ─── 1. Detectar TronWeb (SafePal o TronLink) ──────
            if (typeof window.tronWeb === 'undefined' || window.tronWeb === null) {
                // Si existe tronLink pero no tronWeb, pedir conexión
                if (window.tronLink) {
                   await window.tronLink.request({ method: 'tron_requestAccounts' });
                } else {
                   document.getElementById('wallet-modal').style.display = 'flex';
                   connectBtn.innerText = "Conectar Billetera";
                   connectBtn.disabled = false;
                   return;
                }
            }

            try {
                // ─── 2. Obtener la cuenta de Tron ──────────────────────────
                // En TronWeb, la cuenta suele estar disponible tras la carga o pidiendo 'tron_requestAccounts'
                if (!window.tronWeb.defaultAddress.base58) {
                    await window.tronLink.request({ method: 'tron_requestAccounts' });
                }
                
                const walletAddress = window.tronWeb.defaultAddress.base58;

                if (!walletAddress) {
                    throw new Error("No se pudo obtener tu dirección de Tron. Desbloquea tu billetera.");
                }

                // ─── 3. Obtener nonce y FIRMAR obligatoriamente ─────────────
                const nonceRes  = await fetch(`radix_api/get_nonce.php?wallet=${walletAddress}`);
                const nonceData = await nonceRes.json();
                if (!nonceData.success) {
                    throw new Error("No se pudo obtener el desafío de verificación. Inténtalo de nuevo.");
                }

                let firma;
                try {
                    firma = await window.tronWeb.trx.sign(window.tronWeb.toHex(nonceData.mensaje));
                } catch(e) {
                    throw new Error("Debes firmar el mensaje de verificación en tu billetera para continuar.");
                }
                if (!firma) {
                    throw new Error("La firma fue rechazada. Aprueba la solicitud en tu billetera.");
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
                alert("Hubo un problema al conectar:\n" + error.message);
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
