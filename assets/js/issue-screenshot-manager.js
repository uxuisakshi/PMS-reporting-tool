/**
 * Issue Page Screenshot Manager
 * Handles screenshot uploads and management for issues
 */

if (typeof window.IssueScreenshotManager === 'undefined') {
    window.IssueScreenshotManager = class IssueScreenshotManager {
        constructor(config = {}) {
            this.baseDir = config.baseDir || '';
            this.projectId = config.projectId || 0;
            this.apiUrl = `${this.baseDir}/api/issue_screenshot_upload.php`;
            this.pendingDeleteScreenshotId = null;
            this.screenshots = [];
            this.filteredScreenshots = [];
            this.pageSize = 10;
            this.ensureNotificationContainer();
            this.init();
            this.updateCountBadge();
        }

        ensureNotificationContainer() {
            let container = document.getElementById('pmsNotificationContainer');
            if (!container) {
                container = document.createElement('div');
                container.id = 'pmsNotificationContainer';
                container.className = 'toast-container position-fixed top-0 end-0 p-3';
                document.body.appendChild(container);
            }
            // Ensure high z-index to stay above modals (Bootstrap modals are usually 1055)
            container.style.zIndex = '11000';
            
            // Move it to the end of body to avoid stacking context issues
            if (container.parentElement !== document.body) {
                document.body.appendChild(container);
            }
        }

        init() {
            this.setupEventListeners();
            this.setupDeleteModal();
            this.setupViewControls();
        }

        setupEventListeners() {
            // Upload button click
            document.addEventListener('click', (e) => {
                const uploadBtn = e.target.closest('.btn-upload-page-screenshots');
                if (uploadBtn) {
                    const pageId = uploadBtn.dataset.pageId;
                    if (pageId) {
                        this.openUploadModal(pageId);
                    }
                }

                const viewBtn = e.target.closest('.btn-open-page-screenshots');
                if (viewBtn) {
                    const pageId = viewBtn.dataset.pageId;
                    if (pageId) {
                        this.openViewModal(pageId);
                    }
                }

                // Delete screenshot
                const deleteBtn = e.target.closest('.btn-delete-screenshot');
                if (deleteBtn) {
                    const screenshotId = deleteBtn.dataset.screenshotId;
                    if (screenshotId) {
                        this.promptDeleteScreenshot(screenshotId);
                    }
                }

                const singleViewBtn = e.target.closest('.btn-view-screenshot');
                if (singleViewBtn) {
                    const screenshotId = singleViewBtn.dataset.screenshotId;
                    if (screenshotId) {
                        this.viewScreenshot(screenshotId);
                    }
                }
            });

            // Upload form submit — delegated so it works regardless of when modal renders
            document.addEventListener('submit', (e) => {
                if (e.target && e.target.id === 'screenshotUploadForm') {
                    this.uploadScreenshots(e);
                }
            });
        }

        setupDeleteModal() {
            const confirmBtn = document.getElementById('confirmDeleteScreenshotBtn');
            if (!confirmBtn) {
                return;
            }

            confirmBtn.addEventListener('click', () => {
                if (!this.pendingDeleteScreenshotId) {
                    return;
                }
                this.deleteScreenshot(this.pendingDeleteScreenshotId);
            });
        }

        setupViewControls() {
            const container = document.body; // Root container to look within
            
            container.addEventListener('input', (e) => {
                if (e.target.id === 'pageScreenshotsSearchInput') {
                    this.currentPage = 1;
                    this.applyScreenshotFilters();
                }
            });

            container.addEventListener('change', (e) => {
                if (e.target.id === 'pageScreenshotsUrlFilter') {
                    this.currentPage = 1;
                    this.applyScreenshotFilters();
                }
                if (e.target.id === 'pageScreenshotsPageSize') {
                    this.pageSize = parseInt(e.target.value, 10) || 10;
                    this.currentPage = 1;
                    this.renderScreenshotsTable();
                }
            });

            container.addEventListener('click', (e) => {
                if (e.target.id === 'pageScreenshotsResetFiltersBtn') {
                    this.resetViewFilters();
                }
                if (e.target.id === 'pageScreenshotsPrevBtn') {
                    if (this.currentPage > 1) {
                        this.currentPage -= 1;
                        this.renderScreenshotsTable();
                    }
                }
                if (e.target.id === 'pageScreenshotsNextBtn') {
                    const totalPages = this.getTotalPages();
                    if (this.currentPage < totalPages) {
                        this.currentPage += 1;
                        this.renderScreenshotsTable();
                    }
                }
            });
        }

        openUploadModal(pageId) {
            const modalEl = document.getElementById('issueScreenshotUploadModal');
            if (!modalEl) return;

            const modal = new bootstrap.Modal(modalEl);
            
            // Set form values
            const form = document.getElementById('screenshotUploadForm');
            if (form) form.dataset.pageId = pageId;
            
            // Reset form
            this.resetUploadForm();
            
            // Load grouped URLs for this page
            this.loadGroupedUrls(pageId);

            modal.show();
        }

        openViewModal(pageId) {
            const modalEl = document.getElementById('pageScreenshotsViewModal');
            if (!modalEl) return;

            const modal = new bootstrap.Modal(modalEl);
            const tableBody = document.getElementById('pageScreenshotsTableBody');

            if (tableBody) {
                tableBody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">Loading screenshots...</td></tr>';
            }

            this.resetViewFilters();
            this.loadScreenshots(pageId);
            modal.show();
        }

        setStatus(message, type = 'info') {
            const status = document.getElementById('screenshotUploadStatus');
            if (!status) return;

            if (!message) {
                status.className = 'd-none';
                status.innerHTML = '';
                return;
            }

            status.className = `alert alert-${type} small mb-3`;
            status.classList.remove('d-none'); // Explicitly show
            status.innerHTML = message;
        }

        buildSecureFileUrl(filePath) {
            const normalizedPath = String(filePath || '').trim().replace(/^\/+/, '');
            if (!normalizedPath) {
                return '';
            }

            let base = this.baseDir ? this.baseDir.replace(/\/$/, '') : '';
            // Ensure base starts with / if it's not empty and not absolute
            if (base && !base.startsWith('/') && !base.includes('://')) {
                base = '/' + base;
            }
            
            return `${base}/api/secure_file.php?path=${encodeURIComponent(normalizedPath)}`;
        }

        resolveImageUrl(screenshot) {
            if (screenshot?.public_url) {
                return screenshot.public_url;
            }

            const filePath = String(screenshot?.file_path || '').trim().replace(/^\/+/, '');
            if (!filePath) {
                return '';
            }

            return this.buildSecureFileUrl(filePath);
        }

        setupGroupedUrlSelect() {
            const select = document.getElementById('screenshotGroupedUrlSelect');
            if (!select) return;

            if (window.jQuery && typeof window.jQuery.fn.select2 === 'function') {
                const $select = window.jQuery(select);
                if (!$select.hasClass('select2-hidden-accessible')) {
                    $select.select2({
                        width: '100%',
                        placeholder: select.dataset.placeholder || '-- Select URL (optional) --',
                        allowClear: true,
                        dropdownParent: window.jQuery('#issueScreenshotUploadModal')
                    });
                }
            }
        }

        renderGroupedUrlOptions(groupedUrls) {
            const select = document.getElementById('screenshotGroupedUrlSelect');
            if (!select) return;

            select.innerHTML = '<option value="">-- Select URL (optional) --</option>';
            
            groupedUrls.forEach((url, index) => {
                const urlText = String(url?.url || url?.normalized_url || '').trim();
                if (!urlText) {
                    return;
                }

                const option = document.createElement('option');
                option.value = url.id ? String(url.id) : `fallback_${index}`;
                option.textContent = urlText;
                select.appendChild(option);
            });

            if (window.jQuery && typeof window.jQuery.fn.select2 === 'function') {
                window.jQuery(select).val('').trigger('change.select2');
            }
        }

        loadGroupedUrls(pageId) {
            const select = document.getElementById('screenshotGroupedUrlSelect');
            if (!select) return;

            this.setupGroupedUrlSelect();
            select.innerHTML = '<option value="">Loading URLs...</option>';

            fetch(`${this.apiUrl}?action=grouped_urls&page_id=${encodeURIComponent(pageId)}`)
                .then((response) => response.json())
                .then((data) => {
                    const groupedUrls = Array.isArray(data.grouped_urls)
                        ? data.grouped_urls
                        : (window.ProjectConfig?.groupedUrls || []);
                    this.renderGroupedUrlOptions(groupedUrls);
                })
                .catch((error) => {
                    console.error('Error loading grouped URLs:', error);
                    this.renderGroupedUrlOptions(window.ProjectConfig?.groupedUrls || []);
                });
        }

        loadScreenshots(pageId) {
            fetch(`${this.apiUrl}?action=list&page_id=${pageId}`)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        this.screenshots = Array.isArray(data.screenshots) ? data.screenshots : [];
                        this.populateUrlFilterOptions();
                        this.applyScreenshotFilters();
                        this.updateCountBadge(this.screenshots.length);
                    }
                })
                .catch(err => console.error('Error loading screenshots:', err));
        }

        async updateCountBadge(count = null) {
            const pageId = this.pageId || window.ProjectConfig?.pageId;
            if (!pageId) return;

            if (count === null) {
                try {
                    const r = await fetch(`${this.apiUrl}?action=count&page_id=${pageId}`);
                    const data = await r.json();
                    if (data.success) {
                        count = data.count;
                    }
                } catch (e) {}
            }

            if (count !== null) {
                const badges = document.querySelectorAll(`.screenshot-count-badge[data-page-id="${pageId}"]`);
                badges.forEach(badge => {
                    badge.textContent = count;
                    badge.classList.toggle('d-none', count === 0);
                });
            }
        }

        populateUrlFilterOptions() {
            const urlFilter = document.getElementById('pageScreenshotsUrlFilter');
            if (!urlFilter) return;

            const options = new Map();
            this.screenshots.forEach((item) => {
                const key = String(item.grouped_url || item.description || '').trim();
                if (key) {
                    options.set(key, key);
                }
            });

            urlFilter.innerHTML = '<option value="">All</option>';
            Array.from(options.values()).sort().forEach((value) => {
                const option = document.createElement('option');
                option.value = value;
                option.textContent = value;
                urlFilter.appendChild(option);
            });
        }

        resetViewFilters() {
            const searchInput = document.getElementById('pageScreenshotsSearchInput');
            const urlFilter = document.getElementById('pageScreenshotsUrlFilter');
            const pageSizeSelect = document.getElementById('pageScreenshotsPageSize');

            if (searchInput) searchInput.value = '';
            if (urlFilter) urlFilter.value = '';
            if (pageSizeSelect) pageSizeSelect.value = '10';

            this.pageSize = 10;
            this.currentPage = 1;
            this.filteredScreenshots = Array.isArray(this.screenshots) ? [...this.screenshots] : [];
            this.updatePaginationControls();
        }

        applyScreenshotFilters() {
            const searchInput = document.getElementById('pageScreenshotsSearchInput');
            const urlFilter = document.getElementById('pageScreenshotsUrlFilter');
            const searchValue = String(searchInput?.value || '').trim().toLowerCase();
            const urlFilterValue = String(urlFilter?.value || '').trim();

            this.filteredScreenshots = this.screenshots.filter((item) => {
                const haystack = [
                    item.original_filename,
                    item.grouped_url,
                    item.description,
                    item.full_name,
                    item.created_at
                ].join(' ').toLowerCase();

                const matchesSearch = !searchValue || haystack.includes(searchValue);
                const filterTarget = String(item.grouped_url || item.description || '').trim();
                const matchesUrl = !urlFilterValue || filterTarget === urlFilterValue;
                return matchesSearch && matchesUrl;
            });

            this.renderScreenshotsTable();
        }

        getTotalPages() {
            return Math.max(1, Math.ceil(this.filteredScreenshots.length / this.pageSize));
        }

        renderScreenshotsTable() {
            const totalPages = this.getTotalPages();
            if (this.currentPage > totalPages) {
                this.currentPage = totalPages;
            }

            const startIndex = (this.currentPage - 1) * this.pageSize;
            const visibleScreenshots = this.filteredScreenshots.slice(startIndex, startIndex + this.pageSize);
            this.displayScreenshotsTable(visibleScreenshots, startIndex);
            this.updatePaginationControls();
        }

        updatePaginationControls() {
            const total = this.filteredScreenshots.length;
            const totalPages = this.getTotalPages();
            const info = document.getElementById('pageScreenshotsPaginationInfo');
            const prevBtn = document.getElementById('pageScreenshotsPrevBtn');
            const nextBtn = document.getElementById('pageScreenshotsNextBtn');

            const start = total === 0 ? 0 : ((this.currentPage - 1) * this.pageSize) + 1;
            const end = total === 0 ? 0 : Math.min(this.currentPage * this.pageSize, total);

            if (info) {
                info.textContent = `Showing ${start}-${end} of ${total} screenshots`;
            }

            if (prevBtn) prevBtn.disabled = this.currentPage <= 1;
            if (nextBtn) nextBtn.disabled = this.currentPage >= totalPages;
        }

        displayScreenshotsTable(screenshots, startIndex = 0) {
            const tableBody = document.getElementById('pageScreenshotsTableBody');
            if (!tableBody) return;

            if (screenshots.length === 0) {
                tableBody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">No screenshots uploaded yet.</td></tr>';
                return;
            }

            let html = '';
            screenshots.forEach((ss, index) => {
                const imageUrl = this.resolveImageUrl(ss);
                const createdAt = ss.created_at ? new Date(ss.created_at).toLocaleString() : '-';
                html += `
                    <tr class="screenshot-item" data-screenshot-id="${ss.id}">
                        <td>${startIndex + index + 1}</td>
                        <td>
                            <img src="${imageUrl}" class="img-thumbnail" style="width: 140px; height: 88px; object-fit: cover;" alt="${ss.original_filename}">
                            <div class="small text-muted mt-1 text-break">${ss.original_filename}</div>
                        </td>
                        <td>
                            ${ss.grouped_url ? `<div><a href="${ss.grouped_url}" target="_blank" class="link-primary">${this.shortenUrl(ss.grouped_url, 70)}</a></div>` : '<div>-</div>'}
                        </td>
                        <td>
                            ${ss.description ? `<div class="text-break">${ss.description}</div>` : '<div>-</div>'}
                        </td>
                        <td>
                            <div>${createdAt}</div>
                            <div class="small text-muted">${ss.full_name || ''}</div>
                        </td>
                        <td>
                            <div class="d-flex gap-2 flex-wrap">
                                <button class="btn btn-sm btn-outline-primary btn-view-screenshot" data-screenshot-id="${ss.id}">
                                    <i class="fas fa-eye me-1"></i>View
                                </button>
                                <button class="btn btn-sm btn-outline-danger btn-delete-screenshot" data-screenshot-id="${ss.id}">
                                    <i class="fas fa-trash me-1"></i>Delete
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            });
            tableBody.innerHTML = html;
        }

        shortenUrl(url, maxLength = 40) {
            if (url.length <= maxLength) return url;
            return url.substring(0, maxLength - 3) + '...';
        }

        resetUploadForm() {
            const form = document.getElementById('screenshotUploadForm');
            if (form) {
                form.reset();
                const fileInput = document.getElementById('screenshotFileInput');
                if (fileInput) fileInput.value = '';
            }
            this.setStatus('');
        }

        async uploadScreenshots(e) {
            e.preventDefault();
            const form = e.target;
            const issueId = form.dataset.issueId;
            const pageId = form.dataset.pageId;
            const fileInput = document.getElementById('screenshotFileInput');
            const files = fileInput ? fileInput.files : [];
            const groupedUrlSelect = document.getElementById('screenshotGroupedUrlSelect');
            const groupedUrlId = groupedUrlSelect ? groupedUrlSelect.value : '';
            const selectedUrlText = groupedUrlSelect && groupedUrlSelect.selectedIndex >= 0
                ? groupedUrlSelect.options[groupedUrlSelect.selectedIndex].text.trim()
                : '';
            const descriptionInput = document.getElementById('screenshotDescription');
            const description = descriptionInput ? descriptionInput.value : '';

            if (!files || files.length === 0) {
                alert('Please select at least one screenshot file');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'upload');
            formData.append('issue_id', issueId || 0);
            formData.append('page_id', pageId);
            formData.append('grouped_url_id', /^\d+$/.test(String(groupedUrlId)) ? groupedUrlId : 0);
            if (selectedUrlText && !selectedUrlText.startsWith('-- Select URL')) {
                formData.append('selected_url_text', selectedUrlText);
            }
            formData.append('description', description);

            for (let i = 0; i < files.length; i++) {
                formData.append('screenshots[]', files[i]);
            }

            const uploadBtn = form.querySelector('[type="submit"]');
            const originalText = uploadBtn ? uploadBtn.innerHTML : 'Upload';
            if (uploadBtn) {
                uploadBtn.disabled = true;
                uploadBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Uploading... Please wait';
            }

            try {
                this.setStatus('Uploading screenshots... Processing on server.', 'info');
                const response = await fetch(this.apiUrl, {
                    method: 'POST',
                    body: formData
                });

                const raw = await response.text();
                let data = null;

                try {
                    data = raw ? JSON.parse(raw) : null;
                } catch (parseError) {
                    console.error('Server response was not valid JSON:', raw);
                    throw new Error('Server returned an invalid response.');
                }

                if (!response.ok) {
                    throw new Error(data?.message || `Upload failed with status ${response.status}`);
                }

                if (data.success) {
                    this.setStatus(data.message || 'Screenshots uploaded successfully', 'success');
                    this.showNotification('Screenshots uploaded successfully', 'success');
                    this.loadScreenshots(pageId);
                    this.resetUploadForm();
                } else {
                    const errorText = Array.isArray(data.errors) && data.errors.length
                        ? `${data.message}<br>${data.errors.map((item) => `- ${item}`).join('<br>')}`
                        : (data.message || 'Upload failed');
                    this.setStatus(errorText, 'danger');
                    this.showNotification(data.message || 'Upload failed', 'danger');
                }
            } catch (error) {
                console.error('Upload error:', error);
                const msg = error.message || 'Upload failed unknown error';
                this.setStatus(msg, 'danger');
                this.showNotification('Upload failed: ' + msg, 'danger');
            } finally {
                if (uploadBtn) {
                    uploadBtn.disabled = false;
                    uploadBtn.innerHTML = originalText;
                }
            }
        }

        deleteScreenshot(screenshotId) {
            const confirmBtn = document.getElementById('confirmDeleteScreenshotBtn');
            if (confirmBtn) {
                confirmBtn.disabled = true;
            }

            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('screenshot_id', screenshotId);

            fetch(this.apiUrl, {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    this.showNotification('Screenshot deleted successfully', 'success');
                    this.screenshots = this.screenshots.filter((item) => String(item.id) !== String(screenshotId));
                    this.applyScreenshotFilters();
                    this.updateCountBadge(this.screenshots.length);
                } else {
                    this.showNotification('Delete failed', 'danger');
                }
            })
            .catch(err => {
                console.error('Delete error:', err);
                this.showNotification('Delete failed', 'danger');
            })
            .finally(() => {
                const modalEl = document.getElementById('deleteScreenshotConfirmModal');
                if (modalEl && window.bootstrap?.Modal) {
                    bootstrap.Modal.getOrCreateInstance(modalEl).hide();
                }
                this.pendingDeleteScreenshotId = null;
                if (confirmBtn) {
                    confirmBtn.disabled = false;
                }
            });
        }

        promptDeleteScreenshot(screenshotId) {
            this.pendingDeleteScreenshotId = screenshotId;

            const modalEl = document.getElementById('deleteScreenshotConfirmModal');
            if (!modalEl || !window.bootstrap?.Modal) {
                this.deleteScreenshot(screenshotId);
                return;
            }

            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }

        viewScreenshot(screenshotId) {
            const item = document.querySelector(`[data-screenshot-id="${screenshotId}"]`);
            if (!item) return;

            const imgSrc = item.querySelector('img')?.src;

            if (imgSrc) {
                window.open(imgSrc, '_blank');
            }
        }

        showNotification(message, type = 'info') {
            const container = document.getElementById('pmsNotificationContainer');
            if (!container) return;

            const toastId = 'toast-' + Date.now();
            const toastHtml = `
                <div id="${toastId}" class="toast align-items-center text-white bg-${type === 'danger' ? 'danger' : (type === 'success' ? 'success' : 'primary')} border-0" role="alert" aria-live="assertive" aria-atomic="true">
                    <div class="d-flex">
                        <div class="toast-body">
                            ${message}
                        </div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                    </div>
                </div>
            `;
            
            container.insertAdjacentHTML('beforeend', toastHtml);
            const toastEl = document.getElementById(toastId);
            
            if (window.bootstrap?.Toast) {
                const toast = new bootstrap.Toast(toastEl, { delay: 5000 });
                toast.show();
                toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
            } else {
                toastEl.classList.add('show');
                setTimeout(() => toastEl.remove(), 5000);
            }
        }
    }

    // Initialize when DOM is ready
    document.addEventListener('DOMContentLoaded', () => {
        if (!window.issueScreenshotManager) {
            window.issueScreenshotManager = new IssueScreenshotManager({
                baseDir: window.ProjectConfig?.baseDir || '',
                projectId: window.ProjectConfig?.projectId || 0
            });
        }
    });

} // End of outer if guard
