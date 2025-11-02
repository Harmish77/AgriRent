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
$error = '';

// Handle new review submission
if (isset($_POST['submit_review'])) {
    $review_type = $_POST['review_type']; // 'E' or 'P'
    $item_id = intval($_POST['item_id']);
    $rating = intval($_POST['rating']);
    $comment = trim($_POST['comment']);
    
    if ($rating >= 1 && $rating <= 5) {
        // Check if farmer has already reviewed this item
        $check_sql = "SELECT Review_id FROM reviews WHERE Reviewer_id = ? AND Review_type = ? AND ID = ?";
        $check_stmt = $conn->prepare($check_sql);
        if ($check_stmt) {
            $check_stmt->bind_param('isi', $farmer_id, $review_type, $item_id);
            $check_stmt->execute();
            $existing = $check_stmt->get_result()->num_rows;
            $check_stmt->close();
            
            if ($existing == 0) {
                // Insert new review
                $insert_sql = "INSERT INTO reviews (Reviewer_id, Review_type, ID, Rating, comment, created_date) VALUES (?, ?, ?, ?, ?, NOW())";
                $insert_stmt = $conn->prepare($insert_sql);
                if ($insert_stmt) {
                    $insert_stmt->bind_param('isiis', $farmer_id, $review_type, $item_id, $rating, $comment);
                    if ($insert_stmt->execute()) {
                        $message = 'Review submitted successfully! Thank you for your feedback.';
                    } else {
                        $error = 'Error submitting review: ' . $conn->error;
                    }
                    $insert_stmt->close();
                }
            } else {
                $error = 'You have already reviewed this item.';
            }
        }
    } else {
        $error = 'Please select a valid rating (1-5 stars).';
    }
}

// Handle review update
if (isset($_POST['update_review'])) {
    $review_id = intval($_POST['review_id']);
    $rating = intval($_POST['rating']);
    $comment = trim($_POST['comment']);
    
    if ($rating >= 1 && $rating <= 5) {
        $update_sql = "UPDATE reviews SET Rating = ?, comment = ? WHERE Review_id = ? AND Reviewer_id = ?";
        $update_stmt = $conn->prepare($update_sql);
        if ($update_stmt) {
            $update_stmt->bind_param('isii', $rating, $comment, $review_id, $farmer_id);
            if ($update_stmt->execute()) {
                $message = 'Review updated successfully!';
            } else {
                $error = 'Error updating review: ' . $conn->error;
            }
            $update_stmt->close();
        }
    }
}

// Get current tab
$tab = $_GET['tab'] ?? 'give_reviews';

// Pagination
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

// Get equipment farmer can review (from completed bookings)
if ($tab === 'give_reviews') {
    $available_equipment_sql = "
        SELECT DISTINCT e.Equipment_id, e.Title, e.Brand, e.Model, e.Description, e.Daily_rate,
               u.Name as owner_name, u.Phone as owner_phone, eb.start_date, eb.end_date,
               eb.total_amount, eb.booking_id,
               (SELECT i.image_url FROM images i WHERE i.image_type = 'E' AND i.ID = e.Equipment_id LIMIT 1) as image_url
        FROM equipment e
        JOIN equipment_bookings eb ON e.Equipment_id = eb.equipment_id
        JOIN users u ON e.Owner_id = u.user_id
        WHERE eb.customer_id = ? AND eb.status = 'COM' 
        AND e.Equipment_id NOT IN (
            SELECT ID FROM reviews WHERE Reviewer_id = ? AND Review_type = 'E'
        )
        ORDER BY eb.end_date DESC
    ";

    $equipment_stmt = $conn->prepare($available_equipment_sql);
    if ($equipment_stmt) {
        $equipment_stmt->bind_param('ii', $farmer_id, $farmer_id);
        $equipment_stmt->execute();
        $available_equipment = $equipment_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $equipment_stmt->close();
    } else {
        $available_equipment = [];
    }

    // Get products farmer can review (from completed orders)
    $available_products_sql = "
        SELECT DISTINCT p.product_id, p.Name, p.Description, p.Price, p.Category,
               u.Name as seller_name, u.Phone as seller_phone, po.order_date,
               po.quantity, po.total_price, po.Order_id,
               (SELECT i.image_url FROM images i WHERE i.image_type = 'P' AND i.ID = p.product_id LIMIT 1) as image_url
        FROM product p
        JOIN product_orders po ON p.product_id = po.Product_id
        JOIN users u ON p.seller_id = u.user_id
        WHERE po.buyer_id = ? AND po.Status = 'CON'
        AND p.product_id NOT IN (
            SELECT ID FROM reviews WHERE Reviewer_id = ? AND Review_type = 'P'
        )
        ORDER BY po.order_date DESC
    ";

    $products_stmt = $conn->prepare($available_products_sql);
    if ($products_stmt) {
        $products_stmt->bind_param('ii', $farmer_id, $farmer_id);
        $products_stmt->execute();
        $available_products = $products_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $products_stmt->close();
    } else {
        $available_products = [];
    }
}

// Get reviews given by the farmer
if ($tab === 'my_reviews') {
    $my_reviews_sql = "
        SELECT r.Review_id, r.Review_type, r.ID, r.Rating, r.comment, r.created_date,
               CASE 
                   WHEN r.Review_type = 'E' THEN e.Title
                   WHEN r.Review_type = 'P' THEN p.Name
               END as item_name,
               CASE 
                   WHEN r.Review_type = 'E' THEN CONCAT(e.Brand, ' ', e.Model)
                   WHEN r.Review_type = 'P' THEN p.Category
               END as item_details,
               CASE 
                   WHEN r.Review_type = 'E' THEN owner.Name
                   WHEN r.Review_type = 'P' THEN seller.Name
               END as provider_name,
               CASE 
                   WHEN r.Review_type = 'E' THEN (SELECT i.image_url FROM images i WHERE i.image_type = 'E' AND i.ID = e.Equipment_id LIMIT 1)
                   WHEN r.Review_type = 'P' THEN (SELECT i.image_url FROM images i WHERE i.image_type = 'P' AND i.ID = p.product_id LIMIT 1)
               END as image_url
        FROM reviews r
        LEFT JOIN equipment e ON r.Review_type = 'E' AND r.ID = e.Equipment_id
        LEFT JOIN users owner ON e.Owner_id = owner.user_id
        LEFT JOIN product p ON r.Review_type = 'P' AND r.ID = p.product_id
        LEFT JOIN users seller ON p.seller_id = seller.user_id
        WHERE r.Reviewer_id = ?
        ORDER BY r.created_date DESC
        LIMIT ? OFFSET ?
    ";

    $my_reviews_stmt = $conn->prepare($my_reviews_sql);
    if ($my_reviews_stmt) {
        $my_reviews_stmt->bind_param('iii', $farmer_id, $limit, $offset);
        $my_reviews_stmt->execute();
        $my_reviews = $my_reviews_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $my_reviews_stmt->close();
    } else {
        $my_reviews = [];
    }
}

// Get farmer's review statistics
$farmer_stats_sql = "
    SELECT COUNT(*) as total_reviews, 
           AVG(Rating) as avg_rating,
           SUM(CASE WHEN Rating = 5 THEN 1 ELSE 0 END) as five_star,
           SUM(CASE WHEN Rating = 4 THEN 1 ELSE 0 END) as four_star,
           SUM(CASE WHEN Rating = 3 THEN 1 ELSE 0 END) as three_star,
           SUM(CASE WHEN Rating = 2 THEN 1 ELSE 0 END) as two_star,
           SUM(CASE WHEN Rating = 1 THEN 1 ELSE 0 END) as one_star,
           SUM(CASE WHEN Review_type = 'E' THEN 1 ELSE 0 END) as equipment_reviews,
           SUM(CASE WHEN Review_type = 'P' THEN 1 ELSE 0 END) as product_reviews
    FROM reviews 
    WHERE Reviewer_id = ?
";

$farmer_stats_stmt = $conn->prepare($farmer_stats_sql);
if ($farmer_stats_stmt) {
    $farmer_stats_stmt->bind_param('i', $farmer_id);
    $farmer_stats_stmt->execute();
    $farmer_stats = $farmer_stats_stmt->get_result()->fetch_assoc();
    $farmer_stats_stmt->close();
} else {
    $farmer_stats = [
        'total_reviews' => 0, 'avg_rating' => 0, 'five_star' => 0, 
        'four_star' => 0, 'three_star' => 0, 'two_star' => 0, 'one_star' => 0,
        'equipment_reviews' => 0, 'product_reviews' => 0
    ];
}

require 'fheader.php';
require 'farmer_nav.php';
?>

<link rel="stylesheet" href="../admin.css">

<div class="main-content">
    <h1>My Reviews & Feedback</h1>
    <p style="color: #666; margin-bottom: 30px;">Share your experience with equipment and products you've used</p>

    <?php if ($message): ?>
        <div class="message success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="message error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="cards" style="margin-bottom: 30px;">
        <div class="card">
            <h3>Reviews Given</h3>
            <div class="count"><?= $farmer_stats['total_reviews'] ?></div>
            <small style="color: #666;">Total Reviews</small>
        </div>
        
        <div class="card">
            <h3>Average Rating</h3>
            <div class="count"><?= $farmer_stats['total_reviews'] > 0 ? number_format($farmer_stats['avg_rating'], 1) : '0.0' ?> ⭐</div>
            <small style="color: #666;">You Give</small>
        </div>
        
        <div class="card">
            <h3>Equipment Reviews</h3>
            <div class="count"><?= $farmer_stats['equipment_reviews'] ?></div>
            <small style="color: #28a745;">Equipment</small>
        </div>
        
        <div class="card">
            <h3>Product Reviews</h3>
            <div class="count"><?= $farmer_stats['product_reviews'] ?></div>
            <small style="color: #17a2b8;">Products</small>
        </div>
    </div>

    <!-- Tab Navigation -->
    <div class="quick-actions" style="margin-bottom: 30px;">
        <a href="?tab=give_reviews" class="action-btn <?= $tab === 'give_reviews' ? 'active' : '' ?>" style="margin-right: 15px;">
            <i class="fas fa-plus-circle"></i> Give Reviews (<?= count($available_equipment ?? []) + count($available_products ?? []) ?>)
        </a>
        <a href="?tab=my_reviews" class="action-btn <?= $tab === 'my_reviews' ? 'active' : '' ?>">
            <i class="fas fa-star"></i> My Reviews (<?= $farmer_stats['total_reviews'] ?>)
        </a>
    </div>

    <!-- Give Reviews Tab -->
    <?php if ($tab === 'give_reviews'): ?>
        <div class="tab-content">
            <?php if (!empty($available_equipment) || !empty($available_products)): ?>
                
                <!-- Equipment Reviews Section -->
                <?php if (!empty($available_equipment)): ?>
                    <div class="form-section" style="margin-bottom: 40px;">
                        <h3 style="color: #28a745; margin-bottom: 20px;">
                            <i class="fas fa-tractor"></i> Equipment Ready for Review
                        </h3>
                        <p style="margin-bottom: 20px; color: #666;">Share your experience with equipment you've completed renting</p>
                        
                        <div class="review-items-grid">
                            <?php foreach ($available_equipment as $equipment): ?>
                                <div class="review-item-card">
                                    <!-- Equipment Image -->
                                    <div class="item-image">
                                        <?php if (!empty($equipment['image_url'])): ?>
                                            <img src="../<?= htmlspecialchars($equipment['image_url']) ?>" 
                                                 alt="<?= htmlspecialchars($equipment['Title']) ?>">
                                        <?php else: ?>
                                            <div class="no-image">
                                                <i class="fas fa-tractor"></i>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Equipment Details -->
                                    <div class="item-details">
                                        <h4><?= htmlspecialchars($equipment['Title']) ?></h4>
                                        <p class="item-model"><?= htmlspecialchars($equipment['Brand']) ?> <?= htmlspecialchars($equipment['Model']) ?></p>
                                        <div class="item-meta">
                                            <div><strong>Owner:</strong> <?= htmlspecialchars($equipment['owner_name']) ?></div>
                                            <div><strong>Rental Period:</strong> 
                                                <?= date('M j', strtotime($equipment['start_date'])) ?> - 
                                                <?= date('M j, Y', strtotime($equipment['end_date'])) ?>
                                            </div>
                                            <div><strong>Amount Paid:</strong> ₹<?= number_format($equipment['total_amount']) ?></div>
                                        </div>
                                    </div>
                                    
                                    <!-- Review Button -->
                                    <div class="item-action">
                                        <button onclick="openReviewModal('E', <?= $equipment['Equipment_id'] ?>, '<?= htmlspecialchars($equipment['Title']) ?>', '<?= htmlspecialchars($equipment['owner_name']) ?>')" 
                                                class="action-btn">
                                            <i class="fas fa-star"></i> Write Review
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Products Reviews Section -->
                <?php if (!empty($available_products)): ?>
                    <div class="form-section">
                        <h3 style="color: #17a2b8; margin-bottom: 20px;">
                            <i class="fas fa-seedling"></i> Products Ready for Review
                        </h3>
                        <p style="margin-bottom: 20px; color: #666;">Rate the products you've purchased</p>
                        
                        <div class="review-items-grid">
                            <?php foreach ($available_products as $product): ?>
                                <div class="review-item-card">
                                    <!-- Product Image -->
                                    <div class="item-image">
                                        <?php if (!empty($product['image_url'])): ?>
                                            <img src="../<?= htmlspecialchars($product['image_url']) ?>" 
                                                 alt="<?= htmlspecialchars($product['Name']) ?>">
                                        <?php else: ?>
                                            <div class="no-image">
                                                <i class="fas fa-seedling"></i>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Product Details -->
                                    <div class="item-details">
                                        <h4><?= htmlspecialchars($product['Name']) ?></h4>
                                        <p class="item-category"><?= htmlspecialchars($product['Category']) ?></p>
                                        <div class="item-meta">
                                            <div><strong>Seller:</strong> <?= htmlspecialchars($product['seller_name']) ?></div>
                                            <div><strong>Purchased:</strong> <?= date('M j, Y', strtotime($product['order_date'])) ?></div>
                                            <div><strong>Quantity:</strong> <?= $product['quantity'] ?> units</div>
                                            <div><strong>Amount Paid:</strong> ₹<?= number_format($product['total_price']) ?></div>
                                        </div>
                                    </div>
                                    
                                    <!-- Review Button -->
                                    <div class="item-action">
                                        <button onclick="openReviewModal('P', <?= $product['product_id'] ?>, '<?= htmlspecialchars($product['Name']) ?>', '<?= htmlspecialchars($product['seller_name']) ?>')" 
                                                class="action-btn">
                                            <i class="fas fa-star"></i> Write Review
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <div class="form-section" style="text-align: center; padding: 50px;">
                    <i class="fas fa-clipboard-list" style="font-size: 4rem; color: #dee2e6; margin-bottom: 20px;"></i>
                    <h3 style="color: #666; margin-bottom: 15px;">No Items to Review</h3>
                    <p style="color: #666; line-height: 1.6; margin-bottom: 25px;">
                        Complete equipment rentals or product purchases to leave reviews.<br>
                        Your feedback helps the AgriRent community!
                    </p>
                    <div>
                        <a href="../equipment.php" class="action-btn" style="margin-right: 15px;">
                            <i class="fas fa-tractor"></i> Browse Equipment
                        </a>
                        <a href="../products.php" class="action-btn">
                            <i class="fas fa-seedling"></i> Shop Products
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- My Reviews Tab -->
    <?php if ($tab === 'my_reviews'): ?>
        <div class="tab-content">
            <?php if (!empty($my_reviews)): ?>
                <div class="reviews-list">
                    <?php foreach ($my_reviews as $review): ?>
                        <div class="review-card">
                            <div class="review-header">
                                <div class="review-item-info">
                                    <!-- Item Image -->
                                    <div class="review-item-image">
                                        <?php if (!empty($review['image_url'])): ?>
                                            <img src="../<?= htmlspecialchars($review['image_url']) ?>" 
                                                 alt="<?= htmlspecialchars($review['item_name']) ?>">
                                        <?php else: ?>
                                            <div class="no-image">
                                                <i class="fas fa-<?= $review['Review_type'] == 'E' ? 'tractor' : 'seedling' ?>"></i>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Item Details -->
                                    <div class="review-item-details">
                                        <h4><?= htmlspecialchars($review['item_name']) ?></h4>
                                        <p><?= htmlspecialchars($review['item_details']) ?></p>
                                        <small>
                                            <i class="fas fa-user"></i>
                                            <?= $review['Review_type'] == 'E' ? 'Owner' : 'Seller' ?>: 
                                            <?= htmlspecialchars($review['provider_name']) ?>
                                        </small>
                                    </div>
                                </div>
                                
                                <!-- Rating and Date -->
                                <div class="review-meta">
                                    <div class="rating-stars">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="fas fa-star <?= $i <= $review['Rating'] ? 'filled' : 'empty' ?>"></i>
                                        <?php endfor; ?>
                                        <span class="rating-text"><?= $review['Rating'] ?>/5</span>
                                    </div>
                                    <div class="review-date">
                                        <?= date('M j, Y', strtotime($review['created_date'])) ?>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if (!empty($review['comment'])): ?>
                                <div class="review-comment">
                                    <strong>Your Review:</strong>
                                    <p><?= nl2br(htmlspecialchars($review['comment'])) ?></p>
                                </div>
                            <?php endif; ?>
                            
                            <div class="review-actions">
                                <button onclick="editReview(<?= $review['Review_id'] ?>, '<?= htmlspecialchars($review['item_name']) ?>', <?= $review['Rating'] ?>, '<?= htmlspecialchars($review['comment']) ?>')" 
                                        class="action-btn secondary">
                                    <i class="fas fa-edit"></i> Edit Review
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="form-section" style="text-align: center; padding: 50px;">
                    <i class="fas fa-star-half-alt" style="font-size: 4rem; color: #dee2e6; margin-bottom: 20px;"></i>
                    <h3 style="color: #666; margin-bottom: 15px;">No Reviews Yet</h3>
                    <p style="color: #666; line-height: 1.6; margin-bottom: 25px;">
                        Start sharing your experience by reviewing equipment and products you've used.
                    </p>
                    <a href="?tab=give_reviews" class="action-btn">
                        <i class="fas fa-plus"></i> Start Reviewing
                    </a>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Review Modal -->
<div id="reviewModal" class="modal" style="display: none;">
    <div class="modal-content">
        <span class="close" onclick="closeReviewModal()">&times;</span>
        <h2 id="modalTitle">Write Your Review</h2>
        
        <form method="POST" id="reviewForm">
            <input type="hidden" name="review_type" id="reviewType">
            <input type="hidden" name="item_id" id="itemId">
            
            <div class="form-group">
                <label>How was your experience? *</label>
                <div class="star-rating">
                    <input type="radio" name="rating" value="5" id="star5" required>
                    <label for="star5" title="Excellent">⭐⭐⭐⭐⭐ Excellent</label>
                    
                    <input type="radio" name="rating" value="4" id="star4">
                    <label for="star4" title="Good">⭐⭐⭐⭐ Good</label>
                    
                    <input type="radio" name="rating" value="3" id="star3">
                    <label for="star3" title="Average">⭐⭐⭐ Average</label>
                    
                    <input type="radio" name="rating" value="2" id="star2">
                    <label for="star2" title="Poor">⭐⭐ Poor</label>
                    
                    <input type="radio" name="rating" value="1" id="star1">
                    <label for="star1" title="Very Poor">⭐ Very Poor</label>
                </div>
            </div>
            
            <div class="form-group">
                <label for="comment">Share your experience (Optional):</label>
                <textarea name="comment" id="comment" rows="4" 
                          placeholder="Tell others about your experience - was it reliable? Good condition? Would you recommend it?"></textarea>
                <small style="color: #666;">Your detailed feedback helps other farmers make better decisions</small>
            </div>
            
            <div class="form-actions">
                <button type="submit" name="submit_review" class="action-btn">
                    <i class="fas fa-paper-plane"></i> Submit Review
                </button>
                <button type="button" onclick="closeReviewModal()" class="action-btn secondary" style="margin-left: 10px;">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Review Modal -->
<div id="editReviewModal" class="modal" style="display: none;">
    <div class="modal-content">
        <span class="close" onclick="closeEditModal()">&times;</span>
        <h2>Edit Your Review</h2>
        
        <form method="POST" id="editReviewForm">
            <input type="hidden" name="review_id" id="editReviewId">
            
            <div class="edit-item-name" id="editItemName"></div>
            
            <div class="form-group">
                <label>Update your rating:</label>
                <div class="star-rating">
                    <input type="radio" name="rating" value="5" id="editStar5" required>
                    <label for="editStar5">⭐⭐⭐⭐⭐ Excellent</label>
                    
                    <input type="radio" name="rating" value="4" id="editStar4">
                    <label for="editStar4">⭐⭐⭐⭐ Good</label>
                    
                    <input type="radio" name="rating" value="3" id="editStar3">
                    <label for="editStar3">⭐⭐⭐ Average</label>
                    
                    <input type="radio" name="rating" value="2" id="editStar2">
                    <label for="editStar2">⭐⭐ Poor</label>
                    
                    <input type="radio" name="rating" value="1" id="editStar1">
                    <label for="editStar1">⭐ Very Poor</label>
                </div>
            </div>
            
            <div class="form-group">
                <label for="editComment">Update your review:</label>
                <textarea name="comment" id="editComment" rows="4"></textarea>
            </div>
            
            <div class="form-actions">
                <button type="submit" name="update_review" class="action-btn">
                    <i class="fas fa-save"></i> Update Review
                </button>
                <button type="button" onclick="closeEditModal()" class="action-btn secondary" style="margin-left: 10px;">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<style>
/* Tab styling */
.action-btn.active {
    background: #28a745;
    color: white;
}

.action-btn.secondary {
    background: #6c757d;
    color: white;
}

/* Review items grid */
.review-items-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
    gap: 20px;
}

.review-item-card {
    background: white;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 20px;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.review-item-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.item-image {
    width: 100%;
    height: 150px;
    border-radius: 6px;
    overflow: hidden;
    margin-bottom: 15px;
    background: #f8f9fa;
    display: flex;
    align-items: center;
    justify-content: center;
}

.item-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.no-image {
    color: #6c757d;
    font-size: 2rem;
}

.item-details h4 {
    margin: 0 0 8px 0;
    color: #28a745;
    font-size: 1.1rem;
}

.item-model, .item-category {
    color: #6c757d;
    margin-bottom: 12px;
    font-size: 0.9rem;
}

.item-meta {
    background: #f8f9fa;
    padding: 10px;
    border-radius: 4px;
    font-size: 0.85rem;
    margin-bottom: 15px;
}

.item-meta div {
    margin-bottom: 4px;
}

.item-action {
    text-align: center;
}

/* Reviews list styling */
.reviews-list {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.review-card {
    background: white;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 20px;
}

.review-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 15px;
}

.review-item-info {
    display: flex;
    gap: 15px;
    flex: 1;
}

.review-item-image {
    width: 60px;
    height: 60px;
    border-radius: 6px;
    overflow: hidden;
    background: #f8f9fa;
    display: flex;
    align-items: center;
    justify-content: center;
}

.review-item-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.review-item-details h4 {
    margin: 0 0 5px 0;
    color: #28a745;
}

.review-item-details p {
    margin: 0 0 5px 0;
    color: #6c757d;
    font-size: 0.9rem;
}

.review-item-details small {
    color: #666;
    font-size: 0.8rem;
}

.review-meta {
    text-align: right;
}

.rating-stars {
    margin-bottom: 5px;
}

.rating-stars .fa-star.filled {
    color: #FFD700;
}

.rating-stars .fa-star.empty {
    color: #e9ecef;
}

.rating-text {
    margin-left: 8px;
    font-weight: 600;
    color: #495057;
}

.review-date {
    color: #6c757d;
    font-size: 0.85rem;
}

.review-comment {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 6px;
    border-left: 4px solid #28a745;
    margin-bottom: 15px;
}

.review-comment p {
    margin: 8px 0 0 0;
    line-height: 1.6;
}

.review-actions {
    text-align: right;
}

/* Modal styling */
.modal {
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
}

.modal-content {
    background-color: white;
    margin: 5% auto;
    padding: 0;
    border-radius: 8px;
    width: 90%;
    max-width: 500px;
    position: relative;
}

.modal-content h2 {
    background: #28a745;
    color: white;
    margin: 0;
    padding: 20px;
    border-radius: 8px 8px 0 0;
}

.modal-content form {
    padding: 20px;
}

.close {
    position: absolute;
    top: 15px;
    right: 25px;
    color: white;
    font-size: 28px;
    font-weight: bold;
    cursor: pointer;
}

.close:hover {
    opacity: 0.7;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #495057;
}

.star-rating {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.star-rating input[type="radio"] {
    display: none;
}

.star-rating label {
    padding: 8px 12px;
    border: 2px solid #e9ecef;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s ease;
    display: block;
}

.star-rating label:hover {
    border-color: #28a745;
    background: rgba(40, 167, 69, 0.1);
}

.star-rating input[type="radio"]:checked + label {
    border-color: #28a745;
    background: rgba(40, 167, 69, 0.1);
    color: #28a745;
    font-weight: 600;
}

textarea {
    width: 100%;
    padding: 10px;
    border: 1px solid #ced4da;
    border-radius: 4px;
    font-family: inherit;
    resize: vertical;
}

.form-actions {
    text-align: right;
    margin-top: 20px;
}

.edit-item-name {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 6px;
    margin-bottom: 20px;
    font-weight: 600;
    color: #28a745;
    text-align: center;
}

/* Message styling */
.message.success {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.message.error {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

/* Responsive */
@media (max-width: 768px) {
    .review-items-grid {
        grid-template-columns: 1fr;
    }
    
    .review-header {
        flex-direction: column;
        gap: 15px;
    }
    
    .review-meta {
        text-align: left;
    }
    
    .star-rating {
        flex-direction: column;
    }
}
</style>

<script>
function openReviewModal(type, itemId, itemName, providerName) {
    document.getElementById('reviewType').value = type;
    document.getElementById('itemId').value = itemId;
    document.getElementById('modalTitle').textContent = 'Review: ' + itemName;
    document.getElementById('reviewModal').style.display = 'block';
}

function closeReviewModal() {
    document.getElementById('reviewModal').style.display = 'none';
    document.getElementById('reviewForm').reset();
}

function editReview(reviewId, itemName, rating, comment) {
    document.getElementById('editReviewId').value = reviewId;
    document.getElementById('editItemName').textContent = itemName;
    document.getElementById('editStar' + rating).checked = true;
    document.getElementById('editComment').value = comment;
    document.getElementById('editReviewModal').style.display = 'block';
}

function closeEditModal() {
    document.getElementById('editReviewModal').style.display = 'none';
    document.getElementById('editReviewForm').reset();
}

// Close modals when clicking outside
window.onclick = function(event) {
    const reviewModal = document.getElementById('reviewModal');
    const editModal = document.getElementById('editReviewModal');
    
    if (event.target === reviewModal) {
        closeReviewModal();
    }
    if (event.target === editModal) {
        closeEditModal();
    }
}

// Auto-hide messages
setTimeout(function() {
    document.querySelectorAll('.message').forEach(message => {
        message.style.opacity = '0';
        setTimeout(() => message.remove(), 300);
    });
}, 5000);
</script>

<?php require 'ffooter.php'; ?>
