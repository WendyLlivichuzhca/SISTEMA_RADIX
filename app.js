document.addEventListener('DOMContentLoaded', () => {
    // Smooth scroll for nav links
    document.querySelectorAll('nav a').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            const targetId = this.getAttribute('href');
            if (targetId.startsWith('#')) {
                document.querySelector(targetId).scrollIntoView({
                    behavior: 'smooth'
                });
            }
        });
    });
    // Handle Referral Link parameter (?ref=)
    const urlParams = new URLSearchParams(window.location.search);
    const ref = urlParams.get('ref');
    if (ref) {
        localStorage.setItem('radix_sponsor', ref);
        console.log('Sponsor detectado:', ref);
    }

    // Check if already connected
    const savedWallet = localStorage.getItem('radix_wallet');
    if (savedWallet) {
        window.location.href = 'dashboard.html';
    }

    // Helper to animate numbers
    function animateValue(obj, start, end, duration, suffix = '') {
        let startTimestamp = null;
        const step = (timestamp) => {
            if (!startTimestamp) startTimestamp = timestamp;
            const progress = Math.min((timestamp - startTimestamp) / duration, 1);
            obj.innerHTML = Math.floor(progress * (end - start) + start).toLocaleString() + suffix;
            if (progress < 1) {
                window.requestAnimationFrame(step);
            }
        };
        window.requestAnimationFrame(step);
    }

    // Animate stats from real API
    async function updateStats() {
        try {
            const statsResponse = await fetch('radix_api/stats.php');
            const data = await statsResponse.json();
            
            animateValue(document.getElementById('total-users'), 0, data.users || 0, 2000);
            animateValue(document.getElementById('total-rewards'), 0, data.gifts || 0, 2000, ' USDT');
            animateValue(document.getElementById('total-days'), 0, data.days || 1, 2000);
        } catch (error) {
            console.error('Error fetching stats:', error);
            // Fallback to defaults
            animateValue(document.getElementById('total-users'), 0, 0, 1000);
        }
    }

    const statsObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                updateStats();
                statsObserver.unobserve(entry.target);
            }
        });
    }, { threshold: 0.5 });

    const statsSection = document.getElementById('stats');
    if (statsSection) statsObserver.observe(statsSection);

    // Wallet connection with backend registration
    const connectBtn = document.getElementById('connect-wallet');
    connectBtn.addEventListener('click', async () => {
        if (connectBtn.classList.contains('connected')) return;
        
        try {
            // MetaMask check
            if (!window.ethereum) {
                // Si ya dice "Instalar", redirigir al clic
                if (connectBtn.textContent === 'Instalar MetaMask') {
                    window.open('https://metamask.io/download/', '_blank');
                }
                showToast('¡Por favor instala MetaMask para continuar!');
                connectBtn.textContent = 'Instalar MetaMask';
                connectBtn.style.pointerEvents = 'auto';
                return;
            }

            // 1. Obtener la cuenta real de MetaMask (si existe)
            const accounts = await window.ethereum.request({ method: 'eth_requestAccounts' });
            const realWallet = accounts[0];

            // 2. Solicitar firma (Seguridad)
            const message = `Bienvenido a RADIX. Firma este mensaje para entrar de forma segura.\n\nNonce: ${Math.floor(Math.random() * 1000000)}`;
            const signature = await window.ethereum.request({
                method: 'personal_sign',
                params: [message, realWallet],
            });

            if (!signature) {
                showToast('Firma cancelada');
                connectBtn.textContent = 'Conectar Billetera';
                connectBtn.style.pointerEvents = 'auto';
                return;
            }

            // 3. Registrar / Loguear en el backend
            const formData = new FormData();
            formData.append('wallet', realWallet);
            formData.append('nickname', 'User_' + realWallet.slice(2, 6).toUpperCase());
            formData.append('signature', signature);
            formData.append('message', message);
            
            const sponsor = localStorage.getItem('radix_sponsor');
            // Si el patrocinador es el mismo que el usuario, ignorarlo (Evita el error de auto-patrocinio)
            if (sponsor && sponsor.toLowerCase() !== realWallet.toLowerCase()) {
                formData.append('patrocinador', sponsor);
            }
            const response = await fetch('radix_api/registro.php', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();

            if (result.success) {
                localStorage.setItem('radix_wallet', realWallet);
                localStorage.setItem('radix_auth', 'true'); // Flag de autenticación activa
                
                connectBtn.classList.add('connected');
                connectBtn.innerHTML = `${realWallet.slice(0, 6)}...${realWallet.slice(-4)} <span class="badge">Conectado</span>`;
                connectBtn.style.background = 'linear-gradient(90deg, #00d2ff, #9d00ff)';
                
                showToast('¡Bienvenido! Entrando al panel...');
                
                setTimeout(() => {
                    window.location.href = 'dashboard.html';
                }, 1500);
            } else {
                showToast('Error: ' + result.error);
                connectBtn.textContent = 'Reintentar';
                connectBtn.style.pointerEvents = 'auto';
            }
        } catch (error) {
            showToast('Error de conexión con el servidor');
            connectBtn.textContent = 'Conectar Billetera';
            connectBtn.style.pointerEvents = 'auto';
        }
    });

    function showToast(message) {
        const toast = document.createElement('div');
        toast.className = 'toast';
        toast.textContent = message;
        document.body.appendChild(toast);
        setTimeout(() => toast.classList.add('show'), 100);
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 500);
        }, 3000);
    }

    // Intersection Observer for fade-in animations
    const observerOptions = {
        threshold: 0.1
    };

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('visible');
            }
        });
    }, observerOptions);

    document.querySelectorAll('.step, .benefit-card, .timeline-item').forEach(el => {
        el.classList.add('reveal-on-scroll');
        observer.observe(el);
    });
});
