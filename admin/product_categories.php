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

    <!-- Add Category Form -->
    <div class="form-box">
        <h3>Add New Product Category</h3>
        <form method="POST">
            <div class="form-group">
                <label>Category Name:</label>
                <input type="text" name="category_name" required>
            </div>
            <div class="form-group">
                <label>Description:</label>
                <textarea name="description" rows="3"></textarea>
            </div>
            <button type="submit" name="add_category" class="btn">Add Category</button>
        </form>
    </div>

    <!-- Categories List -->
    <h3>All Product Categories</h3>
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
                <td><?= $category['Category_id'] ?></td>
                <td><?= $category['Category_name'] ?></td>
                <td><?= $category['description'] ?></td>
                <td>
                    <a href="product_subcategories.php?category_id=<?= $category['Category_id'] ?>">Manage Subcategories</a> |
                    <a href="?delete=<?= $category['Category_id'] ?>" onclick="return confirm('Delete this category?')">Delete</a>
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

<?php require 'footer.php'; ?>