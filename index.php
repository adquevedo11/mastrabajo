<?php
declare(strict_types=1);
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Organizador de Cédulas PDF – Consolide y ordene cédulas de ciudadanía en un único PDF.">
    <title>Organizador de Cédulas PDF</title>
    <link rel="icon" type="image/svg+xml" href="<?= $basePath ?>/assets/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= $basePath ?>/assets/favicon.png">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= $basePath ?>/assets/apple-touch-icon.png">

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"
          crossorigin="anonymous">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet"
          crossorigin="anonymous">
    <!-- Google Fonts: Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- App CSS -->
    <link href="<?= $basePath ?>/assets/css/app.css" rel="stylesheet">
</head>
<body>

<!-- ════════════════════ HEADER ════════════════════════ -->
<header class="app-header">
    <div class="container">
        <div class="d-flex align-items-center gap-3">
            <div class="header-icon">📋</div>
            <div>
                <h1 class="app-title">Organizador de Cédulas PDF</h1>
                <p class="app-subtitle">Organice y consolide cédulas de ciudadanía de forma rápida y sencilla</p>
            </div>
            <div class="ms-auto d-none d-md-block">
                <span class="header-badge"><i class="bi bi-shield-check"></i> 100% Local</span>
            </div>
        </div>
    </div>
</header>

<!-- ════════════════════ MAIN ══════════════════════════ -->
<main class="container py-4">

    <!-- ─── STEP 1: Excel Upload ─────────────────────── -->
    <div class="section-card mb-3 fade-in">
        <div class="section-card-header">
            <span class="icon">📊</span>
            <span>Paso 1 – Cargar listado Excel</span>
            <small class="ms-auto text-muted fw-normal">Columnas requeridas: Nombres, Apellidos, Documento</small>
        </div>
        <div class="p-3">
            <div class="row align-items-center g-3">
                <div class="col-md-8">
                    <div class="upload-zone" id="excel-zone">
                        <input type="file" id="excel-input" accept=".xlsx,.xls" aria-label="Seleccionar archivo Excel">
                        <span class="upload-zone-icon">📂</span>
                        <p class="upload-zone-text fw-semibold">Arrastre su archivo Excel aquí o haga clic para seleccionar</p>
                        <p class="upload-zone-hint">Formatos aceptados: .xlsx, .xls</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="p-3 rounded-2" style="background:#F8FAFC;border:1px solid var(--border)">
                        <p class="mb-2 fw-semibold text-secondary" style="font-size:.8rem">
                            <i class="bi bi-info-circle text-primary"></i> Formato requerido:
                        </p>
                        <table class="table table-sm table-bordered mb-0" style="font-size:.72rem">
                            <thead class="table-light">
                                <tr><th>Nombres</th><th>Apellidos</th><th>Documento</th></tr>
                            </thead>
                            <tbody>
                                <tr><td>Andrés Felipe</td><td>Quevedo</td><td>11111111</td></tr>
                                <tr><td>Carlos</td><td>Pérez Gómez</td><td>22222222</td></tr>
                            </tbody>
                        </table>
                        <div class="mt-2 pt-2" style="border-top:1px solid var(--border)">
                            <a href="<?= $basePath ?>/api/excel/template"
                               class="d-inline-flex align-items-center gap-1 text-decoration-none fw-semibold"
                               style="font-size:.75rem;color:var(--primary)"
                               title="Descargar plantilla Excel lista para llenar">
                                <i class="bi bi-download"></i>
                                Descargar plantilla .xlsx
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ─── STATS BAR ───────────────────────────────── -->
    <div id="stats-section" class="hidden mb-3 fade-in">
        <div class="stats-grid">
            <div class="stat-item section-card text-center">
                <div class="stat-value info" id="stat-total">0</div>
                <div class="stat-label"><i class="bi bi-people"></i> Total</div>
            </div>
            <div class="stat-item section-card text-center">
                <div class="stat-value warning" id="stat-pending">0</div>
                <div class="stat-label"><i class="bi bi-clock"></i> Pendientes</div>
            </div>
            <div class="stat-item section-card text-center">
                <div class="stat-value success" id="stat-associated">0</div>
                <div class="stat-label"><i class="bi bi-check-circle"></i> Asociados</div>
            </div>
            <div class="stat-item section-card text-center">
                <div class="stat-value primary" id="stat-pct" style="color:var(--primary)">0%</div>
                <div class="stat-label"><i class="bi bi-pie-chart"></i> Progreso</div>
            </div>
            <!-- Descarga Excel ordenado — visible apenas hay datos -->
            <div class="stat-item section-card text-center d-flex flex-column align-items-center justify-content-center">
                <a id="btn-download-excel"
                   href="<?= $basePath ?>/api/excel/sorted"
                   class="d-inline-flex align-items-center gap-2 fw-semibold text-decoration-none"
                   style="font-size:.82rem;color:var(--success);padding:.4rem .6rem;border:1px solid #BBF7D0;border-radius:6px;background:#F0FDF4">
                    <i class="bi bi-file-earmark-excel-fill"></i>
                    Descargar<br>Excel ordenado
                </a>
            </div>
        </div>
    </div>

    <!-- ─── STEP 2: PDF Upload + Work area ──────────── -->
    <div class="work-section hidden mb-3 fade-in">
        <div class="section-card">
            <div class="section-card-header">
                <span class="icon">📄</span>
                <span>Paso 2 – Cargar archivos PDF</span>
                <small class="ms-auto text-muted fw-normal">Un PDF por cédula (puede tener varias páginas)</small>
            </div>
            <div class="p-3">
                <div class="upload-zone" id="pdf-zone">
                    <input type="file" id="pdf-input" accept=".pdf" multiple aria-label="Seleccionar PDFs">
                    <span class="upload-zone-icon">📁</span>
                    <p class="upload-zone-text fw-semibold">Arrastre los PDFs aquí o haga clic para seleccionar</p>
                    <p class="upload-zone-hint">Solo archivos .pdf · Puede cargar múltiples archivos a la vez</p>
                </div>
            </div>
        </div>
    </div>

    <!-- ─── STEP 3: Two-panel work area ─────────────── -->
    <div class="work-section hidden mb-3 fade-in">
        <div class="row g-3">

            <!-- Left: Persons -->
            <div class="col-lg-6" id="persons-container">
                <div class="section-card h-100">
                    <div class="section-card-header">
                        <span class="icon">👥</span>
                        <span>Personas</span>
                        <div class="ms-auto d-flex gap-2 align-items-center">
                            <span class="panel-count" id="persons-pending-count">0</span>
                            <span style="font-size:.7rem;color:var(--text-muted)">pendientes</span>
                        </div>
                    </div>

                    <!-- Pending persons -->
                    <div class="panel-title">
                        <span><i class="bi bi-clock text-warning"></i> Pendientes de asociar</span>
                        <span class="panel-count" id="persons-pending-count-2">0</span>
                    </div>
                    <div class="persons-list" id="persons-pending-list">
                        <div class="empty-state">
                            <i class="bi bi-person-badge"></i>
                            <p>Cargue el Excel para ver las personas.</p>
                        </div>
                    </div>

                    <!-- Associated persons (collapsible) -->
                    <div class="panel-title" style="cursor:pointer" onclick="toggleSection('#persons-associated-list')" title="Mostrar / ocultar asociados">
                        <span><i class="bi bi-check2-circle text-success"></i> Asociados</span>
                        <div class="d-flex align-items-center gap-2">
                            <span class="panel-count success" id="persons-associated-count">0</span>
                            <span id="btn-toggle-associated" style="font-size:.75rem;color:var(--text-muted)">
                                <i class="bi bi-chevron-up"></i>
                            </span>
                        </div>
                    </div>
                    <div class="persons-list associated-section" id="persons-associated-list">
                        <div class="empty-state" style="padding:1rem">
                            <i class="bi bi-person-slash"></i>
                            <p>Sin asociados aún.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right: PDFs -->
            <div class="col-lg-6">
                <div class="section-card h-100">
                    <div class="section-card-header">
                        <span class="icon">📑</span>
                        <span>PDFs cargados</span>
                        <div class="ms-auto d-flex gap-2 align-items-center">
                            <span class="panel-count" id="pdfs-pending-count">0</span>
                            <span style="font-size:.7rem;color:var(--text-muted)">pendientes</span>
                            <span class="panel-count muted ms-1" id="pdfs-associated-count">0</span>
                            <span style="font-size:.7rem;color:var(--text-muted)">asoc.</span>
                        </div>
                    </div>

                    <div class="p-2">
                        <p class="text-muted mb-1" style="font-size:.72rem;text-align:center">
                            <i class="bi bi-arrows-move text-primary"></i>
                            Arrastre un PDF hacia la persona correspondiente
                            <span class="d-none d-md-inline">o use el botón <i class="bi bi-link"></i></span>
                        </p>
                    </div>

                    <div class="pdfs-grid" id="pdfs-grid">
                        <div class="empty-state" style="grid-column:1/-1;padding:2rem">
                            <i class="bi bi-file-earmark-pdf"></i>
                            <p>Cargue archivos PDF para comenzar.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ─── PROGRESS BAR ─────────────────────────────── -->
    <div class="work-section hidden mb-3 fade-in">
        <div class="progress-wrap">
            <div class="progress-label">
                <span><i class="bi bi-bar-chart-steps text-primary"></i> <strong>Progreso de asociación</strong></span>
                <span id="progress-text" class="text-muted" style="font-size:.8rem">0 / 0 asociados (0%)</span>
            </div>
            <div class="progress-bar-track">
                <div class="progress-bar-fill" id="progress-fill" style="width:0%"></div>
            </div>
        </div>
    </div>

    <!-- ─── ACTION BUTTONS ───────────────────────────── -->
    <div id="actions-section" class="hidden mb-4 fade-in">
        <div class="section-card">
            <div class="section-card-header">
                <span class="icon">⚡</span>
                <span>Paso 3 – Generar y descargar</span>
            </div>
            <div class="p-3 d-flex flex-wrap gap-2 align-items-center">
                <button class="btn-primary-custom" id="btn-generate" disabled>
                    <i class="bi bi-file-earmark-pdf"></i>
                    Generar PDF Final
                </button>
                <button class="btn-success-custom" id="btn-download" disabled>
                    <i class="bi bi-download"></i>
                    Descargar PDF Consolidado
                </button>
                <a href="<?= $basePath ?>/api/excel/sorted"
                   id="btn-download-excel"
                   class="btn-excel-custom">
                    <i class="bi bi-file-earmark-excel"></i>
                    Descargar Excel Ordenado
                </a>
                <div class="ms-auto">
                    <button class="btn-outline-custom" id="btn-reset">
                        <i class="bi bi-arrow-counterclockwise"></i>
                        Reiniciar sesión
                    </button>
                </div>
            </div>
            <div class="px-3 pb-3">
                <div class="alert alert-info mb-0" style="font-size:.78rem;padding:.5rem .9rem">
                    <i class="bi bi-info-circle"></i>
                    El PDF consolidado se llama <strong>Cedulas_Ordenadas.pdf</strong> y contiene todas las
                    cédulas en el orden definido por el Excel, conservando todas las páginas de cada documento.
                </div>
            </div>
        </div>
    </div>

</main>

<!-- ═══════════════ FOOTER ════════════════════════════════ -->
<footer class="app-footer">
    <span>Organizador de Cédulas PDF</span>
    <button class="manual-link" onclick="bootstrap.Modal.getOrCreateInstance($('#modal-manual')).show()">
        <i class="bi bi-book-half"></i> Manual
    </button>
</footer>

<!-- ═══════════════ MODAL MANUAL ═══════════════════════════ -->
<div class="modal fade" id="modal-manual" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--primary);color:#fff;border:none">
                <h5 class="modal-title fw-bold mb-0" style="font-size:1rem">
                    <i class="bi bi-book-half me-2"></i>Manual de uso
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">

                <!-- Pasos -->
                <div class="man-steps">

                    <div class="man-step">
                        <div class="man-num">1</div>
                        <div class="man-content">
                            <div class="man-title"><i class="bi bi-file-earmark-spreadsheet"></i> Cargar el Excel</div>
                            <ul>
                                <li>El archivo debe tener columnas llamadas <strong>Número documento</strong>, <strong>Nombres</strong> y <strong>Apellidos</strong> (los encabezados exactos pueden variar, la app los detecta automáticamente).</li>
                                <li>La fila 1 contiene los encabezados; los datos comienzan en la fila 2.</li>
                                <li>El <strong>orden de las filas</strong> define el orden del PDF consolidado final.</li>
                            </ul>
                        </div>
                    </div>

                    <div class="man-step">
                        <div class="man-num">2</div>
                        <div class="man-content">
                            <div class="man-title"><i class="bi bi-file-earmark-pdf"></i> Subir los PDFs</div>
                            <ul>
                                <li>Arrastre los archivos al área de PDFs o use el botón <em>Seleccionar PDFs</em>. Puede subir varios a la vez.</li>
                                <li>Cada PDF puede tener varias páginas; todas quedan en el consolidado.</li>
                                <li>Si una tarjeta aparece con <strong class="text-danger">borde rojo</strong>, el PDF no es compatible. Elimínelo, conviértalo gratis en <a href="https://www.ilovepdf.com/es/optimizar_pdf" target="_blank" rel="noopener"><strong>ilovepdf.com → Optimizar PDF</strong></a> y súbalo de nuevo.</li>
                                <li>Archivos idénticos se detectan automáticamente y no se duplican.</li>
                            </ul>
                        </div>
                    </div>

                    <div class="man-step">
                        <div class="man-num">3</div>
                        <div class="man-content">
                            <div class="man-title"><i class="bi bi-link-45deg"></i> Asociar PDFs a personas</div>
                            <ul>
                                <li><strong>Arrastrando:</strong> tome una tarjeta PDF y suéltela sobre la fila de la persona en la tabla izquierda.</li>
                                <li><strong>Con botón:</strong> use el ícono <i class="bi bi-link"></i> en la tarjeta PDF, o el ícono <i class="bi bi-paperclip"></i> en la fila de la persona.</li>
                                <li>Las filas pendientes aparecen en azul; las completadas en verde.</li>
                                <li>Si se equivocó, elimine el PDF con <i class="bi bi-trash3"></i>, súbalo de nuevo y reasígnelo.</li>
                            </ul>
                        </div>
                    </div>

                    <div class="man-step">
                        <div class="man-num">4</div>
                        <div class="man-content">
                            <div class="man-title"><i class="bi bi-file-earmark-check"></i> Generar y descargar</div>
                            <ul>
                                <li>Cuando todas las personas tengan PDF asignado, se habilita <strong>Generar PDF Consolidado</strong>.</li>
                                <li>El archivo resultante es <strong>Cedulas_Ordenadas.pdf</strong> con las cédulas en el orden del Excel.</li>
                                <li>También puede descargar el listado en Excel con <strong>Descargar Excel Ordenado</strong>.</li>
                                <li>Para empezar un proceso nuevo use <strong>Reiniciar sesión</strong> — borra todo lo cargado.</li>
                            </ul>
                        </div>
                    </div>

                </div>

                <!-- Problemas frecuentes -->
                <div class="man-faq">
                    <div class="man-faq-title"><i class="bi bi-patch-question-fill me-2"></i>Problemas frecuentes</div>
                    <div class="man-faq-grid">
                        <div class="man-faq-item">
                            <span class="man-fq">PDF con borde rojo</span>
                            <span class="man-fa">Formato PDF moderno (1.5+) no compatible. Conviértalo en <a href="https://www.ilovepdf.com/es/optimizar_pdf" target="_blank" rel="noopener">ilovepdf.com → Optimizar PDF</a> y súbalo de nuevo.</span>
                        </div>
                        <div class="man-faq-item">
                            <span class="man-fq">Dice que el archivo ya está cargado</span>
                            <span class="man-fa">El sistema detectó que es idéntico a uno ya subido. No hace falta subirlo de nuevo.</span>
                        </div>
                        <div class="man-faq-item">
                            <span class="man-fq">El consolidado no respeta el orden</span>
                            <span class="man-fa">El orden viene del Excel. Revise el orden de las filas en su archivo antes de cargarlo.</span>
                        </div>
                        <div class="man-faq-item">
                            <span class="man-fq">La columna de documentos no se detecta</span>
                            <span class="man-fa">El encabezado debe contener las palabras <em>documento</em>, <em>cedula</em> o <em>identificacion</em> (sin tilde).</span>
                        </div>
                    </div>
                </div>

            </div>
            <div class="modal-footer border-0 pt-0 pb-3 justify-content-center">
                <small class="text-muted">Organizador de Cédulas PDF &nbsp;·&nbsp; v1.0</small>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════ PDF VIEWER MODAL ═══════════════════ -->
<div class="modal fade modal-pdf-viewer" id="modal-pdf-viewer" tabindex="-1" aria-labelledby="modal-pdf-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title d-flex align-items-center gap-2" id="modal-pdf-title">
                    <i class="bi bi-file-earmark-pdf-fill text-danger"></i> Vista previa
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body" id="modal-pdf-body" style="max-height:72vh;overflow-y:auto;background:#F1F5F9">
                <div class="text-center p-4 text-muted">
                    <div class="spinner-border text-primary"></div>
                </div>
            </div>
            <div class="modal-footer justify-content-start">
                <small class="text-muted"><i class="bi bi-info-circle"></i> Use la barra de desplazamiento para ver todas las páginas.</small>
                <button type="button" class="btn btn-secondary ms-auto" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════ ASSOCIATION MODAL ══════════════════ -->
<div class="modal fade" id="modal-assoc" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title d-flex align-items-center gap-2" id="modal-assoc-title">
                    <i class="bi bi-link-45deg text-primary"></i> Asociar
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3 p-2 rounded" style="background:var(--primary-light);border:1px solid var(--border-focus)">
                    <small class="text-muted"><i class="bi bi-arrow-right-circle text-primary"></i></small>
                    <strong style="font-size:.82rem" id="modal-assoc-subject">—</strong>
                </div>
                <div class="assoc-list-scroll" id="modal-assoc-pdf-list">
                    <div class="text-center text-muted p-3">Cargando…</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════ CONFIRM MODAL ══════════════════════ -->
<div class="modal fade" id="modal-confirm" tabindex="-1" aria-hidden="true"
     data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered" style="max-width:380px">
        <div class="modal-content" style="border:none;border-radius:var(--radius);box-shadow:0 8px 32px rgba(0,0,0,.18)">
            <div class="modal-header border-0 pb-0 pt-4 px-4">
                <span id="modal-confirm-icon" style="font-size:1.6rem;line-height:1;margin-right:.5rem"></span>
                <h6 class="modal-title fw-bold mb-0" id="modal-confirm-title" style="font-size:.97rem;color:var(--text)"></h6>
            </div>
            <div class="modal-body px-4 pt-2 pb-3">
                <p id="modal-confirm-body" style="font-size:.83rem;color:var(--text-secondary);margin:0;line-height:1.5"></p>
            </div>
            <div class="modal-footer border-0 pt-0 px-4 pb-4 gap-2 justify-content-end">
                <button type="button" class="btn btn-sm btn-outline-secondary px-3" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-sm px-3" id="modal-confirm-btn">Confirmar</button>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════ SCRIPTS ════════════════════════════ -->
<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<!-- PDF.js -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js" crossorigin="anonymous"></script>
<!-- SortableJS -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js" crossorigin="anonymous"></script>
<!-- App JS -->
<script src="<?= $basePath ?>/assets/js/app.js"></script>

<script>
    // Pass base path to JS context
    window.__BASE_PATH = <?= json_encode($basePath) ?>;
</script>

</body>
</html>
