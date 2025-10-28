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

    <!-- Search Box -->
    <div class="search-section">
        <h3>All Subcategories</h3>
        <div class="search-box">
            <input type="text" id="subcategorySearch" placeholder="Search subcategories..." style="padding: 8px; width: 1050px; margin-right: 10px;">
            <button type="button" id="clearSearch" class="btn" style="margin-left: 5px; width: 80px;">Clear</button>
        </div>
    </div>

    <!-- Subcategories List -->
    <table id="subcategoriesTable">
        <thead>
            <tr class="table-header">
                <th>ID</th>
                <th>Name</th>
                <th>Description</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($subcategories->num_rows > 0): ?>
                <?php while($subcategory = $subcategories->fetch_assoc()): ?>
                <tr class="subcategory-row">
                    <td class="subcategory-id"><?= $subcategory['Subcategory_id'] ?></td>
                    <td class="subcategory-name"><?= htmlspecialchars($subcategory['Subcategory_name']) ?></td>
                    <td class="subcategory-description"><?= htmlspecialchars($subcategory['Description']) ?></td>
                    <td>
                        <a href="?category_id=<?= $category_id ?>&edit=<?= $subcategory['Subcategory_id'] ?>">Edit</a> |
                        <a href="?delete=<?= $subcategory['Subcategory_id'] ?>&category_id=<?= $category_id ?>" 
                           onclick="return confirm('Delete this subcategory?')">Delete</a>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr class="no-subcategories">
                    <td colspan="4">No subcategories found</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<style>
/* Search Section Styling */
.search-section {
    margin: 20px 0;
}

.search-box {
    margin-bottom: 15px;
}

#subcategorySearch {
    font-size: 14px;
    border: 1px solid #ccc;
    border-radius: 4px;
}

#subcategorySearch:focus {
    outline: none;
    border-color: #234a23;
    box-shadow: 0 0 5px rgba(0,124,186,0.3);
}

/* Hidden row styling */
.subcategory-row.hidden {
    display: none !important;
}

/* Search results count */
.search-results {
    margin-top: 5px;
    font-size: 12px;
    color: #666;
}

/* Form box styling */
.form-box {
    background: #f9f9f9;
    padding: 20px;
    border-radius: 5px;
    margin-bottom: 30px;
}

.form-group {
    margin-bottom: 15px;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: bold;
}

.form-group input, .form-group textarea {
    width: 100%;
    padding: 8px;
    border: 1px solid #ccc;
    border-radius: 4px;
    font-size: 14px;
}

.btn {
    padding: 8px 15px;
    background: #234a23;
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
    margin-right: 10px;
}

.btn:hover {
    background: #005a8b;
}

.btn-secondary {
    background: #666;
}

.btn-secondary:hover {
    background: #444;
}

/* Message styling */
.message {
    background: #d4edda;
    color: #155724;
    padding: 10px;
    border-radius: 4px;
    margin-bottom: 15px;
}

.error {
    background: #f8d7da;
    color: #721c24;
    padding: 10px;
    border-radius: 4px;
    margin-bottom: 15px;
}

/* Buttons section */
.buttons {
    margin-bottom: 20px;
}

/* Responsive design */
@media only screen and (max-width: 700px) {
    #subcategorySearch {
        width: 100% !important;
        margin-bottom: 10px;
    }
}
</style>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script>
$(document).ready(function() {
    // Live search functionality
    function searchSubcategories() {
        var searchTerm = $('#subcategorySearch').val().toLowerCase();
        var visibleRows = 0;
        
        $('#subcategoriesTable tbody tr.subcategory-row').each(function() {
            var $row = $(this);
            var id = $row.find('.subcategory-id').text().toLowerCase();
            var name = $row.find('.subcategory-name').text().toLowerCase();
            var description = $row.find('.subcategory-description').text().toLowerCase();
            
            // Check if search term matches any field
            var matches = searchTerm === '' || 
                         id.includes(searchTerm) ||
                         name.includes(searchTerm) || 
                         description.includes(searchTerm);
            
            if (matches) {
                $row.removeClass('hidden');
                visibleRows++;
            } else {
                $row.addClass('hidden');
            }
        });
        
        // Handle no results message
        if (visibleRows === 0 && searchTerm !== '') {
            if ($('#noResultsRow').length === 0) {
                $('#subcategoriesTable tbody').append('<tr id="noResultsRow"><td colspan="4" style="text-align: center; padding: 20px; font-style: italic;">No subcategories found matching your search</td></tr>');
            }
        } else {
            $('#noResultsRow').remove();
        }
        
        // Update search results count
        updateSearchCount(visibleRows, searchTerm);
    }
    
    // Update search results count
    function updateSearchCount(count, searchTerm) {
        $('#searchCount').remove();
        
        if (searchTerm !== '') {
            var countText = count + ' subcategor' + (count !== 1 ? 'ies' : 'y') + ' found';
            $('.search-box').append('<div id="searchCount" class="search-results">' + countText + '</div>');
        }
    }
    
    // Search input event
    $('#subcategorySearch').on('keyup', function() {
        searchSubcategories();
    });
    
    // Clear search functionality
    $('#clearSearch').on('click', function() {
        $('#subcategorySearch').val('');
        $('#subcategoriesTable tbody tr.subcategory-row').removeClass('hidden');
        $('#noResultsRow').remove();
        $('#searchCount').remove();
        $('#subcategorySearch').focus();
    });
    
    // Focus search input on page load
    $('#subcategorySearch').focus();
});

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
