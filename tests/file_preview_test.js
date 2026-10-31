const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const handlers = {};
const nodes = new Map();
function element() {
    return {
        hidden: false, dataset: {}, attributes: {}, events: {}, children: [],
        classList: { add() {}, remove() {} },
        setAttribute(name, value) { this.attributes[name] = value; },
        addEventListener(name, callback) { this.events[name] = callback; },
        appendChild(child) { this.children.push(child); },
        replaceChildren() { this.children = []; },
        focus() { this.focused = true; },
        querySelector(selector) {
            if (!nodes.has(selector)) nodes.set(selector, element());
            return nodes.get(selector);
        },
        querySelectorAll() { return []; },
    };
}
const body = element();
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../public/assets/js/file-preview.js'), 'utf8'), {
    document: { body, createElement: element, addEventListener(name, callback) { handlers[name] = callback; } },
    window: { location: { href: 'https://clinic.example/ape-status' } },
    URL, setTimeout: (callback) => callback(),
});

let restoredFocus = false;
const trigger = {
    dataset: { previewTitle: 'Lab Request Form', previewType: 'pdf' },
    getAttribute(name) { return name === 'href' ? '/ape-document?id=7' : null; },
    focus() { restoredFocus = true; },
};
let prevented = false;
handlers.click({ target: { closest: () => trigger }, preventDefault() { prevented = true; } });
const modal = body.children[0];
assert(prevented);
assert.equal(modal.hidden, false);
assert.equal(modal.attributes.role, 'dialog');
assert.equal(modal.attributes['aria-modal'], 'true');
assert.equal(modal.attributes['aria-labelledby'], 'filePreviewTitle');
assert.equal((modal.innerHTML.match(/<svg\b/g) || []).length, 6);
assert(!modal.innerHTML.includes('material-symbols-outlined'));
assert(!modal.innerHTML.includes('undefined'));
for (const svg of modal.innerHTML.matchAll(/<svg\b([^>]+)><path d="([^"]+)"\/><\/svg>/g)) {
    assert(svg[1].includes('class="file-preview-icon"'));
    assert(svg[1].includes('aria-hidden="true"'));
    assert(svg[1].includes('focusable="false"'));
    assert(svg[1].includes('viewBox="0 -960 960 960"'));
    assert(svg[2].startsWith('M'));
}
for (const label of ['Zoom out', 'Reset zoom', 'Zoom in', 'Close file preview']) {
    assert(modal.innerHTML.includes(`aria-label="${label}"`));
}
assert.equal(nodes.get('#filePreviewTitle').textContent, 'Lab Request Form');
assert.equal(nodes.get('.file-preview-download').href, '/ape-document?id=7');
assert.equal(nodes.get('.file-preview-download').attributes.download, 'Lab Request Form');
assert.equal(nodes.get('.file-preview-body').children[0].src, '/ape-document?id=7');
assert(nodes.get('.file-preview-close').focused);
nodes.get('.file-preview-close').events.click();
assert(modal.hidden);
assert(restoredFocus);

console.log('File preview SVG toolbar and open/close checks passed.');
