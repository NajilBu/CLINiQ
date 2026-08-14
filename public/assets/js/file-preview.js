(() => {
    'use strict';

    const imageExtensions = new Set(['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg']);
    let modal = null;
    let previewBody = null;
    let previewTitle = null;
    let downloadLink = null;
    let previousTrigger = null;

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
        modal.addEventListener('click', (event) => {
            if (event.target === modal) closePreview();
        });
    };

    const closePreview = () => {
        if (!modal || modal.hidden) return;
        modal.hidden = true;
        previewBody.replaceChildren();
        document.body.classList.remove('file-preview-open');
        if (previousTrigger && typeof previousTrigger.focus === 'function') previousTrigger.focus();
        previousTrigger = null;
    };

    const openPreview = (trigger) => {
        if (!modal) createModal();
        const url = trigger.getAttribute('href') || trigger.dataset.previewUrl || '';
        if (!url) return;

        previousTrigger = trigger;
        const extension = fileExtension(url);
        const type = trigger.dataset.previewType || (imageExtensions.has(extension) ? 'image' : (extension === 'pdf' ? 'pdf' : 'file'));
        const filename = trigger.dataset.previewTitle
            || trigger.getAttribute('title')
            || decodeURIComponent(url.split('/').pop().split('?')[0])
            || 'Attached file';

        previewTitle.textContent = filename;
        downloadLink.href = url;
        downloadLink.setAttribute('download', filename);
        previewBody.replaceChildren();

        if (type === 'image') {
            const image = document.createElement('img');
            image.className = 'file-preview-image';
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
