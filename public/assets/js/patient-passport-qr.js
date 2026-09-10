(function () {
    'use strict';

    var container = document.getElementById('qr-container');
    var downloadButton = document.getElementById('download-passport-qr');
    var nfcButton = document.getElementById('write-passport-nfc');
    var nfcStatus = document.getElementById('passport-nfc-status');

    if (!container) {
        return;
    }

    function showNfcStatus(message) {
        if (!nfcStatus) {
            return;
        }

        nfcStatus.textContent = message;
        nfcStatus.hidden = false;
    }

    function nfcErrorMessage(error) {
        switch (error && error.name) {
            case 'NotAllowedError':
                return 'NFC permission was denied. Allow NFC access in Chrome and try again.';
            case 'NotSupportedError':
                return 'This NFC tag or device is not supported. Use an unlocked NDEF-compatible tag.';
            case 'NotReadableError':
                return 'The NFC tag could not be read. Hold it against the phone and try again.';
            case 'NetworkError':
                return 'The NFC transfer was interrupted. Keep the tag against the phone until writing finishes.';
            case 'AbortError':
                return 'NFC writing was cancelled.';
            default:
                return 'The NFC tag could not be written. Confirm that NFC is enabled and try again in Chrome for Android.';
        }
    }

    function showError(message) {
        container.replaceChildren();
        var error = document.createElement('span');
        error.className = 'passport-qr-error';
        error.textContent = message;
        container.appendChild(error);
        downloadButton.disabled = true;
    }

    var relativePassportUrl = container.dataset.passportUrl || '';
    if (!relativePassportUrl || relativePassportUrl.indexOf('token=not-generated') !== -1) {
        showError('A patient QR code is not available yet.');
        if (nfcButton) {
            nfcButton.disabled = true;
        }
        return;
    }

    var passportUrl = new URL(relativePassportUrl, window.location.href).href;

    if (nfcButton) {
        nfcButton.addEventListener('click', async function () {
            if (!window.isSecureContext) {
                showNfcStatus('NFC writing requires the HTTPS patient portal. Open the current Cloudflare tunnel and try again.');
                return;
            }

            if (!('NDEFReader' in window)) {
                showNfcStatus('Web NFC is unavailable in this browser. Open this page in Chrome on an NFC-enabled Android phone.');
                return;
            }

            nfcButton.disabled = true;
            showNfcStatus('Ready to write. Hold an unlocked NFC tag against the back of your phone.');

            try {
                var ndef = new window.NDEFReader();
                await ndef.write({
                    records: [{
                        recordType: 'url',
                        data: passportUrl
                    }]
                });
                showNfcStatus('NFC tag written successfully. Test it with another phone before issuing the tag.');
            } catch (error) {
                showNfcStatus(nfcErrorMessage(error));
            } finally {
                nfcButton.disabled = false;
            }
        });
    }

    if (typeof window.QRCode !== 'function') {
        showError('The QR code could not be loaded.');
        return;
    }

    container.replaceChildren();

    new window.QRCode(container, {
        text: passportUrl,
        width: 180,
        height: 180,
        colorDark: '#17261d',
        colorLight: '#ffffff',
        correctLevel: window.QRCode.CorrectLevel.H
    });

    if (!downloadButton) {
        return;
    }

    downloadButton.disabled = false;
    downloadButton.addEventListener('click', function () {
        var canvas = container.querySelector('canvas');
        var image = container.querySelector('img');
        var dataUrl = canvas ? canvas.toDataURL('image/png') : (image ? image.src : '');

        if (!dataUrl) {
            showError('The QR code could not be downloaded.');
            return;
        }

        var link = document.createElement('a');
        link.href = dataUrl;
        link.download = container.dataset.downloadName || 'emergency-passport-qr.png';
        document.body.appendChild(link);
        link.click();
        link.remove();
    });
})();
