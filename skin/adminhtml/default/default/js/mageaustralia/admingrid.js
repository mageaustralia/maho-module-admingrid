/**
 * MageAustralia AdminGrid — Column visibility, ordering, and saved profiles.
 *
 * Vanilla ES6, no build step. Auto-detects Maho admin grids.
 *
 * Grid DOM structure (Maho):
 *   <div id="productGrid">
 *     <table class="actions">…pager/filter…</table>
 *     <div id="productGrid_massaction">…</div>
 *     <div class="grid">
 *       <div class="hor-scroll">
 *         <table class="data" id="productGrid_table">
 *           <thead>
 *             <tr class="headings">
 *               <th data-column-id="entity_id">…</th>
 *             </tr>
 *             <tr class="filter">…</tr>
 *           </thead>
 *         </table>
 *       </div>
 *     </div>
 *   </div>
 */
(function () {
    'use strict';

    const STORAGE_PREFIX = 'admingrid_';
    const INITIALIZED = new WeakSet();

    class AdminGrid {
        constructor(gridEl) {
            this.gridEl = gridEl;
            this.gridBlockId = gridEl.id;
            this.dataTable = gridEl.querySelector('table.data');
            this.gridId = null;
            this.profileId = null;
            this.profiles = [];
            this.columns = [];
            this.config = [];
            this.configByCode = {};
            this.isDirty = false;
            this.toolbar = null;

            this.init();
        }

        async init() {
            this.columns = this.readColumnsFromDom();
            if (this.columns.length === 0) return;

            // Apply cached config instantly (before server round-trip)
            const cached = this.loadFromCache();
            if (cached) {
                this.config = cached;
                this.buildConfigIndex();
                this.applyConfig();
            }

            // Load from server (source of truth)
            await this.loadFromServer();

            // Build toolbar UI
            this.buildToolbar();

            // Re-apply after varienGrid AJAX reloads
            this.observeGridReloads();
        }

        // ── Column reading ──────────────────────────────────────────────

        readColumnsFromDom() {
            const headerRow = this.dataTable?.querySelector('thead tr.headings');
            if (!headerRow) return [];

            const columns = [];
            headerRow.querySelectorAll('th').forEach((th, index) => {
                const code = th.getAttribute('data-column-id');
                if (!code) return; // skip ths without column id

                const sortTitle = th.querySelector('.sort-title');
                const header = sortTitle ? sortTitle.textContent.trim() : th.textContent.trim();

                columns.push({ code, header, index });
            });
            return columns;
        }

        // ── localStorage cache ──────────────────────────────────────────

        loadFromCache() {
            try {
                const raw = localStorage.getItem(STORAGE_PREFIX + this.gridBlockId);
                if (!raw) return null;
                const parsed = JSON.parse(raw);
                return Array.isArray(parsed) ? parsed : null;
            } catch { return null; }
        }

        saveToCache() {
            try {
                localStorage.setItem(
                    STORAGE_PREFIX + this.gridBlockId,
                    JSON.stringify(this.config),
                );
            } catch { /* full or unavailable */ }
        }

        // ── Server communication ────────────────────────────────────────

        async loadFromServer() {
            const loadUrl = this.getConfigUrl('load');
            if (!loadUrl) return;

            try {
                const sep = loadUrl.includes('?') ? '&' : '?';
                const url = `${loadUrl}${sep}grid_block_id=${encodeURIComponent(this.gridBlockId)}&isAjax=true`;
                const resp = await fetch(url, { credentials: 'same-origin' });
                if (!resp.ok) return;
                const data = await resp.json();
                if (data.ajaxExpired || data.error) return;

                this.gridId = data.gridId;
                this.profileId = data.profileId;
                this.profiles = data.profiles || [];

                if (data.config && data.config.length > 0) {
                    this.config = data.config;
                    this.buildConfigIndex();
                    this.applyConfig();
                    this.saveToCache();
                }
            } catch (e) {
                console.warn('AdminGrid: load error', e);
            }
        }

        async postAction(actionKey, params) {
            const url = this.getConfigUrl(actionKey);
            if (!url) throw new Error(`AdminGrid: no URL for ${actionKey}`);

            params.form_key = typeof FORM_KEY !== 'undefined' ? FORM_KEY : '';
            params.isAjax = 'true';

            const resp = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams(params).toString(),
            });
            const data = await resp.json();
            if (data.ajaxExpired) {
                location.reload();
                throw new Error('Session expired');
            }
            return data;
        }

        /**
         * Build admin URL for an action.
         * Extracts the admin base path from the current page URL.
         */
        getConfigUrl(action) {
            if (!AdminGrid._adminBase) {
                // Extract base from current page or nav links
                // Pattern: https://host/index.php/twbackoffice/ or https://host/admin/
                const link = document.querySelector('#nav a[href*="/index.php/"]');
                const href = link ? link.href : window.location.href;
                const m = href.match(/^(https?:\/\/[^/]+\/(?:index\.php\/)?[^/]+\/)/);
                AdminGrid._adminBase = m ? m[1] : '/admin/';
            }
            return `${AdminGrid._adminBase}admingrid/${action}`;
        }

        // ── Config helpers ──────────────────────────────────────────────

        buildConfigIndex() {
            this.configByCode = {};
            this.config.forEach(c => { if (c.code) this.configByCode[c.code] = c; });
        }

        isColumnVisible(code) {
            const cfg = this.configByCode[code];
            return cfg ? cfg.visible !== false : true;
        }

        // ── Apply config to DOM ──────────────────────────────────────────

        applyConfig() {
            const id = `admingrid-style-${this.gridBlockId}`;
            document.getElementById(id)?.remove();

            const tableId = this.dataTable?.id;
            if (!tableId) return;

            // Read column indices LIVE from current DOM (not cached)
            const headerRow = this.dataTable.querySelector('thead tr.headings');
            if (!headerRow) return;

            const hidden = [];
            const widths = [];
            const ths = headerRow.querySelectorAll('th');
            ths.forEach((th, idx) => {
                const code = th.getAttribute('data-column-id');
                if (!code) return;
                if (!this.isColumnVisible(code)) {
                    hidden.push(idx + 1);
                }
                const w = this.configByCode[code]?.width;
                if (w) {
                    const val = /^\d+$/.test(w) ? w + 'px' : w;
                    widths.push({ n: idx + 1, w: val });
                }
            });

            const esc = CSS.escape(tableId);
            const style = document.createElement('style');
            style.id = id;
            let css = '';

            if (hidden.length > 0) {
                css += hidden.map(n => `#${esc} tr > :nth-child(${n})`).join(',\n')
                    + ' { display: none !important; }\n';
                css += hidden.map(n => `#${esc} colgroup col:nth-child(${n})`).join(',\n')
                    + ' { display: none !important; }\n';
            }

            widths.forEach(({ n, w }) => {
                css += `#${esc} colgroup col:nth-child(${n}) { width: ${w} !important; }\n`;
                css += `#${esc} tr > :nth-child(${n}) { width: ${w} !important; min-width: ${w} !important; }\n`;
            });

            // Ensure the table doesn't collapse visible columns to 0
            if (hidden.length > 0 || widths.length > 0) {
                css += `#${esc} { table-layout: auto !important; }\n`;
            }

            style.textContent = css;
            document.head.appendChild(style);

            // Sync checkbox state in toolbar
            if (this.toolbar) {
                this.toolbar.querySelectorAll('input[data-col]').forEach(cb => {
                    cb.checked = this.isColumnVisible(cb.dataset.col);
                });
            }
        }

        // ── Toolbar UI ──────────────────────────────────────────────────

        buildToolbar() {
            // Insert before the actions table (pager/filter bar)
            const actionsTable = this.gridEl.querySelector('table.actions');
            if (!actionsTable) return;

            const bar = document.createElement('div');
            bar.className = 'admingrid-toolbar';

            bar.appendChild(this.buildColumnsButton());
            bar.appendChild(this.buildProfileSelector());
            bar.appendChild(this.buildSaveButton());

            this.toolbar = bar;
            actionsTable.parentNode.insertBefore(bar, actionsTable);
        }

        buildColumnsButton() {
            const wrap = document.createElement('div');
            wrap.className = 'admingrid-columns-wrap';

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'scalable';
            btn.innerHTML = '<span>Columns ▾</span>';

            const dd = document.createElement('div');
            dd.className = 'admingrid-dropdown';
            this._columnsDropdown = dd;

            // Section: Current grid columns (with reorder arrows)
            this._addSectionHeader(dd, 'Grid Columns');
            const gridColsContainer = document.createElement('div');
            gridColsContainer.className = 'admingrid-grid-cols';
            dd.appendChild(gridColsContainer);
            this._buildGridColumnRows(gridColsContainer);

            // Section: Available attributes (loaded async)
            const availSection = document.createElement('div');
            availSection.id = `admingrid-avail-${this.gridBlockId}`;
            availSection.className = 'admingrid-avail-section';
            dd.appendChild(availSection);
            this.loadAvailableColumns(availSection);

            // Search/filter input
            const searchWrap = document.createElement('div');
            searchWrap.className = 'admingrid-search';
            const searchInput = document.createElement('input');
            searchInput.type = 'text';
            searchInput.placeholder = 'Filter columns…';
            searchInput.addEventListener('input', () => {
                const q = searchInput.value.toLowerCase();
                dd.querySelectorAll('[data-filter]').forEach(el => {
                    el.style.display = el.dataset.filter.includes(q) ? '' : 'none';
                });
            });
            searchWrap.appendChild(searchInput);
            dd.insertBefore(searchWrap, dd.firstChild);

            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                dd.style.display = dd.style.display === 'none' ? 'block' : 'none';
                if (dd.style.display === 'block') searchInput.focus();
            });

            document.addEventListener('click', (e) => {
                if (!wrap.contains(e.target)) dd.style.display = 'none';
            });

            wrap.appendChild(btn);
            wrap.appendChild(dd);
            return wrap;
        }

        _addSectionHeader(container, text) {
            const h = document.createElement('div');
            h.textContent = text;
            h.className = 'admingrid-section-header';
            container.appendChild(h);
        }

        /**
         * Build grid column rows with checkbox + drag-to-reorder.
         */
        _buildGridColumnRows(container) {
            container.innerHTML = '';
            const ordered = this._getOrderedColumns();
            let dragSrcCode = null;

            ordered.forEach(col => {
                const row = document.createElement('div');
                row.className = 'admingrid-col-row';
                row.draggable = true;
                row.dataset.colCode = col.code;
                row.dataset.filter = (col.header + ' ' + col.code).toLowerCase();

                const grip = document.createElement('span');
                grip.textContent = '≡';
                grip.className = 'admingrid-grip';
                row.appendChild(grip);

                const cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.checked = this.isColumnVisible(col.code);
                cb.dataset.col = col.code;
                const isCustom = col.code.startsWith('custom_');
                cb.addEventListener('mousedown', e => e.stopPropagation());
                cb.addEventListener('change', () => {
                    if (isCustom && !cb.checked) {
                        // Custom column — remove from DB entirely
                        this.removeCustomColumn(col.code);
                    } else {
                        this.toggleColumn(col.code, cb.checked);
                    }
                });
                row.appendChild(cb);

                // Label — click to rename for custom columns
                const label = document.createElement('span');
                label.textContent = col.header || col.code;
                label.className = isCustom ? 'admingrid-label-editable' : 'admingrid-label';
                if (isCustom) {
                    label.title = 'Click to rename';
                    label.addEventListener('click', (e) => {
                        e.stopPropagation();
                        this._startRename(label, col.code, container);
                    });
                }
                row.appendChild(label);

                // Width input
                const widthInput = document.createElement('input');
                widthInput.type = 'text';
                widthInput.className = 'admingrid-width';
                widthInput.placeholder = 'w';
                const savedWidth = this.configByCode[col.code]?.width || '';
                widthInput.value = savedWidth;
                widthInput.title = 'Column width (e.g. 100, 150px)';
                widthInput.addEventListener('mousedown', e => e.stopPropagation());
                widthInput.addEventListener('change', () => {
                    const w = widthInput.value.trim();
                    this.setColumnWidth(col.code, w);
                });
                // Gear icon for composite columns (before width so they align)
                if (isCustom && col.code.startsWith('custom_composite_')) {
                    const gear = document.createElement('button');
                    gear.type = 'button';
                    gear.className = 'admingrid-gear';
                    gear.textContent = '\u2699';
                    gear.title = 'Configure fields & style';
                    gear.addEventListener('mousedown', e => e.stopPropagation());
                    gear.addEventListener('click', (e) => {
                        e.stopPropagation();
                        this._openCompositeConfig(col.code);
                    });
                    row.appendChild(gear);
                }

                row.appendChild(widthInput);

                // Drag events
                row.addEventListener('dragstart', e => {
                    dragSrcCode = col.code;
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', col.code);
                    requestAnimationFrame(() => row.style.opacity = '0.4');
                });

                row.addEventListener('dragend', () => {
                    row.style.opacity = '1';
                    dragSrcCode = null;
                    container.querySelectorAll('.admingrid-col-row').forEach(r => {
                        r.style.borderTop = '1px solid transparent';
                        r.style.borderBottom = '1px solid transparent';
                    });
                });

                row.addEventListener('dragover', e => {
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                    if (col.code === dragSrcCode) return;
                    // Clear all indicators
                    container.querySelectorAll('.admingrid-col-row').forEach(r => {
                        r.style.borderTop = '1px solid transparent';
                        r.style.borderBottom = '1px solid transparent';
                    });
                    // Show drop indicator
                    const rect = row.getBoundingClientRect();
                    const mid = rect.top + rect.height / 2;
                    if (e.clientY < mid) {
                        row.style.borderTop = '2px solid #1979c3';
                    } else {
                        row.style.borderBottom = '2px solid #1979c3';
                    }
                });

                row.addEventListener('drop', e => {
                    e.preventDefault();
                    const srcCode = e.dataTransfer.getData('text/plain');
                    if (!srcCode || srcCode === col.code) return;

                    // Determine insert position (before or after target)
                    const rect = row.getBoundingClientRect();
                    const insertAfter = e.clientY > rect.top + rect.height / 2;

                    this._reorderColumn(srcCode, col.code, insertAfter, container);
                });

                container.appendChild(row);
            });
        }

        /**
         * Open the composite field configurator panel.
         */
        async _openCompositeConfig(code) {
            // Load current config from server
            let config;
            try {
                const url = this.getConfigUrl('getColumnConfig')
                    + `?grid_block_id=${encodeURIComponent(this.gridBlockId)}&column_code=${encodeURIComponent(code)}&isAjax=true`;
                const resp = await fetch(url, { credentials: 'same-origin' });
                const data = await resp.json();
                if (data.error) return;
                config = data.config || {};
            } catch { return; }

            const fieldLabels = config.field_labels || {};
            const fields = config.fields || [];
            const template = config.template || fields.map(f => [f]);
            const style = config.style || 'plain';
            const customCss = config.custom_css || '';

            // Build modal overlay
            const overlay = document.createElement('div');
            overlay.className = 'admingrid-config-overlay';

            const panel = document.createElement('div');
            panel.className = 'admingrid-config-panel';

            // Header
            const header = document.createElement('div');
            header.className = 'admingrid-config-header';
            header.innerHTML = '<strong>Configure Composite Column</strong>';
            const closeBtn = document.createElement('button');
            closeBtn.type = 'button';
            closeBtn.textContent = '\u2715';
            closeBtn.className = 'admingrid-config-close';
            closeBtn.addEventListener('click', () => overlay.remove());
            header.appendChild(closeBtn);
            panel.appendChild(header);

            // Field selector: template lines
            const linesDiv = document.createElement('div');
            linesDiv.className = 'admingrid-config-lines';

            const linesLabel = document.createElement('div');
            linesLabel.className = 'admingrid-config-section-label';
            linesLabel.textContent = 'Fields & Layout';
            linesDiv.appendChild(linesLabel);

            const linesHelp = document.createElement('div');
            linesHelp.className = 'admingrid-config-help';
            linesHelp.textContent = 'Check fields to include. Fields on the same line are separated by a space. Click "+ Line" to start a new line.';
            linesDiv.appendChild(linesHelp);

            // Build the template editor
            const linesContainer = document.createElement('div');
            linesContainer.className = 'admingrid-config-lines-container';

            const usedFields = new Set(template.flat());

            // Render each template line
            const renderLines = () => {
                linesContainer.innerHTML = '';
                template.forEach((lineFields, lineIdx) => {
                    const lineRow = document.createElement('div');
                    lineRow.className = 'admingrid-config-line';

                    const lineLabel = document.createElement('span');
                    lineLabel.className = 'admingrid-config-line-label';
                    lineLabel.textContent = `Line ${lineIdx + 1}:`;
                    lineRow.appendChild(lineLabel);

                    const tags = document.createElement('div');
                    tags.className = 'admingrid-config-tags';

                    lineFields.forEach(field => {
                        // Skip internal fields in the UI
                        if (field === 'product_id' || field.startsWith('_')) return;
                        const tag = document.createElement('span');
                        tag.className = 'admingrid-config-tag';
                        tag.textContent = fieldLabels[field] || field;
                        const removeTag = document.createElement('span');
                        removeTag.className = 'admingrid-config-tag-remove';
                        removeTag.textContent = '\u2715';
                        removeTag.addEventListener('click', () => {
                            template[lineIdx] = template[lineIdx].filter(f => f !== field);
                            if (template[lineIdx].length === 0) template.splice(lineIdx, 1);
                            usedFields.delete(field);
                            renderLines();
                            renderAvailable();
                        });
                        tag.appendChild(removeTag);
                        tags.appendChild(tag);
                    });

                    lineRow.appendChild(tags);
                    linesContainer.appendChild(lineRow);
                });

                // Add new line button
                const addLine = document.createElement('button');
                addLine.type = 'button';
                addLine.className = 'admingrid-config-add-line';
                addLine.textContent = '+ Line';
                addLine.addEventListener('click', () => {
                    template.push([]);
                    renderLines();
                });
                linesContainer.appendChild(addLine);
            };

            linesDiv.appendChild(linesContainer);
            panel.appendChild(linesDiv);

            // Available (unchecked) fields
            const availDiv = document.createElement('div');
            availDiv.className = 'admingrid-config-available';

            const availLabel = document.createElement('div');
            availLabel.className = 'admingrid-config-section-label';
            availLabel.textContent = 'Available Fields (click to add)';
            availDiv.appendChild(availLabel);

            const availContainer = document.createElement('div');
            availContainer.className = 'admingrid-config-avail-fields';

            const renderAvailable = () => {
                availContainer.innerHTML = '';
                const hiddenFields = ['product_id', '_thumbnail_url'];
                fields.forEach(field => {
                    if (usedFields.has(field) || hiddenFields.includes(field)) return;
                    const chip = document.createElement('span');
                    chip.className = 'admingrid-config-avail-chip';
                    chip.textContent = fieldLabels[field] || field;
                    chip.addEventListener('click', () => {
                        // Add to last template line (or create one)
                        if (template.length === 0) template.push([]);
                        template[template.length - 1].push(field);
                        usedFields.add(field);
                        renderLines();
                        renderAvailable();
                    });
                    availContainer.appendChild(chip);
                });
            };

            availDiv.appendChild(availContainer);
            panel.appendChild(availDiv);

            // Thumbnail size (only for multi_row composites with product_id)
            let thumbSizeInput = null;
            if (config.multi_row && fields.includes('product_id')) {
                const thumbDiv = document.createElement('div');
                thumbDiv.className = 'admingrid-config-thumb';

                const thumbLabel = document.createElement('div');
                thumbLabel.className = 'admingrid-config-section-label';
                thumbLabel.textContent = 'Thumbnail Size (px)';
                thumbDiv.appendChild(thumbLabel);

                thumbSizeInput = document.createElement('input');
                thumbSizeInput.type = 'number';
                thumbSizeInput.className = 'admingrid-config-thumb-input';
                thumbSizeInput.value = config.thumbnail_size || 40;
                thumbSizeInput.min = 0;
                thumbSizeInput.max = 200;
                thumbSizeInput.placeholder = '40';
                thumbDiv.appendChild(thumbSizeInput);

                const thumbHelp = document.createElement('div');
                thumbHelp.className = 'admingrid-config-help';
                thumbHelp.textContent = 'Set to 0 to hide thumbnails.';
                thumbDiv.appendChild(thumbHelp);

                panel.appendChild(thumbDiv);
            }

            // Style selector
            const styleDiv = document.createElement('div');
            styleDiv.className = 'admingrid-config-style';

            const styleLabel = document.createElement('div');
            styleLabel.className = 'admingrid-config-section-label';
            styleLabel.textContent = 'Style';
            styleDiv.appendChild(styleLabel);

            const styleSelect = document.createElement('select');
            styleSelect.className = 'admingrid-config-style-select';
            [['plain', 'Plain'], ['card', 'Card (light bg)'], ['bordered', 'Bordered'], ['compact', 'Compact'], ['custom', 'Custom CSS']].forEach(([val, label]) => {
                const opt = document.createElement('option');
                opt.value = val;
                opt.textContent = label;
                if (val === style) opt.selected = true;
                styleSelect.appendChild(opt);
            });
            styleDiv.appendChild(styleSelect);

            // Custom CSS textarea
            const cssInput = document.createElement('textarea');
            cssInput.className = 'admingrid-config-css-input';
            cssInput.placeholder = 'e.g. border: 1px dotted red; background: #f0f0f0; padding: 4px;';
            cssInput.value = customCss;
            cssInput.style.display = style === 'custom' ? 'block' : 'none';
            styleSelect.addEventListener('change', () => {
                cssInput.style.display = styleSelect.value === 'custom' ? 'block' : 'none';
            });
            styleDiv.appendChild(cssInput);
            panel.appendChild(styleDiv);

            // Save button
            const saveBtn = document.createElement('button');
            saveBtn.type = 'button';
            saveBtn.className = 'scalable task';
            saveBtn.innerHTML = '<span>Save Configuration</span>';
            saveBtn.addEventListener('click', async () => {
                // Build updated config
                const cleanTemplate = template.filter(line => line.length > 0);

                const updatedConfig = {
                    ...config,
                    template: cleanTemplate,
                    thumbnail_size: thumbSizeInput ? parseInt(thumbSizeInput.value) || 0 : (config.thumbnail_size || 0),
                    style: styleSelect.value,
                    custom_css: cssInput.value.trim(),
                };

                try {
                    await this.postAction('updateColumnConfig', {
                        grid_block_id: this.gridBlockId,
                        column_code: code,
                        source_config: JSON.stringify(updatedConfig),
                    });
                    overlay.remove();
                    this.reloadGrid();
                } catch (e) {
                    console.error('AdminGrid: save config failed', e);
                }
            });
            panel.appendChild(saveBtn);

            renderLines();
            renderAvailable();

            overlay.appendChild(panel);
            overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove(); });
            document.body.appendChild(overlay);
        }

        _getOrderedColumns() {
            const colMap = {};
            this.columns.forEach(c => { if (c.code !== 'massaction') colMap[c.code] = c; });

            const codes = Object.keys(colMap);
            codes.sort((a, b) => {
                const posA = this.configByCode[a]?.position ?? colMap[a]?.index ?? 999;
                const posB = this.configByCode[b]?.position ?? colMap[b]?.index ?? 999;
                return posA - posB;
            });

            return codes.map(c => colMap[c]);
        }

        _startRename(label, code, container) {
            const current = label.textContent;
            const input = document.createElement('input');
            input.type = 'text';
            input.value = current;
            input.className = 'admingrid-rename-input';

            const finish = async (save) => {
                const newName = input.value.trim();
                if (save && newName && newName !== current) {
                    try {
                        await this.postAction('renameColumn', {
                            grid_block_id: this.gridBlockId,
                            column_code: code,
                            header: newName,
                        });
                        label.textContent = newName;
                        this.reloadGrid();
                    } catch (e) {
                        label.textContent = current;
                        console.error('AdminGrid: rename failed', e);
                    }
                }
                input.replaceWith(label);
            };

            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') { e.preventDefault(); finish(true); }
                if (e.key === 'Escape') { finish(false); }
            });
            input.addEventListener('blur', () => finish(true));
            input.addEventListener('mousedown', e => e.stopPropagation());

            label.replaceWith(input);
            input.focus();
            input.select();
        }

        _reorderColumn(srcCode, targetCode, insertAfter, container) {
            this.ensureFullConfig();
            this.buildConfigIndex();

            // Work with codes in current display order
            const ordered = this._getOrderedColumns().map(c => c.code);

            // Remove source from current position
            const srcIdx = ordered.indexOf(srcCode);
            if (srcIdx === -1) { console.warn('AdminGrid: srcCode not found', srcCode); return; }
            ordered.splice(srcIdx, 1);

            // Insert at target position
            let tgtIdx = ordered.indexOf(targetCode);
            if (tgtIdx === -1) { console.warn('AdminGrid: targetCode not found', targetCode); return; }
            if (insertAfter) tgtIdx++;
            ordered.splice(tgtIdx, 0, srcCode);

            // Reassign all positions sequentially
            const newConfig = [];
            ordered.forEach((code, i) => {
                const existing = this.configByCode[code] || { code, visible: true };
                newConfig.push({ ...existing, code, position: i });
            });

            this.config = newConfig;
            this.buildConfigIndex();
            this.saveToCache();
            this.isDirty = true;

            // Rebuild the dropdown list
            this._buildGridColumnRows(container);
        }

        /**
         * Load available (not yet added) attributes from server.
         */
        async loadAvailableColumns(container) {
            try {
                const url = this.getConfigUrl('availableColumns')
                    + `?grid_block_id=${encodeURIComponent(this.gridBlockId)}&isAjax=true`;
                const resp = await fetch(url, { credentials: 'same-origin' });
                if (!resp.ok) return;
                const data = await resp.json();
                if (!data.available || data.available.length === 0) return;

                // Split into groups
                const categories = data.available.filter(a => a.group === 'category');
                const composites = data.available.filter(a => a.group === 'composite');
                const collection = data.available.filter(a => a.group === 'collection');
                const attributes = data.available.filter(a => a.group === 'attribute');

                // Category column (product grids only)
                if (categories.length > 0) {
                    this._addSectionHeader(container, 'Category');
                    categories.forEach(col => this._addAvailableRow(container, col));
                }

                // Composite columns (address views, ordered items)
                if (composites.length > 0) {
                    this._addSectionHeader(container, 'Views');
                    composites.forEach(col => this._addAvailableRow(container, col));
                }

                // Table columns section (flat table fields from DB)
                if (collection.length > 0) {
                    this._addSectionHeader(container, 'Table Columns');
                    collection.sort((a, b) => a.label.localeCompare(b.label));
                    collection.forEach(col => this._addAvailableRow(container, col));
                }

                // EAV attributes section
                if (attributes.length > 0) {
                    this._addSectionHeader(container, 'EAV Attributes');
                    attributes.sort((a, b) => a.label.localeCompare(b.label));
                    attributes.forEach(col => this._addAvailableRow(container, col));
                }
            } catch (e) {
                console.warn('AdminGrid: failed to load available columns', e);
            }
        }

        _addAvailableRow(container, attr) {
            const lbl = document.createElement('label');
            lbl.className = 'admingrid-avail-row';
            lbl.dataset.filter = (attr.label + ' ' + attr.code).toLowerCase();

            const cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.checked = false;
            cb.dataset.attrCode = attr.code;
            cb.dataset.attrLabel = attr.label;
            cb.dataset.attrType = attr.type || 'text';
            cb.dataset.entityType = attr.entityType || '';
            cb.dataset.group = attr.group || 'attribute';
            if (attr.relatedTable) cb.dataset.relatedTable = attr.relatedTable;
            if (attr.joinOn) cb.dataset.joinOn = attr.joinOn;
            if (attr.config) cb.dataset.config = JSON.stringify(attr.config);

            cb.addEventListener('change', () => this.addAttributeColumn(cb));

            lbl.appendChild(cb);
            lbl.appendChild(document.createTextNode(`${attr.label} (${attr.code})`));
            container.appendChild(lbl);
        }

        /**
         * Add an EAV attribute as a custom column (one-click from dropdown).
         */
        async addAttributeColumn(checkbox) {
            const code = checkbox.dataset.attrCode;
            const label = checkbox.dataset.attrLabel;
            const colType = checkbox.dataset.attrType || 'text';
            const entityType = checkbox.dataset.entityType || 'catalog_product';
            const group = checkbox.dataset.group || 'attribute';

            checkbox.disabled = true;

            try {
                const params = {
                    grid_block_id: this.gridBlockId,
                    attribute_code: code,
                    entity_type: entityType,
                    label: label,
                    column_type: colType,
                    group: group,
                };
                if (checkbox.dataset.relatedTable) params.related_table = checkbox.dataset.relatedTable;
                if (checkbox.dataset.joinOn) params.join_on = checkbox.dataset.joinOn;
                if (checkbox.dataset.config) params.config = checkbox.dataset.config;

                const data = await this.postAction('addColumn', params);

                if (data.success) {
                    this.reloadGrid();
                } else {
                    checkbox.checked = false;
                    checkbox.disabled = false;
                    if (data.error) console.warn('AdminGrid:', data.error);
                }
            } catch (e) {
                checkbox.checked = false;
                checkbox.disabled = false;
                console.error('AdminGrid: add column failed', e);
            }
        }

        /**
         * Remove a custom column from the DB and reload the grid.
         */
        async removeCustomColumn(code) {
            try {
                await this.postAction('removeColumn', {
                    grid_block_id: this.gridBlockId,
                    column_code: code,
                });
                // Remove from config
                this.config = this.config.filter(c => c.code !== code);
                this.buildConfigIndex();
                this.saveToCache();
                this.reloadGrid();
            } catch (e) {
                console.error('AdminGrid: remove column failed', e);
            }
        }

        /**
         * Reload the grid via AJAX (no full page reload).
         * Falls back to location.reload() if the varienGrid object isn't found.
         */
        reloadGrid() {
            // varienGrid JS objects are named: {gridBlockId}JsObject
            const jsObj = window[this.gridBlockId + 'JsObject'];
            if (jsObj && typeof jsObj.reload === 'function') {
                jsObj.reload();
            } else {
                location.reload();
            }
        }

        buildProfileSelector() {
            const wrap = document.createElement('div');
            wrap.className = 'admingrid-profile';

            const lbl = document.createElement('span');
            lbl.textContent = 'Profile:';
            wrap.appendChild(lbl);

            const sel = document.createElement('select');

            const none = document.createElement('option');
            none.value = '';
            none.textContent = '— Default —';
            sel.appendChild(none);

            this.profiles.forEach(p => {
                const opt = document.createElement('option');
                opt.value = p.id;
                opt.textContent = p.name;
                if (p.id === this.profileId) opt.selected = true;
                sel.appendChild(opt);
            });

            sel.addEventListener('change', () => this.switchProfile(sel.value));
            wrap.appendChild(sel);

            const saveAs = document.createElement('button');
            saveAs.type = 'button';
            saveAs.className = 'scalable';
            saveAs.innerHTML = '<span>Save As…</span>';
            saveAs.addEventListener('click', () => this.saveAsNewProfile());
            wrap.appendChild(saveAs);

            // Delete profile button (only shown when a saved profile is selected)
            const del = document.createElement('button');
            del.type = 'button';
            del.className = 'scalable';
            del.innerHTML = '<span>Delete</span>';
            del.style.cssText = this.profileId ? '' : 'display:none;';
            del.addEventListener('click', () => this.deleteCurrentProfile(sel, del));
            wrap.appendChild(del);

            return wrap;
        }

        buildSaveButton() {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'scalable task';
            btn.innerHTML = '<span>Save</span>';
            btn.addEventListener('click', () => this.saveCurrentProfile());
            return btn;
        }

        // ── Actions ─────────────────────────────────────────────────────

        setColumnWidth(code, width) {
            this.ensureFullConfig();
            this.buildConfigIndex();
            if (this.configByCode[code]) {
                this.configByCode[code].width = width;
            }
            this.config = Object.values(this.configByCode);
            this.saveToCache();
            this.isDirty = true;
        }

        toggleColumn(code, visible) {
            let found = false;
            this.config = this.config.map(c => {
                if (c.code === code) { found = true; return { ...c, visible }; }
                return c;
            });
            if (!found) {
                const col = this.columns.find(c => c.code === code);
                this.config.push({ code, visible, position: col ? col.index : this.config.length });
            }
            this.buildConfigIndex();
            this.applyConfig();
            this.saveToCache();
            this.isDirty = true;
        }

        async saveCurrentProfile() {
            if (!this.gridId) return;
            // Ensure all columns are in config
            this.ensureFullConfig();

            try {
                const data = await this.postAction('saveProfile', {
                    grid_id: this.gridId,
                    column_config: JSON.stringify(this.config),
                    profile_name: this.getActiveProfileName(),
                    is_default: '1',
                    ...(this.profileId ? { profile_id: this.profileId } : {}),
                });
                if (data.success) {
                    this.profileId = data.profileId;
                    this.isDirty = false;
                    this.saveToCache();
                    this.flash('Saved');
                }
            } catch (e) {
                console.error('AdminGrid: save failed', e);
            }
        }

        async saveAsNewProfile() {
            if (!this.gridId) return;
            const name = prompt('Profile name:', 'My Profile');
            if (!name) return;

            this.ensureFullConfig();

            try {
                const data = await this.postAction('saveProfile', {
                    grid_id: this.gridId,
                    column_config: JSON.stringify(this.config),
                    profile_name: name,
                    is_default: '1',
                });
                if (data.success) {
                    this.profileId = data.profileId;
                    this.isDirty = false;
                    this.saveToCache();
                    this.flash('Saved');
                    this.reloadGrid();
                }
            } catch (e) {
                console.error('AdminGrid: save-as failed', e);
            }
        }

        async deleteCurrentProfile(sel, delBtn) {
            if (!this.profileId) return;
            const name = this.getActiveProfileName();
            if (!confirm(`Delete profile "${name}"?`)) return;

            try {
                await this.postAction('deleteProfile', { profile_id: this.profileId });
                this.profileId = null;
                this.config = [];
                this.configByCode = {};
                localStorage.removeItem(STORAGE_PREFIX + this.gridBlockId);
                document.getElementById(`admingrid-style-${this.gridBlockId}`)?.remove();
                this.reloadGrid();
            } catch (e) {
                console.error('AdminGrid: delete profile failed', e);
            }
        }

        async switchProfile(profileId) {
            if (!profileId) {
                // Reset — remove config, clear styles
                this.config = [];
                this.configByCode = {};
                this.profileId = null;
                document.getElementById(`admingrid-style-${this.gridBlockId}`)?.remove();
                localStorage.removeItem(STORAGE_PREFIX + this.gridBlockId);
                this.applyConfig();
                return;
            }

            try {
                await this.postAction('setDefault', { profile_id: profileId });
            } catch { /* proceed anyway */ }

            location.reload();
        }

        // ── Helpers ─────────────────────────────────────────────────────

        ensureFullConfig() {
            // Make sure every column in the DOM is represented in config
            const existing = new Set(this.config.map(c => c.code));
            this.columns.forEach((col, idx) => {
                if (!existing.has(col.code)) {
                    this.config.push({ code: col.code, visible: true, position: idx });
                }
            });
        }

        getActiveProfileName() {
            const p = this.profiles.find(p => p.id === this.profileId);
            return p ? p.name : 'Default';
        }

        flash(msg) {
            const el = document.createElement('span');
            el.textContent = msg;
            el.className = 'admingrid-flash';
            this.toolbar?.appendChild(el);
            setTimeout(() => { el.style.opacity = '0'; }, 1500);
            setTimeout(() => el.remove(), 2000);
        }

        // ── AJAX reload observer ────────────────────────────────────────

        observeGridReloads() {
            // Watch the grid container itself — AJAX replaces its innerHTML
            let debounce = null;
            const observer = new MutationObserver(() => {
                // Debounce — AJAX replacement triggers multiple mutations
                clearTimeout(debounce);
                debounce = setTimeout(() => this.onGridReloaded(), 50);
            });

            observer.observe(this.gridEl, { childList: true, subtree: false });
        }

        /**
         * Called after varienGrid AJAX reload replaces the grid HTML.
         * Re-acquires DOM references, re-applies config, re-builds toolbar.
         */
        onGridReloaded() {
            // Re-acquire DOM references
            this.dataTable = this.gridEl.querySelector('table.data');
            if (!this.dataTable) return;

            this.columns = this.readColumnsFromDom();
            if (this.columns.length === 0) return;

            // Re-apply column visibility CSS
            if (this.config.length > 0) {
                this.applyConfig();
            }

            // Re-build toolbar if it was destroyed
            if (!this.gridEl.querySelector('.admingrid-toolbar')) {
                this.toolbar = null;
                this.buildToolbar();
            }
        }
    }

    // ── Auto-detection ──────────────────────────────────────────────────
    // Find grids by looking for: div > ... > table.data with thead tr.headings th[data-column-id]

    function discoverGrids() {
        document.querySelectorAll('table.data').forEach(table => {
            if (!table.querySelector('thead tr.headings th[data-column-id]')) return;

            // Walk up to find the grid container div (has an ID, contains this table)
            let container = table.closest('.grid')?.parentElement;
            if (!container?.id) return;
            if (INITIALIZED.has(container)) return;

            INITIALIZED.add(container);
            container._adminGrid = new AdminGrid(container);
        });
    }

    // Init
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', discoverGrids);
    } else {
        discoverGrids();
    }

    // Watch for dynamically added grids (tabs, AJAX)
    const watchBody = () => {
        new MutationObserver(() => discoverGrids())
            .observe(document.body, { childList: true, subtree: true });
    };

    if (document.body) watchBody();
    else document.addEventListener('DOMContentLoaded', watchBody);
})();

// ── Category Tree Filter Popup ──────────────────────────────────────
window.AdminGridCategoryFilter = (() => {
    'use strict';

    let _treeCache = null;
    let _overlay = null;
    let _targetId = null;

    async function open(hiddenInputId) {
        _targetId = hiddenInputId;

        if (!_treeCache) {
            const url = (window.ADMINGRID_CONFIG?.urls?.categoryTree || '') + '?isAjax=true';
            try {
                const resp = await fetch(url, { credentials: 'same-origin' });
                const data = await resp.json();
                _treeCache = data.tree || [];
            } catch (e) {
                console.error('AdminGrid: failed to load category tree', e);
                return;
            }
        }

        const hidden = document.getElementById(hiddenInputId);
        const selectedIds = hidden?.value
            ? hidden.value.split(',').map(s => parseInt(s.trim(), 10)).filter(Boolean)
            : [];

        _buildPopup(_treeCache, selectedIds);
    }

    function _buildPopup(tree, selectedIds) {
        close();

        const overlay = document.createElement('div');
        overlay.className = 'admingrid-cat-overlay';
        overlay.addEventListener('click', e => { if (e.target === overlay) close(); });

        const panel = document.createElement('div');
        panel.className = 'admingrid-cat-panel';

        // Header
        const header = document.createElement('div');
        header.className = 'admingrid-cat-header';
        header.innerHTML = '<span>Choose Categories To Filter</span>';
        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'admingrid-cat-close';
        closeBtn.textContent = '\u00D7';
        closeBtn.addEventListener('click', close);
        header.appendChild(closeBtn);

        // Body — scrollable tree
        const body = document.createElement('div');
        body.className = 'admingrid-cat-body';
        tree.forEach(node => body.appendChild(_buildNode(node, selectedIds)));

        // Footer
        const footer = document.createElement('div');
        footer.className = 'admingrid-cat-footer';

        const chooseBtn = document.createElement('button');
        chooseBtn.type = 'button';
        chooseBtn.className = 'scalable save';
        chooseBtn.innerHTML = '<span>Choose</span>';
        chooseBtn.addEventListener('click', _apply);

        const clearBtn = document.createElement('button');
        clearBtn.type = 'button';
        clearBtn.className = 'scalable';
        clearBtn.innerHTML = '<span>Clear</span>';
        clearBtn.addEventListener('click', () => {
            body.querySelectorAll('input[type=checkbox]').forEach(cb => cb.checked = false);
        });

        footer.appendChild(clearBtn);
        footer.appendChild(chooseBtn);

        panel.appendChild(header);
        panel.appendChild(body);
        panel.appendChild(footer);
        overlay.appendChild(panel);
        document.body.appendChild(overlay);
        _overlay = overlay;
    }

    function _buildNode(node, selectedIds, depth = 0) {
        const wrap = document.createElement('div');
        wrap.className = 'admingrid-tree-node';

        const row = document.createElement('div');
        row.className = 'admingrid-tree-row';

        const hasChildren = node.children && node.children.length > 0;

        // Toggle arrow
        const toggle = document.createElement('span');
        toggle.className = 'admingrid-tree-toggle';
        toggle.textContent = hasChildren ? '\u25B6' : '\u00A0\u00A0';
        if (hasChildren) {
            toggle.addEventListener('click', () => {
                const ch = wrap.querySelector('.admingrid-tree-children');
                if (ch) {
                    const expanded = ch.classList.toggle('expanded');
                    toggle.textContent = expanded ? '\u25BC' : '\u25B6';
                }
            });
        }

        // Checkbox + label
        const label = document.createElement('label');
        const cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.value = node.id;
        cb.checked = selectedIds.includes(node.id);
        cb.dataset.name = node.text;
        const span = document.createElement('span');
        span.textContent = node.text;
        if (node.active === false) span.style.opacity = '0.45';
        label.appendChild(cb);
        label.appendChild(span);

        row.appendChild(toggle);
        row.appendChild(label);
        wrap.appendChild(row);

        // Children
        if (hasChildren) {
            const childrenWrap = document.createElement('div');
            childrenWrap.className = 'admingrid-tree-children';

            // Auto-expand top level, or if any descendant is selected
            if (depth === 0 || _hasSelectedDescendant(node, selectedIds)) {
                childrenWrap.classList.add('expanded');
                toggle.textContent = '\u25BC';
            }

            node.children.forEach(child => childrenWrap.appendChild(_buildNode(child, selectedIds, depth + 1)));
            wrap.appendChild(childrenWrap);
        }

        return wrap;
    }

    function _hasSelectedDescendant(node, selectedIds) {
        if (!node.children) return false;
        for (const child of node.children) {
            if (selectedIds.includes(child.id)) return true;
            if (_hasSelectedDescendant(child, selectedIds)) return true;
        }
        return false;
    }

    function _apply() {
        if (!_overlay || !_targetId) return;

        const ids = [];
        const names = [];
        _overlay.querySelectorAll('input[type=checkbox]:checked').forEach(cb => {
            ids.push(cb.value);
            names.push(cb.dataset.name || cb.value);
        });

        const hidden = document.getElementById(_targetId);
        const display = document.getElementById(_targetId + '_display');
        if (hidden) hidden.value = ids.join(',');
        if (display) display.value = names.join(', ');

        close();

        // Trigger grid filter — find the filter row's search button
        if (hidden) {
            const thead = hidden.closest('thead');
            if (thead) {
                const searchBtn = thead.querySelector('td.filter-actions button');
                if (searchBtn) searchBtn.click();
            }
        }
    }

    function close() {
        if (_overlay) {
            _overlay.remove();
            _overlay = null;
        }
    }

    return { open, close };
})();
