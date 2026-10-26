// Recipe Manager "Translate selected": preview dialog, then one AJAX request per
// recipe with a progress display, then a summary. Server side is in
// recipe-bulk-translate.php; RECIPE_BULK_TRANSLATE (ajaxUrl, nonce, languages) is
// printed by page-recipe-manager.php.

let bulkTranslateRunning = false;

function bulkTranslateEscape(text) {
    const div = document.createElement('div');
    div.textContent = text == null ? '' : String(text);
    return div.innerHTML;
}

function bulkTranslateRecipeLabel(title, id) {
    return (title ? bulkTranslateEscape(title) + ' ' : '') + '<span style="color: #666;">#' + parseInt(id, 10) + '</span>';
}

function bulkTranslateLink(url, text) {
    return '<a href="' + bulkTranslateEscape(url) + '" target="_blank" rel="noopener">' + text + '</a>';
}

function bulkTranslatePost(fields) {
    const formData = new FormData();
    formData.append('nonce', RECIPE_BULK_TRANSLATE.nonce);
    for (const key in fields) {
        if (Array.isArray(fields[key])) {
            fields[key].forEach(value => formData.append(key + '[]', value));
        } else {
            formData.append(key, fields[key]);
        }
    }
    return fetch(RECIPE_BULK_TRANSLATE.ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(response => response.json())
        .then(data => {
            if (!data || !data.success) {
                throw new Error(data && data.data && data.data.message ? data.data.message : 'Unknown error');
            }
            return data.data;
        });
}

// Body of the dialog box; the overlay itself stays in place between steps
function setBulkTranslateBody(html) {
    document.getElementById('bulkTranslateBody').innerHTML = html;
}

function openBulkTranslateDialog() {
    const recipeIds = Array.from(document.querySelectorAll('.recipe-checkbox:checked')).map(cb => cb.value);
    if (recipeIds.length === 0) {
        alert('Please select recipes to translate');
        return;
    }

    closeBulkTranslateDialog();

    let options = '';
    RECIPE_BULK_TRANSLATE.languages.forEach(language => {
        const selected = language === 'French' ? ' selected' : '';
        options += `<option value="${bulkTranslateEscape(language)}"${selected}>${bulkTranslateEscape(language)}</option>`;
    });

    const recipeWord = recipeIds.length === 1 ? 'recipe' : 'recipes';
    const html = `
        <div style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 10000; display: flex; align-items: center; justify-content: center;" id="bulkTranslateDialog">
            <div style="background: white; padding: 30px; border-radius: 8px; max-width: 700px; width: 90%; max-height: 85vh; overflow-y: auto;" id="bulkTranslateBody">
                <h2 style="margin-top: 0;">🌐 Translate ${recipeIds.length} ${recipeWord}</h2>
                <p>Each translation is saved as a new recipe in your collection. The selected recipes are not changed.</p>
                <label for="bulkTranslateLanguage"><strong>Translate into:</strong></label>
                <select id="bulkTranslateLanguage" style="width: 100%; padding: 10px; font-size: 15px; margin: 8px 0 20px; border: 2px solid #ddd; border-radius: 4px;">
                    ${options}
                </select>
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" onclick="closeBulkTranslateDialog()" style="padding: 10px 20px; background: #666; color: white; border: none; border-radius: 4px; cursor: pointer;">Cancel</button>
                    <button type="button" id="bulkTranslatePreviewBtn" style="padding: 10px 20px; background: #17a2b8; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">Preview</button>
                </div>
            </div>
        </div>
    `;
    document.body.insertAdjacentHTML('beforeend', html);
    document.getElementById('bulkTranslatePreviewBtn').onclick = function() {
        previewBulkTranslate(recipeIds, document.getElementById('bulkTranslateLanguage').value);
    };
}

function closeBulkTranslateDialog() {
    if (bulkTranslateRunning) {
        return;
    }
    const dialog = document.getElementById('bulkTranslateDialog');
    if (dialog) dialog.remove();
}

function previewBulkTranslate(recipeIds, targetLanguage) {
    setBulkTranslateBody('<h2 style="margin-top: 0;">⏳ Checking selected recipes...</h2>');

    bulkTranslatePost({ action: 'recipe_bulk_translate_preview', target_language: targetLanguage, recipe_ids: recipeIds })
        .then(showBulkTranslatePreview)
        .catch(error => {
            setBulkTranslateBody(`
                <h2 style="margin-top: 0;">❌ Could not prepare the translation</h2>
                <p>${bulkTranslateEscape(error.message)}</p>
                <div style="display: flex; justify-content: flex-end;">
                    <button type="button" onclick="closeBulkTranslateDialog()" style="padding: 10px 20px; background: #666; color: white; border: none; border-radius: 4px; cursor: pointer;">Close</button>
                </div>
            `);
        });
}

function bulkTranslateDecisionLabel(row, target) {
    if (row.decision === 'create' && row.deferred) {
        return '<span style="color: #666;">⏸ NOT THIS RUN (limit reached)</span>';
    }
    if (row.decision === 'create') {
        return row.lang_unknown
            ? `<strong style="color: #155724;">CREATE</strong> (language unknown, will be skipped if already in ${bulkTranslateEscape(target)})`
            : '<strong style="color: #155724;">CREATE</strong>';
    }
    if (row.decision === 'reuse') {
        return '<strong style="color: #0073aa;">REUSE</strong> ' + bulkTranslateLink(row.copy_url, 'existing #' + parseInt(row.copy_id, 10));
    }
    if (row.decision === 'skip') {
        return '<strong style="color: #856404;">SKIP</strong> ' + bulkTranslateEscape(row.message);
    }
    return '<strong style="color: #721c24;">ERROR</strong> ' + bulkTranslateEscape(row.message);
}

function showBulkTranslatePreview(preview) {
    const target = preview.target;
    let rowsHtml = '';
    preview.rows.forEach(row => {
        const from = row.source_id !== row.id
            ? `<div style="font-size: 13px; color: #666;">from #${parseInt(row.source_id, 10)} ${bulkTranslateEscape(row.source_title)}</div>`
            : '';
        rowsHtml += `
            <tr>
                <td style="padding: 6px; border-bottom: 1px solid #eee;">${bulkTranslateRecipeLabel(row.title, row.id)}${from}</td>
                <td style="padding: 6px; border-bottom: 1px solid #eee;">${bulkTranslateDecisionLabel(row, target)}</td>
            </tr>
        `;
    });

    const counts = preview.counts;
    let summary = `${counts.create} to create, ${counts.reuse} already translated (reuse), ${counts.skip} already in ${bulkTranslateEscape(target)} (skip)`;
    if (counts.error) summary += `, ${counts.error} not accessible`;
    let limitNote = '';
    if (counts.deferred) {
        limitNote = `<p style="background: #fff3cd; padding: 10px; border-radius: 4px;">At most ${preview.max_creates} recipes are translated per run. ${counts.deferred} more will be left for next time: run Translate selected again afterwards.</p>`;
    }

    const planIds = preview.plan_ids;
    const confirmButton = planIds.length
        ? `<button type="button" id="bulkTranslateConfirmBtn" style="padding: 10px 20px; background: #17a2b8; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">Translate ${planIds.length} ${planIds.length === 1 ? 'recipe' : 'recipes'}</button>`
        : '';

    setBulkTranslateBody(`
        <h2 style="margin-top: 0;">🌐 Translate into ${bulkTranslateEscape(target)}</h2>
        <p>${summary}.</p>
        ${limitNote}
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 14px;">${rowsHtml}</table>
        ${planIds.length ? '' : '<p>Nothing to create.</p>'}
        <div style="display: flex; gap: 10px; justify-content: flex-end;">
            <button type="button" onclick="closeBulkTranslateDialog()" style="padding: 10px 20px; background: #666; color: white; border: none; border-radius: 4px; cursor: pointer;">${planIds.length ? 'Cancel' : 'Close'}</button>
            ${confirmButton}
        </div>
    `);

    if (planIds.length) {
        document.getElementById('bulkTranslateConfirmBtn').onclick = function() {
            runBulkTranslate(preview);
        };
    }
}

async function runBulkTranslate(preview) {
    if (bulkTranslateRunning) {
        return;
    }
    bulkTranslateRunning = true;

    const titles = {};
    preview.rows.forEach(row => { titles[row.id] = row.title; });

    // REUSE and SKIP rows from the preview go straight into the summary
    const results = preview.rows
        .filter(row => row.decision === 'reuse' || row.decision === 'skip')
        .map(row => ({
            id: row.id,
            title: row.title,
            status: row.decision === 'reuse' ? 'reused' : 'skipped',
            url: row.copy_url,
            new_id: row.copy_id,
            message: row.message
        }));

    const planIds = preview.plan_ids;
    for (let i = 0; i < planIds.length; i++) {
        setBulkTranslateBody(`
            <h2 style="margin-top: 0;">⏳ Translating ${i + 1} of ${planIds.length}...</h2>
            <p>${bulkTranslateRecipeLabel(titles[planIds[i]], planIds[i])}</p>
            <div style="background: #eee; border-radius: 4px; height: 12px; overflow: hidden;">
                <div style="background: #17a2b8; height: 12px; width: ${Math.round(i / planIds.length * 100)}%;"></div>
            </div>
            <p style="color: #666;">Please don't close this window.</p>
        `);
        try {
            results.push(await bulkTranslatePost({ action: 'recipe_bulk_translate_one', recipe_id: planIds[i] }));
        } catch (error) {
            results.push({ id: planIds[i], title: titles[planIds[i]], status: 'failed', message: error.message });
        }
    }

    bulkTranslateRunning = false;
    showBulkTranslateSummary(preview, results);
}

function showBulkTranslateSummary(preview, results) {
    const groups = [
        { status: 'created', label: 'Created' },
        { status: 'reused', label: 'Already translated (reused)' },
        { status: 'skipped', label: 'Skipped' },
        { status: 'failed', label: 'Failed' }
    ];

    let html = `<h2 style="margin-top: 0;">🌐 Translation into ${bulkTranslateEscape(preview.target)} finished</h2>`;
    groups.forEach(group => {
        const items = results.filter(result => result.status === group.status);
        html += `<h3 style="margin-bottom: 5px;">${group.label}: ${items.length}</h3>`;
        if (!items.length) return;
        html += '<ul style="margin-top: 0;">';
        items.forEach(item => {
            let line = bulkTranslateRecipeLabel(item.title, item.id);
            if (item.url) {
                line += ' → ' + bulkTranslateLink(item.url, (item.new_title ? bulkTranslateEscape(item.new_title) + ' ' : '') + '#' + parseInt(item.new_id, 10));
            }
            if (item.message) {
                line += ` <span style="color: ${item.status === 'failed' ? '#721c24' : '#666'};">${bulkTranslateEscape(item.message)}</span>`;
            }
            html += `<li>${line}</li>`;
        });
        html += '</ul>';
    });

    if (preview.counts.deferred) {
        html += `<p style="background: #fff3cd; padding: 10px; border-radius: 4px;">${preview.counts.deferred} more selected recipes were not translated in this run (limit ${preview.max_creates}). Run Translate selected again to continue.</p>`;
    }

    const created = results.some(result => result.status === 'created');
    html += `
        <div style="display: flex; justify-content: flex-end;">
            <button type="button" onclick="${created ? 'window.location.reload()' : 'closeBulkTranslateDialog()'}" style="padding: 10px 20px; background: #666; color: white; border: none; border-radius: 4px; cursor: pointer;">Close</button>
        </div>
    `;
    setBulkTranslateBody(html);
}
