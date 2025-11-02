<?php
session_start();
require_once '../auth/config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'A') {
    header('Location: ../login.php');
    exit;
}

$category_id = intval($_GET['category_id'] ?? 0);

// Get category info
$category_result = $conn->query("SELECT * FROM product_categories WHERE Category_id = $category_id");
$category = $category_result->fetch_assoc();

if (!$category) {
    header('Location: product_categories.php');
    exit;
}

// Add new subcategory
if (isset($_POST['add_subcategory'])) {
    $name = $_POST['subcategory_name'];
    $description = $_POST['description'];
    
    $stmt = $conn->prepare("INSERT INTO product_subcategories (Category_id, Subcategory_name, Description) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $category_id, $name, $description);
    
    if ($stmt->execute()) {
        $message = "Product subcategory added successfully!";
    } else {
        $error = "Error adding subcategory: " . $conn->error;
    }
}

// Edit subcategory
if (isset($_POST['edit_subcategory'])) {
    $id = intval($_POST['subcategory_id']);
    $name = $_POST['subcategory_name'];
    $description = $_POST['description'];
    
    $stmt = $conn->prepare("UPDATE product_subcategories SET Subcategory_name = ?, Description = ? WHERE Subcategory_id = ?");
    $stmt->bind_param("ssi", $name, $description, $id);
    
    if ($stmt->execute()) {
        $message = "Product subcategory updated successfully!";
    } else {
        $error = "Error updating subcategory: " . $conn->error;
    }
}

// Delete subcategory
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    
    // Check if subcategory has products
    $check = $conn->query("SELECT COUNT(*) as count FROM product WHERE Category_id = $id");
    if ($check->fetch_assoc()['count'] > 0) {
        $error = "Cannot delete subcategory with existing products!";
    } else {
        $conn->query("DELETE FROM product_subcategories WHERE Subcategory_id = $id");
        $message = "Product subcategory deleted successfully!";
    }
}

// Get subcategory for editing
$edit_subcategory = null;
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $edit_result = $conn->query("SELECT * FROM product_subcategories WHERE Subcategory_id = $edit_id");
    $edit_subcategory = $edit_result->fetch_assoc();
}

// Get all subcategories for this category
$subcategories = $conn->query("SELECT * FROM product_subcategories WHERE Category_id = $category_id ORDER BY Subcategory_name");

require 'header.php';
require 'admin_nav.php';
?>

<div class="main-content">
    <div class="breadcrumb">
        <a href="product_categories.php">🌾 Product Categories</a> → 
        <span>Subcategories for: <?= htmlspecialchars($category['Category_name']) ?></span>
    </div>
    
    <h1>🌾 <?= htmlspecialchars($category['Category_name']) ?> - Subcategories</h1>
    
    <?php if (isset($message)): ?>
        <div class="message"><?= $message ?></div>
    <?php endif; ?>
    
    <?php if (isset($error)): ?>
        <div class="error"><?= $error ?></div>
    <?php endif; ?>

    <!-- Add/Edit Subcategory Form -->
    <div class="form-box">
        <h3><?= $edit_subcategory ? 'Edit Product Subcategory' : 'Add New Product Subcategory' ?></h3>
        <form method="POST">
            <?php if ($edit_subcategory): ?>
                <input type="hidden" name="subcategory_id" value="<?= $edit_subcategory['Subcategory_id'] ?>">
            <?php endif; ?>
            
            <div class="form-group">
                <label>Subcategory Name</label>
                <input type="text" name="subcategory_name" value="<?= $edit_subcategory ? htmlspecialchars($edit_subcategory['Subcategory_name']) : '' ?>" required>
            </div>
            
            <div class="form-group">
                <label>Description</label>
                <textarea name="description" rows="3"><?= $edit_subcategory ? htmlspecialchars($edit_subcategory['Description']) : '' ?></textarea>
            </div>
            
            <?php if ($edit_subcategory): ?>
                <button type="submit" name="edit_subcategory" class="btn btn-update">Update Subcategory</button>
                <a href="product_subcategories.php?category_id=<?= $category_id ?>" class="btn btn-cancel">Cancel</a>
            <?php else: ?>
                <button type="submit" name="add_subcategory" class="btn">Add Subcategory</button>
            <?php endif; ?>
        </form>
    </div>

    <!-- Subcategories List -->
    <h3>All <?= htmlspecialchars($category['Category_name']) ?> Subcategories</h3>
    <div class="search-box">
        <input type="text" id="subcategorySearch" placeholder="Search subcategories..." style="padding: 8px; width: 300px; margin-right: 10px;">
        <button type="button" id="clearSearch" class="btn">Clear</button>
    </div>

    <table id="subcategoriesTable">
        <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Description</th>
            <th>Actions</th>
        </tr>
        
        <?php if ($subcategories->num_rows > 0): ?>
            <?php while ($subcategory = $subcategories->fetch_assoc()): ?>
            <tr class="subcategory-row">
                <td><?= $subcategory['Subcategory_id'] ?></td>
                <td><?= htmlspecialchars($subcategory['Subcategory_name']) ?></td>
                <td><?= htmlspecialchars($subcategory['Description']) ?></td>
                <td>
                    <a href="?category_id=<?= $category_id ?>&edit=<?= $subcategory['Subcategory_id'] ?>" 
                       class="btn-action btn-edit">Edit</a>
                    <a href="?category_id=<?= $category_id ?>&delete=<?= $subcategory['Subcategory_id'] ?>" 
                       class="btn-action btn-delete" 
                       onclick="return confirm('Delete this subcategory?')">Delete</a>
                </td>
            </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr>
                <td colspan="4">No subcategories found</td>
            </tr>
        <?php endif; ?>
    </table>
</div>

<style>
/* Hide rows during search */
.subcategory-row.hidden {
    display: none !important;
}

/* Breadcrumb */
.breadcrumb {
    margin-bottom: 20px;
    font-size: 14px;
    color: #666;
}

.breadcrumb a {
    color: #234a23;
    text-decoration: none;
    margin-right: 5px;
}

.breadcrumb a:hover {
    text-decoration: underline;
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

.form-group input, .form-group textarea {
    width: 100%;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}

.form-group input:focus, .form-group textarea:focus {
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

/* Action buttons - Exact same as subcategories.php */
.btn-action {
    display: inline-block;
    padding: 6px 12px;
    margin: 2px;
    text-decoration: none;
    border-radius: 4px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    border: 2px solid transparent;
}

.btn-edit {
    background: #ffc107;
    color: #212529;
    border-color: #ffc107;
}

.btn-edit:hover {
    background: white;
    color: #856404;
    border-color: #ffc107;
}

.btn-delete {
    background: #dc3545;
    color: white;
    border-color: #dc3545;
}

.btn-delete:hover {
    background: white;
    color: #dc3545;
    border-color: #dc3545;
}

/* Table Styling */
#subcategoriesTable {
    width: 100%;
    border-collapse: collapse;
    background: white;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    margin-bottom: 20px;
}

#subcategoriesTable th,
#subcategoriesTable td {
    padding: 15px 12px;
    text-align: left;
    border-bottom: 1px solid #e9ecef;
}

#subcategoriesTable th {
    background: #f8f9fa;
    font-weight: 600;
    color: #333;
    font-size: 14px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

#subcategoriesTable tr:hover {
    background: #f8f9fa;
}

#subcategoriesTable tr:last-child td {
    border-bottom: none;
}

/* Message Styling */
.message {
    background: #d4edda;
    color: #155724;
    padding: 12px 16px;
    border-radius: 5px;
    margin-bottom: 20px;
    border: 1px solid #c3e6cb;
    border-left: 4px solid #28a745;
}

.error {
    background: #f8d7da;
    color: #721c24;
    padding: 12px 16px;
    border-radius: 5px;
    margin-bottom: 20px;
    border: 1px solid #f5c6cb;
    border-left: 4px solid #dc3545;
}

/* Search Box */
.search-box {
    margin-bottom: 20px;
}

#subcategorySearch {
    font-size: 14px;
    border: 1px solid #ccc;
    border-radius: 4px;
}

#subcategorySearch:focus {
    outline: none;
    border-color: #234a23;
    box-shadow: 0 0 5px rgba(35, 74, 35, 0.3);
}

/* MOBILE RESPONSIVE */
@media (max-width: 768px) {
    .btn-action {
        display: block;
        margin: 2px 0;
        text-align: center;
        width: 100%;
    }
    
    #subcategorySearch {
        width: 100% !important;
        margin-bottom: 10px !important;
        margin-right: 0 !important;
        box-sizing: border-box;
        font-size: 16px; /* Prevent zoom on iOS */
    }
    
    .search-box {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }
    
    #clearSearch {
        width: 100% !important;
        margin-left: 0 !important;
        box-sizing: border-box;
    }
    
    #subcategoriesTable {
        font-size: 12px;
        min-width: 600px;
    }
    
    .main-content {
        overflow-x: auto;
    }
}

@media (max-width: 480px) {
    #subcategorySearch {
        padding: 12px !important;
        font-size: 16px; /* Prevent zoom on iOS */
    }
    
    #clearSearch {
        padding: 12px !important;
        font-size: 14px;
    }
    
    .btn-action {
        padding: 8px 6px;
        font-size: 12px;
    }
    
    #subcategoriesTable {
        font-size: 11px;
    }
    
    #subcategoriesTable th, #subcategoriesTable td {
        padding: 8px 6px;
    }
}
</style>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script>
$(document).ready(function() {
    // Live search functionality
    $('#subcategorySearch').on('keyup', function() {
        var searchTerm = $(this).val().toLowerCase();
        
        $('#subcategoriesTable tr.subcategory-row').each(function() {
            var rowText = $(this).text().toLowerCase();
            if (rowText.indexOf(searchTerm) === -1) {
                $(this).addClass('hidden');
            } else {
                $(this).removeClass('hidden');
            }
        });
        
        // Show "no results" message if no subcategories match
        var visibleRows = $('#subcategoriesTable tr.subcategory-row:not(.hidden)').length;
        if (visibleRows === 0 && searchTerm !== '') {
            if ($('#noResultsRow').length === 0) {
                $('#subcategoriesTable').append('<tr id="noResultsRow"><td colspan="4" style="text-align: center; padding: 20px; font-style: italic;">No product subcategories found matching your search</td></tr>');
            }
        } else {
            $('#noResultsRow').remove();
        }
    });
    
    // Clear search functionality
    $('#clearSearch').on('click', function() {
        $('#subcategorySearch').val('');
        $('#subcategoriesTable tr.subcategory-row').removeClass('hidden');
        $('#noResultsRow').remove();
        $('#subcategorySearch').focus();
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
