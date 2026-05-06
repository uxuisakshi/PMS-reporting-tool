/* Admin Client Permissions JS - extracted from modules/admin/client_permissions.php */
document.getElementById('clientFilter').addEventListener('change', function() {
    var clientId = this.value;
    var checkboxes = document.querySelectorAll('.form-check[data-client-id]');
    checkboxes.forEach(function(checkbox) {
        if (clientId === '' || checkbox.dataset.clientId === clientId) {
            checkbox.style.display = 'block';
        } else {
            checkbox.style.display = 'none';
            checkbox.querySelector('input').checked = false;
        }
    });
    updateSelectedCount();
});

document.querySelectorAll('.project-checkbox').forEach(function(checkbox) {
    checkbox.addEventListener('change', updateSelectedCount);
});

function updateSelectedCount() {
    var count = document.querySelectorAll('.project-checkbox:checked').length;
    document.getElementById('selectedCount').textContent = count;
}

// Clear search functionality
function clearSearch() {
    var searchInput = document.querySelector('input[name="search"]');
    if (searchInput) {
        searchInput.value = '';
        searchInput.form.submit();
    }
}

// Auto-submit search form on Enter key
document.addEventListener('DOMContentLoaded', function() {
    var searchInput = document.querySelector('input[name="search"]');
    if (searchInput) {
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.form.submit();
            }
        });
        
        // Focus search input if there's a search term
        var urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('search')) {
            searchInput.focus();
            // Move cursor to end of input
            searchInput.setSelectionRange(searchInput.value.length, searchInput.value.length);
        }
    }
});

// Highlight search terms in results
document.addEventListener('DOMContentLoaded', function() {
    var urlParams = new URLSearchParams(window.location.search);
    var searchTerm = urlParams.get('search');
    
    if (searchTerm && searchTerm.trim()) {
        highlightSearchTerms(searchTerm.trim());
    }
});

function highlightSearchTerms(searchTerm) {
    var tableBody = document.querySelector('.table tbody');
    if (!tableBody) return;
    
    var regex = new RegExp('(' + escapeRegExp(searchTerm) + ')', 'gi');
    
    tableBody.querySelectorAll('td').forEach(function(cell) {
        // Skip action column
        if (cell.querySelector('form')) return;
        
        var textNodes = getTextNodes(cell);
        textNodes.forEach(function(node) {
            if (regex.test(node.textContent)) {
                var highlightedHTML = node.textContent.replace(regex, '<mark class="bg-warning">$1</mark>');
                var wrapper = document.createElement('span');
                wrapper.innerHTML = highlightedHTML;
                node.parentNode.replaceChild(wrapper, node);
            }
        });
    });
}

function escapeRegExp(string) {
    return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function getTextNodes(element) {
    var textNodes = [];
    var walker = document.createTreeWalker(
        element,
        NodeFilter.SHOW_TEXT,
        null,
        false
    );
    
    var node;
    while (node = walker.nextNode()) {
        if (node.textContent.trim()) {
            textNodes.push(node);
        }
    }
    
    return textNodes;
}
