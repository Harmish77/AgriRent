<?php
session_start();
require_once '../auth/config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'A') {
    header('Location: ../login.php');
    exit;
}

// Add new category
if (isset($_POST['add_category'])) {
    $name = $_POST['category_name'];
    $description = $_POST['description'];
    
    $stmt = $conn->prepare("INSERT INTO product_categories (Category_name, description) VALUES (?, ?)");
    $stmt->bind_param('ss', $name, $description);
    
    if ($stmt->execute()) {
        $message = "Product category added successfully";
    } else {
        $error = "Error adding category";
    }
}

// Edit category
if (isset($_POST['edit_category'])) {
    $id = intval($_POST['category_id']);
    $name = $_POST['category_name'];
    $description = $_POST['description'];
    
    $stmt = $conn->prepare("UPDATE product_categories SET Category_name = ?, description = ? WHERE Category_id = ?");
    $stmt->bind_param('ssi', $name, $description, $id);
    
    if ($stmt->execute()) {
        $message = "Product category updated successfully";
    } else {
        $error = "Error updating category";
    }
}

// Delete category
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    
    // Check if category has subcategories
    $check = $conn->query("SELECT COUNT(*) as count FROM product_subcategories WHERE Category_id = $id");
    if ($check->fetch_assoc()['count'] > 0) {
        $error = "Cannot delete category with subcategories";
    } else {
        $conn->query("DELETE FROM product_categories WHERE Category_id = $id");
        $message = "Product category deleted successfully";
    }
}

// Get category for editing
$edit_category = null;
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $edit_result = $conn->query("SELECT * FROM product_categories WHERE Category_id = $edit_id");
    $edit_category = $edit_result->fetch_assoc();
}

// Get all categories
$categories = $conn->query("SELECT * FROM product_categories ORDER BY Category_name");

require 'header.php';
require 'admin_nav.php';
?>

<div class="main-content">
    <h1>Product Categories</h1>
    
    <?php if (isset($message)): ?>
        <div class="message"><?= $message ?></div>
    <?php endif; ?>
    
    <?php if (isset($error)): ?>
        <div class="error"><?= $error ?></div>
    <?php endif; ?>

    <!-- Add/Edit Category Form -->
    <div class="form-box">
        <h3><?= isset($edit_category) ? 'Edit Product Category' : 'Add New Product Category' ?></h3>
        <form method="POST">
            <?php if (isset($edit_category)): ?>
                <input type="hidden" name="category_id" value="<?= $edit_category['Category_id'] ?>">
            <?php endif; ?>
            
            <div class="form-group">
                <label>Category Name:</label>
                <input type="text" name="category_name" value="<?= isset($edit_category) ? htmlspecialchars($edit_category['Category_name']) : '' ?>" required>
            </div>
            <div class="form-group">
                <label>Description:</label>
                <textarea name="description" rows="3"><?= isset($edit_category) ? htmlspecialchars($edit_category['description']) : '' ?></textarea>
            </div>
            
            <?php if (isset($edit_category)): ?>
                <button type="submit" name="edit_category" class="btn btn-update">Update Category</button>
                <a href="product_categories.php" class="btn btn-cancel">Cancel</a>
            <?php else: ?>
                <button type="submit" name="add_category" class="btn">Add Category</button>
            <?php endif; ?>
        </form>
    </div>

    <!-- Categories List -->
    <h3>All Product Categories</h3>
    <div class="search-box" id="searchContainer">
        <input type="text" id="categorySearch" placeholder="Search product categories...">
        <button type="button" id="clearSearch" class="btn">Clear</button>
    </div>

    <div id="tableWrapper">
        <table id="categoriesTable">
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Description</th>
                <th>Actions</th>
            </tr>
            
            <?php if ($categories->num_rows > 0): ?>
                <?php while($category = $categories->fetch_assoc()): ?>
                <tr class="category-row">
                    <td><?= $category['Category_id'] ?></td>
                    <td><?= htmlspecialchars($category['Category_name']) ?></td>
                    <td><?= htmlspecialchars($category['description']) ?></td>
                    <td class="actions-cell">
                        <a href="?edit=<?= $category['Category_id'] ?>" class="btn-edit" title="Edit Category">
                            <i class="fas fa-edit"></i> Edit
                        </a>
                        <a href="product_subcategories.php?category_id=<?= $category['Category_id'] ?>" class="btn-subcategory" title="Manage Subcategories">
                            <i class="fas fa-list"></i> Subcategories
                        </a>
                        <a href="?delete=<?= $category['Category_id'] ?>" class="btn-delete" onclick="return confirm('Delete this category?')" title="Delete Category">
                            <i class="fas fa-trash"></i> Delete
                        </a>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="4">No categories found</td>
                </tr>
            <?php endif; ?>
        </table>
    </div>
</div>

<style>
.category-row.hidden {
    display: none !important;
}

/* Enhanced Form Styling */
.form-box {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 30px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.form-box h3 {
    margin-top: 0;
    color: #234a23;
}

.form-group {
    margin-bottom: 15px;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 600;
    color: #333;
}

.form-group input,
.form-group textarea {
    width: 100%;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}

.form-group input:focus,
.form-group textarea:focus {
    outline: none;
    border-color: #234a23;
    box-shadow: 0 0 5px rgba(35, 74, 35, 0.3);
}

/* Button Styling */
.btn {
    display: inline-block;
    padding: 10px 20px;
    background: #234a23;
    color: white;
    text-decoration: none;
    border-radius: 4px;
    border: none;
    cursor: pointer;
    margin-right: 10px;
    font-size: 14px;
}

.btn:hover {
    background: #1e3e1e;
}

.btn-update {
    background: #28a745;
}

.btn-update:hover {
    background: #218838;
}

.btn-cancel {
    background: #6c757d;
}

.btn-cancel:hover {
    background: #5a6268;
}

/* Action buttons in table */
.actions-cell {
    white-space: nowrap;
}

.btn-edit,
.btn-subcategory,
.btn-delete {
    display: inline-block;
    padding: 6px;
    margin: 1px;
    text-decoration: none;
    border-radius: 4px;
    font-size: 14px;
    transition: all 0.3s ease;
}

.btn-edit {
   
    color: 234a23;
}

.btn-edit:hover {
    background: orange;
    color: white;
}

.btn-subcategory {
    
    color: 234a23;
}

.btn-subcategory:hover {
    background: #234a23;
    color: white;
}

.btn-delete {
  color: 234a23;
}

.btn-delete:hover {
    background: #c82333;
    color: white;
}

/* Table Styling */
#categoriesTable {
    width: 100%;
    border-collapse: collapse;
    background: white;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

#categoriesTable th,
#categoriesTable td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #ddd;
}

#categoriesTable th {
    background: #f8f9fa;
    font-weight: 600;
    color: #333;
}

#categoriesTable tr:hover {
    background: #f8f9fa;
}

/* Message Styling */
.message {
    background: #d4edda;
    color: #155724;
    padding: 12px 16px;
    border-radius: 4px;
    margin-bottom: 20px;
    border: 1px solid #c3e6cb;
}

.error {
    background: #f8d7da;
    color: #721c24;
    padding: 12px 16px;
    border-radius: 4px;
    margin-bottom: 20px;
    border: 1px solid #f5c6cb;
}

/* Search Box */
.search-box {
    margin-bottom: 20px;
}

#categorySearch {
    font-size: 14px;
    border: 1px solid #ccc;
    border-radius: 4px;
}

#categorySearch:focus {
    outline: none;
    border-color: #234a23;
    box-shadow: 0 0 5px rgba(35, 74, 35, 0.3);
}

/* Responsive Design */
@media (max-width: 768px) {
    .actions-cell {
        white-space: normal;
    }
    
    .btn-edit,
    .btn-subcategory,
    .btn-delete {
        display: block;
        margin: 2px 0;
        text-align: center;
    }
    
    #categorySearch {
        width: 100%;
        margin-bottom: 10px;
    }
}
</style>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script>
$(document).ready(function() {
    // JavaScript-only responsive design without CSS changes
    function makeResponsive() {
        const screenWidth = window.innerWidth;
        const searchInput = document.getElementById('categorySearch');
        const clearButton = document.getElementById('clearSearch');
        const searchContainer = document.getElementById('searchContainer');
        const tableWrapper = document.getElementById('tableWrapper');
        
        // Mobile responsive adjustments (768px and below)
        if (screenWidth <= 768) {
            // Make search input full width using JavaScript
            searchInput.style.width = 'calc(100% - 20px)';
            searchInput.style.padding = '12px 15px';
            searchInput.style.fontSize = '16px'; // Prevent iOS zoom
            searchInput.style.marginBottom = '10px';
            searchInput.style.marginRight = '0';
            searchInput.style.display = 'block';
            searchInput.style.boxSizing = 'border-box';
            
            // Make clear button full width
            clearButton.style.width = 'calc(100% - 20px)';
            clearButton.style.display = 'block';
            clearButton.style.marginLeft = '0';
            clearButton.style.marginBottom = '0';
            clearButton.style.boxSizing = 'border-box';
            
            // Stack search elements vertically
            searchContainer.style.display = 'flex';
            searchContainer.style.flexDirection = 'column';
            searchContainer.style.gap = '10px';
            
            // Make table horizontally scrollable
            tableWrapper.style.overflowX = 'auto';
            tableWrapper.style.webkitOverflowScrolling = 'touch';
            
            // Adjust action buttons for mobile
            const actionButtons = document.querySelectorAll('.btn-edit, .btn-subcategory, .btn-delete');
            actionButtons.forEach(button => {
                button.style.display = 'block';
                button.style.width = '100%';
                button.style.textAlign = 'center';
                button.style.margin = '2px 0';
                button.style.boxSizing = 'border-box';
            });
            
        } else {
            // Desktop layout - reset to original styles
            searchInput.style.width = '';
            searchInput.style.padding = '8px';
            searchInput.style.fontSize = '14px';
            searchInput.style.marginBottom = '0';
            searchInput.style.marginRight = '10px';
            searchInput.style.display = 'inline-block';
            searchInput.style.boxSizing = 'content-box';
            
            clearButton.style.width = '';
            clearButton.style.display = 'inline-block';
            clearButton.style.marginLeft = '5px';
            clearButton.style.boxSizing = 'content-box';
            
            // Reset search container
            searchContainer.style.display = 'block';
            searchContainer.style.flexDirection = '';
            searchContainer.style.gap = '';
            
            // Reset table wrapper
            tableWrapper.style.overflowX = 'visible';
            
            // Reset action buttons for desktop
            const actionButtons = document.querySelectorAll('.btn-edit, .btn-subcategory, .btn-delete');
            actionButtons.forEach(button => {
                button.style.display = 'inline-block';
                button.style.width = '';
                button.style.textAlign = '';
                button.style.margin = '1px';
            });
        }
        
        // Extra small mobile devices (480px and below)
        if (screenWidth <= 480) {
            searchInput.style.padding = '15px';
            clearButton.style.padding = '15px';
            
            // Make table even more compact
            const table = document.getElementById('categoriesTable');
            table.style.fontSize = '12px';
            
            const tableCells = table.querySelectorAll('th, td');
            tableCells.forEach(cell => {
                cell.style.padding = '8px 6px';
            });
        }
    }
    
    // Run on page load
    makeResponsive();
    
    // Run on window resize with debouncing for performance
    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(makeResponsive, 250);
    });
    
    // Run on orientation change (mobile devices)
    window.addEventListener('orientationchange', function() {
        setTimeout(makeResponsive, 500); // Delay to allow orientation change to complete
    });
    
    // Live search functionality
    $('#categorySearch').on('keyup', function() {
        var searchTerm = $(this).val().toLowerCase();
        
        $('#categoriesTable tr.category-row').each(function() {
            var rowText = $(this).text().toLowerCase();
            
            if (rowText.indexOf(searchTerm) === -1) {
                $(this).addClass('hidden');
            } else {
                $(this).removeClass('hidden');
            }
        });
        
        var visibleRows = $('#categoriesTable tr.category-row:not(.hidden)').length;
        
        if (visibleRows === 0 && searchTerm !== '') {
            if ($('#noResultsRow').length === 0) {
                $('#categoriesTable').append('<tr id="noResultsRow"><td colspan="4" style="text-align: center; padding: 20px; font-style: italic;">No categories found matching your search</td></tr>');
            }
        } else {
            $('#noResultsRow').remove();
        }
    });
    
    // Clear search functionality
    $('#clearSearch').on('click', function() {
        $('#categorySearch').val('');
        $('#categoriesTable tr.category-row').removeClass('hidden');
        $('#noResultsRow').remove();
        $('#categorySearch').focus();
    });
    
    // Auto hide messages after 4 seconds
    if ($('.message, .error').length > 0) {
        setTimeout(function() {
            $('.message, .error').fadeOut(800, function() {
                $(this).remove();
            });
        }, 4000);
    }
    
    // Touch-friendly enhancements for mobile
    if ('ontouchstart' in window) {
        // Add touch feedback for buttons on mobile devices
        $('.btn, .btn-edit, .btn-subcategory, .btn-delete').on('touchstart', function() {
            $(this).css('opacity', '0.7');
        });
        
        $('.btn, .btn-edit, .btn-subcategory, .btn-delete').on('touchend', function() {
            $(this).css('opacity', '1');
        });
    }
});
</script>

<?php require 'footer.php'; ?>
