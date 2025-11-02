<?php
session_start();
require_once 'auth/config.php';

// Only allow logged-in farmers to place orders
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true ) {
    header('Location: login.php');
    exit();
}

$customer_id = (int) $_SESSION['user_id'];

//--- GET PRODUCT ID FROM URL ---//
$product_id = null;
if (isset($_GET['product_id']) && !empty($_GET['product_id'])) {
    $product_id = intval($_GET['product_id']);
} elseif (isset($_GET['id']) && !empty($_GET['id'])) {
    $product_id = intval($_GET['id']);
} elseif (isset($_POST['product_id']) && !empty($_POST['product_id'])) {
    $product_id = intval($_POST['product_id']);
}

// Validate product_id
if (!$product_id || $product_id <= 0) {
    echo "<div style='text-align: center; padding: 50px;'>
            <h2>Invalid Product</h2>
            <p>No product specified for ordering.</p>
            <a href='products.php' class='btn'>← Browse Products</a>
          </div>";
    include 'includes/footer.php';
    exit();
}

//--- FETCH PRODUCT DETAILS ---//
$product_query = "SELECT p.*, u.Name as seller_name, u.Phone as seller_phone, u.Email as seller_email,
                         ps.Subcategory_name, pc.Category_name,
                         i.image_url
                  FROM product p 
                  JOIN users u ON p.seller_id = u.user_id 
                  LEFT JOIN product_subcategories ps ON p.Subcategory_id = ps.Subcategory_id
                  LEFT JOIN product_categories pc ON ps.Category_id = pc.Category_id
                  LEFT JOIN images i ON (i.image_type = 'P' AND i.ID = p.product_id)
                  WHERE p.product_id = ? AND p.Approval_status = 'CON'";

$stmt = $conn->prepare($product_query);
if (!$stmt) {
    die("Database error occurred. Please try again later.");
}
$stmt->bind_param('i', $product_id);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$product) {
    echo "<div style='text-align: center; padding: 50px;'>
            <h2>Product Not Found</h2>
            <p>The requested product is not available or has been removed.</p>
            <a href='products.php' class='btn'>← Browse Products</a>
          </div>";
    include 'includes/footer.php';
    exit();
}

// Check if trying to order own product
if ($product['seller_id'] == $customer_id) {
    echo "<div style='text-align: center; padding: 50px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 8px; margin: 20px;'>
            <h2 style='color: #856404;'>Cannot Order Own Product</h2>
            <p style='color: #856404;'>You cannot order your own product.</p>
            <p style='color: #856404;'>Check your seller dashboard for orders from other users.</p>
            <a href='seller/manage_products.php' class='btn' style='background: #856404;'>Go to Dashboard</a>
          </div>";
    include 'includes/footer.php';
    exit();
}

// Check if product is out of stock
if ($product['Quantity'] <= 0) {
    echo "<div style='text-align: center; padding: 50px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 8px; margin: 20px;'>
            <h2 style='color: #721c24;'>Out of Stock</h2>
            <p style='color: #721c24;'>This product is currently out of stock.</p>
            <p style='color: #721c24;'>Please contact the seller for more information about restocking.</p>
            <a href='products.php' class='btn' style='background: #721c24;'>← Browse Other Products</a>
          </div>";
    include 'includes/footer.php';
    exit();
}

// Check if product_orders table exists and create if needed
$table_check = $conn->query("SHOW TABLES LIKE 'product_orders'");
if (!$table_check || $table_check->num_rows == 0) {
    $create_table_sql = "CREATE TABLE `product_orders` (
        `Order_id` int(11) NOT NULL AUTO_INCREMENT,
        `Product_id` int(11) NOT NULL,
        `buyer_id` int(11) NOT NULL,
        `quantity` decimal(10,2) NOT NULL,
        `total_price` decimal(10,2) NOT NULL,
        `delivery_address` int(11) NOT NULL,
        `Status` char(3) DEFAULT 'PEN',
        `order_date` datetime DEFAULT current_timestamp(),
        PRIMARY KEY (`Order_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    
    $conn->query($create_table_sql);
}

// Fetch user saved delivery addresses
$address_query = "SELECT address_id, address, city, state, Pin_code FROM user_addresses WHERE user_id = ?";
$address_stmt = $conn->prepare($address_query);
if (!$address_stmt) {
    die("Database error occurred. Please try again later.");
}
$address_stmt->bind_param('i', $customer_id);
$address_stmt->execute();
$address_result = $address_stmt->get_result();

// Check if user has any delivery addresses
if ($address_result->num_rows == 0) {
    header('Location: addresses.php?message=' . urlencode('Please add a delivery address to place orders.'));
    exit();
}

$errors = [];
$success_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!empty($_POST['quantity']) || isset($_POST['place_order']))) {
    $quantity = floatval($_POST['quantity'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $delivery_address = intval($_POST['delivery_address'] ?? 0);

    // Validation
    if ($quantity <= 0) {
        $errors[] = 'Please enter a valid quantity';
    }

    if ($quantity > $product['Quantity']) {
        $errors[] = "Only {$product['Quantity']} {$product['Unit']} available in stock";
    }

    if ($delivery_address <= 0) {
        $errors[] = 'Please select a valid delivery address.';
    }

    // Check pending orders
    if (empty($errors)) {
        $pending_query = "SELECT SUM(quantity) as total_pending FROM product_orders WHERE Product_id = ? AND Status = 'PEN'";
        $stmt = $conn->prepare($pending_query);
        if ($stmt) {
            $stmt->bind_param('i', $product_id);
            $stmt->execute();
            $pending_result = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $total_pending = $pending_result['total_pending'] ?? 0;
            $available_quantity = $product['Quantity'] - $total_pending;

            if ($quantity > $available_quantity) {
                $errors[] = "Only {$available_quantity} {$product['Unit']} currently available (considering pending orders)";
            }
        }
    }

    

    // Verify delivery address belongs to user
    if (empty($errors) && $delivery_address > 0) {
        $addr_check = $conn->prepare("SELECT address_id FROM user_addresses WHERE address_id = ? AND user_id = ?");
        if ($addr_check) {
            $addr_check->bind_param('ii', $delivery_address, $customer_id);
            $addr_check->execute();
            if ($addr_check->get_result()->num_rows == 0) {
                $errors[] = 'Invalid delivery address selected.';
            }
            $addr_check->close();
        }
    }

    // Insert order if no errors
    if (empty($errors)) {
        $total_price = $product['Price'] * $quantity;

        $insert_sql = "INSERT INTO product_orders (Product_id, buyer_id, quantity, total_price, delivery_address, Status, order_date) 
                       VALUES (?, ?, ?, ?, ?, 'PEN', NOW())";

        $stmt = $conn->prepare($insert_sql);
        if ($stmt) {
            $stmt->bind_param('iiddi', $product_id, $customer_id, $quantity, $total_price, $delivery_address);
            
            if ($stmt->execute()) {
                $order_id = $conn->insert_id;
                if ($order_id > 0) {
                    $success_message = "Order placed successfully! Order ID: $order_id";
                    
                    // Optional: Send notification to seller
                    try {
                        $notify_sql = "INSERT INTO messages (sender_id, receiver_id, Content, is_read, sent_at) 
                                       VALUES (?, ?, ?, 0, NOW())";
                        $notify_stmt = $conn->prepare($notify_sql);
                        if ($notify_stmt) {
                            $message_content = "New product order received for {$product['Name']} - Order #$order_id";
                            $notify_stmt->bind_param('iis', $customer_id, $product['seller_id'], $message_content);
                            $notify_stmt->execute();
                            $notify_stmt->close();
                        }
                    } catch (Exception $e) {
                        // Silent fail for notifications
                    }
                    
                    // Clear form data
                    $_POST = [];
                } else {
                    $errors[] = 'Failed to place order. Please try again.';
                }
            } else {
                $errors[] = 'Failed to place order. Please try again.';
            }
            $stmt->close();
        } else {
            $errors[] = 'Database error occurred. Please try again.';
        }
    }
}

include 'includes/header.php';
include 'includes/navigation.php';
?>

<div class="container" style="margin-top: 40px; margin-bottom: 40px;">
    <!-- Breadcrumb -->
    <nav style="margin-bottom: 20px;">
        <a href="index.php" style="color: #666; text-decoration: none;">Home</a> › 
        <a href="products.php" style="color: #666; text-decoration: none;">Products</a> › 
        <a href="product_details.php?id=<?= $product_id ?>" style="color: #666; text-decoration: none;"><?= htmlspecialchars($product['Name']) ?></a> › 
        <span style="color: #28a745; font-weight: bold;">Place Order</span>
    </nav>

    <div style="display: grid; grid-template-columns: 1fr 400px; gap: 40px;">
        <!-- Left Column - Product Info -->
        <div>
            <h1 style="color: #28a745; margin-bottom: 30px;">Place Order</h1>
            
            <?php if ($success_message): ?>
                <div style="background: #d4edda; color: #155724; padding: 20px; border-radius: 8px; margin-bottom: 30px; border: 1px solid #c3e6cb;">
                    <h3 style="margin: 0 0 10px 0;">✅ Order Placed Successfully!</h3>
                    <p style="margin: 0;"><?= htmlspecialchars($success_message) ?></p>
                    <div style="margin-top: 15px;">
                        <a href="farmer/product_orders.php" class="btn" style="background: #28a745; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; margin-right: 10px;">View My Orders</a>
                        <a href="products.php" class="btn" style="background: #6c757d; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px;">Continue Shopping</a>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($errors)): ?>
                <div style="background: #f8d7da; color: #721c24; padding: 20px; border-radius: 8px; margin-bottom: 30px; border: 1px solid #f5c6cb;">
                    <h3 style="margin: 0 0 10px 0;">❌ Please fix the following errors:</h3>
                    <ul style="margin: 0; padding-left: 20px;">
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Product Summary Card -->
            <div style="background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); margin-bottom: 30px;">
                <div style="display: flex; gap: 20px; align-items: start;">
                    <div style="flex-shrink: 0;">
                        <?php if (!empty($product['image_url'])): ?>
                            <img src="<?= htmlspecialchars($product['image_url']) ?>" 
                                 alt="<?= htmlspecialchars($product['Name']) ?>"
                                 style="width: 120px; height: 120px; object-fit: cover; border-radius: 8px;">
                        <?php else: ?>
                            <div style="width: 120px; height: 120px; background: linear-gradient(135deg, #e8f5e8 0%, #a8e6a8 100%); display: flex; align-items: center; justify-content: center; border-radius: 8px; color: #666; font-size: 36px;">
                                📦
                            </div>
                        <?php endif; ?>
                    </div>
                    <div style="flex: 1;">
                        <h2 style="margin: 0 0 15px 0; color: #28a745;"><?= htmlspecialchars($product['Name']) ?></h2>
                        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; margin-bottom: 15px;">
                            <div>
                                <strong>Category:</strong> <?= htmlspecialchars($product['Category_name'] ?? 'N/A') ?>
                            </div>
                            <div>
                                <strong>Type:</strong> <?= htmlspecialchars($product['Subcategory_name'] ?? 'N/A') ?>
                            </div>
                            <div>
                                <strong>Price:</strong> <span style="color: #28a745; font-weight: bold;">₹<?= number_format($product['Price'], 2) ?>/<?= strtoupper($product['Unit']) ?></span>
                            </div>
                            <div>
                                <strong>Available:</strong> <span style="color: #28a745;"><?= number_format($product['Quantity'], 1) ?> <?= strtoupper($product['Unit']) ?></span>
                            </div>
                        </div>
                        <div>
                            <strong>Seller:</strong> <?= htmlspecialchars($product['seller_name']) ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Order Form -->
            <div style="background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
                <h3 style="color: #28a745; margin-bottom: 25px;">Order Details</h3>
                
                <form method="POST" id="orderForm" action="">
                    <input type="hidden" name="product_id" value="<?= $product_id ?>">
                    <input type="hidden" name="place_order" value="1">
                    
                    <!-- Quantity -->
                    <div style="margin-bottom: 25px;">
                        <label for="quantity" style="display: block; font-weight: bold; margin-bottom: 8px; color: #333;">
                            Quantity (<?= strtoupper($product['Unit']) ?>) *
                        </label>
                        <input type="number" 
                               name="quantity" 
                               id="quantity" 
                               step="0.01" 
                               min="0.01" 
                               max="<?= $product['Quantity'] ?>"
                               value="<?= htmlspecialchars($_POST['quantity'] ?? '1') ?>"
                               placeholder="Enter quantity"
                               style="width: 100%; padding: 12px; border: 2px solid #ddd; border-radius: 6px; font-size: 16px;"
                               required>
                        <small style="color: #666;">Maximum available: <?= number_format($product['Quantity'], 1) ?> <?= strtoupper($product['Unit']) ?></small>
                    </div>

                    <!-- Delivery Address -->
                    <div style="margin-bottom: 25px;">
                        <label for="delivery_address" style="display: block; font-weight: bold; margin-bottom: 8px; color: #333;">
                            Delivery Address *
                        </label>
                        <select name="delivery_address" id="delivery_address" required
                                style="width: 100%; padding: 12px; border: 2px solid #ddd; border-radius: 6px; font-size: 16px;">
                            <option value="">Select delivery address</option>
                            <?php 
                            // Reset result pointer to beginning
                            $address_result->data_seek(0);
                            while ($address = $address_result->fetch_assoc()): ?>
                                <option value="<?= $address['address_id'] ?>" <?= (isset($_POST['delivery_address']) && $_POST['delivery_address'] == $address['address_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($address['address']) ?>, <?= htmlspecialchars($address['city']) ?>, <?= htmlspecialchars($address['state']) ?> - <?= htmlspecialchars($address['Pin_code']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                        <small style="color: #666; margin-top: 5px; display: block;">
                            <a href="account.php" style="color: #28a745; text-decoration: none;">+ Add new address</a>
                        </small>
                    </div>

                    <!-- Special Notes -->
                    <div style="margin-bottom: 25px;">
                        <label for="notes" style="display: block; font-weight: bold; margin-bottom: 8px; color: #333;">
                            Special Instructions (Optional)
                        </label>
                        <textarea name="notes" 
                                  id="notes" 
                                  rows="3"
                                  placeholder="Any special instructions for the seller..."
                                  style="width: 100%; padding: 12px; border: 2px solid #ddd; border-radius: 6px; font-size: 16px; resize: vertical;"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                    </div>

                    <!-- Total Calculator -->
                    <div id="order-total" style="background: #f8fff8; padding: 20px; border-radius: 8px; border: 1px solid #d1e7d1; margin-bottom: 25px; display: none;">
                        <h4 style="margin: 0 0 15px 0; color: #28a745;">📊 Order Summary</h4>
                        <div id="total-details"></div>
                    </div>

                    <!-- Submit Button -->
                    <div style="text-align: center;">
                        <button type="submit" name="submit_order"
                                style="background: linear-gradient(135deg, #28a745 0%, #34ce57 100%); color: white; padding: 15px 40px; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3); transition: all 0.3s ease;"
                                onmouseover="this.style.transform = 'translateY(-2px)'; this.style.boxShadow = '0 6px 20px rgba(40, 167, 69, 0.4)'"
                                onmouseout="this.style.transform = 'translateY(0)'; this.style.boxShadow = '0 4px 15px rgba(40, 167, 69, 0.3)'">
                            🛒 Place Order
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Right Column - Quick Actions -->
        <div>
            <!-- Quick Actions -->
            <div style="background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); margin-bottom: 20px;">
                <h3 style="color: #28a745; margin-bottom: 20px;">Quick Actions</h3>
                <div style="display: flex; flex-direction: column; gap: 10px;">
                    <a href="product_details.php?id=<?= $product_id ?>" 
                       style="background: #6c757d; color: white; padding: 10px 15px; text-decoration: none; border-radius: 4px; text-align: center;">
                        ️ View Product Details
                    </a>
                    <a href="products.php" 
                       style="background: #17a2b8; color: white; padding: 10px 15px; text-decoration: none; border-radius: 4px; text-align: center;">
                        Continue Shopping
                    </a>
                    <a href="farmer/product_orders.php" 
                       style="background: #ffc107; color: #212529; padding: 10px 15px; text-decoration: none; border-radius: 4px; text-align: center;">
                         My Orders
                    </a>
                </div>
            </div>

            <!-- Seller Info -->
            <div style="background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
                <h3 style="color: #28a745; margin-bottom: 20px;">Seller Information</h3>
                <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px;">
                    <div style="width: 50px; height: 50px; background: #28a745; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 18px;">
                        <?= strtoupper(substr($product['seller_name'], 0, 1)) ?>
                    </div>
                    <div>
                        <h4 style="margin: 0; color: #28a745;"><?= htmlspecialchars($product['seller_name']) ?></h4>
                        <small style="color: #666;">Product Seller</small>
                    </div>
                </div>
                <div style="font-size: 14px;">
                    <p style="margin: 8px 0;"><strong>Phone:</strong> <a href="tel:<?= htmlspecialchars($product['seller_phone']) ?>" style="color: #28a745;"><?= htmlspecialchars($product['seller_phone']) ?></a></p>
                    
                    <?php
                    // WhatsApp contact
                    $phone_digits = preg_replace('/\D/', '', $product['seller_phone']);
                    if (strlen($phone_digits) == 10) {
                        $phone_digits = '91' . $phone_digits;
                    }
                    $whatsapp_message = "Hi! I'm interested in ordering " . $product['Name'] . " from AgriRent. Could you provide more details?";
                    $whatsapp_url = "https://wa.me/" . $phone_digits . "?text=" . rawurlencode($whatsapp_message);
                    ?>
                    
                    <a href="<?= htmlspecialchars($whatsapp_url) ?>" 
                       target="_blank"
                       style="display: flex; align-items: center; justify-content: center; gap: 8px; background: #25d366; color: white; padding: 10px 15px; text-decoration: none; border-radius: 20px; margin-top: 15px; font-size: 13px;">
                        <span>📱</span> Contact via WhatsApp
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Calculate total when quantity changes
function updateOrderTotal() {
    const quantity = parseFloat(document.getElementById('quantity').value) || 0;
    const price = <?= $product['Price'] ?>;
    const unit = '<?= strtoupper($product['Unit']) ?>';
    const calculator = document.getElementById('order-total');
    const details = document.getElementById('total-details');
    
    if (quantity > 0) {
        const total = quantity * price;
        
        details.innerHTML = `
            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                <span>Quantity:</span>
                <strong>${quantity} ${unit}</strong>
            </div>
            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                <span>Price per ${unit}:</span>
                <strong>₹${price.toFixed(2)}</strong>
            </div>
            <div style="display: flex; justify-content: space-between; padding-top: 8px; border-top: 1px solid #28a745;">
                <span>Total Amount:</span>
                <strong style="color: #28a745; font-size: 1.2em;">₹${total.toFixed(2)}</strong>
            </div>
        `;
        calculator.style.display = 'block';
    } else {
        calculator.style.display = 'none';
    }
}

// Set up event listeners
document.getElementById('quantity').addEventListener('input', updateOrderTotal);
document.getElementById('quantity').addEventListener('change', updateOrderTotal);

// Form submission handling
document.getElementById('orderForm').addEventListener('submit', function(e) {
    const quantity = parseFloat(document.getElementById('quantity').value) || 0;
    const maxQuantity = <?= $product['Quantity'] ?>;
    const deliveryAddress = document.getElementById('delivery_address').value;
    
    if (quantity <= 0) {
        alert('Please enter a valid quantity');
        e.preventDefault();
        return false;
    }
    
    if (quantity > maxQuantity) {
        alert(`Maximum available quantity is ${maxQuantity}`);
        e.preventDefault();
        return false;
    }
    
    if (!deliveryAddress) {
        alert('Please select a delivery address');
        e.preventDefault();
        return false;
    }
    
    // Show loading state
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '⏳ Placing Order...';
    submitBtn.disabled = true;
    
    // Re-enable after 10 seconds if still on page
    setTimeout(() => {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }, 10000);
    
    return true;
});

// Initialize calculator and auto-hide messages
document.addEventListener('DOMContentLoaded', function() {
    updateOrderTotal();
    
    // Auto-hide success/error messages after 5 seconds
    const messages = document.querySelectorAll('.message, .error');
    if (messages.length > 0) {
        setTimeout(() => {
            messages.forEach(msg => {
                if (msg.style.background.includes('#d4edda') || msg.style.background.includes('#f8d7da')) {
                    msg.style.transition = 'opacity 0.5s ease';
                    msg.style.opacity = '0';
                    setTimeout(() => msg.remove(), 500);
                }
            });
        }, 5000);
    }
});
</script>

<style>
.container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 20px;
}

@media (max-width: 768px) {
    .container > div {
        grid-template-columns: 1fr !important;
        gap: 20px !important;
    }
}

/* Form styling */
input[type="number"]:focus,
select:focus,
textarea:focus {
    border-color: #28a745;
    outline: none;
    box-shadow: 0 0 5px rgba(40, 167, 69, 0.3);
}

button:hover {
    transform: translateY(-1px);
}

a:hover {
    text-decoration: none;
    opacity: 0.9;
}
</style>

<?php 
$address_stmt->close();
include 'includes/footer.php'; 
?>
