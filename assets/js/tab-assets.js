/* Tab Assets JS - extracted from modules/projects/partials/tab_assets.php */
(function() {
    function showEditFields(assetType) {
        var link = document.getElementById('edit_asset_link_fields');
        var text = document.getElementById('edit_asset_text_fields');
        var file = document.getElementById('edit_asset_file_fields');
        if (!link || !text || !file) return;
        link.style.display = assetType === 'link' ? '' : 'none';
        text.style.display = assetType === 'text' ? '' : 'none';
        file.style.display = assetType === 'file' ? '' : 'none';
    }

    document.querySelectorAll('.js-edit-asset').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var assetType = this.getAttribute('data-asset-type') || '';
            document.getElementById('edit_asset_id').value = this.getAttribute('data-asset-id') || '';
            document.getElementById('edit_asset_project_id').value = this.getAttribute('data-project-id') || '';
            document.getElementById('edit_asset_name').value = this.getAttribute('data-asset-name') || '';
            document.getElementById('edit_asset_type').value = assetType;
            document.getElementById('edit_asset_type_text').value = assetType;
            document.getElementById('edit_main_url').value = this.getAttribute('data-main-url') || '';
            document.getElementById('edit_link_type').value = this.getAttribute('data-link-type') || '';
            document.getElementById('edit_text_category').value = this.getAttribute('data-link-type') || '';
            document.getElementById('edit_text_content').value = this.getAttribute('data-text-content') || this.getAttribute('data-description') || '';
            showEditFields(assetType);
        });
    });
})();
