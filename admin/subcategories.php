<?php
session_start();
require_once '../auth/config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'A') {
    header('Location: ../login.php');
    exit;
}

$category_id = isset($_GET['category_id']) ? $_GET['category_id'] : 0;

if (!$category_id) {
    header('Location: categories.php');
    exit;
}

// Get category info
$category_result = $conn->query("SELECT * FROM equipment_categories WHERE category_id = $category_id");
if ($category_result->num_rows == 0) {
    header('Location: categories.php');
    exit;
}
$category = $category_result->fetch_assoc();

// Add new subcategory
if (isset($_POST['add_subcategory'])) {
    $name = $_POST['subcategory_name'];
    $description = $_POST['description'];
    
    $stmt = $conn->prepare("INSERT INTO equipment_subcategories (Category_id, Subcategory_name, Description) VALUES (?, ?, ?)");
    $stmt->bind_param('iss', $category_id, $name, $description);
    
    if ($stmt->execute()) {
        $message = "Subcategory added successfully";
    } else {
        $error = "Error adding subcategory";
    }
}

// Delete subcategory
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    
    // Check if subcategory has equipment
    $check = $conn->query("SELECT COUNT(*) as count FROM equipment WHERE Subcategories_id = $id");
    if ($check->fetch_assoc()['count'] > 0) {
        $error = "Cannot delete subcategory with equipment listed";
    } else {
        $conn->query("DELETE FROM equipment_subcategories WHERE Subcategory_id = $id");
        $message = "Subcategory deleted successfully";
    }
}

// Get all subcategories for this category
$subcategories = $conn->query("SELECT * FROM equipment_subcategories WHERE Category_id = $category_id ORDER BY Subcategory_name");

require 'header.php';
require 'admin_nav.php';
?>

<div class="main-content">
    <h1>Subcategories for: <?= $category['Name'] ?></h1>
    
    <div class="buttons">
        <a href="categories.php" class="btn">Back to Categories</a>
    </div>
    
    <?php if (isset($message)): ?>
        <div class="message"><?= $message ?></div>
    <?php endif; ?>
    
    <?php if (isset($error)): ?>
        <div class="error"><?= $error ?></div>
    <?php endif; ?>

    <!-- Add Subcategory Form -->
    <div class="form-box">
        <h3>Add New Subcategory</h3>
        <form method="POST">
            <div class="form-group">
                <label>Subcategory Name:</label>
                <input type="text" name="subcategory_name" required>
            </div>
            <div class="form-group">
                <label>Description:</label>
                <textarea name="description" rows="3"></textarea>
            </div>
            <button type="submit" name="add_subcategory" class="btn">Add Subcategory</button>
        </form>
    </div>

    <!-- Subcategories List -->
    <h3>All Subcategories</h3>
    <table>
        <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Description</th>
            <th>Actions</th>
        </tr>
        
        <?php if ($subcategories->num_rows > 0): ?>
            <?php while($subcategory = $subcategories->fetch_assoc()): ?>
            <tr>
                <td><?= $subcategory['Subcategory_id'] ?></td>
                <td><?= $subcategory['Subcategory_name'] ?></td>
                <td><?= $subcategory['Description'] ?></td>
                <td>
                    <a href="?delete=<?= $subcategory['Subcategory_id'] ?>&category_id=<?= $category_id ?>" onclick="return confirm('Delete this subcategory?')">Delete</a>
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

<?php require 'footer.php'; ?>
