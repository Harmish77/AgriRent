<?php
session_start();
require_once '../auth/config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'A') {
    header('Location: ../login.php');
    exit;
}

// Handle review actions
$message = '';
$error = '';

if (isset($_GET['action']) && isset($_GET['id'])) {
    $review_id = intval($_GET['id']);
    $action = $_GET['action'];
    
    if ($action === 'delete') {
        $delete_query = "DELETE FROM reviews WHERE Review_id = ?";
        $stmt = $conn->prepare($delete_query);
        if ($stmt) {
            $stmt->bind_param('i', $review_id);
            if ($stmt->execute()) {
                $message = "Review deleted successfully!";
            } else {
                $error = "Error deleting review: " . $conn->error;
            }
            $stmt->close();
        } else {
            $error = "Error preparing delete statement: " . $conn->error;
        }
    }
}

// Get filter parameters
$rating_filter = isset($_GET['rating']) ? intval($_GET['rating']) : 0;
$type_filter = isset($_GET['type']) ? $_GET['type'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Get basic reviews with correct column name
$basic_reviews_query = "
    SELECT r.*, u.Name as reviewer_name, u.Email as reviewer_email, u.Phone as reviewer_phone
    FROM reviews r 
    JOIN users u ON r.Reviewer_id = u.user_id 
    ORDER BY r.created_date DESC
";

$basic_reviews = $conn->query($basic_reviews_query);
if (!$basic_reviews) {
    die("Error fetching reviews: " . $conn->error);
}

// Process reviews and add item details
$reviews_data = [];
while ($review = $basic_reviews->fetch_assoc()) {
    // Get item details based on review type
    if ($review['Review_type'] == 'E') {
        // Equipment review
        $item_query = "SELECT e.Title as item_name, u.Name as owner_name 
                       FROM equipment e 
                       LEFT JOIN users u ON e.Owner_id = u.user_id 
                       WHERE e.Equipment_id = " . intval($review['ID']);
        $item_result = $conn->query($item_query);
        $item_data = $item_result ? $item_result->fetch_assoc() : null;
    } else {
        // Product review
        $item_query = "SELECT p.Name as item_name, u.Name as owner_name 
                       FROM product p 
                       LEFT JOIN users u ON p.seller_id = u.user_id 
                       WHERE p.product_id = " . intval($review['ID']);
        $item_result = $conn->query($item_query);
        $item_data = $item_result ? $item_result->fetch_assoc() : null;
    }
    
    // Add item data to review
    $review['item_name'] = $item_data ? $item_data['item_name'] : 'Unknown';
    $review['owner_name'] = $item_data ? $item_data['owner_name'] : 'Unknown';
    
    // Apply filters
    $include_review = true;
    
    if ($rating_filter > 0 && $review['Rating'] != $rating_filter) {
        $include_review = false;
    }
    
    if (!empty($type_filter) && $review['Review_type'] != $type_filter) {
        $include_review = false;
    }
    
    if (!empty($search)) {
        $search_text = strtolower($review['reviewer_name'] . ' ' . $review['reviewer_email'] . ' ' . $review['comment']);
        if (strpos($search_text, strtolower($search)) === false) {
            $include_review = false;
        }
    }
    
    if ($include_review) {
        $reviews_data[] = $review;
    }
}

// Get statistics with error handling
function safeQuery($conn, $query, $default = 0) {
    $result = $conn->query($query);
    if ($result === false) {
        return $default;
    }
    $row = $result->fetch_assoc();
    return $row ? ($row['count'] ?? $row['avg'] ?? $default) : $default;
}

$total_reviews = safeQuery($conn, "SELECT COUNT(*) as count FROM reviews");
$avg_rating = safeQuery($conn, "SELECT AVG(Rating) as avg FROM reviews");
$equipment_reviews = safeQuery($conn, "SELECT COUNT(*) as count FROM reviews WHERE Review_type = 'E'");
$product_reviews = safeQuery($conn, "SELECT COUNT(*) as count FROM reviews WHERE Review_type = 'P'");

// Rating distribution
$rating_stats = [];
for ($i = 1; $i <= 5; $i++) {
    $rating_stats[$i] = safeQuery($conn, "SELECT COUNT(*) as count FROM reviews WHERE Rating = $i");
}

require 'header.php';
require 'admin_nav.php';
?>

<div class="main-content">
    <!-- Header Section -->
    <div class="reviews-header">
        <div class="header-content">
            <div class="header-title">
                <h1><i class="fas fa-star"></i>Customer Reviews Management</h1>
            </div>
            <div class="header-stats">
                <div class="stat-card total">
                    <div class="stat-number"><?= number_format($total_reviews) ?></div>
                    <div class="stat-label">Total Reviews</div>
                </div>
                <div class="stat-card rating">
                    <div class="stat-number"><?= number_format($avg_rating, 1) ?></div>
                    <div class="stat-label">Average Rating</div>
                    <div class="star-display">
                        <?php for($i = 1; $i <= 5; $i++): ?>
                            <i class="fas fa-star <?= $i <= round($avg_rating) ? 'filled' : '' ?>"></i>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Messages -->
    <?php if (!empty($message)): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <!-- Filters and Search -->
    <div class="controls-panel">
        <div class="filters-section">
            <h3><i class="fas fa-filter"></i> Filter Reviews</h3>
            <form method="GET" class="filter-form" id="filterForm">
                <div class="filter-group">
                    <label for="search">Search:</label>
                    <div class="search-input-container">
                        <i class="fas fa-search"></i>
                        <input type="text" 
                               id="search" 
                               name="search" 
                               placeholder="Search by reviewer name, email, or comment..." 
                               value="<?= htmlspecialchars($search) ?>"
                               class="search-input">
                    </div>
                </div>
                
                <div class="filter-group">
                    <label for="rating">Rating:</label>
                    <select id="rating" name="rating" class="filter-select">
                        <option value="0">All Ratings</option>
                        <?php for($i = 5; $i >= 1; $i--): ?>
                            <option value="<?= $i ?>" <?= $rating_filter == $i ? 'selected' : '' ?>>
                                <?= $i ?> Star<?= $i != 1 ? 's' : '' ?> (<?= $rating_stats[$i] ?>)
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="type">Type:</label>
                    <select id="type" name="type" class="filter-select">
                        <option value="">All Types</option>
                        <option value="E" <?= $type_filter === 'E' ? 'selected' : '' ?>>
                            Equipment (<?= $equipment_reviews ?>)
                        </option>
                        <option value="P" <?= $type_filter === 'P' ? 'selected' : '' ?>>
                            Products (<?= $product_reviews ?>)
                        </option>
                    </select>
                </div>
                
                <div class="filter-actions">
                    <button type="submit" class="btn btn-filter">
                        <i class="fas fa-search"></i> Apply Filters
                    </button>
                    <a href="?" class="btn btn-clear">
                        <i class="fas fa-times"></i> Clear All
                    </a>
                </div>
            </form>
        </div>

        <!-- Rating Distribution -->
        <div class="rating-distribution">
            <h3><i class="fas fa-chart-bar"></i> Rating Distribution</h3>
            <div class="rating-bars">
                <?php for($i = 5; $i >= 1; $i--): ?>
                    <div class="rating-bar-item">
                        <div class="rating-label">
                            <?= $i ?> <i class="fas fa-star"></i>
                        </div>
                        <div class="rating-bar">
                            <div class="rating-fill" 
                                 style="width: <?= $total_reviews > 0 ? ($rating_stats[$i] / $total_reviews) * 100 : 0 ?>%"
                                 data-count="<?= $rating_stats[$i] ?>">
                            </div>
                        </div>
                        <div class="rating-count"><?= $rating_stats[$i] ?></div>
                    </div>
                <?php endfor; ?>
            </div>
        </div>
    </div>

    <!-- Reviews Table -->
    <div class="reviews-container">
        <div class="table-header">
            <h3><i class="fas fa-list"></i> Reviews List</h3>
            <div class="table-info">
                Showing <?= count($reviews_data) ?> of <?= $total_reviews ?> reviews
            </div>
        </div>
        
        <div class="table-wrapper">
            <?php if (!empty($reviews_data)): ?>
                <table class="reviews-table" id="reviewsTable">
                    <thead>
                        <tr>
                            <th>Review ID</th>
                            <th>Reviewer Info</th>
                            <th>Item Details</th>
                            <th>Rating</th>
                            <th>Review</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($reviews_data as $review): ?>
                        <tr class="review-row" data-rating="<?= $review['Rating'] ?>" data-type="<?= $review['Review_type'] ?>">
                            <td class="review-id">
                                <div class="id-badge">R-<?= str_pad($review['Review_id'], 4, '0', STR_PAD_LEFT) ?></div>
                            </td>
                            
                            <td class="reviewer-info">
                                <div class="reviewer-card">
                                    <div class="reviewer-avatar">
                                        <i class="fas fa-user-circle"></i>
                                    </div>
                                    <div class="reviewer-details">
                                        <div class="reviewer-name"><?= htmlspecialchars($review['reviewer_name']) ?></div>
                                        <div class="reviewer-email">
                                            <i class="fas fa-envelope"></i> 
                                            <?= htmlspecialchars($review['reviewer_email']) ?>
                                        </div>
                                        <?php if (!empty($review['reviewer_phone'])): ?>
                                            <div class="reviewer-phone">
                                                <i class="fas fa-phone"></i> 
                                                <?= htmlspecialchars($review['reviewer_phone']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            
                            <td class="item-info">
                                <div class="item-card">
                                    <div class="item-type">
                                        <span class="type-badge type-<?= strtolower($review['Review_type']) ?>">
                                            <i class="fas fa-<?= $review['Review_type'] == 'E' ? 'tractor' : 'seedling' ?>"></i>
                                            <?= $review['Review_type'] == 'E' ? 'Equipment' : 'Product' ?>
                                        </span>
                                    </div>
                                    <div class="item-name"><?= htmlspecialchars($review['item_name']) ?></div>
                                    <div class="item-id">ID: <?= $review['Review_type'] . '-' . $review['ID'] ?></div>
                                    <div class="item-owner">
                                        <i class="fas fa-user"></i> 
                                        <?= htmlspecialchars($review['owner_name']) ?>
                                    </div>
                                </div>
                            </td>
                            
                            <td class="rating-info">
                                <div class="rating-display">
                                    <div class="stars">
                                        <?php for($i = 1; $i <= 5; $i++): ?>
                                            <i class="fas fa-star <?= $i <= $review['Rating'] ? 'filled' : 'empty' ?>"></i>
                                        <?php endfor; ?>
                                    </div>
                                    <div class="rating-number"><?= $review['Rating'] ?>/5</div>
                                    <div class="rating-label">
                                        <?php
                                        $rating_labels = [5 => 'Excellent', 4 => 'Good', 3 => 'Average', 2 => 'Poor', 1 => 'Terrible'];
                                        echo $rating_labels[$review['Rating']];
                                        ?>
                                    </div>
                                </div>
                            </td>
                            
                            <td class="review-content">
                                <div class="comment-container">
                                    <?php if (!empty($review['comment'])): ?>
                                        <div class="comment-preview">
                                            <?= htmlspecialchars(substr($review['comment'], 0, 100)) ?>
                                            <?php if (strlen($review['comment']) > 100): ?>
                                                <span class="read-more" onclick="showFullComment(this)" data-full-comment="<?= htmlspecialchars($review['comment']) ?>">
                                                    ... <span class="read-more-btn">Read More</span>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="no-comment">
                                            <i class="fas fa-comment-slash"></i>
                                            No written review
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            
                            <td class="date-info">
                                <div class="date-display">
                                    <div class="date-main"><?= date('M d, Y', strtotime($review['created_date'])) ?></div>
                                    <div class="date-time"><?= date('g:i A', strtotime($review['created_date'])) ?></div>
                                    <div class="date-relative">
                                        <?php
                                        $days_ago = floor((time() - strtotime($review['created_date'])) / (60 * 60 * 24));
                                        if ($days_ago == 0) echo "Today";
                                        elseif ($days_ago == 1) echo "Yesterday";
                                        else echo "$days_ago days ago";
                                        ?>
                                    </div>
                                </div>
                            </td>
                            
                            <td class="actions">
                                <div class="action-buttons">
                                    <button class="btn-action btn-view" onclick="viewReviewDetails(<?= htmlspecialchars(json_encode($review)) ?>)">
                                        <i class="fas fa-eye"></i>
                                        <span>View</span>
                                    </button>
                                    <button class="btn-action btn-delete" onclick="confirmDelete(<?= $review['Review_id'] ?>)">
                                        <i class="fas fa-trash"></i>
                                        <span>Delete</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="no-reviews">
                    <div class="no-reviews-icon">
                        <i class="fas fa-star"></i>
                    </div>
                    <h3>No Reviews Found</h3>
                    <p>
                        <?php if (!empty($search) || $rating_filter > 0 || !empty($type_filter)): ?>
                            No reviews match your current filters. Try adjusting your search criteria.
                        <?php else: ?>
                            No customer reviews have been submitted yet. Reviews will appear here once customers start rating equipment and products.
                        <?php endif; ?>
                    </p>
                    <?php if (!empty($search) || $rating_filter > 0 || !empty($type_filter)): ?>
                        <a href="?" class="btn btn-primary">Clear Filters</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Quick Stats Summary -->
    <?php if (!empty($reviews_data)): ?>
    <div class="summary-stats">
        <div class="summary-card">
            <h3><i class="fas fa-chart-pie"></i> Review Summary</h3>
            <div class="stats-grid">
                <div class="stat-item">
                    <div class="stat-icon excellent">
                        <i class="fas fa-star"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?= $rating_stats[5] ?></div>
                        <div class="stat-desc">Excellent (5★)</div>
                        <div class="stat-percent"><?= $total_reviews > 0 ? round(($rating_stats[5] / $total_reviews) * 100, 1) : 0 ?>%</div>
                    </div>
                </div>
                
                <div class="stat-item">
                    <div class="stat-icon good">
                        <i class="fas fa-thumbs-up"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?= $rating_stats[4] + $rating_stats[3] ?></div>
                        <div class="stat-desc">Good (3-4★)</div>
                        <div class="stat-percent"><?= $total_reviews > 0 ? round((($rating_stats[4] + $rating_stats[3]) / $total_reviews) * 100, 1) : 0 ?>%</div>
                    </div>
                </div>
                
                <div class="stat-item">
                    <div class="stat-icon poor">
                        <i class="fas fa-thumbs-down"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?= $rating_stats[2] + $rating_stats[1] ?></div>
                        <div class="stat-desc">Poor (1-2★)</div>
                        <div class="stat-percent"><?= $total_reviews > 0 ? round((($rating_stats[2] + $rating_stats[1]) / $total_reviews) * 100, 1) : 0 ?>%</div>
                    </div>
                </div>
                
                <div class="stat-item">
                    <div class="stat-icon equipment">
                        <i class="fas fa-tractor"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?= $equipment_reviews ?></div>
                        <div class="stat-desc">Equipment Reviews</div>
                        <div class="stat-percent"><?= $total_reviews > 0 ? round(($equipment_reviews / $total_reviews) * 100, 1) : 0 ?>%</div>
                    </div>
                </div>
                
                <div class="stat-item">
                    <div class="stat-icon products">
                        <i class="fas fa-seedling"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?= $product_reviews ?></div>
                        <div class="stat-desc">Product Reviews</div>
                        <div class="stat-percent"><?= $total_reviews > 0 ? round(($product_reviews / $total_reviews) * 100, 1) : 0 ?>%</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Review Details Modal -->
<div id="reviewModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-star"></i> Review Details</h3>
            <span class="modal-close" onclick="closeReviewModal()">&times;</span>
        </div>
        <div class="modal-body" id="reviewModalBody">
            <!-- Content will be populated by JavaScript -->
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="modal">
    <div class="modal-content delete-modal">
        <div class="modal-header">
            <h3><i class="fas fa-exclamation-triangle"></i> Confirm Deletion</h3>
            <span class="modal-close" onclick="closeDeleteModal()">&times;</span>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to delete this review? This action cannot be undone.</p>
            <div class="modal-actions">
                <button class="btn btn-danger" id="confirmDeleteBtn">
                    <i class="fas fa-trash"></i> Delete Review
                </button>
                <button class="btn btn-secondary" onclick="closeDeleteModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
            </div>
        </div>
    </div>
</div>

<style>
/* Main Layout */
.main-content {
    padding: 20px;
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    min-height: 100vh;
}

/* Header Section */
.reviews-header {
    background: linear-gradient(135deg, #234a23, #2d5a2d);
    border-radius: 15px;
    padding: 30px;
    margin-bottom: 30px;
    color: white;
    box-shadow: 0 8px 25px rgba(35, 74, 35, 0.3);
}

.header-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 20px;
}

.header-title h1 {
    margin: 0 0 8px 0;
    font-size: 2.2rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 12px;
    color: white;
}

.subtitle {
    margin: 0;
    opacity: 0.9;
    font-size: 1.1rem;
}

.header-stats {
    display: flex;
    gap: 20px;
}

.stat-card {
    background: rgba(255, 255, 255, 0.15);
    padding: 20px;
    border-radius: 12px;
    text-align: center;
    backdrop-filter: blur(10px);
    min-width: 120px;
}

.stat-number {
    font-size: 2rem;
    font-weight: 700;
    margin-bottom: 5px;
}

.stat-label {
    font-size: 0.9rem;
    opacity: 0.9;
}

.star-display {
    margin-top: 8px;
}

.star-display .fa-star {
    color: #FFD700;
    margin: 0 2px;
}

.star-display .fa-star.filled {
    color: #FFD700;
}

/* Alerts */
.alert {
    padding: 15px 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 500;
}

.alert-success {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.alert-error {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

/* Controls Panel */
.controls-panel {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 30px;
    margin-bottom: 30px;
}

.filters-section,
.rating-distribution {
    background: white;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
}

.filters-section h3,
.rating-distribution h3 {
    margin: 0 0 20px 0;
    color: #234a23;
    font-size: 1.3rem;
    display: flex;
    align-items: center;
    gap: 10px;
}

.filter-form {
    display: grid;
    grid-template-columns: 1fr 150px 150px auto;
    gap: 20px;
    align-items: end;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.filter-group label {
    font-weight: 600;
    color: #495057;
    font-size: 0.9rem;
}

.search-input-container {
    position: relative;
}

.search-input-container i {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #6c757d;
}

.search-input {
    width: 100%;
    padding: 12px 15px 12px 40px;
    border: 2px solid #e9ecef;
    border-radius: 8px;
    font-size: 14px;
    transition: all 0.3s ease;
}

.search-input:focus {
    outline: none;
    border-color: #234a23;
    box-shadow: 0 0 0 3px rgba(35, 74, 35, 0.1);
}

.filter-select {
    padding: 12px 15px;
    border: 2px solid #e9ecef;
    border-radius: 8px;
    font-size: 14px;
    background: white;
    cursor: pointer;
}

.filter-select:focus {
    outline: none;
    border-color: #234a23;
}

.filter-actions {
    display: flex;
    gap: 10px;
}

.btn {
    padding: 12px 20px;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    transition: all 0.3s ease;
}

.btn-filter {
    background: #234a23;
    color: white;
}

.btn-filter:hover {
    background: #1e3e1e;
    transform: translateY(-2px);
}

.btn-clear {
    background: #6c757d;
    color: white;
}

.btn-clear:hover {
    background: #545b62;
}

/* Rating Distribution */
.rating-bars {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.rating-bar-item {
    display: flex;
    align-items: center;
    gap: 12px;
}

.rating-label {
    min-width: 60px;
    font-weight: 600;
    color: #495057;
    display: flex;
    align-items: center;
    gap: 5px;
}

.rating-label .fa-star {
    color: #FFD700;
}

.rating-bar {
    flex: 1;
    height: 8px;
    background: #e9ecef;
    border-radius: 4px;
    overflow: hidden;
}

.rating-fill {
    height: 100%;
    background: linear-gradient(90deg, #234a23, #2d5a2d);
    border-radius: 4px;
    transition: width 0.5s ease;
}

.rating-count {
    min-width: 30px;
    text-align: center;
    font-weight: 600;
    color: #495057;
}

/* Reviews Container */
.reviews-container {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    overflow: hidden;
    margin-bottom: 30px;
}

.table-header {
    background: linear-gradient(135deg, #234a23, #2d5a2d);
    color: white;
    padding: 20px 25px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.table-header h3 {
    margin: 0;
    font-size: 1.3rem;
    display: flex;
    align-items: center;
    gap: 10px;
}

.table-info {
    font-size: 0.9rem;
    opacity: 0.9;
}

.table-wrapper {
    overflow-x: auto;
}

.reviews-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1200px;
}

.reviews-table th {
    padding: 15px 12px;
    text-align: left;
    font-weight: 600;
    color: #495057;
    background: #f8f9fa;
    border-bottom: 2px solid #e9ecef;
    font-size: 0.9rem;
}

.reviews-table td {
    padding: 15px 12px;
    border-bottom: 1px solid #e9ecef;
    vertical-align: top;
}

.review-row:hover {
    background: #f8f9fa;
}

/* Table Cell Styles */
.review-id .id-badge {
    background: #234a23;
    color: white;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
    display: inline-block;
}

.reviewer-card {
    display: flex;
    align-items: flex-start;
    gap: 12px;
}

.reviewer-avatar {
    font-size: 2rem;
    color: #6c757d;
}

.reviewer-details {
    min-width: 0;
}

.reviewer-name {
    font-weight: 600;
    color: #234a23;
    margin-bottom: 4px;
}

.reviewer-email,
.reviewer-phone {
    font-size: 0.8rem;
    color: #6c757d;
    margin-bottom: 2px;
    display: flex;
    align-items: center;
    gap: 5px;
}

.item-card {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.type-badge {
    padding: 4px 10px;
    border-radius: 15px;
    font-size: 0.75rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    width: fit-content;
}

.type-e {
    background: #e3f2fd;
    color: #1565c0;
}

.type-p {
    background: #e8f5e8;
    color: #2e7d32;
}

.item-name {
    font-weight: 600;
    color: #234a23;
    font-size: 0.9rem;
}

.item-id,
.item-owner {
    font-size: 0.8rem;
    color: #6c757d;
    display: flex;
    align-items: center;
    gap: 5px;
}

.rating-display {
    text-align: center;
}

.stars {
    margin-bottom: 8px;
}

.stars .fa-star {
    font-size: 1.2rem;
    margin: 0 1px;
}

.stars .fa-star.filled {
    color: #FFD700;
}

.stars .fa-star.empty {
    color: #e9ecef;
}

.rating-number {
    font-weight: 700;
    color: #234a23;
    font-size: 1.1rem;
    margin-bottom: 4px;
}

.rating-label {
    font-size: 0.8rem;
    color: #6c757d;
    font-weight: 500;
}

.comment-container {
    max-width: 300px;
}

.comment-preview {
    line-height: 1.4;
    color: #495057;
    font-size: 0.9rem;
}

.read-more {
    color: #234a23;
    cursor: pointer;
    font-weight: 500;
}

.read-more-btn {
    text-decoration: underline;
}

.no-comment {
    color: #6c757d;
    font-style: italic;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.9rem;
}

.date-display {
    text-align: center;
}

.date-main {
    font-weight: 600;
    color: #234a23;
    margin-bottom: 4px;
}

.date-time {
    font-size: 0.8rem;
    color: #6c757d;
    margin-bottom: 4px;
}

.date-relative {
    font-size: 0.75rem;
    color: #adb5bd;
    background: #f8f9fa;
    padding: 2px 8px;
    border-radius: 10px;
    display: inline-block;
}

.action-buttons {
    display: flex;
    flex-direction: column;
    gap: 8px;
    min-width: 80px;
}

.btn-action {
    padding: 8px 12px;
    border: none;
    border-radius: 6px;
    font-size: 0.8rem;
    font-weight: 500;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
    justify-content: center;
    transition: all 0.3s ease;
}

.btn-view {
    background: #17a2b8;
    color: white;
}

.btn-view:hover {
    background: #138496;
}

.btn-delete {
    background: #dc3545;
    color: white;
}

.btn-delete:hover {
    background: #c82333;
}

/* Summary Stats */
.summary-stats {
    margin-top: 30px;
}

.summary-card {
    background: white;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
}

.summary-card h3 {
    margin: 0 0 20px 0;
    color: #234a23;
    font-size: 1.3rem;
    display: flex;
    align-items: center;
    gap: 10px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
}

.stat-item {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 20px;
    background: #f8f9fa;
    border-radius: 10px;
    border-left: 4px solid var(--stat-color);
}

.stat-icon {
    width: 50px;
    height: 50px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.5rem;
}

.stat-icon.excellent { background: #28a745; --stat-color: #28a745; }
.stat-icon.good { background: #17a2b8; --stat-color: #17a2b8; }
.stat-icon.poor { background: #dc3545; --stat-color: #dc3545; }
.stat-icon.equipment { background: #6f42c1; --stat-color: #6f42c1; }
.stat-icon.products { background: #20c997; --stat-color: #20c997; }

.stat-content {
    flex: 1;
}

.stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: #234a23;
    margin-bottom: 4px;
}

.stat-desc {
    font-size: 0.9rem;
    color: #495057;
    margin-bottom: 4px;
}

.stat-percent {
    font-size: 0.8rem;
    color: #6c757d;
    font-weight: 500;
}

/* No Reviews State */
.no-reviews {
    text-align: center;
    padding: 60px 20px;
    color: #6c757d;
}

.no-reviews-icon {
    font-size: 4rem;
    color: #dee2e6;
    margin-bottom: 20px;
}

.no-reviews h3 {
    color: #495057;
    margin-bottom: 10px;
}

.no-reviews p {
    margin-bottom: 20px;
    line-height: 1.6;
}

.btn-primary {
    background: #234a23;
    color: white;
}

.btn-primary:hover {
    background: #1e3e1e;
}

/* Modal Styles */
.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(5px);
}

.modal-content {
    background-color: white;
    margin: 5% auto;
    border-radius: 12px;
    width: 90%;
    max-width: 600px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
    overflow: hidden;
}

.modal-header {
    background: linear-gradient(135deg, #234a23, #2d5a2d);
    color: white;
    padding: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h3 {
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.modal-close {
    font-size: 24px;
    cursor: pointer;
    opacity: 0.8;
}

.modal-close:hover {
    opacity: 1;
}

.modal-body {
    padding: 25px;
}

.delete-modal .modal-body {
    text-align: center;
}

.modal-actions {
    display: flex;
    gap: 15px;
    justify-content: center;
    margin-top: 20px;
}

.btn-danger {
    background: #dc3545;
    color: white;
}

.btn-danger:hover {
    background: #c82333;
}

.btn-secondary {
    background: #6c757d;
    color: white;
}

.btn-secondary:hover {
    background: #545b62;
}

/* Modal Detail Styles */
.review-detail-content {
    display: flex;
    flex-direction: column;
    gap: 25px;
}

.detail-section {
    border-bottom: 1px solid #e9ecef;
    padding-bottom: 20px;
}

.detail-section:last-child {
    border-bottom: none;
    padding-bottom: 0;
}

.detail-section h4 {
    color: #234a23;
    margin: 0 0 15px 0;
    font-size: 1.1rem;
    display: flex;
    align-items: center;
    gap: 8px;
}

.detail-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 15px;
}

.detail-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.detail-item strong {
    color: #495057;
    font-size: 0.9rem;
}

.rating-detail {
    display: flex;
    align-items: center;
    gap: 20px;
    margin-bottom: 15px;
}

.stars-large .fa-star {
    font-size: 1.5rem;
    margin: 0 2px;
    color: #FFD700;
}

.rating-info {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.rating-score {
    font-size: 1.3rem;
    font-weight: 700;
    color: #234a23;
}

.comment-detail {
    margin-top: 15px;
}

.comment-text {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
    margin-top: 8px;
    line-height: 1.5;
    color: #495057;
}

.no-comment-detail {
    color: #6c757d;
    font-style: italic;
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 15px;
}

.date-detail {
    color: #495057;
}

.date-full {
    font-weight: 500;
}

/* Responsive Design */
@media (max-width: 1200px) {
    .controls-panel {
        grid-template-columns: 1fr;
    }
    
    .filter-form {
        grid-template-columns: 1fr;
        gap: 15px;
    }
    
    .filter-actions {
        justify-content: center;
    }
    
    .detail-grid {
        grid-template-columns: 1fr;
    }
    
    .stats-grid {
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    }
}

@media (max-width: 768px) {
    .header-content {
        flex-direction: column;
        text-align: center;
    }
    
    .header-stats {
        flex-wrap: wrap;
        justify-content: center;
    }
    
    .table-header {
        flex-direction: column;
        gap: 10px;
        text-align: center;
    }
    
    .action-buttons {
        flex-direction: row;
    }
    
    .modal-content {
        width: 95%;
        margin: 10% auto;
    }
    
    .rating-detail {
        flex-direction: column;
        align-items: center;
        text-align: center;
    }
    
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .stat-item {
        flex-direction: column;
        text-align: center;
    }
}

/* Print Styles */
@media print {
    .controls-panel,
    .actions,
    .modal {
        display: none !important;
    }
    
    .reviews-table {
        font-size: 12px;
    }
    
    .main-content {
        background: white !important;
    }
}
</style>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script>
// Show full comment
function showFullComment(element) {
    const fullComment = element.dataset.fullComment;
    const commentContainer = element.closest('.comment-container');
    
    commentContainer.innerHTML = `
        <div class="comment-full">
            ${fullComment}
            <span class="read-less" onclick="hideFullComment(this, '${fullComment.replace(/'/g, "\\'")}')">
                <span class="read-less-btn">Read Less</span>
            </span>
        </div>
    `;
}

function hideFullComment(element, fullComment) {
    const commentContainer = element.closest('.comment-container');
    const preview = fullComment.substring(0, 100);
    
    commentContainer.innerHTML = `
        <div class="comment-preview">
            ${preview}
            ${fullComment.length > 100 ? `
                <span class="read-more" onclick="showFullComment(this)" data-full-comment="${fullComment.replace(/"/g, '&quot;')}">
                    ... <span class="read-more-btn">Read More</span>
                </span>
            ` : ''}
        </div>
    `;
}

// View review details modal
function viewReviewDetails(review) {
    const modalBody = document.getElementById('reviewModalBody');
    
    const ratingStars = Array.from({length: 5}, (_, i) => 
        `<i class="fas fa-star ${i < review.Rating ? 'filled' : 'empty'}"></i>`
    ).join('');
    
    const ratingLabels = {5: 'Excellent', 4: 'Good', 3: 'Average', 2: 'Poor', 1: 'Terrible'};
    
    modalBody.innerHTML = `
        <div class="review-detail-content">
            <div class="detail-section">
                <h4><i class="fas fa-user"></i> Reviewer Information</h4>
                <div class="detail-grid">
                    <div class="detail-item">
                        <strong>Name:</strong> 
                        <span>${review.reviewer_name}</span>
                    </div>
                    <div class="detail-item">
                        <strong>Email:</strong> 
                        <span>${review.reviewer_email}</span>
                    </div>
                    ${review.reviewer_phone ? `
                        <div class="detail-item">
                            <strong>Phone:</strong> 
                            <span>${review.reviewer_phone}</span>
                        </div>
                    ` : ''}
                </div>
            </div>
            
            <div class="detail-section">
                <h4><i class="fas fa-${review.Review_type === 'E' ? 'tractor' : 'seedling'}"></i> ${review.Review_type === 'E' ? 'Equipment' : 'Product'} Information</h4>
                <div class="detail-grid">
                    <div class="detail-item">
                        <strong>Item Name:</strong> 
                        <span>${review.item_name || 'Unknown'}</span>
                    </div>
                    <div class="detail-item">
                        <strong>Item ID:</strong> 
                        <span>${review.Review_type}-${review.ID}</span>
                    </div>
                    <div class="detail-item">
                        <strong>Owner/Seller:</strong> 
                        <span>${review.owner_name || 'Unknown'}</span>
                    </div>
                </div>
            </div>
            
            <div class="detail-section">
                <h4><i class="fas fa-star"></i> Rating & Review</h4>
                <div class="rating-detail">
                    <div class="stars-large">
                        ${ratingStars}
                    </div>
                    <div class="rating-info">
                        <span class="rating-score">${review.Rating}/5</span>
                        <span class="rating-label">${ratingLabels[review.Rating]}</span>
                    </div>
                </div>
                
                ${review.comment ? `
                    <div class="comment-detail">
                        <strong>Review Comment:</strong>
                        <div class="comment-text">${review.comment}</div>
                    </div>
                ` : `
                    <div class="no-comment-detail">
                        <i class="fas fa-comment-slash"></i>
                        No written review provided
                    </div>
                `}
            </div>
            
            <div class="detail-section">
                <h4><i class="fas fa-calendar"></i> Review Date</h4>
                <div class="date-detail">
                    <div class="date-full">${new Date(review.created_date).toLocaleDateString('en-US', {
                        weekday: 'long',
                        year: 'numeric',
                        month: 'long',
                        day: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    })}</div>
                </div>
            </div>
        </div>
    `;
    
    document.getElementById('reviewModal').style.display = 'block';
}

// Close review modal
function closeReviewModal() {
    document.getElementById('reviewModal').style.display = 'none';
}

// Confirm delete
function confirmDelete(reviewId) {
    document.getElementById('deleteModal').style.display = 'block';
    document.getElementById('confirmDeleteBtn').onclick = function() {
        window.location.href = `?action=delete&id=${reviewId}`;
    };
}

// Close delete modal
function closeDeleteModal() {
    document.getElementById('deleteModal').style.display = 'none';
}

// Close modals when clicking outside
window.onclick = function(event) {
    const reviewModal = document.getElementById('reviewModal');
    const deleteModal = document.getElementById('deleteModal');
    
    if (event.target === reviewModal) {
        reviewModal.style.display = 'none';
    }
    if (event.target === deleteModal) {
        deleteModal.style.display = 'none';
    }
}

// Auto-submit form on filter change
$(document).ready(function() {
    $('#rating, #type').on('change', function() {
        $('#filterForm').submit();
    });
    
    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        $('.alert').fadeOut(500);
    }, 5000);
    
    // Animate rating bars on load
    $('.rating-fill').each(function() {
        const width = $(this).css('width');
        $(this).css('width', '0%');
        $(this).animate({width: width}, 1000);
    });
    
    // Animate summary stats on scroll
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.animation = 'fadeInUp 0.6s ease forwards';
            }
        });
    });
    
    document.querySelectorAll('.stat-item').forEach(item => {
        observer.observe(item);
    });
});
</script>

<?php require 'footer.php'; ?>
