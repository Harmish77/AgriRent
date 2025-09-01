<?php
session_start();
require_once('../auth/config.php');

// Check if user is logged in and is Equipment Owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'O') {
    header('Location: ../login.php');
    exit();
}

$owner_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Fetch categories and subcategories
$categories = [];
$subcategories = [];

try {
    // Get equipment categories
    $cat_result = $conn->query("SELECT category_id, Name FROM equipment_categories ORDER BY Name");
    while ($cat = $cat_result->fetch_assoc()) {
        $categories[] = $cat;
    }

    // Get equipment subcategories with parent category
    $subcat_result = $conn->query("SELECT Subcategory_id, Category_id, Subcategory_name FROM equipment_subcategories ORDER BY Subcategory_name");
    while ($subcat = $subcat_result->fetch_assoc()) {
        $subcategories[] = $subcat;
    }
} catch (Exception $e) {
    error_log("Categories fetch error: " . $e->getMessage());
}

// Handle form submission
if ($_POST) {
    $title = trim($_POST['title']);
    $brand = trim($_POST['brand']);
    $model = trim($_POST['model']);
    $year = intval($_POST['year']);
    $subcategory_id = intval($_POST['subcategory_id']);
    $description = trim($_POST['description']);
    $hourly_rate = floatval($_POST['hourly_rate']);
    $daily_rate = floatval($_POST['daily_rate']);

    // Validation
    if (empty($title) || empty($brand) || empty($model) || empty($description)) {
        $error = 'Please fill in all required fields.';
    } elseif ($subcategory_id <= 0) {
        $error = 'Please select a valid category.';
    } elseif ($hourly_rate <= 0 && $daily_rate <= 0) {
        $error = 'Please enter at least one rate (hourly or daily).';
    } else {
        // Insert equipment
        $stmt = $conn->prepare("INSERT INTO equipment (Owner_id, Subcategories_id, Title, Brand, Model, Year, Description, Hourly_rate, Daily_rate, Approval_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'PEN')");
        $stmt->bind_param("iisssissd", $owner_id, $subcategory_id, $title, $brand, $model, $year, $description, $hourly_rate, $daily_rate);
        
        if ($stmt->execute()) {
            $message = 'Equipment added successfully! It will be reviewed by admin for approval.';
            // Clear form
            $_POST = [];
        } else {
            $error = 'Error adding equipment. Please try again.';
        }
        $stmt->close();
    }
}

require 'aheader.php';
require 'owner_nav.php';
?>

<link rel="stylesheet" href="../admin.css">

<div class="main-content">
    <h1>Add New Equipment</h1>
    <p style="color: #666; margin-bottom: 30px;">List your agricultural equipment for rental</p>

    <?php if ($message): ?>
        <div class="message"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="form-section">
        <form method="POST">
            <div class="form-group">
                <label for="title">Equipment Title </label>
                <input type="text" id="title" name="title" value="<?= htmlspecialchars($_POST['title'] ?? '') ?>" required>
            </div>

            <div class="form-group">
                <label for="brand">Brand </label>
                <input type="text" id="brand" name="brand" value="<?= htmlspecialchars($_POST['brand'] ?? '') ?>" required>
            </div>

            <div class="form-group">
                <label for="model">Model </label>
                <input type="text" id="model" name="model" value="<?= htmlspecialchars($_POST['model'] ?? '') ?>" required>
            </div>

            <div class="form-group">
                <label for="year">Manufacturing Year</label>
                <input type="number" id="year" name="year" min="1900" max="<?= date('Y') ?>" value="<?= htmlspecialchars($_POST['year'] ?? '') ?>">
            </div>

            <!-- Main Category Selection -->
            <div class="form-group">
                <label for="category_id">Equipment Category </label>
                <select id="category_id" name="category_id" onchange="loadSubcategories(this.value)" required>
                    <option value="">Select Main Category</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= $category['category_id'] ?>" <?= (($_POST['category_id'] ?? '') == $category['category_id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($category['Name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Subcategory Selection -->
            <div class="form-group">
                <label for="subcategory_id">Equipment Subcategory *</label>
                <select id="subcategory_id" name="subcategory_id" required>
                    <option value="">Select Subcategory</option>
                    <?php if (isset($_POST['category_id']) && $_POST['category_id']): ?>
                        <?php foreach ($subcategories as $subcat): ?>
                            <?php if ($subcat['Category_id'] == $_POST['category_id']): ?>
                                <option value="<?= $subcat['Subcategory_id'] ?>" <?= (($_POST['subcategory_id'] ?? '') == $subcat['Subcategory_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($subcat['Subcategory_name']) ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="description">Description </label>
                <textarea id="description" name="description" rows="4" placeholder="Describe your equipment features, condition, and any special instructions..." required><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
            </div>

            <div class="form-group">
                <label for="hourly_rate">Hourly Rate (₹)</label>
                <input type="number" id="hourly_rate" name="hourly_rate" step="0.01" min="0" placeholder="0.00" value="<?= htmlspecialchars($_POST['hourly_rate'] ?? '') ?>">
                <small style="color: #666;">Leave empty if not offering hourly rates</small>
            </div>

            <div class="form-group">
                <label for="daily_rate">Daily Rate (₹)</label>
                <input type="number" id="daily_rate" name="daily_rate" step="0.01" min="0" placeholder="0.00" value="<?= htmlspecialchars($_POST['daily_rate'] ?? '') ?>">
                <small style="color: #666;">Leave empty if not offering daily rates</small>
            </div>

            <div class="form-group">
                <button type="submit" class="btn">Add Equipment</button>
                <a href="manage_equipment.php" class="btn" style="background: #6c757d; margin-left: 10px;">Cancel</a>
            </div>
        </form>
    </div>

    <!-- Help Section -->
    <div class="report-sections" style="margin-top: 30px;">
        <div class="report-section">
            <h3>Help & Guidelines</h3>
            <div class="status-list">
                <div>✅ Fill all required fields marked with *</div>
                <div>📝 Provide detailed description for better visibility</div>
                <div>💰 Set competitive rates to attract more customers</div>
                <div>⏳ Equipment will be pending admin approval</div>
                <div>🚜 Only approved equipment appears in listings</div>
            </div>
        </div>
    </div>
</div>

<script>
// JavaScript to handle category/subcategory dependency
function loadSubcategories(categoryId) {
    const subcategorySelect = document.getElementById('subcategory_id');
    
    // Clear subcategory options
    subcategorySelect.innerHTML = '<option value="">Select Subcategory</option>';
    
    if (categoryId === '') {
        return;
    }
    
    // Filter and populate subcategories based on selected category
    const subcategories = <?= json_encode($subcategories) ?>;
    
    subcategories.forEach(function(subcat) {
        if (subcat.Category_id == categoryId) {
            const option = document.createElement('option');
            option.value = subcat.Subcategory_id;
            option.textContent = subcat.Subcategory_name;
            subcategorySelect.appendChild(option);
        }
    });
}

// Set selected values on page load if form was submitted with errors
document.addEventListener('DOMContentLoaded', function() {
    const categorySelect = document.getElementById('category_id');
    if (categorySelect.value) {
        loadSubcategories(categorySelect.value);
        
        // Set subcategory value if it was previously selected
        const selectedSubcategory = '<?= $_POST['subcategory_id'] ?? '' ?>';
        if (selectedSubcategory) {
            setTimeout(function() {
                document.getElementById('subcategory_id').value = selectedSubcategory;
            }, 100);
        }
    }
});
</script>

<style>
.form-section {
    background: white;
    padding: 30px;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    margin-bottom: 30px;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #234a23;
}

.form-group input,
.form-group textarea,
.form-group select {
    width: 100%;
    max-width: 500px;
    padding: 10px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
    transition: border-color 0.3s ease;
}

.form-group input:focus,
.form-group textarea:focus,
.form-group select:focus {
    outline: none;
    border-color: #4a7c59;
    box-shadow: 0 0 5px rgba(74, 124, 89, 0.3);
}

.form-group small {
    display: block;
    margin-top: 5px;
    font-size: 12px;
}

.report-sections {
    display: grid;
    grid-template-columns: 1fr;
    gap: 20px;
}

.report-section {
    background: white;
    padding: 25px;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
}

.report-section h3 {
    color: #234a23;
    margin-bottom: 15px;
    font-size: 18px;
}

.status-list div {
    margin-bottom: 8px;
    padding: 5px 0;
    color: #666;
    font-size: 14px;
}

.message {
    background: #d4edda;
    color: #155724;
    padding: 12px 20px;
    border-radius: 6px;
    margin-bottom: 20px;
    border: 1px solid #c3e6cb;
}

.error {
    background: #f8d7da;
    color: #721c24;
    padding: 12px 20px;
    border-radius: 6px;
    margin-bottom: 20px;
    border: 1px solid #f5c6cb;
}
</style>
