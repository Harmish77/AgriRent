<?php
session_start();
require_once '../auth/config.php';

// Check authentication
if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'A') {
    header('Location: ../login.php');
    exit;
}

$category_id = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;
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
    $name = trim($_POST['subcategory_name']);
    $description = $_POST['description'];
    
    // Check if subcategory name already exists in this category
    $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM equipment_subcategories WHERE LOWER(Subcategory_name) = LOWER(?) AND Category_id = ?");
    $check_stmt->bind_param('si', $name, $category_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    $count = $result->fetch_assoc()['count'];
    
    if ($count > 0) {
        $_SESSION['error'] = "Subcategory name already exists in this category. Please choose a different name.";
    } else {
        $stmt = $conn->prepare("INSERT INTO equipment_subcategories (Category_id, Subcategory_name, Description) VALUES (?, ?, ?)");
        $stmt->bind_param('iss', $category_id, $name, $description);
        
        if ($stmt->execute()) {
            $_SESSION['message'] = "Subcategory added successfully";
        } else {
            $_SESSION['error'] = "Error adding subcategory";
        }
    }
    
    // Redirect to prevent resubmission
    header('Location: ?category_id=' . $category_id);
    exit;
}

// Edit subcategory
if (isset($_POST['edit_subcategory'])) {
    $id = intval($_POST['subcategory_id']);
    $name = trim($_POST['subcategory_name']);
    $description = $_POST['description'];
    
    // Check if subcategory name already exists in this category (excluding current subcategory)
    $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM equipment_subcategories WHERE LOWER(Subcategory_name) = LOWER(?) AND Category_id = ? AND Subcategory_id != ?");
    $check_stmt->bind_param('sii', $name, $category_id, $id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    $count = $result->fetch_assoc()['count'];
    
    if ($count > 0) {
        $_SESSION['error'] = "Subcategory name already exists in this category. Please choose a different name.";
        header('Location: ?category_id=' . $category_id . '&edit=' . $id);
    } else {
        $stmt = $conn->prepare("UPDATE equipment_subcategories SET Subcategory_name = ?, Description = ? WHERE Subcategory_id = ?");
        $stmt->bind_param('ssi', $name, $description, $id);
        
        if ($stmt->execute()) {
            $_SESSION['message'] = "Subcategory updated successfully";
        } else {
            $_SESSION['error'] = "Error updating subcategory";
        }
        
        header('Location: ?category_id=' . $category_id);
    }
    exit;
}

// Delete subcategory
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    
    // Check if subcategory has equipment
    $check = $conn->query("SELECT COUNT(*) as count FROM equipment WHERE Subcategories_id = $id");
    if ($check->fetch_assoc()['count'] > 0) {
        $_SESSION['error'] = "Cannot delete subcategory with equipment listed";
    } else {
        $conn->query("DELETE FROM equipment_subcategories WHERE Subcategory_id = $id");
        $_SESSION['message'] = "Subcategory deleted successfully";
    }
    
    header('Location: ?category_id=' . $category_id);
    exit;
}

// Get messages from session and clear them
$message = isset($_SESSION['message']) ? $_SESSION['message'] : null;
$error = isset($_SESSION['error']) ? $_SESSION['error'] : null;
unset($_SESSION['message'], $_SESSION['error']);

// Get subcategory for editing
$edit_subcategory = null;
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $result = $conn->query("SELECT * FROM equipment_subcategories WHERE Subcategory_id = $edit_id AND Category_id = $category_id");
    $edit_subcategory = $result->fetch_assoc();
}

// Get all subcategories for this category
$subcategories = $conn->query("SELECT * FROM equipment_subcategories WHERE Category_id = $category_id ORDER BY Subcategory_name");
require 'header.php';
require 'admin_nav.php';
?>

<div class="main-content">
    <h1>Subcategories for: <?= htmlspecialchars($category['Name']) ?></h1>
    
    <div class="buttons">
        <a href="categories.php" class="btn">Back to Categories</a>
    </div>
    
    <?php if ($message): ?>
        <div class="message"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
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
                <input type="text" name="subcategory_name" 
                       value="<?= $edit_subcategory ? htmlspecialchars($edit_subcategory['Subcategory_name']) : '' ?>" required>
            </div>
            
            <div class="form-group">
                <label>Description:</label>
                <textarea name="description" rows="3"><?= $edit_subcategory ? htmlspecialchars($edit_subcategory['Description']) : '' ?></textarea>
            </div>
            
            <button type="submit" name="<?= $edit_subcategory ? 'edit_subcategory' : 'add_subcategory' ?>" class="btn">
                <?= $edit_subcategory ? 'Update Subcategory' : 'Add Subcategory' ?>
            </button>
            
            <?php if ($edit_subcategory): ?>
                <a href="?category_id=<?= $category_id ?>" class="btn btn-secondary">Cancel</a>
            <?php endif; ?>
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
                <td><?= htmlspecialchars($subcategory['Subcategory_name']) ?></td>
                <td><?= htmlspecialchars($subcategory['Description']) ?></td>
                <td>
                    <a href="?category_id=<?= $category_id ?>&edit=<?= $subcategory['Subcategory_id'] ?>">Edit</a> |
                    <a href="?delete=<?= $subcategory['Subcategory_id'] ?>&category_id=<?= $category_id ?>" 
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

<script>
// Auto-hide messages after 5 seconds
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
</script>

<?php require 'footer.php'; ?>
