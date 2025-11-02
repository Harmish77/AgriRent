<?php
session_start();
require_once('../auth/config.php');

// Check if user is logged in and is a Farmer
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'F') {
    header('Location: ../login.php');
    exit();
}

$farmer_id = $_SESSION['user_id'];
$message = '';

// Handle booking cancellation
if (isset($_GET['action']) && isset($_GET['id'])) {
    $booking_id = intval($_GET['id']);
    $action = $_GET['action'];
    
    if ($action === 'cancel') {
        // Verify this booking belongs to the farmer and is still cancellable
        $verify_stmt = $conn->prepare("SELECT eb.booking_id, eb.status FROM equipment_bookings eb WHERE eb.booking_id = ? AND eb.customer_id = ?");
        
        if ($verify_stmt) {
            $verify_stmt->bind_param('ii', $booking_id, $farmer_id);
            $verify_stmt->execute();
            $verify_result = $verify_stmt->get_result();
            
            if ($verify_result->num_rows > 0) {
                $booking_data = $verify_result->fetch_assoc();
                
                // Check if booking can be cancelled (not completed and not already rejected/cancelled)
                if ($booking_data['status'] !== 'COM' && $booking_data['status'] !== 'REJ' && $booking_data['status'] !== 'CAN') {
                    $update_stmt = $conn->prepare("UPDATE equipment_bookings SET status = 'CAN' WHERE booking_id = ?");
                    
                    if ($update_stmt) {
                        $update_stmt->bind_param('i', $booking_id);
                        
                        if ($update_stmt->execute()) {
                            $message = "Booking cancelled successfully.";
                        } else {
                            $message = "Error cancelling booking: " . $conn->error;
                        }
                        $update_stmt->close();
                    } else {
                        $message = "Error preparing update statement: " . $conn->error;
                    }
                } else {
                    $message = "Error: This booking cannot be cancelled.";
                }
            } else {
                $message = "Booking not found or you don't have permission to cancel it.";
            }
            $verify_stmt->close();
        } else {
            $message = "Error preparing verification statement: " . $conn->error;
        }
        
        // Redirect to prevent resubmission
        header("Location: equipment_bookings.php" . ($message ? "?msg=" . urlencode($message) : ""));
        exit;
    }
}

// Display message from redirect
if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
}

// Filter by status
$status_filter = $_GET['status'] ?? 'all';

$where_clause = "WHERE eb.customer_id = ?";
$params = [$farmer_id];
$param_types = 'i';

if ($status_filter !== 'all') {
    $where_clause .= " AND eb.status = ?";
    $params[] = $status_filter;
    $param_types .= 's';
}

// Fetch bookings for this farmer with equipment images
$bookings = [];
try {
    // Enhanced query to include equipment images
    $query = "SELECT eb.booking_id, eb.equipment_id, eb.customer_id, eb.start_date, eb.end_date, 
                     eb.Hours, eb.total_amount, eb.status,
                     e.Title as equipment_title, e.Brand, e.Model, e.Hourly_rate, e.Daily_rate, e.Description,
                     owner.Name as owner_name, owner.Phone as owner_phone, owner.Email as owner_email,
                     (SELECT i.image_url FROM images i WHERE i.image_type = 'E' AND i.ID = e.Equipment_id LIMIT 1) as image_url
              FROM equipment_bookings eb
              JOIN equipment e ON eb.equipment_id = e.Equipment_id
              JOIN users owner ON e.Owner_id = owner.user_id
              $where_clause
              ORDER BY eb.booking_id DESC";
    
    $stmt = $conn->prepare($query);
    
    if (!$stmt) {
        die("SQL Prepare Error: " . $conn->error . "<br>Query: " . $query);
    }
    
    $stmt->bind_param($param_types, ...$params);
    
    if (!$stmt->execute()) {
        die("SQL Execute Error: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    
    if (!$result) {
        die("SQL Result Error: " . $conn->error);
    }
    
    while ($row = $result->fetch_assoc()) {
        $bookings[] = $row;
    }
    $stmt->close();
    
} catch (Exception $e) {
    die("Database Error: " . $e->getMessage());
}

// Calculate statistics
$total_bookings = count($bookings);
$pending_bookings = count(array_filter($bookings, fn($b) => $b['status'] === 'PEN'));
$confirmed_bookings = count(array_filter($bookings, fn($b) => $b['status'] === 'CON'));
$completed_bookings = count(array_filter($bookings, fn($b) => $b['status'] === 'COM'));
$rejected_bookings = count(array_filter($bookings, fn($b) => $b['status'] === 'REJ'));
$cancelled_bookings = count(array_filter($bookings, fn($b) => $b['status'] === 'CAN'));
$total_spent = array_sum(array_map(fn($b) => ($b['status'] === 'COM') ? $b['total_amount'] : 0, $bookings));

require 'fheader.php';
require 'farmer_nav.php';
?>

<link rel="stylesheet" href="../admin.css">

<div class="main-content">
    <h1>My Equipment Bookings</h1>
    <p style="color: #666; margin-bottom: 30px;">View and manage your equipment rental bookings</p>

    <?php if ($message): ?>
        <div class="message"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="cards" style="margin-bottom: 30px;">
        <div class="card">
            <h3>Total Bookings</h3>
            <div class="count"><?= $total_bookings ?></div>
        </div>
        <div class="card">
            <h3>Pending Requests</h3>
            <div class="count"><?= $pending_bookings ?></div>
        </div>
        <div class="card">
            <h3>Confirmed Bookings</h3>
            <div class="count"><?= $confirmed_bookings ?></div>
        </div>
        <div class="card">
            <h3>Completed Bookings</h3>
            <div class="count"><?= $completed_bookings ?></div>
        </div>
        <div class="card">
            <h3>Cancelled</h3>
            <div class="count"><?= $cancelled_bookings ?></div>
        </div>
        <div class="card">
            <h3>Total Spent</h3>
            <div class="count">₹<?= number_format($total_spent, 2) ?></div>
        </div>
    </div>

    <!-- Filter and Actions -->
    <div class="quick-actions" style="margin-bottom: 20px; display: flex; align-items: center; gap: 20px; flex-wrap: wrap;">
        <form method="GET" style="display: inline-block;">
            <select name="status" onchange="this.form.submit()" style="padding: 10px; border-radius: 4px; border: 1px solid #ddd; background: white;">
                <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Bookings</option>
                <option value="PEN" <?= $status_filter === 'PEN' ? 'selected' : '' ?>>Pending</option>
                <option value="CON" <?= $status_filter === 'CON' ? 'selected' : '' ?>>Confirmed</option>
                <option value="COM" <?= $status_filter === 'COM' ? 'selected' : '' ?>>Completed</option>
                <option value="REJ" <?= $status_filter === 'REJ' ? 'selected' : '' ?>>Rejected</option>
                <option value="CAN" <?= $status_filter === 'CAN' ? 'selected' : '' ?>>Cancelled</option>
            </select>
        </form>

        <!-- Live Search Box -->
        <div style="display: flex; align-items: center; gap: 10px;">
            <input type="text" id="bookingSearch" placeholder="Search bookings..." style="padding: 10px; border: 1px solid #ddd; border-radius: 4px; width: 300px;">
            <button type="button" id="clearSearch" style="padding: 10px 15px; border: 1px solid #ddd; border-radius: 4px; background: white; cursor: pointer;">Clear</button>
        </div>

        <span style="color: #666; font-size: 14px;">Showing <?= count($bookings) ?> bookings</span>
    </div>

    <!-- Bookings Table -->
    <?php if (count($bookings) > 0): ?>
        <table id="bookingsTable">
            <thead>
                <tr>
                    <th>Equipment</th>
                    <th>Owner</th>
                    <th>Contact</th>
                    <th>Start Date</th>
                    <th>End Date</th>
                    <th>Hours</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bookings as $booking): ?>
                    <?php
                    $status_map = [
                        'CON' => ['status-confirmed', 'Confirmed'],
                        'PEN' => ['status-pending', 'Pending'],
                        'REJ' => ['status-rejected', 'Rejected'],
                        'COM' => ['status-completed', 'Completed'],
                        'CAN' => ['status-cancelled', 'Cancelled']
                    ];
                    list($status_class, $status_text) = $status_map[$booking['status']] ?? ['', 'Unknown'];
                    ?>
                    <tr class="booking-row">
                        <td>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <!-- Equipment Image -->
                                <div style="width: 60px; height: 60px; border-radius: 8px; overflow: hidden; border: 2px solid #e9ecef; flex-shrink: 0;">
                                    <?php if (!empty($booking['image_url'])): ?>
                                        <img src="../<?= htmlspecialchars($booking['image_url']) ?>" 
                                             alt="<?= htmlspecialchars($booking['equipment_title']) ?>"
                                             style="width: 100%; height: 100%; object-fit: cover; cursor: pointer;"
                                             onclick="viewEquipmentImage('<?= htmlspecialchars($booking['image_url']) ?>', '<?= htmlspecialchars($booking['equipment_title']) ?>')">
                                    <?php else: ?>
                                        <div style="width: 100%; height: 100%; background: #f8f9fa; display: flex; align-items: center; justify-content: center; color: #6c757d;">
                                            <i class="fas fa-tractor" style="font-size: 20px;"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Equipment Details -->
                                <div>
                                    <strong style="color: #234a23; font-size: 16px;"><?= htmlspecialchars($booking['equipment_title']) ?></strong><br>
                                    <small style="color: #6c757d;"><?= htmlspecialchars($booking['Brand']) ?> <?= htmlspecialchars($booking['Model']) ?></small><br>
                                    <small style="color: #495057;">ID: #<?= str_pad($booking['booking_id'], 4, '0', STR_PAD_LEFT) ?></small>
                                </div>
                            </div>
                        </td>
                        <td><?= htmlspecialchars($booking['owner_name']) ?></td>
                        <td>
                            <?php if ($booking['owner_phone']): ?>
                                <a href="tel:<?= $booking['owner_phone'] ?>" style="color: #234a23; text-decoration: none;">
                                    <i class="fas fa-phone" style="margin-right: 5px;"></i><?= htmlspecialchars($booking['owner_phone']) ?>
                                </a><br>
                            <?php endif; ?>
                            <?php if ($booking['owner_email']): ?>
                                <a href="mailto:<?= $booking['owner_email'] ?>" style="color: #234a23; text-decoration: none; font-size: 12px;">
                                    <i class="fas fa-envelope" style="margin-right: 5px;"></i><?= htmlspecialchars($booking['owner_email']) ?>
                                </a>
                            <?php endif; ?>
                        </td>
                        <td><?= date('M j, Y', strtotime($booking['start_date'])) ?></td>
                        <td><?= date('M j, Y', strtotime($booking['end_date'])) ?></td>
                        <td><?= htmlspecialchars($booking['Hours'] ?? 'N/A') ?> hrs</td>
                        <td><strong style="color: #28a745;">₹<?= number_format($booking['total_amount'], 2) ?></strong></td>
                        <td><span class="status-badge <?= $status_class ?>"><?= $status_text ?></span></td>
                        <td style="white-space: nowrap;">
                            <!-- Always show Details button -->
                            <button type="button" onclick="viewBookingDetails(<?= htmlspecialchars(json_encode($booking)) ?>)" 
                                    style="color: #17a2b8; font-weight: 600; background: none; border: none; cursor: pointer; text-decoration: underline; padding: 5px; margin-right: 10px;">
                                <i class="fas fa-eye"></i> Details
                            </button>
                            
                            <!-- Show Cancel button for pending and confirmed bookings -->
                            <?php if ($booking['status'] === 'PEN' || $booking['status'] === 'CON'): ?>
                                <a href="?action=cancel&id=<?= $booking['booking_id'] ?>&status=<?= $status_filter ?>" 
                                   onclick="return confirm('Are you sure you want to cancel this booking?')"
                                   style="color: #dc3545; font-weight: 600; text-decoration: none; padding: 5px; margin-right: 10px;">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                            <?php endif; ?>
                            
                            <!-- Show Review button for completed bookings -->
                            <?php if ($booking['status'] === 'COM'): ?>
                                <a href="reviews.php?tab=give_reviews&equipment_id=<?= $booking['equipment_id'] ?>&highlight=<?= $booking['equipment_id'] ?>" 
                                   style="color: #28a745; font-weight: 600; text-decoration: none; padding: 5px;">
                                    <i class="fas fa-star"></i> Review
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="form-section" style="text-align: center; padding: 50px; background: white; border-radius: 8px;">
            <h3 style="color: #666; margin-bottom: 15px;">
                <?= $status_filter !== 'all' ? 'No bookings found with status "' . strtoupper($status_filter) . '"' : 'No Booking Requests Yet' ?>
            </h3>
            <p style="color: #666; margin-bottom: 25px;">
                <?= $status_filter !== 'all' ? 'Try changing the filter or make new bookings.' : 'When you book equipment, your bookings will appear here.' ?>
            </p>
            <a href="../equipments.php" class="action-btn">
                <i class="fas fa-tractor"></i> Browse Equipment
            </a>
        </div>
    <?php endif; ?>
</div>

<!-- Booking Details Modal -->
<div id="bookingDetailsModal" class="modal" style="display: none;">
    <div class="modal-content-large">
        <span class="close" onclick="closeBookingModal()">&times;</span>
        <h2 id="modalTitle">Booking Details</h2>
        
        <div class="modal-body">
            <!-- Equipment Image Section -->
            <div class="booking-image-section">
                <h3>Equipment Image</h3>
                <div id="bookingImageContainer">
                    <!-- Image will be inserted here -->
                </div>
            </div>

            <!-- Booking Details Section -->
            <div class="booking-details-section">
                <h3>Booking Information</h3>
                <div class="details-grid">
                    <div class="detail-row">
                        <strong>Booking ID:</strong>
                        <span id="detailBookingId"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Equipment:</strong>
                        <span id="detailEquipmentTitle"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Brand/Model:</strong>
                        <span id="detailBrandModel"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Description:</strong>
                        <span id="detailDescription"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Start Date:</strong>
                        <span id="detailStartDate"></span>
                    </div>
                    <div class="detail-row">
                        <strong>End Date:</strong>
                        <span id="detailEndDate"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Duration:</strong>
                        <span id="detailHours"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Status:</strong>
                        <span id="detailStatus"></span>
                    </div>
                </div>
            </div>

            <!-- Pricing Details Section -->
            <div class="pricing-details-section">
                <h3>Pricing Information</h3>
                <div class="details-grid">
                    <div class="detail-row">
                        <strong>Hourly Rate:</strong>
                        <span id="detailHourlyRate"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Daily Rate:</strong>
                        <span id="detailDailyRate"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Total Amount:</strong>
                        <span id="detailTotalAmount"></span>
                    </div>
                </div>
            </div>

            <!-- Owner Details Section -->
            <div class="customer-details-section">
                <h3>Equipment Owner Information</h3>
                <div class="details-grid">
                    <div class="detail-row">
                        <strong>Owner Name:</strong>
                        <span id="detailOwnerName"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Phone Number:</strong>
                        <span id="detailOwnerPhone"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Email:</strong>
                        <span id="detailOwnerEmail"></span>
                    </div>
                </div>
            </div>

            <div class="modal-actions">
                <div id="bookingActions">
                    <!-- Action buttons will be inserted here based on status -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Image Viewer Modal -->
<div id="imageViewerModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 800px; padding: 0; background: transparent; border: none; box-shadow: none;">
        <span class="close" onclick="closeImageViewer()" style="position: absolute; top: 10px; right: 25px; color: white; font-size: 40px; z-index: 1001;">&times;</span>
        <div style="text-align: center;">
            <img id="fullscreenImage" style="max-width: 100%; max-height: 90vh; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.3);">
            <p id="imageCaption" style="color: white; margin-top: 15px; font-size: 18px; font-weight: 600;"></p>
        </div>
    </div>
</div>

<style>
/* Live search styling */
.booking-row.hidden {
    display: none !important;
}

#bookingSearch:focus {
    outline: none;
    border-color: #234a23;
}

#clearSearch:hover {
    background: #f5f5f5;
    color: #234a23;
}

/* Status badge styling */
.status-completed {
    background-color: #234a23;
    color: white;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

.status-cancelled {
    background-color: #6c757d;
    color: white;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

/* Modal Styles */
.modal {
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    overflow: auto;
    background-color: rgba(0,0,0,0.5);
}

.modal-content-large {
    background-color: #fefefe;
    margin: 2% auto;
    padding: 0;
    border: none;
    border-radius: 8px;
    width: 90%;
    max-width: 800px;
    max-height: 90vh;
    overflow-y: auto;
    position: relative;
}

.modal-content-large h2 {
    background: #234a23;
    color: white;
    margin: 0;
    padding: 20px;
    border-radius: 8px 8px 0 0;
}

.modal-body {
    padding: 20px;
}

.booking-image-section,
.booking-details-section,
.pricing-details-section,
.customer-details-section {
    margin-bottom: 30px;
}

.booking-image-section h3,
.booking-details-section h3,
.pricing-details-section h3,
.customer-details-section h3 {
    color: #234a23;
    margin-bottom: 15px;
    border-bottom: 2px solid #eee;
    padding-bottom: 5px;
}

#bookingImageContainer {
    text-align: center;
    padding: 20px;
    background: #f8f9fa;
    border-radius: 8px;
}

#bookingImageContainer img {
    max-width: 100%;
    max-height: 300px;
    border-radius: 8px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    object-fit: cover;
    cursor: pointer;
    transition: transform 0.2s ease;
}

#bookingImageContainer img:hover {
    transform: scale(1.02);
}

.details-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 10px;
}

.detail-row {
    display: flex;
    padding: 10px;
    background: #f8f9fa;
    border-radius: 4px;
    border-left: 3px solid #234a23;
}

.detail-row strong {
    min-width: 150px;
    color: #234a23;
}

.detail-row span {
    flex: 1;
    margin-left: 10px;
}

.modal-actions {
    margin-top: 20px;
    padding-top: 20px;
    border-top: 2px solid #eee;
    text-align: center;
}

.modal-actions a {
    display: inline-block;
    margin: 0 5px;
    padding: 10px 20px;
    text-decoration: none;
    border-radius: 4px;
    font-weight: bold;
}

.btn-cancel {
    background: #dc3545;
    color: white;
}

.btn-review {
    background: #28a745;
    color: white;
}

.btn-cancel:hover {
    background: #dc4545;
    color: white;
}

.btn-review:hover {
    background: #289745;
    color: white;
}

.close {
    position: absolute;
    top: 15px;
    right: 25px;
    color: white;
    font-size: 35px;
    font-weight: bold;
    cursor: pointer;
    z-index: 1001;
}

.close:hover {
    color: #ccc;
}

/* Image viewer modal */
#imageViewerModal {
    background-color: rgba(0,0,0,0.9);
}

/* Responsive adjustments for search */
@media (max-width: 768px) {
    .quick-actions {
        flex-direction: column;
        align-items: stretch !important;
        gap: 15px !important;
    }
    
    #bookingSearch {
        width: 100% !important;
    }
    
    .quick-actions div {
        width: 100%;
    }
    
    .modal-content-large {
        width: 95%;
        margin: 1% auto;
    }
    
    .detail-row {
        flex-direction: column;
    }
    
    .detail-row strong {
        min-width: auto;
        margin-bottom: 5px;
    }
    
    .detail-row span {
        margin-left: 0;
    }
}
</style>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script>
$(document).ready(function() {
    // Live search functionality
    $('#bookingSearch').on('keyup', function() {
        var searchTerm = $(this).val().toLowerCase();
        $('#bookingsTable tbody tr.booking-row').each(function() {
            var rowText = $(this).text().toLowerCase();
            if (rowText.indexOf(searchTerm) === -1) {
                $(this).addClass('hidden');
            } else {
                $(this).removeClass('hidden');
            }
        });
    });

    // Clear search functionality
    $('#clearSearch').on('click', function() {
        $('#bookingSearch').val('');
        $('#bookingsTable tbody tr.booking-row').removeClass('hidden');
        $('#bookingSearch').focus();
    });
});

// View equipment image in fullscreen
function viewEquipmentImage(imageUrl, equipmentTitle) {
    document.getElementById('fullscreenImage').src = '../' + imageUrl;
    document.getElementById('imageCaption').textContent = equipmentTitle;
    document.getElementById('imageViewerModal').style.display = 'block';
}

function closeImageViewer() {
    document.getElementById('imageViewerModal').style.display = 'none';
}

// View booking details function
function viewBookingDetails(booking) {
    // Populate booking details
    document.getElementById('detailBookingId').textContent =booking.booking_id;
    document.getElementById('detailEquipmentTitle').textContent = booking.equipment_title || 'N/A';
    document.getElementById('detailBrandModel').textContent = (booking.Brand || 'N/A') + ' ' + (booking.Model || '');
    document.getElementById('detailDescription').textContent = booking.Description || 'No description available';

    // Handle equipment image in modal
    const imageContainer = document.getElementById('bookingImageContainer');
    if (booking.image_url) {
        imageContainer.innerHTML = '<img src="../' + booking.image_url + '" alt="Equipment Image" onclick="viewEquipmentImage(\'' + booking.image_url + '\', \'' + booking.equipment_title + '\')">';
    } else {
        imageContainer.innerHTML = '<div style="padding: 50px; background: #f0f0f0; border-radius: 8px; color: #666;"><i class="fas fa-tractor" style="font-size: 48px; margin-bottom: 10px;"></i><br>No Image Available</div>';
    }

    // Format dates
    const startDate = new Date(booking.start_date);
    const endDate = new Date(booking.end_date);
    document.getElementById('detailStartDate').textContent = startDate.toLocaleDateString('en-US', {
        year: 'numeric', month: 'short', day: 'numeric'
    });
    document.getElementById('detailEndDate').textContent = endDate.toLocaleDateString('en-US', {
        year: 'numeric', month: 'short', day: 'numeric'
    });

    document.getElementById('detailHours').textContent = (booking.Hours || 'N/A') + ' hours';

    // Set status with color
    const statusSpan = document.getElementById('detailStatus');
    const statusMap = {
        'PEN': { class: 'status-pending', text: 'Pending' },
        'CON': { class: 'status-confirmed', text: 'Confirmed' },
        'REJ': { class: 'status-rejected', text: 'Rejected' },
        'COM': { class: 'status-completed', text: 'Completed' },
        'CAN': { class: 'status-cancelled', text: 'Cancelled' }
    };
    const statusInfo = statusMap[booking.status] || { class: '', text: 'Unknown' };
    statusSpan.innerHTML = '<span class="status-badge ' + statusInfo.class + '">' + statusInfo.text + '</span>';

    // Populate pricing details
    document.getElementById('detailHourlyRate').textContent = '₹' + parseFloat(booking.Hourly_rate || 0).toFixed(2) + '/hr';
    document.getElementById('detailDailyRate').textContent = '₹' + parseFloat(booking.Daily_rate || 0).toFixed(2) + '/day';
    document.getElementById('detailTotalAmount').innerHTML = '<span style="color: #28a745; font-weight: bold; font-size: 18px;">₹' + parseFloat(booking.total_amount).toFixed(2) + '</span>';

    // Populate owner details
    document.getElementById('detailOwnerName').textContent = booking.owner_name || 'N/A';
    document.getElementById('detailOwnerPhone').innerHTML = booking.owner_phone ? 
        '<a href="tel:' + booking.owner_phone + '" style="color: #0d6efd; text-decoration: none;">' + booking.owner_phone + '</a>' : 'N/A';
    document.getElementById('detailOwnerEmail').innerHTML = booking.owner_email ? 
        '<a href="mailto:' + booking.owner_email + '" style="color: #0d6efd; text-decoration: none;">' + booking.owner_email + '</a>' : 'N/A';

    // Handle action buttons based on status
    const actionsContainer = document.getElementById('bookingActions');
    let actionsHTML = '';
    
    const currentStatus = new URLSearchParams(window.location.search).get('status') || 'all';
    
    if (booking.status === 'PEN' || booking.status === 'CON') {
        actionsHTML += '<a href="?action=cancel&id=' + booking.booking_id + '&status=' + currentStatus + '" class="btn-cancel" onclick="return confirm(\'Are you sure you want to cancel this booking?\')">Cancel Booking</a>';
    }
    
    if (booking.status === 'COM') {
        actionsHTML += '<a href="reviews.php?tab=give_reviews&equipment_id=' + booking.equipment_id + '&highlight=' + booking.equipment_id + '" class="btn-review">Write Review for This Equipment</a>';
    }
    
    actionsContainer.innerHTML = actionsHTML;

    // Show modal
    document.getElementById('bookingDetailsModal').style.display = 'block';
}

function closeBookingModal() {
    document.getElementById('bookingDetailsModal').style.display = 'none';
}

// Close modal when clicking outside
window.onclick = function(event) {
    const detailsModal = document.getElementById('bookingDetailsModal');
    const imageModal = document.getElementById('imageViewerModal');
    
    if (event.target === detailsModal) {
        detailsModal.style.display = 'none';
    }
    if (event.target === imageModal) {
        imageModal.style.display = 'none';
    }
}

// Keyboard navigation
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        const detailsModal = document.getElementById('bookingDetailsModal');
        const imageModal = document.getElementById('imageViewerModal');
        
        if (detailsModal.style.display === 'block') {
            closeBookingModal();
        }
        if (imageModal.style.display === 'block') {
            closeImageViewer();
        }
    }
});
</script>

<script>
// Auto hide message after 5 seconds
$(document).ready(function() {
    // Check if there's a message to hide
    if ($('.message').length > 0) {
        // Add fade out animation after 5 seconds
        setTimeout(function() {
            $('.message').fadeOut(1000, function() {
                $(this).remove();
            });
        }, 5000); // 5000ms = 5 seconds
    }
});
</script>

<?php require 'ffooter.php'; ?>
