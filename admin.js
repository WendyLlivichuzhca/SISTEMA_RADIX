document.addEventListener('DOMContentLoaded', () => {
    // Check Auth
    const admin = sessionStorage.getItem('radix_admin');
    if (!admin) {
        window.location.href = 'admin_login.html';
        return;
    }

    document.getElementById('admin-name').textContent = admin;

    // Tabs Logic
    document.querySelectorAll('.admin-nav a[data-tab]').forEach(tabBtn => {
        tabBtn.addEventListener('click', (e) => {
            e.preventDefault();
            const target = tabBtn.getAttribute('data-tab');
            
            document.querySelectorAll('.tab-content').forEach(tc => tc.classList.remove('active'));
            document.querySelectorAll('.admin-nav a').forEach(btn => btn.classList.remove('active'));
            
            document.getElementById(`${target}-tab`).classList.add('active');
            tabBtn.classList.add('active');
            
            loadTabData(target);
        });
    });

    // Logout
    document.getElementById('logout-btn').addEventListener('click', () => {
        sessionStorage.removeItem('radix_admin');
        window.location.href = 'admin_login.html';
    });

    // Load Data
    async function loadTabData(tab) {
        try {
            const response = await fetch(`radix_api/admin_data.php?tab=${tab}`);
            const data = await response.json();

            if (tab === 'stats') {
                document.getElementById('stat-total-users').textContent = data.stats.total_users;
                document.getElementById('stat-total-gifts').textContent = `${data.stats.total_gifts} USDT`;
                
                renderTable('recent-users-table', data.recent_users);
            } else if (tab === 'users') {
                renderTable('all-users-table', data.users, true);
            } else if (tab === 'config') {
                document.getElementById('cfg-monto').value = data.config.monto_regalo;
                document.getElementById('cfg-fee').value = data.config.fee_sistema;
            }
        } catch (err) {
            console.error('Error fetching admin data:', err);
        }
    }

    function renderTable(tableId, data, isFull = false) {
        const tbody = document.querySelector(`#${tableId} tbody`);
        tbody.innerHTML = '';
        
        data.forEach(row => {
            const tr = document.createElement('tr');
            if (isFull) {
                tr.innerHTML = `
                    <td>${row.id}</td>
                    <td title="${row.wallet_address}">${row.wallet_address.slice(0, 10)}...</td>
                    <td>${row.nivel || 'A'}</td>
                    <td>${row.patrocinador_id || '-'}</td>
                    <td><span class="badge-${row.estado}">${row.estado}</span></td>
                    <td><button class="btn-action">Ver</button></td>
                `;
            } else {
                tr.innerHTML = `
                    <td>${row.wallet_address.slice(0, 15)}...</td>
                    <td>${row.nickname}</td>
                    <td>${row.fecha_registro}</td>
                    <td>${row.ip_address || '---'}</td>
                `;
            }
            tbody.appendChild(tr);
        });
    }

    // Initial load
    loadTabData('stats');

    // Config form
    document.getElementById('config-form')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const formData = new FormData(e.target);
        
        try {
            const response = await fetch('radix_api/update_config.php', {
                method: 'POST',
                body: formData
            });
            const res = await response.json();
            if (res.success) alert('Configuración actualizada');
        } catch (err) {
            alert('Error al guardar');
        }
    });
});
