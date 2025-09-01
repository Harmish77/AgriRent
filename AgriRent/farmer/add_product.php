<?php
// add_product.php
session_start();
require_once('../auth/config.php'); // Database connection

// Only farmers allowed
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'F') {
    header('Location: ../login.php');
    exit();
}

$farmer_id = (int) $_SESSION['user_id'];

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not found. Make sure config.php sets \$conn = new mysqli(...);");
}

function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$msg = "";
$errors = [];
$success = false;

/* -------------------
   Handle Form Submission
   ------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get and sanitize form data
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
        $errors[] = "Please select a valid unit (Kg, Liter, or Piece)";
    }
    
    // Check if subcategory exists
    if ($subcategory_id > 0) {
        $check_stmt = $conn->prepare("SELECT s.Subcategory_id, c.Category_name, s.Subcategory_name 
                                     FROM product_subcategories s 
                                     JOIN product_categories c ON s.Category_id = c.Category_id 
                                     WHERE s.Subcategory_id = ?");
        $check_stmt->bind_param("i", $subcategory_id);
        $check_stmt->execute();
        $subcategory_info = $check_stmt->get_result()->fetch_assoc();
        $check_stmt->close();
        
        if (!$subcategory_info) {
            $errors[] = "Invalid subcategory selected";
        }
    }
    
    // Check for duplicate product name by same farmer
    if (!empty($name)) {
        $dup_stmt = $conn->prepare("SELECT product_id FROM product WHERE seller_id = ? AND Name = ?");
        $dup_stmt->bind_param("is", $farmer_id, $name);
        $dup_stmt->execute();
        if ($dup_stmt->get_result()->fetch_assoc()) {
            $errors[] = "You already have a product with this name. Please choose a different name.";
        }
        $dup_stmt->close();
    }
    
    // If no errors, insert the product
    if (empty($errors)) {
        $insert_stmt = $conn->prepare("INSERT INTO product (seller_id, Subcategory_id, Name, Description, Price, Quantity, Unit, listed_date, Approval_status) 
                                      VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 'PEN')");
        $insert_stmt->bind_param("iissdss", 
            $farmer_id, 
            $subcategory_id, 
            $name, 
            $description, 
            $price, 
            $quantity, 
            $unit
        );
        
        if ($insert_stmt->execute()) {
            $product_id = $conn->insert_id;
            $success = true;
            $msg = "Product '{$name}' added successfully! (Product ID: #{$product_id}) - Pending admin approval.";
            
            // Clear form data on success
            $_POST = [];
        } else {
            $errors[] = "Database error: Failed to add product. Please try again.";
        }
        $insert_stmt->close();
    }
}

/* -------------------
   Fetch Categories and Subcategories
   ------------------- */
$categories = [];
$subcategories = [];

// Get categories
$cat_result = $conn->query("SELECT Category_id, Category_name, description FROM product_categories ORDER BY Category_name");
if ($cat_result) {
    $categories = $cat_result->fetch_all(MYSQLI_ASSOC);
}

// Get all subcategories (for JavaScript)
$sub_result = $conn->query("SELECT s.Subcategory_id, s.Subcategory_name, s.Category_id, c.Category_name, s.Description 
                           FROM product_subcategories s 
                           JOIN product_categories c ON c.Category_id = s.Category_id 
                           ORDER BY c.Category_name, s.Subcategory_name");
if ($sub_result) {
    $subcategories = $sub_result->fetch_all(MYSQLI_ASSOC);
}

include 'fheader.php';
include 'farmer_nav.php';
?>

<link rel="stylesheet" href="../assets/css/farmer.css">

<div class="main-content">
    <h1>Add New Product</h1>
    <p>List your agricultural products for sale on the AgriRent platform. All products require admin approval before becoming visible to buyers.</p>
</br>
    <?php if ($success && $msg): ?>
        <div class="message">
            <strong>✅ Success!</strong><br>
            <?= e($msg) ?>
            <br><br>
            <a href="manage_products.php" class="btn">← Back to Manage Products</a>
            <a href="add_product.php" class="btn">Add Another Product</a>
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

    <!-- Navigation Links -->
    <div class="quick-actions" style="margin-bottom: 30px;">
        <a href="manage_products.php" class="action-btn">← Back to Products</a>
        <a href="dashboard.php" class="action-btn">Dashboard</a>
    </div>

    <!-- Add Product Form -->
    <div class="form-section">
        <h2>📝 Product Information</h2>
        <form method="POST" id="productForm" novalidate>
            
            <!-- Category Selection -->
            <div class="form-group">
                <label for="category_id"><strong>1. Select Category *</strong></label>
                <select id="category_id" onchange="loadSubcategories()" required>
                    <option value="">-- Choose Product Category --</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= e($cat['Category_id']) ?>" 
                                data-description="<?= e($cat['description'] ?? '') ?>"
                                <?= (($_POST['category_id'] ?? '') == $cat['Category_id']) ? 'selected' : '' ?>>
                            <?= e($cat['Category_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small id="category-description" style="color: #666; font-style: italic;"></small>
            </div>

            <!-- Subcategory Selection -->
            <div class="form-group">
                <label for="subcategory"><strong>2. Select Subcategory *</strong></label>
                <select name="subcategory" id="subcategory" required>
                    <option value="">-- First Select Category --</option>
                </select>
                <small id="subcategory-description" style="color: #666; font-style: italic;"></small>
            </div>

            <!-- Product Name -->
            <div class="form-group">
                <label for="name"><strong>3. Product Name *</strong></label>
                <input type="text" 
                       name="name" 
                       id="name" 
                       value="<?= e($_POST['name'] ?? '') ?>" 
                       placeholder="e.g., Organic Basmati Rice, Fresh Red Tomatoes"
                       maxlength="50" 
                       required>
                <small>Be specific and descriptive (Max 50 characters) - Current: <span id="name-count">0</span></small>
            </div>

            <!-- Product Description -->
            <div class="form-group">
                <label for="description"><strong>4. Product Description *</strong></label>
                <textarea name="description" 
                          id="description" 
                          rows="4" 
                          placeholder="Describe your product: quality, origin, harvest date, organic certification, storage conditions, etc."
                          maxlength="1000" 
                          required><?= e($_POST['description'] ?? '') ?></textarea>
                <small>Detailed description helps buyers make informed decisions (Max 1000 characters) - Current: <span id="desc-count">0</span></small>
            </div>

            <!-- Price and Quantity Row -->
            <div style="display: flex; gap: 20px; align-items: start;">
                
                <!-- Price -->
                <div class="form-group" style="flex: 1;">
                    <label for="price"><strong>5. Price per Unit (₹) *</strong></label>
                    <input type="number" 
                           name="price" 
                           id="price" 
                           step="0.01" 
                           min="0.01" 
                           max="999999.99"
                           value="<?= e($_POST['price'] ?? '') ?>" 
                           placeholder="0.00"
                           required>
                    <small>Enter competitive market price</small>
                </div>

                <!-- Quantity -->
                <div class="form-group" style="flex: 1;">
                    <label for="quantity"><strong>6. Available Quantity *</strong></label>
                    <input type="number" 
                           name="quantity" 
                           id="quantity" 
                           step="0.01" 
                           min="0.01" 
                           max="999999.99"
                           value="<?= e($_POST['quantity'] ?? '') ?>" 
                           placeholder="0.00"
                           required>
                    <small>How much do you have available?</small>
                </div>

                <!-- Unit -->
                <div class="form-group" style="flex: 1;">
                    <label for="unit"><strong>7. Unit of Measurement *</strong></label>
                    <select name="unit" id="unit" required>
                        <option value="">-- Select Unit --</option>
                        <option value="K" <?= (($_POST['unit'] ?? '') === 'K') ? 'selected' : '' ?>>
                            Kilogram (Kg)
                        </option>
                        <option value="L" <?= (($_POST['unit'] ?? '') === 'L') ? 'selected' : '' ?>>
                            Liter (L)
                        </option>
                        <option value="P" <?= (($_POST['unit'] ?? '') === 'P') ? 'selected' : '' ?>>
                            Piece/Count (P)
                        </option>
                    </select>
                    <small>Choose appropriate unit</small>
                </div>
            </div>

            <!-- Price Calculator Display -->
            <div class="form-group">
                <div id="price-calculator" style="background: #f8f9fa; padding: 15px; border-radius: 6px; border: 1px solid #ddd; display: none;">
                    <h4 style="margin: 0 0 10px 0; color: #234a23;">💰 Price Information:</h4>
                    <div id="price-details"></div>
                </div>
            </div>

            

            <!-- Submit Buttons -->
            <div class="form-group">
                <button type="submit" class="btn" id="submit-btn" style="background: #28a745; padding: 15px 30px; font-size: 16px; font-weight: 600;">
                    🚀 Add Product to Marketplace
                </button>
                
                <button type="button" onclick="resetForm()" class="btn" style="background: #6c757d; margin-left: 15px;">
                    🔄 Clear Form
                </button>
                
                <a href="manage_products.php" class="btn" style="background: #dc3545; margin-left: 15px;">
                    ❌ Cancel
                </a>
            </div>
        </form>
    </div>
</div>

<?php include 'ffooter.php'; ?>

<script>
// Subcategories data from PHP
const subcategoriesData = <?= json_encode($subcategories) ?>;

// Load subcategories based on selected category
function loadSubcategories() {
    const categorySelect = document.getElementById('category_id');
    const subcategorySelect = document.getElementById('subcategory');
    const categoryDesc = document.getElementById('category-description');
    const subcategoryDesc = document.getElementById('subcategory-description');
    
    const selectedOption = categorySelect.options[categorySelect.selectedIndex];
    const categoryId = categorySelect.value;
    
    // Update category description
    if (selectedOption && selectedOption.dataset.description) {
        categoryDesc.textContent = selectedOption.dataset.description;
    } else {
        categoryDesc.textContent = '';
    }
    
    // Clear subcategory description
    subcategoryDesc.textContent = '';
    
    // Clear existing subcategory options
    subcategorySelect.innerHTML = '<option value="">-- Loading... --</option>';
    
    if (!categoryId) {
        subcategorySelect.innerHTML = '<option value="">-- First Select Category --</option>';
        return;
    }

    // Filter subcategories for selected category
    const filteredSubs = subcategoriesData.filter(sub => sub.Category_id == categoryId);
    
    subcategorySelect.innerHTML = '<option value="">-- Select Subcategory --</option>';
    
    filteredSubs.forEach(sub => {
        const option = document.createElement('option');
        option.value = sub.Subcategory_id;
        option.textContent = sub.Subcategory_name;
        option.dataset.description = sub.Description || '';
        subcategorySelect.appendChild(option);
    });
}

// Update subcategory description
document.getElementById('subcategory').addEventListener('change', function() {
    const selectedOption = this.options[this.selectedIndex];
    const subcategoryDesc = document.getElementById('subcategory-description');
    
    if (selectedOption && selectedOption.dataset.description) {
        subcategoryDesc.textContent = selectedOption.dataset.description;
    } else {
        subcategoryDesc.textContent = '';
    }
});

// Character counters
function updateCounter(inputId, counterId, maxLength) {
    const input = document.getElementById(inputId);
    const counter = document.getElementById(counterId);
    
    input.addEventListener('input', function() {
        const currentLength = this.value.length;
        counter.textContent = currentLength;
        
        if (currentLength > maxLength * 0.9) {
            counter.style.color = '#dc3545';
        } else if (currentLength > maxLength * 0.7) {
            counter.style.color = '#ffc107';
        } else {
            counter.style.color = '#28a745';
        }
    });
    
    // Initial count
    counter.textContent = input.value.length;
}

// Price calculator
function updatePriceCalculator() {
    const price = parseFloat(document.getElementById('price').value) || 0;
    const quantity = parseFloat(document.getElementById('quantity').value) || 0;
    const unit = document.getElementById('unit').value;
    const calculator = document.getElementById('price-calculator');
    const details = document.getElementById('price-details');
    
    if (price > 0 && quantity > 0 && unit) {
        const totalValue = price * quantity;
        let unitText = '';
        
        switch(unit) {
            case 'K': unitText = 'per kg'; break;
            case 'L': unitText = 'per liter'; break;
            case 'P': unitText = 'per piece'; break;
        }
        
        details.innerHTML = `
            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                <span>Price ${unitText}:</span>
                <strong>₹${price.toFixed(2)}</strong>
            </div>
            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                <span>Available quantity:</span>
                <strong>${quantity} ${unit === 'K' ? 'kg' : (unit === 'L' ? 'liters' : 'pieces')}</strong>
            </div>
            <div style="display: flex; justify-content: space-between; padding-top: 8px; border-top: 1px solid #ddd;">
                <span>Total inventory value:</span>
                <strong style="color: #28a745;">₹${totalValue.toFixed(2)}</strong>
            </div>
        `;
        calculator.style.display = 'block';
    } else {
        calculator.style.display = 'none';
    }
}

// Form reset function
function resetForm() {
    if (confirm('Are you sure you want to clear all form data?')) {
        document.getElementById('productForm').reset();
        document.getElementById('subcategory').innerHTML = '<option value="">-- First Select Category --</option>';
        document.getElementById('category-description').textContent = '';
        document.getElementById('subcategory-description').textContent = '';
        document.getElementById('price-calculator').style.display = 'none';
        updateCounter('name', 'name-count', 50);
        updateCounter('description', 'desc-count', 1000);
    }
}

// Form validation and submission
document.getElementById('productForm').addEventListener('submit', function(e) {
    const submitBtn = document.getElementById('submit-btn');
    const originalText = submitBtn.innerHTML;
    
    // Show loading state
    submitBtn.innerHTML = '⏳ Adding Product...';
    submitBtn.disabled = true;
    
    // Re-enable button after 3 seconds in case of errors
    setTimeout(() => {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }, 3000);
});

// Initialize everything when page loads
document.addEventListener('DOMContentLoaded', function() {
    // Set up character counters
    updateCounter('name', 'name-count', 50);
    updateCounter('description', 'desc-count', 1000);
    
    // Set up price calculator
    ['price', 'quantity', 'unit'].forEach(id => {
        document.getElementById(id).addEventListener('input', updatePriceCalculator);
        document.getElementById(id).addEventListener('change', updatePriceCalculator);
    });
    
    // Load subcategories if category was previously selected (on form errors)
    const categorySelect = document.getElementById('category_id');
    if (categorySelect.value) {
        loadSubcategories();
        
        // Set previously selected subcategory after a brief delay
        setTimeout(() => {
            const selectedSubcategory = '<?= e($_POST['subcategory'] ?? '') ?>';
            if (selectedSubcategory) {
                document.getElementById('subcategory').value = selectedSubcategory;
            }
        }, 100);
    }
    
    // Initial price calculator update
    updatePriceCalculator();
});
</script>
