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

// Enable mysqli error reporting
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

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
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $brand = trim($_POST['brand'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $year = intval($_POST['year'] ?? 0);
    $category_id = intval($_POST['category_id'] ?? 0);
    $subcategory_id = intval($_POST['subcategory_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $hourly_rate = floatval($_POST['hourly_rate'] ?? 0);
    $daily_rate = floatval($_POST['daily_rate'] ?? 0);

    // Validation
    if (empty($title) || empty($brand) || empty($model) || empty($description)) {
        $error = 'Please fill in all required fields.';
    } elseif ($category_id <= 0 || $subcategory_id <= 0) {
        $error = 'Please select valid category and subcategory.';
    } elseif ($hourly_rate <= 0 && $daily_rate <= 0) {
        $error = 'Please enter at least one rate (hourly or daily).';
    }

    // Handle image upload
    $uploaded_file_path = '';
    if (empty($error)) {
        if (!isset($_FILES['equipment_image']) || $_FILES['equipment_image']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Please upload an equipment image.';
        } else {
            $file = $_FILES['equipment_image'];
            $file_size = $file['size'];
            $file_type = $file['type'];
            $file_tmp = $file['tmp_name'];
            $file_name = $file['name'];

            // Validate file type
            $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
            if (!in_array($file_type, $allowed_types)) {
                $error = 'Invalid file type. Please upload JPEG, PNG, or GIF images only.';
            }
            // Validate file size (5MB limit)
            elseif ($file_size > 5242880) {
                $error = 'Image size must be less than 5MB.';
            }
            else {
                // Create upload directory if it doesn't exist
                $upload_dir = '../uploads/equipment_images/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }

                // Generate unique filename
                $file_extension = pathinfo($file_name, PATHINFO_EXTENSION);
                $new_filename = 'equip_' . uniqid() . '_' . time() . '.' . $file_extension;
                $upload_path = $upload_dir . $new_filename;

                // Move uploaded file
                if (move_uploaded_file($file_tmp, $upload_path)) {
                    $uploaded_file_path = 'uploads/equipment_images/' . $new_filename;
                } else {
                    $error = 'Failed to upload image. Please try again.';
                }
            }
        }
    }

    // Insert equipment if no errors
    if (empty($error)) {
        try {
            $conn->begin_transaction();

            // Insert equipment
            $equipment_sql = "INSERT INTO equipment (Owner_id, Subcategories_id, Title, Brand, Model, Year, Description, Hourly_rate, Daily_rate, Approval_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'PEN')";
            $stmt = $conn->prepare($equipment_sql);
            
            if (!$stmt) {
                throw new Exception('Equipment prepare failed: ' . $conn->error);
            }
            
            $stmt->bind_param("iisssisdd", $owner_id, $subcategory_id, $title, $brand, $model, $year, $description, $hourly_rate, $daily_rate);
            
            if (!$stmt->execute()) {
                throw new Exception('Equipment execute failed: ' . $stmt->error);
            }
            
            $equipment_id = $stmt->insert_id;
            $stmt->close();

            // Insert image record
            $image_sql = "INSERT INTO images (image_type, ID, image_url) VALUES ('E', ?, ?)";
            $img_stmt = $conn->prepare($image_sql);
            
            if (!$img_stmt) {
                throw new Exception('Image prepare failed: ' . $conn->error);
            }
            
            $img_stmt->bind_param("is", $equipment_id, $uploaded_file_path);
            
            if (!$img_stmt->execute()) {
                throw new Exception('Image execute failed: ' . $img_stmt->error);
            }
            
            $img_stmt->close();
            $conn->commit();
            
            $message = 'Equipment added successfully with image! It will be reviewed by admin for approval.';
            // Clear form data
            $_POST = [];
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = 'Database error: ' . $e->getMessage();
            error_log("Equipment add error: " . $e->getMessage());
        }
    }
}

require 'oheader.php';
require 'owner_nav.php';
?>

<link rel="stylesheet" href="../admin.css">

<div class="main-content">
    <h1>Add New Equipment</h1>
    <p style="color: #666; margin-bottom: 30px;">List your agricultural equipment for rental with photos</p>

    <?php if ($message): ?>
        <div class="message"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="form-section">
        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label for="title">Equipment Title *</label>
                <input type="text" id="title" name="title" value="<?= htmlspecialchars($_POST['title'] ?? '') ?>" required>
            </div>

            <div class="form-group">
                <label for="brand">Brand *</label>
                <input type="text" id="brand" name="brand" value="<?= htmlspecialchars($_POST['brand'] ?? '') ?>" required>
            </div>

            <div class="form-group">
                <label for="model">Model *</label>
                <input type="text" id="model" name="model" value="<?= htmlspecialchars($_POST['model'] ?? '') ?>" required>
            </div>

            <div class="form-group">
                <label for="year">Manufacturing Year</label>
                <input type="number" id="year" name="year" min="1900" max="<?= date('Y') ?>" value="<?= htmlspecialchars($_POST['year'] ?? '') ?>">
            </div>

            <!-- Main Category Selection -->
            <div class="form-group">
                <label for="category_id">Equipment Category *</label>
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
                </select>
            </div>

            <!-- Equipment Image Upload -->
            <div class="form-group">
                <label for="equipment_image">Equipment Photo *</label>
                <input type="file" id="equipment_image" name="equipment_image" accept="image/*" required>
                <small style="color: #666; display: block; margin-top: 5px;">
                    Upload a clear photo of your equipment (JPEG, PNG, GIF - Max 5MB)
                </small>
                <div id="image_preview" style="margin-top: 10px;"></div>
            </div>

            <div class="form-group">
                <label for="description">Description *</label>
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
                <button type="submit" class="btn">📷 Add Equipment with Photo</button>
                <a href="manage_equipment.php" class="btn" style="background: #6c757d; margin-left: 10px;">Cancel</a>
            </div>
        </form>
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

// Image preview functionality
document.getElementById('equipment_image').addEventListener('change', function(e) {
    const file = e.target.files[0];
    const preview = document.getElementById('image_preview');
    
    if (file) {
        // Validate file type
        const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
        if (!allowedTypes.includes(file.type)) {
            alert('Please select a valid image file (JPEG, PNG, GIF)');
            this.value = '';
            preview.innerHTML = '';
            return;
        }
        
        // Validate file size (5MB)
        if (file.size > 5242880) {
            alert('Image size must be less than 5MB');
            this.value = '';
            preview.innerHTML = '';
            return;
        }
        
        // Show preview
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.innerHTML = `
                <div style="border: 1px solid #ddd; padding: 10px; border-radius: 4px; display: inline-block;">
                    <img src="${e.target.result}" alt="Equipment Preview" style="max-width: 200px; max-height: 150px; object-fit: cover;">
                    <p style="margin: 5px 0 0 0; font-size: 12px; color: #666;">Preview: ${file.name}</p>
                </div>
            `;
        };
        reader.readAsDataURL(file);
    } else {
        preview.innerHTML = '';
    }
});

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

.form-group input[type="file"] {
    padding: 8px;
    background: white;
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

#image_preview {
    text-align: center;
}
</style>
