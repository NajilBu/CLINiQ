(() => {
    'use strict';

    // Shared by staff and student pages; render without an icon-font dependency.
    const iconPaths = {
        preview: 'M200 -120Q167 -120 143.5 -143.5Q120 -167 120 -200V-760Q120 -793 143.5 -816.5Q167 -840 200 -840H760Q793 -840 816.5 -816.5Q840 -793 840 -760V-200Q840 -167 816.5 -143.5Q793 -120 760 -120ZM200 -200H760Q760 -200 760.0 -200.0Q760 -200 760 -200V-680H200V-200Q200 -200 200.0 -200.0Q200 -200 200 -200ZM240 -440Q269 -511 333.5 -555.5Q398 -600 480.0 -600.0Q562 -600 626.5 -555.5Q691 -511 720 -440Q691 -369 626.5 -324.5Q562 -280 480.0 -280.0Q398 -280 333.5 -324.5Q269 -369 240 -440ZM654 -440Q628 -487 582.0 -513.5Q536 -540 480.0 -540.0Q424 -540 378.0 -513.5Q332 -487 306 -440Q332 -393 378.0 -366.5Q424 -340 480.0 -340.0Q536 -340 582.0 -366.5Q628 -393 654 -440ZM480.0 -440.0Q480 -440 480.0 -440.0Q480 -440 480.0 -440.0Q480 -440 480.0 -440.0Q480 -440 480.0 -440.0Q480 -440 480.0 -440.0Q480 -440 480.0 -440.0Q480 -440 480.0 -440.0Q480 -440 480.0 -440.0ZM480.0 -380.0Q505 -380 522.5 -397.5Q540 -415 540.0 -440.0Q540 -465 522.5 -482.5Q505 -500 480.0 -500.0Q455 -500 437.5 -482.5Q420 -465 420.0 -440.0Q420 -415 437.5 -397.5Q455 -380 480.0 -380.0Z',
        zoom_out: 'M784 -120 532 -372Q502 -348 463.0 -334.0Q424 -320 380 -320Q271 -320 195.5 -395.5Q120 -471 120.0 -580.0Q120 -689 195.5 -764.5Q271 -840 380 -840Q489 -840 564.5 -764.5Q640 -689 640 -580Q640 -536 626.0 -497.0Q612 -458 588 -428L840 -176ZM380 -400Q455 -400 507.5 -452.5Q560 -505 560.0 -580.0Q560 -655 507.5 -707.5Q455 -760 380 -760Q305 -760 252.5 -707.5Q200 -655 200.0 -580.0Q200 -505 252.5 -452.5Q305 -400 380 -400ZM280 -540V-620H480V-540Z',
        fit_screen: 'M800 -600V-720Q800 -720 800.0 -720.0Q800 -720 800 -720H680V-800H800Q833 -800 856.5 -776.5Q880 -753 880 -720V-600ZM80 -600V-720Q80 -753 103.5 -776.5Q127 -800 160 -800H280V-720H160Q160 -720 160.0 -720.0Q160 -720 160 -720V-600ZM680 -160V-240H800Q800 -240 800.0 -240.0Q800 -240 800 -240V-360H880V-240Q880 -207 856.5 -183.5Q833 -160 800 -160ZM160 -160Q127 -160 103.5 -183.5Q80 -207 80 -240V-360H160V-240Q160 -240 160.0 -240.0Q160 -240 160 -240H280V-160ZM240 -320V-640H720V-320ZM320 -400H640V-560H320ZM320 -400V-560V-400Z',
        zoom_in: 'M784 -120 532 -372Q502 -348 463.0 -334.0Q424 -320 380 -320Q271 -320 195.5 -395.5Q120 -471 120.0 -580.0Q120 -689 195.5 -764.5Q271 -840 380 -840Q489 -840 564.5 -764.5Q640 -689 640 -580Q640 -536 626.0 -497.0Q612 -458 588 -428L840 -176ZM380 -400Q455 -400 507.5 -452.5Q560 -505 560.0 -580.0Q560 -655 507.5 -707.5Q455 -760 380 -760Q305 -760 252.5 -707.5Q200 -655 200.0 -580.0Q200 -505 252.5 -452.5Q305 -400 380 -400ZM340 -460V-540H260V-620H340V-700H420V-620H500V-540H420V-460Z',
        download: 'M480 -320 280 -520 336 -578 440 -474V-800H520V-474L624 -578L680 -520ZM240 -160Q207 -160 183.5 -183.5Q160 -207 160 -240V-360H240V-240Q240 -240 240.0 -240.0Q240 -240 240 -240H720Q720 -240 720.0 -240.0Q720 -240 720 -240V-360H800V-240Q800 -207 776.5 -183.5Q753 -160 720 -160Z',
        close: 'M256 -200 200 -256 424 -480 200 -704 256 -760 480 -536 704 -760 760 -704 536 -480 760 -256 704 -200 480 -424Z',
    };
    const icon = (name) => `<svg class="file-preview-icon" viewBox="0 -960 960 960" aria-hidden="true" focusable="false"><path d="${iconPaths[name]}"/></svg>`;

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
                        ${icon('preview')}
                        <div>
                            <span class="file-preview-eyebrow">File Preview</span>
                            <h2 id="filePreviewTitle"></h2>
                        </div>
                    </div>
                    <div class="file-preview-actions">
                        <button class="file-preview-zoom-control" type="button" data-preview-zoom-out aria-label="Zoom out" title="Zoom out" disabled>
                            ${icon('zoom_out')}
                        </button>
                        <button class="file-preview-zoom-control" type="button" data-preview-zoom-reset aria-label="Reset zoom" title="Reset zoom" disabled>
                            ${icon('fit_screen')}
                        </button>
                        <button class="file-preview-zoom-control" type="button" data-preview-zoom-in aria-label="Zoom in" title="Zoom in" disabled>
                            ${icon('zoom_in')}
                        </button>
                        <a class="file-preview-download" href="#" download>
                            ${icon('download')}
                            Download
                        </a>
                        <button class="file-preview-close" type="button" aria-label="Close file preview">
                            ${icon('close')}
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
