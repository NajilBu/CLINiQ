(() => {
    'use strict';

    const formatPhone = (value) => {
        let digits = String(value || '').replace(/\D/g, '');
        if (digits.startsWith('63')) digits = digits.slice(2);
        else if (digits.startsWith('0')) digits = digits.slice(1);
        digits = digits.slice(0, 10);

        if (!digits) return '';
        let formatted = `+63 ${digits.slice(0, 3)}`;
        if (digits.length > 3) formatted += ` ${digits.slice(3, 6)}`;
        if (digits.length > 6) formatted += ` ${digits.slice(6, 10)}`;
        return formatted;
    };

    const titleCaseName = (value) => String(value || '')
        .trim()
        .replace(/\s+/g, ' ')
        .toLocaleLowerCase()
        .replace(/(^|[\s'-])\p{L}/gu, (match) => match.toLocaleUpperCase());

    const initEmergencyContactForm = (form) => {
        if (!(form instanceof HTMLFormElement) || form.dataset.emergencyContactReady === '1') return;
        form.dataset.emergencyContactReady = '1';

        const guardian = form.querySelector('[name="guardian_name"]');
        const relationship = form.querySelector('[name="relationship"]');
        const primary = form.querySelector('[name="primary_contact"]');
        const secondary = form.querySelector('[name="secondary_contact"]');
        if (!(guardian instanceof HTMLInputElement)
            || !(relationship instanceof HTMLSelectElement)
            || !(primary instanceof HTMLInputElement)
            || !(secondary instanceof HTMLInputElement)) return;

        const showError = (input, message) => {
            input.setCustomValidity(message);
            input.setAttribute('aria-invalid', message === '' ? 'false' : 'true');
            const error = form.querySelector(`[data-contact-error="${input.name}"]`);
            if (error) {
                error.textContent = message;
                error.hidden = message === '';
            }
        };

        const validateGuardian = () => {
            const value = guardian.value.trim();
            let message = '';
            if (!value) message = 'Enter the guardian or next-of-kin name.';
            else if (value.length < 2 || value.length > 100 || !/^[\p{L}\p{M}][\p{L}\p{M} .'-]{1,99}$/u.test(value)) {
                message = 'Use letters, spaces, apostrophes, periods, or hyphens only.';
            }
            showError(guardian, message);
            return message === '';
        };

        const validateRelationship = () => {
            const message = relationship.value ? '' : 'Select the guardian relationship.';
            showError(relationship, message);
            return message === '';
        };

        const phoneIsValid = (value) => /^\+63 9\d{2} \d{3} \d{4}$/.test(value);
        const validatePhones = () => {
            const primaryMessage = phoneIsValid(primary.value)
                ? ''
                : 'Enter a valid number such as +63 912 345 6789.';
            let secondaryMessage = '';
            if (secondary.value && !phoneIsValid(secondary.value)) {
                secondaryMessage = 'Enter a valid number or leave this blank.';
            } else if (secondary.value && secondary.value === primary.value) {
                secondaryMessage = 'Use a different number from the primary contact.';
            }
            showError(primary, primaryMessage);
            showError(secondary, secondaryMessage);
            return primaryMessage === '' && secondaryMessage === '';
        };

        guardian.addEventListener('blur', () => {
            guardian.value = titleCaseName(guardian.value);
            validateGuardian();
        });
        guardian.addEventListener('input', validateGuardian);
        relationship.addEventListener('change', validateRelationship);

        [primary, secondary].forEach((input) => {
            input.addEventListener('input', () => {
                input.value = formatPhone(input.value);
                validatePhones();
            });
            input.addEventListener('blur', validatePhones);
        });

        form.addEventListener('submit', (event) => {
            guardian.value = titleCaseName(guardian.value);
            primary.value = formatPhone(primary.value);
            secondary.value = formatPhone(secondary.value);
            const valid = validateGuardian() & validateRelationship() & validatePhones();
            if (!valid) {
                event.preventDefault();
                form.querySelector(':invalid')?.focus();
                form.reportValidity();
            }
        });

        primary.value = formatPhone(primary.value);
        secondary.value = formatPhone(secondary.value);
    };

    document.querySelectorAll('[data-emergency-contact-form]').forEach(initEmergencyContactForm);
})();
