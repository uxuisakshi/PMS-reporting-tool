const DEFAULT_UPLOAD_ENDPOINT = 'https://pms.athenaeumtransformation.com/api/extension_temp_upload.php';

let lastScan = null;

const elements = {
    uploadEndpoint: document.getElementById('uploadEndpoint'),
    uploadToken: document.getElementById('uploadToken'),
    enableUpload: document.getElementById('enableUpload'),
    runScanBtn: document.getElementById('runScanBtn'),
    downloadExcelBtn: document.getElementById('downloadExcelBtn'),
    totalFindings: document.getElementById('totalFindings'),
    criticalCount: document.getElementById('criticalCount'),
    seriousCount: document.getElementById('seriousCount'),
    moderateCount: document.getElementById('moderateCount'),
    minorCount: document.getElementById('minorCount'),
    pageMeta: document.getElementById('pageMeta'),
    uploadMeta: document.getElementById('uploadMeta'),
    statusMessage: document.getElementById('statusMessage'),
    findingsList: document.getElementById('findingsList')
};

document.addEventListener('DOMContentLoaded', async function () {
    await restoreState();
    bindEvents();
});

function bindEvents() {
    elements.runScanBtn.addEventListener('click', runCurrentTabScan);
    elements.downloadExcelBtn.addEventListener('click', downloadExcelReport);

    [elements.uploadEndpoint, elements.uploadToken, elements.enableUpload].forEach(function (node) {
        node.addEventListener('change', saveSettings);
        node.addEventListener('input', saveSettings);
    });
}

async function restoreState() {
    const stored = await chrome.storage.local.get(['extensionScannerSettings', 'extensionScannerLastScan']);
    const settings = stored.extensionScannerSettings || {};

    elements.uploadEndpoint.value = settings.uploadEndpoint || DEFAULT_UPLOAD_ENDPOINT;
    elements.uploadToken.value = settings.uploadToken || '';
    elements.enableUpload.checked = Boolean(settings.enableUpload);

    if (stored.extensionScannerLastScan) {
        lastScan = stored.extensionScannerLastScan;
        renderScan(lastScan);
        setStatus('Restored last scan.', 'idle');
    }
}

async function saveSettings() {
    await chrome.storage.local.set({
        extensionScannerSettings: {
            uploadEndpoint: elements.uploadEndpoint.value.trim(),
            uploadToken: elements.uploadToken.value,
            enableUpload: elements.enableUpload.checked
        }
    });
}

function setBusy(isBusy) {
    elements.runScanBtn.disabled = isBusy;
    elements.downloadExcelBtn.disabled = isBusy || !lastScan;
}

function setStatus(message, type) {
    elements.statusMessage.textContent = message;
    elements.statusMessage.className = 'status ' + type;
}

async function runCurrentTabScan() {
    setBusy(true);
    setStatus('Running accessibility scan on current tab...', 'working');
    elements.uploadMeta.textContent = '';

    try {
        const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
        if (!tab || !tab.id) {
            throw new Error('Active tab not found');
        }
        if (!/^https?:/i.test(tab.url || '')) {
            throw new Error('Only http/https pages can be scanned');
        }

        await chrome.scripting.executeScript({
            target: { tabId: tab.id },
            files: ['vendor/axe.min.js']
        });

        const scanResults = await chrome.scripting.executeScript({
            target: { tabId: tab.id },
            func: async function () {
                if (!window.axe) {
                    throw new Error('axe-core failed to load');
                }

                const result = await window.axe.run(document, {
                    resultTypes: ['violations']
                });

                return {
                    pageTitle: document.title || 'Untitled Page',
                    pageUrl: window.location.href,
                    htmlLang: document.documentElement.lang || '',
                    violations: result.violations.map(function (violation) {
                        return {
                            id: violation.id,
                            impact: violation.impact || 'unknown',
                            help: violation.help || '',
                            description: violation.description || '',
                            helpUrl: violation.helpUrl || '',
                            tags: Array.isArray(violation.tags) ? violation.tags : [],
                            nodes: Array.isArray(violation.nodes) ? violation.nodes.map(function (node) {
                                return {
                                    target: Array.isArray(node.target) ? node.target.join(' | ') : '',
                                    html: node.html || '',
                                    failureSummary: node.failureSummary || ''
                                };
                            }) : []
                        };
                    })
                };
            }
        });

        const screenshotDataUrl = await chrome.tabs.captureVisibleTab(tab.windowId, { format: 'png' });
        const normalized = normalizeScan(scanResults[0].result, screenshotDataUrl);

        if (elements.enableUpload.checked && elements.uploadEndpoint.value.trim()) {
            const uploadResponse = await uploadScreenshot(normalized);
            normalized.upload = uploadResponse;
            elements.uploadMeta.textContent = 'Uploaded to ' + uploadResponse.screenshot_relative_path;
        }

        lastScan = normalized;
        await chrome.storage.local.set({ extensionScannerLastScan: normalized });
        renderScan(normalized);
        setStatus('Scan completed successfully.', 'success');
    } catch (error) {
        console.error(error);
        setStatus(error.message || 'Scan failed', 'error');
    } finally {
        setBusy(false);
    }
}

function normalizeScan(result, screenshotDataUrl) {
    const findings = [];
    const counts = {
        critical: 0,
        serious: 0,
        moderate: 0,
        minor: 0,
        unknown: 0
    };

    (result.violations || []).forEach(function (violation) {
        const impact = String(violation.impact || 'unknown').toLowerCase();
        if (Object.prototype.hasOwnProperty.call(counts, impact)) {
            counts[impact] += violation.nodes.length || 1;
        } else {
            counts.unknown += violation.nodes.length || 1;
        }

        (violation.nodes || []).forEach(function (node, index) {
            findings.push({
                rowId: violation.id + '-' + index,
                ruleId: violation.id,
                impact: impact,
                help: violation.help,
                description: violation.description,
                helpUrl: violation.helpUrl,
                tags: (violation.tags || []).join(', '),
                target: node.target || '',
                html: node.html || '',
                failureSummary: node.failureSummary || ''
            });
        });
    });

    return {
        scanId: buildScanId(),
        scannedAt: new Date().toISOString(),
        pageTitle: result.pageTitle,
        pageUrl: result.pageUrl,
        htmlLang: result.htmlLang,
        counts: counts,
        totalFindings: findings.length,
        findings: findings,
        screenshotDataUrl: screenshotDataUrl
    };
}

function buildScanId() {
    return 'scan_' + new Date().toISOString().replace(/[-:.TZ]/g, '').slice(0, 14) + '_' + Math.random().toString(36).slice(2, 8);
}

function renderScan(scan) {
    elements.totalFindings.textContent = String(scan.totalFindings || 0);
    elements.criticalCount.textContent = String(scan.counts.critical || 0);
    elements.seriousCount.textContent = String(scan.counts.serious || 0);
    elements.moderateCount.textContent = String(scan.counts.moderate || 0);
    elements.minorCount.textContent = String(scan.counts.minor || 0);
    elements.pageMeta.textContent = scan.pageTitle + ' | ' + scan.pageUrl;
    elements.downloadExcelBtn.disabled = false;

    if (!scan.findings || scan.findings.length === 0) {
        elements.findingsList.innerHTML = '<p class="empty">No accessibility violations found on this page.</p>';
        return;
    }

    elements.findingsList.innerHTML = scan.findings.map(function (finding) {
        var impact = finding.impact || 'unknown';
        return ''
            + '<article class="finding">'
            + '  <div class="finding-top">'
            + '      <h3>' + escapeHtml(finding.ruleId + ' - ' + finding.help) + '</h3>'
            + '      <span class="badge impact-' + escapeHtml(impact) + '">' + escapeHtml(impact) + '</span>'
            + '  </div>'
            + '  <p>' + escapeHtml(finding.failureSummary || finding.description || 'No summary available') + '</p>'
            + '  <code>' + escapeHtml(finding.target || 'No target selector') + '</code>'
            + '</article>';
    }).join('');
}

async function uploadScreenshot(scan) {
    const endpoint = elements.uploadEndpoint.value.trim();
    if (!endpoint) {
        throw new Error('Upload endpoint is required when upload is enabled');
    }

    const blob = await (await fetch(scan.screenshotDataUrl)).blob();
    const formData = new FormData();
    formData.append('scan_id', scan.scanId);
    formData.append('page_title', scan.pageTitle || 'Untitled Page');
    formData.append('page_url', scan.pageUrl || '');
    formData.append('finding_count', String(scan.totalFindings || 0));
    formData.append('impact_summary', JSON.stringify(scan.counts || {}));
    formData.append('findings_json', JSON.stringify(scan.findings || []));
    formData.append('screenshot', blob, 'viewport.png');

    const headers = {};
    if (elements.uploadToken.value) {
        headers['X-Extension-Token'] = elements.uploadToken.value;
    }

    const response = await fetch(endpoint, {
        method: 'POST',
        headers: headers,
        body: formData
    });

    const data = await response.json().catch(function () { return null; });
    if (!response.ok || !data || !data.success) {
        throw new Error((data && data.message) || 'Screenshot upload failed');
    }
    return data;
}

function downloadExcelReport() {
    if (!lastScan) {
        return;
    }

    const wb = XLSX.utils.book_new();
    const summaryRows = [
        ['Scan ID', lastScan.scanId],
        ['Scanned At', lastScan.scannedAt],
        ['Page Title', lastScan.pageTitle],
        ['Page URL', lastScan.pageUrl],
        ['HTML Lang', lastScan.htmlLang],
        ['Total Findings', lastScan.totalFindings],
        ['Critical', lastScan.counts.critical || 0],
        ['Serious', lastScan.counts.serious || 0],
        ['Moderate', lastScan.counts.moderate || 0],
        ['Minor', lastScan.counts.minor || 0],
        ['Unknown', lastScan.counts.unknown || 0],
        ['Uploaded Screenshot Path', lastScan.upload ? lastScan.upload.screenshot_relative_path : ''],
        ['Uploaded Metadata Path', lastScan.upload ? lastScan.upload.metadata_relative_path : '']
    ];

    const findingRows = [[
        'Rule ID',
        'Impact',
        'Help',
        'Description',
        'Target',
        'Failure Summary',
        'Help URL',
        'Tags'
    ]];

    (lastScan.findings || []).forEach(function (finding) {
        findingRows.push([
            finding.ruleId,
            finding.impact,
            finding.help,
            finding.description,
            finding.target,
            finding.failureSummary,
            finding.helpUrl,
            finding.tags
        ]);
    });

    XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(summaryRows), 'Summary');
    XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(findingRows), 'Findings');

    const safeTitle = String(lastScan.pageTitle || 'scan')
        .replace(/[\\/:*?"<>|]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim()
        .slice(0, 80) || 'scan';

    XLSX.writeFile(wb, safeTitle + ' - Accessibility Scan.xlsx');
}

function escapeHtml(text) {
    return String(text == null ? '' : text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}