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

// Fetch equipment data
$equipment = null;
$stmt = $conn->prepare("SELECT * FROM equipment WHERE Equipment_id = ? AND Owner_id = ?");
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
        $error = 'Please select a valid subcategory.';
    } elseif ($hourly_rate <= 0 && $daily_rate <= 0) {
        $error = 'Please enter at least one rate (hourly or daily).';
    } else {
        // Update equipment (reset to pending approval if changed)
        $stmt = $conn->prepare("UPDATE equipment SET Subcategories_id = ?, Title = ?, Brand = ?, Model = ?, Year = ?, Description = ?, Hourly_rate = ?, Daily_rate = ?, Approval_status = 'PEN' WHERE Equipment_id = ? AND Owner_id = ?");
        $stmt->bind_param("isssissdii", $subcategory_id, $title, $brand, $model, $year, $description, $hourly_rate, $daily_rate, $equipment_id, $owner_id);
        
        if ($stmt->execute()) {
            $message = 'Equipment updated successfully! It will be reviewed by admin for approval.';
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

require 'oheader.php';
require 'owner_nav.php';
?>

<link rel="stylesheet" href="../assets/css/equipment.css">

<div class="main-content">
    <h1>Edit Equipment</h1>
    <p style="color: #666; margin-bottom: 30px;">Update your equipment information</p>

    <?php if ($message): ?>
        <div class="message"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="form-section">
        <form method="POST">
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

<?php 
    require 'ofooter.php';
?>
