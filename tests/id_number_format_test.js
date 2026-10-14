const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const script = fs.readFileSync(path.join(__dirname, '..', 'public', 'assets', 'js', 'id-number-format.js'), 'utf8');
const context = {
    document: { readyState: 'loading', addEventListener() {} },
    window: {},
    HTMLInputElement: class {}
};
vm.runInNewContext(script, context);

assert.equal(context.window.CliniqIdNumber.format('2300211', 'mixed', true), '23-00211');
assert.equal(context.window.CliniqIdNumber.format('0000002', 'mixed', true), '0000002');
assert.equal(context.window.CliniqIdNumber.format('2300211', 'mixed'), '2300211');
console.log('ID number formatting checks passed.');
