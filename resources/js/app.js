if (document.body.dataset.page === 'invoice') {
    const form = document.querySelector('#import-form');
    const fileInput = document.querySelector('#document-file');
    const fileName = document.querySelector('#file-name');
    const dropzone = document.querySelector('#dropzone');
    const productsText = document.querySelector('#products-text');
    const productsTextCount = document.querySelector('#products-text-count');
    const analyzeButton = document.querySelector('#analyze-button');
    const savedCustomer = document.querySelector('#saved-customer');
    const saveCustomerChoice = document.querySelector('#save-customer-choice');
    const saveCustomerCheckbox = document.querySelector('#save-customer');
    const customerSaveStatus = document.querySelector('#customer-save-status');
    const emptyPreview = document.querySelector('#empty-preview');
    const loadingPreview = document.querySelector('#loading-preview');
    const draftPreview = document.querySelector('#draft-preview');
    const draftStatus = document.querySelector('#draft-status');
    const itemsBody = document.querySelector('#items-body');
    const notice = document.querySelector('#notice');
    const issueButton = document.querySelector('#issue-button');
    const autosaveStatus = document.querySelector('#autosave-status');
    const accessGate = document.querySelector('#access-gate');
    const accessForm = document.querySelector('#company-access-form');
    const accessError = document.querySelector('#access-error');
    const companyTokenInput = document.querySelector('#company-token');
    const connectButton = document.querySelector('#connect-company');
    const creditDrawer = document.querySelector('#credit-note-drawer');
    const creditForm = document.querySelector('#credit-note-form');
    const creditItemsBody = document.querySelector('#credit-items-body');
    const creditReasonCode = document.querySelector('#credit-reason-code');
    const creditReasonDescription = document.querySelector('#credit-reason-description');
    const creditIssueButton = document.querySelector('#issue-credit-note');
    const creditError = document.querySelector('#credit-note-error');

    let draft = null;
    let company = null;
    let companyToken = '';
    let customers = [];
    let autoSaveTimer = null;
    let autoSaveChain = Promise.resolve();
    let creditSourceDraft = null;

    const fullCreditReasons = ['01', '02', '06'];
    const creditReasonDefaults = {
        '01': 'Operación anulada a solicitud del cliente.',
        '02': 'Operación anulada por error en el RUC del cliente.',
        '03': 'Corrección de la descripción del concepto.',
        '04': 'Descuento global aplicado a la operación.',
        '05': 'Descuento aplicado a uno o más conceptos.',
        '06': 'Devolución total de los productos o servicios.',
        '07': 'Devolución parcial de productos o servicios.',
        '09': 'Disminución acordada en el valor de la operación.',
        '10': 'Ajuste de la operación por otros conceptos.',
    };

    const money = (value, currency = 'PEN') => new Intl.NumberFormat('es-PE', {
        style: 'currency',
        currency,
        minimumFractionDigits: 2,
    }).format(Number(value || 0));

    const escapeHtml = (value) => {
        const element = document.createElement('span');
        element.textContent = value ?? '';
        return element.innerHTML.replace(/"/g, '&quot;');
    };

    const initials = (name) => String(name || 'FY')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase();

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
                ...(companyToken ? { Authorization: `Bearer ${companyToken}` } : {}),
                ...(options.headers || {}),
            },
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            const validation = payload.errors ? Object.values(payload.errors).flat().join(' ') : '';
            throw new Error(validation || payload.data?.error || payload.message || 'No pudimos completar la operación.');
        }
        return payload.data ?? payload;
    };

    const setStep = (active) => {
        const order = ['source', 'review', 'sunat'];
        const current = order.indexOf(active);
        document.querySelectorAll('.conveyor li').forEach((element, index) => {
            element.classList.toggle('is-active', index === current);
            element.classList.toggle('is-done', index < current);
        });
    };

    const renderCompany = (value) => {
        company = value;
        document.querySelector('#active-company-name').textContent = company.trade_name || company.legal_name;
        document.querySelector('#active-company-meta').textContent = `RUC ${company.ruc} · ${company.sunat_driver === 'fake' ? 'Simulación' : `SUNAT ${company.sunat_environment}`}`;
        document.querySelector('#company-avatar').textContent = initials(company.trade_name || company.legal_name);
        document.querySelector('#app-content').classList.add('is-ready');
        accessGate.classList.add('is-hidden');
        accessError.hidden = true;
    };

    const connectCompany = async (token) => {
        companyToken = token.trim();
        if (!companyToken) throw new Error('Escribe la clave de tu empresa.');
        const connected = await api('/api/company');
        sessionStorage.setItem('facturaya_company_token', companyToken);
        renderCompany(connected);
        await loadCustomers();
        await loadRecent();
    };

    const loadCustomers = async () => {
        customers = await api('/api/customers');
        const selected = savedCustomer.value;
        savedCustomer.innerHTML = '<option value="">Escribir un cliente nuevo</option>';
        customers.forEach((customer) => {
            const option = document.createElement('option');
            option.value = customer.id;
            option.textContent = `${customer.name} · RUC ${customer.ruc}`;
            savedCustomer.appendChild(option);
        });
        savedCustomer.value = customers.some((customer) => customer.id === selected) ? selected : '';
    };

    const findCustomerByRuc = (ruc) => customers.find((customer) => customer.ruc === String(ruc || '').trim());

    const syncCustomerPrompt = () => {
        const ruc = form.elements.customer_ruc.value.trim();
        const existing = findCustomerByRuc(ruc);
        if (existing) {
            saveCustomerChoice.hidden = true;
            customerSaveStatus.textContent = 'Este cliente ya está guardado para esta empresa.';
            customerSaveStatus.hidden = false;
            saveCustomerCheckbox.checked = false;
            return;
        }

        customerSaveStatus.hidden = true;
        saveCustomerChoice.hidden = !/^\d{11}$/.test(ruc) || form.elements.customer_name.value.trim() === '';
    };

    const saveCustomerIfRequested = async () => {
        if (!saveCustomerCheckbox.checked) return;

        const customer = await api('/api/customers', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ruc: form.elements.customer_ruc.value.trim(),
                name: form.elements.customer_name.value.trim(),
            }),
        });

        await loadCustomers();
        savedCustomer.value = customer.id;
        saveCustomerChoice.hidden = true;
        saveCustomerCheckbox.checked = false;
        customerSaveStatus.textContent = 'Cliente guardado para la próxima ocasión.';
        customerSaveStatus.hidden = false;
    };

    const disconnectCompany = () => {
        sessionStorage.removeItem('facturaya_company_token');
        companyToken = '';
        company = null;
        companyTokenInput.value = '';
        accessGate.classList.remove('is-hidden');
        document.querySelector('#app-content').classList.remove('is-ready');
        setTimeout(() => companyTokenInput.focus(), 80);
    };

    const createItemRow = (item = {}) => {
        const row = document.createElement('tr');
        row.dataset.confidence = item.confidence ?? '';
        row.dataset.sourcePage = item.source_page ?? '';
        row.innerHTML = `
            <td><input class="item-input description" aria-label="Descripción" maxlength="500" value="${escapeHtml(item.description || '')}"></td>
            <td><input class="item-input quantity" aria-label="Cantidad" type="number" min="0.001" step="0.001" value="${item.quantity ?? 1}"></td>
            <td><input class="item-input price" aria-label="Precio unitario" type="number" min="0" step="0.01" value="${item.unit_price ?? 0}"></td>
            <td class="line-total">${money(item.line_total || 0, draft?.currency)}</td>
            <td><button class="remove-item" type="button" aria-label="Eliminar concepto">×</button></td>
        `;
        row.querySelectorAll('.item-input').forEach((input) => input.addEventListener('input', () => {
            refreshPreviewTotals();
            scheduleAutoSave();
        }));
        row.querySelector('.remove-item').addEventListener('click', () => {
            if (itemsBody.children.length > 1) {
                row.remove();
                refreshPreviewTotals();
                scheduleAutoSave();
            }
        });
        return row;
    };

    const renderDraft = (value) => {
        draft = value;
        form.elements.customer_ruc.value = draft.customer.ruc || '';
        form.elements.customer_name.value = draft.customer.name || '';
        form.elements.issue_date.value = draft.issue_date || '';
        form.querySelectorAll('input[name="tax_mode"]').forEach((input) => {
            input.checked = input.value === draft.tax_mode;
        });
        const savedDraftCustomer = customers.find((customer) => customer.ruc === draft.customer.ruc);
        savedCustomer.value = savedDraftCustomer?.id || '';
        emptyPreview.hidden = true;
        loadingPreview.hidden = true;
        draftPreview.hidden = false;
        document.querySelector('#preview-title').textContent = 'Revisa los datos extraídos';
        document.querySelector('#summary-client').textContent = draft.customer.name;
        document.querySelector('#summary-ruc').textContent = `RUC ${draft.customer.ruc} · ${draft.issue_date}`;
        document.querySelector('#summary-company').textContent = `Emisor: ${draft.company.legal_name} · RUC ${draft.company.ruc}`;
        syncCustomerPrompt();
        draftStatus.textContent = draft.status === 'issued' ? 'Emitida' : 'Lista para revisar';
        draftStatus.className = `status-badge ${draft.status === 'issued' ? 'is-issued' : 'is-ready'}`;

        itemsBody.innerHTML = '';
        draft.items.forEach((item) => itemsBody.appendChild(createItemRow(item)));
        document.querySelector('#subtotal').textContent = money(draft.totals.subtotal, draft.currency);
        document.querySelector('#igv').textContent = money(draft.totals.igv, draft.currency);
        document.querySelector('#total').textContent = money(draft.totals.total, draft.currency);

        const warningBox = document.querySelector('#warnings');
        warningBox.hidden = !draft.warnings?.length;
        warningBox.innerHTML = (draft.warnings || []).map((warning) => `<p>⚑ ${escapeHtml(warning)}</p>`).join('');
        setAutosaveStatus('Los cambios se guardan automáticamente');
        setStep(draft.status === 'issued' ? 'sunat' : 'review');
        issueButton.disabled = draft.status === 'issued';
    };

    const resetInvoice = () => {
        draft = null;
        clearTimeout(autoSaveTimer);
        form.reset();
        fileName.textContent = 'Opcional si escribes los productos · PDF, JPG, PNG o WEBP · máximo 12 MB';
        updateTextCount();
        itemsBody.innerHTML = '';
        emptyPreview.hidden = false;
        loadingPreview.hidden = true;
        draftPreview.hidden = true;
        document.querySelector('#preview-title').textContent = 'Tu factura aparecerá aquí';
        draftStatus.textContent = 'Esperando productos';
        draftStatus.className = 'status-badge';
        setAutosaveStatus('Los cambios se guardan automáticamente');
        issueButton.disabled = false;
        savedCustomer.value = '';
        saveCustomerCheckbox.checked = false;
        saveCustomerChoice.hidden = true;
        customerSaveStatus.hidden = true;
        showNotice('');
        setStep('source');
        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        document.querySelector('#page-title').scrollIntoView({ behavior: reducedMotion ? 'auto' : 'smooth', block: 'start' });
    };

    const collectDraft = () => ({
        customer_ruc: form.elements.customer_ruc.value,
        customer_name: form.elements.customer_name.value,
        issue_date: form.elements.issue_date.value,
        tax_mode: form.elements.tax_mode.value,
        currency: draft.currency,
        items: [...itemsBody.querySelectorAll('tr')].map((row) => ({
            description: row.querySelector('.description').value,
            quantity: row.querySelector('.quantity').value,
            unit_price: row.querySelector('.price').value,
            confidence: row.dataset.confidence || null,
            source_page: row.dataset.sourcePage || null,
        })),
    });

    const roundMoney = (value) => Math.round((value + Number.EPSILON) * 100) / 100;

    const calculateLine = (quantity, unitPrice, taxMode) => {
        if (taxMode === 'included') {
            const total = roundMoney(quantity * unitPrice);
            const base = roundMoney(total / 1.18);
            return { base, igv: roundMoney(total - base), total };
        }

        const base = roundMoney(quantity * unitPrice);
        const igv = roundMoney(base * 0.18);
        return { base, igv, total: roundMoney(base + igv) };
    };

    const refreshPreviewTotals = () => {
        if (!draft) return;

        let subtotal = 0;
        let igv = 0;
        let total = 0;
        const taxMode = form.elements.tax_mode.value;

        [...itemsBody.querySelectorAll('tr')].forEach((row) => {
            const quantityValue = row.querySelector('.quantity').value;
            const priceValue = row.querySelector('.price').value;
            const quantity = Number(quantityValue);
            const unitPrice = Number(priceValue);
            const lineTotalCell = row.querySelector('.line-total');

            if (quantityValue === '' || priceValue === '' || !Number.isFinite(quantity) || quantity <= 0 || !Number.isFinite(unitPrice) || unitPrice < 0) {
                lineTotalCell.textContent = '—';
                return;
            }

            let lineBase;
            let lineIgv;
            let lineTotal;

            if (taxMode === 'included') {
                lineTotal = roundMoney(quantity * unitPrice);
                lineBase = roundMoney(lineTotal / 1.18);
                lineIgv = roundMoney(lineTotal - lineBase);
            } else {
                lineBase = roundMoney(quantity * unitPrice);
                lineIgv = roundMoney(lineBase * 0.18);
                lineTotal = roundMoney(lineBase + lineIgv);
            }

            subtotal += lineBase;
            igv += lineIgv;
            total += lineTotal;
            lineTotalCell.textContent = money(lineTotal, draft.currency);
        });

        document.querySelector('#subtotal').textContent = money(roundMoney(subtotal), draft.currency);
        document.querySelector('#igv').textContent = money(roundMoney(igv), draft.currency);
        document.querySelector('#total').textContent = money(roundMoney(total), draft.currency);
    };

    const reviewFieldsAreValid = () => {
        const customerIsValid = /^\d{11}$/.test(form.elements.customer_ruc.value)
            && form.elements.customer_name.value.trim() !== ''
            && form.elements.issue_date.value !== '';
        const itemsAreValid = [...itemsBody.querySelectorAll('tr')].every((row) => {
            const quantityValue = row.querySelector('.quantity').value;
            const priceValue = row.querySelector('.price').value;
            const quantity = Number(quantityValue);
            const unitPrice = Number(priceValue);

            return row.querySelector('.description').value.trim() !== ''
                && quantityValue !== '' && priceValue !== ''
                && Number.isFinite(quantity) && quantity > 0
                && Number.isFinite(unitPrice) && unitPrice >= 0;
        });

        return customerIsValid && itemsAreValid;
    };

    const setAutosaveStatus = (message, state = 'saved') => {
        const icon = state === 'saving' ? '…' : state === 'error' ? '!' : '✓';
        autosaveStatus.className = `autosave-status ${state === 'saved' ? '' : `is-${state}`}`.trim();
        autosaveStatus.innerHTML = `<span aria-hidden="true">${icon}</span> ${message}`;
    };

    const applySavedDraft = (updated) => {
        draft = updated;
        [...itemsBody.querySelectorAll('tr')].forEach((row, index) => {
            if (updated.items[index]) row.querySelector('.line-total').textContent = money(updated.items[index].line_total, updated.currency);
        });
        document.querySelector('#subtotal').textContent = money(updated.totals.subtotal, updated.currency);
        document.querySelector('#igv').textContent = money(updated.totals.igv, updated.currency);
        document.querySelector('#total').textContent = money(updated.totals.total, updated.currency);
    };

    const saveDraft = async () => {
        if (!draft) throw new Error('Primero interpreta los productos.');
        const updated = await api(`/api/invoice-drafts/${draft.id}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(collectDraft()),
        });
        applySavedDraft(updated);
        return updated;
    };

    const scheduleAutoSave = () => {
        if (!draft) return;
        clearTimeout(autoSaveTimer);

        if (!reviewFieldsAreValid()) {
            setAutosaveStatus('Completa el dato para poder guardarlo', 'error');
            return;
        }

        setAutosaveStatus('Cambios pendientes…', 'saving');
        autoSaveTimer = setTimeout(() => {
            autoSaveChain = autoSaveChain
                .catch(() => {})
                .then(async () => {
                    setAutosaveStatus('Guardando…', 'saving');
                    await saveDraft();
                    setAutosaveStatus('Cambios guardados');
                })
                .catch(() => setAutosaveStatus('No se pudieron guardar los cambios', 'error'));
        }, 650);
    };

    const flushAutoSave = async () => {
        clearTimeout(autoSaveTimer);
        await autoSaveChain.catch(() => {});

        if (!reviewFieldsAreValid()) throw new Error('Completa correctamente todos los conceptos antes de emitir.');

        setAutosaveStatus('Guardando…', 'saving');
        const updated = await saveDraft();
        setAutosaveStatus('Cambios guardados');
        return updated;
    };

    const statusLabel = (item) => {
        if (item.invoice?.status === 'accepted') return ['Aceptada', 'accepted'];
        if (item.invoice?.status === 'rejected') return ['Rechazada', 'rejected'];
        const labels = {
            review_required: 'Por revisar',
            issued: 'Emitida',
            issue_failed: 'Con error',
            analyzing: 'Analizando',
            failed: 'No procesada',
        };
        return [labels[item.status] || item.status, item.status];
    };

    const sunatResultMessage = (invoice) => {
        if (String(invoice.sunat?.code) === '0111') {
            return `${invoice.number}: SUNAT bloqueó el envío porque el usuario SOL no tiene el perfil “Envío de documentos electrónicos-Grandes emisores”. Configura un usuario SOL secundario con ese perfil y vuelve a intentarlo.`;
        }

        return `${invoice.number}: ${invoice.sunat?.message || 'SUNAT no devolvió un mensaje.'}`;
    };

    const closeCreditNote = () => {
        creditDrawer.hidden = true;
        creditSourceDraft = null;
        creditItemsBody.innerHTML = '';
        creditError.hidden = true;
        document.body.classList.remove('no-scroll');
    };

    const refreshCreditTotals = () => {
        if (!creditSourceDraft) return;
        let subtotal = 0;
        let igv = 0;
        let total = 0;
        let selected = 0;

        [...creditItemsBody.querySelectorAll('tr')].forEach((row) => {
            const checkbox = row.querySelector('.credit-item-check');
            const active = checkbox.checked;
            row.classList.toggle('is-excluded', !active);
            row.querySelectorAll('input[type="number"]').forEach((input) => { input.disabled = !active || fullCreditReasons.includes(creditReasonCode.value); });
            if (!active) return;

            const quantity = Number(row.querySelector('.credit-quantity').value);
            const unitPrice = Number(row.querySelector('.credit-price').value);
            const line = calculateLine(quantity, unitPrice, creditSourceDraft.tax_mode);
            row.querySelector('.credit-line-total').textContent = money(line.total, creditSourceDraft.currency);
            subtotal += line.base;
            igv += line.igv;
            total += line.total;
            selected += 1;
        });

        document.querySelector('#credit-subtotal').textContent = money(roundMoney(subtotal), creditSourceDraft.currency);
        document.querySelector('#credit-igv').textContent = money(roundMoney(igv), creditSourceDraft.currency);
        document.querySelector('#credit-total').textContent = money(roundMoney(total), creditSourceDraft.currency);
        document.querySelector('#credit-selection-label').textContent = fullCreditReasons.includes(creditReasonCode.value)
            ? 'Factura completa'
            : `${selected} ${selected === 1 ? 'concepto seleccionado' : 'conceptos seleccionados'}`;
    };

    const refreshCreditMode = () => {
        const full = fullCreditReasons.includes(creditReasonCode.value);
        const help = document.querySelector('#credit-mode-help');
        help.textContent = full
            ? 'Este motivo toma todos los conceptos e importes de la factura original.'
            : 'Selecciona los conceptos y ajusta la cantidad o el precio sin superar los valores originales.';

        [...creditItemsBody.querySelectorAll('tr')].forEach((row) => {
            const checkbox = row.querySelector('.credit-item-check');
            if (full) checkbox.checked = true;
            checkbox.disabled = full;
        });
        refreshCreditTotals();
    };

    const renderCreditItems = () => {
        creditItemsBody.innerHTML = creditSourceDraft.items.map((item) => `
            <tr data-item-id="${item.id}">
                <td><input class="credit-item-check" type="checkbox" checked aria-label="Incluir ${escapeHtml(item.description)}"></td>
                <td><strong>${escapeHtml(item.description)}</strong><small>Máx. ${escapeHtml(item.quantity)} × ${money(item.unit_price, creditSourceDraft.currency)}</small></td>
                <td><input class="credit-number credit-quantity" type="number" min="0.001" max="${item.quantity}" step="0.001" value="${item.quantity}" aria-label="Cantidad a acreditar" required></td>
                <td><input class="credit-number credit-price" type="number" min="0" max="${item.unit_price}" step="0.01" value="${item.unit_price}" aria-label="Precio a acreditar" required></td>
                <td class="credit-line-total">${money(item.line_total, creditSourceDraft.currency)}</td>
            </tr>
        `).join('');

        creditItemsBody.querySelectorAll('input').forEach((input) => input.addEventListener('input', refreshCreditTotals));
        refreshCreditMode();
    };

    const openCreditNote = async (draftId) => {
        showNotice('');
        const source = await api(`/api/invoice-drafts/${draftId}`);
        if (!source.invoice || source.invoice.status !== 'accepted') throw new Error('La factura todavía no está aceptada por SUNAT.');

        creditSourceDraft = source;
        creditForm.reset();
        creditReasonCode.value = '01';
        creditReasonDescription.value = creditReasonDefaults['01'];
        document.querySelector('#credit-invoice-number').textContent = source.invoice.number;
        document.querySelector('#credit-customer').textContent = `${source.customer.name} · RUC ${source.customer.ruc}`;
        document.querySelector('#credit-note-series').textContent = `${company.default_credit_note_series || 'FC01'} · siguiente número`;
        const issueDate = document.querySelector('#credit-issue-date');
        issueDate.min = source.issue_date;
        creditError.hidden = true;
        renderCreditItems();
        creditDrawer.hidden = false;
        document.body.classList.add('no-scroll');
        setTimeout(() => creditReasonCode.focus(), 50);
    };

    const openDraftForReview = async (draftId) => {
        clearTimeout(autoSaveTimer);
        await autoSaveChain.catch(() => {});
        const selectedDraft = await api(`/api/invoice-drafts/${draftId}`);

        if (selectedDraft.status !== 'review_required' || selectedDraft.invoice) {
            throw new Error('Este borrador ya no está disponible para revisión. Actualiza la lista e inténtalo otra vez.');
        }

        renderDraft(selectedDraft);
    };

    const downloadProtectedFile = async (url) => {
        const response = await fetch(url, { headers: { Authorization: `Bearer ${companyToken}` } });
        if (!response.ok) throw new Error('El archivo ya no está disponible.');
        const blob = await response.blob();
        const disposition = response.headers.get('Content-Disposition') || '';
        const filename = disposition.match(/filename="?([^";]+)"?/i)?.[1] || 'documento';
        const objectUrl = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = objectUrl;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(objectUrl);
    };

    async function loadRecent() {
        const recent = await api('/api/invoice-drafts');
        const body = document.querySelector('#recent-body');
        document.querySelector('#recent-count').textContent = `${recent.length} ${recent.length === 1 ? 'registro' : 'registros'}`;
        if (!recent.length) {
            body.innerHTML = '<tr><td colspan="6" class="empty-row">Aún no hay documentos. Tu primera factura aparecerá aquí.</td></tr>';
            return;
        }
        body.innerHTML = recent.map((item) => {
            const [label, status] = statusLabel(item);
            const notes = item.invoice?.credit_notes || [];
            const invoiceFiles = item.invoice?.files || {};
            const invoiceFileList = item.invoice?.status === 'accepted'
                ? `<div class="invoice-file-list">
                    ${invoiceFiles.pdf ? `<button class="document-file-link document-file-pdf" type="button" data-file-url="${escapeHtml(invoiceFiles.pdf)}">PDF</button>` : ''}
                    ${invoiceFiles.xml ? `<button class="document-file-link" type="button" data-file-url="${escapeHtml(invoiceFiles.xml)}">XML</button>` : ''}
                    ${invoiceFiles.cdr ? `<button class="document-file-link" type="button" data-file-url="${escapeHtml(invoiceFiles.cdr)}">CDR</button>` : ''}
                </div>` : '';
            const noteList = notes.length ? `<div class="credit-note-list">${notes.map((note) => `
                <span class="credit-note-chip"><strong>${escapeHtml(note.number)}</strong><small>${escapeHtml(note.status === 'accepted' ? 'NC aceptada' : note.status)}</small>
                    ${note.files?.xml ? `<button class="document-file-link" type="button" data-file-url="${escapeHtml(note.files.xml)}">XML</button>` : ''}
                    ${note.files?.cdr ? `<button class="document-file-link" type="button" data-file-url="${escapeHtml(note.files.cdr)}">CDR</button>` : ''}
                </span>`).join('')}</div>` : '';
            let action = '<span class="action-unavailable">Sin acciones</span>';
            if (item.invoice?.status === 'accepted') {
                action = `<button class="credit-note-trigger" type="button" data-credit-draft-id="${item.id}">+ Nota de crédito</button>`;
            } else if (item.invoice?.status === 'error' && item.status === 'issue_failed') {
                action = `<button class="retry-invoice-trigger" type="button" data-retry-draft-id="${item.id}">Reintentar envío</button>`;
            } else if (item.status === 'review_required' && !item.invoice) {
                action = `<button class="review-draft-trigger" type="button" data-review-draft-id="${item.id}" aria-label="Revisar borrador de ${escapeHtml(item.customer.name)}">Revisar y emitir</button>`;
            }
            return `<tr>
                <td><span class="mono-date">${escapeHtml(item.issue_date)}</span></td>
                <td><strong>${escapeHtml(item.customer.name)}</strong><small>RUC ${escapeHtml(item.customer.ruc)}</small></td>
                <td><strong>${escapeHtml(item.invoice?.number || 'Borrador')}</strong>${invoiceFileList}${noteList}</td>
                <td><strong>${money(item.totals.total, item.currency)}</strong></td>
                <td><span class="activity-status status-${escapeHtml(status)}">${escapeHtml(label)}</span></td>
                <td>${action}</td>
            </tr>`;
        }).join('');
    }

    document.querySelector('#recent-body').addEventListener('click', async (event) => {
        const creditTrigger = event.target.closest('[data-credit-draft-id]');
        const reviewTrigger = event.target.closest('[data-review-draft-id]');
        const retryTrigger = event.target.closest('[data-retry-draft-id]');
        const fileButton = event.target.closest('[data-file-url]');
        try {
            if (retryTrigger) {
                setBusy(retryTrigger, true, 'Reintentando…');
                const invoice = await api(`/api/invoice-drafts/${retryTrigger.dataset.retryDraftId}/issue`, { method: 'POST' });
                await loadRecent();
                showNotice(sunatResultMessage(invoice), invoice.status === 'accepted' ? 'success' : 'error');
            } else if (reviewTrigger) {
                setBusy(reviewTrigger, true, 'Abriendo…');
                await openDraftForReview(reviewTrigger.dataset.reviewDraftId);
                showNotice('Borrador abierto. Revisa los datos y pulsa “Emitir factura” cuando todo esté correcto.', 'success');
                const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                draftPreview.scrollIntoView({ behavior: reducedMotion ? 'auto' : 'smooth', block: 'start' });
                setBusy(reviewTrigger, false, '');
            } else if (creditTrigger) {
                creditTrigger.disabled = true;
                await openCreditNote(creditTrigger.dataset.creditDraftId);
                creditTrigger.disabled = false;
            } else if (fileButton) {
                fileButton.disabled = true;
                await downloadProtectedFile(fileButton.dataset.fileUrl);
                fileButton.disabled = false;
            }
        } catch (error) {
            if (retryTrigger) setBusy(retryTrigger, false, '');
            if (reviewTrigger) setBusy(reviewTrigger, false, '');
            if (creditTrigger) creditTrigger.disabled = false;
            if (fileButton) fileButton.disabled = false;
            showNotice(error.message, 'error');
        }
    });

    creditReasonCode.addEventListener('change', () => {
        creditReasonDescription.value = creditReasonDefaults[creditReasonCode.value] || '';
        refreshCreditMode();
    });
    document.querySelector('#close-credit-note').addEventListener('click', closeCreditNote);
    document.querySelector('#cancel-credit-note').addEventListener('click', closeCreditNote);
    creditDrawer.addEventListener('click', (event) => { if (event.target === creditDrawer) closeCreditNote(); });

    creditForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        creditError.hidden = true;
        const items = [...creditItemsBody.querySelectorAll('tr')]
            .filter((row) => row.querySelector('.credit-item-check').checked)
            .map((row) => ({
                invoice_draft_item_id: Number(row.dataset.itemId),
                quantity: row.querySelector('.credit-quantity').value,
                unit_price: row.querySelector('.credit-price').value,
            }));

        if (!items.length) {
            creditError.textContent = 'Selecciona al menos un concepto para la nota.';
            creditError.hidden = false;
            return;
        }

        setBusy(creditIssueButton, true, 'Enviando a SUNAT…');
        try {
            const note = await api(`/api/invoices/${creditSourceDraft.invoice.id}/credit-notes`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    issue_date: document.querySelector('#credit-issue-date').value,
                    reason_code: creditReasonCode.value,
                    reason_description: creditReasonDescription.value,
                    items,
                }),
            });
            closeCreditNote();
            await loadRecent();
            showNotice(`${note.number}: ${note.sunat.message}`, note.status === 'accepted' ? 'success' : 'error');
        } catch (error) {
            creditError.textContent = error.message;
            creditError.hidden = false;
        } finally {
            setBusy(creditIssueButton, false, '');
        }
    });

    accessForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        accessError.hidden = true;
        setBusy(connectButton, true, 'Verificando…');
        try {
            await connectCompany(companyTokenInput.value);
        } catch (error) {
            companyToken = '';
            accessError.textContent = error.message;
            accessError.hidden = false;
        } finally {
            setBusy(connectButton, false, '');
        }
    });

    document.querySelector('#use-demo-token').addEventListener('click', async () => {
        companyTokenInput.value = 'fya_demo_local_token';
        accessForm.requestSubmit();
    });

    document.querySelector('#company-switcher').addEventListener('click', disconnectCompany);
    document.querySelector('#new-invoice-button').addEventListener('click', resetInvoice);

    const updateTextCount = () => {
        productsTextCount.textContent = `${productsText.value.length.toLocaleString('es-PE')} / 10,000`;
    };

    productsText.addEventListener('input', updateTextCount);

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        showNotice('');
        const hasText = productsText.value.trim().length > 0;
        const hasFile = fileInput.files.length > 0;

        if (!hasText && !hasFile) {
            showNotice('Escribe los productos, adjunta un archivo o usa ambas opciones.', 'error');
            productsText.focus();
            return;
        }

        const busyLabel = hasText && hasFile ? 'Combinando fuentes…' : hasText ? 'Interpretando texto…' : 'Leyendo documento…';
        const successLabel = hasText && hasFile ? 'Texto y archivo combinados' : hasText ? 'Texto interpretado' : 'Documento leído';
        const formData = new FormData(form);
        emptyPreview.hidden = true;
        loadingPreview.hidden = false;
        draftPreview.hidden = true;
        document.querySelector('#preview-title').textContent = 'Interpretando productos…';
        draftStatus.textContent = 'Procesando';
        draftStatus.className = 'status-badge';
        productsText.disabled = true;
        fileInput.disabled = true;
        setBusy(analyzeButton, true, busyLabel);
        setStep('review');
        try {
            const result = await api('/api/invoice-drafts/import', { method: 'POST', body: formData });
            renderDraft(result);
            await loadRecent();
            showNotice(`${successLabel}. Revisa conceptos, cantidades y precios antes de emitir.`, 'success');
        } catch (error) {
            loadingPreview.hidden = true;
            emptyPreview.hidden = false;
            document.querySelector('#preview-title').textContent = 'Tu factura aparecerá aquí';
            draftStatus.textContent = 'Esperando productos';
            setStep('source');
            showNotice(error.message, 'error');
        } finally {
            setBusy(analyzeButton, false, '');
            productsText.disabled = false;
            fileInput.disabled = false;
        }
    });

    issueButton.addEventListener('click', async () => {
        showNotice('');
        setBusy(issueButton, true, 'Enviando…');
        try {
            await flushAutoSave();
            await saveCustomerIfRequested();
            setStep('sunat');
            const invoice = await api(`/api/invoice-drafts/${draft.id}/issue`, { method: 'POST' });
            draft.status = invoice.status === 'accepted' ? 'issued' : 'issue_failed';
            draftStatus.textContent = invoice.status === 'accepted' ? 'Emitida' : invoice.status;
            draftStatus.className = `status-badge ${invoice.status === 'accepted' ? 'is-issued' : ''}`;
            issueButton.disabled = invoice.status === 'accepted';
            await loadRecent();
            showNotice(sunatResultMessage(invoice), invoice.status === 'accepted' ? 'success' : 'error');
        } catch (error) {
            setStep('review');
            showNotice(error.message, 'error');
        } finally {
            setBusy(issueButton, false, '');
            issueButton.disabled = draft?.status === 'issued';
        }
    });

    document.querySelector('#add-item').addEventListener('click', () => {
        itemsBody.appendChild(createItemRow());
        refreshPreviewTotals();
        scheduleAutoSave();
    });
    ['customer_ruc', 'customer_name', 'issue_date'].forEach((name) => {
        form.elements[name].addEventListener('input', () => {
            if (name !== 'issue_date') syncCustomerPrompt();
            scheduleAutoSave();
        });
    });
    savedCustomer.addEventListener('change', () => {
        const selected = customers.find((customer) => customer.id === savedCustomer.value);
        if (!selected) return;
        form.elements.customer_ruc.value = selected.ruc;
        form.elements.customer_name.value = selected.name;
        syncCustomerPrompt();
        if (draft) scheduleAutoSave();
    });
    form.querySelectorAll('input[name="tax_mode"]').forEach((input) => input.addEventListener('change', () => {
        refreshPreviewTotals();
        scheduleAutoSave();
    }));
    fileInput.addEventListener('change', () => { fileName.textContent = fileInput.files[0]?.name || 'Opcional si escribes los productos · PDF, JPG, PNG o WEBP · máximo 12 MB'; });

    ['dragenter', 'dragover'].forEach((eventName) => dropzone.addEventListener(eventName, (event) => {
        event.preventDefault();
        dropzone.classList.add('is-dragging');
    }));

    ['dragleave', 'drop'].forEach((eventName) => dropzone.addEventListener(eventName, (event) => {
        event.preventDefault();
        dropzone.classList.remove('is-dragging');
    }));

    dropzone.addEventListener('drop', (event) => {
        if (!event.dataTransfer.files.length) return;
        const transfer = new DataTransfer();
        transfer.items.add(event.dataTransfer.files[0]);
        fileInput.files = transfer.files;
        fileInput.dispatchEvent(new Event('change'));
    });

    updateTextCount();

    const savedCompanyToken = sessionStorage.getItem('facturaya_company_token');
    if (savedCompanyToken) {
        companyTokenInput.value = savedCompanyToken;
        connectCompany(savedCompanyToken).catch(() => disconnectCompany());
    } else {
        setTimeout(() => companyTokenInput.focus(), 80);
    }
}
