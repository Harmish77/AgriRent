<?php
// add_product.php
session_start();
require_once('../auth/config.php');

// Only farmers allowed
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'O') {
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
    $user_address_id = (int)($_POST['user_address_id'] ?? 0);
    
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
    
    if ($user_address_id <= 0) {
        $errors[] = "Please select your address/location";
    }
    
    // Check if subcategory exists
    if ($subcategory_id > 0) {
        $check_stmt = $conn->prepare("SELECT s.Subcategory_id, c.Category_name, s.Subcategory_name 
                                     FROM product_subcategories s 
                                     JOIN product_categories c ON s.Category_id = c.Category_id 
                                     WHERE s.Subcategory_id = ?");
        if ($check_stmt) {
            $check_stmt->bind_param("i", $subcategory_id);
            $check_stmt->execute();
            $subcategory_info = $check_stmt->get_result()->fetch_assoc();
            $check_stmt->close();
            
            if (!$subcategory_info) {
                $errors[] = "Invalid subcategory selected";
            }
        }
    }
    
    // Verify user address belongs to the current user - CORRECTED table structure
    if ($user_address_id > 0) {
        $address_stmt = $conn->prepare("SELECT address_id FROM user_addresses WHERE address_id = ? AND user_id = ?");
        if ($address_stmt) {
            $address_stmt->bind_param("ii", $user_address_id, $farmer_id);
            $address_stmt->execute();
            $address_result = $address_stmt->get_result();
            if (!$address_result->fetch_assoc()) {
                $errors[] = "Invalid address selected";
            }
            $address_stmt->close();
        }
    }
    
    // Check for duplicate product name by same farmer
    if (!empty($name)) {
        $dup_stmt = $conn->prepare("SELECT product_id FROM product WHERE seller_id = ? AND Name = ?");
        if ($dup_stmt) {
            $dup_stmt->bind_param("is", $farmer_id, $name);
            $dup_stmt->execute();
            if ($dup_stmt->get_result()->fetch_assoc()) {
                $errors[] = "You already have a product with this name. Please choose a different name.";
            }
            $dup_stmt->close();
        }
    }
    
    // Handle image upload
    $image_uploaded = false;
    $uploaded_image_path = '';
    
    if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] !== UPLOAD_ERR_NO_FILE) {
        $upload_error = $_FILES['product_image']['error'];
        
        if ($upload_error === UPLOAD_ERR_OK) {
            $file_tmp = $_FILES['product_image']['tmp_name'];
            $file_name = $_FILES['product_image']['name'];
            $file_size = $_FILES['product_image']['size'];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            
            // Validate file
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
            $max_file_size = 5 * 1024 * 1024; // 5MB
            
            if (!in_array($file_ext, $allowed_extensions)) {
                $errors[] = "Invalid image format. Please upload JPG, JPEG, PNG, or GIF files only.";
            } elseif ($file_size > $max_file_size) {
                $errors[] = "Image file is too large. Maximum size allowed is 5MB.";
            } elseif (!getimagesize($file_tmp)) {
                $errors[] = "Invalid image file. Please upload a valid image.";
            } else {
                // Create uploads directory if it doesn't exist
                $upload_dir = '../uploads/products/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                // Generate unique filename
                $new_filename = 'product_' . $farmer_id . '_' . time() . '.' . $file_ext;
                $upload_path = $upload_dir . $new_filename;
                
                if (move_uploaded_file($file_tmp, $upload_path)) {
                    $uploaded_image_path = 'uploads/products/' . $new_filename;
                    $image_uploaded = true;
                } else {
                    $errors[] = "Failed to upload image. Please try again.";
                }
            }
        } else {
            $errors[] = "Image upload error. Please try again.";
        }
    }
    
    // If no errors, insert the product
    if (empty($errors)) {
        // Start transaction
        $conn->autocommit(false);
        $product_inserted = false;
        $product_id = 0;
        
        try {
            // First, add address_id column to product table if it doesn't exist
            $check_column = $conn->query("SHOW COLUMNS FROM product LIKE 'address_id'");
            if ($check_column && $check_column->num_rows == 0) {
                $add_column_query = "ALTER TABLE product ADD COLUMN address_id INT(11) AFTER Unit";
                $conn->query($add_column_query);
                
                // Add foreign key constraint
                $add_fk_query = "ALTER TABLE product ADD CONSTRAINT fk_product_address 
                               FOREIGN KEY (address_id) REFERENCES user_addresses(address_id)";
                $conn->query($add_fk_query);
            }
            
            // Insert product with address_id reference - CORRECTED field names
            $insert_stmt = $conn->prepare("INSERT INTO product (seller_id, Subcategory_id, Name, Description, Price, Quantity, Unit, address_id, listed_date, Approval_status) 
                                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'PEN')");
            if ($insert_stmt) {
                $insert_stmt->bind_param("iissddsi", 
                    $farmer_id, 
                    $subcategory_id, 
                    $name, 
                    $description, 
                    $price, 
                    $quantity, 
                    $unit,
                    $user_address_id
                );
                
                if ($insert_stmt->execute()) {
                    $product_id = $conn->insert_id;
                    $product_inserted = true;
                }
                $insert_stmt->close();
            } else {
                throw new Exception("Failed to prepare product insert statement");
            }
            
            // Insert image if uploaded
            if ($product_inserted && $image_uploaded && !empty($uploaded_image_path)) {
                $image_stmt = $conn->prepare("INSERT INTO images (image_type, ID, image_url, upload_date) VALUES ('P', ?, ?, NOW())");
                if ($image_stmt) {
                    $image_stmt->bind_param("is", $product_id, $uploaded_image_path);
                    
                    if (!$image_stmt->execute()) {
                        throw new Exception("Failed to save image information");
                    }
                    $image_stmt->close();
                }
            }
            
            // Commit transaction
            $conn->commit();
            $success = true;
            $msg = "Product '{$name}' added successfully! - Pending admin approval.";
            
            // Clear form data on success
            $_POST = [];
            
        } catch (Exception $e) {
            // Rollback transaction
            $conn->rollback();
            $errors[] = "Database error: Failed to add product. Please try again.";
            error_log("Product add error: " . $e->getMessage());
            
            // Delete uploaded image if it exists
            if ($image_uploaded && file_exists($upload_path ?? '')) {
                unlink($upload_path);
            }
        }
        
        $conn->autocommit(true);
    }
}

/* -------------------
   Fetch Categories, Subcategories, and User Addresses
   ------------------- */
$categories = [];
$subcategories = [];
$user_addresses = [];

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

// Get user addresses for the dropdown - CORRECTED field name
$address_stmt = $conn->prepare("SELECT address_id, address, city, state, Pin_code 
                               FROM user_addresses 
                               WHERE user_id = ? 
                               ORDER BY city, address");
if ($address_stmt) {
    $address_stmt->bind_param("i", $farmer_id);
    $address_stmt->execute();
    $address_result = $address_stmt->get_result();
    if ($address_result) {
        $user_addresses = $address_result->fetch_all(MYSQLI_ASSOC);
    }
    $address_stmt->close();
}

include 'oheader.php';
include 'owner_nav.php';
?>

<link rel="stylesheet" href="../assets/css/farmer.css">

<div class="main-content">
    <h1>Add New Product</h1>
    <p>List your agricultural products for sale on the AgriRent platform. All products require admin approval before becoming visible to buyers.</p>
    <br>
    
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
    
    <!-- Add Product Form -->
    <div class="form-section">
        <h2>Product Information</h2>
        <form method="POST" id="productForm" enctype="multipart/form-data" novalidate>
            
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
            </div>
            
            <!-- User Address Selection -->
            <div class="form-group">
                <label for="user_address_id"><strong>5. Select Your Address/Location *</strong></label>
                <select name="user_address_id" id="user_address_id" required>
                    <option value="">-- Select Your Address --</option>
                    <?php if (!empty($user_addresses)): ?>
                        <?php foreach ($user_addresses as $addr): ?>
                            <?php 
                            $display_text = e($addr['address'] . ', ' . $addr['city'] . ', ' . $addr['state'] . ' - ' . $addr['Pin_code']);
                            $selected = (($_POST['user_address_id'] ?? '') == $addr['address_id']) ? 'selected' : '';
                            ?>
                            <option value="<?= $addr['address_id'] ?>" 
                                    data-address="<?= e($addr['address']) ?>"
                                    data-city="<?= e($addr['city']) ?>"
                                    data-state="<?= e($addr['state']) ?>"
                                    data-pincode="<?= e($addr['Pin_code']) ?>"
                                    <?= $selected ?>>
                                <?= $display_text ?>
                            </option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <option value="" disabled>No addresses found. Please add an address to your profile first.</option>
                    <?php endif; ?>
                </select>
                <small id="address-description" style="color: #666; font-style: italic;">
                    Choose the address where customers can pick up or where you're located
                </small>
                
                <!-- Add Address Link -->
                <?php if (empty($user_addresses)): ?>
                <div style="margin-top: 10px; padding: 10px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 4px;">
                    <strong>⚠️ No addresses found!</strong><br>
                    <a href="profile.php" class="btn" style="background: #ffc107; color: #212529; margin-top: 10px; display: inline-block;">
                        📍 Add Address to Profile
                    </a>
                </div>
                <?php else: ?>
                <div style="margin-top: 8px;">
                    <small style="color: #28a745;">
                         <a href="profile.php" style="color: #28a745; text-decoration: underline;">Manage addresses in your profile</a>
                    </small>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Product Image Upload -->
            <div class="form-group">
                <label for="product_image"><strong>6. Product Image</strong></label>
                <input type="file" 
                       name="product_image" 
                       id="product_image" 
                       accept="image/*"
                       style="padding: 10px; border: 2px dashed #ddd; border-radius: 6px; width: 100%; box-sizing: border-box;">
                <small>Upload a clear image of your product (JPG, PNG - Max 5MB). </small>
                
                <!-- Image Preview -->
                <div id="image-preview" style="margin-top: 10px; display: none;">
                    <img id="preview-image" style="max-width: 200px; max-height: 200px; border-radius: 6px; border: 1px solid #ddd;">
                    <br>
                    <button type="button" onclick="removeImage()" style="margin-top: 5px; background: #dc3545; color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer;">Remove Image</button>
                </div>
            </div>
            
            <!-- Price and Quantity Row -->
            <div style="display: flex; gap: 20px; align-items: start;">
                
                <!-- Price -->
                <div class="form-group" style="flex: 1;">
                    <label for="price"><strong>7. Price per Unit (₹) *</strong></label>
                    <input type="number" 
                           name="price" 
                           id="price" 
                           step="0.01" 
                           min="0.01" 
                           max="999999.99"
                           value="<?= e($_POST['price'] ?? '') ?>" 
                           placeholder="0.00"
                           required>
                    
                </div>
                
                <!-- Quantity -->
                <div class="form-group" style="flex: 1;">
                    <label for="quantity"><strong>8. Available Quantity *</strong></label>
                    <input type="number" 
                           name="quantity" 
                           id="quantity" 
                           step="0.01" 
                           min="0.01" 
                           max="999999.99"
                           value="<?= e($_POST['quantity'] ?? '') ?>" 
                           placeholder="0.00"
                           required>
                    
                </div>
                
                <!-- Unit -->
                <div class="form-group" style="flex: 1;">
                    <label for="unit"><strong>9. Unit of Measurement *</strong></label>
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
                    
                </div>
            </div>
            
            <!-- Price Calculator Display -->
            <div class="form-group">
                <div id="price-calculator" style="background: #f8fff8; padding: 15px; border-radius: 6px; border: 1px solid #d1e7d1; display: none;">
                    <h4 style="margin: 0 0 10px 0; color: #28a745;"> Price Information:</h4>
                    <div id="price-details"></div>
                </div>
            </div>
            
            <!-- Selected Address Preview -->
            <div class="form-group">
                <div id="address-preview" style="background: #e8f5e8; padding: 15px; border-radius: 6px; border: 1px solid #c3e6cb; display: none;">
                    <h4 style="margin: 0 0 10px 0; color: #234a23;"> Selected Address:</h4>
                    <div id="address-details"></div>
                </div>
            </div>
            
            <!-- Submit Buttons -->
            <div class="form-group">
                <button type="submit" class="btn" id="submit-btn" style="background: #234a23; padding: 15px 30px; font-size: 16px; font-weight: 600;" <?= empty($user_addresses) ? 'disabled' : '' ?>>
                     Add Product
                </button>
                
                <a href="manage_products.php" class="btn" style="background:#6c757d; margin-left: 15px;">
                    Cancel
                </a>
            </div>
            
            <?php if (empty($user_addresses)): ?>
            <div style="margin-top: 10px;">
                <small style="color: #dc3545;">
                    <strong>⚠️ Note:</strong> You must add at least one address to your profile before you can add products.
                </small>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php include 'ofooter.php'; ?>

<script>
// Subcategories data from PHP
const subcategoriesData = <?= json_encode($subcategories) ?>;
const userAddresses = <?= json_encode($user_addresses) ?>;

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

// Address selection handling
document.getElementById('user_address_id').addEventListener('change', function() {
    const addressPreview = document.getElementById('address-preview');
    const addressDetails = document.getElementById('address-details');
    const selectedOption = this.options[this.selectedIndex];
    
    if (this.value && selectedOption) {
        const address = selectedOption.dataset.address;
        const city = selectedOption.dataset.city;
        const state = selectedOption.dataset.state;
        const pincode = selectedOption.dataset.pincode;
        
        addressDetails.innerHTML = `
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px;">
                <div><strong>Address:</strong> ${address}</div>
                <div><strong>City:</strong> ${city}</div>
                <div><strong>State:</strong> ${state}</div>
                <div><strong>PIN Code:</strong> ${pincode}</div>
            </div>
            <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #28a745;">
                <small style="color: #666;">This address will be associated with your product for customer reference.</small>
            </div>
        `;
        addressPreview.style.display = 'block';
    } else {
        addressPreview.style.display = 'none';
    }
});

// Image preview functionality
document.getElementById('product_image').addEventListener('change', function(e) {
    const file = e.target.files[0];
    const preview = document.getElementById('image-preview');
    const previewImage = document.getElementById('preview-image');
    
    if (file) {
        // Validate file size (5MB)
        if (file.size > 5 * 1024 * 1024) {
            alert('File size too large. Maximum 5MB allowed.');
            this.value = '';
            preview.style.display = 'none';
            return;
        }
        
        // Validate file type
        const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
        if (!allowedTypes.includes(file.type)) {
            alert('Invalid file type. Please upload JPG, PNG, or GIF images.');
            this.value = '';
            preview.style.display = 'none';
            return;
        }
        
        // Show preview
        const reader = new FileReader();
        reader.onload = function(e) {
            previewImage.src = e.target.result;
            preview.style.display = 'block';
        };
        reader.readAsDataURL(file);
    } else {
        preview.style.display = 'none';
    }
});

// Remove image function
function removeImage() {
    document.getElementById('product_image').value = '';
    document.getElementById('image-preview').style.display = 'none';
}

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
            <div style="display: flex; justify-content: space-between; padding-top: 8px; border-top: 1px solid #28a745;">
                <span>Total inventory value:</span>
                <strong style="color: #28a745;">₹${totalValue.toFixed(2)}</strong>
            </div>
        `;
        calculator.style.display = 'block';
    } else {
        calculator.style.display = 'none';
    }
}

// Form validation and submission
document.getElementById('productForm').addEventListener('submit', function(e) {
    const submitBtn = document.getElementById('submit-btn');
    const originalText = submitBtn.innerHTML;
    
    // Check if addresses exist
    if (userAddresses.length === 0) {
        e.preventDefault();
        alert('Please add at least one address to your profile before adding products.');
        return;
    }
    
    // Show loading state
    submitBtn.innerHTML = '⏳ Adding Product...';
    submitBtn.disabled = true;
    
    // Re-enable button after 10 seconds in case of errors
    setTimeout(() => {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }, 10000);
});

// Auto hide success/error messages after 4 seconds
$(document).ready(function() {
    if ($('.message, .error').length > 0) {
        setTimeout(function() {
            $('.message, .error').fadeOut(800, function() {
                $(this).remove();
            });
        }, 4000);
    }
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
    
    // Trigger address preview if address was selected (on form errors)
    const addressSelect = document.getElementById('user_address_id');
    if (addressSelect.value) {
        addressSelect.dispatchEvent(new Event('change'));
    }
    
    // Debug: Log available data
    console.log('Categories:', <?= json_encode($categories) ?>);
    console.log('Subcategories:', subcategoriesData);
    console.log('User Addresses:', userAddresses);
});
</script>

<style>
/* Additional styles for the product form */
.form-group {
    margin-bottom: 25px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    color: #234a23;
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 12px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 16px;
    box-sizing: border-box;
    transition: border-color 0.3s ease;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    border-color: #28a745;
    outline: none;
    box-shadow: 0 0 5px rgba(40, 167, 69, 0.3);
}

.btn {
    display: inline-block;
    padding: 12px 20px;
    text-decoration: none;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    font-weight: 600;
    text-align: center;
    transition: all 0.3s ease;
}

.btn:hover:not(:disabled) {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.message {
    background: #d4edda;
    color: #155724;
    padding: 15px;
    border-radius: 6px;
    border: 1px solid #c3e6cb;
    margin-bottom: 20px;
}

.error {
    background: #f8d7da;
    color: #721c24;
    padding: 15px;
    border-radius: 6px;
    border: 1px solid #f5c6cb;
    margin-bottom: 20px;
}

.form-section {
    background: white;
    padding: 30px;
    border-radius: 8px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

/* Address preview styling */
#address-preview {
    animation: slideDown 0.3s ease-out;
}

@keyframes slideDown {
    from {
        opacity: 0;
        max-height: 0;
        overflow: hidden;
    }
    to {
        opacity: 1;
        max-height: 200px;
        overflow: visible;
    }
}

@media (max-width: 768px) {
    .form-section {
        padding: 20px;
    }
    
    div[style*="display: flex"] {
        flex-direction: column !important;
        gap: 0 !important;
    }
    
    div[style*="flex: 1"] {
        flex: none !important;
        margin-bottom: 20px;
    }
    
    #address-details > div {
        grid-template-columns: 1fr !important;
    }
}
</style>
