(() => {
    'use strict';

    const imageExtensions = new Set(['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg']);
    const defaultImageZoom = 1;
    let modal = null;
    let previewBody = null;
    let previewTitle = null;
    let downloadLink = null;
    let previousTrigger = null;
    let activeImage = null;
    let imageBaseSize = null;
    let imageZoom = 1;

    const fileExtension = (url) => {
        try {
            const pathname = new URL(url, window.location.href).pathname;
            return pathname.split('.').pop().toLowerCase();
        } catch (error) {
            return '';
        }
    };

    const createModal = () => {
        modal = document.createElement('div');
        modal.className = 'file-preview-backdrop';
        modal.hidden = true;
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-labelledby', 'filePreviewTitle');
        modal.innerHTML = `
            <div class="file-preview-dialog">
                <header class="file-preview-header">
                    <div class="file-preview-heading">
                        <span class="material-symbols-outlined" aria-hidden="true">preview</span>
                        <div>
                            <span class="file-preview-eyebrow">File Preview</span>
                            <h2 id="filePreviewTitle"></h2>
                        </div>
                    </div>
                    <div class="file-preview-actions">
                        <button class="file-preview-zoom-control" type="button" data-preview-zoom-out aria-label="Zoom out" title="Zoom out" disabled>
                            <span class="material-symbols-outlined" aria-hidden="true">zoom_out</span>
                        </button>
                        <button class="file-preview-zoom-control" type="button" data-preview-zoom-reset aria-label="Reset zoom" title="Reset zoom" disabled>
                            <span class="material-symbols-outlined" aria-hidden="true">fit_screen</span>
                        </button>
                        <button class="file-preview-zoom-control" type="button" data-preview-zoom-in aria-label="Zoom in" title="Zoom in" disabled>
                            <span class="material-symbols-outlined" aria-hidden="true">zoom_in</span>
                        </button>
                        <a class="file-preview-download" href="#" download>
                            <span class="material-symbols-outlined" aria-hidden="true">download</span>
                            Download
                        </a>
                        <button class="file-preview-close" type="button" aria-label="Close file preview">
                            <span class="material-symbols-outlined" aria-hidden="true">close</span>
                        </button>
                    </div>
                </header>
                <div class="file-preview-body"></div>
            </div>
        `;
        document.body.appendChild(modal);
        previewBody = modal.querySelector('.file-preview-body');
        previewTitle = modal.querySelector('#filePreviewTitle');
        downloadLink = modal.querySelector('.file-preview-download');

        modal.querySelector('.file-preview-close').addEventListener('click', closePreview);
        modal.querySelector('[data-preview-zoom-out]').addEventListener('click', () => setImageZoom(imageZoom - 0.25));
        modal.querySelector('[data-preview-zoom-reset]').addEventListener('click', () => setImageZoom(defaultImageZoom));
        modal.querySelector('[data-preview-zoom-in]').addEventListener('click', () => setImageZoom(imageZoom + 0.25));
        previewBody.addEventListener('wheel', (event) => {
            if (!activeImage || !event.ctrlKey) return;
            event.preventDefault();
            const zoomStep = event.deltaY < 0 ? 0.1 : -0.1;
            setImageZoom(imageZoom + zoomStep);
        }, { passive: false });
        modal.addEventListener('click', (event) => {
            if (event.target === modal) closePreview();
        });
    };

    const setImageZoom = (nextZoom) => {
        if (!activeImage || !imageBaseSize || !previewBody) return;
        imageZoom = Math.max(0.25, Math.min(3, Math.round(nextZoom * 100) / 100));
        activeImage.style.width = `${Math.round(imageBaseSize.width * imageZoom)}px`;
        activeImage.style.height = `${Math.round(imageBaseSize.height * imageZoom)}px`;
        // Remove size caps before measuring, then retain the scroll viewport only
        // when the rendered image genuinely exceeds the available preview area.
        previewBody.classList.toggle('is-zoomed', imageZoom > 1);
        requestAnimationFrame(() => {
            if (!activeImage || !previewBody) return;
            const imageBounds = activeImage.getBoundingClientRect();
            const needsScroll = imageBounds.width > previewBody.clientWidth
                || imageBounds.height > previewBody.clientHeight;
            previewBody.classList.toggle('is-zoomed', needsScroll);
        });
        modal.querySelector('[data-preview-zoom-out]').disabled = imageZoom <= 0.25;
        modal.querySelector('[data-preview-zoom-reset]').disabled = imageZoom === defaultImageZoom;
        modal.querySelector('[data-preview-zoom-in]').disabled = imageZoom >= 3;
    };

    const prepareImageZoom = (image) => {
        activeImage = image;
        imageBaseSize = {
            width: image.getBoundingClientRect().width,
            height: image.getBoundingClientRect().height,
        };
        imageZoom = defaultImageZoom;
        setImageZoom(defaultImageZoom);
    };

    const closePreview = () => {
        if (!modal || modal.hidden) return;
        modal.hidden = true;
        setTimeout(() => { if (modal.hidden) previewBody.replaceChildren(); }, 240);
        activeImage = null;
        imageBaseSize = null;
        imageZoom = 1;
        previewBody.classList.remove('is-zoomed');
        modal.querySelectorAll('[data-preview-zoom-out], [data-preview-zoom-reset], [data-preview-zoom-in]').forEach((control) => {
            control.disabled = true;
        });
        document.body.classList.remove('file-preview-open');
        if (previousTrigger && typeof previousTrigger.focus === 'function') previousTrigger.focus();
        previousTrigger = null;
    };

    const openPreview = (trigger) => {
        if (!modal) createModal();
        const url = trigger.getAttribute('href') || trigger.dataset.previewUrl || '';
        if (!url) return;

        previousTrigger = trigger;
        const filename = trigger.dataset.previewTitle
            || trigger.getAttribute('title')
            || decodeURIComponent(url.split('/').pop().split('?')[0])
            || 'Attached file';
        const urlExtension = fileExtension(url);
        const filenameExtension = fileExtension(filename);
        const extension = imageExtensions.has(urlExtension) || urlExtension === 'pdf'
            ? urlExtension
            : filenameExtension;
        const type = trigger.dataset.previewType || (imageExtensions.has(extension) ? 'image' : (extension === 'pdf' ? 'pdf' : 'file'));

        previewTitle.textContent = filename;
        downloadLink.href = url;
        downloadLink.setAttribute('download', filename);
        previewBody.replaceChildren();

        if (type === 'image') {
            const image = document.createElement('img');
            image.className = 'file-preview-image';
            image.addEventListener('load', () => requestAnimationFrame(() => prepareImageZoom(image)), { once: true });
            image.src = url;
            image.alt = filename;
            previewBody.appendChild(image);
        } else {
            const frame = document.createElement('iframe');
            frame.className = 'file-preview-frame';
            frame.src = url;
            frame.title = filename;
            previewBody.appendChild(frame);
        }

        modal.hidden = false;
        document.body.classList.add('file-preview-open');
        modal.querySelector('.file-preview-close').focus();
    };

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-file-preview]');
        if (!trigger) return;
        event.preventDefault();
        openPreview(trigger);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closePreview();
    });
})();
