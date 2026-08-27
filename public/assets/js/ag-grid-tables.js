(function () {
    function readGridJson(grid, selector, fallback) {
        const node = grid.querySelector(selector);
        if (!node) return fallback;

        try {
            return JSON.parse(node.textContent || '');
        } catch (error) {
            console.warn('Unable to parse AG Grid data for', grid.id, error);
            return fallback;
        }
    }

    function htmlRenderer(params) {
        return params.value || '';
    }

    function tableCellTooltipText(cell) {
        const columnId = String(cell.getAttribute('col-id') || '').toLowerCase();
        if (/action|profile|control/.test(columnId) || cell.querySelector('button, input, textarea, select, form, .btn')) {
            return '';
        }

        const explicitTooltip = cell.querySelector('[data-tooltip-text]')?.dataset.tooltipText || cell.dataset.tooltipText || '';
        if (explicitTooltip.trim() !== '') {
            return explicitTooltip.replace(/\s+/g, ' ').trim();
        }

        if (cell.querySelector('.avatar')) {
            const primaryName = cell.querySelector('strong');
            if (primaryName) {
                return String(primaryName.innerText || primaryName.textContent || '').replace(/\s+/g, ' ').trim();
            }
        }

        const copy = cell.cloneNode(true);
        copy.querySelectorAll('.material-symbols-outlined, .avatar, button, input, textarea, select, form, .btn').forEach((node) => node.remove());
        return String(copy.innerText || copy.textContent || '').replace(/\s+/g, ' ').trim();
    }

    function tableCellIsOverflowing(cell) {
        const candidates = [
            cell,
            ...cell.querySelectorAll('.ag-cell-wrapper, .ag-cell-value, .ag-cell-value > *, .ag-cell-wrapper > *')
        ];
        return candidates.some((node) => (
            node.clientWidth > 0
            && node.clientHeight > 0
            && (node.scrollWidth > node.clientWidth + 1 || node.scrollHeight > node.clientHeight + 1)
        ));
    }

    function initOverflowCellTooltips() {
        if (document.body.dataset.gridOverflowTooltips === 'true') return;
        document.body.dataset.gridOverflowTooltips = 'true';

        const tooltip = document.createElement('div');
        tooltip.className = 'cliniq-cell-overflow-tooltip';
        tooltip.setAttribute('role', 'tooltip');
        tooltip.hidden = true;
        document.body.appendChild(tooltip);
        let activeCell = null;

        function hideTooltip() {
            tooltip.hidden = true;
            activeCell = null;
        }

        function showTooltip(cell) {
            const text = tableCellTooltipText(cell);
            if (!text || !tableCellIsOverflowing(cell)) {
                hideTooltip();
                return;
            }

            activeCell = cell;
            tooltip.textContent = text;
            tooltip.hidden = false;
            tooltip.style.left = '0px';
            tooltip.style.top = '0px';

            const cellRect = cell.getBoundingClientRect();
            const tooltipRect = tooltip.getBoundingClientRect();
            const viewportPadding = 8;
            const left = Math.min(
                Math.max(viewportPadding, cellRect.left),
                Math.max(viewportPadding, window.innerWidth - tooltipRect.width - viewportPadding)
            );
            let top = cellRect.top - tooltipRect.height - 8;
            if (top < viewportPadding) {
                top = Math.min(window.innerHeight - tooltipRect.height - viewportPadding, cellRect.bottom + 8);
            }

            tooltip.style.left = `${left}px`;
            tooltip.style.top = `${Math.max(viewportPadding, top)}px`;
        }

        document.addEventListener('mouseover', (event) => {
            if (!(event.target instanceof Element)) return;
            const cell = event.target.closest('.cliniq-ag-grid .ag-cell, table td, table th');
            if (!cell || cell === activeCell) return;
            showTooltip(cell);
        });

        document.addEventListener('mouseout', (event) => {
            if (!activeCell) return;
            const nextTarget = event.relatedTarget;
            if (nextTarget && activeCell.contains(nextTarget)) return;
            if (!nextTarget || !activeCell.contains(nextTarget)) hideTooltip();
        });

        document.addEventListener('scroll', hideTooltip, true);
        window.addEventListener('resize', hideTooltip);
    }

    function compareSortValues(left, right, type) {
        const leftMissing = left === null || left === undefined || left === '';
        const rightMissing = right === null || right === undefined || right === '';
        if (leftMissing || rightMissing) {
            if (leftMissing && rightMissing) return 0;
            return leftMissing ? 1 : -1;
        }

        if (type === 'number') {
            return Number(left) - Number(right);
        }

        if (type === 'date') {
            const leftTime = Date.parse(String(left));
            const rightTime = Date.parse(String(right));
            if (Number.isFinite(leftTime) && Number.isFinite(rightTime)) {
                return leftTime - rightTime;
            }
        }

        return String(left).localeCompare(String(right), undefined, {
            numeric: true,
            sensitivity: 'base'
        });
    }

    function normalizeColumns(columns, shouldFitColumns) {
        return columns.map((column) => {
            const nextColumn = { ...column };
            if (nextColumn.cellRenderer === 'html') {
                nextColumn.cellRenderer = htmlRenderer;
            }

            if (nextColumn.sortField) {
                const sortField = nextColumn.sortField;
                const sortType = nextColumn.sortType || 'text';
                nextColumn.comparator = (left, right, leftNode, rightNode) => compareSortValues(
                    leftNode && leftNode.data ? leftNode.data[sortField] : left,
                    rightNode && rightNode.data ? rightNode.data[sortField] : right,
                    sortType
                );
                delete nextColumn.sortField;
                delete nextColumn.sortType;
            }

            if (nextColumn.field === 'rowNumber') {
                nextColumn.valueGetter = (params) => params.node && params.node.rowIndex !== null
                    ? params.node.rowIndex + 1
                    : '';
            }

            if (shouldFitColumns && nextColumn.suppressSizeToFit !== true) {
                const basis = Number(nextColumn.flex || nextColumn.width || nextColumn.minWidth || 140);
                nextColumn.flex = Math.max(0.7, Math.min(2.25, basis / 140));
                nextColumn.minWidth = Math.min(Number(nextColumn.minWidth || 92), 140);
                delete nextColumn.width;
            }

            return nextColumn;
        });
    }

    function makeEmptyOverlay(title, text) {
        return `
            <div class="cliniq-grid-empty">
                <span class="material-symbols-outlined">table_view</span>
                <strong>${escapeHtml(title || 'No records found')}</strong>
                <p>${escapeHtml(text || 'There is nothing to show here yet.')}</p>
            </div>
        `;
    }

    function initGrid(grid) {
        if (grid.dataset.agGridInitialized === 'true') return;
        grid.dataset.agGridInitialized = 'true';

        if (!window.agGrid || !window.agGrid.createGrid) {
            grid.innerHTML = '<div class="cliniq-grid-empty"><strong>Table failed to load</strong><p>Please check your connection and refresh the page.</p></div>';
            return;
        }

        const rowData = readGridJson(grid, '[data-grid-rows]', []);
        const pageSize = Number(grid.dataset.pageSize || 25);
        const paginationEnabled = grid.dataset.pagination === 'true';
        const paginationControlsId = grid.dataset.paginationControls || '';
        const rowHeight = Number(grid.dataset.rowHeight || 70);
        const shouldFitColumns = grid.dataset.fitColumns !== 'false';
        const columnDefs = normalizeColumns(readGridJson(grid, '[data-grid-columns]', []), shouldFitColumns);

        function eventTarget(gridEvent) {
            const nativeEvent = gridEvent && gridEvent.event;
            const target = nativeEvent && (nativeEvent.target || nativeEvent.srcElement);
            if (!target) return null;
            return target.nodeType === Node.TEXT_NODE ? target.parentElement : target;
        }

        function isInteractiveClick(gridEvent) {
            const target = eventTarget(gridEvent);
            return Boolean(target && target.closest('a, button, input, textarea, select, label, form, [data-no-row-click]'));
        }

        function navigateRow(gridEvent) {
            const rowUrl = gridEvent && gridEvent.data && gridEvent.data.rowUrl;
            if (!rowUrl || isInteractiveClick(gridEvent)) return;
            window.location.assign(rowUrl);
        }

        function renderPaginationControls(api) {
            if (!paginationEnabled || !paginationControlsId || !api) return;
            const controls = document.getElementById(paginationControlsId);
            if (!controls) return;

            const totalPages = Math.max(1, Number(api.paginationGetTotalPages ? api.paginationGetTotalPages() : 1));
            const currentPage = Math.min(totalPages, Number(api.paginationGetCurrentPage ? api.paginationGetCurrentPage() : 0) + 1);
            let html = `<button type="button" data-page-action="previous" aria-label="Previous page" ${currentPage === 1 ? 'class="page-disabled" disabled' : ''}>‹</button>`;
            for (let page = 1; page <= totalPages; page += 1) {
                const active = page === currentPage;
                html += `<button type="button" data-page-number="${page}"${active ? ' class="page-active" aria-current="page"' : ''}>${page}</button>`;
            }
            html += `<button type="button" data-page-action="next" aria-label="Next page" ${currentPage === totalPages ? 'class="page-disabled" disabled' : ''}>›</button>`;
            controls.innerHTML = html;

            if (controls.dataset.paginationReady !== 'true') {
                controls.dataset.paginationReady = 'true';
                controls.addEventListener('click', (event) => {
                    const button = event.target.closest('button');
                    if (!button || button.disabled) return;

                    if (button.dataset.pageNumber) {
                        api.paginationGoToPage(Number(button.dataset.pageNumber) - 1);
                    } else if (button.dataset.pageAction === 'previous') {
                        api.paginationGoToPage(Math.max(0, api.paginationGetCurrentPage() - 1));
                    } else if (button.dataset.pageAction === 'next') {
                        api.paginationGoToPage(Math.min(api.paginationGetTotalPages() - 1, api.paginationGetCurrentPage() + 1));
                    }
                });
            }
        }

        function fitColumns(api) {
            if (shouldFitColumns && api && api.sizeColumnsToFit) {
                api.sizeColumnsToFit();
            }
        }

        function refreshRowNumbers(api) {
            if (api && api.refreshCells) {
                api.refreshCells({ columns: ['rowNumber'], force: true });
            }
        }

        const gridOptions = {
            rowData,
            columnDefs,
            defaultColDef: {
                sortable: true,
                filter: true,
                resizable: true,
                minWidth: shouldFitColumns ? 76 : 130,
                flex: 1,
                wrapHeaderText: true,
                autoHeaderHeight: true
            },
            pagination: paginationEnabled,
            paginationPageSize: pageSize,
            paginationPageSizeSelector: false,
            suppressPaginationPanel: paginationEnabled && Boolean(paginationControlsId),
            animateRows: true,
            suppressCellFocus: true,
            rowHeight,
            headerHeight: 48,
            overlayNoRowsTemplate: makeEmptyOverlay(grid.dataset.emptyTitle, grid.dataset.emptyText),
            getRowClass: (params) => {
                const classes = [];
                if (params.data && params.data.rowUrl) classes.push('ag-row-clickable');
                if (params.data && params.data.rowClass) classes.push(params.data.rowClass);
                return classes.join(' ');
            },
            onCellClicked: navigateRow,
            onRowClicked: navigateRow,
            onPaginationChanged: (params) => {
                renderPaginationControls(params.api);
                refreshRowNumbers(params.api);
            },
            onSortChanged: (params) => refreshRowNumbers(params.api),
            onFilterChanged: (params) => refreshRowNumbers(params.api)
        };

        function announceGridReady(params) {
            window.cliniqAgGrids = window.cliniqAgGrids || {};
            window.cliniqAgGrids[grid.id] = params.api;
            window.dispatchEvent(new CustomEvent('cliniq:ag-grid-ready', {
                detail: {
                    id: grid.id,
                    api: params.api,
                    grid,
                    rowData
                }
            }));
        }

        if (shouldFitColumns) {
            gridOptions.suppressHorizontalScroll = true;
            gridOptions.onGridReady = (params) => window.requestAnimationFrame(() => fitColumns(params.api));
            gridOptions.onGridSizeChanged = (params) => fitColumns(params.api);
            gridOptions.onFirstDataRendered = (params) => {
                fitColumns(params.api);
                announceGridReady(params);
            };
        } else {
            gridOptions.onFirstDataRendered = announceGridReady;
        }

        const api = window.agGrid.createGrid(grid, gridOptions);
        fitColumns(api);
        renderPaginationControls(api);

        if (shouldFitColumns && window.ResizeObserver) {
            const observer = new ResizeObserver(() => fitColumns(api));
            observer.observe(grid);
            if (grid.parentElement) {
                observer.observe(grid.parentElement);
            }
        }

        const searchInput = grid.dataset.searchInput ? document.getElementById(grid.dataset.searchInput) : null;

        if (searchInput) {
            searchInput.addEventListener('input', () => {
                if (api.setGridOption) {
                    api.setGridOption('quickFilterText', searchInput.value);
                } else if (api.setQuickFilter) {
                    api.setQuickFilter(searchInput.value);
                }
                if (paginationEnabled && api.paginationGoToFirstPage) {
                    api.paginationGoToFirstPage();
                    window.requestAnimationFrame(() => renderPaginationControls(api));
                }
            });

            if (searchInput.value) {
                if (api.setGridOption) {
                    api.setGridOption('quickFilterText', searchInput.value);
                } else if (api.setQuickFilter) {
                    api.setQuickFilter(searchInput.value);
                }
            }
        }
    }

    window.cliniqInitAgGrids = function (root) {
        (root || document).querySelectorAll('[data-ag-grid]').forEach(initGrid);
    };

    document.addEventListener('DOMContentLoaded', () => {
        initOverflowCellTooltips();
        window.cliniqInitAgGrids(document);
    });
})();
