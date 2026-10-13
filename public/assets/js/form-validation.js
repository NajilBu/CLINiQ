/* Shared inline validation for saved CLINiQ forms. Server validation remains authoritative. */
(() => {
    'use strict';

    const personNamePattern = /^[\p{L} .'-]+$/u;
    const personNameFields = new Set(['first_name', 'middle_name', 'last_name', 'full_name']);
    const excludedTypes = new Set(['button', 'file', 'hidden', 'image', 'reset', 'submit', 'search']);

    const eligible = (field) => field instanceof HTMLInputElement || field instanceof HTMLSelectElement || field instanceof HTMLTextAreaElement;
    const savedForm = (form) => form instanceof HTMLFormElement
        && String(form.method || 'get').toLowerCase() !== 'get'
        && !form.matches('[data-live-validation="off"]');
    const shouldValidate = (field) => eligible(field)
        && !field.disabled
        && !field.readOnly
        && !excludedTypes.has((field.type || '').toLowerCase())
        && !field.matches('[data-table-search], [data-live-validation="off"]')
        && savedForm(field.form);
    const canonicalField = (field) => {
        if (!(field instanceof HTMLInputElement) || field.type !== 'radio' || !field.name || !field.form) return field;
        return field.form.querySelector(`input[type="radio"][name="${CSS.escape(field.name)}"]`) || field;
    };

    const hasOwnMessage = (field) => field.dataset.liveValidationOwnMessage === '1';
    const clearOwnMessage = (field) => {
        if (!hasOwnMessage(field)) return;
        field.setCustomValidity('');
        delete field.dataset.liveValidationOwnMessage;
    };
    const setOwnMessage = (field, message) => {
        field.setCustomValidity(message);
        if (message) field.dataset.liveValidationOwnMessage = '1';
        else delete field.dataset.liveValidationOwnMessage;
    };

    const errorNode = (field) => {
        const contactError = field.form?.querySelector(`[data-contact-error="${CSS.escape(field.name)}"]`);
        const anchor = field.closest('.student-field, .clinic-label, .form-group, .field, label') || field.parentElement;
        if (anchor instanceof HTMLElement) anchor.dataset.liveValidationAnchor = 'true';
        if (contactError) return contactError;
        const existingId = field.dataset.liveValidationErrorId;
        if (existingId) return document.getElementById(existingId);

        const node = document.createElement('p');
        const id = `live-validation-${Math.random().toString(36).slice(2, 10)}`;
        node.id = id;
        node.className = 'field-live-error';
        node.hidden = true;
        node.setAttribute('aria-live', 'polite');
        field.dataset.liveValidationErrorId = id;
        field.insertAdjacentElement('afterend', node);
        return node;
    };
    const linkError = (field, node) => {
        const ids = (field.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
        if (!ids.includes(node.id)) field.setAttribute('aria-describedby', [...ids, node.id].join(' '));
    };
    const render = (field, message) => {
        const node = errorNode(field);
        linkError(field, node);
        node.textContent = message;
        node.hidden = message === '';
        field.classList.toggle('input-error', message !== '');
        field.setAttribute('aria-invalid', message !== '' ? 'true' : 'false');
    };

    const passwordFields = (form) => [...form.querySelectorAll('input[type="password"]')];
    const isPasswordConfirmation = (field) => field.dataset.passwordFor !== undefined || /(?:confirm|confirmation)/i.test(field.name);
    const isEmailConfirmation = (field) => field.dataset.emailConfirmationFor !== undefined;
    const isNewPassword = (field) => {
        if (isPasswordConfirmation(field)) return false;
        if (field.dataset.passwordStrength !== undefined) return true;
        const hasConfirmation = passwordFields(field.form).some((candidate) => /(?:confirm|confirmation)/i.test(candidate.name));
        if (!hasConfirmation) return false;
        return field.autocomplete === 'new-password' || /^(password|new_password)$/i.test(field.name);
    };
    const confirmationFor = (field) => {
        const selector = field.dataset.passwordConfirm;
        if (selector) return field.form?.querySelector(selector) || null;
        return passwordFields(field.form).find((candidate) => /(?:confirm|confirmation)/i.test(candidate.name)) || null;
    };
    const passwordForConfirmation = (field) => {
        const selector = field.dataset.passwordFor;
        if (selector) return field.form?.querySelector(selector) || null;
        return passwordFields(field.form).find((candidate) => isNewPassword(candidate)) || null;
    };
    const strongPassword = (value) => value.length >= 8 && value.length <= 128
        && /[a-z]/.test(value) && /[A-Z]/.test(value) && /\d/.test(value) && /[^A-Za-z0-9]/.test(value);

    const namedMessage = (field, showRequired = false) => {
        const value = String(field.value || '');
        const trimmed = value.trim();
        const name = field.name.replace(/\[.*$/, '');
        if ((field.dataset.personName !== undefined || personNameFields.has(name) || field.autocomplete === 'given-name' || field.autocomplete === 'additional-name' || field.autocomplete === 'family-name')
            && value !== '' && !personNamePattern.test(value)) {
            return 'Use only letters, spaces, apostrophes, periods, and hyphens.';
        }
        if (field.dataset.institutionalEmail !== undefined && !isEmailConfirmation(field) && value !== '' && !/^[^\s@]+@plpasig\.edu\.ph$/i.test(value)) {
            return 'Use your @plpasig.edu.ph institutional email address.';
        }
        if (field.dataset.emailConfirmationFor) {
            const source = field.form?.querySelector(field.dataset.emailConfirmationFor);
            if (value !== '' && source && value.toLowerCase() !== String(source.value || '').toLowerCase()) return 'Re-enter the same email address.';
        }
        if (isNewPassword(field) && value !== '' && !strongPassword(value)) {
            return 'Use 8–128 characters with uppercase, lowercase, number, and special character.';
        }
        if (isPasswordConfirmation(field)) {
            const password = passwordForConfirmation(field);
            if (value !== '' && password && value !== password.value) return 'Re-enter the same password.';
        }
        if (field.type === 'date' && value !== '' && (field.min && value < field.min || field.max && value > field.max)) {
            return 'Enter a date within the allowed range.';
        }
        if (showRequired && field.required && trimmed === '') {
            if (isEmailConfirmation(field)) return 'Re-enter your email address.';
            if (isPasswordConfirmation(field)) return 'Re-enter your password.';
            return field.dataset.requiredMessage || 'Complete this required field.';
        }
        return '';
    };
    const nativeMessage = (field, showRequired = false) => {
        const validity = field.validity;
        if (validity.valid) return '';
        if (validity.valueMissing) {
            if (!showRequired) return '';
            if (isEmailConfirmation(field)) return 'Re-enter your email address.';
            if (isPasswordConfirmation(field)) return 'Re-enter your password.';
            return field.dataset.requiredMessage || 'Complete this required field.';
        }
        if (validity.typeMismatch) return isEmailConfirmation(field) ? 'Re-enter a valid email address.' : field.type === 'email' ? 'Enter a valid email address.' : 'Enter a valid value.';
        if (validity.tooShort) return `Use at least ${field.minLength} characters.`;
        if (validity.tooLong) return `Use no more than ${field.maxLength} characters.`;
        if (validity.patternMismatch) return field.title || 'Use the required format.';
        if (validity.rangeUnderflow || validity.rangeOverflow) return 'Enter a value within the allowed range.';
        return field.validationMessage || 'Enter a valid value.';
    };

    const validate = (field, reveal = false, showRequired = reveal) => {
        field = canonicalField(field);
        if (!shouldValidate(field)) return true;
        clearOwnMessage(field);
        if (field.matches('[data-id-number-format]')
            && String(field.value || '').trim() !== ''
            && window.CliniqIdNumber
            && !window.CliniqIdNumber.isValid(field.value, window.CliniqIdNumber.kindFor(field))
            && !reveal) {
            render(field, '');
            return false;
        }
        const message = namedMessage(field, showRequired);
        if (message) setOwnMessage(field, message);
        const finalMessage = message || nativeMessage(field, showRequired);
        if (reveal || field.dataset.liveValidationTouched === '1') render(field, finalMessage);
        return finalMessage === '';
    };
    const validateRelated = (field, reveal = false, showRequired = reveal) => {
        field = canonicalField(field);
        validate(field, reveal, showRequired);
        if (isNewPassword(field)) {
            const confirmation = confirmationFor(field);
            if (confirmation) validate(confirmation, reveal && confirmation.dataset.liveValidationTouched === '1', showRequired && confirmation.dataset.liveValidationTouched === '1');
        }
    };
    const prepare = (form) => {
        if (!savedForm(form) || form.dataset.liveValidationReady === '1') return;
        form.dataset.liveValidationReady = '1';
        form.noValidate = true;
        form.addEventListener('submit', (event) => {
            const fields = [...form.elements].filter(shouldValidate).filter((field, index, all) => field.type !== 'radio' || all.indexOf(canonicalField(field)) === index);
            const firstInvalid = fields.find((field) => !validate(field, true, true));
            if (!firstInvalid) return;
            event.preventDefault();
            firstInvalid.focus({ preventScroll: false });
        });
    };
    const init = (root = document) => {
        root.querySelectorAll?.('form').forEach(prepare);
    };

    document.addEventListener('input', (event) => {
        const field = canonicalField(event.target);
        if (!shouldValidate(field)) return;
        field.dataset.liveValidationTouched = '1';
        validateRelated(field);
    });
    document.addEventListener('change', (event) => {
        const field = canonicalField(event.target);
        if (!shouldValidate(field)) return;
        field.dataset.liveValidationTouched = '1';
        validateRelated(field);
    });
    document.addEventListener('blur', (event) => {
        const field = canonicalField(event.target);
        if (!shouldValidate(field)) return;
        field.dataset.liveValidationTouched = '1';
        validateRelated(field, true, false);
    }, true);
    window.CliniqFormValidation = { init, validate };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => init());
    else init();
})();
