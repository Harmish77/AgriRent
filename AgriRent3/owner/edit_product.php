<?php
// edit_product.php
session_start();
require_once('../auth/config.php');

// Only farmers/owners allowed
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'O') {
    header('Location: ../login.php');
    exit();
}

$farmer_id = (int) $_SESSION['user_id'];
$product_id = (int)($_GET['id'] ?? 0);

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not found.");
}

function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$msg = "";
$errors = [];
$success = false;

// Fetch product details
$product = null;
$stmt = $conn->prepare("SELECT p.*, s.Category_id FROM product p 
                       LEFT JOIN product_subcategories s ON p.Subcategory_id = s.Subcategory_id 
                       WHERE p.product_id = ? AND p.seller_id = ?");
$stmt->bind_param("ii", $product_id, $farmer_id);
$stmt->execute();
$result = $stmt->get_result();
$product = $result->fetch_assoc();
$stmt->close();

if (!$product) {
    header("Location: manage_products.php?error=Product not found");
    exit();
}

/* -------------------
   Handle Form Submission
   ------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subcategory_id = (int)($_POST['subcategory'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $price = floatval($_POST['price'] ?? 0);
    $quantity = floatval($_POST['quantity'] ?? 0);
    $unit = strtoupper(trim($_POST['unit'] ?? ''));
    
    // Validation
    if (empty($name)) {
        $errors[] = "Product name is required";
    } elseif (strlen($name) > 50) {
        $errors[] = "Product name must be 50 characters or less";
    }
    
    if ($subcategory_id <= 0) {
        $errors[] = "Please select a valid subcategory";
    }
    
    if (empty($description)) {
        $errors[] = "Product description is required";
    } elseif (strlen($description) > 1000) {
        $errors[] = "Description must be 1000 characters or less";
    }
    
    if ($price <= 0) {
        $errors[] = "Price must be greater than 0";
    } elseif ($price > 999999.99) {
        $errors[] = "Price cannot exceed ₹999,999.99";
    }
    
    if ($quantity <= 0) {
        $errors[] = "Quantity must be greater than 0";
    } elseif ($quantity > 999999.99) {
        $errors[] = "Quantity cannot exceed 999,999.99";
    }
    
    if (!in_array($unit, ['K', 'L', 'P'])) {
        $errors[] = "Please select a valid unit";
    }
    
    // Check for duplicate name (excluding current product)
    if (!empty($name)) {
        $dup_stmt = $conn->prepare("SELECT product_id FROM product WHERE seller_id = ? AND Name = ? AND product_id != ?");
        $dup_stmt->bind_param("isi", $farmer_id, $name, $product_id);
        $dup_stmt->execute();
        if ($dup_stmt->get_result()->fetch_assoc()) {
            $errors[] = "You already have another product with this name.";
        }
        $dup_stmt->close();
    }
    
    // Update product if no errors
    if (empty($errors)) {
        $update_stmt = $conn->prepare("UPDATE product SET 
                                      Subcategory_id = ?, Name = ?, Description = ?, Price = ?, 
                                      Quantity = ?, Unit = ?, Approval_status = 'PEN' 
                                      WHERE product_id = ? AND seller_id = ?");
        $update_stmt->bind_param("issdssii", 
            $subcategory_id, $name, $description, $price, 
            $quantity, $unit, $product_id, $farmer_id
        );
        
        if ($update_stmt->execute()) {
            $success = true;
            $msg = "Product updated successfully! Changes are pending admin approval.";
            
            // Refresh product data
            $stmt = $conn->prepare("SELECT p.*, s.Category_id FROM product p 
                                  LEFT JOIN product_subcategories s ON p.Subcategory_id = s.Subcategory_id 
                                  WHERE p.product_id = ? AND p.seller_id = ?");
            $stmt->bind_param("ii", $product_id, $farmer_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $product = $result->fetch_assoc();
            $stmt->close();
        } else {
            $errors[] = "Failed to update product. Please try again.";
        }
        $update_stmt->close();
    }
}

/* -------------------
   Fetch Categories and Subcategories
   ------------------- */
$categories = [];
$subcategories = [];

$cat_result = $conn->query("SELECT Category_id, Category_name FROM product_categories ORDER BY Category_name");
if ($cat_result) $categories = $cat_result->fetch_all(MYSQLI_ASSOC);

$sub_result = $conn->query("SELECT s.Subcategory_id, s.Subcategory_name, s.Category_id, c.Category_name 
                           FROM product_subcategories s 
                           JOIN product_categories c ON c.Category_id = s.Category_id 
                           ORDER BY c.Category_name, s.Subcategory_name");
if ($sub_result) $subcategories = $sub_result->fetch_all(MYSQLI_ASSOC);

include 'oheader.php';
include 'owner_nav.php';
?>

<link rel="stylesheet" href="../assets/css/farmer.css">

<div class="main-content">
    <h1>Edit Product</h1>
    <p>Update your product information. Changes will require admin approval.</p>

    <?php if ($success && $msg): ?>
        <div class="message">
            <strong>✅ Success!</strong><br>
            <?= e($msg) ?>
            <br><br>
            <a href="manage_products.php" class="btn">← Back to Manage Products</a>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="error">
            <strong>❌ Please fix the following errors:</strong>
            <ul style="margin: 10px 0 0 20px;">
                <?php foreach($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- Navigation -->
    <div class="quick-actions" style="margin-bottom: 30px;">
        <a href="manage_products.php" class="action-btn">← Back to Products</a>
    </div>

    <!-- Edit Form -->
    <div class="form-section">
        <h2>📝 Edit Product Information</h2>
        <form method="POST" id="editProductForm">
            
            <!-- Category Selection -->
            <div class="form-group">
                <label for="category_id"><strong>Category *</strong></label>
                <select id="category_id" onchange="loadSubcategories()" required>
                    <option value="">-- Choose Category --</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= e($cat['Category_id']) ?>" 
                                <?= ($product['Category_id'] == $cat['Category_id']) ? 'selected' : '' ?>>
                            <?= e($cat['Category_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Subcategory Selection -->
            <div class="form-group">
                <label for="subcategory"><strong>Subcategory *</strong></label>
                <select name="subcategory" id="subcategory" required>
                    <option value="">-- Select Subcategory --</option>
                </select>
            </div>

            <!-- Product Name -->
            <div class="form-group">
                <label for="name"><strong>Product Name *</strong></label>
                <input type="text" 
                       name="name" 
                       id="name" 
                       value="<?= e($product['Name']) ?>" 
                       maxlength="50" 
                       required>
                <small>Current: <span id="name-count">0</span>/50 characters</small>
            </div>

            <!-- Description -->
            <div class="form-group">
                <label for="description"><strong>Description *</strong></label>
                <textarea name="description" 
                          id="description" 
                          rows="4" 
                          maxlength="1000" 
                          required><?= e($product['Description']) ?></textarea>
                <small>Current: <span id="desc-count">0</span>/1000 characters</small>
            </div>

            <!-- Price, Quantity, Unit Row -->
            <div style="display: flex; gap: 20px;">
                <div class="form-group" style="flex: 1;">
                    <label for="price"><strong>Price (₹) *</strong></label>
                    <input type="number" 
                           name="price" 
                           id="price" 
                           step="0.01" 
                           min="0.01" 
                           value="<?= e($product['Price']) ?>" 
                           required>
                </div>

                <div class="form-group" style="flex: 1;">
                    <label for="quantity"><strong>Quantity *</strong></label>
                    <input type="number" 
                           name="quantity" 
                           id="quantity" 
                           step="0.01" 
                           min="0.01" 
                           value="<?= e($product['Quantity']) ?>" 
                           required>
                </div>

                <div class="form-group" style="flex: 1;">
                    <label for="unit"><strong>Unit *</strong></label>
                    <select name="unit" id="unit" required>
                        <option value="K" <?= $product['Unit'] === 'K' ? 'selected' : '' ?>>Kilogram (Kg)</option>
                        <option value="L" <?= $product['Unit'] === 'L' ? 'selected' : '' ?>>Liter (L)</option>
                        <option value="P" <?= $product['Unit'] === 'P' ? 'selected' : '' ?>>Piece (P)</option>
                    </select>
                </div>
            </div>

            <!-- Submit Buttons -->
            <div class="form-group">
                <button type="submit" class="btn" style="background: #28a745; padding: 15px 30px;">
                    💾 Update Product
                </button>
                <a href="manage_products.php" class="btn" style="background: #6c757d; margin-left: 15px;">
                    ❌ Cancel
                </a>
            </div>
        </form>
    </div>
</div>

<?php include 'ofooter.php'; ?>

<script>
const subcategoriesData = <?= json_encode($subcategories) ?>;
const currentSubcategoryId = <?= $product['Subcategory_id'] ?>;

function loadSubcategories() {
    const categoryId = document.getElementById('category_id').value;
    const subcategorySelect = document.getElementById('subcategory');
    
    subcategorySelect.innerHTML = '<option value="">-- Select Subcategory --</option>';
    
    if (categoryId) {
        const filteredSubs = subcategoriesData.filter(sub => sub.Category_id == categoryId);
        
        filteredSubs.forEach(sub => {
            const option = document.createElement('option');
            option.value = sub.Subcategory_id;
            option.textContent = sub.Subcategory_name;
            if (sub.Subcategory_id == currentSubcategoryId) {
                option.selected = true;
            }
            subcategorySelect.appendChild(option);
        });
    }
}

function updateCounter(inputId, counterId) {
    const input = document.getElementById(inputId);
    const counter = document.getElementById(counterId);
    
    function update() {
        counter.textContent = input.value.length;
    }
    
    input.addEventListener('input', update);
    update(); // Initial count
}

document.addEventListener('DOMContentLoaded', function() {
    loadSubcategories();
    updateCounter('name', 'name-count');
    updateCounter('description', 'desc-count');
    
    // Auto hide success/error messages after 4 seconds
    if ($('.message, .error').length > 0) {
        setTimeout(function() {
            $('.message, .error').fadeOut(800, function() {
                $(this).remove();
            });
        }, 4000);
    }
});
</script>

<style>
.form-section {
    background: white;
    padding: 30px;
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
}

.form-group {
    margin-bottom: 25px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #234a23;
}

.form-group input, .form-group select, .form-group textarea {
    width: 100%;
    padding: 12px 15px;
    border: 2px solid #ddd;
    border-radius: 8px;
    font-size: 15px;
    transition: all 0.3s ease;
}

.form-group input:focus, .form-group select:focus, .form-group textarea:focus {
    border-color: #4a7c59;
    outline: none;
    box-shadow: 0 0 0 3px rgba(74, 124, 89, 0.1);
}

.form-group small {
    display: block;
    margin-top: 6px;
    color: #666;
    font-size: 13px;
}

.message {
    background: #d4edda;
    color: #155724;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 25px;
    border: 1px solid #c3e6cb;
}

.error {
    background: #f8d7da;
    color: #721c24;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 25px;
    border: 1px solid #f5c6cb;
}

.action-btn {
    background: #234a23;
    color: #fff;
    padding: 10px 20px;
    text-decoration: none;
    border-radius: 6px;
    font-weight: 600;
    margin-right: 10px;
    display: inline-block;
}

.action-btn:hover {
    background: #4a7c59;
    color: #fff;
}

.btn {
    background: #234a23;
    color: white;
    padding: 12px 24px;
    text-decoration: none;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    font-weight: 600;
    display: inline-block;
    transition: all 0.3s ease;
}

.btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

@media (max-width: 768px) {
    div[style*="display: flex"] {
        flex-direction: column !important;
    }
}
</style>
