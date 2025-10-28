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
    <div class="search-box">
        <input type="text" id="categorySearch" placeholder="Search product categories..." >
        <button type="button" id="clearSearch" class="btn" >Clear</button>
    </div>

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
                <td>
                    <a href="product_subcategories.php?category_id=<?= $category['Category_id'] ?>" class="btn-action btn-subcategory">Manage Subcategories</a>
                    <a href="?edit=<?= $category['Category_id'] ?>" class="btn-action btn-edit">Edit</a>
                    <a href="?delete=<?= $category['Category_id'] ?>" class="btn-action btn-delete" onclick="return confirm('Delete this category?')">Delete</a>
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

/* Action buttons - Same styling as categories.php */
.btn-action {
    display: inline-block;
    padding: 4px 8px;
    margin: 2px;
    text-decoration: none;
    border-radius: 3px;
    font-size: 14px;
    font-weight: bold;
    color: white;
    cursor: pointer;
    transition: all 0.3s ease;
    border: none;
}

.btn-subcategory {
    
    color: black;
}

.btn-subcategory:hover {
    background-color: #234a23;
    
    color: white;
}

.btn-edit {
   
    color: black;
}

.btn-edit:hover {
    background-color: #ffc107;
   
    color: #212529;
}

.btn-delete {
    color: #234a23;
}

.btn-delete:hover {
    background-color: #c82333;
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

/* MOBILE RESPONSIVE ADDITIONS - These are the ONLY new additions for mobile responsiveness */
@media (max-width: 768px) {
    .btn-action {
        display: block;
        margin: 2px 0;
        text-align: center;
        width: 100%;
    }
    
    #categorySearch {
        width: 100% !important; /* Override inline style */
        margin-bottom: 10px !important; /* Override inline style */
        margin-right: 0 !important; /* Override inline style */
        box-sizing: border-box;
        font-size: 16px; /* Prevent zoom on iOS */
    }
    
    /* Make search box use flexbox for better mobile layout */
    .search-box {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }
    
    #clearSearch {
        width: 100% !important; /* Override inline style */
        margin-left: 0 !important; /* Override inline style */
        box-sizing: border-box;
    }
    
    /* Table responsiveness */
    #categoriesTable {
        font-size: 12px;
        min-width: 600px;
    }
    
    /* Make table horizontally scrollable on mobile */
    .main-content {
        overflow-x: auto;
    }
}

@media (max-width: 480px) {
    /* Extra small mobile devices */
    #categorySearch {
        padding: 12px !important; /* Better touch target */
        font-size: 16px; /* Prevent zoom on iOS */
    }
    
    #clearSearch {
        padding: 12px !important; /* Better touch target */
        font-size: 14px;
    }
    
    .btn-action {
        padding: 8px 6px;
        font-size: 12px;
    }
    
    #categoriesTable {
        font-size: 11px;
    }
    
    #categoriesTable th,
    #categoriesTable td {
        padding: 6px 4px;
    }
}
</style>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script>
$(document).ready(function() {
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
});
</script>

<?php require 'footer.php'; ?>
