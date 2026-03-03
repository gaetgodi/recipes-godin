function updateSelectedCount() {
    const checked = document.querySelectorAll('.recipe-checkbox:checked').length;
    document.getElementById('selectedCount').textContent = checked;
    
    // Enable/disable buttons (both top and bottom sets)
    const viewBtn = document.getElementById('viewBtn');
    const viewBtnTop = document.getElementById('viewBtnTop');
    const printBtn = document.getElementById('printBtn');
    const printBtnTop = document.getElementById('printBtnTop');
    const copyToMyBtn = document.getElementById('copyToMyBtn');
    const copyToMyBtnTop = document.getElementById('copyToMyBtnTop');
    const copyBtn = document.getElementById('copyBtn');
    const copyBtnTop = document.getElementById('copyBtnTop');
    const shareBtn = document.getElementById('shareBtn');
    const shareBtnTop = document.getElementById('shareBtnTop');
    const deleteBtn = document.getElementById('deleteBtn');
    const deleteBtnTop = document.getElementById('deleteBtnTop');
    
    // View buttons
    if (viewBtn) viewBtn.disabled = (checked === 0);
    if (viewBtnTop) viewBtnTop.disabled = (checked === 0);
    
    // Print buttons
    if (printBtn) printBtn.disabled = (checked === 0);
    if (printBtnTop) printBtnTop.disabled = (checked === 0);
    
    // Copy to My buttons
    if (copyToMyBtn) copyToMyBtn.disabled = (checked === 0);
    if (copyToMyBtnTop) copyToMyBtnTop.disabled = (checked === 0);
    
    // Copy buttons
    if (copyBtn) copyBtn.disabled = (checked === 0);
    if (copyBtnTop) copyBtnTop.disabled = (checked === 0);
    
    // Share buttons
    if (shareBtn) shareBtn.disabled = (checked === 0);
    if (shareBtnTop) shareBtnTop.disabled = (checked === 0);
    
    // Delete buttons
    if (deleteBtn) deleteBtn.disabled = (checked === 0);
    if (deleteBtnTop) deleteBtnTop.disabled = (checked === 0);
}

function toggleSelectAll() {
    const selectAll = document.getElementById('selectAll');
    const allRows = document.querySelectorAll('.recipe-manager-table tbody tr');
    
    allRows.forEach(row => {
        const checkbox = row.querySelector('.recipe-checkbox');
        if (!checkbox) return;
        
        // Skip if row is hidden
        if (row.style.display === 'none') {
            checkbox.checked = false;
        } else {
            checkbox.checked = selectAll.checked;
        }
    });
    
    updateSelectedCount();
}

function toggleCategoryDropdown() {
    const dropdown = document.getElementById('categoryDropdown');
    dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
}

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    const dropdown = document.getElementById('categoryDropdown');
    const button = document.getElementById('categoryFilterBtn');
    
    if (dropdown && button && !dropdown.contains(event.target) && !button.contains(event.target)) {
        dropdown.style.display = 'none';
    }
});

function updateFilterButton() {
    const checkboxes = document.querySelectorAll('input[name="category_filters[]"]:checked');
    const button = document.getElementById('filterBtnText');
    
    if (checkboxes.length === 0) {
        button.textContent = 'Select Categories';
    } else if (checkboxes.length === 1) {
        button.textContent = '1 category selected';
    } else {
        button.textContent = checkboxes.length + ' categories selected';
    }
}

function applyFiltersInstantly() {
    updateFilterButton();
    
    const checkboxes = document.querySelectorAll('input[name="category_filters[]"]:checked');
    const selectedCats = Array.from(checkboxes).map(cb => cb.value);
    
    const baseUrl = window.location.pathname;
    const urlParams = new URLSearchParams(window.location.search);
    const collection = urlParams.get('collection');
    
    let newUrl = baseUrl;
    const params = [];
    
    if (collection) {
        params.push('collection=' + collection);
    }
    
    if (selectedCats.length > 0) {
        params.push('recipe_cat=' + selectedCats.join(','));
    }
    
    if (params.length > 0) {
        newUrl += '?' + params.join('&');
    }
    
    window.location.href = newUrl;
}

function clearFilters() {
    const baseUrl = window.location.pathname;
    const urlParams = new URLSearchParams(window.location.search);
    const collection = urlParams.get('collection');
    
    let newUrl = baseUrl;
    if (collection) {
        newUrl += '?collection=' + collection;
    }
    
    window.location.href = newUrl;
}

function searchRecipes() {
    const searchInput = document.getElementById('recipeSearch');
    const searchTerm = searchInput.value.toLowerCase().trim();
    const tableRows = document.querySelectorAll('.recipe-manager-table tbody tr');
    let visibleCount = 0;
    
    tableRows.forEach(row => {
        // Skip the "no recipes" message row
        if (row.cells.length < 4) return;
        
        const titleCell = row.cells[2];
        const titleLink = titleCell.querySelector('.recipe-title-link');
        
        if (titleLink) {
            const title = titleLink.textContent.toLowerCase();
            const checkbox = row.querySelector('.recipe-checkbox');
            
            if (title.includes(searchTerm)) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
                if (checkbox) checkbox.checked = false;
            }
        }
    });
    
    updateRecipeCount(visibleCount);
    updateSelectedCount();
    
    // Uncheck "Select All" when search changes
    const selectAll = document.getElementById('selectAll');
    if (selectAll) selectAll.checked = false;
}

function clearSearch() {
    const searchInput = document.getElementById('recipeSearch');
    searchInput.value = '';
    searchRecipes();
}

function updateRecipeCount(count) {
    const countElement = document.querySelector('.recipe-count strong');
    if (countElement) {
        countElement.textContent = count;
    }
}

// Disable hidden checkboxes before form submission
function cleanupHiddenCheckboxes(event) {
    const allCheckboxes = document.querySelectorAll('.recipe-checkbox');
    
    allCheckboxes.forEach(checkbox => {
        const row = checkbox.closest('tr');
        if (row && row.style.display === 'none') {
            checkbox.disabled = true;
        }
    });
}