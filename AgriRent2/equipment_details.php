<?php
session_start();
require_once 'auth/config.php';

// Handle review submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_review') {
    if (isset($_SESSION['logged_in']) && $_SESSION['logged_in']) {
        $reviewer_id = $_SESSION['user_id'];
        $equipment_id = intval($_POST['equipment_id']);
        $rating = intval($_POST['rating']);
        $comment = trim($_POST['comment'] ?? '');
        
        // Check if user is admin - admins cannot review
        if ($_SESSION['user_type'] == 'A') {
            $error_message = "Administrators cannot submit reviews.";
        }
        // Validation
        elseif ($rating >= 1 && $rating <= 5 && $equipment_id > 0) {
            // Check if user is not the equipment owner
            $owner_check_query = "SELECT Owner_id FROM equipment WHERE Equipment_id = ?";
            $owner_stmt = $conn->prepare($owner_check_query);
            
            if ($owner_stmt) {
                $owner_stmt->bind_param("i", $equipment_id);
                $owner_stmt->execute();
                $owner_result = $owner_stmt->get_result()->fetch_assoc();
                $owner_stmt->close();
                
                if ($owner_result && $reviewer_id != $owner_result['Owner_id']) {
                    // Check if user has already reviewed
                    $existing_check = "SELECT Review_id FROM reviews WHERE Reviewer_id = ? AND Review_type = 'E' AND ID = ?";
                    $existing_stmt = $conn->prepare($existing_check);
                    
                    if ($existing_stmt) {
                        $existing_stmt->bind_param("ii", $reviewer_id, $equipment_id);
                        $existing_stmt->execute();
                        $existing_result = $existing_stmt->get_result()->fetch_assoc();
                        $existing_stmt->close();
                        
                        if (!$existing_result) {
                            // Insert review (removed booking requirement)
                            $insert_review = "INSERT INTO reviews (Reviewer_id, Review_type, ID, Rating, comment, created_date) VALUES (?, 'E', ?, ?, ?, NOW())";
                            $review_stmt = $conn->prepare($insert_review);
                            
                            if ($review_stmt) {
                                // Handle empty comment
                                $comment_value = !empty($comment) ? $comment : null;
                                $review_stmt->bind_param("iiis", $reviewer_id, $equipment_id, $rating, $comment_value);
                                
                                if ($review_stmt->execute()) {
                                    $success_message = "Review submitted successfully!";
                                    // Redirect to prevent form resubmission
                                    header("Location: " . $_SERVER['REQUEST_URI'] . "?review=success");
                                    exit();
                                } else {
                                    $error_message = "Failed to submit review. Database error: " . $review_stmt->error;
                                }
                                $review_stmt->close();
                            } else {
                                $error_message = "Failed to prepare review insertion query: " . $conn->error;
                            }
                        } else {
                            $error_message = "You have already reviewed this equipment.";
                        }
                    } else {
                        $error_message = "Failed to check existing reviews: " . $conn->error;
                    }
                } else {
                    $error_message = "Equipment owners cannot review their own equipment.";
                }
            } else {
                $error_message = "Failed to verify equipment owner: " . $conn->error;
            }
        } else {
            $error_message = "Invalid rating or equipment ID.";
        }
    } else {
        $error_message = "Please log in to submit a review.";
    }
}

// Display success message
if (isset($_GET['review']) && $_GET['review'] === 'success') {
    $success_message = "Review submitted successfully!";
}

// Get equipment ID from URL
$equipment_id = intval($_GET['id'] ?? 0);

if ($equipment_id <= 0) {
    header('Location: equipments.php');
    exit();
}

// Fetch equipment details with owner info and images
$equipment_query = "SELECT e.*, u.Name as owner_name, u.Phone as owner_phone, u.Email as owner_email,
                           i.image_url, es.Subcategory_name, ec.Name as category_name
                    FROM equipment e 
                    JOIN users u ON e.Owner_id = u.user_id 
                    LEFT JOIN images i ON (i.image_type = 'E' AND i.ID = e.Equipment_id)
                    LEFT JOIN equipment_subcategories es ON e.Subcategories_id = es.Subcategory_id
                    LEFT JOIN equipment_categories ec ON es.Category_id = ec.category_id
                    WHERE e.Equipment_id = ? AND e.Approval_status = 'CON'";

$stmt = $conn->prepare($equipment_query);
if (!$stmt) {
    die("Database error: " . $conn->error);
}
$stmt->bind_param("i", $equipment_id);
$stmt->execute();
$result = $stmt->get_result();
$equipment = $result->fetch_assoc();
$stmt->close();

if (!$equipment) {
    header('Location: equipments.php');
    exit();
}

// Fetch equipment reviews
$reviews_query = "SELECT r.*, u.Name as reviewer_name 
                 FROM reviews r 
                 JOIN users u ON r.Reviewer_id = u.user_id 
                 WHERE r.Review_type = 'E' AND r.ID = ? 
                 ORDER BY r.created_date DESC 
                 LIMIT 10";

$reviews_stmt = $conn->prepare($reviews_query);
if ($reviews_stmt) {
    $reviews_stmt->bind_param("i", $equipment_id);
    $reviews_stmt->execute();
    $reviews_result = $reviews_stmt->get_result();
    $reviews = [];
    while ($review = $reviews_result->fetch_assoc()) {
        $reviews[] = $review;
    }
    $reviews_stmt->close();
} else {
    $reviews = [];
    $error_message = "Failed to load reviews: " . $conn->error;
}

// Calculate average rating
$avg_rating = 0;
$total_reviews = 0;
if (count($reviews) > 0) {
    $total_rating = array_sum(array_column($reviews, 'Rating'));
    $total_reviews = count($reviews);
    $avg_rating = $total_rating / $total_reviews;
}

// Fetch booking count for popularity
$booking_count_query = "SELECT COUNT(*) as total_bookings 
                       FROM equipment_bookings 
                       WHERE equipment_id = ? AND status = 'CON'";
$booking_stmt = $conn->prepare($booking_count_query);
if ($booking_stmt) {
    $booking_stmt->bind_param("i", $equipment_id);
    $booking_stmt->execute();
    $booking_count = $booking_stmt->get_result()->fetch_assoc()['total_bookings'];
    $booking_stmt->close();
} else {
    $booking_count = 0;
}

// Get similar equipment from same owner
$similar_query = "SELECT e.Equipment_id, e.Title, e.Daily_rate, i.image_url 
                 FROM equipment e 
                 LEFT JOIN images i ON (i.image_type = 'E' AND i.ID = e.Equipment_id)
                 WHERE e.Owner_id = ? AND e.Equipment_id != ? AND e.Approval_status = 'CON' 
                 LIMIT 3";
$similar_stmt = $conn->prepare($similar_query);
if ($similar_stmt) {
    $similar_stmt->bind_param("ii", $equipment['Owner_id'], $equipment_id);
    $similar_stmt->execute();
    $similar_equipment = $similar_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $similar_stmt->close();
} else {
    $similar_equipment = [];
}

include 'includes/header.php';
include 'includes/navigation.php';
?>

<div class="container" style="margin-top: 40px; margin-bottom: 40px;">
    <!-- Breadcrumb Navigation -->
    <nav style="margin-bottom: 20px;">
        <a href="index.php" style="color: #666; text-decoration: none;">Home</a> › 
        <a href="equipments.php" style="color: #666; text-decoration: none;">Equipment</a> › 
        <span style="color: #234a23; font-weight: bold;"><?= htmlspecialchars($equipment['Title']) ?></span>
    </nav>

    <!-- Success/Error Messages -->
    <?php if (isset($success_message)): ?>
        <div style="background: #d4edda; color: #155724; padding: 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #c3e6cb;">
            <strong>✅ Success!</strong> <?= htmlspecialchars($success_message) ?>
        </div>
    <?php endif; ?>

    <?php if (isset($error_message)): ?>
        <div style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #f5c6cb;">
            <strong>❌ Error!</strong> <?= htmlspecialchars($error_message) ?>
        </div>
    <?php endif; ?>

    <div style="display: grid; grid-template-columns: 1fr 350px; gap: 40px; margin-bottom: 40px;">

        <!-- Left Column - Equipment Details -->
        <div>
            <!-- Equipment Image with Gallery -->
            <div style="margin-bottom: 30px;">
                <div style="position: relative;">
                    <?php if (!empty($equipment['image_url'])): ?>
                        <img src="<?= htmlspecialchars($equipment['image_url']) ?>" 
                             alt="<?= htmlspecialchars($equipment['Title']) ?>"
                             style="width: 100%; height: 400px; object-fit: cover; border-radius: 12px; cursor: pointer; box-shadow: 0 8px 24px rgba(0,0,0,0.1);"
                             onclick="openImageModal(this)">

                        <!-- Image Overlay Info -->
                        <div style="position: absolute; bottom: 20px; left: 20px; background: rgba(0,0,0,0.7); color: white; padding: 10px 15px; border-radius: 6px;">
                            <small>Click to view full size</small>
                        </div>
                    <?php else: ?>
                        <div style="width: 100%; height: 400px; background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%); display: flex; align-items: center; justify-content: center; border-radius: 12px; color: #666; font-size: 64px;">
                            🚜
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Equipment Information Card -->
            <div style="background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); margin-bottom: 30px;">
                <h1 style="color: #234a23; margin-bottom: 10px; font-size: 28px; font-weight: 700;"><?= htmlspecialchars($equipment['Title']) ?></h1>

                <!-- Rating and Popularity -->
                <div style="display: flex; align-items: center; gap: 20px; margin-bottom: 25px; padding-bottom: 20px; border-bottom: 1px solid #eee;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <!-- Display average rating stars -->
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <?php if ($i <= floor($avg_rating)): ?>
                                <span style="color: #ffd700; font-size: 18px;">★</span>
                            <?php elseif ($i <= ceil($avg_rating) && $avg_rating > floor($avg_rating)): ?>
                                <span style="color: #ffd700; font-size: 18px;">☆</span>
                            <?php else: ?>
                                <span style="color: #ddd; font-size: 18px;">★</span>
                            <?php endif; ?>
                        <?php endfor; ?>
                        <span style="font-weight: 600; color: #234a23; margin-left: 5px;"><?= number_format($avg_rating, 1) ?></span>
                        <span style="color: #666;">(<b><?= $total_reviews ?></b> reviews)</span>
                    </div>
                    <div style="color: #666;">
                        📈 <strong><?= $booking_count ?></strong> times rented
                    </div>
                </div>

                <!-- Equipment Specifications -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 25px; margin-bottom: 30px;">
                    <div>
                        <h4 style="color: #666; margin-bottom: 8px; font-size: 14px; text-transform: uppercase; letter-spacing: 0.5px;">Brand</h4>
                        <p style="font-size: 18px; font-weight: 600; color: #333; margin: 0;"><?= htmlspecialchars($equipment['Brand']) ?></p>
                    </div>
                    <div>
                        <h4 style="color: #666; margin-bottom: 8px; font-size: 14px; text-transform: uppercase; letter-spacing: 0.5px;">Model</h4>
                        <p style="font-size: 18px; font-weight: 600; color: #333; margin: 0;"><?= htmlspecialchars($equipment['Model']) ?></p>
                    </div>
                    <?php if ($equipment['Year']): ?>
                        <div>
                            <h4 style="color: #666; margin-bottom: 8px; font-size: 14px; text-transform: uppercase; letter-spacing: 0.5px;">Year</h4>
                            <p style="font-size: 18px; font-weight: 600; color: #333; margin: 0;"><?= htmlspecialchars($equipment['Year']) ?></p>
                        </div>
                    <?php endif; ?>
                    <div>
                        <h4 style="color: #666; margin-bottom: 8px; font-size: 14px; text-transform: uppercase; letter-spacing: 0.5px;">SubCategory</h4>
                        <p style="font-size: 18px; font-weight: 600; color: #333; margin: 0;"><?= htmlspecialchars($equipment['Subcategory_name']) ?></p>
                    </div>
                </div>

                <!-- Description -->
                <div style="margin-bottom: 30px;">
                    <h3 style="color: #234a23; margin-bottom: 15px; font-size: 20px;">Description</h3>
                    <p style="line-height: 1.7; color: #444; font-size: 16px;"><?= nl2br(htmlspecialchars($equipment['Description'])) ?></p>
                </div>
            </div>

            <!-- Customer Reviews Section -->
            <div style="background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); margin-bottom: 30px;">
                <h3 style="color: #234a23; margin-bottom: 25px; font-size: 22px;">Customer Reviews</h3>

                <?php if (count($reviews) > 0): ?>
                    <div style="margin-bottom: 30px;">
                        <?php foreach ($reviews as $index => $review): ?>
                            <div style="border-bottom: <?= $index < count($reviews) - 1 ? '1px solid #f0f0f0' : 'none' ?>; padding-bottom: 20px; margin-bottom: 20px;">
                                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 12px;">
                                    <div>
                                        <h5 style="margin: 0; color: #234a23; font-weight: 600;"><?= htmlspecialchars($review['reviewer_name']) ?></h5>
                                        <div style="margin-top: 6px; display: flex; align-items: center; gap: 3px;">
                                            <!-- Display stars based on review rating -->
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <?php if ($i <= $review['Rating']): ?>
                                                    <span style="color: #ffd700; font-size: 16px; text-shadow: 0 0 2px rgba(255, 215, 0, 0.5);">★</span>
                                                <?php else: ?>
                                                    <span style="color: #e0e0e0; font-size: 16px;">★</span>
                                                <?php endif; ?>
                                            <?php endfor; ?>
                                            <span style="color: #666; font-size: 14px; margin-left: 10px; font-weight: 600;"><?= $review['Rating'] ?>/5</span>
                                            
                                            <!-- Rating text -->
                                            <?php 
                                            $rating_text = '';
                                            switch($review['Rating']) {
                                                case 1: $rating_text = 'Poor'; break;
                                                case 2: $rating_text = 'Fair'; break;
                                                case 3: $rating_text = 'Good'; break;
                                                case 4: $rating_text = 'Very Good'; break;
                                                case 5: $rating_text = 'Excellent'; break;
                                            }
                                            ?>
                                            <span style="color: #999; font-size: 12px; margin-left: 5px; font-style: italic;"><?= $rating_text ?></span>
                                        </div>
                                    </div>
                                    <small style="color: #999; font-size: 13px;"><?= date('M j, Y', strtotime($review['created_date'])) ?></small>
                                </div>
                                <?php if (!empty($review['comment'])): ?>
                                    <p style="color: #555; line-height: 1.6; margin: 0; font-size: 15px; padding-left: 0; margin-top: 8px;"><?= nl2br(htmlspecialchars($review['comment'])) ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px 20px; color: #666;">
                        <div style="font-size: 48px; margin-bottom: 15px;">💬</div>
                        <h4 style="margin-bottom: 10px;">No reviews yet</h4>
                        <p>Be the first to review this equipment!</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Add Review Form Section - Only show for eligible users -->
            <?php 
            $can_review = false;
            $existing_review = null;
            
            if (isset($_SESSION['logged_in']) && $_SESSION['logged_in']) {
                // Check conditions for showing review form
                $is_not_owner = $_SESSION['user_id'] != $equipment['Owner_id'];
                $is_not_admin = $_SESSION['user_type'] != 'A';
                
                if ($is_not_owner && $is_not_admin) {
                    $can_review = true;
                    
                    // Check if user has already reviewed this equipment
                    $existing_review_query = "SELECT Review_id FROM reviews WHERE Reviewer_id = ? AND Review_type = 'E' AND ID = ?";
                    $existing_stmt = $conn->prepare($existing_review_query);
                    
                    if ($existing_stmt) {
                        $existing_stmt->bind_param("ii", $_SESSION['user_id'], $equipment_id);
                        $existing_stmt->execute();
                        $existing_review = $existing_stmt->get_result()->fetch_assoc();
                        $existing_stmt->close();
                    }
                }
            }
            ?>

            <?php if ($can_review && !$existing_review): ?>
                <!-- Show review form only if user can review and hasn't reviewed yet -->
                <div style="background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); margin-bottom: 30px;">
                    <h3 style="color: #234a23; margin-bottom: 25px; font-size: 22px;">Leave a Review</h3>

                    <!-- Show review form -->
                    <form id="reviewForm" method="POST" style="max-width: 600px;">
                        <input type="hidden" name="action" value="add_review">
                        <input type="hidden" name="equipment_id" value="<?= $equipment_id ?>">
                        
                        <!-- Star Rating -->
                        <div style="margin-bottom: 25px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 10px; color: #333;">Rating *</label>
                            <div class="star-rating" style="display: flex; gap: 8px; margin-bottom: 10px; align-items: center;">
                                <span class="star" data-rating="1" style="font-size: 32px; color: #ddd; cursor: pointer; transition: all 0.2s ease; line-height: 1;">⭐</span>
                                <span class="star" data-rating="2" style="font-size: 32px; color: #ddd; cursor: pointer; transition: all 0.2s ease; line-height: 1;">⭐</span>
                                <span class="star" data-rating="3" style="font-size: 32px; color: #ddd; cursor: pointer; transition: all 0.2s ease; line-height: 1;">⭐</span>
                                <span class="star" data-rating="4" style="font-size: 32px; color: #ddd; cursor: pointer; transition: all 0.2s ease; line-height: 1;">⭐</span>
                                <span class="star" data-rating="5" style="font-size: 32px; color: #ddd; cursor: pointer; transition: all 0.2s ease; line-height: 1;">⭐</span>
                            </div>
                            <input type="hidden" name="rating" id="selectedRating" value="" required>
                            <small style="color: #666;">Click on stars to rate (1-5 stars)</small>
                            <div id="ratingDisplay" style="margin-top: 8px; font-weight: 600; color: #234a23; min-height: 20px;"></div>
                        </div>

                        <!-- Comment -->
                        <div style="margin-bottom: 25px;">
                            <label for="comment" style="display: block; font-weight: 600; margin-bottom: 8px; color: #333;">Your Review</label>
                            <textarea name="comment" id="comment" 
                                      rows="4" 
                                      placeholder="Share your thoughts about this equipment..."
                                      style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; resize: vertical; box-sizing: border-box;"
                                      maxlength="500"></textarea>
                            <small style="color: #666;">Optional - Maximum 500 characters</small>
                        </div>

                        <!-- Submit Button -->
                        <div style="text-align: center;">
                            <button type="submit" 
                                    style="background: #234a23; color: white; padding: 12px 30px; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; font-size: 16px; transition: background-color 0.3s ease;"
                                    onmouseover="this.style.background='#2d5d2f'" 
                                    onmouseout="this.style.background='#234a23'">
                                Submit Review
                            </button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <!-- Similar Equipment from Same Owner -->
            <?php if (count($similar_equipment) > 0): ?>
                <div style="background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08);">
                    <h3 style="color: #234a23; margin-bottom: 25px; font-size: 22px;">More from <?= htmlspecialchars($equipment['owner_name']) ?></h3>

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                        <?php foreach ($similar_equipment as $similar): ?>
                            <a href="equipment_details.php?id=<?= $similar['Equipment_id'] ?>" 
                               style="text-decoration: none; border: 1px solid #eee; border-radius: 8px; overflow: hidden; transition: transform 0.2s;">
                                   <?php if (!empty($similar['image_url'])): ?>
                                    <img src="<?= htmlspecialchars($similar['image_url']) ?>" 
                                         alt="<?= htmlspecialchars($similar['Title']) ?>"
                                         style="width: 100%; height: 120px; object-fit: cover;">
                                     <?php else: ?>
                                    <div style="width: 100%; height: 120px; background: #f5f5f5; display: flex; align-items: center; justify-content: center; color: #999;">
                                        🚜
                                    </div>
                                <?php endif; ?>
                                <div style="padding: 15px;">
                                    <h5 style="margin: 0; color: #234a23; font-size: 14px;"><?= htmlspecialchars($similar['Title']) ?></h5>
                                    <p style="margin: 5px 0 0 0; color: #666; font-size: 13px;">₹<?= number_format($similar['Daily_rate']) ?>/day</p>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Right Column - Booking Card -->
        <div>
            <!-- Pricing and Booking Card -->
            <div style="background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); top: 20px; margin-bottom: 20px;">
                <h3 style="color: #234a23; margin-bottom: 20px; font-size: 20px; text-align: center;">Rental Pricing</h3>

                <!-- Pricing Display -->
                <div style="text-align: center; margin-bottom: 25px; padding: 20px; background: #f8fffe; border-radius: 8px;">
                    <?php if ($equipment['Daily_rate'] > 0): ?>
                        <div style="margin-bottom: 10px;">
                            <span style="font-size: 24px; font-weight: 700; color: #234a23;">₹<?= number_format($equipment['Daily_rate'], 0) ?></span>
                            <span style="color: #666; font-size: 16px;">/day</span>
                        </div>
                    <?php endif; ?>
                    <?php if ($equipment['Hourly_rate'] > 0): ?>
                        <div>
                            <span style="font-size: 18px; font-weight: 600; color: #666;">₹<?= number_format($equipment['Hourly_rate'], 0) ?></span>
                            <span style="color: #666; font-size: 14px;">/hour</span>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Action Buttons -->
                <div style="text-align: center; margin-bottom: 25px;">
                    <?php if (isset($_SESSION['logged_in']) && $_SESSION['logged_in']): ?>
                        <?php if ($_SESSION['user_id'] == $equipment['Owner_id']): ?>
                            <!-- Equipment Owner [O] sees their own equipment -->
                            <div style="background: #e8f5e8; color: #234a23; padding: 15px; border-radius: 8px; text-align: center; margin-bottom: 12px; border: 1px solid #c3e6cb;">
                                <strong>✅ This is your equipment</strong>
                                <p style="margin: 5px 0 0 0; font-size: 14px;">You cannot book your own equipment</p>
                            </div>
                            <a href="owner/edit_equipment.php?id=<?= $equipment_id ?>" 
                               style="display: block; background: #17a2b8; color: white; padding: 12px 20px; text-decoration: none; border-radius: 6px; margin-bottom: 10px; text-align: center; font-weight: 600; transition: background-color 0.3s ease;">
                                ⚙️ Edit Equipment
                            </a>
                            <a href="owner/manage_equipment.php" 
                               style="display: block; background: #6c757d; color: white; padding: 12px 20px; text-decoration: none; border-radius: 6px; text-align: center; font-weight: 600; transition: background-color 0.3s ease;">
                                📋 Manage All Equipment
                            </a>

                        <?php elseif ($_SESSION['user_type'] == 'F'|| $_SESSION['user_type'] == 'O'): ?>
                            <!-- Farmer [F] can book equipment -->
                            <a href="booking_form.php?equipment_id=<?= $equipment_id ?>" 
                               style="display: block; background: linear-gradient(135deg, #234a23 0%, #2d5d2f 100%); color: white; padding: 15px 25px; text-decoration: none; border-radius: 8px; font-weight: 600; margin-bottom: 12px; text-align: center; box-shadow: 0 4px 12px rgba(35, 74, 35, 0.3); transition: all 0.3s ease;">
                                📅 Book Now
                            </a>

                            <?php
                            // Generate WhatsApp link for Farmer
                            $phone_digits = preg_replace('/\D/', '', $equipment['owner_phone']);
                            if (strlen($phone_digits) == 10) {
                                $phone_digits = '91' . $phone_digits; // Add India country code
                            }
                            $whatsapp_message = "Hi! I'm interested in renting your " . $equipment['Title'] . " (" . $equipment['Brand'] . " " . $equipment['Model'] . ") from AgriRent. Could you provide more details about availability and pricing?";
                            $encoded_message = rawurlencode($whatsapp_message);
                            $whatsapp_url = "https://wa.me/" . $phone_digits . "?text=" . $encoded_message;
                            ?>

                            <!-- Professional WhatsApp Contact Button -->
                            <a href="<?= htmlspecialchars($whatsapp_url) ?>" 
                               target="_blank" 
                               rel="noopener noreferrer"
                               style="display: flex; align-items: center; justify-content: center; gap: 10px; background: #25d366; color: white; padding: 12px 20px; text-decoration: none; border-radius: 25px; text-align: center; font-weight: 600; transition: all 0.3s ease; box-shadow: 0 4px 15px rgba(37, 211, 102, 0.3); margin: 0 auto; max-width: 300px;"
                               onmouseover="this.style.background = '#128c7e'; this.style.transform = 'translateY(-2px)'; this.style.boxShadow = '0 6px 20px rgba(37, 211, 102, 0.4)'"
                               onmouseout="this.style.background = '#25d366'; this.style.transform = 'translateY(0)'; this.style.boxShadow = '0 4px 15px rgba(37, 211, 102, 0.3)'">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M20.5 3.4C18.2 1.1 15.2 0 12 0 5.4 0 0 5.4 0 12c0 2.1.5 4.2 1.6 6L0 24l6.1-1.6C8 23.4 10 24 12 24c6.6 0 12-5.4 12-12 0-3.2-1.2-6.2-3.5-8.6zM12 22c-1.8 0-3.6-.5-5.1-1.4l-.4-.2-3.9 1 1-3.8-.3-.4C2.5 15.7 2 13.9 2 12 2 6.5 6.5 2 12 2s10 4.5 10 10-4.5 10-10 10zm5.5-7.5c-.3-.1-1.8-.9-2.1-1-.3-.1-.5-.1-.7.1-.2.3-.8 1-.9 1.2-.1.2-.3.2-.5.1-.3-.1-1.1-.4-2.1-1.3-.8-.7-1.3-1.6-1.4-1.9-.1-.3 0-.4.1-.5.1-.1.3-.3.4-.4.1-.1.2-.3.2-.4.1-.1 0-.3 0-.4-.1-.1-.7-1.7-.9-2.3-.2-.6-.4-.5-.7-.5H8.5c-.2 0-.5.1-.8.4-.3.3-1.1 1.1-1.1 2.6s1.1 3 1.3 3.2c.1.2 2.1 3.2 5.1 4.5.7.3 1.3.5 1.7.6.7.2 1.4.2 1.9.1.6-.1 1.8-.7 2.1-1.4.3-.7.3-1.3.2-1.4-.1-.2-.3-.2-.6-.4z"/>
                                </svg>
                                <span>Contact Owner via WhatsApp</span>
                            </a>

                        <?php elseif ($_SESSION['user_type'] == 'A'): ?>
                            <!-- Admin [A] sees management options -->
                            <div style="background: #fff3cd; color: #856404; padding: 15px; border-radius: 6px; text-align: center; margin-bottom: 12px; border: 1px solid #ffeaa7;">
                                <strong>🔧 Administrator Access</strong>
                                <p style="margin: 5px 0 0 0; font-size: 14px;">You have admin privileges for this equipment</p>
                            </div>
                            <a href="admin/equipment.php" 
                               style="display: block; background: #dc3545; color: white; padding: 12px 20px; text-decoration: none; border-radius: 6px; margin-bottom: 10px; text-align: center; font-weight: 600; transition: background-color 0.3s ease;">
                                🛠️ Admin Panel
                            </a>
                            <a href="tel:<?= htmlspecialchars($equipment['owner_phone']) ?>" 
                               style="display: block; background: #17a2b8; color: white; padding: 12px 20px; text-decoration: none; border-radius: 6px; text-align: center; font-weight: 600; transition: background-color 0.3s ease;">
                                📞 Contact Owner
                            </a>

                        <?php else: ?>
                            <!-- Other user types (invalid) -->
                            <div style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 6px; text-align: center; margin-bottom: 12px; border: 1px solid #f5c6cb;">
                                <strong>❌ Access Restricted</strong>
                                <p style="margin: 5px 0 0 0; font-size: 14px;">Invalid user type</p>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <!-- Not logged in users -->
                        <a href="login.php" 
                           style="display: block; background: #234a23; color: white; padding: 15px 25px; text-decoration: none; border-radius: 8px; font-weight: 600; margin-bottom: 10px; text-align: center; transition: background-color 0.3s ease;">
                            Login to Book Equipment
                        </a>
                        <p style="color: #666; font-size: 12px; text-align: center; margin: 0;">Please log in to rent this equipment</p>
                    <?php endif; ?>
                </div>

                <!-- Owner Information -->
                <div style="background: #f8f9fa; padding: 20px; border-radius: 8px;">
                    <h4 style="color: #234a23; margin-bottom: 15px; font-size: 16px;">Equipment Owner</h4>
                    <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px;">
                        <div style="width: 50px; height: 50px; background: #234a23; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 18px;">
                            <?= strtoupper(substr($equipment['owner_name'], 0, 1)) ?>
                        </div>
                        <div>
                            <h5 style="margin: 0; color: #234a23;"><?= htmlspecialchars($equipment['owner_name']) ?></h5>
                            <small style="color: #666;">Equipment Owner</small>
                        </div>
                    </div>
                    <div style="font-size: 14px;">
                        <p style="margin: 5px 0;"><strong>Phone:</strong> <a href="tel:<?= htmlspecialchars($equipment['owner_phone']) ?>" style="color: #234a23;"><?= htmlspecialchars($equipment['owner_phone']) ?></a></p>
                    </div>
                </div>
            </div>

            <!-- Equipment Stats -->
            <div style="background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08);">
                <h4 style="color: #234a23; margin-bottom: 15px; font-size: 16px;">Equipment Stats</h4>
                <div style="display: flex; flex-direction: column; gap: 12px;">
                    <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f0f0f0;">
                        <span style="color: #666;">Listed:</span>
                        <span style="font-weight: 600;"><?= date('M j, Y', strtotime($equipment['listed_date'])) ?></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f0f0f0;">
                        <span style="color: #666;">Total Bookings:</span>
                        <span style="color: #234a23; font-weight: 600;"><?= $booking_count ?></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 0;">
                        <span style="color: #666;">Average Rating:</span>
                        <div style="display: flex; align-items: center; gap: 5px;">
                            <!-- Display average rating stars -->
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <?php if ($i <= floor($avg_rating)): ?>
                                    <span style="color: #ffd700; font-size: 14px;">★</span>
                                <?php else: ?>
                                    <span style="color: #ddd; font-size: 14px;">★</span>
                                <?php endif; ?>
                            <?php endfor; ?>
                            <span style="color: #666; font-weight: 600; margin-left: 3px;"><?= number_format($avg_rating, 1) ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Full Screen Image Modal -->
<div id="imageModal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.95);">
    <span onclick="closeImageModal()" style="position: absolute; top: 30px; right: 45px; color: #fff; font-size: 40px; font-weight: bold; cursor: pointer; z-index: 1001;">&times;</span>
    <img id="modalImage" style="margin: auto; display: block; max-width: 90%; max-height: 90%; margin-top: 2%; border-radius: 8px;">
    <div style="text-align: center; margin-top: 20px;">
        <span style="color: white; font-size: 16px;"><?= htmlspecialchars($equipment['Title']) ?></span>
    </div>
</div>

<script>
// Star rating functionality - Only initialize if form exists
const starRating = document.querySelector('.star-rating');
if (starRating) {
    const stars = document.querySelectorAll('.star');
    const ratingInput = document.getElementById('selectedRating');
    
    // Add click event to each star
    stars.forEach((star, index) => {
        star.addEventListener('click', function() {
            const rating = parseInt(this.getAttribute('data-rating'));
            ratingInput.value = rating;
            
            // Update all stars based on rating
            updateStarDisplay(rating);
        });
        
        // Add hover effect
        star.addEventListener('mouseenter', function() {
            const rating = parseInt(this.getAttribute('data-rating'));
            updateStarDisplay(rating, true);
        });
    });

    // Reset to selected rating when mouse leaves the rating area
    starRating.addEventListener('mouseleave', function() {
        const selectedRating = parseInt(ratingInput.value) || 0;
        updateStarDisplay(selectedRating);
    });
    
    // Function to update star display
    function updateStarDisplay(rating, isHover = false) {
        stars.forEach((star, index) => {
            const starNumber = index + 1;
            if (starNumber <= rating) {
                star.style.color = isHover ? '#ffed4e' : '#ffd700';
            } else {
                star.style.color = '#ddd';
            }
        });
        
        // Show rating text
        const ratingDisplay = document.getElementById('ratingDisplay');
        if (ratingDisplay && rating > 0) {
            const ratingTexts = {
                1: '⭐ Poor',
                2: '⭐⭐ Fair', 
                3: '⭐⭐⭐ Good',
                4: '⭐⭐⭐⭐ Very Good',
                5: '⭐⭐⭐⭐⭐ Excellent'
            };
            ratingDisplay.innerHTML = ratingTexts[rating] || '';
        }
    }
}

// Form validation - Only if form exists
const reviewForm = document.getElementById('reviewForm');
if (reviewForm) {
    reviewForm.addEventListener('submit', function(e) {
        const rating = document.getElementById('selectedRating').value;
        if (!rating || rating === '0') {
            e.preventDefault();
            alert('Please select a star rating before submitting.');
            return false;
        }
        
        // Show loading state
        const submitBtn = this.querySelector('button[type="submit"]');
        if (submitBtn) {
            submitBtn.innerHTML = 'Submitting...';
            submitBtn.disabled = true;
        }
    });
}

// Image modal functions
function openImageModal(img) {
    const modal = document.getElementById('imageModal');
    const modalImage = document.getElementById('modalImage');
    if (modal && modalImage) {
        modal.style.display = 'block';
        modalImage.src = img.src;
        document.body.style.overflow = 'hidden';
    }
}

function closeImageModal() {
    const modal = document.getElementById('imageModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
    }
}

// Close modal when clicking outside the image
window.onclick = function (event) {
    const modal = document.getElementById('imageModal');
    if (event.target === modal) {
        closeImageModal();
    }
}

// Keyboard navigation for modal
document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        closeImageModal();
    }
});
</script>

<style>
    @media (max-width: 768px) {
        .container > div {
            grid-template-columns: 1fr !important;
            gap: 20px !important;
        }

        .container > div > div:first-child > div {
            grid-template-columns: 1fr !important;
        }
    }

    /* Star rating improvements */
    .star-rating {
        user-select: none;
    }
    
    .star {
        display: inline-block;
        cursor: pointer;
        transition: color 0.2s ease, transform 0.1s ease;
        user-select: none;
    }
    
    .star:hover {
        transform: scale(1.1);
    }
    
    .star:active {
        transform: scale(0.95);
    }

    /* Smooth hover effects */
    a:hover {
        transform: translateY(-1px);
        transition: transform 0.2s ease;
    }

    /* Image hover effect */
    img:hover {
        filter: brightness(1.05);
        transition: filter 0.3s ease;
    }
</style>

<?php include 'includes/footer.php'; ?>
