<?php
session_start();
require_once 'auth/config.php';

// Get product ID from URL
$product_id = intval($_GET['id'] ?? 0);
if ($product_id <= 0) {
    header('Location: products.php');
    exit();
}

// Fetch product details with seller info and images
$product_query = "SELECT p.*, u.Name as seller_name, u.Phone as seller_phone, u.Email as seller_email,
                         i.image_url, ps.Subcategory_name, pc.Category_name
                  FROM product p 
                  JOIN users u ON p.seller_id = u.user_id 
                  LEFT JOIN images i ON (i.image_type = 'P' AND i.ID = p.product_id)
                  LEFT JOIN product_subcategories ps ON p.Subcategory_id = ps.Subcategory_id
                  LEFT JOIN product_categories pc ON ps.Category_id = pc.Category_id
                  WHERE p.product_id = ? AND p.Approval_status = 'CON'";

$stmt = $conn->prepare($product_query);
$stmt->bind_param("i", $product_id);
$stmt->execute();
$result = $stmt->get_result();
$product = $result->fetch_assoc();
$stmt->close();

if (!$product) {
    header('Location: products.php');
    exit();
}

// Fetch product reviews
$reviews_query = "SELECT r.*, u.Name as reviewer_name 
                 FROM reviews r 
                 JOIN users u ON r.Reviewer_id = u.user_id 
                 WHERE r.Review_type = 'P' AND r.ID = ? 
                 ORDER BY r.created_date DESC 
                 LIMIT 10";

$reviews_stmt = $conn->prepare($reviews_query);
$reviews_stmt->bind_param("i", $product_id);
$reviews_stmt->execute();
$reviews_result = $reviews_stmt->get_result();
$reviews = [];
while ($review = $reviews_result->fetch_assoc()) {
    $reviews[] = $review;
}
$reviews_stmt->close();

// Calculate average rating
$avg_rating = 0;
$total_reviews = 0;
if (count($reviews) > 0) {
    $total_rating = array_sum(array_column($reviews, 'Rating'));
    $total_reviews = count($reviews);
    $avg_rating = $total_rating / $total_reviews;
}

// Fetch order count for popularity
$order_count_query = "SELECT COUNT(*) as total_orders 
                     FROM product_orders 
                     WHERE Product_id = ? AND Status = 'CON'";

$order_stmt = $conn->prepare($order_count_query);
$order_stmt->bind_param("i", $product_id);
$order_stmt->execute();
$order_count = $order_stmt->get_result()->fetch_assoc()['total_orders'];
$order_stmt->close();

// Get similar products from same seller
$similar_query = "SELECT p.product_id, p.Name, p.Price, i.image_url 
                 FROM product p 
                 LEFT JOIN images i ON (i.image_type = 'P' AND i.ID = p.product_id)
                 WHERE p.seller_id = ? AND p.product_id != ? AND p.Approval_status = 'CON' 
                 LIMIT 3";

$similar_stmt = $conn->prepare($similar_query);
$similar_stmt->bind_param("ii", $product['seller_id'], $product_id);
$similar_stmt->execute();
$similar_products = $similar_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$similar_stmt->close();

include 'includes/header.php';
include 'includes/navigation.php';
?>

<div class="container" style="margin-top: 40px; margin-bottom: 40px;">
    <!-- Breadcrumb Navigation -->
    <nav style="margin-bottom: 20px;">
        <a href="index.php" style="color: #666; text-decoration: none;">Home</a> › 
        <a href="products.php" style="color: #666; text-decoration: none;">Products</a> › 
        <span style="color: #234a23; font-weight: bold;"><?= htmlspecialchars($product['Name']) ?></span>
    </nav>
    
    <div style="display: grid; grid-template-columns: 1fr 350px; gap: 40px; margin-bottom: 40px;">
        <!-- Left Column - Product Details -->
        <div>
            <!-- Product Image with Gallery -->
            <div style="margin-bottom: 30px;">
                <div style="position: relative;">
                    <?php if (!empty($product['image_url'])): ?>
                        <img src="<?= htmlspecialchars($product['image_url']) ?>" 
                             alt="<?= htmlspecialchars($product['Name']) ?>"
                             style="width: 100%; height: 400px; object-fit: cover; border-radius: 12px; cursor: pointer; box-shadow: 0 8px 24px rgba(0,0,0,0.1);"
                             onclick="openImageModal(this)">
                        <!-- Image Overlay Info -->
                        <div style="position: absolute; bottom: 20px; left: 20px; background: rgba(0,0,0,0.7); color: white; padding: 10px 15px; border-radius: 6px;">
                            <small>Click to view full size</small>
                        </div>
                    <?php else: ?>
                        <div style="width: 100%; height: 400px; background: linear-gradient(135deg, #e8f5e8 0%, #a8e6a8 100%); display: flex; align-items: center; justify-content: center; border-radius: 12px; color: #666; font-size: 64px;">
                            📦
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Product Information Card -->
            <div style="background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); margin-bottom: 30px;">
                <h1 style="color: #234a23; margin-bottom: 10px; font-size: 28px; font-weight: 700;"><?= htmlspecialchars($product['Name']) ?></h1>
                
                <!-- Rating and Popularity -->
                <div style="display: flex; align-items: center; gap: 20px; margin-bottom: 25px; padding-bottom: 20px; border-bottom: 1px solid #eee;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <span style="color: <?= $i <= $avg_rating ? '#ffd700' : '#ddd' ?>; font-size: 18px;">⭐</span>
                        <?php endfor; ?>
                        <span style="font-weight: 600; color: #234a23;"><?= number_format($avg_rating, 1) ?></span>
                        <span style="color: #666;">(<b><?= $total_reviews ?></b> reviews)</span>
                    </div>
                    <div style="color: #666;">
                        📈 <strong><?= $order_count ?></strong> times ordered
                    </div>
                </div>
                
                <!-- Product Specifications -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 25px; margin-bottom: 30px;">
                    <div>
                        <h4 style="color: #666; margin-bottom: 8px; font-size: 14px; text-transform: uppercase; letter-spacing: 0.5px;">Category</h4>
                        <p style="font-size: 18px; font-weight: 600; color: #333; margin: 0;"><?= htmlspecialchars($product['Category_name'] ?? 'N/A') ?></p>
                    </div>
                    <div>
                        <h4 style="color: #666; margin-bottom: 8px; font-size: 14px; text-transform: uppercase; letter-spacing: 0.5px;">Type</h4>
                        <p style="font-size: 18px; font-weight: 600; color: #333; margin: 0;"><?= htmlspecialchars($product['Subcategory_name'] ?? 'N/A') ?></p>
                    </div>
                    <div>
                        <h4 style="color: #666; margin-bottom: 8px; font-size: 14px; text-transform: uppercase; letter-spacing: 0.5px;">Available Quantity</h4>
                        <p style="font-size: 18px; font-weight: 600; color: #333; margin: 0;"><?= number_format($product['Quantity'], 1) ?> <?= strtoupper($product['Unit']) ?></p>
                    </div>
                    <div>
                        <h4 style="color: #666; margin-bottom: 8px; font-size: 14px; text-transform: uppercase; letter-spacing: 0.5px;">Unit</h4>
                        <p style="font-size: 18px; font-weight: 600; color: #333; margin: 0;"><?= strtoupper($product['Unit']) ?></p>
                    </div>
                </div>
                
                <!-- Description -->
                <div style="margin-bottom: 30px;">
                    <h3 style="color: #234a23; margin-bottom: 15px; font-size: 20px;">Description</h3>
                    <p style="line-height: 1.7; color: #444; font-size: 16px;"><?= nl2br(htmlspecialchars($product['Description'])) ?></p>
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
                                        <div style="margin-top: 6px; display: flex; align-items: center; gap: 5px;">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <span style="color: <?= $i <= $review['Rating'] ? '#ffd700' : '#ddd' ?>; font-size: 14px;">⭐</span>
                                            <?php endfor; ?>
                                            <span style="color: #666; font-size: 14px; margin-left: 5px;"><?= $review['Rating'] ?>/5</span>
                                        </div>
                                    </div>
                                    <small style="color: #999; font-size: 13px;"><?= date('M j, Y', strtotime($review['created_date'])) ?></small>
                                </div>
                                <?php if (!empty($review['comment'])): ?>
                                    <p style="color: #555; line-height: 1.6; margin: 0; font-size: 15px;"><?= nl2br(htmlspecialchars($review['comment'])) ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px 20px; color: #666;">
                        <div style="font-size: 48px; margin-bottom: 15px;">💬</div>
                        <h4 style="margin-bottom: 10px;">No reviews yet</h4>
                        <p>Be the first to review this product after purchasing!</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Similar Products from Same Seller -->
            <?php if (count($similar_products) > 0): ?>
                <div style="background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08);">
                    <h3 style="color: #234a23; margin-bottom: 25px; font-size: 22px;">More from <?= htmlspecialchars($product['seller_name']) ?></h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                        <?php foreach ($similar_products as $similar): ?>
                            <a href="product_details.php?id=<?= $similar['product_id'] ?>" 
                               style="text-decoration: none; border: 1px solid #eee; border-radius: 8px; overflow: hidden; transition: transform 0.2s;">
                                <?php if (!empty($similar['image_url'])): ?>
                                    <img src="<?= htmlspecialchars($similar['image_url']) ?>" 
                                         alt="<?= htmlspecialchars($similar['Name']) ?>"
                                         style="width: 100%; height: 120px; object-fit: cover;">
                                <?php else: ?>
                                    <div style="width: 100%; height: 120px; background: #f5f5f5; display: flex; align-items: center; justify-content: center; color: #999;">
                                        📦
                                    </div>
                                <?php endif; ?>
                                <div style="padding: 15px;">
                                    <h5 style="margin: 0; color: #234a23; font-size: 14px;"><?= htmlspecialchars($similar['Name']) ?></h5>
                                    <p style="margin: 5px 0 0 0; color: #666; font-size: 13px;">₹<?= number_format($similar['Price'], 2) ?></p>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Right Column - Purchase Card -->
        <div>
            <!-- Pricing and Purchase Card -->
            <div style="background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); top: 20px; margin-bottom: 20px;">
                <h3 style="color: #234a23; margin-bottom: 20px; font-size: 20px; text-align: center;">Product Pricing</h3>
                
                <!-- Pricing Display -->
                <div style="text-align: center; margin-bottom: 25px; padding: 20px; background: #f8fff8; border-radius: 8px;">
                    <div style="margin-bottom: 10px;">
                        <span style="font-size: 24px; font-weight: 700; color: #234a23;">₹<?= number_format($product['Price'], 2) ?></span>
                        <span style="color: #666; font-size: 16px;">/<?= strtoupper($product['Unit']) ?></span>
                    </div>
                    <?php if ($product['Quantity'] > 0): ?>
                        <div style="color: #234a23; font-size: 14px;">
                            ✅ In Stock (<?= number_format($product['Quantity'], 1) ?> <?= strtoupper($product['Unit']) ?> available)
                        </div>
                    <?php else: ?>
                        <div style="color: #dc3545; font-size: 14px;">
                            ❌ Out of Stock
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Action Buttons -->
                <div style="text-align: center; margin-bottom: 25px;">
                    <?php if (isset($_SESSION['logged_in']) && $_SESSION['logged_in']): ?>
                        <?php if ($_SESSION['user_id'] == $product['seller_id']): ?>
                            <!-- Product Seller [S] sees their own product -->
                            <div style="background: #e8f5e8; color: #234a23; padding: 15px; border-radius: 8px; text-align: center; margin-bottom: 12px; border: 1px solid #c3e6cb;">
                                <strong>✅ This is your product</strong>
                                <p style="margin: 5px 0 0 0; font-size: 14px;">You cannot buy your own product</p>
                            </div>
                            <a href="seller/edit_product.php?id=<?= $product_id ?>" 
                               style="display: block; background: #17a2b8; color: white; padding: 12px 20px; text-decoration: none; border-radius: 6px; margin-bottom: 10px; text-align: center; font-weight: 600; transition: background-color 0.3s ease;">
                                ⚙️ Edit Product
                            </a>
                            <a href="seller/manage_products.php" 
                               style="display: block; background: #6c757d; color: white; padding: 12px 20px; text-decoration: none; border-radius: 6px; text-align: center; font-weight: 600; transition: background-color 0.3s ease;">
                                📋 Manage All Products
                            </a>
                        <?php elseif ($_SESSION['user_type'] == 'F' || $_SESSION['user_type'] == 'O'): ?>
                            <!-- Farmer [F] can buy products -->
                            <?php if ($product['Quantity'] > 0): ?>
                                <a href="order_form.php?product_id=<?= $product_id ?>" 
                                   style="display: block; background: linear-gradient(135deg, #234a23 0%, #34ce57 100%); color: white; padding: 15px 25px; text-decoration: none; border-radius: 8px; font-weight: 600; margin-bottom: 12px; text-align: center; box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3); transition: all 0.3s ease;">
                                    🛒 Order Now
                                </a>
                            <?php else: ?>
                                <div style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 8px; text-align: center; margin-bottom: 12px; border: 1px solid #f5c6cb;">
                                    <strong>❌ Out of Stock</strong>
                                    <p style="margin: 5px 0 0 0; font-size: 14px;">This product is currently unavailable</p>
                                </div>
                            <?php endif; ?>
                            
                            <?php
                            // Generate WhatsApp link for Farmer
                            $phone_digits = preg_replace('/\D/', '', $product['seller_phone']);
                            if (strlen($phone_digits) == 10) {
                                $phone_digits = '91' . $phone_digits; // Add India country code
                            }
                            $whatsapp_message = "Hi! I'm interested in buying your " . $product['Name'] . " from AgriRent. Could you provide more details about availability and pricing?";
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
                                <span>Contact Seller via WhatsApp</span>
                            </a>
                        <?php elseif ($_SESSION['user_type'] == 'A'): ?>
                            <!-- Admin [A] sees management options -->
                            <div style="background: #fff3cd; color: #856404; padding: 15px; border-radius: 6px; text-align: center; margin-bottom: 12px; border: 1px solid #ffeaa7;">
                                <strong>🔧 Administrator Access</strong>
                                <p style="margin: 5px 0 0 0; font-size: 14px;">You have admin privileges for this product</p>
                            </div>
                            <a href="admin/products.php" 
                               style="display: block; background: #dc3545; color: white; padding: 12px 20px; text-decoration: none; border-radius: 6px; margin-bottom: 10px; text-align: center; font-weight: 600; transition: background-color 0.3s ease;">
                                🛠️ Admin Panel
                            </a>
                            <a href="tel:<?= htmlspecialchars($product['seller_phone']) ?>" 
                               style="display: block; background: #17a2b8; color: white; padding: 12px 20px; text-decoration: none; border-radius: 6px; text-align: center; font-weight: 600; transition: background-color 0.3s ease;">
                                📞 Contact Seller
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
                            Login to Order Product
                        </a>
                        <p style="color: #666; font-size: 12px; text-align: center; margin: 0;">Please log in to purchase this product</p>
                    <?php endif; ?>
                </div>
                
                <!-- Seller Information -->
                <div style="background: #f8f9fa; padding: 20px; border-radius: 8px;">
                    <h4 style="color: #234a23; margin-bottom: 15px; font-size: 16px;">Product Seller</h4>
                    <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px;">
                        <div style="width: 50px; height: 50px; background: #234a23; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 18px;">
                            <?= strtoupper(substr($product['seller_name'], 0, 1)) ?>
                        </div>
                        <div>
                            <h5 style="margin: 0; color: #234a23;"><?= htmlspecialchars($product['seller_name']) ?></h5>
                            <small style="color: #666;">Product Seller</small>
                        </div>
                    </div>
                    <div style="font-size: 14px;">
                        <p style="margin: 5px 0;"><strong>Phone:</strong> <a href="tel:<?= htmlspecialchars($product['seller_phone']) ?>" style="color: #234a23;"><?= htmlspecialchars($product['seller_phone']) ?></a></p>
                    </div>
                </div>
            </div>
            
            <!-- Product Stats -->
            <div style="background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08);">
                <h4 style="color: #234a23; margin-bottom: 15px; font-size: 16px;">Product Stats</h4>
                <div style="display: flex; flex-direction: column; gap: 12px;">
                    <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f0f0f0;">
                        <span style="color: #666;">Listed:</span>
                        <span style="font-weight: 600;"><?= date('M j, Y', strtotime($product['listed_date'])) ?></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f0f0f0;">
                        <span style="color: #666;">Total Orders:</span>
                        <span style="color: #234a23; font-weight: 600;"><?= $order_count ?></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 8px 0;">
                        <span style="color: #666;">Average Rating:</span>
                        <span style="color: #ffd700; font-weight: 600;"><?= number_format($avg_rating, 1) ?> ⭐</span>
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
        <span style="color: white; font-size: 16px;"><?= htmlspecialchars($product['Name']) ?></span>
    </div>
</div>

<script>
    function openImageModal(img) {
        document.getElementById('imageModal').style.display = 'block';
        document.getElementById('modalImage').src = img.src;
        document.body.style.overflow = 'hidden';
    }
    
    function closeImageModal() {
        document.getElementById('imageModal').style.display = 'none';
        document.body.style.overflow = 'auto';
    }

    // Close modal when clicking outside the image
    window.onclick = function (event) {
        const modal = document.getElementById('imageModal');
        if (event.target == modal) {
            closeImageModal();
        }
    }

    // Keyboard navigation
    document.onkeydown = function (event) {
        if (event.key === 'Escape') {
            closeImageModal();
        }
    }
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
