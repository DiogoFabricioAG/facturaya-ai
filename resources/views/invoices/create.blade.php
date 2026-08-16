<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Convierte texto, imágenes o PDF en una factura electrónica lista para revisar y enviar a SUNAT.">
    <title>Emitir factura · FacturaYa AI</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body data-page="invoice">
    <section id="access-gate" class="access-gate" aria-labelledby="access-title">
        <div class="access-visual" aria-hidden="true">
            <div class="access-brand"><span class="brand-mark">F</span><span>FacturaYa <em>AI</em></span></div>
            <div class="route-poster">
                <span class="route-origin">ENTRADA</span>
                <div class="route-line"><i></i><i></i><i></i><i></i></div>
                <span class="route-destination">SUNAT</span>
            </div>
            <p>Una ruta corta para emitir correctamente.</p>
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
                <a class="is-active" href="/">Emitir factura</a>
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
                    <p class="eyebrow">Nueva factura electrónica</p>
                    <h1 id="page-title">De tus palabras a la factura, <span>en una sola revisión.</span></h1>
                <p class="intro-copy">Escribe los productos como los dirías normalmente, adjunta una cotización o combina ambos. Nosotros los ordenamos; tú conservas la decisión final.</p>
                </div>
                <button id="new-invoice-button" class="outline-action" type="button">+ Nueva factura</button>
            </section>

            <ol class="conveyor" aria-label="Proceso de facturación">
                <li class="is-active" data-step="source"><span>01</span><strong>Entrada</strong></li>
                <li data-step="review"><span>02</span><strong>Revisión</strong></li>
                <li data-step="sunat"><span>03</span><strong>SUNAT</strong></li>
            </ol>

            <div id="notice" class="notice" role="status" aria-live="polite" hidden></div>

            <section class="workspace" aria-label="Nueva factura">
                <form id="import-form" class="capture-panel">
                    <div class="section-heading">
                        <span class="section-number">1</span>
                        <div><h2>¿A quién facturamos?</h2><p>Datos del cliente que recibirá la factura.</p></div>
                    </div>
                    <label class="field saved-customer-field">
                        <span>Cliente guardado</span>
                        <select id="saved-customer" aria-label="Seleccionar un cliente guardado">
                            <option value="">Escribir un cliente nuevo</option>
                        </select>
                        <small id="saved-customer-help">Tus clientes guardados solo están disponibles dentro de esta empresa.</small>
                    </label>
                    <div class="form-grid">
                        <label class="field">
                            <span>RUC del cliente</span>
                            <input name="customer_ruc" inputmode="numeric" pattern="[0-9]{11}" maxlength="11" placeholder="20123456789" autocomplete="off" required>
                            <small>11 dígitos</small>
                        </label>
                        <label class="field field-wide">
                            <span>Razón social</span>
                            <input name="customer_name" maxlength="255" placeholder="Ej. Comercial Andina S.A.C." autocomplete="organization" required>
                        </label>
                        <label class="field">
                            <span>Fecha de emisión</span>
                            <input name="issue_date" type="date" value="{{ now('America/Lima')->format('Y-m-d') }}" required>
                        </label>
                    </div>

                    <div class="divider"></div>
                    <div class="section-heading compact">
                        <span class="section-number">2</span>
                        <div><h2>¿Cómo están escritos tus precios?</h2><p>Esto define cómo calculamos el IGV.</p></div>
                    </div>
                    <div class="tax-choices">
                        <label class="tax-choice">
                            <input type="radio" name="tax_mode" value="included" checked>
                            <span class="choice-box"><span class="choice-check" aria-hidden="true"></span><strong>Ya incluyen IGV</strong><small>Precio ÷ 1.18</small><em>S/ 118 → Base 100 + IGV 18</em></span>
                        </label>
                        <label class="tax-choice">
                            <input type="radio" name="tax_mode" value="excluded">
                            <span class="choice-box"><span class="choice-check" aria-hidden="true"></span><strong>Agregar IGV</strong><small>Precio × 0.18</small><em>S/ 100 → Total 118</em></span>
                        </label>
                    </div>

                    <div class="divider"></div>
                    <div class="section-heading compact">
                        <span class="section-number">3</span>
                        <div><h2>Agrega los productos</h2><p>Escribe, adjunta un archivo o usa ambas opciones para completar la información.</p></div>
                    </div>
                    <div class="source-stack">
                    <div class="source-input-panel">
                        <label class="product-text-field">
                            <span class="source-label"><span class="source-option-icon" aria-hidden="true">Aa</span><span><strong>Escribe lo que vendiste</strong><small>También puedes usar este espacio para completar o corregir el archivo.</small></span></span>
                            <textarea id="products-text" name="products_text" rows="6" minlength="5" maxlength="10000" placeholder="Ej. Vendí 2 laptops Lenovo a S/ 2,500 cada una. En el archivo, cambia el mantenimiento de 2 a 3 meses."></textarea>
                            <span class="textarea-meta"><small>Opcional si adjuntas un archivo.</small><small id="products-text-count">0 / 10,000</small></span>
                        </label>
                    </div>

                    <div class="source-join" aria-hidden="true"><span>y / o</span></div>

                    <div class="source-input-panel">
                        <label id="dropzone" class="dropzone">
                            <input id="document-file" name="file" type="file" accept="application/pdf,image/jpeg,image/png,image/webp">
                            <span class="upload-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M12 16V4m0 0L7.5 8.5M12 4l4.5 4.5M5 14v4a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                            <span><strong>Adjunta un archivo</strong> arrastrándolo aquí o haciendo clic</span>
                            <small id="file-name">Opcional si escribes los productos · PDF, JPG, PNG o WEBP · máximo 12 MB</small>
                        </label>
                    </div>
                    </div>
                    <button id="analyze-button" class="primary-button" type="submit"><span>Interpretar productos</span><svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4 10h12m-4-4 4 4-4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
                    <p class="safety-note"><svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 2.5 16 5v4.6c0 3.6-2.5 6.3-6 7.9-3.5-1.6-6-4.3-6-7.9V5l6-2.5Z" stroke="currentColor" stroke-width="1.5"/><path d="m7.3 10 1.7 1.7 3.8-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>Nada se envía a SUNAT hasta que tú lo confirmes.</p>
                </form>

                <aside class="preview-panel" aria-labelledby="preview-title">
                    <div class="preview-top"><span class="preview-kicker">Vista previa</span><span id="draft-status" class="status-badge">Esperando productos</span></div>
                    <h2 id="preview-title">Tu factura aparecerá aquí</h2>
                    <div id="empty-preview" class="empty-preview">
                        <div class="paper-stack" aria-hidden="true"><span></span><span></span><span></span></div>
                        <p>Interpretaremos conceptos, cantidades y precios. Podrás corregir todo antes de emitir.</p>
                    </div>
                    <div id="loading-preview" class="loading-preview" hidden>
                        <span class="processing-mark" aria-hidden="true"><i></i><i></i><i></i></span>
                        <strong>Organizando los productos</strong>
                        <small>Estamos convirtiendo la entrada en una lista revisable.</small>
                    </div>
                    <div id="draft-preview" class="draft-preview" hidden>
                        <div class="client-summary">
                            <span>Cliente</span>
                            <strong id="summary-client"></strong>
                            <small id="summary-ruc"></small>
                            <small id="summary-company" class="summary-company"></small>
                            <label id="save-customer-choice" class="save-customer-choice">
                                <input id="save-customer" type="checkbox">
                                <span><strong>¿Deseas guardar los datos de este cliente para una próxima ocasión?</strong><small>Se guardará solo para esta empresa.</small></span>
                            </label>
                            <p id="customer-save-status" class="customer-save-status" role="status" hidden></p>
                        </div>
                        <div id="warnings" class="warnings" hidden></div>
                        <div class="table-scroll">
                            <table class="items-table">
                                <thead><tr><th>Descripción</th><th>Cant.</th><th>P. unit.</th><th>Total</th><th><span class="sr-only">Eliminar</span></th></tr></thead>
                                <tbody id="items-body"></tbody>
                            </table>
                        </div>
                        <button id="add-item" class="text-button" type="button">+ Agregar concepto</button>
                        <div class="totals">
                            <div><span>Valor de venta</span><strong id="subtotal">S/ 0.00</strong></div>
                            <div><span>IGV (18%)</span><strong id="igv">S/ 0.00</strong></div>
                            <div class="grand-total"><span>Total</span><strong id="total">S/ 0.00</strong></div>
                        </div>
                        <div class="review-actions">
                            <p id="autosave-status" class="autosave-status" aria-live="polite"><span aria-hidden="true">✓</span> Los cambios se guardan automáticamente</p>
                            <button id="issue-button" class="primary-button" type="button"><span>Emitir factura</span><svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4 10h12m-4-4 4 4-4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
                        </div>
                    </div>
                </aside>
            </section>

            <section class="recent-section" aria-labelledby="recent-title">
                <div class="recent-heading"><div><p class="eyebrow">Últimos movimientos</p><h2 id="recent-title">Actividad de esta empresa</h2></div><span id="recent-count" class="record-count">0 registros</span></div>
                <div class="recent-table-wrap">
                    <table class="recent-table">
                        <thead><tr><th>Fecha</th><th>Cliente</th><th>Documento</th><th>Importe</th><th>Estado</th><th>Acción</th></tr></thead>
                        <tbody id="recent-body"><tr><td colspan="6" class="empty-row">Conecta una empresa para ver su actividad.</td></tr></tbody>
                    </table>
                </div>
            </section>
        </main>

        <footer><span>FacturaYa AI · Espacio multiempresa</span><span>Cálculo determinístico · Greenter {{ \Composer\InstalledVersions::getPrettyVersion('greenter/lite') }}</span></footer>
    </div>

    <div id="credit-note-drawer" class="drawer-backdrop" hidden>
        <section class="credit-note-drawer" role="dialog" aria-modal="true" aria-labelledby="credit-note-title">
            <header>
                <div><p class="eyebrow">Documento vinculado</p><h2 id="credit-note-title">Emitir nota de crédito</h2><p>Corrige una factura aceptada sin volver a escribir sus datos.</p></div>
                <button id="close-credit-note" class="drawer-close" type="button" aria-label="Cerrar">×</button>
            </header>

            <form id="credit-note-form">
                <div class="document-linkage" aria-label="Documento afectado y nota por emitir">
                    <div><span>Factura aceptada</span><strong id="credit-invoice-number">—</strong><small id="credit-customer">—</small></div>
                    <span class="linkage-arrow" aria-hidden="true">→</span>
                    <div class="is-credit"><span>Nota de crédito</span><strong id="credit-note-series">FC01 · siguiente número</strong><small>Mismo cliente y moneda</small></div>
                </div>

                <div class="drawer-section credit-reason-section">
                    <span class="drawer-section-label">¿Qué necesitas corregir?</span>
                    <div class="form-grid">
                        <label class="field field-wide"><span>Motivo SUNAT</span>
                            <select id="credit-reason-code" name="reason_code" required>
                                <option value="01">01 · Anulación de la operación</option>
                                <option value="02">02 · Anulación por error en el RUC</option>
                                <option value="03">03 · Corrección de la descripción</option>
                                <option value="04">04 · Descuento global</option>
                                <option value="05">05 · Descuento por ítem</option>
                                <option value="06">06 · Devolución total</option>
                                <option value="07">07 · Devolución por ítem</option>
                                <option value="09">09 · Disminución en el valor</option>
                                <option value="10">10 · Otros conceptos</option>
                            </select>
                        </label>
                        <label class="field"><span>Fecha de emisión</span><input id="credit-issue-date" name="issue_date" type="date" value="{{ now('America/Lima')->format('Y-m-d') }}" required></label>
                        <label class="field field-wide"><span>Explicación</span><input id="credit-reason-description" name="reason_description" maxlength="250" value="Operación anulada a solicitud del cliente." required></label>
                    </div>
                    <p id="credit-mode-help" class="credit-mode-help">La anulación toma todos los conceptos e importes de la factura original.</p>
                </div>

                <div class="drawer-section">
                    <div class="credit-items-heading"><span class="drawer-section-label">Importe a acreditar</span><small id="credit-selection-label">Factura completa</small></div>
                    <div class="table-scroll">
                        <table class="credit-items-table">
                            <thead><tr><th><span class="sr-only">Incluir</span></th><th>Concepto</th><th>Cant.</th><th>P. unit.</th><th>Total</th></tr></thead>
                            <tbody id="credit-items-body"></tbody>
                        </table>
                    </div>
                    <div class="credit-totals">
                        <div><span>Valor de venta</span><strong id="credit-subtotal">S/ 0.00</strong></div>
                        <div><span>IGV (18%)</span><strong id="credit-igv">S/ 0.00</strong></div>
                        <div class="grand-total"><span>Total de la nota</span><strong id="credit-total">S/ 0.00</strong></div>
                    </div>
                </div>

                <p id="credit-note-error" class="access-error form-error" role="alert" hidden></p>
                <div class="credit-actions">
                    <button id="cancel-credit-note" class="secondary-button" type="button">Cancelar</button>
                    <button id="issue-credit-note" class="primary-button" type="submit"><span>Emitir nota de crédito</span><svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4 10h12m-4-4 4 4-4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
                </div>
            </form>
        </section>
    </div>
</body>
</html>
