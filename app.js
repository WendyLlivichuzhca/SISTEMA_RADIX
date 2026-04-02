/**
 * app.js - RADIX landing flow
 * Registro directo con wallet TRON valida y acceso por contrasena.
 */

const WALLET_SETUP_MODAL_KEY = 'radix_wallet_setup_modal_dismissed';
const REF_STORAGE_KEY = 'radix_ref_wallet';
const REGISTER_DRAFT_KEY = 'radix_register_draft';
const PASSWORD_MIN_LENGTH = 8;
const REGISTER_INPUT_DEFAULT_BORDER = '1px solid #2a2a3a';
const REGISTER_INPUT_ERROR_BORDER = '1px solid rgba(255,107,107,0.9)';

function isLikelyMobile() {
    return /Android|iPhone|iPad|iPod/i.test(navigator.userAgent || '');
}

function isTronWallet(value) {
    return /^T[a-zA-Z0-9]{33}$/.test((value || '').trim());
}

function getReferralWallet() {
    const urlParams = new URLSearchParams(window.location.search);
    const ref = (urlParams.get('ref') || sessionStorage.getItem(REF_STORAGE_KEY) || '').trim();
    return ref;
}

function saveRegisterDraft(data) {
    localStorage.setItem(REGISTER_DRAFT_KEY, JSON.stringify(data));
}

function loadRegisterDraft() {
    try {
        return JSON.parse(localStorage.getItem(REGISTER_DRAFT_KEY) || '{}');
    } catch (error) {
        return {};
    }
}

function clearRegisterDraft() {
    localStorage.removeItem(REGISTER_DRAFT_KEY);
}

function normalizeTelegramUsername(value) {
    return ((value || '').trim().replace(/\s+/g, '')).replace(/^@+/, '');
}

function formatTelegramUsername(value) {
    const normalized = normalizeTelegramUsername(value);
    return normalized ? `@${normalized}` : '';
}

function isTelegramUsername(value) {
    const normalized = normalizeTelegramUsername(value);
    return /^[A-Za-z0-9_]{5,32}$/.test(normalized);
}

function getRegisterFieldMap() {
    return {
        nombre_completo: {
            input: document.getElementById('contact-nombre'),
            error: document.getElementById('contact-nombre-error')
        },
        telefono: {
            input: document.getElementById('contact-telefono'),
            error: document.getElementById('contact-telefono-error')
        },
        correo_electronico: {
            input: document.getElementById('contact-correo'),
            error: document.getElementById('contact-correo-error')
        },
        telegram_username: {
            input: document.getElementById('contact-telegram'),
            error: document.getElementById('contact-telegram-error')
        },
        wallet: {
            input: document.getElementById('contact-wallet'),
            error: document.getElementById('contact-wallet-error')
        },
        password: {
            input: document.getElementById('contact-password'),
            error: document.getElementById('contact-password-error')
        },
        password_confirm: {
            input: document.getElementById('contact-password-confirm'),
            error: document.getElementById('contact-password-confirm-error')
        }
    };
}

function clearRegisterFieldError(field) {
    if (!field) return;
    if (field.error) {
        field.error.textContent = '';
    }
    if (field.input) {
        field.input.style.border = REGISTER_INPUT_DEFAULT_BORDER;
    }
}

function setRegisterFieldError(field, message) {
    if (!field) return;
    if (field.error) {
        field.error.textContent = message;
    }
    if (field.input) {
        field.input.style.border = REGISTER_INPUT_ERROR_BORDER;
    }
}

function clearRegisterFieldErrors(fields) {
    Object.values(fields).forEach((field) => clearRegisterFieldError(field));
}

function buildRegisterFormValues(inputs) {
    return {
        nombre_completo: inputs.nombreInput.value.trim(),
        telefono: inputs.telefonoInput.value.trim(),
        correo_electronico: inputs.correoInput.value.trim(),
        telegram_username: formatTelegramUsername(inputs.telegramInput ? inputs.telegramInput.value : ''),
        patrocinador: inputs.patrocinadorInput ? inputs.patrocinadorInput.value.trim() : '',
        wallet: inputs.walletInput.value.trim(),
        password: inputs.passwordInput.value
    };
}

function getRegisterValidationMessage(fieldKey, formValues, passwordConfirm) {
    if (fieldKey === 'nombre_completo') {
        if (!formValues.nombre_completo) return 'Ingresa tu nombre completo.';
        if (formValues.nombre_completo.length < 3) return 'Escribe al menos 3 caracteres.';
        return '';
    }

    if (fieldKey === 'telefono') {
        const digits = formValues.telefono.replace(/\D/g, '');
        if (!formValues.telefono) return 'Ingresa tu telefono.';
        if (digits.length < 7) return 'Ingresa un telefono valido.';
        return '';
    }

    if (fieldKey === 'correo_electronico') {
        if (!formValues.correo_electronico) return 'Ingresa tu correo electronico.';
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(formValues.correo_electronico)) {
            return 'Revisa el formato del correo electronico.';
        }
        return '';
    }

    if (fieldKey === 'telegram_username') {
        if (!formValues.telegram_username) return '';
        if (!isTelegramUsername(formValues.telegram_username)) {
            return 'Usa un usuario valido, por ejemplo @radix_user.';
        }
        return '';
    }

    if (fieldKey === 'wallet') {
        if (!formValues.wallet) return 'Ingresa tu wallet TRON.';
        if (!isTronWallet(formValues.wallet)) {
            return 'La wallet debe empezar con T y tener formato TRON valido.';
        }
        return '';
    }

    if (fieldKey === 'password') {
        if (!formValues.password) return 'Crea una contrasena para tu acceso.';
        if (formValues.password.length < PASSWORD_MIN_LENGTH) {
            return `Debe tener al menos ${PASSWORD_MIN_LENGTH} caracteres.`;
        }
        return '';
    }

    if (fieldKey === 'password_confirm') {
        if (!passwordConfirm) return 'Confirma tu contrasena.';
        if (formValues.password !== passwordConfirm) return 'Las contrasenas no coinciden.';
        return '';
    }

    return '';
}

function validateRegisterForm(fields, formValues, passwordConfirm) {
    const orderedKeys = [
        'nombre_completo',
        'telefono',
        'correo_electronico',
        'telegram_username',
        'wallet',
        'password',
        'password_confirm'
    ];

    let firstInvalidField = null;

    orderedKeys.forEach((fieldKey) => {
        const field = fields[fieldKey];
        const message = getRegisterValidationMessage(fieldKey, formValues, passwordConfirm);

        if (message) {
            setRegisterFieldError(field, message);
            if (!firstInvalidField) {
                firstInvalidField = field;
            }
            return;
        }

        clearRegisterFieldError(field);
    });

    if (firstInvalidField && firstInvalidField.input) {
        firstInvalidField.input.focus();
    }

    return !firstInvalidField;
}

function showModal(modal) {
    if (!modal) return;
    modal.style.cssText = 'display:flex;position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.88);z-index:99999;align-items:center;justify-content:center;padding:20px;box-sizing:border-box;';
}

function hideModal(modal) {
    if (!modal) return;
    modal.style.display = 'none';
}

function openWalletSetupModal() {
    if (localStorage.getItem(WALLET_SETUP_MODAL_KEY) === '1') return;
    const setupModal = document.getElementById('wallet-setup-modal');
    showModal(setupModal);
}

function closeWalletSetupModal(remember = true) {
    const setupModal = document.getElementById('wallet-setup-modal');
    hideModal(setupModal);
    if (remember) {
        localStorage.setItem(WALLET_SETUP_MODAL_KEY, '1');
    }
}

window.closeWalletSetupModal = closeWalletSetupModal;

function ensureWalletModalCopyUi() {
    const modal = document.getElementById('wallet-modal');
    if (!modal) return;

    const playLink = modal.querySelector('a[href*="play.google.com"]');
    const appsRow = playLink ? playLink.parentElement : null;
    if (!appsRow) return;

    let linkInput = document.getElementById('wallet-current-link');
    let copyBtn = document.getElementById('wallet-copy-link');

    if (!linkInput || !copyBtn) {
        const wrapper = document.createElement('div');
        wrapper.style.cssText = 'display:flex;flex-direction:column;gap:8px;margin-bottom:10px;';
        wrapper.innerHTML = `
            <input id="wallet-current-link" type="text" readonly style="width:100%;background:#0a0a12;border:1px solid #2a2a3a;color:#bbb;padding:12px 14px;border-radius:10px;font-size:0.78rem;outline:none;box-sizing:border-box;">
            <button id="wallet-copy-link" type="button" style="width:100%;padding:12px;border-radius:12px;background:rgba(157,0,255,0.16);border:1px solid rgba(157,0,255,0.32);color:#fff;font-size:0.86rem;font-weight:700;cursor:pointer;">
                Copiar enlace de esta pagina
            </button>
        `;
        appsRow.parentElement.insertBefore(wrapper, appsRow);
        linkInput = wrapper.querySelector('#wallet-current-link');
        copyBtn = wrapper.querySelector('#wallet-copy-link');
    }

    if (linkInput) {
        linkInput.value = window.location.href;
    }

    if (copyBtn && !copyBtn.dataset.bound) {
        copyBtn.dataset.bound = 'true';
        copyBtn.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(window.location.href);
                mostrarToastLanding('Enlace copiado. Abre SafePal > Explorar y pegalo ahi.', '#00e676');
            } catch (error) {
                mostrarToastLanding('No se pudo copiar automaticamente. Copialo manualmente.', '#ffb400');
            }
        });
    }

    const mobileInfo = appsRow.previousElementSibling?.querySelector?.('p');
    if (mobileInfo) {
        mobileInfo.innerHTML = 'Si ya tienes SafePal en tu celular, tambien puedes usarlo para abrir esta pagina. Aun asi, el registro ya no requiere firma: solo una wallet valida de la red TRON.';
    }
}

function openWalletModal() {
    ensureWalletModalCopyUi();
    const walletModal = document.getElementById('wallet-modal');
    showModal(walletModal);
}

function inicializarHeroSupport() {
    const shortcut = document.querySelector('.login-shortcut');
    const shortcutText = shortcut?.querySelector('span');
    const copyBtn = document.getElementById('copy-safe-link-hero');

    if (shortcut) {
        shortcut.style.display = 'flex';
        shortcut.style.alignItems = 'center';
        shortcut.style.gap = '0.55rem';
        shortcut.style.flexWrap = 'wrap';
        shortcut.style.marginTop = '1rem';
        shortcut.style.color = '#9aa1b4';
        shortcut.style.fontSize = '0.92rem';
    }

    if (shortcutText) {
        shortcutText.textContent = 'Usa una wallet TRON valida para registrarte. Ya no necesitas firmar desde SafePal o TronLink.';
    }

    if (copyBtn) {
        copyBtn.style.display = 'none';
        copyBtn.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(window.location.href);
                mostrarToastLanding('Enlace copiado. Pegalo en SafePal > Explorar.', '#00e676');
            } catch (error) {
                mostrarToastLanding('No se pudo copiar el enlace automaticamente.', '#ffb400');
            }
        });
    }
}

function fillRegisterForm() {
    const draft = loadRegisterDraft();
    const refWallet = getReferralWallet();

    const nombreInput = document.getElementById('contact-nombre');
    const telefonoInput = document.getElementById('contact-telefono');
    const correoInput = document.getElementById('contact-correo');
    const telegramInput = document.getElementById('contact-telegram');
    const patrocinadorInput = document.getElementById('contact-patrocinador');
    const walletInput = document.getElementById('contact-wallet');
    const passwordInput = document.getElementById('contact-password');
    const passwordConfirmInput = document.getElementById('contact-password-confirm');

    if (nombreInput) nombreInput.value = draft.nombre_completo || '';
    if (telefonoInput) telefonoInput.value = draft.telefono || '';
    if (correoInput) correoInput.value = draft.correo_electronico || '';
    if (telegramInput) telegramInput.value = draft.telegram_username || '';
    if (patrocinadorInput) patrocinadorInput.value = draft.patrocinador || refWallet || '';
    if (walletInput) walletInput.value = draft.wallet || '';
    if (passwordInput) passwordInput.value = draft.password || '';
    if (passwordConfirmInput) passwordConfirmInput.value = draft.password || '';
}

function clearRegisterStatus() {
    const statusEl = document.getElementById('contact-modal-status');
    if (!statusEl) return;
    statusEl.textContent = '';
    statusEl.style.color = '#ff6b6b';
}

function openRegisterModal() {
    fillRegisterForm();
    clearRegisterFieldErrors(getRegisterFieldMap());
    const walletHint = document.getElementById('contact-wallet-hint');
    const passwordHint = document.getElementById('contact-password-hint');
    const labelTexts = {
        'contact-telefono': 'Telefono',
        'contact-correo': 'Correo electronico',
        'contact-password': 'Contrasena de acceso',
        'contact-password-confirm': 'Confirmar contrasena'
    };

    Object.entries(labelTexts).forEach(([fieldId, text]) => {
        const label = document.querySelector(`label[for="${fieldId}"]`);
        if (label) {
            label.textContent = text;
        }
    });

    if (walletHint) {
        walletHint.textContent = 'Usa una direccion valida de la red TRON. El sistema revisa el formato antes de crear tu cuenta.';
    }
    if (passwordHint) {
        passwordHint.textContent = 'Esta contrasena te servira para volver a entrar con tu correo, telefono o wallet.';
    }
    clearRegisterStatus();
    const modal = document.getElementById('contact-modal');
    showModal(modal);
    const firstInput = document.getElementById('contact-nombre');
    if (firstInput) firstInput.focus();
}

function closeRegisterModal() {
    const modal = document.getElementById('contact-modal');
    hideModal(modal);
    clearRegisterStatus();
}

function openRegisterEntry() {
    openRegisterModal();
}

window.radixOpenRegisterEntry = openRegisterEntry;

function openUserLoginModal() {
    const form = document.getElementById('user-login-form');
    const statusEl = document.getElementById('user-login-status');
    const loginInput = document.getElementById('user-login-identifier');
    const modal = document.getElementById('user-login-modal');

    if (form) form.reset();
    if (statusEl) {
        statusEl.textContent = '';
        statusEl.style.color = '#888';
    }

    showModal(modal);
    if (loginInput) loginInput.focus();
}

function closeUserLoginModal() {
    const modal = document.getElementById('user-login-modal');
    const statusEl = document.getElementById('user-login-status');
    hideModal(modal);
    if (statusEl) statusEl.textContent = '';
}

async function procesarRegistro(formValues, statusEl) {
    statusEl.style.color = '#888';
    statusEl.textContent = 'Validando datos del registro...';

    statusEl.textContent = 'Creando tu cuenta...';
    const formData = new FormData();
    formData.append('wallet', formValues.wallet);
    formData.append('nickname', `TRON_${formValues.wallet.substring(0, 4)}`);
    formData.append('nombre_completo', formValues.nombre_completo);
    formData.append('telefono', formValues.telefono);
    formData.append('correo_electronico', formValues.correo_electronico);
    formData.append('telegram_username', formValues.telegram_username);
    formData.append('password', formValues.password);

    const sponsorWallet = formValues.patrocinador || getReferralWallet();
    if (sponsorWallet) {
        formData.append('patrocinador', sponsorWallet);
    }

    const regRes = await fetch('radix_api/registro.php', {
        method: 'POST',
        body: formData
    });
    const regData = await regRes.json();

    if (!regData.success) {
        throw new Error(regData.error || 'No se pudo completar el registro.');
    }

    clearRegisterDraft();
    statusEl.style.color = '#00e676';
    statusEl.textContent = 'Registro completado. Redirigiendo...';

    setTimeout(() => {
        window.location.href = 'dashboard.php';
    }, 500);
}

function inicializarRegistroUsuario() {
    const openBtn = document.getElementById('open-register-modal');
    const navOpenBtn = document.getElementById('open-register-modal-nav');
    const modal = document.getElementById('contact-modal');
    const closeBtn = document.getElementById('contact-modal-close');
    const form = document.getElementById('contact-modal-form');
    const statusEl = document.getElementById('contact-modal-status');
    const switchToLoginBtn = document.getElementById('switch-to-login');

    const nombreInput = document.getElementById('contact-nombre');
    const telefonoInput = document.getElementById('contact-telefono');
    const correoInput = document.getElementById('contact-correo');
    const telegramInput = document.getElementById('contact-telegram');
    const patrocinadorInput = document.getElementById('contact-patrocinador');
    const walletInput = document.getElementById('contact-wallet');
    const passwordInput = document.getElementById('contact-password');
    const passwordConfirmInput = document.getElementById('contact-password-confirm');
    const registerFields = getRegisterFieldMap();

    if ((!openBtn && !navOpenBtn) || !modal || !closeBtn || !form || !statusEl || !nombreInput || !telefonoInput || !correoInput || !walletInput || !passwordInput || !passwordConfirmInput) {
        return;
    }

    const getCurrentFormValues = () => buildRegisterFormValues({
        nombreInput,
        telefonoInput,
        correoInput,
        telegramInput,
        patrocinadorInput,
        walletInput,
        passwordInput
    });

    const validateSingleField = (fieldKey) => {
        const formValues = getCurrentFormValues();
        const passwordConfirm = passwordConfirmInput.value;
        const field = registerFields[fieldKey];
        const message = getRegisterValidationMessage(fieldKey, formValues, passwordConfirm);

        if (message) {
            setRegisterFieldError(field, message);
            return false;
        }

        clearRegisterFieldError(field);
        return true;
    };

    const bindFieldValidation = (input, fieldKey) => {
        if (!input) return;

        input.addEventListener('blur', () => {
            validateSingleField(fieldKey);
        });

        input.addEventListener('input', () => {
            clearRegisterStatus();
            if (registerFields[fieldKey]?.error?.textContent) {
                validateSingleField(fieldKey);
                return;
            }
            clearRegisterFieldError(registerFields[fieldKey]);
        });
    };

    if (openBtn) {
        openBtn.addEventListener('click', openRegisterEntry);
    }
    if (navOpenBtn) {
        navOpenBtn.addEventListener('click', openRegisterEntry);
    }

    closeBtn.addEventListener('click', closeRegisterModal);
    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeRegisterModal();
        }
    });

    if (switchToLoginBtn) {
        switchToLoginBtn.addEventListener('click', () => {
            closeRegisterModal();
            openUserLoginModal();
        });
    }

    bindFieldValidation(nombreInput, 'nombre_completo');
    bindFieldValidation(telefonoInput, 'telefono');
    bindFieldValidation(correoInput, 'correo_electronico');
    bindFieldValidation(telegramInput, 'telegram_username');
    bindFieldValidation(walletInput, 'wallet');
    bindFieldValidation(passwordInput, 'password');
    bindFieldValidation(passwordConfirmInput, 'password_confirm');

    passwordInput.addEventListener('input', () => {
        if (passwordConfirmInput.value || registerFields.password_confirm?.error?.textContent) {
            validateSingleField('password_confirm');
        }
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        const formValues = getCurrentFormValues();
        const passwordConfirm = passwordConfirmInput.value;

        clearRegisterFieldErrors(registerFields);

        if (!validateRegisterForm(registerFields, formValues, passwordConfirm)) {
            statusEl.style.color = '#ff6b6b';
            statusEl.textContent = 'Revisa los campos marcados para continuar.';
            return;
        }

        if (formValues.patrocinador && !isTronWallet(formValues.patrocinador)) {
            statusEl.style.color = '#ff6b6b';
            statusEl.textContent = 'La wallet del patrocinador debe ser una direccion TRON valida.';
            return;
        }

        saveRegisterDraft(formValues);

        try {
            await procesarRegistro(formValues, statusEl);
        } catch (error) {
            statusEl.style.color = '#ff6b6b';
            statusEl.textContent = error.message || 'No se pudo completar el registro.';
            mostrarToastLanding(`Error: ${statusEl.textContent}`);
        }
    });
}

function inicializarLoginUsuario() {
    const openBtn = document.getElementById('open-login-modal');
    const heroOpenBtn = document.getElementById('open-login-modal-hero');
    const modal = document.getElementById('user-login-modal');
    const closeBtn = document.getElementById('user-login-close');
    const form = document.getElementById('user-login-form');
    const statusEl = document.getElementById('user-login-status');
    const loginInput = document.getElementById('user-login-identifier');
    const passwordInput = document.getElementById('user-login-password');
    const switchToRegisterBtn = document.getElementById('switch-to-register');

    if (!openBtn || !modal || !closeBtn || !form || !statusEl || !loginInput || !passwordInput) {
        return;
    }

    openBtn.addEventListener('click', openUserLoginModal);
    if (heroOpenBtn) {
        heroOpenBtn.addEventListener('click', openUserLoginModal);
    }

    if (switchToRegisterBtn) {
        switchToRegisterBtn.addEventListener('click', () => {
            closeUserLoginModal();
            openRegisterEntry();
        });
    }

    closeBtn.addEventListener('click', closeUserLoginModal);
    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeUserLoginModal();
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
                statusEl.textContent = data.error || 'No se pudo iniciar sesion.';
                return;
            }

            statusEl.style.color = '#00e676';
            statusEl.textContent = 'Acceso concedido. Redirigiendo...';
            setTimeout(() => {
                window.location.href = 'dashboard.php';
            }, 500);
        } catch (error) {
            statusEl.style.color = '#ff6b6b';
            statusEl.textContent = 'Error de conexion con el servidor.';
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    const initialUrlParams = new URLSearchParams(window.location.search);
    const initialRef = (initialUrlParams.get('ref') || '').trim();
    if (initialRef) {
        sessionStorage.setItem(REF_STORAGE_KEY, initialRef);
    }

    inicializarHeroSupport();
    inicializarRegistroUsuario();
    inicializarLoginUsuario();
    ensureWalletModalCopyUi();

    // Carga de estadisticas publicas
    async function loadHomeStats() {
        try {
            const res = await fetch('radix_api/public_stats.php');
            const data = await res.json();
            if (data.success) {
                if (document.getElementById('total-users')) document.getElementById('total-users').innerText = data.total_usuarios;
                if (document.getElementById('total-rewards')) document.getElementById('total-rewards').innerText = `$${Number(data.total_pagado).toFixed(2)} USDT`;
            }
        } catch (error) {
            console.log('Stats no disponibles aun.');
        }
    }

    loadHomeStats();

    const launchDate = new Date('2026-04-01');
    const daysSinceLaunch = document.getElementById('total-days');
    if (daysSinceLaunch) {
        const today = new Date();
        const diff = Math.max(0, Math.floor((today - launchDate) / (1000 * 60 * 60 * 24)));
        daysSinceLaunch.innerText = diff;
    }

    const revealEls = document.querySelectorAll('.reveal-on-scroll');
    if (revealEls.length > 0) {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.15 });
        revealEls.forEach((el) => observer.observe(el));
    }
});

(async function mostrarBannerReferido() {
    const params = new URLSearchParams(window.location.search);
    const refWallet = params.get('ref');
    if (!refWallet) return;

    const banner = document.getElementById('ref-banner');
    const nickEl = document.getElementById('ref-nickname');
    if (!banner || !nickEl) return;

    nickEl.innerText = `${refWallet.substring(0, 6)}...${refWallet.slice(-4)}`;
    banner.style.display = 'block';

    const header = document.querySelector('header');
    if (header) header.style.marginTop = '46px';

    try {
        const res = await fetch(`radix_api/public_stats.php?ref_wallet=${encodeURIComponent(refWallet)}`);
        const data = await res.json();
        if (data.display_name || data.nickname) {
            nickEl.innerText = data.display_name || data.nickname;
        }
    } catch (error) {
        // silencioso
    }
})();

function mostrarToastLanding(msg, color = '#ff5252') {
    let container = document.getElementById('landing-toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'landing-toast-container';
        container.style.cssText = 'position:fixed;bottom:24px;left:50%;transform:translateX(-50%);z-index:99999;display:flex;flex-direction:column;align-items:center;gap:8px;pointer-events:none;';
        document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.style.cssText = `background:#111;color:#fff;padding:13px 22px;border-radius:12px;border-left:3px solid ${color};font-size:0.9rem;font-weight:600;box-shadow:0 8px 30px rgba(0,0,0,0.5);pointer-events:auto;max-width:380px;text-align:center;line-height:1.4;`;
    toast.innerText = msg;
    container.appendChild(toast);

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.4s';
        setTimeout(() => toast.remove(), 400);
    }, 5000);
}
