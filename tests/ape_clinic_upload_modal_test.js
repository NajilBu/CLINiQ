// Run: node tests/ape_clinic_upload_modal_test.js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '..', 'public', 'ape', 'view.php'), 'utf8');
const anchor = source.indexOf("const modal = document.querySelector('[data-clinic-upload-modal]');");
assert.ok(anchor !== -1, 'Clinic upload handler is present');
const start = source.lastIndexOf('(() => {', anchor);
const end = source.indexOf('})();', anchor) + '})();'.length;
assert.ok(start !== -1 && end > anchor, 'Clinic upload handler can be isolated');

const listeners = new Map();
const element = () => ({
    value: '',
    textContent: '',
    innerHTML: '',
    classList: { add() {} },
    addEventListener(name, callback) { listeners.set(this, { ...(listeners.get(this) || {}), [name]: callback }); },
    focus() { this.focused = true; },
});
const typeInput = element();
const requirementLabel = element();
const fileInput = element();
fileInput.files = [{ name: 'lab.pdf', size: 1024, type: 'application/pdf' }];
const previewWrap = element();
const preview = element();
const filename = element();
const submitButton = element();
const formParts = {
    '[data-clinic-upload-type]': typeInput,
    '[data-clinic-upload-file]': fileInput,
    '[data-clinic-upload-preview-wrap]': previewWrap,
    '[data-clinic-upload-preview]': preview,
    '[data-clinic-upload-filename]': filename,
    'button[type="submit"]': submitButton,
};
const form = {
    querySelector(selector) { return formParts[selector] || null; },
    addEventListener: element().addEventListener,
    reset() {},
};
const modal = {
    id: 'apeClinicUploadModal',
    querySelector(selector) { return selector === '[data-clinic-upload-requirement]' ? requirementLabel : null; },
};
const trigger = element();
trigger.dataset = { requirementName: 'Lab Request Form' };
const currentRow = {
    innerHTML: 'Missing',
    dataset: { apeRequirementName: 'Lab Request Form' },
    querySelector(selector) {
        return selector === '[data-clinic-upload-trigger]' && this.innerHTML === 'Missing' ? trigger : null;
    },
};
trigger.closest = () => currentRow;
const updatedRow = {
    innerHTML: 'Submitted with Preview',
    dataset: { apeRequirementName: 'Lab Request Form' },
};
const updatedTrigger = element();
updatedTrigger.dataset = { requirementName: 'Lab Request Form' };
updatedTrigger.closest = () => updatedRow;
const updatedPage = {
    querySelector(selector) {
        return selector === '#flash-toasts [data-flash]'
            ? { dataset: { flash: 'success', message: 'Submitted APE document.' } } : null;
    },
    querySelectorAll(selector) {
        return selector === '[data-clinic-upload-trigger]' ? [updatedTrigger]
            : selector === '[data-ape-requirement-name]' ? [updatedRow] : [];
    },
};
let movedToBody = false;
let openedId = '';
let navigated = false;
let uploadRequested = false;
const toasts = [];

vm.runInNewContext(source.slice(start, end), {
    document: {
        body: { appendChild(node) { movedToBody = node === modal; } },
        querySelector(selector) {
            return selector === '[data-clinic-upload-modal]' ? modal
                : selector === '[data-clinic-upload-form]' ? form : null;
        },
        querySelectorAll(selector) {
            return selector === '[data-clinic-upload-trigger]' ? [trigger]
                : selector === '[data-ape-requirement-name]' ? [currentRow] : [];
        },
    },
    showModal(id) { openedId = id; },
    closeModal() {},
    showToast(message, type) { toasts.push({ message, type }); },
    window: {
        location: {
            href: 'http://localhost/public/ape/view.php?id=42',
            pathname: '/public/ape/view.php',
            assign() { navigated = true; },
        },
    },
    FormData: class { constructor() { uploadRequested = true; } },
    DOMParser: class { parseFromString() { return updatedPage; } },
    async fetch() {
        return {
            ok: true,
            url: 'http://localhost/public/ape/view.php?id=42',
            async text() { return '<html></html>'; },
        };
    },
    URL,
});

assert.equal(movedToBody, true, 'Modal is moved outside the clipped document panel');
listeners.get(trigger).click({ stopPropagation() {} });
assert.equal(openedId, 'apeClinicUploadModal', 'Shared visible modal opens');
assert.equal(typeInput.value, 'Lab Request Form', 'Upload form receives the selected requirement');
assert.equal(requirementLabel.textContent, 'Lab Request Form', 'Modal displays the selected requirement');
assert.equal(fileInput.focused, true, 'File input is ready for selection');
assert.match(source, /\$isClinicManagedApe \? 'faculty or NTP' : 'student'/,
    'The shared upload form covers Faculty/NTP and student records');
assert.match(source, /\['Pending', 'Verified'\]/,
    'Rows under clinic review do not offer an upload that the server would reject');

(async () => {
    let prevented = false;
    await listeners.get(form).submit({ preventDefault() { prevented = true; } });
    assert.equal(prevented, true, 'Native page-refreshing submission is prevented');
    assert.equal(uploadRequested, true, 'File is sent in a background request');
    assert.equal(navigated, false, 'Successful upload does not navigate or reload');
    assert.equal(currentRow.innerHTML, updatedRow.innerHTML, 'Only the affected document row updates');
    assert.equal(toasts.at(-1).type, 'success', 'Successful upload is acknowledged');
    console.log('Faculty/NTP and student APE upload modal tests passed.');
})().catch((error) => { console.error(error); process.exitCode = 1; });
