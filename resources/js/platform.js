if (document.body.dataset.page === 'platform') {
    const gate = document.querySelector('#platform-gate');
    const gateForm = document.querySelector('#platform-access-form');
    const tokenInput = document.querySelector('#platform-token');
    const gateError = document.querySelector('#platform-access-error');
    const connectButton = document.querySelector('#connect-platform');
    const drawer = document.querySelector('#company-drawer');
    const companyForm = document.querySelector('#company-form');
    const companyFormError = document.querySelector('#company-form-error');
    const tokenDialog = document.querySelector('#token-dialog');
    let platformToken = '';
    let companies = [];

    const escapeHtml = (value) => {
        const element = document.createElement('span');
        element.textContent = value ?? '';
        return element.innerHTML;
    };

    const setBusy = (button, busy, label) => {
        button.disabled = busy;
        const text = button.querySelector('span') || button;
        if (!button.dataset.originalLabel) button.dataset.originalLabel = text.textContent;
        text.textContent = busy ? label : button.dataset.originalLabel;
    };

    const api = async (url, options = {}) => {
        const response = await fetch(url, {
            ...options,
            headers: { Accept: 'application/json', Authorization: `Bearer ${platformToken}`, ...(options.headers || {}) },
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            const validation = payload.errors ? Object.values(payload.errors).flat().join(' ') : '';
            throw new Error(validation || payload.message || 'No se pudo completar la operación.');
        }
        return payload;
    };

    const showNotice = (message, type = 'success') => {
        const notice = document.querySelector('#platform-notice');
        notice.hidden = !message;
        notice.className = `notice ${type === 'error' ? 'is-error' : 'is-success'}`;
        notice.textContent = message;
    };

    const renderCompanies = () => {
        const total = companies.length;
        const live = companies.filter((item) => item.sunat_driver === 'greenter').length;
        document.querySelector('#metric-total').textContent = total;
        document.querySelector('#metric-live').textContent = live;
        document.querySelector('#metric-fake').textContent = total - live;
        document.querySelector('#company-count').textContent = `${total} ${total === 1 ? 'registro' : 'registros'}`;
        const list = document.querySelector('#company-list');
        if (!total) {
            list.innerHTML = '<div class="registry-empty">No hay empresas todavía. Registra la primera para comenzar.</div>';
            return;
        }
        list.innerHTML = companies.map((item, index) => `
            <article class="company-file">
                <span class="file-index">${String(index + 1).padStart(2, '0')}</span>
                <div class="file-identity"><span class="file-ruc">RUC ${escapeHtml(item.ruc)}</span><h3>${escapeHtml(item.trade_name || item.legal_name)}</h3><p>${escapeHtml(item.legal_name)}</p></div>
                <div class="file-location"><span>Domicilio fiscal</span><strong>${escapeHtml(item.fiscal_address.district)}, ${escapeHtml(item.fiscal_address.department)}</strong><small>${escapeHtml(item.fiscal_address.address)}</small></div>
                <div class="file-sunat"><span class="mode-chip mode-${escapeHtml(item.sunat_driver)}">${item.sunat_driver === 'greenter' ? 'Greenter' : 'Simulación'}</span><small>${escapeHtml(item.sunat_environment)} · Fact. ${escapeHtml(item.default_series)} · NC ${escapeHtml(item.default_credit_note_series)}</small></div>
                <span class="file-status ${item.active ? 'is-active' : ''}">${item.active ? 'Activa' : 'Inactiva'}</span>
            </article>
        `).join('');
    };

    const loadCompanies = async () => {
        const payload = await api('/api/admin/companies');
        companies = payload.data || [];
        renderCompanies();
    };

    const connect = async (token) => {
        platformToken = token.trim();
        if (!platformToken) throw new Error('Escribe la clave de administración.');
        await loadCompanies();
        sessionStorage.setItem('facturaya_platform_token', platformToken);
        gate.classList.add('is-hidden');
        document.querySelector('#platform-content').classList.add('is-ready');
    };

    gateForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        gateError.hidden = true;
        setBusy(connectButton, true, 'Verificando…');
        try {
            await connect(tokenInput.value);
        } catch (error) {
            platformToken = '';
            gateError.textContent = error.message;
            gateError.hidden = false;
        } finally {
            setBusy(connectButton, false, '');
        }
    });

    document.querySelector('#close-platform').addEventListener('click', () => {
        sessionStorage.removeItem('facturaya_platform_token');
        platformToken = '';
        tokenInput.value = '';
        gate.classList.remove('is-hidden');
        document.querySelector('#platform-content').classList.remove('is-ready');
        tokenInput.focus();
    });

    const openDrawer = () => {
        companyFormError.hidden = true;
        drawer.hidden = false;
        document.body.classList.add('no-scroll');
    };
    const closeDrawer = () => { drawer.hidden = true; document.body.classList.remove('no-scroll'); };
    document.querySelector('#open-company-form').addEventListener('click', openDrawer);
    document.querySelector('#close-company-form').addEventListener('click', closeDrawer);
    drawer.addEventListener('click', (event) => { if (event.target === drawer) closeDrawer(); });

    document.querySelector('#sunat-driver').addEventListener('change', (event) => {
        const real = event.target.value === 'greenter';
        const credentials = document.querySelector('#sunat-credentials');
        credentials.hidden = !real;
        credentials.querySelectorAll('input').forEach((input) => { input.required = real; });
    });

    companyForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const button = document.querySelector('#save-company');
        companyFormError.hidden = true;
        setBusy(button, true, 'Registrando…');
        showNotice('');
        try {
            const payload = await api('/api/admin/companies', { method: 'POST', body: new FormData(companyForm) });
            document.querySelector('#issued-token-value').textContent = payload.api_token;
            closeDrawer();
            tokenDialog.hidden = false;
            document.body.classList.add('no-scroll');
            companyForm.reset();
            const credentials = document.querySelector('#sunat-credentials');
            credentials.hidden = true;
            credentials.querySelectorAll('input').forEach((input) => { input.required = false; });
            await loadCompanies();
        } catch (error) {
            companyFormError.textContent = error.message;
            companyFormError.hidden = false;
            const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            companyFormError.scrollIntoView({ behavior: reducedMotion ? 'auto' : 'smooth', block: 'center' });
        } finally {
            setBusy(button, false, '');
        }
    });

    document.querySelector('#copy-token').addEventListener('click', async (event) => {
        await navigator.clipboard.writeText(document.querySelector('#issued-token-value').textContent);
        event.currentTarget.textContent = 'Copiada';
    });

    document.querySelector('#close-token-dialog').addEventListener('click', () => {
        tokenDialog.hidden = true;
        document.body.classList.remove('no-scroll');
        showNotice('Empresa registrada y lista para usar.', 'success');
    });

    const savedToken = sessionStorage.getItem('facturaya_platform_token');
    if (savedToken) {
        tokenInput.value = savedToken;
        connect(savedToken).catch(() => {
            sessionStorage.removeItem('facturaya_platform_token');
            platformToken = '';
            gate.classList.remove('is-hidden');
        });
    } else {
        setTimeout(() => tokenInput.focus(), 80);
    }
}
