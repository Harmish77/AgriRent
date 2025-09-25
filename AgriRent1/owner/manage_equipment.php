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

// Handle equipment deletion
if (isset($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);
    
    // Delete from images table first
    $conn->query("DELETE FROM images WHERE image_type='E' AND ID=$delete_id");
    
    // Delete equipment
    if ($conn->query("DELETE FROM equipment WHERE Equipment_id=$delete_id AND Owner_id=$owner_id")) {
        $message = 'Equipment deleted successfully.';
    } else {
        $message = 'Error deleting equipment.';
    }
}

// Filter parameters
$status_filter = $_GET['status'] ?? 'all';
$search_query = $_GET['search'] ?? '';

// Build WHERE clause
$where_clause = "WHERE e.Owner_id = ?";
$params = [$owner_id];
$param_types = "i";

if ($status_filter !== 'all') {
    $where_clause .= " AND e.Approval_status = ?";
    $params[] = $status_filter;
    $param_types .= "s";
}

// Add search functionality
if (!empty($search_query)) {
    $where_clause .= " AND (e.Title LIKE ? OR e.Brand LIKE ? OR e.Model LIKE ?)";
    $search_term = "%" . $search_query . "%";
    $params = array_merge($params, [$search_term, $search_term, $search_term]);
    $param_types .= "sss";
}

// Fetch equipment with images
$sql = "SELECT e.*, i.image_url 
        FROM equipment e 
        LEFT JOIN images i ON (i.image_type = 'E' AND i.ID = e.Equipment_id)
        $where_clause 
        ORDER BY e.listed_date DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($param_types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$equipment_list = [];
while ($row = $result->fetch_assoc()) {
    $equipment_list[] = $row;
}
$stmt->close();

require 'oheader.php';
require 'owner_nav.php';
?>

<link rel="stylesheet" href="../admin.css">

<div class="main-content">
    <div class="page-header">
        <div class="header-left">
            <h1>Manage Equipment</h1>
            <p style="color: #666; margin-bottom: 0;">View, edit, and manage all your agricultural equipment listings</p>
        </div>
        <div class="header-right">
            <a href="add_equipment.php" class="btn-primary">
                <i class="icon">➕</i> Add New Equipment
            </a>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="message"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <!-- Search and Filter Section -->
    <div class="controls-section">
        <!-- Search Bar -->
        <div class="search-container">
            <form method="GET" class="search-form">
                <input type="text" 
                       name="search" 
                       placeholder="Search by title, brand, or model..." 
                       value="<?= htmlspecialchars($search_query) ?>"
                       class="search-input">
                <button type="submit" class="search-btn">
                    <i class="icon">🔍</i> Search
                </button>
                <?php if (!empty($search_query)): ?>
                    <a href="?" class="clear-search">Clear</a>
                <?php endif; ?>
                <!-- Preserve status filter in search -->
                <?php if ($status_filter !== 'all'): ?>
                    <input type="hidden" name="status" value="<?= $status_filter ?>">
                <?php endif; ?>
            </form>
        </div>

        <!-- Filter Tabs -->
        <div class="tabs">
            <a href="?<?= !empty($search_query) ? 'search=' . urlencode($search_query) . '&' : '' ?>status=all" 
               class="tab <?= $status_filter == 'all' ? 'active' : '' ?>">All Equipment</a>
            <a href="?<?= !empty($search_query) ? 'search=' . urlencode($search_query) . '&' : '' ?>status=PEN" 
               class="tab <?= $status_filter == 'PEN' ? 'active' : '' ?>">Pending</a>
            <a href="?<?= !empty($search_query) ? 'search=' . urlencode($search_query) . '&' : '' ?>status=CON" 
               class="tab <?= $status_filter == 'CON' ? 'active' : '' ?>">Approved</a>
            <a href="?<?= !empty($search_query) ? 'search=' . urlencode($search_query) . '&' : '' ?>status=REJ" 
               class="tab <?= $status_filter == 'REJ' ? 'active' : '' ?>">Rejected</a>
        </div>
    </div>

    <!-- Results Summary -->
    <?php if (!empty($search_query)): ?>
        <div class="search-results-info">
            Found <?= count($equipment_list) ?> equipment(s) matching "<?= htmlspecialchars($search_query) ?>"
        </div>
    <?php endif; ?>

    <?php if (count($equipment_list) > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>Image</th>
                    <th>Title</th>
                    <th>Brand</th>
                    <th>Model</th>
                    <th>Year</th>
                    <th>Hourly Rate</th>
                    <th>Daily Rate</th>
                    <th>Status</th>
                    <th>Listed Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($equipment_list as $equipment): ?>
                    <?php
                    $status_text = '';
                    $status_class = '';
                    switch ($equipment['Approval_status']) {
                        case 'PEN':
                            $status_text = 'Pending';
                            $status_class = 'status-pending';
                            break;
                        case 'CON':
                            $status_text = 'Approved';
                            $status_class = 'status-approved';
                            break;
                        case 'REJ':
                            $status_text = 'Rejected';
                            $status_class = 'status-rejected';
                            break;
                    }
                    ?>
                    <tr>
                        <td>
                            <?php if (!empty($equipment['image_url'])): ?>
                                <img src="../<?= htmlspecialchars($equipment['image_url']) ?>" 
                                     alt="Equipment Image" 
                                     style="width:60px; height:60px; object-fit:cover; border:1px solid #ddd; cursor:pointer;"
                                     onclick="openImageModal(this)">
                            <?php else: ?>
                                <div style="width:60px; height:60px; background:#f5f5f5; display:flex; align-items:center; justify-content:center; border:1px solid #ddd; font-size:10px; text-align:center;">
                                    No Image
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($equipment['Title']) ?></td>
                        <td><?= htmlspecialchars($equipment['Brand']) ?></td>
                        <td><?= htmlspecialchars($equipment['Model']) ?></td>
                        <td><?= htmlspecialchars($equipment['Year'] ?? 'N/A') ?></td>
                        <td>₹<?= number_format($equipment['Hourly_rate'] ?? 0, 2) ?>/hr</td>
                        <td>₹<?= number_format($equipment['Daily_rate'] ?? 0, 2) ?>/day</td>
                        <td><span class="<?= $status_class ?>"><?= $status_text ?></span></td>
                        <td><?= date('M j, Y', strtotime($equipment['listed_date'])) ?></td>
                        <td>
                            <a href="view_equipment.php?id=<?= $equipment['Equipment_id'] ?>" style="color: #17a2b8;">View</a> | 
                            <a href="edit_equipment.php?id=<?= $equipment['Equipment_id'] ?>" style="color: #28a745;">Edit</a> | 
                            <a href="?delete_id=<?= $equipment['Equipment_id'] ?>&status=<?= $status_filter ?><?= !empty($search_query) ? '&search=' . urlencode($search_query) : '' ?>" 
                               style="color: #dc3545;" 
                               onclick="return confirm('Are you sure you want to delete this equipment?')">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="no-equipment">
            <?php if (!empty($search_query)): ?>
                <h3>No Equipment Found</h3>
                <p>No equipment matches your search "<?= htmlspecialchars($search_query) ?>"</p>
                <p>Try searching with different keywords or <a href="?<?= $status_filter !== 'all' ? 'status=' . $status_filter : '' ?>">clear your search</a></p>
            <?php elseif ($status_filter !== 'all'): ?>
                <h3>No equipment found with status: <?= strtoupper($status_filter) ?></h3>
                <p>Try changing the filter or add new equipment.</p>
            <?php else: ?>
                <h3>No Equipment Found</h3>
                <p>Start by adding your first piece of agricultural equipment.</p>
            <?php endif; ?>
            <a href="add_equipment.php" class="btn-primary">➕ Add Your First Equipment</a>
        </div>
    <?php endif; ?>
</div>

<!-- Image Modal -->
<div id="imageModal" class="modal" style="display: none;">
    <span class="close" onclick="closeImageModal()">&times;</span>
    <img class="modal-content" id="modalImage">
</div>

<style>
/* Page Header Styles */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 2px solid #f0f0f0;
}

.header-left h1 {
    margin: 0 0 5px 0;
    color: #234a23;
}

.header-right {
    flex-shrink: 0;
}

/* Button Styles */
.btn-primary {
    background: #234a23;
    color: white;
    padding: 12px 24px;
    text-decoration: none;
    border-radius: 6px;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: background-color 0.3s;
    border: none;
    cursor: pointer;
}

.btn-primary:hover {
    background: #1a3a1a;
}

.btn-primary .icon {
    font-size: 16px;
}

/* Controls Section */
.controls-section {
    margin-bottom: 25px;
}

/* Search Styles */
.search-container {
    margin-bottom: 20px;
}

.search-form {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.search-input {
    flex: 1;
    min-width: 300px;
    padding: 12px 16px;
    border: 2px solid #ddd;
    border-radius: 6px;
    font-size: 14px;
}

.search-input:focus {
    outline: none;
    border-color: #234a23;
}

.search-btn {
    background: #234a23;
    color: white;
    padding: 12px 20px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
    font-weight: 500;
    transition: background-color 0.3s;
}

.search-btn:hover {
    background: #1a3a1a;
}

.clear-search {
    color: #666;
    text-decoration: none;
    padding: 12px 16px;
    border: 1px solid #ddd;
    border-radius: 6px;
    transition: all 0.3s;
}

.clear-search:hover {
    background: #f5f5f5;
    color: #234a23;
}

/* Search Results Info */
.search-results-info {
    background: #e8f4fd;
    color: #0c5460;
    padding: 10px 15px;
    border-radius: 6px;
    margin-bottom: 20px;
    font-size: 14px;
}

/* Existing Styles */
.tabs {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.tab {
    padding: 10px 20px;
    text-decoration: none;
    color: #666;
    border: 1px solid #ddd;
    border-radius: 4px;
    transition: all 0.3s;
}

.tab.active,
.tab:hover {
    background: #234a23;
    color: white;
}

table {
    width: 100%;
    border-collapse: collapse;
    background: white;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
}

th, td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #eee;
}

th {
    background: #f8f9fa;
    font-weight: bold;
    color: #234a23;
}

.status-pending {
    background: #fff3cd;
    color: #856404;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 12px;
}

.status-approved {
    background: #d4edda;
    color: #155724;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 12px;
}

.status-rejected {
    background: #f8d7da;
    color: #721c24;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 12px;
}

.no-equipment {
    text-align: center;
    padding: 60px 20px;
    background: white;
    border-radius: 8px;
    color: #666;
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

/* Responsive Design */
@media (max-width: 768px) {
    .page-header {
        flex-direction: column;
        gap: 20px;
        align-items: stretch;
    }
    
    .search-input {
        min-width: auto;
        width: 100%;
    }
    
    .search-form {
        flex-direction: column;
    }
    
    .search-btn, .clear-search {
        width: 100%;
        justify-content: center;
    }
    
    table {
        font-size: 12px;
    }
    
    th, td {
        padding: 8px 4px;
    }
    
    .tabs {
        flex-wrap: wrap;
    }
}

@media (max-width: 480px) {
    .btn-primary {
        width: 100%;
        justify-content: center;
    }
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

// Auto-submit search form on Enter key
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.querySelector('.search-input');
    if (searchInput) {
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.closest('form').submit();
            }
        });
    }
});
</script>

<?php require 'ofooter.php'; ?>
