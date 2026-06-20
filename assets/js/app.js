/* =========================================================
   ORGANIZADOR DE CÉDULAS PDF – Frontend Application
   ========================================================= */

'use strict';

// ── Config ────────────────────────────────────────────────
const API_URL = (() => {
    const p = window.location.pathname.replace(/\/[^/]*$/, '');
    return (p || '') + '/api';
})();

// ── State ─────────────────────────────────────────────────
const state = {
    persons:      [],   // sorted from Excel
    pdfs:         {},   // keyed by id
    associations: {},   // documento → pdfId
    generated:    false,
    draggedPdfId: null,
    pdfCacheBuster: Date.now(),
};

// ── PDF.js worker ─────────────────────────────────────────
if (typeof pdfjsLib !== 'undefined') {
    pdfjsLib.GlobalWorkerOptions.workerSrc =
        'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
}

// ── Utility helpers ───────────────────────────────────────
const $  = (sel, ctx = document) => ctx.querySelector(sel);
const $$ = (sel, ctx = document) => [...ctx.querySelectorAll(sel)];

function pad2(n) { return String(n).padStart(2, '0'); }

let spinnerCount = 0;
function showSpinner(msg = 'Procesando…') {
    spinnerCount++;
    let el = $('#spinner-overlay');
    if (!el) {
        el = document.createElement('div');
        el.id = 'spinner-overlay';
        el.className = 'spinner-overlay';
        el.innerHTML = `
            <div class="spinner-box">
                <div class="spinner-border text-primary" role="status" style="width:2.5rem;height:2.5rem"></div>
                <p id="spinner-msg">${msg}</p>
            </div>`;
        document.body.appendChild(el);
    } else {
        $('#spinner-msg').textContent = msg;
    }
}

function hideSpinner() {
    spinnerCount = Math.max(0, spinnerCount - 1);
    if (spinnerCount === 0) {
        const el = $('#spinner-overlay');
        if (el) el.remove();
    }
}

function toast(message, type = 'info', duration = 4500) {
    const container = $('#toast-container') || (() => {
        const c = document.createElement('div');
        c.id = 'toast-container';
        c.className = 'toast-container-custom';
        document.body.appendChild(c);
        return c;
    })();

    const icons = { success: 'bi-check-circle-fill', error: 'bi-x-circle-fill',
                    warning: 'bi-exclamation-triangle-fill', info: 'bi-info-circle-fill' };

    const item = document.createElement('div');
    item.className = `toast-item ${type}`;
    item.innerHTML = `<i class="bi ${icons[type] || icons.info}"></i><span>${message}</span>`;
    container.appendChild(item);

    setTimeout(() => {
        item.style.animation = 'fadeOut .35s ease forwards';
        setTimeout(() => item.remove(), 360);
    }, duration);
}

// ── Custom confirm modal ──────────────────────────────────
function showConfirm(title, message, confirmLabel = 'Confirmar', danger = true) {
    return new Promise(resolve => {
        const modal      = $('#modal-confirm');
        const titleEl    = $('#modal-confirm-title');
        const bodyEl     = $('#modal-confirm-body');
        const iconEl     = $('#modal-confirm-icon');
        const confirmBtn = $('#modal-confirm-btn');

        if (titleEl)    titleEl.textContent  = title;
        if (bodyEl)     bodyEl.textContent   = message;
        if (iconEl)     iconEl.textContent   = danger ? '⚠️' : 'ℹ️';
        if (confirmBtn) {
            confirmBtn.textContent = confirmLabel;
            confirmBtn.className   = `btn btn-sm px-3 ${danger ? 'btn-danger' : 'btn-primary'}`;
        }

        let confirmed = false;
        const bsModal = bootstrap.Modal.getOrCreateInstance(modal);

        confirmBtn.onclick = () => { confirmed = true; bsModal.hide(); };

        modal.addEventListener('hidden.bs.modal', () => resolve(confirmed), { once: true });
        bsModal.show();
    });
}

// ── API calls ─────────────────────────────────────────────
async function apiPost(endpoint, body, isFormData = false) {
    const opts = { method: 'POST' };
    if (isFormData) {
        opts.body = body;
    } else {
        opts.headers = { 'Content-Type': 'application/json' };
        opts.body = JSON.stringify(body);
    }
    const resp = await fetch(`${API_URL}/${endpoint}`, opts);
    if (!resp.ok && resp.status !== 400 && resp.status !== 404) {
        throw new Error(`HTTP ${resp.status}`);
    }
    return resp.json();
}

async function apiGet(endpoint) {
    const resp = await fetch(`${API_URL}/${endpoint}`);
    if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
    return resp.json();
}

// ── State updaters from API response ─────────────────────
function applyServerState(data) {
    if (data.persons)      state.persons      = data.persons;
    if (data.associations) state.associations = data.associations;
    if (data.pdfs) {
        const pdfMap = {};
        data.pdfs.forEach(p => pdfMap[p.id] = p);
        state.pdfs = pdfMap;
    }
    if (typeof data.stats === 'object') {
        updateStats(data.stats);
    } else {
        computeAndUpdateStats();
    }
}

function computeAndUpdateStats() {
    const total      = state.persons.length;
    const associated = Object.keys(state.associations).length;
    updateStats({ total, associated, pending: total - associated,
                  percentage: total > 0 ? Math.round((associated / total) * 100) : 0 });
}

// ── Excel upload ──────────────────────────────────────────
async function handleExcelUpload(file) {
    if (!file) return;
    showSpinner('Procesando Excel…');

    const fd = new FormData();
    fd.append('excel', file);

    try {
        const data = await apiPost('excel/upload', fd, true);
        if (!data.success) { toast(data.error, 'error'); return; }

        applyServerState(data);
        state.generated = false;

        toast(`${data.count} registros cargados y ordenados.`, 'success');
        showWorkArea();
        renderPersonsPanel();
        renderPdfsPanel();
        updateProgress();
        updateActionButtons();
    } catch (e) {
        toast('Error al procesar el Excel: ' + e.message, 'error');
    } finally {
        hideSpinner();
    }
}

// ── PDF upload ────────────────────────────────────────────
async function handlePdfUpload(files) {
    if (!files || files.length === 0) return;
    if (state.persons.length === 0) {
        toast('Cargue primero el archivo Excel.', 'warning');
        return;
    }

    showSpinner(`Cargando ${files.length} PDF(s)…`);

    const fd = new FormData();
    [...files].forEach(f => fd.append('pdfs[]', f));

    try {
        const data = await apiPost('pdf/upload', fd, true);
        if (!data.success) { toast(data.error, 'error'); return; }

        data.pdfs.forEach(p => { state.pdfs[p.id] = p; });

        if (data.errors && data.errors.length > 0) {
            toast(data.errors.join(' | '), 'warning');
        }

        toast(data.message, 'success');
        renderPdfsPanel();
        computeAndUpdateStats();
        updateActionButtons();
    } catch (e) {
        toast('Error al cargar PDFs: ' + e.message, 'error');
    } finally {
        hideSpinner();
    }
}

// ── Association ───────────────────────────────────────────
async function associate(pdfId, documento) {
    showSpinner('Asociando…');
    try {
        const data = await apiPost('association/create', { documento, pdf_id: pdfId });
        if (!data.success) { toast(data.error, 'error'); return; }

        applyServerState(data);
        state.generated = false;
        renderPersonsPanel();
        renderPdfsPanel();
        updateProgress();
        updateActionButtons();
        toast('Asociación realizada correctamente.', 'success');
    } catch (e) {
        toast('Error: ' + e.message, 'error');
    } finally {
        hideSpinner();
    }
}

async function removeAssociation(documento) {
    showSpinner('Quitando asociación…');
    try {
        const data = await apiPost('association/remove', { documento });
        if (!data.success) { toast(data.error, 'error'); return; }

        applyServerState(data);
        state.generated = false;
        renderPersonsPanel();
        renderPdfsPanel();
        updateProgress();
        updateActionButtons();
        toast('Asociación eliminada.', 'info');
    } catch (e) {
        toast('Error: ' + e.message, 'error');
    } finally {
        hideSpinner();
    }
}

// ── Generate PDF ──────────────────────────────────────────
async function generatePdf() {
    showSpinner('Generando PDF consolidado…');
    try {
        const data = await apiPost('generate', {});
        if (!data.success) { toast(data.error, 'error'); return; }

        state.generated = true;
        if (data.unused_pdfs > 0) {
            toast(`PDF generado. Hay ${data.unused_pdfs} PDF(s) sin utilizar.`, 'warning');
        } else {
            toast('PDF consolidado generado exitosamente.', 'success');
        }

        if (Array.isArray(data.warnings) && data.warnings.length > 0) {
            data.warnings.forEach(msg => toast(`⚠ Omitido: ${msg}`, 'warning', 9000));
        }

        updateActionButtons();
    } catch (e) {
        toast('Error al generar: ' + e.message, 'error');
    } finally {
        hideSpinner();
    }
}

function downloadPdf() {
    window.location.href = `${API_URL}/download`;
}

async function resetSession() {
    const ok = await showConfirm(
        '¿Reiniciar sesión?',
        'Se perderán todos los datos cargados: personas, PDFs y asociaciones. Esta acción no se puede deshacer.',
        'Sí, reiniciar'
    );
    if (!ok) return;
    showSpinner('Reiniciando…');
    try {
        await apiPost('reset', {});
        state.persons      = [];
        state.pdfs         = {};
        state.associations = {};
        state.generated    = false;

        hideWorkArea();
        renderPersonsPanel();
        renderPdfsPanel();
        updateStats({ total: 0, associated: 0, pending: 0, percentage: 0 });
        updateProgress();
        updateActionButtons();

        // Reset file inputs
        $$('input[type="file"]').forEach(i => { i.value = ''; });
        toast('Sesión reiniciada.', 'info');
    } catch (e) {
        toast('Error al reiniciar: ' + e.message, 'error');
    } finally {
        hideSpinner();
    }
}

// ── Remove PDF ────────────────────────────────────────────
async function removePdf(pdfId) {
    const pdf = state.pdfs[pdfId];
    if (!pdf) return;

    const isAssoc = pdf.status === 'associated';
    const msg = isAssoc
        ? `"${pdf.original_name}" está asociado a una persona. Se quitará la asociación y se eliminará el archivo.`
        : `Se eliminará "${pdf.original_name}" de forma permanente.`;

    const ok = await showConfirm('Eliminar PDF', msg, 'Eliminar');
    if (!ok) return;

    showSpinner('Eliminando PDF…');
    try {
        const data = await apiPost('pdf/remove', { pdf_id: pdfId });
        if (!data.success) { toast(data.error, 'error'); return; }

        applyServerState(data);
        state.generated = false;
        renderPersonsPanel();
        renderPdfsPanel();
        updateProgress();
        updateActionButtons();
        toast('PDF eliminado.', 'info');
    } catch (e) {
        toast('Error al eliminar el PDF: ' + e.message, 'error');
    } finally {
        hideSpinner();
    }
}

// ── Render: Show/hide sections ────────────────────────────
function showWorkArea() {
    $$('.work-section').forEach(el => el.classList.remove('hidden'));
}

function hideWorkArea() {
    $$('.work-section').forEach(el => el.classList.add('hidden'));
    if (state.persons.length === 0) {
        $$('.stats-section').forEach(el => el.classList.add('hidden'));
    }
}

// ── Render: Stats ─────────────────────────────────────────
function updateStats(stats) {
    const el = $('#stats-section');
    if (!el) return;

    el.classList.remove('hidden');
    $('#stat-total').textContent      = stats.total;
    $('#stat-pending').textContent    = stats.pending;
    $('#stat-associated').textContent = stats.associated;
    $('#stat-pct').textContent        = stats.percentage + '%';
}

// ── Render: Progress ──────────────────────────────────────
function updateProgress() {
    const total      = state.persons.length;
    const associated = Object.keys(state.associations).length;
    const pct        = total > 0 ? Math.round((associated / total) * 100) : 0;

    const bar  = $('#progress-fill');
    const text = $('#progress-text');
    if (!bar) return;

    bar.style.width = pct + '%';
    bar.className   = 'progress-bar-fill' + (pct === 100 ? ' complete' : '');
    if (text) text.textContent = `${associated} / ${total} asociados (${pct}%)`;
}

// ── Render: Action buttons ────────────────────────────────
function updateActionButtons() {
    const total    = state.persons.length;
    const pending  = total - Object.keys(state.associations).length;
    const hasPdfs  = Object.keys(state.pdfs).length > 0;
    const canGen   = total > 0 && pending === 0;

    const btnGen  = $('#btn-generate');
    const btnDown = $('#btn-download');
    const btnRes  = $('#btn-reset');

    if (btnGen)  btnGen.disabled  = !canGen;
    if (btnDown) btnDown.disabled = !state.generated;
    if (btnRes)  btnRes.disabled  = total === 0 && !hasPdfs;

    const sect = $('#actions-section');
    if (sect && total > 0) sect.classList.remove('hidden');
}

// ── Render: Persons Panel ─────────────────────────────────
function renderPersonsPanel() {
    const container = $('#persons-container');
    if (!container) return;

    const pending    = state.persons.filter(p => p.status === 'pending');
    const associated = state.persons.filter(p => p.status === 'associated');

    const pendingCount    = $('#persons-pending-count');
    const associatedCount = $('#persons-associated-count');

    if (pendingCount)    pendingCount.textContent    = pending.length;
    if (associatedCount) associatedCount.textContent = associated.length;

    // Sync panel-title sub-count badge
    const pendingCount2 = $('#persons-pending-count-2');
    if (pendingCount2) pendingCount2.textContent = pending.length;

    // Pending list
    const pendingList = $('#persons-pending-list');
    if (pendingList) {
        if (pending.length === 0) {
            pendingList.innerHTML = `
                <div class="empty-state">
                    <i class="bi bi-check2-all"></i>
                    <p>¡Todos los registros han sido asociados!</p>
                </div>`;
        } else {
            pendingList.innerHTML = '';
            pending.forEach(p => pendingList.appendChild(createPersonRow(p)));
        }
    }

    // Associated list
    const assocList = $('#persons-associated-list');
    if (assocList) {
        if (associated.length === 0) {
            assocList.innerHTML = `<div class="empty-state" style="padding:1rem"><i class="bi bi-person-slash"></i><p>Sin asociados aún.</p></div>`;
        } else {
            assocList.innerHTML = '';
            associated.forEach(p => assocList.appendChild(createPersonRow(p)));
        }
    }
}

function createPersonRow(person) {
    const row = document.createElement('div');
    row.className = `person-row ${person.status}`;
    row.dataset.doc = person.documento;

    const isAssoc = person.status === 'associated';
    const pdfName = isAssoc && state.pdfs[person.pdf_id]
        ? state.pdfs[person.pdf_id].original_name
        : '';

    row.innerHTML = `
        <div class="person-num">${pad2(person.order)}</div>
        <div class="person-info">
            <div class="person-name">${escHtml(person.nombres)} ${escHtml(person.apellidos)}</div>
            <div class="person-doc">${escHtml(person.documento)}${pdfName ? ` <span style="color:var(--success);font-size:.6rem">• ${escHtml(pdfName)}</span>` : ''}</div>
        </div>
        <div class="person-badges">
            <span class="badge-status ${isAssoc ? 'badge-associated' : 'badge-pending'}">
                ${isAssoc ? '✅ Asociado' : '🟡 Pendiente'}
            </span>
        </div>
        <div class="person-actions">
            ${isAssoc
                ? `<button class="btn-icon danger" title="Quitar asociación" onclick="removeAssociation('${escAttr(person.documento)}')">
                       <i class="bi bi-x-lg"></i>
                   </button>`
                : `<button class="btn-icon" title="Asociar PDF manualmente" onclick="openAssocModal('${escAttr(person.documento)}')">
                       <i class="bi bi-paperclip"></i>
                   </button>`
            }
        </div>`;

    // DnD drop target (only for pending persons)
    if (!isAssoc) {
        row.addEventListener('dragover', e => {
            e.preventDefault();
            row.classList.add('drag-over');
        });
        row.addEventListener('dragleave', () => {
            row.classList.remove('drag-over');
        });
        row.addEventListener('drop', e => {
            e.preventDefault();
            row.classList.remove('drag-over');
            if (state.draggedPdfId) {
                associate(state.draggedPdfId, person.documento);
            }
        });
    }

    return row;
}

// ── Render: PDF Panel ─────────────────────────────────────
function renderPdfsPanel() {
    const grid = $('#pdfs-grid');
    if (!grid) return;

    const allPdfs  = Object.values(state.pdfs);
    const pending  = allPdfs.filter(p => p.status === 'pending');
    const associated = allPdfs.filter(p => p.status === 'associated');

    const countEl = $('#pdfs-pending-count');
    if (countEl) countEl.textContent = pending.length;

    const assocCountEl = $('#pdfs-associated-count');
    if (assocCountEl) assocCountEl.textContent = associated.length;

    if (allPdfs.length === 0) {
        grid.innerHTML = `
            <div class="empty-state" style="grid-column:1/-1;padding:2rem">
                <i class="bi bi-file-earmark-pdf"></i>
                <p>Cargue archivos PDF para comenzar.</p>
            </div>`;
        return;
    }

    grid.innerHTML = '';

    // Show pending first, then associated (dimmed)
    [...pending, ...associated].forEach(pdf => {
        const card = createPdfCard(pdf);
        grid.appendChild(card);
        renderThumbnail(pdf.id, card.querySelector('canvas'));
    });
}

function createPdfCard(pdf) {
    const card = document.createElement('div');
    card.className = `pdf-card ${pdf.status === 'associated' ? 'associated' : ''}`;
    card.dataset.pdfId = pdf.id;

    const shortName = pdf.original_name.length > 20
        ? pdf.original_name.substring(0, 17) + '…'
        : pdf.original_name;

    card.innerHTML = `
        <div class="pdf-thumbnail-wrap">
            <canvas></canvas>
            <div class="pdf-thumb-placeholder" style="display:none">
                <i class="bi bi-file-earmark-pdf-fill text-danger"></i>
                <span>PDF</span>
            </div>
            ${pdf.status === 'associated' ? '<span class="badge-assoc-person">✓</span>' : ''}
        </div>
        <div class="pdf-card-body">
            <div class="pdf-name" title="${escAttr(pdf.original_name)}">${escHtml(shortName)}</div>
            <div class="pdf-pages"><i class="bi bi-file-text"></i> ${pdf.pages} página${pdf.pages !== 1 ? 's' : ''}</div>
            <div class="pdf-card-actions">
                <button class="btn btn-outline-secondary btn-sm" onclick="openPdfViewer('${escAttr(pdf.id)}', '${escAttr(pdf.original_name)}', ${pdf.pages})" title="Ver PDF">
                    <i class="bi bi-eye"></i> Ver
                </button>
                ${pdf.status === 'pending'
                    ? `<button class="btn btn-outline-primary btn-sm" onclick="openAssocModalByPdf('${escAttr(pdf.id)}')" title="Asociar a persona">
                           <i class="bi bi-link"></i>
                       </button>`
                    : ''
                }
                <button class="btn btn-outline-danger btn-sm ms-auto" onclick="removePdf('${escAttr(pdf.id)}')" title="Eliminar PDF">
                    <i class="bi bi-trash3"></i>
                </button>
            </div>
        </div>`;

    if (pdf.status === 'pending') {
        card.draggable = true;
        card.addEventListener('dragstart', e => {
            state.draggedPdfId = pdf.id;
            card.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'link';
            e.dataTransfer.setData('text/plain', pdf.id);
            // Highlight pending person rows
            $$('.person-row.pending').forEach(r => r.classList.add('drop-active'));
        });
        card.addEventListener('dragend', () => {
            state.draggedPdfId = null;
            card.classList.remove('dragging');
            $$('.person-row').forEach(r => {
                r.classList.remove('drop-active');
                r.classList.remove('drag-over');
            });
        });
    }

    return card;
}

// ── PDF Thumbnail (PDF.js) ────────────────────────────────
const thumbnailCache = {};

async function renderThumbnail(pdfId, canvas) {
    if (!canvas) return;
    if (typeof pdfjsLib === 'undefined') {
        showPlaceholder(canvas);
        return;
    }

    const url = `${API_URL}/pdf/file/${pdfId}`;

    try {
        let pdfDoc = thumbnailCache[pdfId];
        if (!pdfDoc) {
            pdfDoc = await pdfjsLib.getDocument({ url, cMapPacked: true }).promise;
            thumbnailCache[pdfId] = pdfDoc;
        }

        const page     = await pdfDoc.getPage(1);
        const vp       = page.getViewport({ scale: 1 });
        const scale    = Math.min(140 / vp.width, 120 / vp.height);
        const viewport = page.getViewport({ scale });

        canvas.width  = viewport.width;
        canvas.height = viewport.height;

        await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;

        // Hide placeholder
        const placeholder = canvas.closest('.pdf-thumbnail-wrap')?.querySelector('.pdf-thumb-placeholder');
        if (placeholder) placeholder.style.display = 'none';
        canvas.style.display = 'block';
    } catch {
        showPlaceholder(canvas);
    }
}

function showPlaceholder(canvas) {
    canvas.style.display = 'none';
    const placeholder = canvas.closest('.pdf-thumbnail-wrap')?.querySelector('.pdf-thumb-placeholder');
    if (placeholder) placeholder.style.display = 'flex';
}

// ── PDF Viewer Modal ──────────────────────────────────────
async function openPdfViewer(pdfId, pdfName, pages) {
    const modal    = $('#modal-pdf-viewer');
    const title    = $('#modal-pdf-title');
    const body     = $('#modal-pdf-body');
    const url      = `${API_URL}/pdf/file/${pdfId}`;

    if (title) title.textContent = pdfName;
    if (body)  body.innerHTML = '<div class="text-center p-4"><div class="spinner-border text-primary"></div><p class="mt-2 text-muted">Cargando…</p></div>';

    const bsModal = new bootstrap.Modal(modal);
    bsModal.show();

    if (typeof pdfjsLib === 'undefined') {
        body.innerHTML = `<p class="text-center text-muted p-4">Visor no disponible. <a href="${url}" target="_blank">Abrir en nueva pestaña</a></p>`;
        return;
    }

    try {
        let pdfDoc = thumbnailCache[pdfId];
        if (!pdfDoc) {
            pdfDoc = await pdfjsLib.getDocument({ url, cMapPacked: true }).promise;
            thumbnailCache[pdfId] = pdfDoc;
        }

        body.innerHTML = '';

        for (let i = 1; i <= pdfDoc.numPages; i++) {
            const page     = await pdfDoc.getPage(i);
            const vp       = page.getViewport({ scale: 1 });
            const scale    = Math.min(700 / vp.width, 900 / vp.height, 1.8);
            const viewport = page.getViewport({ scale });

            const wrapper = document.createElement('div');
            wrapper.innerHTML = `<div class="modal-page-num">Página ${i} de ${pdfDoc.numPages}</div>`;

            const canvas = document.createElement('canvas');
            canvas.className  = 'pdf-page-canvas';
            canvas.width      = viewport.width;
            canvas.height     = viewport.height;
            canvas.style.maxWidth = '100%';

            wrapper.appendChild(canvas);
            body.appendChild(wrapper);

            await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
        }
    } catch (e) {
        body.innerHTML = `<p class="text-center text-danger p-4">Error al cargar el PDF: ${escHtml(e.message)}<br><a href="${url}" target="_blank">Intentar abrir directamente</a></p>`;
    }
}

// ── Association Modal (person-based) ─────────────────────
function openAssocModal(documento) {
    const person = state.persons.find(p => p.documento === documento);
    if (!person) return;

    const pendingPdfs = Object.values(state.pdfs).filter(p => p.status === 'pending');

    if (pendingPdfs.length === 0) {
        toast('No hay PDFs pendientes disponibles. Cargue archivos PDF primero.', 'warning');
        return;
    }

    const modal     = $('#modal-assoc');
    const titleEl   = $('#modal-assoc-title');
    const subjectEl = $('#modal-assoc-subject');
    const listEl    = $('#modal-assoc-pdf-list');

    if (titleEl)   titleEl.textContent   = 'Seleccionar PDF';
    if (subjectEl) subjectEl.textContent = `${person.nombres} ${person.apellidos} — ${person.documento}`;

    if (listEl) {
        listEl.innerHTML = '';
        pendingPdfs.forEach(pdf => {
            const item = document.createElement('div');
            item.className = 'assoc-pdf-item';
            item.innerHTML = `
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-file-earmark-pdf-fill text-danger"></i>
                    <div>
                        <div style="font-weight:600;font-size:.82rem">${escHtml(pdf.original_name)}</div>
                        <div style="font-size:.7rem;color:var(--text-muted)">${pdf.pages} página(s)</div>
                    </div>
                </div>`;
            item.addEventListener('click', () => {
                bootstrap.Modal.getInstance(modal)?.hide();
                associate(pdf.id, documento);
            });
            listEl.appendChild(item);
        });
    }

    new bootstrap.Modal(modal).show();
}

// ── Association Modal (pdf-based) ─────────────────────────
function openAssocModalByPdf(pdfId) {
    const pdf = state.pdfs[pdfId];
    if (!pdf || pdf.status !== 'pending') return;

    const pendingPersons = state.persons.filter(p => p.status === 'pending');

    if (pendingPersons.length === 0) {
        toast('No hay personas pendientes. Todos están asociados.', 'warning');
        return;
    }

    const modal     = $('#modal-assoc');
    const titleEl   = $('#modal-assoc-title');
    const subjectEl = $('#modal-assoc-subject');
    const listEl    = $('#modal-assoc-pdf-list');

    if (titleEl)   titleEl.textContent   = 'Seleccionar Persona';
    if (subjectEl) subjectEl.textContent = `PDF: ${pdf.original_name} (${pdf.pages} pág.)`;

    if (listEl) {
        listEl.innerHTML = '';
        pendingPersons.forEach(person => {
            const item = document.createElement('div');
            item.className = 'assoc-person-item';
            item.innerHTML = `
                <div class="d-flex align-items-center gap-2">
                    <span style="font-size:.7rem;color:var(--text-muted);font-weight:700;min-width:24px">${pad2(person.order)}</span>
                    <div>
                        <div style="font-weight:600;font-size:.82rem">${escHtml(person.nombres)} ${escHtml(person.apellidos)}</div>
                        <div style="font-size:.7rem;color:var(--text-muted)">${escHtml(person.documento)}</div>
                    </div>
                </div>`;
            item.addEventListener('click', () => {
                bootstrap.Modal.getInstance(modal)?.hide();
                associate(pdfId, person.documento);
            });
            listEl.appendChild(item);
        });
    }

    new bootstrap.Modal(modal).show();
}

// ── Escape helpers ────────────────────────────────────────
function escHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function escAttr(str) {
    return String(str).replace(/'/g, "\\'").replace(/"/g, '&quot;');
}

// ── Collapsed sections toggle ─────────────────────────────
function toggleSection(id) {
    const el = $(id);
    if (!el) return;
    const nowHidden = el.classList.toggle('hidden');
    // Sync chevron icon on the toggle button
    const btn = $('#btn-toggle-associated');
    if (btn) {
        const icon = btn.querySelector('i');
        if (icon) icon.className = `bi bi-chevron-${nowHidden ? 'down' : 'up'}`;
    }
}

// ── Init ──────────────────────────────────────────────────
async function init() {
    // Restore session state
    try {
        const data = await apiGet('excel/data');
        if (data.success && data.persons && data.persons.length > 0) {
            applyServerState(data);
            state.generated = data.generated || false;
            showWorkArea();
            renderPersonsPanel();
            renderPdfsPanel();
            updateProgress();
            updateActionButtons();
            toast(`Sesión restaurada: ${data.persons.length} registros.`, 'info', 3000);
        }
    } catch {
        // No session to restore — start fresh
    }

    // Excel upload input
    const excelInput = $('#excel-input');
    if (excelInput) {
        excelInput.addEventListener('change', e => {
            if (e.target.files[0]) handleExcelUpload(e.target.files[0]);
            e.target.value = '';
        });
    }

    // Excel drag & drop on upload zone
    const excelZone = $('#excel-zone');
    if (excelZone) {
        excelZone.addEventListener('dragover', e => { e.preventDefault(); excelZone.classList.add('dragover'); });
        excelZone.addEventListener('dragleave', () => excelZone.classList.remove('dragover'));
        excelZone.addEventListener('drop', e => {
            e.preventDefault();
            excelZone.classList.remove('dragover');
            const f = e.dataTransfer.files[0];
            if (f) handleExcelUpload(f);
        });
    }

    // PDF upload input
    const pdfInput = $('#pdf-input');
    if (pdfInput) {
        pdfInput.addEventListener('change', e => {
            if (e.target.files.length > 0) handlePdfUpload(e.target.files);
            e.target.value = '';
        });
    }

    // PDF drag & drop on pdf zone
    const pdfZone = $('#pdf-zone');
    if (pdfZone) {
        pdfZone.addEventListener('dragover', e => { e.preventDefault(); pdfZone.classList.add('dragover'); });
        pdfZone.addEventListener('dragleave', () => pdfZone.classList.remove('dragover'));
        pdfZone.addEventListener('drop', e => {
            e.preventDefault();
            pdfZone.classList.remove('dragover');
            if (e.dataTransfer.files.length > 0) handlePdfUpload(e.dataTransfer.files);
        });
    }

    // Action buttons
    const btnGen  = $('#btn-generate');
    const btnDown = $('#btn-download');
    const btnRes  = $('#btn-reset');

    if (btnGen)  btnGen.addEventListener('click',  generatePdf);
    if (btnDown) btnDown.addEventListener('click', downloadPdf);
    if (btnRes)  btnRes.addEventListener('click',  resetSession);
    // Toggle for associated section is handled by toggleSection() called from the div onclick
}

document.addEventListener('DOMContentLoaded', init);
