/* Shared patient ID formatting and validation. */
(function () {
    'use strict';

    const COMPLETE_PATTERN = /^(?:\d{2}-\d{5}|[A-Z]{3,10}-\d{4})$/;

    function format(value) {
        const compact = String(value || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
        if (!compact) return '';

        if (/^\d/.test(compact)) {
            const digits = compact.replace(/\D/g, '').slice(0, 7);
            return digits.length <= 2 ? digits : `${digits.slice(0, 2)}-${digits.slice(2)}`;
        }

        const match = compact.match(/^([A-Z]{0,10})(\d*)$/);
        if (!match) return compact;
        const prefix = match[1];
        const digits = match[2].slice(0, 4);
        return digits ? `${prefix}-${digits}` : prefix;
    }

    function validationMessage(value) {
        if (!value || COMPLETE_PATTERN.test(value)) return '';
        return 'Use a student ID such as 23-00262 or a prefixed ID such as FAC-0001.';
    }

    function prepare(input) {
        if (!(input instanceof HTMLInputElement) || input.dataset.idNumberFormatterReady === '1') return;
        input.dataset.idNumberFormatterReady = '1';
        input.maxLength = 15;
        input.autocapitalize = 'characters';
        input.pattern = '(?:[0-9]{2}-[0-9]{5}|[A-Za-z]{3,10}-[0-9]{4})';
        input.title = 'Use 23-00262 or FAC-0001 format.';

        const sync = () => {
            input.value = format(input.value);
            input.setCustomValidity(validationMessage(input.value));
        };
        input.addEventListener('input', sync);
        input.addEventListener('change', sync);
        sync();
    }

    function init(root) {
        const scope = root && root.querySelectorAll ? root : document;
        scope.querySelectorAll('[data-id-number-format]').forEach(prepare);
    }

    window.CliniqIdNumber = { format, isValid: (value) => COMPLETE_PATTERN.test(format(value)), init };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => init());
    else init();
})();
