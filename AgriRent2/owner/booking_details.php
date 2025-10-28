<?php
session_start();
require_once('../auth/config.php');

// Check if user is logged in and is Equipment Owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'O') {
    header('Location: ../login.php');
    exit();
}

$owner_id = $_SESSION['user_id'];
$booking_id = intval($_GET['id'] ?? 0);

if ($booking_id <= 0) {
    header('Location: equipment_bookings.php');
    exit();
}

// Handle booking actions (approve/reject) - UPDATED to handle both PEN and CON status
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action_booking_id = intval($_GET['id']);
    $action = $_GET['action'];
    
    if (in_array($action, ['approve', 'reject'])) {
        $new_status = ($action == 'approve') ? 'CON' : 'REJ';
        
        // Verify booking belongs to this owner before updating
        $verify_stmt = $conn->prepare("SELECT eb.booking_id, eb.status FROM equipment_bookings eb 
                                      JOIN equipment e ON eb.equipment_id = e.Equipment_id 
                                      WHERE eb.booking_id = ? AND e.Owner_id = ?");
        $verify_stmt->bind_param('ii', $action_booking_id, $owner_id);
        $verify_stmt->execute();
        $verify_result = $verify_stmt->get_result();
        
        if ($verify_result->num_rows > 0) {
            $existing_booking = $verify_result->fetch_assoc();
            
            // Allow reject action for both PEN and CON status
            if ($action == 'reject' || ($action == 'approve' && $existing_booking['status'] == 'PEN')) {
                $update_stmt = $conn->prepare('UPDATE equipment_bookings SET status = ? WHERE booking_id = ?');
                $update_stmt->bind_param('si', $new_status, $action_booking_id);
                
                if ($update_stmt->execute()) {
                    $action_message = ($action == 'approve') ? 'approved' : 'rejected';
                    $message = "Booking {$action_message} successfully.";
                } else {
                    $message = "Error updating booking status.";
                }
                $update_stmt->close();
            } else {
                $message = "Cannot approve an already confirmed booking.";
            }
        }
        $verify_stmt->close();
        
        header('Location: booking_details.php?id=' . $booking_id . ($message ? '&msg=' . urlencode($message) : ''));
        exit();
    }
}

// Display message from action
$message = $_GET['msg'] ?? '';

// Fetch booking details with equipment image using your table structure
$booking = null;
$stmt = $conn->prepare("
    SELECT eb.*, e.Title as equipment_title, e.Brand, e.Model, e.Hourly_rate, e.Daily_rate,
           u.Name as customer_name, u.Email as customer_email, u.Phone as customer_phone,
           ua.address, ua.city, ua.state, ua.Pin_code,
           ec.Name as category_name, es.Subcategory_name,
           i.image_url
    FROM equipment_bookings eb
    JOIN equipment e ON eb.equipment_id = e.Equipment_id
    JOIN users u ON eb.customer_id = u.user_id
    LEFT JOIN user_addresses ua ON u.user_id = ua.user_id
    LEFT JOIN equipment_subcategories es ON e.Subcategories_id = es.Subcategory_id
    LEFT JOIN equipment_categories ec ON es.Category_id = ec.category_id
    LEFT JOIN images i ON (i.image_type = 'E' AND i.ID = e.Equipment_id)
    WHERE eb.booking_id = ? AND e.Owner_id = ?
");
$stmt->bind_param("ii", $booking_id, $owner_id);
$stmt->execute();
$result = $stmt->get_result();
$booking = $result->fetch_assoc();
$stmt->close();

if (!$booking) {
    header('Location: equipment_bookings.php');
    exit();
}

// Extract start_time and end_time from time_slot
if (!empty($booking['time_slot']) && strpos($booking['time_slot'], '-') !== false) {
    list($booking['start_time'], $booking['end_time']) = array_map('trim', explode('-', $booking['time_slot']));
} else {
    $booking['start_time'] = null;
    $booking['end_time'] = null;
}

require 'oheader.php';
require 'owner_nav.php';
?>

<link rel="stylesheet" href="../admin.css">
<style>
.btn-approve {
    background: #28a745 !important;
    color: white;
    margin-left: 10px;
}
.btn-approve:hover {
    background: #218838 !important;
}
.btn-reject {
    background: #dc3545 !important;
    color: white;
    margin-left: 10px;
}
.btn-reject:hover {
    background: #c82333 !important;
}
.alert-message {
    background: #d4edda;
    color: #155724;
    padding: 12px 16px;
    border-radius: 6px;
    margin-bottom: 20px;
    border: 1px solid #c3e6cb;
}
</style>

<div class="main-content">
    <h1>Booking Details</h1>
    <p style="color: #666; margin-bottom: 30px;">Complete information about this booking request</p>
    
    <?php if ($message): ?>
        <div class="alert-message"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    
    <!-- UPDATED Action Buttons with Reject for Confirmed Bookings -->
    <div class="quick-actions" style="margin-bottom: 30px;">
        <a href="equipment_bookings.php" class="action-btn">&larr; Back to Bookings</a>
        
        <?php if ($booking['status'] == 'PEN'): ?>
            <!-- Pending: Show both Approve and Reject -->
            <a href="booking_details.php?action=approve&id=<?= $booking_id ?>" 
               onclick="return confirm('Are you sure you want to approve this booking?')" 
               class="action-btn btn-approve"> Approve</a>
            <a href="booking_details.php?action=reject&id=<?= $booking_id ?>" 
               onclick="return confirm('Are you sure you want to reject this booking?')" 
               class="action-btn btn-reject"> Reject</a>
               
        <?php elseif ($booking['status'] == 'CON'): ?>
            <!-- Confirmed: Show Reject button for accidental approvals -->
            <span style="background: #28a745; color: white; padding:  8px 16px; border-radius: 4px; margin-left: 10px;  ">
                <strong>Currently Approved</strong>
            </span>
            <a href="booking_details.php?action=reject&id=<?= $booking_id ?>" 
               onclick="return confirm('Are you sure you want to reject this approved booking? This action will cancel the booking.')" 
               class="action-btn btn-reject"> Reject Booking</a>
               
        <?php elseif ($booking['status'] == 'REJ'): ?>
            <!-- Rejected: Show status only -->
            <span style="background: #dc3545; color: white; padding: 8px 16px; border-radius: 4px; margin-left: 10px;">
                 Rejected
            </span>
        <?php endif; ?>
    </div>

    <!-- Equipment Photo and Overview Section -->
    <div style="background: white; padding: 25px; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); margin-bottom: 30px;">
        <div style="display: flex; align-items: flex-start; gap: 25px;">
            <!-- Equipment Image -->
            <div style="flex-shrink: 0;">
                <?php 
                $image_url = !empty($booking['image_url']) ? htmlspecialchars($booking['image_url'], ENT_QUOTES, 'UTF-8') : null;
                if ($image_url): ?>
                    <img src="../<?= $image_url ?>" 
                         alt="<?= htmlspecialchars($booking['equipment_title'], ENT_QUOTES, 'UTF-8') ?>" 
                         style="max-width: 320px; max-height: 280px; border-radius: 10px; object-fit: cover; border: 2px solid #e0e0e0; box-shadow: 0 2px 8px rgba(0,0,0,0.1);" />
                <?php else: ?>
                    <div style="width: 320px; height: 280px; background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); border: 2px solid #e0e0e0; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: #666;">
                        <div style="text-align: center; padding: 20px;">
                            <div style="font-size: 4rem; margin-bottom: 15px; opacity: 0.4;">📷</div>
                            <div style="font-size: 16px; font-weight: 500;">No Image Available</div>
                            <div style="font-size: 14px; color: #999; margin-top: 5px;">Equipment photo not uploaded</div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Equipment Overview Info -->
            <div style="flex-grow: 1;">
                <h2 style="color: #2d4a22; margin-bottom: 20px; font-size: 2rem; font-weight: 700;">
                    <?= htmlspecialchars($booking['equipment_title'], ENT_QUOTES, 'UTF-8') ?>
                </h2>
                
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; font-size: 16px; line-height: 1.6;">
                    <div style="background: #f8f9fa; padding: 12px; border-radius: 6px;">
                        <strong style="color: #495057;">Brand:</strong> 
                        <span style="color: #2d4a22; font-weight: 600;"><?= htmlspecialchars($booking['Brand'], ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <div style="background: #f8f9fa; padding: 12px; border-radius: 6px;">
                        <strong style="color: #495057;">Model:</strong> 
                        <span style="color: #2d4a22; font-weight: 600;"><?= htmlspecialchars($booking['Model'], ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <div style="background: #f8f9fa; padding: 12px; border-radius: 6px;">
                        <strong style="color: #495057;">Category:</strong> 
                        <span style="color: #2d4a22; font-weight: 600;"><?= htmlspecialchars($booking['Subcategory_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <div style="background: #f8f9fa; padding: 12px; border-radius: 6px;">
                        <strong style="color: #495057;">Status:</strong> 
                        <?php
                        $status_map = [
                            'CON' => ['status-confirmed', 'Confirmed'],
                            'PEN' => ['status-pending', 'Pending Approval'],
                            'REJ' => ['status-rejected', 'Rejected']
                        ];
                        list($status_class, $status_text) = $status_map[$booking['status']] ?? ['', 'Unknown'];
                        ?>
                        <span class="status-badge <?= $status_class ?>"><?= $status_text ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="report-sections">
        <!-- Booking Information -->
        <div class="report-section">
            <h3>Booking Information</h3>
            <div class="booking-details">
                <div class="detail-row">
                    <strong>Booking ID:</strong> #<?= $booking['booking_id'] ?>
                </div>
                <div class="detail-row">
                    <strong>Rental Period:</strong><br>
                    <strong>From: </strong> <?= date('M j, Y', strtotime($booking['start_date'])) ?>
                    <?php if ($booking['start_time']): ?>
                        at <strong style="color: #2d4a22;"><?= htmlspecialchars($booking['start_time'], ENT_QUOTES, 'UTF-8') ?></strong>
                    <?php endif; ?><br>
                    <strong>To:</strong> <?= date('M j, Y', strtotime($booking['end_date'])) ?>
                    <?php if ($booking['end_time']): ?>
                        at <strong style="color: #2d4a22;"><?= htmlspecialchars($booking['end_time'], ENT_QUOTES, 'UTF-8') ?></strong>
                    <?php endif; ?>
                </div>
                <div class="detail-row">
                    <strong>Duration:</strong> <?= htmlspecialchars($booking['Hours'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?> hours
                </div>
                <div class="detail-row">
                    <strong>Total Amount:</strong> 
                    <span style="font-size: 20px; color: #28a745; font-weight: bold;">
                        ₹<?= number_format($booking['total_amount'], 2) ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Equipment Pricing Information -->
        <div class="report-section">
            <h3>Equipment Pricing</h3>
            <div class="booking-details">
                <div class="detail-row">
                    <strong>Hourly Rate:</strong> ₹<?= number_format($booking['Hourly_rate'] ?? 0, 2) ?>/hour
                </div>
                <div class="detail-row">
                    <strong>Daily Rate:</strong> ₹<?= number_format($booking['Daily_rate'] ?? 0, 2) ?>/day (12 hours)
                </div>
            </div>
        </div>

        <!-- Customer Information -->
        <div class="report-section">
            <h3>Customer Information</h3>
            <div class="booking-details">
                <div class="detail-row">
                    <strong>Name:</strong> <?= htmlspecialchars($booking['customer_name'], ENT_QUOTES, 'UTF-8') ?>
                </div>
                <div class="detail-row">
                    <strong>Phone:</strong> 
                    <a href="tel:<?= htmlspecialchars($booking['customer_phone'], ENT_QUOTES, 'UTF-8') ?>" 
                       style="color: #0d6efd; text-decoration: none; font-weight: bold;">
                        <?= htmlspecialchars($booking['customer_phone'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                </div>
                <div class="detail-row">
                    <strong>Email:</strong> 
                    <a href="mailto:<?= htmlspecialchars($booking['customer_email'], ENT_QUOTES, 'UTF-8') ?>" 
                       style="color: #0d6efd; text-decoration: none; font-weight: bold;">
                        <?= htmlspecialchars($booking['customer_email'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                </div>
                <?php if ($booking['address']): ?>
                <div class="detail-row">
                    <strong>Address:</strong><br>
                    <?= htmlspecialchars($booking['address'], ENT_QUOTES, 'UTF-8') ?><br>
                    <?= htmlspecialchars($booking['city'], ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars($booking['state'], ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars($booking['Pin_code'], ENT_QUOTES, 'UTF-8') ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php 
require 'ofooter.php';
?>
