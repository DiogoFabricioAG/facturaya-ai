<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Crea, revisa y envía a SUNAT guías de remisión remitente vinculadas a tus facturas.">
    <title>Guías de remisión · FacturaYa AI</title>
    @vite(['resources/css/app.css', 'resources/js/despatches.js'])
</head>
<body data-page="despatches">
    <section id="access-gate" class="access-gate" aria-labelledby="access-title">
        <div class="access-visual" aria-hidden="true">
            <div class="access-brand"><span class="brand-mark">F</span><span>FacturaYa <em>AI</em></span></div>
            <div class="route-poster">
                <span class="route-origin">PARTIDA</span>
                <div class="route-line"><i></i><i></i><i></i><i></i></div>
                <span class="route-destination">LLEGADA</span>
            </div>
            <p>Cada traslado, con su guía revisada.</p>
        </div>
        <div class="access-form-panel">
            <p class="eyebrow">Espacio de empresa</p>
            <h1 id="access-title">Entra con la clave de tu empresa.</h1>
            <p>Esta clave selecciona automáticamente el RUC, certificado, serie y configuración SUNAT del emisor.</p>
            <form id="company-access-form">
                <label class="field">
                    <span>Clave de empresa</span>
                    <input id="company-token" type="password" placeholder="fya_••••••••••••••••" autocomplete="off" required>
                </label>
                <button id="connect-company" class="primary-button" type="submit"><span>Entrar al espacio</span><svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4 10h12m-4-4 4 4-4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
            </form>
            <button id="use-demo-token" class="demo-link" type="button">Usar empresa demostración</button>
            <p id="access-error" class="access-error" role="alert" hidden></p>
            <p class="access-footnote">La clave permanece únicamente en esta pestaña del navegador.</p>
        </div>
    </section>

    <div class="page-shell app-content" id="app-content">
        <header class="topbar">
            <a class="brand" href="/" aria-label="FacturaYa AI, inicio"><span class="brand-mark" aria-hidden="true">F</span><span>FacturaYa <em>AI</em></span></a>
            <nav class="product-nav" aria-label="Navegación principal">
                <a href="/">Emitir comprobante</a>
                <a class="is-active" href="/guias">Guías de remisión</a>
                <a href="/platform">Empresas</a>
            </nav>
            <button id="company-switcher" class="company-switcher" type="button" title="Cambiar de empresa">
                <span class="company-avatar" id="company-avatar">FY</span>
                <span><strong id="active-company-name">Empresa</strong><small id="active-company-meta">Conectando…</small></span>
                <svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="m4 6 4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
        </header>

        <main>
            <section class="intro intro-with-action" aria-labelledby="page-title">
                <div>
                    <p class="eyebrow">Guía de remisión remitente</p>
                    <h1 id="page-title">Cada traslado, <span>con su guía revisada.</span></h1>
                    <p class="intro-copy">Completa los datos del traslado tú mismo, sin interpretación automática. Revisas la guía completa y solo se envía a SUNAT cuando la confirmas.</p>
                </div>
                <button id="new-guide-button" class="outline-action" type="button">+ Nueva guía</button>
            </section>

            <ol class="conveyor" aria-label="Proceso de la guía">
                <li class="is-active" data-step="source"><span>01</span><strong>Datos</strong></li>
                <li data-step="review"><span>02</span><strong>Revisión</strong></li>
                <li data-step="sunat"><span>03</span><strong>SUNAT</strong></li>
            </ol>

            <div id="notice" class="notice" role="status" aria-live="polite" hidden></div>

            <section class="workspace" aria-label="Nueva guía de remisión">
                <form id="guide-form" class="capture-panel gre-form">
                    <div class="section-heading">
                        <span class="section-number">1</span>
                        <div><h2>¿Qué factura respalda el traslado?</h2><p>El destinatario de la guía es el cliente de esa factura.</p></div>
                    </div>
                    <label class="field">
                        <span>Factura aceptada</span>
                        <select id="gre-invoice" name="invoice_id" required>
                            <option value="">Conecta una empresa para ver sus facturas</option>
                        </select>
                        <small class="field-help">Solo facturas aceptadas por SUNAT con cliente RUC, en el entorno actual de la empresa.</small>
                    </label>
                    <div id="gre-recipient" class="client-summary gre-recipient" hidden>
                        <span>Destinatario</span>
                        <strong id="gre-recipient-name"></strong>
                        <small id="gre-recipient-ruc"></small>
                        <small id="gre-recipient-guides" class="summary-company" hidden></small>
                    </div>

                    <div class="divider"></div>
                    <div class="section-heading compact">
                        <span class="section-number">2</span>
                        <div><h2>Fechas y vehículo</h2><p>Motivo venta · transporte privado · serie T001.</p></div>
                    </div>
                    <div class="form-grid">
                        <label class="field">
                            <span>Fecha de emisión</span>
                            <input id="gre-issue-date" name="issue_date" type="date" required>
                        </label>
                        <label class="field">
                            <span>Inicio del traslado</span>
                            <input id="gre-transfer-date" name="transfer_date" type="date" required>
                        </label>
                        <label class="field field-wide">
                            <span>Vehículo según su tarjeta de propiedad</span>
                            <select id="gre-vehicle-category" name="vehicle_category" required>
                                <option value="">Selecciona la categoría del vehículo</option>
                                <option value="M1">Categoría M1 (auto o camioneta de hasta 8 asientos)</option>
                                <option value="L">Categoría L (moto o mototaxi)</option>
                                <option value="other">Otra categoría (camión, furgón…): requiere placa y conductor</option>
                            </select>
                            <small class="field-help">Con M1 o L la guía no lleva placa ni conductor. Revisa la categoría en la tarjeta del vehículo.</small>
                        </label>
                    </div>
                    <fieldset id="gre-transport-fields" class="gre-transport" hidden disabled>
                        <legend>Vehículo y conductor</legend>
                        <div class="form-grid">
                            <label class="field"><span>Placa</span><input name="plate" maxlength="7" placeholder="ABC123" autocomplete="off" required><small class="field-help">6 caracteres, sin guion.</small></label>
                            <label class="field"><span>DNI del conductor</span><input name="driver_dni" inputmode="numeric" pattern="[0-9]{8}" maxlength="8" placeholder="12345678" autocomplete="off" required></label>
                            <label class="field"><span>Nombres</span><input name="driver_first_name" maxlength="100" autocomplete="off" required></label>
                            <label class="field"><span>Apellidos</span><input name="driver_last_name" maxlength="100" autocomplete="off" required></label>
                            <label class="field field-wide"><span>Licencia de conducir</span><input name="driver_license" maxlength="15" placeholder="Q12345678" autocomplete="off" required></label>
                        </div>
                    </fieldset>

                    <div class="divider"></div>
                    <div class="section-heading compact">
                        <span class="section-number">3</span>
                        <div><h2>Recorrido</h2><p>Ubigeo INEI de 6 dígitos y la dirección como debe figurar en la guía.</p></div>
                    </div>
                    <div class="form-grid gre-route">
                        <label class="field"><span>Ubigeo de partida</span><input name="origin_ubigeo" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="150132" autocomplete="off" required></label>
                        <label class="field"><span>Dirección de partida</span><input name="origin_address" maxlength="500" placeholder="Av., Mz., Lote, referencia" required></label>
                        <label class="field"><span>Ubigeo de llegada</span><input name="destination_ubigeo" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="090402" autocomplete="off" required></label>
                        <label class="field"><span>Dirección de llegada</span><input name="destination_address" maxlength="500" placeholder="Almacén, obra o local de entrega" required></label>
                    </div>

                    <div class="divider"></div>
                    <div class="section-heading compact">
                        <span class="section-number">4</span>
                        <div><h2>Bienes trasladados</h2><p>Cantidades y unidades reales del traslado; no se calculan desde importes.</p></div>
                    </div>
                    <label class="field gre-weight">
                        <span>Peso bruto total (kg)</span>
                        <input name="weight_kg" inputmode="decimal" pattern="[0-9]{1,9}([.][0-9]{1,3})?" placeholder="140" autocomplete="off" required>
                    </label>
                    <div class="table-scroll">
                        <table class="items-table gre-items">
                            <thead><tr><th>Descripción</th><th>Cant.</th><th>Unidad</th><th><span class="sr-only">Eliminar</span></th></tr></thead>
                            <tbody id="gre-items-body"></tbody>
                        </table>
                    </div>
                    <div class="gre-item-actions">
                        <button id="gre-add-item" class="text-button" type="button">+ Agregar bien</button>
                        <button id="gre-copy-items" class="text-button" type="button" hidden>Copiar bienes de la factura</button>
                    </div>

                    <button id="gre-review-button" class="primary-button" type="submit"><span>Revisar guía</span><svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4 10h12m-4-4 4 4-4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
                    <p class="safety-note"><svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 2.5 16 5v4.6c0 3.6-2.5 6.3-6 7.9-3.5-1.6-6-4.3-6-7.9V5l6-2.5Z" stroke="currentColor" stroke-width="1.5"/><path d="m7.3 10 1.7 1.7 3.8-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>Revisar no envía nada a SUNAT.</p>
                </form>

                <aside class="preview-panel" aria-labelledby="preview-title">
                    <div class="preview-top"><span class="preview-kicker">Vista previa</span><span id="gre-status" class="status-badge">Esperando datos</span></div>
                    <h2 id="preview-title">Tu guía aparecerá aquí</h2>
                    <div id="gre-empty" class="empty-preview">
                        <div class="paper-stack" aria-hidden="true"><span></span><span></span><span></span></div>
                        <p>Completa el formulario y pulsa “Revisar guía”. Aquí verás exactamente lo que se enviará a SUNAT.</p>
                    </div>
                    <div id="gre-review" class="gre-review" hidden>
                        <div class="client-summary">
                            <span>Destinatario</span>
                            <strong id="review-customer"></strong>
                            <small id="review-customer-ruc"></small>
                            <small id="review-environment" class="summary-company"></small>
                        </div>
                        <dl class="gre-summary">
                            <div><dt>Guía</dt><dd id="review-number"></dd></div>
                            <div><dt>Factura</dt><dd id="review-invoice"></dd></div>
                            <div><dt>Emisión</dt><dd id="review-issue-date"></dd></div>
                            <div><dt>Inicio del traslado</dt><dd id="review-transfer-date"></dd></div>
                            <div class="is-wide"><dt>Traslado</dt><dd id="review-transport"></dd></div>
                            <div class="is-wide"><dt>Partida</dt><dd id="review-origin"></dd></div>
                            <div class="is-wide"><dt>Llegada</dt><dd id="review-destination"></dd></div>
                            <div><dt>Peso bruto</dt><dd id="review-weight"></dd></div>
                            <div><dt>Bienes</dt><dd id="review-item-count"></dd></div>
                        </dl>
                        <div class="table-scroll">
                            <table class="items-table gre-items gre-items-readonly">
                                <thead><tr><th>Descripción</th><th>Cant.</th><th>Unidad</th></tr></thead>
                                <tbody id="review-items"></tbody>
                            </table>
                        </div>
                        <p id="review-message" class="gre-message" role="status" hidden></p>
                        <div id="review-confirm" class="gre-confirm" hidden>
                            <label class="gre-confirm-check">
                                <input id="review-confirm-check" type="checkbox">
                                <span id="review-confirm-label">Revisé los datos. Enviar esta guía a SUNAT; después no se puede modificar.</span>
                            </label>
                            <button id="gre-issue-button" class="primary-button" type="button" disabled><span>Emitir guía</span><svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4 10h12m-4-4 4 4-4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
                            <p class="gre-edit-hint">¿Algo no cuadra? Corrige el formulario y vuelve a pulsar “Revisar guía”.</p>
                        </div>
                        <div id="review-followup" class="invoice-file-list gre-followup" hidden>
                            <button id="gre-refresh-button" class="retry-invoice-trigger" type="button" hidden>Consultar estado en SUNAT</button>
                            <button id="gre-xml-button" class="document-file-link" type="button" hidden>XML</button>
                            <button id="gre-cdr-button" class="document-file-link" type="button" hidden>CDR</button>
                        </div>
                    </div>
                </aside>
            </section>

            <section class="recent-section" aria-labelledby="recent-title">
                <div class="recent-heading"><div><p class="eyebrow">Historial</p><h2 id="recent-title">Guías de esta empresa</h2></div><span id="recent-count" class="record-count">0 registros</span></div>
                <div class="recent-table-wrap">
                    <table class="recent-table">
                        <thead><tr><th>Emisión</th><th>Guía</th><th>Factura · destinatario</th><th>Llegada</th><th>Estado</th><th>Acción</th></tr></thead>
                        <tbody id="recent-body"><tr><td colspan="6" class="empty-row">Conecta una empresa para ver sus guías.</td></tr></tbody>
                    </table>
                </div>
            </section>
        </main>

        <footer><span>FacturaYa AI · Guías de remisión remitente</span><span>Greenter {{ \Composer\InstalledVersions::getPrettyVersion('greenter/lite') }}</span></footer>
    </div>

    <script id="gre-units" type="application/json">@json($units)</script>
</body>
</html>
