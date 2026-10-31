const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../public/assets/js/ag-grid-tables.js'), 'utf8');
const css = fs.readFileSync(path.join(__dirname, '../public/assets/css/app.css'), 'utf8');
const sizing = source.match(/function fitContentHeight\(api\) \{[\s\S]*?\n        \}/)?.[0];
assert(sizing, 'Shared table sizing must remain testable.');
for (const [headerHeight, rowHeight, count, expectedHeight] of [
    [48, 40, 0, 270],
    [48, 70, 1, 270],
    [48, 70, 3, 270],
    [48, 70, 4, 330],
    [48, 70, 10, 750],
    [48, 70, 25, 750],
    [48, 40, 10, 450],
    [96, 70, 0, 318],
    [96, 70, 10, 798],
]) {
    const properties = {};
    const grid = {
        querySelector: () => ({ offsetHeight: headerHeight }),
        style: { setProperty(name, value) { properties[name] = value; } },
    };
    vm.runInNewContext(`${sizing}; fitContentHeight(api);`, {
        grid, contentHeight: true, pageSize: 10, rowHeight,
        api: { getDisplayedRowCount: () => count },
    });
    assert.equal(properties['--cliniq-grid-header-height'], `${headerHeight}px`);
    assert.equal(grid.style.height, `${expectedHeight}px`);
}
const fixedGrid = { querySelector: () => null, style: { setProperty() {} } };
vm.runInNewContext(`${sizing}; fitContentHeight(null);`, { grid: fixedGrid, contentHeight: false });
assert.equal(fixedGrid.style.height, undefined, 'Fixed-height tables must retain their existing height.');
assert(source.includes('onGridSizeChanged: (params) => {'));
assert(!source.includes('gridOptions.onGridSizeChanged ='), 'A column-fitting branch must not replace the shared resize handler.');
assert(css.includes('min-height: calc(var(--cliniq-grid-header-height, 48px) + 222px);'));
assert(css.includes('.cliniq-ag-grid .ag-body.ag-layout-auto-height .ag-center-cols-viewport,'), 'Auto-height body minimum must outrank the injected grid theme.');
const overlay = css.match(/\.cliniq-ag-grid \.ag-overlay-panel \.ag-overlay-wrapper\.ag-overlay-no-rows-wrapper\s*\{([^}]+)\}/)?.[1] || '';
assert(overlay.includes('padding-top: var(--cliniq-grid-header-height, 48px);'));
assert(overlay.includes('box-sizing: border-box;'));
const empty = css.match(/\.cliniq-grid-empty\s*\{([^}]+)\}/)?.[1] || '';
assert(empty.includes('box-sizing: border-box;'));
assert(empty.includes('margin-block: auto;'));
console.log('Shared table minimum-height checks passed (empty, populated, page limits, tall headers, fixed heights).');
