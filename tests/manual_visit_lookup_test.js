const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../public/visits/create.php'), 'utf8');
const start = source.indexOf('const studentIdLookup =');
const end = source.indexOf('function syncDispensingRow(');
assert(start >= 0 && end > start, 'The manual visit lookup script must be present.');
const formatter = fs.readFileSync(path.join(__dirname, '../public/assets/js/id-number-format.js'), 'utf8');

function setup() {
    const elements = {};
    for (const id of ['studentIdLookup', 'patientIdInput', 'patientNameDisplay', 'patientCourseDisplay',
        'patientSexDisplay', 'patientTypeDisplay', 'patientLookupStatus', 'visitSubjectType', 'guestNameInput', 'visitForm']) {
        elements[id] = {
            value: '', listeners: {}, classList: { toggle() {} },
            addEventListener(type, callback) { this.listeners[type] = callback; },
            closest() { return this; }, focus() {}
        };
    }
    elements.visitSubjectType.value = 'registered';
    const timers = new Map();
    const requests = [];
    let timerId = 0;
    const context = vm.createContext({
        document: { readyState: 'loading', addEventListener() {}, getElementById: id => elements[id] },
        window: {
            clearTimeout: id => timers.delete(id),
            setTimeout(callback) { timers.set(++timerId, callback); return timerId; }
        },
        fetch: url => new Promise((resolve, reject) => requests.push({ url, resolve, reject })),
        encodeURIComponent
    });
    vm.runInContext(formatter, context);
    vm.runInContext(source.slice(start, end), context);
    return {
        elements, timers,
        enter(value) {
            elements.studentIdLookup.value = value;
            elements.studentIdLookup.listeners.input();
        },
        lookup() {
            const [id, callback] = [...timers.entries()][0];
            timers.delete(id);
            const finished = callback();
            const request = requests[requests.length - 1];
            return {
                async respond(patient) { request.resolve({ json: async () => patient }); await finished; },
                async fail() { request.reject(new Error('Synthetic network failure')); await finished; }
            };
        },
        submitBlocked() {
            let blocked = false;
            elements.visitForm.listeners.submit({ preventDefault() { blocked = true; } });
            return blocked;
        }
    };
}

const patient = (personId, idNumber) => ({
    found: true, person_id: personId, id_number: idNumber,
    name: `Synthetic patient ${personId}`, course: 'Test', sex: 'Test', type: 'Test'
});

(async () => {
    for (const replacement of ['', '23-1234']) {
        const queued = setup();
        queued.enter('23-12345');
        queued.enter(replacement);
        assert.equal(queued.timers.size, 0, 'Clearing or shortening an ID must cancel its queued lookup.');
        assert(queued.submitBlocked(), 'An unresolved patient must not be submitted.');

        const pending = setup();
        pending.enter('23-12345');
        const request = pending.lookup();
        pending.enter(replacement);
        await request.respond(patient(42, '23-12345'));
        assert.equal(pending.elements.studentIdLookup.value, replacement);
        assert.equal(pending.elements.patientIdInput.value, '', 'A stale response must not restore the old patient.');
        assert(pending.submitBlocked());
    }

    for (const failOldRequest of [false, true]) {
        const latest = setup();
        latest.enter('23-12345');
        const oldRequest = latest.lookup();
        latest.enter('24-54321');
        const newRequest = latest.lookup();
        await newRequest.respond(patient(84, '24-54321'));
        if (failOldRequest) await oldRequest.fail();
        else await oldRequest.respond(patient(42, '23-12345'));
        assert.equal(latest.elements.studentIdLookup.value, '24-54321');
        assert.equal(latest.elements.patientIdInput.value, 84);
        assert.equal(latest.elements.patientNameDisplay.value, 'Synthetic patient 84');
        assert(!latest.submitBlocked(), 'The latest resolved patient can be submitted.');
    }

    const guest = setup();
    guest.enter('23-12345');
    const request = guest.lookup();
    guest.elements.visitSubjectType.value = 'guest';
    guest.elements.visitSubjectType.listeners.change();
    await request.respond(patient(42, '23-12345'));
    assert.equal(guest.elements.patientIdInput.value, '');
    assert.equal(guest.elements.patientTypeDisplay.value, 'Guest');
    assert(guest.submitBlocked(), 'Guest name remains required.');

    console.log('Manual visit lookup behavior passed (debounce cancellation, stale success/failure, newest patient, guest, and submit guards).');
})().catch(error => { console.error(error); process.exitCode = 1; });
