<?php
session_start();
require_once 'auth/config.php';

if (!isset($_SESSION['logged_in']) || !isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Check for message from URL parameter (e.g., from order page redirect)
if (isset($_GET['message']) && !empty($_GET['message'])) {
    $message = trim($_GET['message']);
}

$allowed_district = "Surat"; // Replace with your allowed district name
$allowed_state = "Gujarat"; // Replace with your allowed state name

// Handle add address submission
if (isset($_POST['add_address'])) {
    $address = trim($_POST['address']);
    $city = $allowed_district; // Fixed value
    $state = $allowed_state; // Fixed value
    $pincode = trim($_POST['pincode']);

    if (empty($address) || empty($pincode)) {
        $error = "Address and pin code are required.";
    } elseif (!preg_match('/^\d{6}$/', $pincode)) {
        $error = "Pin code must be a 6-digit number.";
    } else {
        $stmt = $conn->prepare("INSERT INTO user_addresses (user_id, address, city, state, Pin_code) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("issss", $user_id, $address, $city, $state, $pincode);

        if ($stmt->execute()) {
            $message = "Address added successfully.";
        } else {
            $error = "Failed to add address.";
        }
        $stmt->close();
    }
}

// Handle edit address submission
if (isset($_POST['edit_address'])) {
    $address_id = intval($_POST['address_id']);
    $address = trim($_POST['address']);
    $pincode = trim($_POST['pincode']);
    
    if (empty($address) || empty($pincode)) {
        $error = "Address and pin code are required.";
    } elseif (!preg_match('/^\d{6}$/', $pincode)) {
        $error = "Pin code must be a 6-digit number.";
    } else {
        $stmt = $conn->prepare("UPDATE user_addresses SET address = ?, Pin_code = ? WHERE address_id = ? AND user_id = ?");
        $stmt->bind_param("ssii", $address, $pincode, $address_id, $user_id);
        
        if ($stmt->execute()) {
            $message = "Address updated successfully.";
        } else {
            $error = "Failed to update address.";
        }
        $stmt->close();
    }
}

// Handle delete address
if (isset($_POST['delete_address'])) {
    $address_id = intval($_POST['address_id']);
    $stmt = $conn->prepare("DELETE FROM user_addresses WHERE address_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $address_id, $user_id);
    
    if ($stmt->execute()) {
        $message = "Address deleted successfully.";
    } else {
        $error = "Failed to delete address.";
    }
    $stmt->close();
}

// Fetch user addresses
$addresses_query = "SELECT address_id, address, city, state, Pin_code FROM user_addresses WHERE user_id = ?";
$addresses_stmt = $conn->prepare($addresses_query);
$addresses_stmt->bind_param("i", $user_id);
$addresses_stmt->execute();
$addresses_result = $addresses_stmt->get_result();

// Fetch user data for header
$stmt = $conn->prepare("SELECT Name FROM users WHERE user_id=?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

require 'includes/header.php';
require 'includes/navigation.php';
?>

<div class="account-container">
    <div class="profile-page">
        <h1>My Addresses</h1>

        <?php if ($message): ?>
            <div class="success-message"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="error-message"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="profile-card">
            <div class="profile-header">
                <div class="profile-avatar"><?= strtoupper(substr($user['Name'], 0, 2)) ?></div>
                <div class="profile-info">
                    <h2><?= htmlspecialchars($user['Name']) ?></h2>
                    <p>Address Management</p>
                </div>
            </div>

            <!-- Existing Addresses -->
            <?php if ($addresses_result->num_rows > 0): ?>
                <h2 style="margin-top: 30px;">Your Saved Addresses</h2>
                <div style="margin-bottom: 30px;">
                    <?php while ($address = $addresses_result->fetch_assoc()): ?>
                        <div class="profile-form" style="margin-bottom: 20px; padding: 20px; border: 1px solid #ddd; border-radius: 8px;" id="address-<?= $address['address_id'] ?>">
                            <div style="display: flex; justify-content: space-between; align-items: start;">
                                <div style="flex: 1;">
                                    <h4 style="margin: 0 0 10px 0; color: #333;"><?= htmlspecialchars($address['address']) ?></h4>
                                    <p style="margin: 0; color: #666;">
                                        <?= htmlspecialchars($address['city']) ?>, <?= htmlspecialchars($address['state']) ?> - <?= htmlspecialchars($address['Pin_code']) ?>
                                    </p>
                                </div>
                                <div style="display: flex; gap: 10px;">
                                    <button type="button" onclick="editAddress(<?= $address['address_id'] ?>, '<?= htmlspecialchars($address['address'], ENT_QUOTES) ?>', '<?= $address['Pin_code'] ?>')" 
                                            class="btn-primary" style="background: #28a745; color: white; padding: 8px 15px; border: none; border-radius: 4px; cursor: pointer;">
                                        Edit
                                    </button>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this address?')">
                                        <input type="hidden" name="address_id" value="<?= $address['address_id'] ?>">
                                        <button type="submit" name="delete_address" class="btn-secondary" style="background: #dc3545; color: white; padding: 8px 15px; border: none; border-radius: 4px; cursor: pointer;">
                                            Delete
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>
                <div style="text-align: center; padding: 30px; background: #f8f9fa; border-radius: 8px; margin: 20px 0;">
                    <h3 style="color: #666; margin-bottom: 10px;">No addresses saved</h3>
                    <p style="color: #666;">Add your first delivery address below.</p>
                </div>
            <?php endif; ?>

            <!-- Add/Edit New Address Form -->
            <h2 style="margin-top: 40px;" id="form-title">Add New Address</h2>

            <form method="POST" class="profile-form" style="margin-top: 20px;" id="address-form">
                <input type="hidden" name="address_id" id="address-id" value="">
                
                <div class="form-group">
                    <label for="address">Address *</label>
                    <input type="text" id="address" name="address" required>
                </div>

                <div class="form-group">
                    <label for="city">City (District)</label>
                    <input type="text" id="city" name="city" value="<?= $allowed_district ?>" readonly style="background-color: #f8f9fa;">
                </div>

                <div class="form-group">
                    <label for="state">State</label>
                    <input type="text" id="state" name="state" value="<?= $allowed_state ?>" readonly style="background-color: #f8f9fa;">
                </div>

                <div class="form-group">
                    <label for="pincode">Pin Code *</label>
                    <input type="text" id="pincode" name="pincode" maxlength="6" required>
                </div>

                <div class="form-buttons">
                    <button type="submit" name="add_address" id="submit-btn" class="btn-primary">Add Address</button>
                    <button type="button" onclick="cancelEdit()" id="cancel-btn" class="btn-secondary" style="display: none;">Cancel</button>
                    <a href="account.php" class="btn-secondary">Back to Profile</a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editAddress(addressId, addressText, pincode) {
    // Switch to edit mode
    document.getElementById('form-title').textContent = 'Edit Address';
    document.getElementById('address-id').value = addressId;
    document.getElementById('address').value = addressText;
    document.getElementById('pincode').value = pincode;
    
    // Change submit button
    const submitBtn = document.getElementById('submit-btn');
    submitBtn.name = 'edit_address';
    submitBtn.textContent = 'Update Address';
    
    // Show cancel button
    document.getElementById('cancel-btn').style.display = 'inline-block';
    
    // Scroll to form
    document.getElementById('address-form').scrollIntoView({ behavior: 'smooth' });
}

function cancelEdit() {
    // Reset to add mode
    document.getElementById('form-title').textContent = 'Add New Address';
    document.getElementById('address-id').value = '';
    document.getElementById('address').value = '';
    document.getElementById('pincode').value = '';
    
    // Change submit button back
    const submitBtn = document.getElementById('submit-btn');
    submitBtn.name = 'add_address';
    submitBtn.textContent = 'Add Address';
    
    // Hide cancel button
    document.getElementById('cancel-btn').style.display = 'none';
}
</script>

<?php require 'includes/footer.php'; ?>
