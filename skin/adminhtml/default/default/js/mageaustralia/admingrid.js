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
            const ths = headerRow.querySelectorAll('th');
            ths.forEach((th, idx) => {
                const code = th.getAttribute('data-column-id');
                if (code && !this.isColumnVisible(code)) {
                    hidden.push(idx + 1); // nth-child is 1-based
                }
            });

            if (hidden.length === 0) return;

            const esc = CSS.escape(tableId);
            const style = document.createElement('style');
            style.id = id;
            const cellRules = hidden.map(n =>
                `#${esc} tr > :nth-child(${n})`
            ).join(',\n');
            const colRules = hidden.map(n =>
                `#${esc} colgroup col:nth-child(${n})`
            ).join(',\n');
            style.textContent = `${cellRules} { display: none !important; }\n${colRules} { width: 0 !important; visibility: collapse !important; }`;
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
            wrap.style.cssText = 'position:relative;';

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
            input.style.cssText = 'font-size:12px;padding:1px 4px;border:1px solid #1979c3;border-radius:2px;width:100%;box-sizing:border-box;';

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
                const attributes = data.available.filter(a => a.group === 'attribute');
                const collection = data.available.filter(a => a.group === 'collection');

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
