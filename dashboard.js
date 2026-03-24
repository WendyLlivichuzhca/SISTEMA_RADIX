document.addEventListener('DOMContentLoaded', () => {
    // Basic protection: if no wallet or not authenticated, go home
    const wallet = localStorage.getItem('radix_wallet');
    const isAuth = localStorage.getItem('radix_auth');
    
    if (!wallet || isAuth !== 'true') {
        window.location.href = 'index.html';
        return;
    }

    document.getElementById('wallet-display').textContent = `${wallet.slice(0, 6)}...${wallet.slice(-4)}`;

    // Fetch user data from backend
    async function loadDashboardData() {
        try {
            const response = await fetch(`radix_api/user_data.php?wallet=${wallet}`);
            const data = await response.json();

            if (data.success) {
                // Update basic info
                document.getElementById('user-nickname').textContent = data.user.nickname;
                document.getElementById('total-earnings').innerHTML = `${data.earnings.toFixed(2)} <span>USDT</span>`;
                document.getElementById('ref-link').value = data.referral_link;
                
                const badge = document.getElementById('current-badge');
                const cicloText = data.user.ciclo > 1 ? ` - Ciclo ${data.user.ciclo}` : '';
                badge.textContent = `Tablero ${data.user.nivel}${cicloText}`;
                
                // Update avatar with first letter
                document.getElementById('user-avatar').textContent = data.user.nickname.charAt(0).toUpperCase();

                // Update Matrix Slots
                const referidos = data.referidos || [];
                referidos.forEach(ref => {
                    const slot = document.getElementById(`slot-${ref.posicion}`);
                    if (slot) {
                        slot.classList.add('filled');
                        if (ref.tipo === 'clon') slot.classList.add('is-clon');
                        slot.querySelector('.slot-name').textContent = ref.tipo === 'clon' ? '🤖 RADIX CLON' : ref.nickname;
                    }
                });

                // Update Progress Bar
                const count = referidos.length;
                const progress = (count / 3) * 100;
                document.getElementById('progress-fill').style.width = `${progress}%`;

            } else {
                alert('Error al cargar datos: ' + data.error);
                localStorage.removeItem('radix_wallet');
                window.location.href = 'index.html';
            }
        } catch (error) {
            console.error('Error dashboard:', error);
        }
    }

    loadDashboardData();

    // Copy ref link
    document.getElementById('copy-btn').addEventListener('click', () => {
        const link = document.getElementById('ref-link');
        link.select();
        document.execCommand('copy');
        
        const btn = document.getElementById('copy-btn');
        const oldText = btn.textContent;
        btn.textContent = '¡Copiado!';
        btn.style.background = '#00ff88';
        
        setTimeout(() => {
            btn.textContent = oldText;
            btn.style.background = '';
        }, 2000);
    });

    // Logout
    document.getElementById('logout-btn').addEventListener('click', () => {
        localStorage.removeItem('radix_wallet');
        window.location.href = 'index.html';
    });
});
