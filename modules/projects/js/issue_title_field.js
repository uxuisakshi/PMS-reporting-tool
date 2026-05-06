// issue_title_field.js
// Handles issue title input for Final Issue Modal with Apply Preset button

(function() {
    // Add once: visible keyboard focus style for highlighted suggestion
    if (!document.getElementById('issueTitleSuggestionA11yStyle')) {
        const style = document.createElement('style');
        style.id = 'issueTitleSuggestionA11yStyle';
        style.textContent = '.issue-title-suggestion-active{outline:2px solid #0d6efd;outline-offset:-2px;background:#e7f1ff;color:#0a58ca;font-weight:600;}';
        document.head.appendChild(style);
    }

    function getApiUrl() {
        const base = (window.ProjectConfig && window.ProjectConfig.baseDir) ? window.ProjectConfig.baseDir : '';
        return base + '/api/issue_titles.php';
    }

    // Inject input field
    function injectIssueTitleField(defaultValue) {
        const wrap = document.getElementById('customIssueTitleWrap');
        if (!wrap) {
            return;
        }
        
        // Check if field already exists - don't re-inject to prevent reset
        const existingInput = document.getElementById('customIssueTitle');
        if (existingInput) {
            // Only update the value if explicitly provided (not undefined or null)
            // Allow empty string to clear the field if explicitly passed
            if (defaultValue !== undefined && defaultValue !== null) {
                existingInput.value = defaultValue;
            }
            return;
        }
        
        wrap.innerHTML = '';
        const label = document.createElement('label');
        label.className = 'form-label fw-bold mb-1';
        label.textContent = 'Issue Title';
        
        // Create input with button wrapper
        const inputGroup = document.createElement('div');
        inputGroup.className = 'input-group';
        
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'form-control form-control-lg';
        input.id = 'customIssueTitle';
        input.placeholder = 'Type or search issue title...';
        input.autocomplete = 'off';
        input.value = defaultValue || '';
        input.setAttribute('aria-controls', 'issueTitleSuggestions');
        input.setAttribute('aria-autocomplete', 'list');
        
        // Apply Preset button
        const applyBtn = document.createElement('button');
        applyBtn.type = 'button';
        applyBtn.className = 'btn btn-outline-primary';
        applyBtn.id = 'applyPresetBtn';
        applyBtn.innerHTML = '<i class="fas fa-magic"></i> Apply Preset';
        applyBtn.title = 'Load preset data for selected title';
        applyBtn.style.display = 'none'; // Hidden by default
        
        // Suggestion dropdown
        const dropdown = document.createElement('div');
        dropdown.className = 'w-100 border bg-white rounded shadow-sm';
        dropdown.style.position = 'absolute';
        dropdown.style.zIndex = 10610;
        dropdown.style.maxHeight = '220px';
        dropdown.style.overflowY = 'auto';
        dropdown.style.display = 'none';
        dropdown.setAttribute('role', 'listbox');
        dropdown.id = 'issueTitleSuggestions';
        
        inputGroup.appendChild(input);
        inputGroup.appendChild(applyBtn);
        
        wrap.appendChild(label);
        wrap.appendChild(inputGroup);
        wrap.appendChild(dropdown);
        
        // Suggestion logic
        let timer = null;
        let isMouseOverDropdown = false;
        let selectedPresetTitle = null;
        let suggestionItems = [];
        let highlightedIndex = -1;
        
        // Apply Preset button click handler
        applyBtn.addEventListener('click', function() {
            const title = input.value.trim();
            if (title) {
                loadPresetData(title);
                applyBtn.style.display = 'none';
                if (window.showToast) {
                    showToast('Preset applied: ' + title, 'success');
                }
            }
        });
        
        // Show/hide Apply button based on input
        input.addEventListener('input', function() {
            // Hide apply button when typing
            applyBtn.style.display = 'none';
            selectedPresetTitle = null;
            
            clearTimeout(timer);
            const val = input.value.trim();
            if (val.length < 2) {
                if (val.length === 0) {
                    fetchAllPresets();
                } else {
                    dropdown.style.display = 'none';
                }
                return;
            }
            timer = setTimeout(() => fetchSuggestions(val), 250);
        });
        
        input.addEventListener('focus', function() {
            const val = input.value.trim();
            // Show suggestions on focus if there's text, or fetch all presets if empty
            if (val.length >= 2) {
                fetchSuggestions(val);
            } else {
                // Fetch all presets when focusing on empty field
                fetchAllPresets();
            }
        });
        
        input.addEventListener('blur', function() {
            // Only hide if mouse is not over dropdown
            setTimeout(() => { 
                if (!isMouseOverDropdown) {
                    dropdown.style.display = 'none'; 
                }
            }, 200);
        });

        function handleSuggestionNavigation(e) {
            if (suggestionItems.length === 0 || dropdown.style.display === 'none') return;

            const key = e.key;
            const code = e.keyCode || e.which;

            if (key === 'ArrowDown' || code === 40) {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                setHighlightedIndex((highlightedIndex + 1) % suggestionItems.length);
                input.focus();
            } else if (key === 'ArrowUp' || code === 38) {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                setHighlightedIndex((highlightedIndex - 1 + suggestionItems.length) % suggestionItems.length);
                input.focus();
            } else if (key === 'Enter' || code === 13) {
                if (highlightedIndex >= 0 && highlightedIndex < suggestionItems.length) {
                    e.preventDefault();
                    e.stopPropagation();
                    e.stopImmediatePropagation();
                    chooseSuggestion(suggestionItems[highlightedIndex]);
                }
            } else if (key === 'Tab' || code === 9) {
                resetSuggestions();
            } else if (key === 'Escape' || code === 27) {
                e.stopPropagation();
                e.stopImmediatePropagation();
                resetSuggestions();
            }
        }

        input.addEventListener('keydown', handleSuggestionNavigation, true);
        
        // Track mouse over dropdown to prevent blur from hiding it
        dropdown.addEventListener('mouseenter', function() {
            isMouseOverDropdown = true;
        });
        dropdown.addEventListener('mouseleave', function() {
            isMouseOverDropdown = false;
        });
        
        function fetchAllPresets() {
            const apiUrl = getApiUrl();
            if (!apiUrl || apiUrl.includes('undefined')) {
                return;
            }
            const projectType = (window.ProjectConfig && window.ProjectConfig.projectType) ? window.ProjectConfig.projectType : 'web';
            // Fetch presets with empty query to get all
            fetch(apiUrl + '?q=&project_type=' + encodeURIComponent(projectType) + '&presets_only=1', { credentials: 'same-origin' })
                .then(res => res.json())
                .then(data => {
                    resetSuggestions();
                    if (data && Array.isArray(data.titles) && data.titles.length) {
                        data.titles.forEach((title, idx) => {
                            const item = document.createElement('div');
                            item.className = 'px-3 py-2 border-bottom issue-title-suggestion';
                            item.textContent = title;
                            item.setAttribute('role', 'option');
                            item.setAttribute('tabindex', '-1');
                            item.id = 'issueTitleSuggestion_' + idx;
                            item.style.cursor = 'pointer';
                            item.onmousedown = function(e) { e.preventDefault(); };
                            item.onclick = () => chooseSuggestion(item);
                            dropdown.appendChild(item);
                            suggestionItems.push(item);
                        });
                        setHighlightedIndex(0);
                        dropdown.style.display = 'block';
                    } else {
                        resetSuggestions();
                        }
                })
                .catch(err => {
                    });
        }
        
        function fetchSuggestions(query) {
            // Safety check: don't fetch if apiUrl is invalid
            const apiUrl = getApiUrl();
            if (!apiUrl || apiUrl.includes('undefined')) {
                dropdown.style.display = 'none';
                return;
            }
            const projectType = (window.ProjectConfig && window.ProjectConfig.projectType) ? window.ProjectConfig.projectType : 'web';
            fetch(apiUrl + '?q=' + encodeURIComponent(query) + '&project_type=' + encodeURIComponent(projectType), { credentials: 'same-origin' })
                .then(res => res.json())
                .then(data => {
                    resetSuggestions();
                    if (data && Array.isArray(data.titles) && data.titles.length) {
                        data.titles.forEach((title, idx) => {
                            const item = document.createElement('div');
                            item.className = 'px-3 py-2 border-bottom issue-title-suggestion';
                            item.textContent = title;
                            item.setAttribute('role', 'option');
                            item.setAttribute('tabindex', '-1');
                            item.id = 'issueTitleSuggestion_' + idx;
                            item.style.cursor = 'pointer';
                            item.onmousedown = function(e) { e.preventDefault(); };
                            item.onclick = () => chooseSuggestion(item);
                            dropdown.appendChild(item);
                            suggestionItems.push(item);
                        });
                        setHighlightedIndex(0);
                        dropdown.style.display = 'block';
                    } else if (query.trim().length >= 2) {
                        // Show "No results" only if query length >= 2
                        const item = document.createElement('div');
                        item.className = 'px-3 py-2 text-muted small italic';
                        item.textContent = 'No matching suggestions found';
                        dropdown.appendChild(item);
                        dropdown.style.display = 'block';
                    } else {
                        resetSuggestions();
                    }
                })
                .catch(err => { 
                    resetSuggestions();
                });
        }

        function chooseSuggestion(item) {
            const title = (item && item.textContent ? item.textContent : '').trim();
            if (!title) return;
            input.value = title;
            dropdown.style.display = 'none';
            selectedPresetTitle = title;
            applyBtn.style.display = 'block';
            resetSuggestions();
        }

        function setHighlightedIndex(index) {
            highlightedIndex = index;
            suggestionItems.forEach(function(it, idx) {
                const active = idx === highlightedIndex;
                it.classList.toggle('issue-title-suggestion-active', active);
                it.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            if (suggestionItems[highlightedIndex]) {
                input.setAttribute('aria-activedescendant', suggestionItems[highlightedIndex].id || '');
                suggestionItems[highlightedIndex].scrollIntoView({ block: 'nearest' });
            }
        }

        function resetSuggestions() {
            dropdown.innerHTML = '';
            dropdown.style.display = 'none';
            suggestionItems = [];
            highlightedIndex = -1;
            input.removeAttribute('aria-activedescendant');
        }

        function formatCodeBackticks(html) {
            if (!html || typeof html !== 'string') return html;
            // Matches text between backticks: `code` -> <code>code</code>
            // Uses global match to catch all instances in the preset
            return html.replace(/`([^`]+)`/g, '<code>$1</code>');
        }
        
        function loadPresetData(title) {
            // Fetch preset details and populate form
            const apiUrl = getApiUrl();
            if (!apiUrl || apiUrl.includes('undefined')) return;
            
            const projectType = (window.ProjectConfig && window.ProjectConfig.projectType) ? window.ProjectConfig.projectType : 'web';
            const presetApiUrl = apiUrl.replace('issue_titles.php', 'issue_presets.php');
            
            fetch(presetApiUrl + '?action=get_by_title&title=' + encodeURIComponent(title) + '&project_type=' + encodeURIComponent(projectType), { credentials: 'same-origin' })
                .then(res => res.json())
                .then(data => {
                    if (data && data.preset) {
                        // Populate description if available
                        if (data.preset.description_html) {
                            const descField = document.getElementById('finalIssueDetails');
                            if (descField) {
                                if (window.jQuery && jQuery.fn.summernote && jQuery(descField).summernote) {
                                    // Transform backticks to <code> before setting the content
                                    const formattedHtml = formatCodeBackticks(data.preset.description_html);
                                    jQuery(descField).summernote('code', formattedHtml);
                                } else {
                                    descField.value = data.preset.description_html;
                                }
                            }
                        }
                        
                        // Populate metadata fields if available
                        if (data.preset.metadata_json) {
                            let metadata = data.preset.metadata_json;
                            if (typeof metadata === 'string') {
                                try {
                                    metadata = JSON.parse(metadata);
                                } catch (e) {
                                    return;
                                }
                            }
                            
                            // Map common metadata fields
                            const fieldMap = {
                                'severity': 'severity',
                                'priority': 'priority',
                                'status': 'finalIssueStatus',
                                'wcag_sc': 'wcag_sc',
                                'wcag_level': 'wcag_level',
                                'gigw': 'gigw',
                                'is17802': 'is17802',
                                'users_affected': 'users_affected'
                            };
                            
                            Object.keys(metadata).forEach(key => {
                                const fieldId = fieldMap[key] || key;
                                let field = document.getElementById(fieldId);
                                
                                // Try with finalIssue prefix if not found
                                if (!field && !fieldId.startsWith('finalIssue')) {
                                    field = document.getElementById('finalIssue' + key.charAt(0).toUpperCase() + key.slice(1));
                                }

                                // Try dynamic metadata field id pattern used in modal
                                if (!field) {
                                    field = document.getElementById('finalIssueField_' + key);
                                }
                                
                                // Try looking in metadata container
                                if (!field) {
                                    field = document.querySelector('#finalIssueMetadataContainer [data-field="' + key + '"]');
                                }
                                
                                if (field) {
                                    const value = metadata[key];
                                    if (Array.isArray(value)) {
                                        // For multi-select fields
                                        if (window.jQuery && jQuery.fn.select2) {
                                            jQuery(field).val(value).trigger('change');
                                        } else {
                                            Array.from(field.options).forEach(opt => {
                                                opt.selected = value.includes(opt.value);
                                            });
                                        }
                                    } else {
                                        // For single value fields
                                        if (field.multiple) {
                                            const normalized = (value === null || value === undefined || value === '') ? [] : [String(value)];
                                            if (window.jQuery && jQuery.fn.select2) {
                                                jQuery(field).val(normalized).trigger('change');
                                            } else {
                                                Array.from(field.options).forEach(opt => {
                                                    opt.selected = normalized.includes(opt.value);
                                                });
                                            }
                                        } else {
                                            field.value = value;
                                            if (window.jQuery && jQuery.fn.select2) {
                                                jQuery(field).trigger('change');
                                            }
                                        }
                                    }
                                    }
                            });
                        }
                    }
                })
                .catch(err => {});
        }
    }

    // Expose for modal open
    window.injectIssueTitleField = injectIssueTitleField;

    // Note: Field injection is now handled by openFinalEditor() in view_issues.js
    // No need for modal shown event listener as it causes race conditions
})();
