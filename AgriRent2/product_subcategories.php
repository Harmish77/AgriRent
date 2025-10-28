<?php
session_start();
require_once '../auth/config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'A') {
    header('Location: ../login.php');
    exit;
}

$category_id = isset($_GET['category_id']) ? $_GET['category_id'] : 0;

if (!$category_id) {
    header('Location: product_categories.php');
    exit;
}

// Get category info
$category_result = $conn->query("SELECT * FROM product_categories WHERE Category_id = $category_id");
if ($category_result->num_rows == 0) {
    header('Location: product_categories.php');
    exit;
}
$category = $category_result->fetch_assoc();

// Add new subcategory
if (isset($_POST['add_subcategory'])) {
    $name = $_POST['subcategory_name'];
    $description = $_POST['description'];
    
    $stmt = $conn->prepare("INSERT INTO product_subcategories (Category_id, Subcategory_name, Description) VALUES (?, ?, ?)");
    $stmt->bind_param('iss', $category_id, $name, $description);
    
    if ($stmt->execute()) {
        $message = "Subcategory added successfully";
    } else {
        $error = "Error adding subcategory";
    }
}

// Edit subcategory
if (isset($_POST['edit_subcategory'])) {
    $subcategory_id = $_POST['subcategory_id'];
    $name = $_POST['subcategory_name'];
    $description = $_POST['description'];
    
    $stmt = $conn->prepare("UPDATE product_subcategories SET Subcategory_name = ?, Description = ? WHERE Subcategory_id = ?");
    $stmt->bind_param('ssi', $name, $description, $subcategory_id);
    
    if ($stmt->execute()) {
        $message = "Subcategory updated successfully";
    } else {
        $error = "Error updating subcategory";
    }
}

// Delete subcategory
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    
    // Check if subcategory has products
    $check = $conn->query("SELECT COUNT(*) as count FROM product WHERE Subcategory_id = $id");
    if ($check->fetch_assoc()['count'] > 0) {
        $error = "Cannot delete subcategory with products listed";
    } else {
        $conn->query("DELETE FROM product_subcategories WHERE Subcategory_id = $id");
        $message = "Subcategory deleted successfully";
    }
}

// Get subcategory for editing
$edit_subcategory = null;
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $edit_result = $conn->query("SELECT * FROM product_subcategories WHERE Subcategory_id = $edit_id AND Category_id = $category_id");
    if ($edit_result->num_rows > 0) {
        $edit_subcategory = $edit_result->fetch_assoc();
    }
}

// Get all subcategories for this category
$subcategories = $conn->query("SELECT * FROM product_subcategories WHERE Category_id = $category_id ORDER BY Subcategory_name");

require 'header.php';
require 'admin_nav.php';
?>

<div class="main-content">
    <h1>Subcategories for: <?= $category['Category_name'] ?></h1>
    
    <div class="buttons">
        <a href="product_categories.php" class="btn">Back to Product Categories</a>
    </div>
    
    <?php if (isset($message)): ?>
        <div class="message"><?= $message ?></div>
    <?php endif; ?>
    
    <?php if (isset($error)): ?>
        <div class="error"><?= $error ?></div>
    <?php endif; ?>

    <!-- Add/Edit Subcategory Form -->
    <div class="form-box">
        <h3><?= $edit_subcategory ? 'Edit Subcategory' : 'Add New Subcategory' ?></h3>
        <form method="POST">
            <?php if ($edit_subcategory): ?>
                <input type="hidden" name="subcategory_id" value="<?= $edit_subcategory['Subcategory_id'] ?>">
            <?php endif; ?>
            
            <div class="form-group">
                <label>Subcategory Name:</label>
                <input type="text" name="subcategory_name" value="<?= $edit_subcategory ? $edit_subcategory['Subcategory_name'] : '' ?>" required>
            </div>
            <div class="form-group">
                <label>Description:</label>
                <textarea name="description" rows="3"><?= $edit_subcategory ? $edit_subcategory['Description'] : '' ?></textarea>
            </div>
            
            <button type="submit" name="<?= $edit_subcategory ? 'edit_subcategory' : 'add_subcategory' ?>" class="btn">
                <?= $edit_subcategory ? 'Update Subcategory' : 'Add Subcategory' ?>
            </button>
            
            <?php if ($edit_subcategory): ?>
                <a href="?category_id=<?= $category_id ?>" class="btn" style="background-color: #666; margin-left: 10px;">Cancel</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Subcategories List -->
    <h3>All Subcategories</h3>
    <div class="search-box">
        <input type="text" id="subcategorySearch" placeholder="Search subcategories..." style="padding: 8px; width: 1050px; margin-right: 10px;">
        <button type="button" id="clearSearch" class="btn" style="margin-left: 5px; width: 85px;">Clear</button>
    </div>

    <table id="subcategoriesTable">
        <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Description</th>
            <th>Actions</th>
        </tr>
        
        <?php if ($subcategories->num_rows > 0): ?>
            <?php while($subcategory = $subcategories->fetch_assoc()): ?>
            <tr class="subcategory-row">
                <td><?= $subcategory['Subcategory_id'] ?></td>
                <td><?= $subcategory['Subcategory_name'] ?></td>
                <td><?= $subcategory['Description'] ?></td>
                <td>
                    <a href="?edit=<?= $subcategory['Subcategory_id'] ?>&category_id=<?= $category_id ?>" class="btn-edit">Edit</a> | 
                    <a href="?delete=<?= $subcategory['Subcategory_id'] ?>&category_id=<?= $category_id ?>" onclick="return confirm('Delete this subcategory?')" class="btn-delete">Delete</a>
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

</style>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script>
$(document).ready(function() {
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
    });
    
    $('#clearSearch').on('click', function() {
        $('#subcategorySearch').val('');
        $('#subcategoriesTable tr.subcategory-row').removeClass('hidden');
        $('#subcategorySearch').focus();
    });
});
</script>

<?php require 'footer.php'; ?>
