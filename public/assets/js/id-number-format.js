/* Shared patient ID formatting and validation. */
(function () {
    'use strict';

    const patterns = {
        student: /^(?:0[1-9]|[1-9]\d)-\d{5}$/,
        employee: /^\d{7}$/,
        mixed: /^(?:\d{7}|\d{2}-\d{5})$/
    };

    function kindFor(input) {
        return input?.dataset.idNumberKind === 'student' || input?.dataset.idNumberKind === 'employee'
            ? input.dataset.idNumberKind
            : 'mixed';
    }

    function format(value, kind = 'mixed', autoStudentFormat = false) {
        const raw = String(value || '').toUpperCase();
        const digits = raw.replace(/\D/g, '').slice(0, 7);
        const hasStudentHyphen = /^\d{0,2}-\d*$/.test(raw);
        if (raw.includes('-') && !hasStudentHyphen) return raw;
        if (kind === 'student') return digits.length > 2 && !digits.startsWith('00') ? `${digits.slice(0, 2)}-${digits.slice(2)}` : digits;
        if (kind === 'mixed' && autoStudentFormat && /^2\d{0,6}$/.test(digits)) return digits.length > 2 ? `${digits.slice(0, 2)}-${digits.slice(2)}` : digits;
        if (kind === 'mixed' && hasStudentHyphen) return digits.length > 2 ? `${digits.slice(0, 2)}-${digits.slice(2)}` : digits;
        return digits;
    }

    function prepare(input) {
        if (!(input instanceof HTMLInputElement) || input.dataset.idNumberFormatterReady === '1') return;
        input.dataset.idNumberFormatterReady = '1';
        input.autocapitalize = 'characters';

        const sync = () => {
            applyConstraints(input);
            const compactValue = input.value.replace(/\D/g, '').slice(0, 7);
            const autoStudentFormat = input.dataset.idNumberAutoStudent === '1';
            input.value = format(input.value, kindFor(input), autoStudentFormat);
            const compactTarget = input.dataset.idNumberCompactTarget;
            const compactInput = compactTarget ? document.getElementById(compactTarget) : null;
            if (compactInput instanceof HTMLInputElement) {
                compactInput.value = autoStudentFormat && /^2\d{6}$/.test(compactValue) ? compactValue : '';
            }
            input.setCustomValidity('');
        };
        input.addEventListener('input', sync);
        input.addEventListener('change', sync);
        sync();
    }

    function applyConstraints(input) {
        const kind = kindFor(input);
        input.maxLength = kind === 'employee' ? 7 : 8;
        input.pattern = kind === 'student'
            ? '(?:0[1-9]|[1-9][0-9])-[0-9]{5}'
            : kind === 'employee'
                ? '[0-9]{7}'
                : '(?:[0-9]{7}|[0-9]{2}-[0-9]{5})';
        input.title = kind === 'student'
            ? 'Use the student ID format 01-00000.'
            : kind === 'employee'
                ? 'Enter exactly seven digits.'
                : 'Use 01-00000 for a student or exactly seven digits.';
    }

    function init(root) {
        const scope = root && root.querySelectorAll ? root : document;
        scope.querySelectorAll('[data-id-number-format]').forEach(prepare);
    }

    window.CliniqIdNumber = {
        applyConstraints,
        format,
        isValid: (value, kind = 'mixed') => patterns[kind]?.test(format(value, kind)) ?? false,
        kindFor,
        init
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => init());
    else init();
})();
