(function () {
    'use strict';

    var container = document.getElementById('qr-container');
    var downloadButton = document.getElementById('download-passport-qr');
    var nfcButton = document.getElementById('write-passport-nfc');
    var nfcStatus = document.getElementById('passport-nfc-status');
    var overwriteDialog = document.getElementById('passport-nfc-overwrite-dialog');
    var overwritePassword = document.getElementById('passport-nfc-password');
    var overwritePasswordLabel = document.getElementById('passport-nfc-password-label');
    var overwriteError = document.getElementById('passport-nfc-overwrite-error');
    var overwriteReady = document.getElementById('passport-nfc-overwrite-ready');
    var overwriteConfirm = document.getElementById('passport-nfc-overwrite-confirm');
    var overwriteCancel = document.getElementById('passport-nfc-overwrite-cancel');

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
        var scannedTag = null;
        var passwordConfirmed = false;
        var checkingPassword = false;

        function recordSignature(message) {
            return Array.from(message.records || []).map(function (record) {
                var data = record.data;
                var bytes = data ? Array.from(new Uint8Array(data.buffer, data.byteOffset, data.byteLength)) : [];
                return record.recordType + ':' + bytes.join(',');
            }).join('|');
        }

        function scanOneTag(onRead) {
            return new Promise(function (resolve, reject) {
                var reader = new window.NDEFReader();
                var controller = new AbortController();
                var finished = false;
                var timer = window.setTimeout(function () {
                    if (finished) return;
                    finished = true;
                    controller.abort();
                    reject(new Error('No NFC tag was detected. Try again and hold the tag closer to your phone.'));
                }, 45000);

                reader.onreading = function (event) {
                    if (finished) return;
                    finished = true;
                    window.clearTimeout(timer);
                    Promise.resolve().then(function () { return onRead(event, reader); }).then(resolve, reject).finally(function () {
                        controller.abort();
                    });
                };
                reader.scan({ signal: controller.signal }).catch(function (error) {
                    if (finished) return;
                    finished = true;
                    window.clearTimeout(timer);
                    reject(error);
                });
            });
        }

        function resetOverwriteDialog() {
            scannedTag = null;
            passwordConfirmed = false;
            overwritePassword.value = '';
            overwritePassword.hidden = false;
            overwritePasswordLabel.hidden = false;
            overwriteReady.hidden = true;
            overwriteError.hidden = true;
            overwriteConfirm.textContent = 'Confirm overwrite';
            overwriteConfirm.disabled = false;
            overwriteCancel.disabled = false;
            nfcButton.disabled = false;
        }

        overwriteCancel.addEventListener('click', function () { overwriteDialog.close(); });
        overwriteDialog.addEventListener('cancel', function (event) {
            if (checkingPassword) event.preventDefault();
        });
        overwriteDialog.addEventListener('close', resetOverwriteDialog);
        overwritePassword.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                overwriteConfirm.click();
            }
        });

        overwriteConfirm.addEventListener('click', async function () {
            if (checkingPassword || !scannedTag) return;
            overwriteError.hidden = true;

            if (!passwordConfirmed) {
                if (!overwritePassword.value) {
                    overwriteError.textContent = 'Enter your current password.';
                    overwriteError.hidden = false;
                    overwritePassword.focus();
                    return;
                }
                checkingPassword = true;
                overwriteConfirm.disabled = overwriteCancel.disabled = true;
                try {
                    var response = await fetch('patient-nfc-authorize.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            _csrf: document.querySelector('meta[name="csrf-token"]')?.content || '',
                            password: overwritePassword.value
                        })
                    });
                    if (response.status !== 204) {
                        var failure = await response.json().catch(function () { return {}; });
                        throw new Error(failure.error || 'Could not verify your password. Please sign in again and retry.');
                    }
                    passwordConfirmed = true;
                    overwritePassword.value = '';
                    overwritePassword.hidden = true;
                    overwritePasswordLabel.hidden = true;
                    overwriteReady.hidden = false;
                    overwriteConfirm.textContent = 'Tap same tag to overwrite';
                } catch (error) {
                    overwriteError.textContent = error.message;
                    overwriteError.hidden = false;
                } finally {
                    checkingPassword = false;
                    overwriteConfirm.disabled = overwriteCancel.disabled = false;
                }
                return;
            }

            var expectedTag = scannedTag;
            overwriteDialog.close();
            nfcButton.disabled = true;
            showNfcStatus('Hold the same NFC tag against your phone again to overwrite it.');
            try {
                await scanOneTag(async function (event, reader) {
                    var serial = event.serialNumber || '';
                    if ((expectedTag.serial && serial && expectedTag.serial !== serial)
                        || recordSignature(event.message) !== expectedTag.signature) {
                        throw new Error('This is a different tag or its contents changed. Nothing was written. Start again.');
                    }
                    showNfcStatus('Writing to the confirmed tag. Keep it against the phone.');
                    await reader.write({ records: [{ recordType: 'url', data: passportUrl }] }, { overwrite: true });
                });
                showNfcStatus('NFC tag overwritten successfully. Test it with another phone before issuing the tag.');
            } catch (error) {
                showNfcStatus(error.message || nfcErrorMessage(error));
            } finally {
                nfcButton.disabled = false;
            }
        });

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
            showNfcStatus('Hold an NFC tag against your phone to check whether it already contains data.');

            try {
                var wroteEmptyTag = await scanOneTag(async function (event, reader) {
                    if (event.message.records.length > 0) {
                        scannedTag = {
                            serial: event.serialNumber || '',
                            signature: recordSignature(event.message)
                        };
                        overwriteDialog.showModal();
                        overwritePassword.focus();
                        showNfcStatus('This tag already contains data. Confirm in the popup before overwriting it.');
                        return false;
                    }
                    showNfcStatus('Empty tag detected. Hold it against the phone while writing.');
                    await reader.write({ records: [{ recordType: 'url', data: passportUrl }] }, { overwrite: false });
                    return true;
                });
                if (wroteEmptyTag) showNfcStatus('NFC tag written successfully. Test it with another phone before issuing the tag.');
            } catch (error) {
                showNfcStatus(error.message || nfcErrorMessage(error));
            } finally {
                if (!overwriteDialog.open) nfcButton.disabled = false;
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
