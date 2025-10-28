<?php
session_start();
require_once '../auth/config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'A') {
    header('Location: ../login.php');
    exit;
}

if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $id = $_GET['id'];
    
    if ($action == 'approve') {
        $conn->query("UPDATE equipment SET Approval_status='CON' WHERE Equipment_id=$id");
        $message = "Equipment approved";
    } elseif ($action == 'reject') {
        $conn->query("UPDATE equipment SET Approval_status='REJ' WHERE Equipment_id=$id");
        $message = "Equipment rejected";
    } elseif ($action == 'reactivate') {
        $conn->query("UPDATE equipment SET Approval_status='CON' WHERE Equipment_id=$id");
        $message = "Equipment reactivated";
    }
}

$status = isset($_GET['status']) ? $_GET['status'] : 'PEN';
$where = "WHERE e.Approval_status = '$status'";

// Updated query to include all equipment details and image
$equipment = $conn->query("SELECT e.*, u.Name as owner_name, u.Email as owner_email, 
                          u.Phone as owner_phone, i.image_url 
                          FROM equipment e 
                          JOIN users u ON e.Owner_id = u.user_id 
                          LEFT JOIN images i ON (i.image_type = 'E' AND i.ID = e.Equipment_id)
                          $where 
                          ORDER BY e.listed_date DESC");

require 'header.php';
require 'admin_nav.php';
?>

<div class="main-content">
    <h1>Equipment</h1>
    
    <?php if (isset($message)): ?>
        <div class="message"><?= $message ?></div>
    <?php endif; ?>
    
    <div class="tabs">
        <a href="?status=PEN" class="tab <?= $status == 'PEN' ? 'active' : '' ?>">Pending</a>
        <a href="?status=CON" class="tab <?= $status == 'CON' ? 'active' : '' ?>">Approved</a>
        <a href="?status=REJ" class="tab <?= $status == 'REJ' ? 'active' : '' ?>">Rejected</a>
    </div>
    
    <div class="search-box">
        <input type="text" id="liveSearch" placeholder="Search equipment..." >
        <button type="button" id="clearSearch" class="btn" >Clear</button>
    </div>
    
    <table id="equipmentTable">
        <thead>
            <tr class="table-header">
                <th>ID</th>
                <th>Title</th>
                <th>Owner</th>
                <th>Brand/Model</th>
                <th>Rates</th>
                <th>Date Added</th>
                <th>View</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($equipment->num_rows > 0): ?>
                <?php while($item = $equipment->fetch_assoc()): ?>
                <tr class="table-row">
                    <td><?= $item['Equipment_id'] ?></td>
                    <td><?= htmlspecialchars($item['Title']) ?></td>
                    <td><?= htmlspecialchars($item['owner_name']) ?></td>
                    <td><?= htmlspecialchars($item['Brand']) ?> <?= htmlspecialchars($item['Model']) ?></td>
                    <td>
                        Rs.<?= $item['Hourly_rate'] ?>/hr<br>
                        Rs.<?= $item['Daily_rate'] ?>/day
                    </td>
                    <td><?= date('M d, Y', strtotime($item['listed_date'])) ?></td>
                    <td>
                        <button type="button" class="btn-view" onclick="viewEquipment(<?= htmlspecialchars(json_encode($item)) ?>)">
                            View Details
                        </button>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr class="no-results">
                    <td colspan="7">No equipment found</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Equipment Details Modal -->
<div id="equipmentModal" class="modal" style="display: none;">
    <div class="modal-content-large">
        <span class="close" onclick="closeEquipmentModal()">&times;</span>
        <h2 id="modalTitle">Equipment Details</h2>
        
        <div class="modal-body">
            <div class="equipment-image-section">
                <h3>Equipment Image</h3>
                <div id="equipmentImageContainer">
                    <!-- Image will be inserted here -->
                </div>
            </div>
            
            <div class="equipment-details-section">
                <h3>Equipment Information</h3>
                <div class="details-grid">
                    <div class="detail-row">
                        <strong>Equipment ID:</strong>
                        <span id="detailEquipmentId"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Title:</strong>
                        <span id="detailTitle"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Brand:</strong>
                        <span id="detailBrand"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Model:</strong>
                        <span id="detailModel"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Description:</strong>
                        <span id="detailDescription"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Hourly Rate:</strong>
                        <span id="detailHourlyRate"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Daily Rate:</strong>
                        <span id="detailDailyRate"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Year:</strong>
                        <span id="detailYear"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Listed Date:</strong>
                        <span id="detailListedDate"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Status:</strong>
                        <span id="detailStatus"></span>
                    </div>
                </div>
            </div>
            
            <div class="owner-details-section">
                <h3>Owner Information</h3>
                <div class="details-grid">
                    <div class="detail-row">
                        <strong>Owner Name:</strong>
                        <span id="detailOwnerName"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Owner Email:</strong>
                        <span id="detailOwnerEmail"></span>
                    </div>
                    <div class="detail-row">
                        <strong>Owner Phone:</strong>
                        <span id="detailOwnerPhone"></span>
                    </div>
                </div>
            </div>
            
            <div class="modal-actions">
                <div id="equipmentActions">
                    <!-- Action buttons will be inserted here based on status -->
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

.equipment-image-section, .equipment-details-section, .owner-details-section {
    margin-bottom: 30px;
}

.equipment-image-section h3, .equipment-details-section h3, .owner-details-section h3 {
    color: #234a23;
    margin-bottom: 15px;
    border-bottom: 2px solid #eee;
    padding-bottom: 5px;
}

#equipmentImageContainer {
    text-align: center;
    padding: 20px;
    background: #f8f9fa;
    border-radius: 5px;
}

#equipmentImageContainer img {
    max-width: 100%;
    max-height: 300px;
    border-radius: 5px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    cursor: pointer;
    transition: transform 0.2s ease;
}

#equipmentImageContainer img:hover {
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

.btn-reactivate {
    background: #234a23;
    color: white;
}
.btn-approve:hover {
    background: #235a25;
    color: white;
}

.btn-reject:hover {
    background: #dc3547;
    color: white;
}

.btn-reactivate:hover {
    background: #235a24;
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

.btn-view {
    background: #234a23;
    color: white;
    border: none;
    padding: 6px 12px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 12px;
}

.btn-view:hover {
    background: #235a24;
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
}

.fullscreen-close:hover {
    color: #ffcccc;
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
    box-shadow: 0 0 5px rgba(0,124,186,0.3);
}

.table-row.hidden {
    display: none !important;
}

@media only screen and (max-width: 768px) {
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
    
    #liveSearch {
        width: 100%;
        margin-bottom: 10px;
    }
    
    .fullscreen-close {
        top: 10px;
        right: 20px;
        font-size: 40px;
    }
}
</style>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script>
$(document).ready(function() {
    // Live search functionality
    $('#liveSearch').on('keyup', function() {
        var searchTerm = $(this).val().toLowerCase();
        
        $('#equipmentTable tbody tr.table-row').each(function() {
            var rowText = $(this).text().toLowerCase();
            
            if (rowText.indexOf(searchTerm) === -1) {
                $(this).addClass('hidden');
            } else {
                $(this).removeClass('hidden');
            }
        });
        
        var visibleRows = $('#equipmentTable tbody tr.table-row:not(.hidden)').length;
        
        if (visibleRows === 0 && searchTerm !== '') {
            if ($('#noResultsRow').length === 0) {
                $('#equipmentTable tbody').append('<tr id="noResultsRow"><td colspan="7" style="text-align: center; padding: 20px; font-style: italic;">No equipment found matching your search</td></tr>');
            }
        } else {
            $('#noResultsRow').remove();
        }
    });
    
    // Clear search functionality
    $('#clearSearch').on('click', function() {
        $('#liveSearch').val('');
        $('#equipmentTable tbody tr.table-row').removeClass('hidden');
        $('#noResultsRow').remove();
        $('#liveSearch').focus();
    });
    
    // Clear search on tab change
    $('.tab').on('click', function() {
        $('#liveSearch').val('');
    });
});

// View equipment details function
function viewEquipment(equipment) {
    // Populate equipment details
    document.getElementById('detailEquipmentId').textContent = equipment.Equipment_id;
    document.getElementById('detailTitle').textContent = equipment.Title || 'N/A';
    document.getElementById('detailBrand').textContent = equipment.Brand || 'N/A';
    document.getElementById('detailModel').textContent = equipment.Model || 'N/A';
    document.getElementById('detailDescription').textContent = equipment.Description || 'No description available';
    document.getElementById('detailHourlyRate').textContent = 'Rs.' + equipment.Hourly_rate + '/hr';
    document.getElementById('detailDailyRate').textContent = 'Rs.' + equipment.Daily_rate + '/day';
    document.getElementById('detailYear').textContent = equipment.Year || 'N/A';
    
    document.getElementById('detailListedDate').textContent = new Date(equipment.listed_date).toLocaleDateString();
    
    // Set status with color
    const statusSpan = document.getElementById('detailStatus');
    if (equipment.Approval_status === 'PEN') {
        statusSpan.innerHTML = '<span style="color: orange;">Pending</span>';
    } else if (equipment.Approval_status === 'CON') {
        statusSpan.innerHTML = '<span style="color: green;">Approved</span>';
    } else {
        statusSpan.innerHTML = '<span style="color: red;">Rejected</span>';
    }
    
    // Populate owner details
    document.getElementById('detailOwnerName').textContent = equipment.owner_name || 'N/A';
    document.getElementById('detailOwnerEmail').textContent = equipment.owner_email || 'N/A';
    document.getElementById('detailOwnerPhone').textContent = equipment.owner_phone || 'N/A';
    
    // Handle equipment image with fullscreen capability
    const imageContainer = document.getElementById('equipmentImageContainer');
    if (equipment.image_url) {
        imageContainer.innerHTML = '<img src="../' + equipment.image_url + '" alt="Equipment Image" onclick="openFullscreenImage(this, \'' + (equipment.Title || 'Equipment') + '\')" title="Click to view fullscreen">';
    } else {
        imageContainer.innerHTML = '<div style="padding: 50px; background: #f0f0f0; border-radius: 5px; color: #666;"><i class="fas fa-image" style="font-size: 48px; margin-bottom: 10px;"></i><br>No Image Available</div>';
    }
    
    // Handle action buttons based on status
    const actionsContainer = document.getElementById('equipmentActions');
    let actionsHTML = '';
    
    const currentStatus = new URLSearchParams(window.location.search).get('status') || 'PEN';
    
    // Button logic based on status - REMOVED DELETE BUTTONS
    if (equipment.Approval_status === 'PEN') {
        // Pending: Show Approve and Reject buttons
        actionsHTML += '<a href="?action=approve&id=' + equipment.Equipment_id + '&status=' + currentStatus + '" class="btn-approve">Approve Equipment</a>';
        actionsHTML += '<a href="?action=reject&id=' + equipment.Equipment_id + '&status=' + currentStatus + '" class="btn-reject">Reject Equipment</a>';
    } else if (equipment.Approval_status === 'CON') {
        // Approved (CON): Show only Reject button
        actionsHTML += '<a href="?action=reject&id=' + equipment.Equipment_id + '&status=' + currentStatus + '" class="btn-reject">Reject Equipment</a>';
    } else if (equipment.Approval_status === 'REJ') {
        // Rejected (REJ): Show only Reactivate button
        actionsHTML += '<a href="?action=reactivate&id=' + equipment.Equipment_id + '&status=' + currentStatus + '" class="btn-reactivate">Reactivate Equipment</a>';
    }
    
    actionsContainer.innerHTML = actionsHTML;
    
    // Show modal
    document.getElementById('equipmentModal').style.display = 'block';
}

function closeEquipmentModal() {
    document.getElementById('equipmentModal').style.display = 'none';
}

// Fullscreen Image Functions
function openFullscreenImage(imgElement, caption) {
    const modal = document.getElementById('fullscreenImageModal');
    const fullscreenImg = document.getElementById('fullscreenImage');
    const captionElement = document.getElementById('fullscreenImageCaption');
    
    modal.style.display = 'block';
    fullscreenImg.src = imgElement.src;
    captionElement.innerHTML = caption || 'Equipment Image';
    
    // Prevent body scrolling
    document.body.style.overflow = 'hidden';
}

function closeFullscreenImage() {
    const modal = document.getElementById('fullscreenImageModal');
    modal.style.display = 'none';
    
    // Restore body scrolling
    document.body.style.overflow = 'auto';
}

// Close modal when clicking outside
window.onclick = function(event) {
    const equipmentModal = document.getElementById('equipmentModal');
    const fullscreenModal = document.getElementById('fullscreenImageModal');
    
    if (event.target == equipmentModal) {
        equipmentModal.style.display = 'none';
    }
    
    if (event.target == fullscreenModal) {
        closeFullscreenImage();
    }
}

// Keyboard navigation
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        const equipmentModal = document.getElementById('equipmentModal');
        const fullscreenModal = document.getElementById('fullscreenImageModal');
        
        if (fullscreenModal.style.display === 'block') {
            closeFullscreenImage();
        } else if (equipmentModal.style.display === 'block') {
            closeEquipmentModal();
        }
    }
});
</script>

<?php require 'footer.php'; ?>
