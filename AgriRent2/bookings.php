<?php
session_start();
require_once '../auth/config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'A') {
    header('Location: ../login.php');
    exit;
}

// Current status filter
$status = isset($_GET['status']) ? $_GET['status'] : 'PEN';

// Current search filter
$search = isset($_GET['search']) ? $_GET['search'] : '';

// Corrected query with only existing columns - removed Location
$sql = "
    SELECT eb.*, e.Title as equipment_name, e.Brand, e.Model, e.Description, 
           e.Hourly_rate, e.Daily_rate,
           customer.Name as customer_name, customer.Email as customer_email, 
           customer.Phone as customer_phone,
           owner.Name as owner_name, owner.Email as owner_email, owner.Phone as owner_phone,
           i.image_url as equipment_image
    FROM equipment_bookings eb
    JOIN equipment e ON eb.equipment_id = e.Equipment_id
    JOIN users customer ON eb.customer_id = customer.user_id
    JOIN users owner ON e.Owner_id = owner.user_id
    LEFT JOIN images i ON (i.image_type = 'E' AND i.ID = e.Equipment_id)
    WHERE eb.status = '$status'
    ORDER BY eb.start_date DESC
";

$bookings = $conn->query($sql);

// Error handling for SQL query
if (!$bookings) {
    die("SQL Error: " . $conn->error . "<br>Query: " . $sql);
}

require 'header.php';
require 'admin_nav.php';
?>

<div class="main-content">
    <h1>Bookings (View Only)</h1>

    <div class="tabs">
        <a href="?status=PEN" class="tab <?= $status == 'PEN' ? 'active' : '' ?>">Pending</a>
        <a href="?status=CON" class="tab <?= $status == 'CON' ? 'active' : '' ?>">Confirmed</a>
        <a href="?status=COM" class="tab <?= $status == 'COM' ? 'active' : '' ?>">Completed</a>
        <a href="?status=REJ" class="tab <?= $status == 'REJ' ? 'active' : '' ?>">Rejected</a>
    </div>

    <!-- Search Box -->
    <div class="search-box">
        <input type="text" id="liveSearch" placeholder="Search bookings by ID, equipment, customer, owner..." style="padding: 8px; width: 800px; margin-bottom: 10px;">
        <button type="button" id="clearSearch" class="btn" style="margin-left: 10px; width: 85px;">Clear</button>
    </div>

    <table id="bookingsTable">
        <thead>
            <tr>
                <th>ID</th>
                <th>Equipment</th>
                <th>Customer</th>
                <th>Owner</th>
                <th>Duration</th>
                <th>Amount</th>
                <th>Status</th>
                <th>View</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($bookings && $bookings->num_rows > 0): ?>
                <?php while($booking = $bookings->fetch_assoc()): ?>
                <tr class="booking-row">
                    <td>B-<?= $booking['booking_id'] ?></td>
                    <td>
                        <?= htmlspecialchars($booking['equipment_name']) ?><br>
                        <small><?= htmlspecialchars($booking['Brand']) ?></small>
                    </td>
                    <td>
                        <?= htmlspecialchars($booking['customer_name']) ?><br>
                        <small><?= htmlspecialchars($booking['customer_email']) ?></small>
                    </td>
                    <td><?= htmlspecialchars($booking['owner_name']) ?></td>
                    <td>
                        <?= date('M d', strtotime($booking['start_date'])) ?> - 
                        <?= date('M d, Y', strtotime($booking['end_date'])) ?><br>
                        <small><?= isset($booking['Hours']) ? $booking['Hours'] : '0' ?> hours</small>
                    </td>
                    <td>Rs.<?= number_format($booking['total_amount'], 2) ?></td>
                    <td>
                        <?php if ($booking['status'] == 'PEN'): ?>
                            <span style="color: orange;">Pending</span>
                        <?php elseif ($booking['status'] == 'CON'): ?>
                            <span style="color: green;">Confirmed</span>
                        <?php elseif ($booking['status'] == 'COM'): ?>
                            <span style="color: blue;">Completed</span>
                        <?php else: ?>
                            <span style="color: red;">Cancelled</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button type="button" class="btn-view" onclick="viewBooking(<?= htmlspecialchars(json_encode($booking)) ?>)">
                            View Details
                        </button>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="8">No bookings found</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    
    <div style="margin-top: 20px; padding: 15px; background: #fff3cd; border-radius: 5px;">
        <strong>Note:</strong> Admin can only view bookings. Equipment owners and customers handle booking processing directly.
    </div>
</div>

<!-- Booking Details Modal -->
<div id="bookingModal" class="modal" style="display: none;">
    <div class="modal-content-large">
        <span class="close" onclick="closeBookingModal()">&times;</span>
        <h2 id="modalTitle">Booking Details</h2>
        
        <div class="modal-body">
            <!-- Equipment Section -->
            <div class="equipment-section">
                <h3>Equipment Information</h3>
                <div class="equipment-content">
                    <div class="equipment-image-container">
                        <div id="equipmentImageDisplay">
                            <!-- Equipment image will be inserted here -->
                        </div>
                    </div>
                    <div class="equipment-details">
                        <div class="details-grid">
                            <div class="detail-row">
                                <strong>Equipment Name:</strong>
                                <span id="detailEquipmentName"></span>
                            </div>
                            <div class="detail-row">
                                <strong>Brand & Model:</strong>
                                <span id="detailBrandModel"></span>
                            </div>
                            <div class="detail-row">
                                <strong>Description:</strong>
                                <span id="detailEquipmentDescription"></span>
                            </div>
                            <div class="detail-row">
                                <strong>Rates:</strong>
                                <span id="detailEquipmentRates"></span>
                            </div>
                        </div>
                    </div>
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
                        <strong>Start Date & Time:</strong>
                        <span id="detailStartDate"></span>
                    </div>
                    <div class="detail-row">
                        <strong>End Date & Time:</strong>
                        <span id="detailEndDate"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Total Hours:</strong>
                        <span id="detailTotalHours"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Total Amount:</strong>
                        <span id="detailTotalAmount"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Booking Created:</strong>
                        <span id="detailBookingDate"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Status:</strong>
                        <span id="detailBookingStatus"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Special Requirements:</strong>
                        <span id="detailSpecialReq"></span>
                    </div>
                </div>
            </div>
            
            <!-- Customer Details Section -->
            <div class="customer-details-section">
                <h3>Customer Information</h3>
                <div class="details-grid">
                    <div class="detail-row">
                        <strong>Customer Name:</strong>
                        <span id="detailCustomerName"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Email:</strong>
                        <span id="detailCustomerEmail"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Phone:</strong>
                        <span id="detailCustomerPhone"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Address:</strong>
                        <span id="detailCustomerAddress"></span>
                    </div>
                </div>
            </div>
            
            <!-- Owner Details Section -->
            <div class="owner-details-section">
                <h3>Equipment Owner Information</h3>
                <div class="details-grid">
                    <div class="detail-row">
                        <strong>Owner Name:</strong>
                        <span id="detailOwnerName"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Email:</strong>
                        <span id="detailOwnerEmail"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Phone:</strong>
                        <span id="detailOwnerPhone"></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Fullscreen Image Modal -->
<div id="fullscreenImageModal" class="fullscreen-modal" style="display: none;">
    <span class="fullscreen-close" onclick="closeFullscreenImage()">&times;</span>
    <img id="fullscreenImage" class="fullscreen-image" src="" alt="Fullscreen Equipment Image">
    <div id="fullscreenImageCaption" class="fullscreen-caption"></div>
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
    background-color: rgba(0,0,0,0.5);
}

.modal-content-large {
    background-color: #fefefe;
    margin: 1% auto;
    padding: 0;
    border: none;
    border-radius: 8px;
    width: 95%;
    max-width: 1000px;
    max-height: 95vh;
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

.equipment-section, .booking-details-section, .customer-details-section, .owner-details-section {
    margin-bottom: 30px;
    background: #f8f9fa;
    padding: 20px;
    border-radius: 8px;
    
}

.equipment-section h3, .booking-details-section h3, .customer-details-section h3, .owner-details-section h3 {
    color: #234a23;
    margin-bottom: 15px;
    margin-top: 0;
    border-bottom: 2px solid #e9ecef;
    padding-bottom: 10px;
}

.equipment-content {
    display: grid;
    grid-template-columns: 300px 1fr;
    gap: 20px;
    align-items: start;
}

.equipment-image-container {
    text-align: center;
}

#equipmentImageDisplay {
    background: #fff;
    padding: 15px;
    border-radius: 8px;
    border: 2px solid #e9ecef;
    min-height: 200px;
    display: flex;
    align-items: center;
    justify-content: center;
}

#equipmentImageDisplay img {
    max-width: 100%;
    max-height: 250px;
    object-fit: contain;
    border-radius: 5px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    cursor: pointer;
    transition: transform 0.2s ease;
}

#equipmentImageDisplay img:hover {
    transform: scale(1.02);
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
}

.equipment-details {
    background: #fff;
    padding: 15px;
    border-radius: 8px;
    border: 1px solid #e9ecef;
}

.details-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 12px;
}

.detail-row {
    display: flex;
    padding: 12px;
    background: #fff;
    border-radius: 6px;
    border: 1px solid #e9ecef;
    transition: all 0.2s ease;
}

.detail-row:hover {
    border-color: #234a23;
    box-shadow: 0 2px 4px rgba(35, 74, 35, 0.1);
}

.detail-row strong {
    min-width: 180px;
    color: #234a23;
    font-weight: 600;
}

.detail-row span {
    flex: 1;
    margin-left: 15px;
    color: #495057;
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

/* Fullscreen Image Modal Styles */
.fullscreen-modal {
    display: none;
    position: fixed;
    z-index: 2000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.95);
    animation: fadeIn 0.3s ease-in-out;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.fullscreen-image {
    margin: auto;
    display: block;
    width: auto;
    height: auto;
    max-width: 95%;
    max-height: 90%;
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    border-radius: 8px;
    box-shadow: 0 4px 20px rgba(255, 255, 255, 0.1);
    animation: zoomIn 0.3s ease-in-out;
}

@keyframes zoomIn {
    from { transform: translate(-50%, -50%) scale(0.5); }
    to { transform: translate(-50%, -50%) scale(1); }
}

.fullscreen-close {
    position: absolute;
    top: 20px;
    right: 35px;
    color: #ffffff;
    font-size: 50px;
    font-weight: bold;
    cursor: pointer;
    transition: color 0.3s ease;
    z-index: 2001;
    text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.5);
}

.fullscreen-close:hover,
.fullscreen-close:focus {
    color: #ffcccc;
    text-decoration: none;
}

.fullscreen-caption {
    margin: auto;
    display: block;
    width: 80%;
    max-width: 700px;
    text-align: center;
    color: #ffffff;
    padding: 10px 0;
    position: absolute;
    bottom: 20px;
    left: 50%;
    transform: translateX(-50%);
    background: rgba(0, 0, 0, 0.7);
    border-radius: 4px;
    font-size: 16px;
    line-height: 1.4;
}

.btn-view {
    background: #234a23;
    color: white;
    border: none;
    padding: 6px 12px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 12px;
    font-weight: 500;
    transition: background-color 0.2s ease;
}

.btn-view:hover {
    background: #236a23;
}

/* Search Box Styling */
#liveSearch {
    font-size: 14px;
    border: 1px solid #ccc;
    border-radius: 4px;
}

#liveSearch:focus {
   outline: none;
    border-color: #234a23;
    box-shadow: 0 0 5px rgba(35, 74, 35, 0.3);
}

.booking-row.hidden {
    display: none !important;
}

/* Status badges */
.status-badge {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: bold;
    text-transform: uppercase;
}

.status-pending { background: #fff3cd; color: #856404; }
.status-confirmed { background: #d4edda; color: #155724; }
.status-completed { background: #cce5ff; color: #004085; }
.status-rejected { background: #f8d7da; color: #721c24; }

/* Responsive Design */
@media only screen and (max-width: 768px) {
    .modal-content-large {
        width: 98%;
        margin: 1% auto;
    }
    
    .equipment-content {
        grid-template-columns: 1fr;
        gap: 15px;
    }
    
    .detail-row {
        flex-direction: column;
        gap: 5px;
    }
    
    .detail-row strong {
        min-width: auto;
    }
    
    .detail-row span {
        margin-left: 0;
    }
    
    #liveSearch {
        width: 100%;
        margin-bottom: 10px;
    }
    
    .fullscreen-close {
        top: 10px;
        right: 20px;
        font-size: 40px;
    }
    
    .fullscreen-caption {
        width: 95%;
        bottom: 10px;
        font-size: 14px;
    }
    
    .fullscreen-image {
        max-width: 98%;
        max-height: 85%;
    }
}
</style>

<!-- jQuery for live search -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    function filterRows() {
        var value = $('#liveSearch').val().toLowerCase();
        var visibleRows = 0;

        $('#bookingsTable tbody tr.booking-row').each(function() {
            var isMatch = $(this).text().toLowerCase().indexOf(value) > -1;
            $(this).toggle(isMatch);
            if (isMatch) visibleRows++;
        });

        if (visibleRows === 0 && value !== '') {
            if ($('#noResultsRow').length === 0) {
                $('#bookingsTable tbody').append('<tr id="noResultsRow"><td colspan="8" style="text-align: center; padding: 20px; font-style: italic;">No bookings found matching your search</td></tr>');
            }
        } else {
            $('#noResultsRow').remove();
        }
    }

    // Run filter on keyup
    $('#liveSearch').on('keyup', filterRows);
    
    // Clear button functionality
    $('#clearSearch').on('click', function() {
        $('#liveSearch').val('');
        $('#bookingsTable tbody tr.booking-row').show();
        $('#noResultsRow').remove();
        $('#liveSearch').focus();
    });

    // Clear search on tab change
    $('.tab').on('click', function() {
        $('#liveSearch').val('');
    });
});

// View booking details function
function viewBooking(booking) {
    // Equipment Information
    document.getElementById('detailEquipmentName').textContent = booking.equipment_name || 'N/A';
    document.getElementById('detailBrandModel').textContent = (booking.Brand || 'N/A') + ' ' + (booking.Model || '');
    document.getElementById('detailEquipmentDescription').textContent = booking.Description || 'No description available';
    document.getElementById('detailEquipmentRates').textContent = 'Rs.' + (booking.Hourly_rate || '0') + '/hr, Rs.' + (booking.Daily_rate || '0') + '/day';
    
    // Equipment Image with fullscreen capability
    const imageContainer = document.getElementById('equipmentImageDisplay');
    if (booking.equipment_image) {
        imageContainer.innerHTML = '<img src="../' + booking.equipment_image + '" alt="Equipment Image" onclick="openFullscreenImage(this, \'' + (booking.equipment_name || 'Equipment') + '\')" title="Click to view fullscreen">';
    } else {
        imageContainer.innerHTML = '<div style="color: #6c757d; text-align: center;"><i class="fas fa-image" style="font-size: 48px; margin-bottom: 10px; display: block;"></i>No Image Available</div>';
    }
    
    // Booking Information
    document.getElementById('detailBookingId').textContent = + booking.booking_id;
    document.getElementById('detailStartDate').textContent = formatDateTime(booking.start_date, booking.start_time);
    document.getElementById('detailEndDate').textContent = formatDateTime(booking.end_date, booking.end_time);
    document.getElementById('detailTotalHours').textContent = (booking.Hours || '0') + ' hours';
    document.getElementById('detailTotalAmount').textContent = 'Rs.' + parseFloat(booking.total_amount || 0).toLocaleString();
    
    // Use start_date as booking created date
    let bookingDateText = 'N/A';
    if (booking.start_date) {
        const startDate = new Date(booking.start_date);
        bookingDateText = startDate.toLocaleDateString('en-US', { 
            weekday: 'short', 
            year: 'numeric', 
            month: 'short', 
            day: 'numeric' 
        });
    }
    document.getElementById('detailBookingDate').textContent = bookingDateText;
    
    document.getElementById('detailSpecialReq').textContent = booking.special_requirements || 'None';
    
    // Status with styling
    const statusElement = document.getElementById('detailBookingStatus');
    let statusClass = '';
    let statusText = '';
    
    switch(booking.status) {
        case 'PEN':
            statusClass = 'status-pending';
            statusText = 'Pending';
            break;
        case 'CON':
            statusClass = 'status-confirmed';
            statusText = 'Confirmed';
            break;
        case 'COM':
            statusClass = 'status-completed';
            statusText = 'Completed';
            break;
        case 'REJ':
            statusClass = 'status-rejected';
            statusText = 'Rejected';
            break;
        default:
            statusClass = 'status-pending';
            statusText = booking.status || 'Unknown';
    }
    
    statusElement.innerHTML = '<span class="status-badge ' + statusClass + '">' + statusText + '</span>';
    
    // Customer Information
    document.getElementById('detailCustomerName').textContent = booking.customer_name || 'N/A';
    document.getElementById('detailCustomerEmail').textContent = booking.customer_email || 'N/A';
    document.getElementById('detailCustomerPhone').textContent = booking.customer_phone || 'N/A';
    document.getElementById('detailCustomerAddress').textContent = booking.customer_address || 'N/A';
    
    // Owner Information
    document.getElementById('detailOwnerName').textContent = booking.owner_name || 'N/A';
    document.getElementById('detailOwnerEmail').textContent = booking.owner_email || 'N/A';
    document.getElementById('detailOwnerPhone').textContent = booking.owner_phone || 'N/A';
    
    // Show modal
    document.getElementById('bookingModal').style.display = 'block';
}

function closeBookingModal() {
    document.getElementById('bookingModal').style.display = 'none';
}

// Fullscreen Image Functions
function openFullscreenImage(imgElement, caption) {
    const modal = document.getElementById('fullscreenImageModal');
    const fullscreenImg = document.getElementById('fullscreenImage');
    const captionElement = document.getElementById('fullscreenImageCaption');
    
    modal.style.display = 'block';
    fullscreenImg.src = imgElement.src;
    captionElement.innerHTML = caption || 'Equipment Image';
    
    // Prevent body scrolling when modal is open
    document.body.style.overflow = 'hidden';
}

function closeFullscreenImage() {
    const modal = document.getElementById('fullscreenImageModal');
    modal.style.display = 'none';
    
    // Restore body scrolling
    document.body.style.overflow = 'auto';
}

// Helper function to format date and time
function formatDateTime(date, time) {
    if (!date) return 'N/A';
    
    const dateObj = new Date(date);
    const dateStr = dateObj.toLocaleDateString('en-US', { 
        weekday: 'short', 
        year: 'numeric', 
        month: 'short', 
        day: 'numeric' 
    });
    
    if (time) {
        return dateStr + ' at ' + time;
    }
    
    return dateStr;
}

// Close modals when clicking outside or pressing Escape
window.onclick = function(event) {
    const bookingModal = document.getElementById('bookingModal');
    const fullscreenModal = document.getElementById('fullscreenImageModal');
    
    if (event.target == bookingModal) {
        bookingModal.style.display = 'none';
    }
    
    if (event.target == fullscreenModal) {
        closeFullscreenImage();
    }
}

// Keyboard navigation for fullscreen modal
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        const fullscreenModal = document.getElementById('fullscreenImageModal');
        if (fullscreenModal.style.display === 'block') {
            closeFullscreenImage();
        }
        
        const bookingModal = document.getElementById('bookingModal');
        if (bookingModal.style.display === 'block') {
            closeBookingModal();
        }
    }
});
</script>

<?php require 'footer.php'; ?>
