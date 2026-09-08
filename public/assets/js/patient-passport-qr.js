(function () {
    'use strict';

    var container = document.getElementById('qr-container');
    var downloadButton = document.getElementById('download-passport-qr');

    if (!container || !downloadButton) {
        return;
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
        return;
    }

    if (typeof window.QRCode !== 'function') {
        showError('The QR code could not be loaded.');
        return;
    }

    var passportUrl = new URL(relativePassportUrl, window.location.href).href;
    container.replaceChildren();

    new window.QRCode(container, {
        text: passportUrl,
        width: 180,
        height: 180,
        colorDark: '#17261d',
        colorLight: '#ffffff',
        correctLevel: window.QRCode.CorrectLevel.H
    });

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
