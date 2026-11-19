if (document.body.dataset.page === 'despatches') {
    const $ = (selector) => document.querySelector(selector);
    const form = $('#guide-form');
    const notice = $('#notice');
    const accessGate = $('#access-gate');
    const accessForm = $('#company-access-form');
    const accessError = $('#access-error');
    const companyTokenInput = $('#company-token');
    const connectButton = $('#connect-company');
    const invoiceSelect = $('#gre-invoice');
    const categorySelect = $('#gre-vehicle-category');
    const transportFields = $('#gre-transport-fields');
    const itemsBody = $('#gre-items-body');
    const copyItemsButton = $('#gre-copy-items');
    const reviewButton = $('#gre-review-button');
    const issueButton = $('#gre-issue-button');
    const confirmCheck = $('#review-confirm-check');
    const refreshButton = $('#gre-refresh-button');
    const units = JSON.parse($('#gre-units').textContent);

    let companyToken = '';
    let company = null;
    let invoices = [];
    let guides = [];
    let current = null;
    let itemsTouched = false;

    const STATUS = {
        draft: ['Borrador', 'review_required'],
        processing: ['Enviando', 'analyzing'],
        pending: ['Por confirmar en SUNAT', 'analyzing'],
        accepted: ['Aceptada', 'accepted'],
        rejected: ['Rechazada', 'rejected'],
        send_failed: ['No enviada', 'failed'],
        unknown: ['Envío incierto', 'review_required'],
        preparation_failed: ['No preparada', 'failed'],
    };
    const SENT = ['pending', 'accepted', 'rejected', 'unknown', 'send_failed'];
    const CATEGORY = { M1: 'Vehículo M1 (sin placa ni conductor en la guía)', L: 'Vehículo L (sin placa ni conductor en la guía)' };

    const escapeHtml = (value) => {
        const element = document.createElement('span');
        element.textContent = value ?? '';
        return element.innerHTML.replace(/"/g, '&quot;');
    };
    const initials = (name) => String(name || 'FY').split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
    const limaToday = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Lima' }).format(new Date());
    const guideNumber = (guide) => (guide.correlative ? `${guide.series}-${guide.correlative}` : null);
    const statusOf = (status) => STATUS[status] || [status, 'analyzing'];
    const unitLabel = (code) => (units[code] ? `${units[code]} (${code})` : code);
    const newKey = () => `web-${window.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(36).slice(2)}`}`;

    const setBusy = (button, busy, label) => {
        button.disabled = busy;
        const text = button.querySelector('span') || button;
        if (!button.dataset.originalLabel) button.dataset.originalLabel = text.textContent;
        text.textContent = busy ? label : button.dataset.originalLabel;
    };

    const showNotice = (message, type = 'info') => {
        notice.hidden = !message;
        notice.className = `notice ${type === 'error' ? 'is-error' : type === 'success' ? 'is-success' : ''}`;
        notice.textContent = message;
        if (message) {
            const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            notice.scrollIntoView({ behavior: reducedMotion ? 'auto' : 'smooth', block: 'nearest' });
        }
    };

    const api = async (url, options = {}) => {
        const response = await fetch(url, {
            ...options,
            headers: {
                Accept: 'application/json',
                ...(options.body ? { 'Content-Type': 'application/json' } : {}),
                ...(companyToken ? { Authorization: `Bearer ${companyToken}` } : {}),
                ...(options.headers || {}),
            },
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            const validation = payload.errors ? Object.values(payload.errors).flat().join(' ') : '';
            throw new Error(validation || payload.message || 'No pudimos completar la operación.');
        }
        return payload.data ?? payload;
    };

    const downloadProtectedFile = async (url) => {
        const response = await fetch(url, { headers: { Authorization: `Bearer ${companyToken}` } });
        if (!response.ok) throw new Error('El archivo ya no está disponible.');
        const blob = await response.blob();
        const disposition = response.headers.get('Content-Disposition') || '';
        const filename = disposition.match(/filename="?([^";]+)"?/i)?.[1] || 'guia';
        const objectUrl = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = objectUrl;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(objectUrl);
    };

    const setStep = (active) => {
        const order = ['source', 'review', 'sunat'];
        const index = order.indexOf(active);
        document.querySelectorAll('.conveyor li').forEach((element, position) => {
            element.classList.toggle('is-active', position === index);
            element.classList.toggle('is-done', position < index);
        });
    };

    const unitOptions = (selected) => Object.entries(units)
        .map(([code, name]) => `<option value="${escapeHtml(code)}"${code === selected ? ' selected' : ''}>${escapeHtml(`${name} (${code})`)}</option>`)
        .join('');

    const addItemRow = (item = {}) => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td><input class="item-input description" name="item_description" maxlength="500" required value="${escapeHtml(item.description || '')}" aria-label="Descripción del bien"></td>
            <td><input class="item-input quantity" name="item_quantity" inputmode="decimal" pattern="[0-9]{1,9}([.][0-9]{1,3})?" required value="${escapeHtml(item.quantity || '')}" aria-label="Cantidad"></td>
            <td><select class="item-input gre-unit" name="item_unit" aria-label="Unidad">${unitOptions(item.unit || 'NIU')}</select></td>
            <td><button class="remove-item" type="button" aria-label="Quitar bien">×</button></td>`;
        itemsBody.appendChild(row);
        return row;
    };

    const fillItemsFromInvoice = (invoice) => {
        itemsBody.innerHTML = '';
        (invoice?.items || []).forEach((item) => addItemRow({ description: item.description, quantity: item.quantity, unit: 'NIU' }));
        if (!itemsBody.children.length) addItemRow();
        itemsTouched = false;
    };

    const selectedInvoice = () => invoices.find((invoice) => invoice.id === invoiceSelect.value) || null;

    const renderRecipient = () => {
        const invoice = selectedInvoice();
        $('#gre-recipient').hidden = !invoice;
        copyItemsButton.hidden = !invoice?.items?.length;
        if (!invoice) return;
        $('#gre-recipient-name').textContent = invoice.customer.name;
        $('#gre-recipient-ruc').textContent = `RUC ${invoice.customer.ruc} · factura ${invoice.number} del ${invoice.issue_date}`;
        const previous = invoice.guides.filter((guide) => guide.status !== 'draft');
        const previousLabel = $('#gre-recipient-guides');
        previousLabel.hidden = !previous.length;
        previousLabel.textContent = previous.length
            ? `Esta factura ya tiene ${previous.length === 1 ? 'una guía' : `${previous.length} guías`}: ${previous.map((guide) => `${guide.number || 'sin número'} (${statusOf(guide.status)[0].toLowerCase()})`).join(', ')}.`
            : '';
    };

    const syncTransportFields = () => {
        const other = categorySelect.value === 'other';
        transportFields.hidden = !other;
        transportFields.disabled = !other;
    };

    const renderInvoices = () => {
        const selected = invoiceSelect.value;
        invoiceSelect.innerHTML = invoices.length
            ? '<option value="">Selecciona la factura del traslado</option>'
            : '<option value="">No hay facturas aceptadas con cliente RUC</option>';
        invoices.forEach((invoice) => {
            const option = document.createElement('option');
            option.value = invoice.id;
            const sent = invoice.guides.filter((guide) => guide.status !== 'draft').length;
            option.textContent = `${invoice.number} · ${invoice.customer.name} · ${invoice.issue_date}${sent ? ` · ${sent} guía${sent === 1 ? '' : 's'}` : ''}`;
            invoiceSelect.appendChild(option);
        });
        invoiceSelect.value = invoices.some((invoice) => invoice.id === selected) ? selected : '';
        renderRecipient();
    };

    const loadInvoices = async () => {
        invoices = await api('/api/despatch-invoices');
        renderInvoices();
    };

    const transportText = (payload) => {
        const category = payload.vehicle_category || 'other';
        if (CATEGORY[category]) return `Venta · transporte privado · ${CATEGORY[category]}`;
        const driver = payload.driver || {};
        return `Venta · transporte privado · placa ${payload.vehicle?.plate || '—'} · conductor ${driver.first_name || ''} ${driver.last_name || ''} (DNI ${driver.dni || '—'}, licencia ${driver.license || '—'})`;
    };

    const renderGuide = (guide) => {
        current = guide;
        const payload = guide.payload || {};
        const [label] = statusOf(guide.status);
        const badge = $('#gre-status');
        badge.textContent = label;
        badge.className = `status-badge${guide.status === 'accepted' ? ' is-issued' : guide.status === 'draft' ? ' is-ready' : ''}`;
        $('#preview-title').textContent = guide.status === 'draft'
            ? 'Revisa la guía antes de enviarla'
            : `Guía ${guideNumber(guide) || guide.series}`;
        $('#gre-empty').hidden = true;
        $('#gre-review').hidden = false;
        $('#review-customer').textContent = guide.customer?.name || 'Cliente de la factura';
        $('#review-customer-ruc').textContent = guide.customer?.ruc ? `RUC ${guide.customer.ruc}` : '';
        $('#review-environment').textContent = guide.is_demo
            ? 'Simulación local: no se envía a SUNAT'
            : `Emisor RUC ${company?.ruc || ''} · SUNAT ${guide.environment === 'production' ? 'producción' : guide.environment}`;
        $('#review-number').textContent = guideNumber(guide) || `${guide.series} · número al emitir`;
        $('#review-invoice').textContent = guide.invoice_number || '—';
        $('#review-issue-date').textContent = payload.issue_date || '—';
        $('#review-transfer-date').textContent = payload.transfer_date || '—';
        $('#review-transport').textContent = transportText(payload);
        $('#review-origin').textContent = `${payload.origin?.address || '—'} · ubigeo ${payload.origin?.ubigeo || '—'}`;
        $('#review-destination').textContent = `${payload.destination?.address || '—'} · ubigeo ${payload.destination?.ubigeo || '—'}`;
        $('#review-weight').textContent = `${payload.weight_kg || '—'} kg`;
        $('#review-item-count').textContent = String((payload.items || []).length);
        $('#review-items').innerHTML = (payload.items || []).map((item) => `<tr>
            <td>${escapeHtml(item.description)}</td><td>${escapeHtml(item.quantity)}</td><td>${escapeHtml(unitLabel(item.unit))}</td></tr>`).join('');

        const message = $('#review-message');
        const showMessage = guide.status !== 'draft' && guide.message;
        message.hidden = !showMessage;
        message.textContent = showMessage ? guide.message : '';
        message.className = `gre-message${['send_failed', 'rejected', 'preparation_failed', 'unknown'].includes(guide.status) ? ' is-error' : guide.status === 'accepted' ? ' is-success' : ''}`;

        const isDraft = guide.status === 'draft';
        $('#review-confirm').hidden = !isDraft;
        confirmCheck.checked = false;
        issueButton.disabled = true;
        $('#review-confirm-label').textContent = guide.is_demo
            ? 'Revisé los datos. Simular el envío (esta empresa no envía a SUNAT).'
            : 'Revisé los datos. Enviar esta guía a SUNAT; después no se puede modificar.';

        const followup = $('#review-followup');
        refreshButton.hidden = guide.status !== 'pending';
        $('#gre-xml-button').hidden = !SENT.includes(guide.status);
        $('#gre-cdr-button').hidden = !['accepted', 'rejected'].includes(guide.status);
        followup.hidden = refreshButton.hidden && $('#gre-xml-button').hidden && $('#gre-cdr-button').hidden;
        setStep(isDraft ? 'review' : 'sunat');
    };

    const clearPreview = () => {
        current = null;
        $('#gre-status').textContent = 'Esperando datos';
        $('#gre-status').className = 'status-badge';
        $('#preview-title').textContent = 'Tu guía aparecerá aquí';
        $('#gre-empty').hidden = false;
        $('#gre-review').hidden = true;
        setStep('source');
    };

    const renderGuides = () => {
        const body = $('#recent-body');
        $('#recent-count').textContent = `${guides.length} ${guides.length === 1 ? 'registro' : 'registros'}`;
        if (!guides.length) {
            body.innerHTML = '<tr><td colspan="6" class="empty-row">Aún no hay guías. La primera aparecerá aquí.</td></tr>';
            return;
        }
        body.innerHTML = guides.map((guide) => {
            const [label, tone] = statusOf(guide.status);
            const payload = guide.payload || {};
            const files = `${SENT.includes(guide.status) ? `<button class="document-file-link" type="button" data-file-id="${escapeHtml(guide.id)}" data-file-type="xml">XML</button>` : ''}${['accepted', 'rejected'].includes(guide.status) ? `<button class="document-file-link" type="button" data-file-id="${escapeHtml(guide.id)}" data-file-type="cdr">CDR</button>` : ''}`;
            const environment = guide.is_demo ? '<span class="document-environment environment-beta">Simulación</span>' : '';
            let action = `<button class="credit-note-trigger" type="button" data-open-id="${escapeHtml(guide.id)}">Ver</button>`;
            if (guide.status === 'draft') action = `<button class="review-draft-trigger" type="button" data-open-id="${escapeHtml(guide.id)}">Revisar y emitir</button>`;
            if (guide.status === 'pending') action = `<button class="retry-invoice-trigger" type="button" data-refresh-id="${escapeHtml(guide.id)}">Consultar estado</button>`;
            return `<tr>
                <td><span class="mono-date">${escapeHtml(payload.issue_date || '')}</span></td>
                <td><strong>${escapeHtml(guideNumber(guide) || 'Sin número')}</strong>${environment}${files ? `<div class="invoice-file-list">${files}</div>` : ''}</td>
                <td><strong>${escapeHtml(guide.invoice_number || '—')}</strong><small>${escapeHtml(guide.customer?.name || '')}</small></td>
                <td><strong>${escapeHtml(payload.destination?.address || '—')}</strong><small>Ubigeo ${escapeHtml(payload.destination?.ubigeo || '—')}</small></td>
                <td><span class="activity-status status-${escapeHtml(tone)}">${escapeHtml(label)}</span>${guide.status !== 'draft' && guide.message ? `<small class="gre-row-message">${escapeHtml(guide.message)}</small>` : ''}</td>
                <td>${action}</td>
            </tr>`;
        }).join('');
    };

    const prefillOrigin = () => {
        const origin = guides.find((guide) => guide.payload?.origin)?.payload.origin;
        if (!origin) return;
        if (!form.elements.origin_ubigeo.value) form.elements.origin_ubigeo.value = origin.ubigeo || '';
        if (!form.elements.origin_address.value) form.elements.origin_address.value = origin.address || '';
    };

    const loadGuides = async () => {
        guides = await api('/api/despatches');
        renderGuides();
        prefillOrigin();
    };

    const resetDates = () => {
        const today = limaToday();
        form.elements.issue_date.value = today;
        form.elements.issue_date.max = today;
        form.elements.transfer_date.value = today;
        form.elements.transfer_date.min = today;
    };

    const resetForm = () => {
        const origin = { ubigeo: form.elements.origin_ubigeo.value, address: form.elements.origin_address.value };
        form.reset();
        form.elements.origin_ubigeo.value = origin.ubigeo;
        form.elements.origin_address.value = origin.address;
        resetDates();
        itemsBody.innerHTML = '';
        addItemRow();
        itemsTouched = false;
        syncTransportFields();
        renderRecipient();
        clearPreview();
    };

    const renderCompany = (value) => {
        company = value;
        $('#active-company-name').textContent = company.trade_name || company.legal_name;
        $('#active-company-meta').textContent = `RUC ${company.ruc} · ${company.sunat_driver === 'fake' ? 'Simulación' : `SUNAT ${company.sunat_environment}`}`;
        $('#company-avatar').textContent = initials(company.trade_name || company.legal_name);
        $('#app-content').classList.add('is-ready');
        accessGate.classList.add('is-hidden');
        accessError.hidden = true;
    };

    const connectCompany = async (token) => {
        companyToken = token.trim();
        if (!companyToken) throw new Error('Escribe la clave de tu empresa.');
        const connected = await api('/api/company');
        sessionStorage.setItem('facturaya_company_token', companyToken);
        renderCompany(connected);
        await Promise.all([loadInvoices(), loadGuides()]);
    };

    const disconnectCompany = () => {
        sessionStorage.removeItem('facturaya_company_token');
        companyToken = '';
        company = null;
        invoices = [];
        guides = [];
        companyTokenInput.value = '';
        accessGate.classList.remove('is-hidden');
        $('#app-content').classList.remove('is-ready');
        resetForm();
        setTimeout(() => companyTokenInput.focus(), 80);
    };

    const payloadFromForm = () => {
        const elements = form.elements;
        const items = [...itemsBody.querySelectorAll('tr')].map((row) => ({
            description: row.querySelector('[name="item_description"]').value.trim(),
            quantity: row.querySelector('[name="item_quantity"]').value.trim().replace(',', '.'),
            unit: row.querySelector('[name="item_unit"]').value,
        }));
        if (!items.length) throw new Error('Agrega al menos un bien trasladado.');
        if (items.some((item) => !(Number(item.quantity) > 0))) throw new Error('Cada bien necesita una cantidad mayor que cero.');
        const weight = elements.weight_kg.value.trim().replace(',', '.');
        if (!(Number(weight) > 0)) throw new Error('Indica el peso bruto total en kilogramos.');
        const payload = {
            idempotency_key: newKey(),
            invoice_id: elements.invoice_id.value,
            issue_date: elements.issue_date.value,
            transfer_date: elements.transfer_date.value,
            vehicle_category: elements.vehicle_category.value,
            origin: { ubigeo: elements.origin_ubigeo.value.trim(), address: elements.origin_address.value.trim() },
            destination: { ubigeo: elements.destination_ubigeo.value.trim(), address: elements.destination_address.value.trim() },
            weight_kg: weight,
            items,
        };
        if (payload.vehicle_category === 'other') {
            const plate = elements.plate.value.toUpperCase().replace(/[\s-]/g, '');
            if (!/^[A-Z0-9]{6}$/.test(plate)) throw new Error('La placa debe tener 6 letras o números, sin guion.');
            payload.vehicle = { plate };
            payload.driver = {
                dni: elements.driver_dni.value.trim(),
                first_name: elements.driver_first_name.value.trim(),
                last_name: elements.driver_last_name.value.trim(),
                license: elements.driver_license.value.toUpperCase().replace(/[\s-]/g, ''),
            };
        }
        return payload;
    };

    const resultNotice = (guide) => {
        if (guide.is_demo) return ['Simulación local completada: esta guía no se envió a SUNAT.', 'info'];
        if (guide.status === 'accepted') return [`SUNAT aceptó la guía ${guideNumber(guide)}.`, 'success'];
        if (guide.status === 'pending') return [`SUNAT recibió la guía ${guideNumber(guide)}. Falta confirmar si fue aceptada: consulta el estado en unos segundos.`, 'info'];
        return [guide.message || 'La guía no quedó aceptada. Revisa el detalle.', 'error'];
    };

    const refreshGuide = async (id) => {
        const guide = await api(`/api/despatches/${encodeURIComponent(id)}/status`, { method: 'POST' });
        if (current?.id === guide.id) renderGuide(guide);
        await Promise.all([loadGuides(), loadInvoices()]);
        const [message, type] = resultNotice(guide);
        showNotice(guide.status === 'pending' ? 'SUNAT aún está procesando la guía. Vuelve a consultar en unos segundos.' : message, type);
        return guide;
    };

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!form.reportValidity()) return;
        let payload;
        try {
            payload = payloadFromForm();
        } catch (error) {
            showNotice(error.message, 'error');
            return;
        }
        setBusy(reviewButton, true, 'Preparando revisión…');
        try {
            const guide = await api('/api/despatches', { method: 'POST', body: JSON.stringify(payload) });
            renderGuide(guide);
            await loadGuides();
            showNotice('Revisa la guía en la vista previa. No se envió nada a SUNAT.', 'success');
            const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            $('#gre-review').scrollIntoView({ behavior: reducedMotion ? 'auto' : 'smooth', block: 'start' });
        } catch (error) {
            showNotice(error.message, 'error');
        } finally {
            setBusy(reviewButton, false);
        }
    });

    confirmCheck.addEventListener('change', () => { issueButton.disabled = !confirmCheck.checked; });

    issueButton.addEventListener('click', async () => {
        if (!current || current.status !== 'draft' || !confirmCheck.checked) return;
        setBusy(issueButton, true, current.is_demo ? 'Simulando…' : 'Enviando a SUNAT…');
        try {
            const guide = await api(`/api/despatches/${encodeURIComponent(current.id)}/issue`, {
                method: 'POST',
                body: JSON.stringify({ confirmed: true, confirmation_digest: current.confirmation_digest }),
            });
            renderGuide(guide);
            await Promise.all([loadGuides(), loadInvoices()]);
            const [message, type] = resultNotice(guide);
            showNotice(message, type);
        } catch (error) {
            showNotice(error.message, 'error');
        } finally {
            setBusy(issueButton, false);
            issueButton.disabled = !confirmCheck.checked || current?.status !== 'draft';
        }
    });

    refreshButton.addEventListener('click', async () => {
        if (!current) return;
        setBusy(refreshButton, true, 'Consultando…');
        try {
            await refreshGuide(current.id);
        } catch (error) {
            showNotice(error.message, 'error');
        } finally {
            setBusy(refreshButton, false);
        }
    });

    $('#gre-xml-button').addEventListener('click', () => current && downloadProtectedFile(`/api/despatches/${encodeURIComponent(current.id)}/files/xml`).catch((error) => showNotice(error.message, 'error')));
    $('#gre-cdr-button').addEventListener('click', () => current && downloadProtectedFile(`/api/despatches/${encodeURIComponent(current.id)}/files/cdr`).catch((error) => showNotice(error.message, 'error')));

    $('#recent-body').addEventListener('click', async (event) => {
        const openTrigger = event.target.closest('[data-open-id]');
        const refreshTrigger = event.target.closest('[data-refresh-id]');
        const fileTrigger = event.target.closest('[data-file-id]');
        try {
            if (fileTrigger) {
                await downloadProtectedFile(`/api/despatches/${encodeURIComponent(fileTrigger.dataset.fileId)}/files/${fileTrigger.dataset.fileType}`);
            } else if (refreshTrigger) {
                setBusy(refreshTrigger, true, 'Consultando…');
                const guide = await refreshGuide(refreshTrigger.dataset.refreshId);
                renderGuide(guide);
            } else if (openTrigger) {
                setBusy(openTrigger, true, 'Abriendo…');
                renderGuide(await api(`/api/despatches/${encodeURIComponent(openTrigger.dataset.openId)}`));
                setBusy(openTrigger, false);
                const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                $('#gre-review').scrollIntoView({ behavior: reducedMotion ? 'auto' : 'smooth', block: 'start' });
            }
        } catch (error) {
            showNotice(error.message, 'error');
            if (openTrigger) setBusy(openTrigger, false);
            if (refreshTrigger) setBusy(refreshTrigger, false);
        }
    });

    invoiceSelect.addEventListener('change', () => {
        renderRecipient();
        const invoice = selectedInvoice();
        if (invoice && !itemsTouched) fillItemsFromInvoice(invoice);
    });
    copyItemsButton.addEventListener('click', () => {
        const invoice = selectedInvoice();
        if (!invoice) return;
        if (itemsTouched && !window.confirm('Se reemplazarán los bienes escritos por los de la factura. ¿Continuar?')) return;
        fillItemsFromInvoice(invoice);
    });
    categorySelect.addEventListener('change', syncTransportFields);
    form.elements.issue_date.addEventListener('change', () => {
        form.elements.transfer_date.min = form.elements.issue_date.value;
    });
    itemsBody.addEventListener('input', () => { itemsTouched = true; });
    itemsBody.addEventListener('click', (event) => {
        const remove = event.target.closest('.remove-item');
        if (!remove) return;
        remove.closest('tr').remove();
        itemsTouched = true;
        if (!itemsBody.children.length) addItemRow();
    });
    $('#gre-add-item').addEventListener('click', () => {
        itemsTouched = true;
        addItemRow().querySelector('input').focus();
    });
    $('#new-guide-button').addEventListener('click', () => {
        resetForm();
        showNotice('');
        form.scrollIntoView({ block: 'start' });
    });

    accessForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        setBusy(connectButton, true, 'Verificando clave…');
        try {
            await connectCompany(companyTokenInput.value);
        } catch (error) {
            companyToken = '';
            accessError.textContent = error.message || 'No pudimos validar la clave.';
            accessError.hidden = false;
        } finally {
            setBusy(connectButton, false);
        }
    });
    $('#use-demo-token').addEventListener('click', () => {
        companyTokenInput.value = 'fya_demo_local_token';
        accessForm.requestSubmit();
    });
    $('#company-switcher').addEventListener('click', disconnectCompany);

    resetDates();
    addItemRow();
    syncTransportFields();

    const savedCompanyToken = sessionStorage.getItem('facturaya_company_token');
    if (savedCompanyToken) {
        companyTokenInput.value = savedCompanyToken;
        connectCompany(savedCompanyToken).catch(() => disconnectCompany());
    } else {
        setTimeout(() => companyTokenInput.focus(), 80);
    }
}
