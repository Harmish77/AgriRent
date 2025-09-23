<?php
session_start();
require_once('../auth/config.php');

// Check if user is logged in and is Equipment Owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'O') {
    header('Location: ../login.php');
    exit();
}

$owner_id = $_SESSION['user_id'];
$equipment_id = intval($_GET['id'] ?? 0);
$message = '';
$error = '';

if ($equipment_id <= 0) {
    header('Location: manage_equipment.php');
    exit();
}

// Fetch equipment data with image
$equipment = null;
$stmt = $conn->prepare("SELECT e.*, i.image_url FROM equipment e LEFT JOIN images i ON (i.image_type = 'E' AND i.ID = e.Equipment_id) WHERE e.Equipment_id = ? AND e.Owner_id = ?");
$stmt->bind_param("ii", $equipment_id, $owner_id);
$stmt->execute();
$result = $stmt->get_result();
$equipment = $result->fetch_assoc();
$stmt->close();

if (!$equipment) {
    header('Location: manage_equipment.php');
    exit();
}

// Fetch categories and subcategories
$subcategories = [];
$subcat_result = $conn->query("SELECT Subcategory_id, Subcategory_name FROM equipment_subcategories ORDER BY Subcategory_name");
while ($subcat = $subcat_result->fetch_assoc()) {
    $subcategories[] = $subcat;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
        $error = 'Please select a valid subcategory.';
    } elseif ($hourly_rate <= 0 && $daily_rate <= 0) {
        $error = 'Please enter at least one rate (hourly or daily).';
    } else {
        // Handle image upload if new image is provided
        $new_image_uploaded = false;
        if (isset($_FILES['new_image']) && $_FILES['new_image']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['new_image'];
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
                    // Delete old image file if exists
                    if (!empty($equipment['image_url']) && file_exists('../' . $equipment['image_url'])) {
                        unlink('../' . $equipment['image_url']);
                    }

                    // Update or insert image record
                    $new_image_url = 'uploads/equipment_images/' . $new_filename;
                    
                    if (!empty($equipment['image_url'])) {
                        // Update existing image record
                        $img_stmt = $conn->prepare("UPDATE images SET image_url = ? WHERE image_type = 'E' AND ID = ?");
                        $img_stmt->bind_param('si', $new_image_url, $equipment_id);
                    } else {
                        // Insert new image record
                        $img_stmt = $conn->prepare("INSERT INTO images (image_type, ID, image_url) VALUES ('E', ?, ?)");
                        $img_stmt->bind_param('is', $equipment_id, $new_image_url);
                    }
                    
                    if ($img_stmt->execute()) {
                        $equipment['image_url'] = $new_image_url;
                        $new_image_uploaded = true;
                    }
                    $img_stmt->close();
                } else {
                    $error = 'Failed to upload image. Please try again.';
                }
            }
        }

        // Update equipment if no errors
        if (empty($error)) {
            $stmt = $conn->prepare("UPDATE equipment SET Subcategories_id = ?, Title = ?, Brand = ?, Model = ?, Year = ?, Description = ?, Hourly_rate = ?, Daily_rate = ?, Approval_status = 'PEN' WHERE Equipment_id = ? AND Owner_id = ?");
            $stmt->bind_param("isssissdii", $subcategory_id, $title, $brand, $model, $year, $description, $hourly_rate, $daily_rate, $equipment_id, $owner_id);
            
            if ($stmt->execute()) {
                $success_msg = 'Equipment updated successfully!';
                if ($new_image_uploaded) {
                    $success_msg .= ' New image uploaded.';
                }
                $success_msg .= ' It will be reviewed by admin for approval.';
                $message = $success_msg;
                
                // Refresh equipment data
                $equipment['Title'] = $title;
                $equipment['Brand'] = $brand;
                $equipment['Model'] = $model;
                $equipment['Year'] = $year;
                $equipment['Subcategories_id'] = $subcategory_id;
                $equipment['Description'] = $description;
                $equipment['Hourly_rate'] = $hourly_rate;
                $equipment['Daily_rate'] = $daily_rate;
            } else {
                $error = 'Error updating equipment. Please try again.';
            }
            $stmt->close();
        }
    }
}

require 'oheader.php';
require 'owner_nav.php';
?>

<link rel="stylesheet" href="../assets/css/equipment.css">

<div class="main-content">
    <h1>Edit Equipment</h1>
    <p style="color: #666; margin-bottom: 30px;">Update your equipment information and photo</p>

    <?php if ($message): ?>
        <div class="message"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Current Equipment Image -->
    <?php if (!empty($equipment['image_url'])): ?>
    <div class="form-section" style="margin-bottom: 20px;">
        <h3>Current Equipment Photo</h3>
        <div style="text-align: center; padding: 20px;">
            <img src="../<?= htmlspecialchars($equipment['image_url']) ?>" 
                 alt="<?= htmlspecialchars($equipment['Title']) ?>" 
                 style="max-width: 300px; max-height: 200px; border: 1px solid #ddd; border-radius: 8px; cursor: pointer;"
                 onclick="openImageModal(this)">
            <p style="margin-top: 10px; color: #666; font-size: 12px;">Click image to view full size</p>
        </div>
    </div>
    <?php endif; ?>

    <div class="form-section">
        <form method="POST" enctype="multipart/form-data">
            
            <!-- Image Upload Field -->
            <div class="form-group">
                <label for="new_image">Upload New Image</label>
                <input type="file" id="new_image" name="new_image" accept="image/*">
                <small style="color: #666; display: block; margin-top: 5px;">
                    Leave empty to keep current image. Max size: 5MB. Allowed types: JPEG, PNG, GIF.
                </small>
            </div>

            <div class="form-group">
                <label for="title">Equipment Title *</label>
                <input type="text" id="title" name="title" value="<?= htmlspecialchars($_POST['title'] ?? $equipment['Title']) ?>" required>
            </div>

            <div class="form-group">
                <label for="brand">Brand *</label>
                <input type="text" id="brand" name="brand" value="<?= htmlspecialchars($_POST['brand'] ?? $equipment['Brand']) ?>" required>
            </div>

            <div class="form-group">
                <label for="model">Model *</label>
                <input type="text" id="model" name="model" value="<?= htmlspecialchars($_POST['model'] ?? $equipment['Model']) ?>" required>
            </div>

            <div class="form-group">
                <label for="year">Manufacturing Year</label>
                <input type="number" id="year" name="year" min="1900" max="<?= date('Y') ?>" value="<?= htmlspecialchars($_POST['year'] ?? $equipment['Year']) ?>">
            </div>

            <div class="form-group">
                <label for="subcategory_id">Category *</label>
                <select id="subcategory_id" name="subcategory_id" required>
                    <option value="">Select Equipment Category</option>
                    <?php foreach ($subcategories as $subcat): ?>
                        <option value="<?= $subcat['Subcategory_id'] ?>" <?= (($_POST['subcategory_id'] ?? $equipment['Subcategories_id']) == $subcat['Subcategory_id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($subcat['Subcategory_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="description">Description *</label>
                <textarea id="description" name="description" rows="4" required><?= htmlspecialchars($_POST['description'] ?? $equipment['Description']) ?></textarea>
            </div>

            <div class="form-group">
                <label for="hourly_rate">Hourly Rate (₹)</label>
                <input type="number" id="hourly_rate" name="hourly_rate" step="0.01" min="0" value="<?= htmlspecialchars($_POST['hourly_rate'] ?? $equipment['Hourly_rate']) ?>">
            </div>

            <div class="form-group">
                <label for="daily_rate">Daily Rate (₹)</label>
                <input type="number" id="daily_rate" name="daily_rate" step="0.01" min="0" value="<?= htmlspecialchars($_POST['daily_rate'] ?? $equipment['Daily_rate']) ?>">
            </div>

            <div class="form-group">
                <button type="submit" class="btn">Update Equipment</button>
                <a href="manage_equipment.php" class="btn" style="background: #6c757d; margin-left: 10px;">Cancel</a>
                <a href="view_equipment.php?id=<?= $equipment_id ?>" class="btn" style="background: #17a2b8; margin-left: 10px;">View Details</a>
            </div>
        </form>
    </div>

    <!-- Current Status -->
    <div class="report-sections" style="margin-top: 30px;">
        <div class="report-section">
            <h3>Current Status</h3>
            <div class="status-list">
                <div>
                    Status: 
                    <?php
                    $status_map = [
                        'CON' => ['status-confirmed', 'Approved'],
                        'PEN' => ['status-pending', 'Pending'],
                        'REJ' => ['status-rejected', 'Rejected']
                    ];
                    list($status_class, $status_text) = $status_map[$equipment['Approval_status']] ?? ['', 'Unknown'];
                    ?>
                    <span class="status-badge <?= $status_class ?>"><?= $status_text ?></span>
                </div>
                <div>Listed Date: <strong><?= date('M j, Y', strtotime($equipment['listed_date'])) ?></strong></div>
            </div>
        </div>
    </div>
</div>

<!-- Image Modal -->
<div id="imageModal" class="modal" style="display: none;">
    <span class="close" onclick="closeImageModal()">&times;</span>
    <img class="modal-content" id="modalImage">
</div>

<style>
/* Modal Styles */
.modal {
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    overflow: auto;
    background-color: rgba(0,0,0,0.9);
}

.modal-content {
    margin: auto;
    display: block;
    max-width: 90%;
    max-height: 80%;
    margin-top: 5%;
}

.close {
    position: absolute;
    top: 20px;
    right: 35px;
    color: #fff;
    font-size: 40px;
    font-weight: bold;
    cursor: pointer;
}

.close:hover {
    color: #bbb;
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

.form-group small {
    font-size: 12px;
    margin-top: 5px;
}
</style>

<script>
function openImageModal(img) {
    const modal = document.getElementById('imageModal');
    const modalImg = document.getElementById('modalImage');
    
    modal.style.display = "block";
    modalImg.src = img.src;
}

function closeImageModal() {
    document.getElementById('imageModal').style.display = "none";
}

// Close modal when clicking outside the image
window.onclick = function(event) {
    const modal = document.getElementById('imageModal');
    if (event.target == modal) {
        modal.style.display = "none";
    }
}
</script>

<?php 
    require 'ofooter.php';
?>
