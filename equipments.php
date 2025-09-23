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
$where_clause = "WHERE e.Approval_status = 'CON'";
$params = [];
$param_types = "";

if (!empty($search)) {
    $where_clause .= " AND (e.Title LIKE ? OR e.Brand LIKE ? OR e.Model LIKE ?)";
    $search_term = "%$search%";
    $params = array_merge($params, [$search_term, $search_term, $search_term]);
    $param_types .= "sss";
}

if ($category_filter > 0) {
    $where_clause .= " AND es.Category_id = ?";
    $params[] = $category_filter;
    $param_types .= "i";
}

// Get equipment with pagination
$equipment_query = "SELECT e.*, u.Name as owner_name, i.image_url,
                           es.Subcategory_name, ec.Name as category_name
                    FROM equipment e 
                    JOIN users u ON e.Owner_id = u.user_id 
                    LEFT JOIN images i ON (i.image_type = 'E' AND i.ID = e.Equipment_id)
                    LEFT JOIN equipment_subcategories es ON e.Subcategories_id = es.Subcategory_id
                    LEFT JOIN equipment_categories ec ON es.Category_id = ec.category_id
                    $where_clause 
                    ORDER BY e.listed_date DESC 
                    LIMIT ? OFFSET ?";

$params[] = $limit;
$params[] = $offset;
$param_types .= "ii";

$stmt = $conn->prepare($equipment_query);
if (!empty($param_types)) {
    $stmt->bind_param($param_types, ...$params);
}
$stmt->execute();
$equipment_result = $stmt->get_result();
$equipment_list = [];
while ($equip = $equipment_result->fetch_assoc()) {
    $equipment_list[] = $equip;
}
$stmt->close();

// Get total count for pagination
$count_params = array_slice($params, 0, -2); // Remove LIMIT and OFFSET
$count_types = substr($param_types, 0, -2);
$count_query = "SELECT COUNT(*) as total FROM equipment e 
                LEFT JOIN equipment_subcategories es ON e.Subcategories_id = es.Subcategory_id
                LEFT JOIN equipment_categories ec ON es.Category_id = ec.category_id
                $where_clause";
$count_stmt = $conn->prepare($count_query);
if (!empty($count_types)) {
    $count_stmt->bind_param($count_types, ...$count_params);
}
$count_stmt->execute();
$total_equipment = $count_stmt->get_result()->fetch_assoc()['total'];
$count_stmt->close();

$total_pages = ceil($total_equipment / $limit);

// Get categories for filter
$categories = [];
$cat_result = $conn->query("SELECT category_id, Name FROM equipment_categories ORDER BY Name");
while ($cat = $cat_result->fetch_assoc()) {
    $categories[] = $cat;
}

include 'includes/header.php';
include 'includes/navigation.php';
?>

<div class="container" style="margin-top: 40px; margin-bottom: 40px;">
    <h1>Equipment Rental</h1>
    <p style="color: #666; margin-bottom: 30px;">Find the perfect agricultural equipment for your farming needs</p>

    <!-- Search and Filter -->
    <div style="background: white; padding: 20px; border-radius: 8px; margin-bottom: 30px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <form method="GET" style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
            <input type="text" name="search" placeholder="Search equipment, brand, or model..." 
                   value="<?= htmlspecialchars($search) ?>" 
                   style="flex: 1; min-width: 250px; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
            
            <select name="category" style="padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                <option value="0">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['category_id'] ?>" <?= $category_filter == $cat['category_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($cat['Name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            
            <button type="submit" style="padding: 10px 20px; background: #234a23; color: white; border: none; border-radius: 4px; cursor: pointer;">
                🔍 Search
            </button>
            
            <?php if (!empty($search) || $category_filter > 0): ?>
                <a href="equipments.php" style="padding: 10px 20px; background: #6c757d; color: white; text-decoration: none; border-radius: 4px;">
                    Clear
                </a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Results Info -->
    <div style="margin-bottom: 20px;">
        <p style="color: #666;">
            Showing <?= count($equipment_list) ?> of <?= $total_equipment ?> equipment
            <?php if (!empty($search)): ?>
                for "<?= htmlspecialchars($search) ?>"
            <?php endif; ?>
        </p>
    </div>

    <!-- Equipment Grid -->
    <?php if (count($equipment_list) > 0): ?>
        <div class="equipment-row" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px;">
            <?php foreach ($equipment_list as $equipment): ?>
                <div class="equipment-card" style="background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">
                    <div class="equipment-image">
                        <?php if (!empty($equipment['image_url'])): ?>
                            <img src="<?= htmlspecialchars($equipment['image_url']) ?>" 
                                 alt="<?= htmlspecialchars($equipment['Title']) ?>"
                                 style="width: 100%; height: 200px; object-fit: cover;">
                        <?php else: ?>
                            <div style="width: 100%; height: 200px; background: #f5f5f5; display: flex; align-items: center; justify-content: center; color: #666;">
                                📷 Equipment Image
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="equipment-info" style="padding: 20px;">
                        <h3 style="margin: 0 0 10px 0; color: #234a23;"><?= htmlspecialchars($equipment['Title']) ?></h3>
                        <p><strong>Brand:</strong> <?= htmlspecialchars($equipment['Brand']) ?></p>
                        <p><strong>Model:</strong> <?= htmlspecialchars($equipment['Model']) ?></p>
                        <?php if ($equipment['Year']): ?>
                            <p><strong>Year:</strong> <?= htmlspecialchars($equipment['Year']) ?></p>
                        <?php endif; ?>
                        <p><strong>Category:</strong> <?= htmlspecialchars($equipment['Subcategory_name'] ?? 'N/A') ?></p>
                        <p><strong>Owner:</strong> <?= htmlspecialchars($equipment['owner_name']) ?></p>
                        
                        <div class="price-box" style="margin: 15px 0;">
                            <?php if ($equipment['Daily_rate'] > 0): ?>
                                <span class="price" style="font-size: 18px; font-weight: bold; color: #234a23;">
                                    ₹<?= number_format($equipment['Daily_rate'], 0) ?>/day
                                </span>
                            <?php endif; ?>
                            <?php if ($equipment['Hourly_rate'] > 0): ?>
                                <small style="display: block; color: #666;">
                                    ₹<?= number_format($equipment['Hourly_rate'], 0) ?>/hour
                                </small>
                            <?php endif; ?>
                        </div>
                        
                        <a class="rent-btn"
                               href="<?= isset($_SESSION['logged_in']) && $_SESSION['logged_in'] ? 'equipment_details.php?id=' . $equipment['Equipment_id'] : 'login.php' ?>">
                               Rent Now
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
                       style="padding: 10px 15px; <?= $i == $page ? 'background: #234a23; color: white;' : 'background: white; color: #234a23;' ?> border: 1px solid #234a23; text-decoration: none; border-radius: 4px;">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <div style="text-align: center; padding: 60px 20px; background: white; border-radius: 8px;">
            <h3 style="color: #666; margin-bottom: 15px;">No Equipment Found</h3>
            <p style="color: #666; margin-bottom: 25px;">
                <?php if (!empty($search) || $category_filter > 0): ?>
                    Try adjusting your search criteria or browse all equipment.
                <?php else: ?>
                    No equipment is currently available for rent.
                <?php endif; ?>
            </p>
            <?php if (!empty($search) || $category_filter > 0): ?>
                <a href="equipments.php" style="background: #234a23; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px;">
                    Browse All Equipment
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
