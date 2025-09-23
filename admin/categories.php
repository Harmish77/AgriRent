<?php
session_start();
require_once '../auth/config.php';
if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'A') {
    header('Location: ../login.php');
    exit;
}

// Add new category
if (isset($_POST['add_category'])) {
    $name = trim($_POST['category_name']);
    $description = $_POST['description'];
    
    // Check if category name already exists
    $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM equipment_categories WHERE LOWER(Name) = LOWER(?)");
    $check_stmt->bind_param('s', $name);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    $count = $result->fetch_assoc()['count'];
    
    if ($count > 0) {
        $error = "Category name already exists. Please choose a different name.";
    } else {
        $stmt = $conn->prepare("INSERT INTO equipment_categories (Name, Description) VALUES (?, ?)");
        $stmt->bind_param('ss', $name, $description);
        
        if ($stmt->execute()) {
            $message = "Category added successfully";
        } else {
            $error = "Error adding category";
        }
    }
}

// Edit category
if (isset($_POST['edit_category'])) {
    $id = $_POST['category_id'];
    $name = trim($_POST['category_name']);
    $description = $_POST['description'];
    
    // Check if category name already exists (excluding current category)
    $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM equipment_categories WHERE LOWER(Name) = LOWER(?) AND category_id != ?");
    $check_stmt->bind_param('si', $name, $id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    $count = $result->fetch_assoc()['count'];
    
    if ($count > 0) {
        $error = "Category name already exists. Please choose a different name.";
    } else {
        $stmt = $conn->prepare("UPDATE equipment_categories SET Name = ?, Description = ? WHERE category_id = ?");
        $stmt->bind_param('ssi', $name, $description, $id);
        
        if ($stmt->execute()) {
            $message = "Category updated successfully";
        } else {
            $error = "Error updating category";
        }
    }
}

// Delete category
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $check = $conn->query("SELECT COUNT(*) as count FROM equipment_subcategories WHERE Category_id = $id");
    if ($check->fetch_assoc()['count'] > 0) {
        $error = "Cannot delete category with subcategories";
    } else {
        $conn->query("DELETE FROM equipment_categories WHERE category_id = $id");
        $message = "Category deleted successfully";
    }
}

// Get category for editing
$edit_category = null;
if (isset($_GET['edit'])) {
    $edit_id = $_GET['edit'];
    $result = $conn->query("SELECT * FROM equipment_categories WHERE category_id = $edit_id");
    $edit_category = $result->fetch_assoc();
}

// Get all categories
$categories = $conn->query("SELECT * FROM equipment_categories ORDER BY Name");
require 'header.php';
require 'admin_nav.php';
?>

<div class="main-content">
    <h1>Equipment Categories</h1>
    
    <?php if (isset($message)): ?>
        <div class="message"><?= $message ?></div>
    <?php endif; ?>
    
    <?php if (isset($error)): ?>
        <div class="error"><?= $error ?></div>
    <?php endif; ?>

    <!-- Add/Edit Category Form -->
    <div class="form-box">
        <h3><?= $edit_category ? 'Edit Category' : 'Add New Category' ?></h3>
        <form method="POST">
            <?php if ($edit_category): ?>
                <input type="hidden" name="category_id" value="<?= $edit_category['category_id'] ?>">
            <?php endif; ?>
            
            <div class="form-group">
                <label>Category Name:</label>
                <input type="text" name="category_name" 
                       value="<?= $edit_category ? htmlspecialchars($edit_category['Name']) : '' ?>" required>
                
            </div>
            
            <div class="form-group">
                <label>Description:</label>
                <textarea name="description" rows="3"><?= $edit_category ? htmlspecialchars($edit_category['Description']) : '' ?></textarea>
            </div>
            
            <button type="submit" name="<?= $edit_category ? 'edit_category' : 'add_category' ?>" class="btn">
                <?= $edit_category ? 'Update Category' : 'Add Category' ?>
            </button>
            
            <?php if ($edit_category): ?>
                <a href="?" class="btn btn-secondary">Cancel</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Categories List -->
    <h3>All Categories</h3>
    <table>
        <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Description</th>
            <th>Actions</th>
        </tr>
        
        <?php if ($categories->num_rows > 0): ?>
            <?php while($category = $categories->fetch_assoc()): ?>
            <tr>
                <td><?= $category['category_id'] ?></td>
                <td><?= $category['Name'] ?></td>
                <td><?= $category['Description'] ?></td>
                <td>
                    <a href="subcategories.php?category_id=<?= $category['category_id'] ?>">Manage Subcategories</a> |
                    <a href="?edit=<?= $category['category_id'] ?>">Edit</a> |
                    <a href="?delete=<?= $category['category_id'] ?>" onclick="return confirm('Delete this category?')">Delete</a>
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

<script>
    document.addEventListener('DOMContentLoaded', function() {
    const messages = document.querySelectorAll('.message, .error');
    messages.forEach(function(message) {
        setTimeout(function() {
            message.style.transition = 'opacity 0.5s';
            message.style.opacity = '0';
            setTimeout(function() {
                message.remove();
            }, 500);
        }, 5000);
    });
});

    if (window.history.replaceState) {
    window.history.replaceState(null, null, window.location.href);
}
</script>

<?php require 'footer.php'; ?>
