<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Administra empresas emisoras en FacturaYa AI.">
    <title>Empresas · FacturaYa AI</title>
    @vite(['resources/css/app.css', 'resources/js/platform.js'])
</head>
<body data-page="platform">
    <section id="platform-gate" class="access-gate platform-gate" aria-labelledby="platform-access-title">
        <div class="access-visual platform-access-visual" aria-hidden="true">
            <div class="access-brand"><span class="brand-mark">F</span><span>FacturaYa <em>AI</em></span></div>
            <div class="dossier-stack"><span>RUC</span><span>SOL</span><span>F001</span></div>
            <p>Un expediente seguro para cada emisor.</p>
        </div>
        <div class="access-form-panel">
            <p class="eyebrow">Administración de plataforma</p>
            <h1 id="platform-access-title">Gestiona tus empresas emisoras.</h1>
            <p>Introduce la clave administrativa global. Esta no es la clave individual de una empresa.</p>
            <form id="platform-access-form">
                <label class="field"><span>Clave de administración</span><input id="platform-token" type="password" placeholder="Clave de PLATFORM_ADMIN_TOKEN" autocomplete="off" required></label>
                <button id="connect-platform" class="primary-button" type="submit"><span>Abrir administración</span><svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4 10h12m-4-4 4 4-4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
            </form>
            <p id="platform-access-error" class="access-error" role="alert" hidden></p>
            <p class="access-footnote">En desarrollo, la clave está en el archivo `.env`.</p>
        </div>
    </section>

    <div id="platform-content" class="page-shell app-content platform-content">
        <header class="topbar">
            <a class="brand" href="/"><span class="brand-mark" aria-hidden="true">F</span><span>FacturaYa <em>AI</em></span></a>
            <nav class="product-nav" aria-label="Navegación principal"><a href="/">Emitir factura</a><a class="is-active" href="/platform">Empresas</a></nav>
            <button id="close-platform" class="outline-action compact-action" type="button">Cerrar administración</button>
        </header>

        <main class="platform-main">
            <section class="platform-hero">
                <div><p class="eyebrow">Control de emisores</p><h1>Empresas <span>listas para facturar.</span></h1><p class="intro-copy">Cada expediente conserva su identidad fiscal, credenciales, certificado y correlativos sin mezclarlos con los demás.</p></div>
                <button id="open-company-form" class="primary-button platform-cta" type="button"><span>Registrar empresa</span><svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 4v12M4 10h12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></button>
            </section>

            <section class="platform-metrics" aria-label="Resumen de empresas">
                <div><span>Total</span><strong id="metric-total">0</strong><small>empresas registradas</small></div>
                <div><span>Greenter</span><strong id="metric-live">0</strong><small>con transmisión configurada</small></div>
                <div><span>Simulación</span><strong id="metric-fake">0</strong><small>sin envío a SUNAT</small></div>
            </section>

            <div id="platform-notice" class="notice" role="status" hidden></div>

            <section class="company-registry" aria-labelledby="registry-title">
                <div class="registry-heading"><div><p class="eyebrow">Expedientes tributarios</p><h2 id="registry-title">Empresas registradas</h2></div><span class="record-count" id="company-count">0 registros</span></div>
                <div id="company-list" class="company-list"><div class="registry-empty">Abre la administración para cargar las empresas.</div></div>
            </section>
        </main>

        <footer><span>FacturaYa AI · Administración</span><span>Credenciales y certificados cifrados con APP_KEY</span></footer>
    </div>

    <div id="company-drawer" class="drawer-backdrop" hidden>
        <section class="company-drawer" role="dialog" aria-modal="true" aria-labelledby="drawer-title">
            <header><div><p class="eyebrow">Nuevo expediente</p><h2 id="drawer-title">Registrar empresa emisora</h2></div><button id="close-company-form" class="drawer-close" type="button" aria-label="Cerrar">×</button></header>
            <form id="company-form">
                <div class="drawer-section"><span class="drawer-section-label">Identidad fiscal</span>
                    <div class="form-grid">
                        <label class="field"><span>RUC</span><input name="ruc" inputmode="numeric" maxlength="11" pattern="[0-9]{11}" placeholder="20666666666" required></label>
                        <label class="field field-wide"><span>Razón social</span><input name="legal_name" placeholder="SERVICIOS ANDINOS S.A.C." required></label>
                        <label class="field field-wide"><span>Nombre comercial</span><input name="trade_name" placeholder="Servicios Andinos"></label>
                    </div>
                </div>
                <div class="drawer-section"><span class="drawer-section-label">Domicilio fiscal</span>
                    <div class="form-grid three-columns">
                        <label class="field"><span>Ubigeo</span><input name="ubigeo" maxlength="6" value="150101" required></label>
                        <label class="field"><span>Departamento</span><input name="department" value="LIMA" required></label>
                        <label class="field"><span>Provincia</span><input name="province" value="LIMA" required></label>
                        <label class="field"><span>Distrito</span><input name="district" value="LIMA" required></label>
                        <label class="field field-wide"><span>Dirección</span><input name="address" placeholder="Av. Principal 123" required></label>
                    </div>
                </div>
                <div class="drawer-section"><span class="drawer-section-label">Emisión electrónica</span>
                    <div class="form-grid">
                        <label class="field"><span>Modo</span><select id="sunat-driver" name="sunat_driver"><option value="fake">Simulación local</option><option value="greenter">Greenter + SUNAT</option></select></label>
                        <label class="field"><span>Entorno SUNAT</span><select name="sunat_environment"><option value="beta">Beta / pruebas</option><option value="production">Producción</option></select></label>
                        <label class="field"><span>Serie de facturas</span><input name="default_series" value="F001" maxlength="4" required></label>
                        <label class="field"><span>Serie de notas de crédito</span><input name="default_credit_note_series" value="FC01" maxlength="4" required></label>
                        <label class="field"><span>Nombre del token</span><input name="token_name" value="Sistema principal" required></label>
                    </div>
                    <div id="sunat-credentials" class="sunat-credentials" hidden>
                        <label class="field"><span>Usuario SOL</span><input name="sol_user" autocomplete="off"></label>
                        <label class="field"><span>Contraseña SOL</span><input name="sol_password" type="password" autocomplete="new-password"></label>
                        <div class="certificate-box">
                            <div class="certificate-box-heading">
                                <span class="certificate-lock" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M7.5 10V7.5a4.5 4.5 0 0 1 9 0V10m-10 0h11a2 2 0 0 1 2 2v7h-15v-7a2 2 0 0 1 2-2Z" stroke="currentColor" stroke-width="1.7"/><path d="M12 14v2.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg></span>
                                <div><strong>Certificado digital de la empresa</strong><small>Sube el archivo original; nosotros lo preparamos para Greenter.</small></div>
                            </div>
                            <div class="certificate-fields">
                                <label class="certificate-field"><span>Archivo .p12 o .pfx</span><input name="certificate" type="file" accept=".p12,.pfx,application/x-pkcs12"><small>Normalmente es el archivo descargado de SUNAT o de tu proveedor.</small></label>
                                <label class="field certificate-password"><span>Contraseña del certificado</span><input name="certificate_password" type="password" autocomplete="new-password"><small>Se usa una sola vez para abrirlo. No la guardamos.</small></label>
                            </div>
                        </div>
                    </div>
                </div>
                <p id="company-form-error" class="access-error form-error" role="alert" hidden></p>
                <button id="save-company" class="primary-button" type="submit"><span>Registrar y generar token</span><svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4 10h12m-4-4 4 4-4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
            </form>
        </section>
    </div>

    <div id="token-dialog" class="drawer-backdrop" hidden>
        <section class="token-dialog" role="dialog" aria-modal="true" aria-labelledby="token-title">
            <span class="success-seal" aria-hidden="true">✓</span>
            <p class="eyebrow">Empresa registrada</p>
            <h2 id="token-title">Guarda esta clave ahora.</h2>
            <p>Por seguridad, no volveremos a mostrarla.</p>
            <div class="issued-token"><code id="issued-token-value"></code><button id="copy-token" type="button">Copiar</button></div>
            <button id="close-token-dialog" class="primary-button" type="button"><span>Ya guardé la clave</span></button>
        </section>
    </div>
</body>
</html>
