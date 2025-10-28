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

// Handle booking actions (approve/reject/complete/reapprove)
if (isset($_GET['action']) && isset($_GET['id'])) {
    $booking_id = intval($_GET['id']);
    $action = $_GET['action'];

    if (in_array($action, ['approve', 'reject', 'complete', 'reapprove'])) {
        $new_status = '';
        
        // Determine new status based on action
        if ($action == 'approve') {
            $new_status = 'CON';
        } elseif ($action == 'reject') {
            $new_status = 'REJ';
        } elseif ($action == 'complete') {
            $new_status = 'COM';
        } elseif ($action == 'reapprove') {
            $new_status = 'CON';  // Re-approval changes status back to confirmed
        }

        // Verify booking belongs to this owner before updating
        $verify_stmt = $conn->prepare("SELECT eb.booking_id, eb.status FROM equipment_bookings eb 
                                      JOIN equipment e ON eb.equipment_id = e.Equipment_id 
                                      WHERE eb.booking_id = ? AND e.Owner_id = ?");
        $verify_stmt->bind_param('ii', $booking_id, $owner_id);
        $verify_stmt->execute();
        $verify_result = $verify_stmt->get_result();

        if ($verify_result->num_rows > 0) {
            $booking_data = $verify_result->fetch_assoc();
            
            // Check validation rules
            if ($action == 'complete' && $booking_data['status'] != 'CON') {
                $message = 'Error: Only confirmed bookings can be marked as complete.';
            } elseif ($action == 'reapprove' && $booking_data['status'] != 'REJ') {
                $message = 'Error: Only rejected bookings can be re-approved.';
            } else {
                $update_stmt = $conn->prepare('UPDATE equipment_bookings SET status = ? WHERE booking_id = ?');
                $update_stmt->bind_param('si', $new_status, $booking_id);

                if ($update_stmt->execute()) {
                    if ($action == 'approve') {
                        $message = 'Booking approved successfully.';
                    } elseif ($action == 'reject') {
                        $message = 'Booking rejected successfully.';
                    } elseif ($action == 'complete') {
                        $message = 'Booking marked as complete successfully.';
                    } elseif ($action == 'reapprove') {
                        $message = 'Booking re-approved successfully.';
                    }
                } else {
                    $message = 'Error updating booking status.';
                }
                $update_stmt->close();
            }
        }
        $verify_stmt->close();

        header('Location: equipment_bookings.php' . ($message ? '?msg=' . urlencode($message) : ''));
        exit();
    }
}

// Display message from redirect
if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
}

// Filter by status
$status_filter = $_GET['status'] ?? 'all';
$where_clause = "WHERE e.Owner_id = ?";
$params = [$owner_id];
$param_types = "i";

if ($status_filter !== 'all') {
    $where_clause .= " AND eb.status = ?";
    $params[] = $status_filter;
    $param_types .= "s";
}

// Fetch bookings for this owner's equipment - FIXED to prevent duplicates
$bookings = [];
try {
    $query = "SELECT DISTINCT eb.booking_id, eb.equipment_id, eb.customer_id, eb.start_date, eb.end_date, 
                     eb.Hours, eb.total_amount, eb.status, eb.time_slot, 
                     e.Title as equipment_title, e.Brand, e.Model, e.Hourly_rate, e.Daily_rate,
                     u.Name as customer_name, u.Phone as customer_phone, u.Email as customer_email,
                     es.Subcategory_name,
                     (SELECT ua.address FROM user_addresses ua WHERE ua.user_id = u.user_id LIMIT 1) as address,
                     (SELECT ua.city FROM user_addresses ua WHERE ua.user_id = u.user_id LIMIT 1) as city,
                     (SELECT ua.state FROM user_addresses ua WHERE ua.user_id = u.user_id LIMIT 1) as state,
                     (SELECT ua.Pin_code FROM user_addresses ua WHERE ua.user_id = u.user_id LIMIT 1) as Pin_code,
                     (SELECT i.image_url FROM images i WHERE i.image_type = 'E' AND i.ID = e.Equipment_id LIMIT 1) as image_url
              FROM equipment_bookings eb 
              JOIN equipment e ON eb.equipment_id = e.Equipment_id 
              JOIN users u ON eb.customer_id = u.user_id 
              LEFT JOIN equipment_subcategories es ON e.Subcategories_id = es.Subcategory_id
              $where_clause 
              ORDER BY eb.booking_id DESC";

    $stmt = $conn->prepare($query);
    $stmt->bind_param($param_types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        // Extract start_time and end_time from time_slot
        if (!empty($row['time_slot']) && strpos($row['time_slot'], '-') !== false) {
            list($start_time, $end_time) = explode('-', $row['time_slot']);
            $row['start_time'] = trim($start_time);
            $row['end_time'] = trim($end_time);
        } else {
            $row['start_time'] = null;
            $row['end_time'] = null;
        }
        $bookings[] = $row;
    }
    $stmt->close();
} catch (Exception $e) {
    error_log("Bookings fetch error: " . $e->getMessage());
}

// Calculate statistics
$total_bookings = count($bookings);
$pending_bookings = count(array_filter($bookings, fn($b) => $b['status'] == 'PEN'));
$confirmed_bookings = count(array_filter($bookings, fn($b) => $b['status'] == 'CON'));
$completed_bookings = count(array_filter($bookings, fn($b) => $b['status'] == 'COM'));
$rejected_bookings = count(array_filter($bookings, fn($b) => $b['status'] == 'REJ'));
$total_revenue = array_sum(array_map(fn($b) => ($b['status'] == 'CON' || $b['status'] == 'COM') ? $b['total_amount'] : 0, $bookings));

require 'oheader.php';
require 'owner_nav.php';
?>

<link rel="stylesheet" href="../admin.css">
<div class="main-content">
    <h1>Equipment Booking Requests</h1>
    <p style="color: #666; margin-bottom: 30px;">Manage booking requests for your equipment</p>

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
            <h3>Rejected Bookings</h3>
            <div class="count"><?= $rejected_bookings ?></div>
        </div>
        <div class="card">
            <h3>Total Revenue</h3>
            <div class="count">₹<?= number_format($total_revenue, 2) ?></div>
        </div>
    </div>

    <!-- Filter and Actions -->
    <div class="quick-actions" style="margin-bottom: 20px; display: flex; align-items: center; gap: 20px; flex-wrap: wrap;">
        <form method="GET" style="display: inline-block;">
            <select name="status" onchange="this.form.submit()" style="padding: 10px; border-radius: 4px; border: 1px solid #ddd; background: white;">
                <option value="all" <?= $status_filter == 'all' ? 'selected' : '' ?>>All Bookings</option>
                <option value="PEN" <?= $status_filter == 'PEN' ? 'selected' : '' ?>>Pending</option>
                <option value="CON" <?= $status_filter == 'CON' ? 'selected' : '' ?>>Confirmed</option>
                <option value="COM" <?= $status_filter == 'COM' ? 'selected' : '' ?>>Completed</option>
                <option value="REJ" <?= $status_filter == 'REJ' ? 'selected' : '' ?>>Rejected</option>
            </select>
        </form>
        
        <!-- Live Search Box -->
        <div style="display: flex; align-items: center; gap: 10px;">
            <input type="text" id="bookingSearch" placeholder="Search bookings..." style="padding: 10px; border: 1px solid #ddd; border-radius: 4px; width: 300px;">
            <button type="button" id="clearSearch" style="padding: 10px 15px; border: 1px solid #ddd; border-radius: 4px; background: white; cursor: pointer;">Clear</button>
        </div>
        
        <span style="color: #666; font-size: 14px;">
            Showing <?= count($bookings) ?> booking(s)
        </span>
    </div>

    <!-- Bookings Table -->
    <?php if (count($bookings) > 0): ?>
        <table id="bookingsTable">
            <thead>
                <tr>
                    <th>Equipment</th>
                    <th>Customer</th>
                    <th>Contact</th>
                    <th>Start Date</th>
                    <th>End Date</th>
                    <th>Start Time</th>
                    <th>End Time</th>
                    <th>Hours</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                foreach ($bookings as $booking):
                    $status_map = [
                        'CON' => ['status-confirmed', 'Confirmed'],
                        'PEN' => ['status-pending', 'Pending'],
                        'REJ' => ['status-rejected', 'Rejected'],
                        'COM' => ['status-completed', 'Completed']
                    ];
                    list($status_class, $status_text) = $status_map[$booking['status']] ?? ['', 'Unknown'];
                    ?>
                    <tr class="booking-row">
                        <td><strong><?= htmlspecialchars($booking['equipment_title']) ?></strong></td>
                        <td><?= htmlspecialchars($booking['customer_name']) ?></td>
                        <td><?= htmlspecialchars($booking['customer_phone']) ?></td>
                        <td><?= date('M j, Y', strtotime($booking['start_date'])) ?></td>
                        <td><?= date('M j, Y', strtotime($booking['end_date'])) ?></td>
                        <td>
                            <?php if ($booking['start_time']): ?>
                                <span style="font-weight: 600; color: #2d4a22;"><?= htmlspecialchars($booking['start_time']) ?></span>
                            <?php else: ?>
                                <span style="color: #666;">N/A</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($booking['end_time']): ?>
                                <span style="font-weight: 600; color: #2d4a22;"><?= htmlspecialchars($booking['end_time']) ?></span>
                            <?php else: ?>
                                <span style="color: #666;">N/A</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($booking['Hours'] ?? 'N/A') ?></td>
                        <td>₹<?= number_format($booking['total_amount'], 2) ?></td>
                        <td><span class="status-badge <?= $status_class ?>"><?= $status_text ?></span></td>
                        <td>
                            <!-- Always show Details button -->
                            <button type="button" onclick="viewBookingDetails(<?= htmlspecialchars(json_encode($booking)) ?>)" 
                                    style="color: #17a2b8; font-weight: 600; background: none; border: none; cursor: pointer; text-decoration: underline; padding: 5px;">
                                Details
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="form-section" style="text-align: center; padding: 50px; background: white; border-radius: 8px;">
            <h3 style="color: #666; margin-bottom: 15px;">
                <?= $status_filter !== 'all' ? 'No bookings found with status: ' . strtoupper($status_filter) : 'No Booking Requests Yet' ?>
            </h3>
            <p style="color: #666; margin-bottom: 25px;">
                <?= $status_filter !== 'all' ? 'Try changing the filter or wait for new bookings.' : 'When farmers book your equipment, they will appear here for your approval.' ?>
            </p>
            <a href="add_equipment.php" class="action-btn">➕ Add More Equipment</a>
        </div>
    <?php endif; ?>
</div>

<!-- Booking Details Modal -->
<div id="bookingDetailsModal" class="modal" style="display: none;">
    <div class="modal-content-large">
        <span class="close" onclick="closeBookingModal()">&times;</span>
        <h2 id="modalTitle">Booking Details</h2>
        
        <div class="modal-body">
            <div class="booking-image-section">
                <h3>Equipment Image</h3>
                <div id="bookingImageContainer">
                    <!-- Image will be inserted here -->
                </div>
            </div>
            
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
                        <strong>Category:</strong>
                        <span id="detailCategory"></span>
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
                        <strong>Start Time:</strong>
                        <span id="detailStartTime"></span>
                    </div>
                    <div class="detail-row">
                        <strong>End Time:</strong>
                        <span id="detailEndTime"></span>
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
            
            <div class="customer-details-section">
                <h3>Customer Information</h3>
                <div class="details-grid">
                    <div class="detail-row">
                        <strong>Customer Name:</strong>
                        <span id="detailCustomerName"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Phone Number:</strong>
                        <span id="detailCustomerPhone"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Email:</strong>
                        <span id="detailCustomerEmail"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Address:</strong>
                        <span id="detailCustomerAddress"></span>
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

/* Completed status styling */
.status-completed {
    background-color: #234a23;
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

.booking-image-section, .booking-details-section, .pricing-details-section, .customer-details-section {
    margin-bottom: 30px;
}

.booking-image-section h3, .booking-details-section h3, .pricing-details-section h3, .customer-details-section h3 {
    color: #234a23;
    margin-bottom: 15px;
    border-bottom: 2px solid #eee;
    padding-bottom: 5px;
}

#bookingImageContainer {
    text-align: center;
    padding: 20px;
    background: #f8f9fa;
    border-radius: 5px;
}

#bookingImageContainer img {
    max-width: 100%;
    max-height: 300px;
    border-radius: 5px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    object-fit: cover;
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

.btn-approve {
    background: #234a23;
    color: white;
}

.btn-reject {
    background: #dc3545;
    color: white;
}

.btn-complete {
    background: #234a23;
    color: white;
}

.btn-reapprove {
    background: #ffc107;
    color: #212529;
}


.btn-approve:hover {
    background: #235a23;
    color: white;
}

.btn-reject:hover {
    background: #dc4545;
    color: white;
}

.btn-complete:hover {
    background: #233a23;
    color: white;
}

.btn-reapprove:hover {
    background: #ffc207;
    color: #212529;
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
    
    .quick-actions > div {
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

// View booking details function
function viewBookingDetails(booking) {
    // Populate booking details
    document.getElementById('detailBookingId').textContent = '#' + booking.booking_id;
    document.getElementById('detailEquipmentTitle').textContent = booking.equipment_title || 'N/A';
    document.getElementById('detailBrandModel').textContent = (booking.Brand || 'N/A') + ' ' + (booking.Model || '');
    document.getElementById('detailCategory').textContent = booking.Subcategory_name || 'N/A';
    
    // Format dates
    const startDate = new Date(booking.start_date);
    const endDate = new Date(booking.end_date);
    document.getElementById('detailStartDate').textContent = startDate.toLocaleDateString('en-US', {
        year: 'numeric', month: 'short', day: 'numeric'
    });
    document.getElementById('detailEndDate').textContent = endDate.toLocaleDateString('en-US', {
        year: 'numeric', month: 'short', day: 'numeric'
    });
    
    document.getElementById('detailStartTime').textContent = booking.start_time || 'N/A';
    document.getElementById('detailEndTime').textContent = booking.end_time || 'N/A';
    document.getElementById('detailHours').textContent = (booking.Hours || 'N/A') + ' hours';
    
    // Set status with color
    const statusSpan = document.getElementById('detailStatus');
    const statusMap = {
        'PEN': { class: 'status-pending', text: 'Pending' },
        'CON': { class: 'status-confirmed', text: 'Confirmed' },
        'REJ': { class: 'status-rejected', text: 'Rejected' },
        'COM': { class: 'status-completed', text: 'Completed' }
    };
    const statusInfo = statusMap[booking.status] || { class: '', text: 'Unknown' };
    statusSpan.innerHTML = `<span class="status-badge ${statusInfo.class}">${statusInfo.text}</span>`;
    
    // Populate pricing details
    document.getElementById('detailHourlyRate').textContent = '₹' + (parseFloat(booking.Hourly_rate || 0).toFixed(2)) + '/hr';
    document.getElementById('detailDailyRate').textContent = '₹' + (parseFloat(booking.Daily_rate || 0).toFixed(2)) + '/day';
    document.getElementById('detailTotalAmount').innerHTML = '<span style="color: #28a745; font-weight: bold; font-size: 18px;">₹' + (parseFloat(booking.total_amount).toFixed(2)) + '</span>';
    
    // Populate customer details
    document.getElementById('detailCustomerName').textContent = booking.customer_name || 'N/A';
    document.getElementById('detailCustomerPhone').innerHTML = booking.customer_phone ? 
        `<a href="tel:${booking.customer_phone}" style="color: #0d6efd; text-decoration: none;">${booking.customer_phone}</a>` : 'N/A';
    document.getElementById('detailCustomerEmail').innerHTML = booking.customer_email ? 
        `<a href="mailto:${booking.customer_email}" style="color: #0d6efd; text-decoration: none;">${booking.customer_email}</a>` : 'N/A';
    
    // Format address
    let addressText = 'N/A';
    if (booking.address) {
        addressText = booking.address;
        if (booking.city) addressText += '<br/>' + booking.city;
        if (booking.state) addressText += ', ' + booking.state;
        if (booking.Pin_code) addressText += ' - ' + booking.Pin_code;
    }
    document.getElementById('detailCustomerAddress').innerHTML = addressText;
    
    // Handle equipment image
    const imageContainer = document.getElementById('bookingImageContainer');
    if (booking.image_url) {
        imageContainer.innerHTML = '<img src="../' + booking.image_url + '" alt="Equipment Image">';
    } else {
        imageContainer.innerHTML = '<div style="padding: 50px; background: #f0f0f0; border-radius: 5px; color: #666;"><i class="fas fa-image" style="font-size: 48px; margin-bottom: 10px;"></i><br>No Image Available</div>';
    }
    
    // Handle action buttons based on status
    const actionsContainer = document.getElementById('bookingActions');
    let actionsHTML = '';
    
    const currentStatus = new URLSearchParams(window.location.search).get('status') || 'all';
    
    if (booking.status === 'PEN') {
        actionsHTML += '<a href="?action=approve&id=' + booking.booking_id + '&status=' + currentStatus + '" class="btn-approve" onclick="return confirm(\'Approve this booking?\')">Approve Booking</a>';
        actionsHTML += '<a href="?action=reject&id=' + booking.booking_id + '&status=' + currentStatus + '" class="btn-reject" onclick="return confirm(\'Reject this booking?\')">Reject Booking</a>';
    } else if (booking.status === 'CON') {
        actionsHTML += '<a href="?action=complete&id=' + booking.booking_id + '&status=' + currentStatus + '" class="btn-complete" onclick="return confirm(\'Mark as complete?\')">Mark Complete</a>';
        actionsHTML += '<a href="?action=reject&id=' + booking.booking_id + '&status=' + currentStatus + '" class="btn-reject" onclick="return confirm(\'Reject this booking?\')">Reject Booking</a>';
    } else if (booking.status === 'REJ') {
        // Add Re-Approval button for rejected bookings
        actionsHTML += '<a href="?action=reapprove&id=' + booking.booking_id + '&status=' + currentStatus + '" class="btn-reapprove" onclick="return confirm(\'Re-approve this rejected booking?\')">Re-Approve Booking</a>';
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
    const modal = document.getElementById('bookingDetailsModal');
    if (event.target == modal) {
        modal.style.display = 'none';
    }
}

// Keyboard navigation
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        const modal = document.getElementById('bookingDetailsModal');
        if (modal.style.display === 'block') {
            closeBookingModal();
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


<?php
require 'ofooter.php';
?>
