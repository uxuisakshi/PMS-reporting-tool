/* Universal Header JS - extracted from includes/universal_header.php */
(function() {
    var universalHeaderInitialized = false;

    function initializeUniversalHeader() {
        if (universalHeaderInitialized) return;
        var navbar = document.querySelector('.pms-universal-header');
        if (!navbar) return;
        navbar.style.setProperty('background-color', '#0755C6', 'important');
        navbar.style.setProperty('background', '#0755C6', 'important');
        var brandElements = navbar.querySelectorAll('.pms-brand, .pms-brand-text, .pms-nav-link, .pms-user-name');
        brandElements.forEach(function(el) { el.style.setProperty('color', 'white', 'important'); });
        var icons = navbar.querySelectorAll('.pms-nav-link i');
        icons.forEach(function(icon) { icon.style.setProperty('color', 'white', 'important'); });
        universalHeaderInitialized = true;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeUniversalHeader);
    } else {
        initializeUniversalHeader();
    }

    window.addEventListener('load', function() {
        if (!universalHeaderInitialized) initializeUniversalHeader();
    });
})();
