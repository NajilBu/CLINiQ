const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const script = fs.readFileSync(path.join(__dirname, '..', 'public', 'assets', 'js', 'patient-passport-qr.js'), 'utf8');

function element() {
    const handlers = {};
    return {
        hidden: false, disabled: false, open: false, value: '', textContent: '', dataset: {},
        addEventListener(name, callback) { handlers[name] = callback; },
        async emit(name, event = {}) { return handlers[name]?.(event); },
        focus() {},
        showModal() { this.open = true; },
        close() { this.open = false; handlers.close?.(); },
        replaceChildren() {}, appendChild() {}, remove() {},
        click() { return handlers.click?.(); }
    };
}

function setup() {
    const ids = [
        'qr-container', 'download-passport-qr', 'write-passport-nfc', 'passport-nfc-status',
        'passport-nfc-overwrite-dialog', 'passport-nfc-password', 'passport-nfc-password-label',
        'passport-nfc-overwrite-error', 'passport-nfc-overwrite-ready',
        'passport-nfc-overwrite-confirm', 'passport-nfc-overwrite-cancel'
    ];
    const nodes = Object.fromEntries(ids.map(id => [id, element()]));
    nodes['qr-container'].dataset.passportUrl = '../public/emergency.php?token=test';
    const readers = [];
    const writes = [];
    class NDEFReader {
        constructor() { readers.push(this); }
        scan() { return Promise.resolve(); }
        write(message, options) { writes.push({ message, options }); return Promise.resolve(); }
        async read(records, serialNumber = 'tag-1') {
            const data = new TextEncoder().encode('previous content');
            return this.onreading({
                serialNumber,
                message: { records: records ? [{ recordType: 'url', data: new DataView(data.buffer) }] : [] }
            });
        }
    }
    let passwordAccepted = false;
    const context = {
        document: {
            getElementById: id => nodes[id],
            querySelector: () => ({ content: 'csrf-token' }),
            createElement: () => element(),
            body: { appendChild() {} }
        },
        window: {
            isSecureContext: true,
            location: { href: 'https://clinic.example/patient-portal/patient-passport.php' },
            NDEFReader,
            QRCode: Object.assign(function () {}, { CorrectLevel: { H: 1 } }),
            setTimeout: () => 1, clearTimeout() {}
        },
        fetch: async () => passwordAccepted
            ? { status: 204 }
            : { status: 401, json: async () => ({ error: 'Incorrect password.' }) },
        AbortController, URL, URLSearchParams, Uint8Array, DataView, TextEncoder,
        Promise, Array, Error
    };
    vm.runInNewContext(script, context);
    return { nodes, readers, writes, acceptPassword: () => { passwordAccepted = true; } };
}

async function flush() { await new Promise(resolve => setImmediate(resolve)); }

(async () => {
    const empty = setup();
    const emptyClick = empty.nodes['write-passport-nfc'].emit('click');
    await flush();
    await empty.readers[0].read(false);
    await emptyClick;
    assert.equal(empty.writes.length, 1);
    assert.equal(empty.writes[0].options.overwrite, false);

    const existing = setup();
    const existingClick = existing.nodes['write-passport-nfc'].emit('click');
    await flush();
    await existing.readers[0].read(true);
    await existingClick;
    assert.equal(existing.writes.length, 0);
    assert.equal(existing.nodes['passport-nfc-overwrite-dialog'].open, true);

    existing.nodes['passport-nfc-password'].value = 'wrong';
    await existing.nodes['passport-nfc-overwrite-confirm'].click();
    assert.equal(existing.writes.length, 0);
    assert.equal(existing.nodes['passport-nfc-overwrite-error'].hidden, false);

    existing.acceptPassword();
    existing.nodes['passport-nfc-password'].value = 'correct';
    await existing.nodes['passport-nfc-overwrite-confirm'].click();
    assert.equal(existing.writes.length, 0);
    assert.equal(existing.nodes['passport-nfc-overwrite-confirm'].textContent, 'Tap same tag to overwrite');

    const overwriteClick = existing.nodes['passport-nfc-overwrite-confirm'].click();
    await flush();
    await existing.readers[1].read(true, 'different-tag');
    await overwriteClick;
    assert.equal(existing.writes.length, 0);

    const confirmed = setup();
    const confirmedClick = confirmed.nodes['write-passport-nfc'].emit('click');
    await flush();
    await confirmed.readers[0].read(true);
    await confirmedClick;
    confirmed.acceptPassword();
    confirmed.nodes['passport-nfc-password'].value = 'correct';
    await confirmed.nodes['passport-nfc-overwrite-confirm'].click();
    const confirmedWrite = confirmed.nodes['passport-nfc-overwrite-confirm'].click();
    await flush();
    await confirmed.readers[1].read(true);
    await confirmedWrite;
    assert.equal(confirmed.writes.length, 1);
    assert.equal(confirmed.writes[0].options.overwrite, true);
    console.log('NFC flow checks passed: empty tag, rejected password, different tag, confirmed overwrite.');
})().catch(error => { console.error(error); process.exitCode = 1; });
