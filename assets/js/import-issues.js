(function () {
    var cfg = window.IssueImportConfig || {};
    var sectionMappings = [];
    var metadataMappings = [];
    var BUILT_IN_ISSUE_FIELD_KEYS = {
        title: true,
        description: true,
        status: true,
        priority: true,
        severity: true,
        common_title: true,
        common_issue_title: true,
        pages: true,
        page_numbers: true,
        qa_status: true,
        grouped_urls: true
    };
    var BUILT_IN_ISSUE_FIELD_LABELS = {
        title: true,
        description: true,
        status: true,
        priority: true,
        severity: true,
        'common issue title': true,
        pages: true,
        'page numbers': true,
        'qa status': true,
        'grouped urls': true
    };
    var FIXED_SHEETS = {
        issues: 'Final Report',
        pages: 'URL details',
        allUrls: 'All URLs'
    };

    var baseDir = String(cfg.baseDir || '').trim();
    var projectType = String(cfg.projectType || 'web').trim() || 'web';
    if (!baseDir) return;

    var form = document.getElementById('issueImportForm');
    var btn = document.getElementById('issueImportBtn');
    var loadHeadersBtn = document.getElementById('loadHeadersBtn');
    var mappingSection = document.getElementById('mappingSection');
    var resultCard = document.getElementById('importResultCard');
    var resultBody = document.getElementById('importResultBody');
    var sectionContainer = document.getElementById('issueSectionMappingFields');
    var metadataContainer = document.getElementById('issueMetadataMappingFields');
    var sheetsData = [];

    if (!form || !btn || !loadHeadersBtn) return;

    function esc(v) {
        return String(v || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function setResult(html, isError) {
        if (!resultCard || !resultBody) return;
        resultCard.style.display = '';
        if (isError) {
            resultBody.innerHTML = '<div class="text-danger fw-semibold">Error</div><div>' + esc(html) + '</div>';
        } else {
            resultBody.innerHTML = html;
        }
    }

    function resetSelect(sel, allowNone) {
        if (!sel) return;
        sel.innerHTML = '';
        if (allowNone) {
            var opt = document.createElement('option');
            opt.value = '';
            opt.textContent = '-- None --';
            sel.appendChild(opt);
        }
    }

    function getSheetByName(name) {
        var target = String(name || '').toLowerCase();
        for (var i = 0; i < sheetsData.length; i++) {
            if (String(sheetsData[i].name || '').toLowerCase() === target) return sheetsData[i];
        }
        return null;
    }

    function fillColumnSelect(sel, headers, allowNone) {
        resetSelect(sel, allowNone);
        if (!sel) return;
        for (var i = 0; i < headers.length; i++) {
            var o = document.createElement('option');
            o.value = String(i);
            o.textContent = headers[i] ? headers[i] : ('Column ' + (i + 1));
            sel.appendChild(o);
        }
    }

    function slugify(value) {
        return String(value || '')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '');
    }

    function isBuiltInIssueField(fieldKey, fieldLabel) {
        var normalizedKey = slugify(fieldKey);
        var normalizedLabel = String(fieldLabel || '').trim().toLowerCase();
        return !!(BUILT_IN_ISSUE_FIELD_KEYS[normalizedKey] || BUILT_IN_ISSUE_FIELD_LABELS[normalizedLabel]);
    }

    function renderDynamicMappingFields() {
        if (sectionContainer) {
            sectionContainer.innerHTML = '';
            sectionMappings.forEach(function (sec) {
                var key = sec.key || slugify(sec.name);
                var wrap = document.createElement('div');
                wrap.className = 'col-md-6';
                wrap.innerHTML = '<label class="form-label">' + esc(sec.name) + '</label>' +
                    '<select id="mapIssueSection_' + esc(key) + '" class="form-select"></select>';
                sectionContainer.appendChild(wrap);
                sec.key = key;
            });
        }

        if (metadataContainer) {
            metadataContainer.innerHTML = '';
            metadataMappings.forEach(function (meta) {
                var wrap = document.createElement('div');
                wrap.className = 'col-md-6';
                wrap.innerHTML = '<label class="form-label">' + esc(meta.label) + ' (Metadata)</label>' +
                    '<select id="mapIssueMeta_' + esc(meta.key) + '" class="form-select"></select>';
                metadataContainer.appendChild(wrap);
            });
        }
    }

    function loadTemplateConfig() {
        return Promise.all([
            fetch(baseDir + '/api/issue_templates.php?action=list&project_type=' + encodeURIComponent(projectType), {
                credentials: 'same-origin'
            }).then(function (r) { return r.json(); }).catch(function () { return {}; }),
            fetch(baseDir + '/api/issue_templates.php?action=metadata_options&project_type=' + encodeURIComponent(projectType), {
                credentials: 'same-origin'
            }).then(function (r) { return r.json(); }).catch(function () { return {}; })
        ]).then(function (results) {
            var listRes = results[0] || {};
            var metaRes = results[1] || {};

            var sections = Array.isArray(listRes.default_sections) ? listRes.default_sections : [];
            if (!sections.length) {
                sections = ['Actual Result', 'Incorrect Code', 'Screenshot', 'Recommendation', 'Correct Code'];
            }
            sectionMappings = sections.map(function (name) {
                return {
                    name: String(name || '').trim(),
                    key: slugify(name)
                };
            }).filter(function (item) {
                return item.name && item.key;
            });

            var fields = Array.isArray(metaRes.fields) ? metaRes.fields : [];
            var seenMetadata = {};
            metadataMappings = fields.map(function (field) {
                return {
                    key: String(field.field_key || '').trim(),
                    label: String(field.field_label || '').trim()
                };
            }).filter(function (item) {
                if (!item.key || !item.label) {
                    return false;
                }

                if (isBuiltInIssueField(item.key, item.label)) {
                    return false;
                }

                var dedupeKey = slugify(item.key) + '|' + String(item.label || '').trim().toLowerCase();
                if (seenMetadata[dedupeKey]) {
                    return false;
                }
                seenMetadata[dedupeKey] = true;
                return true;
            });

            renderDynamicMappingFields();
        });
    }

    loadHeadersBtn.addEventListener('click', function () {
        var fileInput = document.getElementById('issueImportFile');
        if (!fileInput || !fileInput.files || !fileInput.files[0]) {
            setResult('Please select a file first.', true);
            return;
        }

        var fd = new FormData(form);
        fd.append('action', 'preview');

        loadHeadersBtn.disabled = true;
        loadHeadersBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Loading...';
        setResult('Reading workbook headers...', false);

        Promise.all([
            loadTemplateConfig(),
            fetch(baseDir + '/modules/projects/upload_issues.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        }).then(function (r) {
                if (!r.ok) throw new Error('Server returned ' + r.status);
                return r.json();
            })
        ])
            .then(function (results) {
                var json = results[1];
                if (!json || !json.success || !Array.isArray(json.sheets) || json.sheets.length === 0) {
                    throw new Error((json && (json.error || json.message)) ? (json.error || json.message) : 'Could not read sheet headers');
                }

                sheetsData = json.sheets;
                if (mappingSection) mappingSection.style.display = '';
                btn.disabled = false;

                var issuesHeaders = (getSheetByName(FIXED_SHEETS.issues) || {}).headers || [];
                var pagesHeaders = (getSheetByName(FIXED_SHEETS.pages) || {}).headers || [];
                var allUrlsHeaders = (getSheetByName(FIXED_SHEETS.allUrls) || {}).headers || [];

                fillColumnSelect(document.getElementById('mapIssueTitle'), issuesHeaders, false);
                fillColumnSelect(document.getElementById('mapIssueStatus'), issuesHeaders, true);
                fillColumnSelect(document.getElementById('mapIssuePriority'), issuesHeaders, true);
                fillColumnSelect(document.getElementById('mapIssueSeverity'), issuesHeaders, true);
                fillColumnSelect(document.getElementById('mapIssueCommonTitle'), issuesHeaders, true);
                sectionMappings.forEach(function (sec) {
                    fillColumnSelect(document.getElementById('mapIssueSection_' + sec.key), issuesHeaders, true);
                });
                metadataMappings.forEach(function (meta) {
                    fillColumnSelect(document.getElementById('mapIssueMeta_' + meta.key), issuesHeaders, true);
                });
                fillColumnSelect(document.getElementById('mapIssuePages'), issuesHeaders, true);
                fillColumnSelect(document.getElementById('mapIssuePageNumbers'), issuesHeaders, true);
                fillColumnSelect(document.getElementById('mapIssueQaStatus'), issuesHeaders, true);
                fillColumnSelect(document.getElementById('mapIssueGroupedUrls'), issuesHeaders, true);

                fillColumnSelect(document.getElementById('mapPageName'), pagesHeaders, true);
                fillColumnSelect(document.getElementById('mapUniqueUrl'), pagesHeaders, true);
                fillColumnSelect(document.getElementById('mapPagesGroupedUrls'), pagesHeaders, true);
                fillColumnSelect(document.getElementById('mapPageNumber'), pagesHeaders, true);
                fillColumnSelect(document.getElementById('mapScreenName'), pagesHeaders, true);
                fillColumnSelect(document.getElementById('mapNotes'), pagesHeaders, true);

                fillColumnSelect(document.getElementById('mapAllUrls'), allUrlsHeaders, true);

                var info = 'Headers loaded. ' +
                    'Final Report: ' + issuesHeaders.length + ' columns, ' +
                    'URL details: ' + pagesHeaders.length + ' columns, ' +
                    'All URLs: ' + allUrlsHeaders.length + ' columns.';
                setResult('<div class="text-success fw-semibold">' + esc(info) + '</div>', false);
            })
            .catch(function (err) {
                setResult((err && err.message) ? err.message : 'Failed to load headers', true);
            })
            .finally(function () {
                loadHeadersBtn.disabled = false;
                loadHeadersBtn.innerHTML = '<i class="fas fa-table me-1"></i> Load Sheet Headers';
            });
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        var fileInput = document.getElementById('issueImportFile');
        if (!fileInput || !fileInput.files || !fileInput.files[0]) {
            setResult('Please select a file.', true);
            return;
        }

        var issueTitleMap = document.getElementById('mapIssueTitle');
        if (!issueTitleMap || issueTitleMap.value === '') {
            setResult('Map the Title column for Final Report sheet.', true);
            return;
        }

        var pagesMap = {
            page_name: (document.getElementById('mapPageName') || {}).value || '',
            unique_url: (document.getElementById('mapUniqueUrl') || {}).value || '',
            grouped_urls: (document.getElementById('mapPagesGroupedUrls') || {}).value || '',
            page_number: (document.getElementById('mapPageNumber') || {}).value || '',
            screen_name: (document.getElementById('mapScreenName') || {}).value || '',
            notes: (document.getElementById('mapNotes') || {}).value || ''
        };

        var allUrlsMap = {
            url: (document.getElementById('mapAllUrls') || {}).value || ''
        };

        var issuesMap = {
            title: (document.getElementById('mapIssueTitle') || {}).value || '',
            status: (document.getElementById('mapIssueStatus') || {}).value || '',
            priority: (document.getElementById('mapIssuePriority') || {}).value || '',
            severity: (document.getElementById('mapIssueSeverity') || {}).value || '',
            common_title: (document.getElementById('mapIssueCommonTitle') || {}).value || '',
            pages: (document.getElementById('mapIssuePages') || {}).value || '',
            page_numbers: (document.getElementById('mapIssuePageNumbers') || {}).value || '',
            qa_status: (document.getElementById('mapIssueQaStatus') || {}).value || '',
            grouped_urls: (document.getElementById('mapIssueGroupedUrls') || {}).value || ''
        };

        var sectionsMap = {};
        sectionMappings.forEach(function (sec) {
            sectionsMap[sec.name] = (document.getElementById('mapIssueSection_' + sec.key) || {}).value || '';
        });
        issuesMap.sections_map = sectionsMap;

        var metadataMap = {};
        metadataMappings.forEach(function (meta) {
            metadataMap[meta.key] = (document.getElementById('mapIssueMeta_' + meta.key) || {}).value || '';
        });
        issuesMap.metadata_map = metadataMap;

        var fd = new FormData(form);
        fd.append('action', 'import');
        fd.append('issues_sheet', FIXED_SHEETS.issues);
        fd.append('pages_sheet', FIXED_SHEETS.pages);
        fd.append('all_urls_sheet', FIXED_SHEETS.allUrls);
        fd.append('issues_map', JSON.stringify(issuesMap));
        fd.append('pages_map', JSON.stringify(pagesMap));
        fd.append('all_urls_map', JSON.stringify(allUrlsMap));

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Importing...';
        setResult('Import in progress...', false);

        fetch(baseDir + '/modules/projects/upload_issues.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        })
            .then(function (r) {
                if (!r.ok) throw new Error('Server returned ' + r.status);
                return r.json();
            })
            .then(function (json) {
                if (json && json.success) {
                    var html = '';
                    html += '<div class="text-success fw-semibold mb-2">Import completed successfully.</div>';
                    html += '<div>Inserted: <strong>' + (json.inserted || 0) + '</strong></div>';
                    html += '<div>Skipped: <strong>' + (json.skipped || 0) + '</strong></div>';
                    html += '<div>Project pages added: <strong>' + (json.added_project_pages || 0) + '</strong></div>';
                    html += '<div>Unique pages added: <strong>' + (json.added_unique || 0) + '</strong></div>';
                    html += '<div>Grouped URLs added: <strong>' + (json.added_grouped || 0) + '</strong></div>';
                    if (json.errors && json.errors.length) {
                        html += '<hr><div class="text-danger fw-semibold mb-1">Row Errors</div><ul class="mb-0">';
                        json.errors.forEach(function (err) {
                            html += '<li>' + esc(err) + '</li>';
                        });
                        html += '</ul>';
                    }
                    setResult(html, false);
                } else {
                    setResult((json && (json.error || json.message)) ? (json.error || json.message) : 'Import failed', true);
                }
            })
            .catch(function (err) {
                setResult((err && err.message) ? err.message : 'Request error', true);
            })
            .finally(function () {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-upload me-1"></i> Import Issues';
            });
    });
})();
