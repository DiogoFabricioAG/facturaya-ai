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
    const drawerKicker = document.querySelector('#company-drawer-kicker');
    const drawerTitle = document.querySelector('#drawer-title');
    const saveCompanyButton = document.querySelector('#save-company');
    const tokenNameField = document.querySelector('#token-name-field');
    const tokenNameInput = companyForm.querySelector('[name="token_name"]');
    const activeInput = companyForm.querySelector('[name="active"]');
    const certificateHelp = document.querySelector('#certificate-help');
    let platformToken = '';
    let companies = [];
    let editingCompany = null;
    let drawerCloseTimer = null;

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
                <div class="file-actions"><button class="edit-company-button" type="button" data-edit-company-id="${escapeHtml(item.id)}">Editar</button></div>
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

    const setDrawerMode = () => {
        const isEditing = Boolean(editingCompany);
        drawerKicker.textContent = isEditing ? 'Editar expediente' : 'Nuevo expediente';
        drawerTitle.textContent = isEditing ? 'Actualiza la empresa emisora' : 'Registrar empresa emisora';
        tokenNameField.hidden = isEditing;
        tokenNameInput.required = !isEditing;
        saveCompanyButton.dataset.originalLabel = isEditing ? 'Guardar cambios' : 'Registrar y generar token';
        saveCompanyButton.querySelector('span').textContent = isEditing ? 'Guardar cambios' : 'Registrar y generar token';
        certificateHelp.textContent = isEditing && editingCompany?.sunat_credentials_configured
            ? 'Ya hay un certificado guardado. Sube otro solo si necesitas reemplazarlo.'
            : 'Sube el archivo original; nosotros lo preparamos para Greenter.';
    };

    const syncCredentialRequirements = () => {
        const real = document.querySelector('#sunat-driver').value === 'greenter';
        const credentials = document.querySelector('#sunat-credentials');
        credentials.hidden = !real;
        credentials.querySelectorAll('input').forEach((input) => {
            input.required = real && !editingCompany;
        });
    };

    const openDrawer = (company = null) => {
        window.clearTimeout(drawerCloseTimer);
        editingCompany = company;
        companyForm.reset();
        if (company) {
            const address = company.fiscal_address || {};
            Object.entries({
                ruc: company.ruc,
                legal_name: company.legal_name,
                trade_name: company.trade_name || '',
                ubigeo: address.ubigeo,
                department: address.department,
                province: address.province,
                district: address.district,
                address: address.address,
                sunat_driver: company.sunat_driver,
                sunat_environment: company.sunat_environment,
                default_series: company.default_series,
                default_credit_note_series: company.default_credit_note_series,
                active: company.active ? '1' : '0',
            }).forEach(([name, value]) => {
                const field = companyForm.elements.namedItem(name);
                if (field) field.value = value ?? '';
            });
        }
        setDrawerMode();
        syncCredentialRequirements();
        companyFormError.hidden = true;
        drawer.hidden = false;
        drawer.classList.remove('is-open');
        drawer.setAttribute('aria-hidden', 'false');
        document.body.classList.add('no-scroll');
        // Force the closed layout to be painted before enabling the open state;
        // this keeps the entrance transition reliable across browsers.
        void drawer.offsetWidth;
        window.requestAnimationFrame(() => window.requestAnimationFrame(() => drawer.classList.add('is-open')));
    };
    const closeDrawer = () => {
        if (drawer.hidden) return;
        drawer.classList.remove('is-open');
        drawer.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('no-scroll');
        editingCompany = null;
        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        drawerCloseTimer = window.setTimeout(() => {
            if (!drawer.classList.contains('is-open')) drawer.hidden = true;
        }, reducedMotion ? 0 : 340);
    };
    document.querySelector('#open-company-form').addEventListener('click', openDrawer);
    document.querySelector('#close-company-form').addEventListener('click', closeDrawer);
    drawer.addEventListener('click', (event) => { if (event.target === drawer) closeDrawer(); });

    document.querySelector('#sunat-driver').addEventListener('change', (event) => {
        syncCredentialRequirements();
    });

    document.querySelector('#company-list').addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-edit-company-id]');
        if (!trigger) return;
        const company = companies.find((item) => item.id === trigger.dataset.editCompanyId);
        if (company) openDrawer(company);
    });

    companyForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const button = document.querySelector('#save-company');
        companyFormError.hidden = true;
        setBusy(button, true, editingCompany ? 'Guardando…' : 'Registrando…');
        showNotice('');
        try {
            const formData = new FormData(companyForm);
            const isEditing = Boolean(editingCompany);
            let endpoint = '/api/admin/companies';
            if (isEditing) {
                endpoint += `/${editingCompany.id}`;
                formData.append('_method', 'PUT');
                formData.delete('token_name');
                ['sol_user', 'sol_password', 'certificate_password'].forEach((name) => {
                    if (!formData.get(name)) formData.delete(name);
                });
                if (!formData.get('certificate') || !formData.get('certificate').name) formData.delete('certificate');
                formData.set('active', activeInput.value === '1' ? '1' : '0');
            }
            const payload = await api(endpoint, { method: 'POST', body: formData });
            if (isEditing) {
                closeDrawer();
                companyForm.reset();
                await loadCompanies();
                showNotice('Cambios guardados. La empresa sigue lista para usar.', 'success');
                return;
            }
            document.querySelector('#issued-token-value').textContent = payload.api_token;
            closeDrawer();
            tokenDialog.hidden = false;
            document.body.classList.add('no-scroll');
            companyForm.reset();
            const credentials = document.querySelector('#sunat-credentials');
            credentials.hidden = true;
            credentials.querySelectorAll('input').forEach((input) => { input.required = false; });
            setDrawerMode();
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
