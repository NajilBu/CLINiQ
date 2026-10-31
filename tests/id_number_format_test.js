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

class FakeInput {
    constructor(value = '', dataset = {}) {
        this.value = value;
        this.dataset = dataset;
        this.listeners = {};
    }
    addEventListener(type, listener) {
        this.listeners[type] = listener;
    }
    setCustomValidity() {}
    emit(type, event = {}) {
        this.listeners[type]?.(event);
    }
}

const explicitStudentId = new FakeInput('23-00211', {
    idNumberAutoStudent: '1',
    idNumberCompactTarget: 'explicit-compact'
});
const explicitCompact = new FakeInput();
const compactStudentId = new FakeInput('2300211', {
    idNumberAutoStudent: '1',
    idNumberCompactTarget: 'compact-student-compact'
});
const compactStudentValue = new FakeInput();
const inputs = [explicitStudentId, compactStudentId];
const formatterContext = {
    document: {
        readyState: 'complete',
        addEventListener() {},
        querySelectorAll() { return inputs; },
        getElementById(id) {
            return {
                'explicit-compact': explicitCompact,
                'compact-student-compact': compactStudentValue
            }[id] || null;
        }
    },
    window: {},
    HTMLInputElement: FakeInput
};
vm.runInNewContext(script, formatterContext);

assert.equal(explicitStudentId.value, '23-00211');
assert.equal(explicitCompact.value, '');
assert.equal(compactStudentId.value, '23-00211');
assert.equal(compactStudentValue.value, '2300211');

compactStudentId.value = '23-00211';
compactStudentId.emit('input', { inputType: 'insertFromPaste' });
assert.equal(compactStudentValue.value, '');
function entryField() {
    const input = new FakeInput('', { idNumberAutoStudent: '1', idNumberCompactTarget: 'compact' });
    const compact = new FakeInput();
    vm.runInNewContext(script, {
        document: {
            readyState: 'complete',
            querySelectorAll: () => [input],
            getElementById: () => compact
        },
        window: {},
        HTMLInputElement: FakeInput
    });
    return { input, compact };
}

function typeId(input, value) {
    for (const character of value) {
        input.value += character;
        input.emit('input', { inputType: 'insertText', data: character });
    }
}

for (const [entered, displayed, compactValue] of [
    ['23-00211', '23-00211', ''],
    ['2300211', '23-00211', '2300211'],
    ['19-12345', '19-12345', ''],
    ['0000002', '0000002', '']
]) {
    const { input, compact } = entryField();
    typeId(input, entered);
    assert.equal(input.value, displayed, `Typing ${entered} must retain the intended ID.`);
    assert.equal(compact.value, compactValue, `Typing ${entered} must retain its lookup priority.`);
    input.emit('change');
    assert.equal(compact.value, compactValue, 'Leaving the field must retain its lookup priority.');
}

for (const inputType of ['insertFromPaste', 'insertReplacementText']) {
    const { input, compact } = entryField();
    typeId(input, '2300211');
    input.value = '23-00211';
    input.emit('input', { inputType, data: null });
    assert.equal(input.value, '23-00211');
    assert.equal(compact.value, '', `${inputType} must honor an explicit hyphenated ID.`);
}

const retyped = entryField();
typeId(retyped.input, '2300211');
retyped.input.value = '';
retyped.input.emit('input', { inputType: 'deleteContentBackward' });
assert.equal(retyped.compact.value, '');
typeId(retyped.input, '23-00211');
assert.equal(retyped.compact.value, '', 'A cleared field must not retain a previous compact lookup.');

console.log('ID number formatting checks passed (typed, pasted, replaced, cleared, and exact-ID priority).');
