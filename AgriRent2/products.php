<?php 
session_start(); 
require_once 'auth/config.php';

// Pagination
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 12;
$offset = ($page - 1) * $limit;

// Search and filter
$search = trim($_GET['search'] ?? '');
$category_filter = intval($_GET['category'] ?? 0);

// Build WHERE clause
$where_clause = "WHERE p.Approval_status = 'CON'";
$params = [];
$param_types = "";

if (!empty($search)) {
    $where_clause .= " AND (p.Name LIKE ? OR p.Description LIKE ?)";
    $search_term = "%$search%";
    $params = array_merge($params, [$search_term, $search_term]);
    $param_types .= "ss";
}

if ($category_filter > 0) {
    $where_clause .= " AND ps.Category_id = ?";
    $params[] = $category_filter;
    $param_types .= "i";
}

// Get products with pagination
$products_query = "SELECT p.*, u.Name as seller_name, i.image_url,
                          ps.Subcategory_name, pc.Category_name
                   FROM product p 
                   JOIN users u ON p.seller_id = u.user_id 
                   LEFT JOIN images i ON (i.image_type = 'P' AND i.ID = p.product_id)
                   LEFT JOIN product_subcategories ps ON p.Subcategory_id = ps.Subcategory_id
                   LEFT JOIN product_categories pc ON ps.Category_id = pc.Category_id
                   $where_clause 
                   ORDER BY p.listed_date DESC 
                   LIMIT ? OFFSET ?";

$params[] = $limit;
$params[] = $offset;
$param_types .= "ii";

$stmt = $conn->prepare($products_query);
if (!empty($param_types)) {
    $stmt->bind_param($param_types, ...$params);
}
$stmt->execute();
$products_result = $stmt->get_result();
$products_list = [];
while ($product = $products_result->fetch_assoc()) {
    $products_list[] = $product;
}
$stmt->close();

// Get total count for pagination
$count_params = array_slice($params, 0, -2); // Remove LIMIT and OFFSET
$count_types = substr($param_types, 0, -2);

$count_query = "SELECT COUNT(*) as total FROM product p 
                LEFT JOIN product_subcategories ps ON p.Subcategory_id = ps.Subcategory_id
                LEFT JOIN product_categories pc ON ps.Category_id = pc.Category_id
                $where_clause";

$count_stmt = $conn->prepare($count_query);
if (!empty($count_types)) {
    $count_stmt->bind_param($count_types, ...$count_params);
}
$count_stmt->execute();
$total_products = $count_stmt->get_result()->fetch_assoc()['total'];
$count_stmt->close();

$total_pages = ceil($total_products / $limit);

// Get categories for filter
$categories = [];
$cat_result = $conn->query("SELECT Category_id, Category_name FROM product_categories ORDER BY Category_name");
while ($cat = $cat_result->fetch_assoc()) {
    $categories[] = $cat;
}

include 'includes/header.php';
include 'includes/navigation.php';
?>

<div class="container" style="margin-top: 40px; margin-bottom: 40px;">
    <h1>Agricultural Products</h1>
    <p style="color: #666; margin-bottom: 30px;">Find quality agricultural products and supplies for your farming needs</p>
    
    <!-- Search and Filter -->
    <div style="background: white; padding: 20px; border-radius: 8px; margin-bottom: 30px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <form method="GET" style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
            <input type="text" name="search" placeholder="Search products, name, or description..." 
                   value="<?= htmlspecialchars($search) ?>" 
                   style="flex: 1; min-width: 250px; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
            
            <select name="category" style="padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                <option value="0">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['Category_id'] ?>" <?= $category_filter == $cat['Category_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($cat['Category_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            
            <button type="submit" style="padding: 10px 20px; background: #234a23; color: white; border: none; border-radius: 4px; cursor: pointer;">
                🔍 Search
            </button>
            
            <?php if (!empty($search) || $category_filter > 0): ?>
                <a href="products.php" style="padding: 10px 20px; background: #6c757d; color: white; text-decoration: none; border-radius: 4px;">
                    Clear
                </a>
            <?php endif; ?>
        </form>
    </div>
    
    <!-- Results Info -->
    <div style="margin-bottom: 20px;">
        <p style="color: #666;">
            Showing <?= count($products_list) ?> of <?= $total_products ?> products
            <?php if (!empty($search)): ?>
                for "<?= htmlspecialchars($search) ?>"
            <?php endif; ?>
        </p>
    </div>
    
    <!-- Products Grid -->
    <?php if (count($products_list) > 0): ?>
        <div class="equipment-row" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px;">
            <?php foreach ($products_list as $product): ?>
                <div class="equipment-card" style="background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">
                    <div class="equipment-image">
                        <?php if (!empty($product['image_url'])): ?>
                            <img src="<?= htmlspecialchars($product['image_url']) ?>" 
                                 alt="<?= htmlspecialchars($product['Name']) ?>"
                                 style="width: 100%; height: 200px; object-fit: cover;">
                        <?php else: ?>
                            <div style="width: 100%; height: 200px; background: #f5f5f5; display: flex; align-items: center; justify-content: center; color: #666;">
                                📦 Product Image
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="equipment-info" style="padding: 20px;">
                        <h3 style="margin: 0 0 10px 0; color: #234a23;"><?= htmlspecialchars($product['Name']) ?></h3>
                        <p><strong>Category:</strong> <?= htmlspecialchars($product['Category_name'] ?? 'N/A') ?></p>
                        <p><strong>Type:</strong> <?= htmlspecialchars($product['Subcategory_name'] ?? 'N/A') ?></p>
                        <p><strong>Seller:</strong> <?= htmlspecialchars($product['seller_name']) ?></p>
                        <p><strong>Available:</strong> <?= number_format($product['Quantity'], 1) ?> <?= strtoupper($product['Unit']) ?></p>
                        
                        <?php if (!empty($product['Description'])): ?>
                            <p><strong>Description:</strong> <?= htmlspecialchars(substr($product['Description'], 0, 100)) ?><?= strlen($product['Description']) > 100 ? '...' : '' ?></p>
                        <?php endif; ?>
                        
                        <div class="price-box" style="margin: 15px 0;">
                            <span class="price" style="font-size: 18px; font-weight: bold; color: #234a23;">
                                ₹<?= number_format($product['Price'], 2) ?>/<?= strtoupper($product['Unit']) ?>
                            </span>
                        </div>
                        
                        <a class="rent-btn"
                               href="<?= isset($_SESSION['logged_in']) && $_SESSION['logged_in'] ? 'product_details.php?id=' . $product['product_id'] : 'login.php' ?>">
                               Buy Now
                            </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div style="display: flex; justify-content: center; margin-top: 40px; gap: 5px;">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&category=<?= $category_filter ?>" 
                       style="padding: 10px 15px; <?= $i == $page ? 'background: #28a745; color: white;' : 'background: white; color: #28a745;' ?> border: 1px solid #28a745; text-decoration: none; border-radius: 4px;">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
        
    <?php else: ?>
        <div style="text-align: center; padding: 60px 20px; background: white; border-radius: 8px;">
            <h3 style="color: #666; margin-bottom: 15px;">No Products Found</h3>
            <p style="color: #666; margin-bottom: 25px;">
                <?php if (!empty($search) || $category_filter > 0): ?>
                    Try adjusting your search criteria or browse all products.
                <?php else: ?>
                    No products are currently available for sale.
                <?php endif; ?>
            </p>
            <?php if (!empty($search) || $category_filter > 0): ?>
                <a href="products.php" style="background: #28a745; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px;">
                    Browse All Products
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
