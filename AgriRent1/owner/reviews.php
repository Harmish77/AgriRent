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

// Handle review response
if (isset($_POST['respond_review'])) {
    $review_id = intval($_POST['review_id']);
    $response = trim($_POST['response']);
    
    if (!empty($response)) {
        // Add owner response to review comment
        $response_text = "\n\n--- OWNER RESPONSE ---\n" . $response;
        $update_sql = "UPDATE reviews r 
                       JOIN equipment e ON (r.Review_type = 'E' AND r.ID = e.Equipment_id) 
                       SET r.comment = CONCAT(r.comment, ?) 
                       WHERE r.Review_id = ? AND e.Owner_id = ?";
        $stmt = $conn->prepare($update_sql);
        if ($stmt) {
            $stmt->bind_param('sii', $response_text, $review_id, $owner_id);
            if ($stmt->execute()) {
                $message = 'Response added successfully!';
            }
            $stmt->close();
        }
    }
}

// Pagination and filtering
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;
$rating_filter = $_GET['rating'] ?? 'all';
$type_filter = $_GET['type'] ?? 'all';

// Build WHERE clause for owner's equipment reviews
$where_clause = "WHERE r.Review_type = 'E' AND e.Owner_id = ?";
$params = [$owner_id];
$param_types = "i";

if ($rating_filter !== 'all') {
    $where_clause .= " AND r.Rating = ?";
    $params[] = intval($rating_filter);
    $param_types .= "i";
}

// Get reviews for owner's equipment
$sql = "SELECT r.Review_id, r.Rating, r.comment, r.created_date,
               e.Title as equipment_title, e.Brand, e.Model,
               u.Name as reviewer_name
        FROM reviews r
        JOIN equipment e ON (r.Review_type = 'E' AND r.ID = e.Equipment_id)
        JOIN users u ON r.Reviewer_id = u.user_id
        $where_clause
        ORDER BY r.created_date DESC
        LIMIT ? OFFSET ?";

$params[] = $limit;
$params[] = $offset;
$param_types .= "ii";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('SQL Error: ' . $conn->error);
}

$stmt->bind_param($param_types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$reviews = [];
while ($row = $result->fetch_assoc()) {
    $reviews[] = $row;
}
$stmt->close();

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total
              FROM reviews r
              JOIN equipment e ON (r.Review_type = 'E' AND r.ID = e.Equipment_id)
              $where_clause";

$count_stmt = $conn->prepare($count_sql);
if ($count_stmt) {
    $count_params = array_slice($params, 0, -2);
    $count_types = substr($param_types, 0, -2);
    
    if (!empty($count_params)) {
        $count_stmt->bind_param($count_types, ...$count_params);
    }
    $count_stmt->execute();
    $total_reviews = $count_stmt->get_result()->fetch_assoc()['total'];
    $count_stmt->close();
} else {
    $total_reviews = 0;
}

$total_pages = ceil($total_reviews / $limit);

// Get statistics
$stats_sql = "SELECT 
                COUNT(*) as total,
                AVG(r.Rating) as avg_rating,
                SUM(CASE WHEN r.Rating = 5 THEN 1 ELSE 0 END) as five_star,
                SUM(CASE WHEN r.Rating = 4 THEN 1 ELSE 0 END) as four_star,
                SUM(CASE WHEN r.Rating = 3 THEN 1 ELSE 0 END) as three_star,
                SUM(CASE WHEN r.Rating = 2 THEN 1 ELSE 0 END) as two_star,
                SUM(CASE WHEN r.Rating = 1 THEN 1 ELSE 0 END) as one_star
              FROM reviews r
              JOIN equipment e ON (r.Review_type = 'E' AND r.ID = e.Equipment_id)
              WHERE e.Owner_id = ?";

$stats_stmt = $conn->prepare($stats_sql);
if ($stats_stmt) {
    $stats_stmt->bind_param("i", $owner_id);
    $stats_stmt->execute();
    $stats = $stats_stmt->get_result()->fetch_assoc();
    $stats_stmt->close();
} else {
    $stats = ['total' => 0, 'avg_rating' => 0, 'five_star' => 0, 'four_star' => 0, 'three_star' => 0, 'two_star' => 0, 'one_star' => 0];
}

require 'oheader.php';
require 'owner_nav.php';
?>

<link rel="stylesheet" href="../assets/css/equipment.css">

<div class="main-content">
    <h1>Equipment Reviews</h1>
    <p style="color: #666; margin-bottom: 30px;">Monitor and respond to customer reviews of your equipment</p>

    <?php if ($message): ?>
        <div class="message"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="cards" style="margin-bottom: 30px;">
        <div class="card">
            <h3>Total Reviews</h3>
            <div class="count"><?= $stats['total'] ?></div>
            <small style="color: #666;">All Equipment</small>
        </div>
        
        <div class="card">
            <h3>Average Rating</h3>
            <div class="count"><?= $stats['total'] > 0 ? number_format($stats['avg_rating'], 1) : '0.0' ?> ⭐</div>
            <small style="color: #666;">Out of 5.0</small>
        </div>
        
        <div class="card">
            <h3>5-Star Reviews</h3>
            <div class="count"><?= $stats['five_star'] ?></div>
            <small style="color: #28a745;">Excellent</small>
        </div>
        
        <div class="card">
            <h3>1-Star Reviews</h3>
            <div class="count"><?= $stats['one_star'] ?></div>
            <small style="color: #dc3545;">Needs Attention</small>
        </div>
    </div>

    <!-- Filter Options -->
    <div class="quick-actions" style="margin-bottom: 20px;">
        <form method="GET" style="display: flex; gap: 15px; align-items: center;">
            <label><strong>Filter by Rating:</strong></label>
            <select name="rating" onchange="this.form.submit()" style="padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                <option value="all" <?= $rating_filter == 'all' ? 'selected' : '' ?>>All Ratings</option>
                <option value="5" <?= $rating_filter == '5' ? 'selected' : '' ?>>⭐⭐⭐⭐⭐ (5 Stars)</option>
                <option value="4" <?= $rating_filter == '4' ? 'selected' : '' ?>>⭐⭐⭐⭐ (4 Stars)</option>
                <option value="3" <?= $rating_filter == '3' ? 'selected' : '' ?>>⭐⭐⭐ (3 Stars)</option>
                <option value="2" <?= $rating_filter == '2' ? 'selected' : '' ?>>⭐⭐ (2 Stars)</option>
                <option value="1" <?= $rating_filter == '1' ? 'selected' : '' ?>>⭐ (1 Star)</option>
            </select>
        </form>

        <span style="margin-left: 20px; color: #666;">
            Showing <?= count($reviews) ?> of <?= $total_reviews ?> review(s)
        </span>
    </div>

    <!-- Reviews List -->
    <?php if (count($reviews) > 0): ?>
        <div class="reviews-container">
            <?php foreach ($reviews as $review): ?>
                <div class="review-card">
                    <div class="review-header">
                        <div class="review-info">
                            <h4>Review #<?= $review['Review_id'] ?></h4>
                            <div class="equipment-info">
                                Equipment: <?= htmlspecialchars($review['equipment_title']) ?> 
                                (<?= htmlspecialchars($review['Brand']) ?> <?= htmlspecialchars($review['Model']) ?>)
                            </div>
                        </div>
                        <div class="review-rating">
                            <div class="rating-stars">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <span class="star <?= $i <= $review['Rating'] ? 'filled' : '' ?>">⭐</span>
                                <?php endfor; ?>
                            </div>
                            <span class="rating-text"><?= $review['Rating'] ?>/5</span>
                        </div>
                    </div>

                    <div class="review-body">
                        <div class="reviewer-info">
                            <strong>Reviewed by:</strong> <?= htmlspecialchars($review['reviewer_name']) ?>
                            <span style="margin-left: 15px; color: #666;">
                                <?= date('M j, Y g:i A', strtotime($review['created_date'])) ?>
                            </span>
                        </div>
                        
                        <?php if (!empty($review['comment'])): ?>
                            <div class="review-comment">
                                <strong>Customer Comment:</strong>
                                <p><?= nl2br(htmlspecialchars($review['comment'])) ?></p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="review-actions">
                        <button onclick="toggleResponse(<?= $review['Review_id'] ?>)" class="action-btn">
                            💬 Respond to Review
                        </button>
                    </div>

                    <!-- Response Form -->
                    <div id="response-<?= $review['Review_id'] ?>" class="response-form" style="display: none;">
                        <form method="POST">
                            <input type="hidden" name="review_id" value="<?= $review['Review_id'] ?>">
                            <textarea name="response" placeholder="Thank the customer or address their concerns..." rows="3" required></textarea>
                            <div style="margin-top: 10px;">
                                <button type="submit" name="respond_review" class="action-btn">Send Response</button>
                                <button type="button" onclick="toggleResponse(<?= $review['Review_id'] ?>)" class="action-btn" style="background: #6c757d; margin-left: 10px;">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="pagination" style="margin-top: 30px;">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="?page=<?= $i ?>&rating=<?= $rating_filter ?>" class="<?= $page == $i ? 'active' : '' ?>"><?= $i ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <div class="form-section" style="text-align: center; padding: 50px;">
            <h3 style="color: #666; margin-bottom: 15px;">
                <?= $rating_filter !== 'all' ? 'No reviews with selected rating' : 'No Reviews Yet' ?>
            </h3>
            <p style="color: #666; margin-bottom: 25px;">
                <?= $rating_filter !== 'all' ? 'Try changing the filter or encourage customers to leave reviews.' : 'When customers rent your equipment, they can leave reviews here.' ?>
            </p>
            <?php if ($rating_filter !== 'all'): ?>
                <a href="reviews.php" class="action-btn">🔄 Show All Reviews</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Rating Breakdown -->
    <?php if ($stats['total'] > 0): ?>
        <div class="report-sections" style="margin-top: 40px;">
            <div class="report-section">
                <h3>⭐ Rating Breakdown</h3>
                <div class="rating-breakdown">
                    <?php
                    $ratings = [
                        5 => ['count' => $stats['five_star'], 'label' => '5 Stars', 'color' => '#28a745'],
                        4 => ['count' => $stats['four_star'], 'label' => '4 Stars', 'color' => '#7bc342'],
                        3 => ['count' => $stats['three_star'], 'label' => '3 Stars', 'color' => '#ffc107'],
                        2 => ['count' => $stats['two_star'], 'label' => '2 Stars', 'color' => '#fd7e14'],
                        1 => ['count' => $stats['one_star'], 'label' => '1 Star', 'color' => '#dc3545']
                    ];
                    
                    foreach ($ratings as $star => $data):
                        $percentage = $stats['total'] > 0 ? ($data['count'] / $stats['total']) * 100 : 0;
                    ?>
                        <div class="rating-bar">
                            <div class="rating-label"><?= $data['label'] ?></div>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?= $percentage ?>%; background: <?= $data['color'] ?>;"></div>
                            </div>
                            <div class="rating-count"><?= $data['count'] ?> (<?= number_format($percentage, 1) ?>%)</div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="report-section">
                <h3>📈 Review Tips</h3>
                <div class="tips-list">
                    <div>💡 <strong>Respond Quickly:</strong> Reply to reviews within 24 hours</div>
                    <div>❤️ <strong>Thank Customers:</strong> Always thank customers for positive reviews</div>
                    <div>🔧 <strong>Address Issues:</strong> Respond constructively to negative reviews</div>
                    <div>⭐ <strong>Encourage Reviews:</strong> Ask satisfied customers to leave reviews</div>
                    <div>📊 <strong>Monitor Trends:</strong> Track your rating changes over time</div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
function toggleResponse(reviewId) {
    const form = document.getElementById('response-' + reviewId);
    form.style.display = form.style.display === 'none' ? 'block' : 'none';
}
</script>
<?php 
    require 'ofooter.php';
?>
