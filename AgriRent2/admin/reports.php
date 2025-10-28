<?php
session_start();
require_once '../auth/config.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['user_type'] !== 'A') {
    header('Location: ../login.php');
    exit;
}

// Get filters
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
$month = isset($_GET['month']) ? intval($_GET['month']) : 0;
$date_range = isset($_GET['date_range']) ? $_GET['date_range'] : 'year';
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'overview';

// Helper function to safely execute queries
function safeQuery($conn, $query, $default = 0) {
    $result = $conn->query($query);
    if ($result === false) {
        error_log("SQL Error: " . $conn->error . " Query: " . $query);
        return $default;
    }
    $row = $result->fetch_assoc();
    return $row ? (isset($row['count']) ? intval($row['count']) : intval($row['revenue'] ?? 0)) : $default;
}

// Get current period stats with error handling
$current_users = safeQuery($conn, "SELECT COUNT(*) as count FROM users WHERE User_type != 'A'");
$current_equipment = safeQuery($conn, "SELECT COUNT(*) as count FROM equipment WHERE YEAR(listed_date) = $year" . ($month > 0 ? " AND MONTH(listed_date) = $month" : ""));
$current_products = safeQuery($conn, "SELECT COUNT(*) as count FROM product");
$current_bookings = safeQuery($conn, "SELECT COUNT(*) as count FROM equipment_bookings WHERE YEAR(start_date) = $year" . ($month > 0 ? " AND MONTH(start_date) = $month" : ""));
$current_orders = safeQuery($conn, "SELECT COUNT(*) as count FROM product_orders WHERE YEAR(order_date) = $year" . ($month > 0 ? " AND MONTH(order_date) = $month" : ""));

// Get previous period for comparison
$prev_year = $month > 0 ? $year : $year - 1;
$prev_month = $month > 0 ? ($month > 1 ? $month - 1 : 12) : 12;
if ($month > 0 && $month == 1) {
    $prev_year = $year - 1;
}

$prev_users = max(1, safeQuery($conn, "SELECT COUNT(*) as count FROM users WHERE User_type != 'A'", 1));
$prev_equipment = max(1, safeQuery($conn, "SELECT COUNT(*) as count FROM equipment WHERE YEAR(listed_date) = $prev_year" . ($month > 0 ? " AND MONTH(listed_date) = $prev_month" : ""), 1));
$prev_products = max(1, safeQuery($conn, "SELECT COUNT(*) as count FROM product", 1));
$prev_bookings = max(1, safeQuery($conn, "SELECT COUNT(*) as count FROM equipment_bookings WHERE YEAR(start_date) = $prev_year" . ($month > 0 ? " AND MONTH(start_date) = $prev_month" : ""), 1));
$prev_orders = max(1, safeQuery($conn, "SELECT COUNT(*) as count FROM product_orders WHERE YEAR(order_date) = $prev_year" . ($month > 0 ? " AND MONTH(order_date) = $prev_month" : ""), 1));

// Calculate percentage changes
$users_change = $prev_users > 0 ? (($current_users - $prev_users) / $prev_users) * 100 : 0;
$equipment_change = $prev_equipment > 0 ? (($current_equipment - $prev_equipment) / $prev_equipment) * 100 : 0;
$products_change = $prev_products > 0 ? (($current_products - $prev_products) / $prev_products) * 100 : 0;
$bookings_change = $prev_bookings > 0 ? (($current_bookings - $prev_bookings) / $prev_bookings) * 100 : 0;
$orders_change = $prev_orders > 0 ? (($current_orders - $prev_orders) / $prev_orders) * 100 : 0;

// Revenue calculations with error handling
function safeRevenueQuery($conn, $query, $default = 0) {
    $result = $conn->query($query);
    if ($result === false) {
        error_log("SQL Error: " . $conn->error . " Query: " . $query);
        return $default;
    }
    $row = $result->fetch_assoc();
    return $row ? floatval($row['revenue'] ?? 0) : $default;
}

$revenue_condition = "YEAR(order_date) = $year" . ($month > 0 ? " AND MONTH(order_date) = $month" : "");
$booking_revenue_condition = "YEAR(start_date) = $year" . ($month > 0 ? " AND MONTH(start_date) = $month" : "");

$current_product_revenue = safeRevenueQuery($conn, "SELECT COALESCE(SUM(total_price), 0) as revenue FROM product_orders WHERE Status = 'CON' AND $revenue_condition");
$current_booking_revenue = safeRevenueQuery($conn, "SELECT COALESCE(SUM(total_amount), 0) as revenue FROM equipment_bookings WHERE status = 'CON' AND $booking_revenue_condition");
$current_total_revenue = $current_product_revenue + $current_booking_revenue;

$prev_product_revenue = max(1, safeRevenueQuery($conn, "SELECT COALESCE(SUM(total_price), 0) as revenue FROM product_orders WHERE Status = 'CON' AND YEAR(order_date) = $prev_year" . ($month > 0 ? " AND MONTH(order_date) = $prev_month" : ""), 1));
$prev_booking_revenue = max(1, safeRevenueQuery($conn, "SELECT COALESCE(SUM(total_amount), 0) as revenue FROM equipment_bookings WHERE status = 'CON' AND YEAR(start_date) = $prev_year" . ($month > 0 ? " AND MONTH(start_date) = $prev_month" : ""), 1));
$prev_total_revenue = $prev_product_revenue + $prev_booking_revenue;
$revenue_change = $prev_total_revenue > 0 ? (($current_total_revenue - $prev_total_revenue) / $prev_total_revenue) * 100 : 0;

// Monthly data for charts with error handling
$monthly_data = [];
for ($i = 1; $i <= 12; $i++) {
    $monthly_data[] = [
        'month' => $i,
        'users' => safeQuery($conn, "SELECT COUNT(*) as count FROM users WHERE User_type != 'A' AND YEAR(created_at) = $year AND MONTH(created_at) = $i"),
        'equipment' => safeQuery($conn, "SELECT COUNT(*) as count FROM equipment WHERE YEAR(listed_date) = $year AND MONTH(listed_date) = $i"),
        'products' => safeQuery($conn, "SELECT COUNT(*) as count FROM product WHERE YEAR(created_at) = $year AND MONTH(created_at) = $i"),
        'orders' => safeQuery($conn, "SELECT COUNT(*) as count FROM product_orders WHERE YEAR(order_date) = $year AND MONTH(order_date) = $i"),
        'bookings' => safeQuery($conn, "SELECT COUNT(*) as count FROM equipment_bookings WHERE YEAR(start_date) = $year AND MONTH(start_date) = $i"),
        'product_revenue' => safeRevenueQuery($conn, "SELECT COALESCE(SUM(total_price), 0) as revenue FROM product_orders WHERE Status = 'CON' AND YEAR(order_date) = $year AND MONTH(order_date) = $i"),
        'booking_revenue' => safeRevenueQuery($conn, "SELECT COALESCE(SUM(total_amount), 0) as revenue FROM equipment_bookings WHERE status = 'CON' AND YEAR(start_date) = $year AND MONTH(start_date) = $i")
    ];
}

// Status data with error handling
function safeStatusQuery($conn, $query) {
    $result = $conn->query($query);
    if ($result === false) {
        error_log("SQL Error: " . $conn->error . " Query: " . $query);
        return [];
    }
    return $result->fetch_all(MYSQLI_ASSOC);
}

$equipment_status = safeStatusQuery($conn, "SELECT Approval_status, COUNT(*) as count FROM equipment GROUP BY Approval_status");
$product_status = safeStatusQuery($conn, "SELECT Approval_status, COUNT(*) as count FROM product GROUP BY Approval_status");
$booking_status = safeStatusQuery($conn, "SELECT status, COUNT(*) as count FROM equipment_bookings GROUP BY status");
$order_status = safeStatusQuery($conn, "SELECT Status, COUNT(*) as count FROM product_orders GROUP BY Status");

// Top performers with error handling
$top_owners_query = "
    SELECT u.Name, u.Email, u.Phone, COUNT(e.Equipment_id) as equipment_count,
           COALESCE(SUM(eb.total_amount), 0) as total_earnings,
           AVG(eb.total_amount) as avg_booking_value
    FROM users u
    LEFT JOIN equipment e ON u.user_id = e.Owner_id
    LEFT JOIN equipment_bookings eb ON e.Equipment_id = eb.equipment_id AND eb.status = 'CON'
    WHERE u.User_type = 'O'
    GROUP BY u.user_id
    HAVING equipment_count > 0
    ORDER BY total_earnings DESC
    LIMIT 15
";

$top_owners_result = $conn->query($top_owners_query);
$top_owners = [];
if ($top_owners_result) {
    $top_owners = $top_owners_result->fetch_all(MYSQLI_ASSOC);
}

$top_products_query = "
    SELECT p.Name as product_name, p.Price, COUNT(po.Order_id) as orders_count,
           COALESCE(SUM(po.total_price), 0) as total_revenue,
           AVG(po.total_price) as avg_order_value,
           u.Name as seller_name, u.Email as seller_email
    FROM product p
    LEFT JOIN product_orders po ON p.product_id = po.Product_id AND po.Status = 'CON'
    LEFT JOIN users u ON p.seller_id = u.user_id
    GROUP BY p.product_id
    HAVING orders_count > 0
    ORDER BY total_revenue DESC
    LIMIT 15
";

$top_products_result = $conn->query($top_products_query);
$top_products = [];
if ($top_products_result) {
    $top_products = $top_products_result->fetch_all(MYSQLI_ASSOC);
}

require 'header.php';
require 'admin_nav.php';
?>

<div class="main-content">
    <!-- Animated Header with Gradient -->
    <div class="dashboard-header">
        <div class="header-pattern"></div>
        <div class="header-content">
            <div class="header-info">
                <div class="header-title-container">
                    <div class="agri-icon">
                        <i class="fas fa-chart-line"></i>
                        <i class="fas fa-leaf"></i>
                    </div>
                    <div>
                        <h1 class="animated-title">AgriRent Analytics Dashboard</h1>
                        <p class="header-subtitle">
                            <i class="fas fa-calendar"></i> 
                            <?= $month > 0 ? date('F', mktime(0, 0, 0, $month, 1)) . ' ' . $year : 'Year ' . $year ?> Report
                            <span class="live-indicator">
                                <span class="pulse-dot"></span>
                                Live Data
                            </span>
                        </p>
                    </div>
                </div>
            </div>
            <div class="header-controls">
                <div class="filters-container">
                    <div class="filter-group">
                        <label><i class="fas fa-calendar-alt"></i> Year</label>
                        <select id="yearFilter" onchange="applyFilters()" class="custom-select">
                            <?php for($y = date('Y'); $y >= 2020; $y--): ?>
                                <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label><i class="fas fa-calendar-day"></i> Month</label>
                        <select id="monthFilter" onchange="applyFilters()" class="custom-select">
                            <option value="0" <?= $month == 0 ? 'selected' : '' ?>>All Months</option>
                            <?php 
                            $months = ['January', 'February', 'March', 'April', 'May', 'June', 
                                      'July', 'August', 'September', 'October', 'November', 'December'];
                            for($m = 1; $m <= 12; $m++): 
                            ?>
                                <option value="<?= $m ?>" <?= $m == $month ? 'selected' : '' ?>><?= $months[$m-1] ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label><i class="fas fa-chart-bar"></i> Type</label>
                        <select id="reportTypeFilter" onchange="applyFilters()" class="custom-select">
                            <option value="overview" <?= $report_type == 'overview' ? 'selected' : '' ?>>Overview</option>
                            <option value="detailed" <?= $report_type == 'detailed' ? 'selected' : '' ?>>Detailed</option>
                            <option value="financial" <?= $report_type == 'financial' ? 'selected' : '' ?>>Financial</option>
                        </select>
                    </div>
                </div>
                <div class="export-actions">
                    <button onclick="exportToExcel()" class="action-btn excel-btn">
                        <i class="fas fa-file-excel"></i>
                        <span>Excel</span>
                        <div class="btn-shine"></div>
                    </button>
                    <button onclick="exportToPDF()" class="action-btn pdf-btn">
                        <i class="fas fa-file-pdf"></i>
                        <span>PDF</span>
                        <div class="btn-shine"></div>
                    </button>
                    <button onclick="printReport()" class="action-btn print-btn">
                        <i class="fas fa-print"></i>
                        <span>Print</span>
                        <div class="btn-shine"></div>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Enhanced KPI Cards with Agricultural Theme -->
    <div class="kpi-dashboard">
        <div class="kpi-card users-card" onclick="drillDown('users')">
            <div class="kpi-background">
                <div class="agri-pattern users-pattern"></div>
            </div>
            <div class="kpi-content">
                <div class="kpi-header">
                    <div class="kpi-icon users-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="kpi-trend <?= $users_change >= 0 ? 'trend-up' : 'trend-down' ?>">
                        <i class="fas fa-arrow-<?= $users_change >= 0 ? 'up' : 'down' ?>"></i>
                        <span><?= number_format(abs($users_change), 1) ?>%</span>
                    </div>
                </div>
                <div class="kpi-body">
                    <div class="kpi-number"><?= number_format($current_users) ?></div>
                    <div class="kpi-label">Active Farmers & Owners</div>
                    <div class="kpi-detail">
                        <i class="fas fa-seedling"></i>
                        Growing community: +<?= max(0, $current_users - $prev_users) ?> new members
                    </div>
                </div>
                <div class="kpi-progress">
                    <div class="progress-track">
                        <div class="progress-fill users-progress" style="width: <?= min(100, ($current_users / max(1, $current_users + $prev_users)) * 100) ?>%"></div>
                    </div>
                    <div class="progress-label">Community Growth</div>
                </div>
            </div>
        </div>

        <div class="kpi-card equipment-card" onclick="drillDown('equipment')">
            <div class="kpi-background">
                <div class="agri-pattern equipment-pattern"></div>
            </div>
            <div class="kpi-content">
                <div class="kpi-header">
                    <div class="kpi-icon equipment-icon">
                        <i class="fas fa-tractor"></i>
                    </div>
                    <div class="kpi-trend <?= $equipment_change >= 0 ? 'trend-up' : 'trend-down' ?>">
                        <i class="fas fa-arrow-<?= $equipment_change >= 0 ? 'up' : 'down' ?>"></i>
                        <span><?= number_format(abs($equipment_change), 1) ?>%</span>
                    </div>
                </div>
                <div class="kpi-body">
                    <div class="kpi-number"><?= number_format($current_equipment) ?></div>
                    <div class="kpi-label">Agricultural Equipment</div>
                    <div class="kpi-detail">
                        <i class="fas fa-tools"></i>
                        Machinery fleet: +<?= max(0, $current_equipment - $prev_equipment) ?> new listings
                    </div>
                </div>
                <div class="kpi-progress">
                    <div class="progress-track">
                        <div class="progress-fill equipment-progress" style="width: <?= min(100, ($current_equipment / max(1, $current_equipment + $prev_equipment)) * 100) ?>%"></div>
                    </div>
                    <div class="progress-label">Equipment Expansion</div>
                </div>
            </div>
        </div>

        <div class="kpi-card products-card" onclick="drillDown('products')">
            <div class="kpi-background">
                <div class="agri-pattern products-pattern"></div>
            </div>
            <div class="kpi-content">
                <div class="kpi-header">
                    <div class="kpi-icon products-icon">
                        <i class="fas fa-seedling"></i>
                    </div>
                    <div class="kpi-trend <?= $products_change >= 0 ? 'trend-up' : 'trend-down' ?>">
                        <i class="fas fa-arrow-<?= $products_change >= 0 ? 'up' : 'down' ?>"></i>
                        <span><?= number_format(abs($products_change), 1) ?>%</span>
                    </div>
                </div>
                <div class="kpi-body">
                    <div class="kpi-number"><?= number_format($current_products) ?></div>
                    <div class="kpi-label">Farm Products</div>
                    <div class="kpi-detail">
                        <i class="fas fa-apple-alt"></i>
                        Fresh produce & supplies marketplace
                    </div>
                </div>
                <div class="kpi-progress">
                    <div class="progress-track">
                        <div class="progress-fill products-progress" style="width: <?= min(100, ($current_products / max(1, $current_products + $prev_products)) * 100) ?>%"></div>
                    </div>
                    <div class="progress-label">Product Variety</div>
                </div>
            </div>
        </div>

        <div class="kpi-card revenue-card" onclick="drillDown('revenue')">
            <div class="kpi-background">
                <div class="agri-pattern revenue-pattern"></div>
            </div>
            <div class="kpi-content">
                <div class="kpi-header">
                    <div class="kpi-icon revenue-icon">
                        <i class="fas fa-rupee-sign"></i>
                    </div>
                    <div class="kpi-trend <?= $revenue_change >= 0 ? 'trend-up' : 'trend-down' ?>">
                        <i class="fas fa-arrow-<?= $revenue_change >= 0 ? 'up' : 'down' ?>"></i>
                        <span><?= number_format(abs($revenue_change), 1) ?>%</span>
                    </div>
                </div>
                <div class="kpi-body">
                    <div class="kpi-number">₹<?= number_format($current_total_revenue) ?></div>
                    <div class="kpi-label">Total Revenue</div>
                    <div class="kpi-detail">
                        <i class="fas fa-chart-line"></i>
                        Products: ₹<?= number_format($current_product_revenue) ?> | Equipment: ₹<?= number_format($current_booking_revenue) ?>
                    </div>
                </div>
                <div class="kpi-progress">
                    <div class="progress-track">
                        <div class="progress-fill revenue-progress" style="width: <?= min(100, ($current_total_revenue / max(1, $current_total_revenue + $prev_total_revenue)) * 100) ?>%"></div>
                    </div>
                    <div class="progress-label">Revenue Growth</div>
                </div>
            </div>
        </div>

        <div class="kpi-card bookings-card" onclick="drillDown('bookings')">
            <div class="kpi-background">
                <div class="agri-pattern bookings-pattern"></div>
            </div>
            <div class="kpi-content">
                <div class="kpi-header">
                    <div class="kpi-icon bookings-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div class="kpi-trend <?= $bookings_change >= 0 ? 'trend-up' : 'trend-down' ?>">
                        <i class="fas fa-arrow-<?= $bookings_change >= 0 ? 'up' : 'down' ?>"></i>
                        <span><?= number_format(abs($bookings_change), 1) ?>%</span>
                    </div>
                </div>
                <div class="kpi-body">
                    <div class="kpi-number"><?= number_format($current_bookings) ?></div>
                    <div class="kpi-label">Equipment Rentals</div>
                    <div class="kpi-detail">
                        <i class="fas fa-handshake"></i>
                        Active rentals: +<?= max(0, $current_bookings - $prev_bookings) ?> new bookings
                    </div>
                </div>
                <div class="kpi-progress">
                    <div class="progress-track">
                        <div class="progress-fill bookings-progress" style="width: <?= min(100, ($current_bookings / max(1, $current_bookings + $prev_bookings)) * 100) ?>%"></div>
                    </div>
                    <div class="progress-label">Rental Activity</div>
                </div>
            </div>
        </div>

        <div class="kpi-card orders-card" onclick="drillDown('orders')">
            <div class="kpi-background">
                <div class="agri-pattern orders-pattern"></div>
            </div>
            <div class="kpi-content">
                <div class="kpi-header">
                    <div class="kpi-icon orders-icon">
                        <i class="fas fa-shopping-cart"></i>
                    </div>
                    <div class="kpi-trend <?= $orders_change >= 0 ? 'trend-up' : 'trend-down' ?>">
                        <i class="fas fa-arrow-<?= $orders_change >= 0 ? 'up' : 'down' ?>"></i>
                        <span><?= number_format(abs($orders_change), 1) ?>%</span>
                    </div>
                </div>
                <div class="kpi-body">
                    <div class="kpi-number"><?= number_format($current_orders) ?></div>
                    <div class="kpi-label">Product Orders</div>
                    <div class="kpi-detail">
                        <i class="fas fa-truck"></i>
                        Fresh orders: +<?= max(0, $current_orders - $prev_orders) ?> new purchases
                    </div>
                </div>
                <div class="kpi-progress">
                    <div class="progress-track">
                        <div class="progress-fill orders-progress" style="width: <?= min(100, ($current_orders / max(1, $current_orders + $prev_orders)) * 100) ?>%"></div>
                    </div>
                    <div class="progress-label">Order Volume</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Enhanced Charts Section -->
    <div class="charts-dashboard">
        <div class="main-chart-container">
            <div class="chart-header">
                <div class="chart-title">
                    <i class="fas fa-chart-area chart-icon"></i>
                    <h3>Agricultural Business Insights (<?= $year ?>)</h3>
                </div>
                <div class="chart-controls">
                    <button class="chart-tab active" data-chart="revenue" onclick="switchChart(this, 'revenue')">
                        <i class="fas fa-coins"></i> Revenue
                    </button>
                    <button class="chart-tab" data-chart="growth" onclick="switchChart(this, 'growth')">
                        <i class="fas fa-chart-line"></i> Growth
                    </button>
                    <button class="chart-tab" data-chart="comparison" onclick="switchChart(this, 'comparison')">
                        <i class="fas fa-balance-scale"></i> Compare
                    </button>
                </div>
            </div>
            <div class="chart-canvas">
                <canvas id="mainChart"></canvas>
            </div>
        </div>

        <div class="status-charts-grid">
            <div class="status-chart-card">
                <div class="chart-mini-header">
                    <i class="fas fa-tractor"></i>
                    <h4>Equipment Status</h4>
                </div>
                <div class="chart-mini-body">
                    <canvas id="equipmentStatusChart"></canvas>
                </div>
            </div>

            <div class="status-chart-card">
                <div class="chart-mini-header">
                    <i class="fas fa-seedling"></i>
                    <h4>Product Status</h4>
                </div>
                <div class="chart-mini-body">
                    <canvas id="productStatusChart"></canvas>
                </div>
            </div>

            <div class="status-chart-card">
                <div class="chart-mini-header">
                    <i class="fas fa-calendar-check"></i>
                    <h4>Rental Status</h4>
                </div>
                <div class="chart-mini-body">
                    <canvas id="bookingStatusChart"></canvas>
                </div>
            </div>

            <div class="status-chart-card">
                <div class="chart-mini-header">
                    <i class="fas fa-shopping-cart"></i>
                    <h4>Order Status</h4>
                </div>
                <div class="chart-mini-body">
                    <canvas id="orderStatusChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Enhanced Data Tables -->
    <div class="tables-dashboard">
        <div class="data-table-card">
            <div class="table-header">
                <div class="table-title">
                    <i class="fas fa-trophy"></i>
                    <h3>Top Equipment Owners</h3>
                    <span class="table-badge"><?= count($top_owners) ?> Active</span>
                </div>
                <div class="table-actions">
                    <div class="search-container">
                        <i class="fas fa-search"></i>
                        <input type="text" id="ownersSearch" placeholder="Search owners..." class="table-search">
                    </div>
                    <button onclick="exportTable('ownersTable', 'top_equipment_owners')" class="table-btn export-btn">
                        <i class="fas fa-download"></i>
                        Export
                    </button>
                </div>
            </div>
            <div class="table-container">
                <table id="ownersTable" class="data-table">
                    <thead>
                        <tr>
                            <th><i class="fas fa-medal"></i> Rank</th>
                            <th><i class="fas fa-user"></i> Owner Details</th>
                            <th><i class="fas fa-tractor"></i> Equipment</th>
                            <th><i class="fas fa-rupee-sign"></i> Earnings</th>
                            <th><i class="fas fa-chart-bar"></i> Performance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($top_owners)): ?>
                            <?php 
                            $rank = 1;
                            $max_earnings = $top_owners[0]['total_earnings'] ?? 1;
                            foreach($top_owners as $owner): 
                                $performance = $max_earnings > 0 ? ($owner['total_earnings'] / $max_earnings) * 100 : 0;
                            ?>
                            <tr class="owner-row">
                                <td>
                                    <div class="rank-container">
                                        <div class="rank-badge rank-<?= $rank <= 3 ? $rank : 'default' ?>"><?= $rank ?></div>
                                        <?php if($rank <= 3): ?>
                                            <i class="fas fa-crown rank-crown"></i>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="owner-info">
                                        <div class="owner-avatar">
                                            <i class="fas fa-user-circle"></i>
                                        </div>
                                        <div class="owner-details">
                                            <div class="owner-name"><?= htmlspecialchars($owner['Name'] ?? 'N/A') ?></div>
                                            <div class="owner-contact">
                                                <i class="fas fa-envelope"></i> <?= htmlspecialchars($owner['Email'] ?? 'N/A') ?>
                                            </div>
                                            <div class="owner-contact">
                                                <i class="fas fa-phone"></i> <?= htmlspecialchars($owner['Phone'] ?? 'N/A') ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="equipment-stats">
                                        <div class="equipment-count"><?= $owner['equipment_count'] ?? 0 ?></div>
                                        <div class="equipment-label">Machines</div>
                                    </div>
                                </td>
                                <td>
                                    <div class="earnings-display">
                                        <div class="total-earnings">₹<?= number_format($owner['total_earnings'] ?? 0) ?></div>
                                        <div class="avg-earnings">Avg: ₹<?= number_format($owner['avg_booking_value'] ?? 0) ?></div>
                                    </div>
                                </td>
                                <td>
                                    <div class="performance-display">
                                        <div class="performance-circle" data-percentage="<?= $performance ?>">
                                            <svg width="60" height="60">
                                                <circle cx="30" cy="30" r="25" stroke="#e2e8f0" stroke-width="4" fill="none"/>
                                                <circle cx="30" cy="30" r="25" stroke="#22c55e" stroke-width="4" fill="none"
                                                        stroke-dasharray="157" stroke-dashoffset="<?= 157 - ($performance / 100) * 157 ?>"
                                                        stroke-linecap="round" transform="rotate(-90 30 30)"/>
                                            </svg>
                                            <span class="performance-text"><?= number_format($performance, 0) ?>%</span>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php 
                            $rank++;
                            endforeach; 
                            ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="no-data-message">
                                    <div class="no-data-icon">
                                        <i class="fas fa-seedling"></i>
                                    </div>
                                    <div class="no-data-text">No equipment owners data available</div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="data-table-card">
            <div class="table-header">
                <div class="table-title">
                    <i class="fas fa-star"></i>
                    <h3>Top Products by Revenue</h3>
                    <span class="table-badge"><?= count($top_products) ?> Products</span>
                </div>
                <div class="table-actions">
                    <div class="search-container">
                        <i class="fas fa-search"></i>
                        <input type="text" id="productsSearch" placeholder="Search products..." class="table-search">
                    </div>
                    <button onclick="exportTable('productsTable', 'top_products')" class="table-btn export-btn">
                        <i class="fas fa-download"></i>
                        Export
                    </button>
                </div>
            </div>
            <div class="table-container">
                <table id="productsTable" class="data-table">
                    <thead>
                        <tr>
                            <th><i class="fas fa-medal"></i> Rank</th>
                            <th><i class="fas fa-apple-alt"></i> Product</th>
                            <th><i class="fas fa-user-tie"></i> Seller</th>
                            <th><i class="fas fa-shopping-cart"></i> Orders</th>
                            <th><i class="fas fa-chart-line"></i> Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($top_products)): ?>
                            <?php 
                            $rank = 1;
                            foreach($top_products as $product): 
                            ?>
                            <tr class="product-row">
                                <td>
                                    <div class="rank-container">
                                        <div class="rank-badge rank-<?= $rank <= 3 ? $rank : 'default' ?>"><?= $rank ?></div>
                                        <?php if($rank <= 3): ?>
                                            <i class="fas fa-star rank-star"></i>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="product-info">
                                        <div class="product-icon">
                                            <i class="fas fa-seedling"></i>
                                        </div>
                                        <div class="product-details">
                                            <div class="product-name"><?= htmlspecialchars($product['product_name'] ?? 'N/A') ?></div>
                                            <div class="product-price">
                                                <i class="fas fa-tag"></i> ₹<?= number_format($product['Price'] ?? 0) ?> per unit
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="seller-info">
                                        <div class="seller-avatar">
                                            <i class="fas fa-store"></i>
                                        </div>
                                        <div class="seller-details">
                                            <div class="seller-name"><?= htmlspecialchars($product['seller_name'] ?? 'N/A') ?></div>
                                            <div class="seller-email">
                                                <i class="fas fa-envelope"></i> <?= htmlspecialchars($product['seller_email'] ?? 'N/A') ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="orders-display">
                                        <div class="orders-count"><?= $product['orders_count'] ?? 0 ?></div>
                                        <div class="orders-label">Orders</div>
                                    </div>
                                </td>
                                <td>
                                    <div class="revenue-display">
                                        <div class="total-revenue">₹<?= number_format($product['total_revenue'] ?? 0) ?></div>
                                        <div class="avg-order">Avg: ₹<?= number_format($product['avg_order_value'] ?? 0) ?></div>
                                    </div>
                                </td>
                            </tr>
                            <?php 
                            $rank++;
                            endforeach; 
                            ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="no-data-message">
                                    <div class="no-data-icon">
                                        <i class="fas fa-apple-alt"></i>
                                    </div>
                                    <div class="no-data-text">No products data available</div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Enhanced Monthly Summary -->
    <div class="summary-dashboard">
        <div class="summary-header">
            <div class="summary-title">
                <i class="fas fa-calendar-alt"></i>
                <h3>Agricultural Performance Summary (<?= $year ?>)</h3>
                <div class="summary-period">
                    <i class="fas fa-clock"></i>
                    <?= $month > 0 ? 'Monthly Report' : 'Annual Overview' ?>
                </div>
            </div>
            <button onclick="exportTable('summaryTable', 'monthly_summary')" class="summary-export-btn">
                <i class="fas fa-download"></i>
                <span>Export Full Report</span>
            </button>
        </div>
        <div class="summary-content">
            <div class="summary-table-container">
                <table id="summaryTable" class="summary-table">
                    <thead>
                        <tr>
                            <th><i class="fas fa-calendar"></i> Period</th>
                            <th><i class="fas fa-users"></i> New Users</th>
                            <th><i class="fas fa-tractor"></i> Equipment</th>
                            <th><i class="fas fa-seedling"></i> Products</th>
                            <th><i class="fas fa-shopping-cart"></i> Orders</th>
                            <th><i class="fas fa-calendar-check"></i> Bookings</th>
                            <th><i class="fas fa-coins"></i> Product Revenue</th>
                            <th><i class="fas fa-chart-line"></i> Rental Revenue</th>
                            <th><i class="fas fa-rupee-sign"></i> Total Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $months_names = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                        $totals = ['users' => 0, 'equipment' => 0, 'products' => 0, 'orders' => 0, 'bookings' => 0, 'product_revenue' => 0, 'booking_revenue' => 0];
                        
                        foreach($monthly_data as $data): 
                            $total_revenue = $data['product_revenue'] + $data['booking_revenue'];
                            $totals['users'] += $data['users'];
                            $totals['equipment'] += $data['equipment'];
                            $totals['products'] += $data['products'];
                            $totals['orders'] += $data['orders'];
                            $totals['bookings'] += $data['bookings'];
                            $totals['product_revenue'] += $data['product_revenue'];
                            $totals['booking_revenue'] += $data['booking_revenue'];
                        ?>
                        <tr class="summary-row">
                            <td class="period-cell">
                                <div class="month-display">
                                    <span class="month-name"><?= $months_names[$data['month'] - 1] ?></span>
                                    <span class="year-name"><?= $year ?></span>
                                </div>
                            </td>
                            <td class="metric-cell users-metric"><?= number_format($data['users']) ?></td>
                            <td class="metric-cell equipment-metric"><?= number_format($data['equipment']) ?></td>
                            <td class="metric-cell products-metric"><?= number_format($data['products']) ?></td>
                            <td class="metric-cell orders-metric"><?= number_format($data['orders']) ?></td>
                            <td class="metric-cell bookings-metric"><?= number_format($data['bookings']) ?></td>
                            <td class="revenue-cell product-revenue">₹<?= number_format($data['product_revenue']) ?></td>
                            <td class="revenue-cell booking-revenue">₹<?= number_format($data['booking_revenue']) ?></td>
                            <td class="revenue-cell total-revenue">₹<?= number_format($total_revenue) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="totals-row">
                            <th class="total-label">
                                <i class="fas fa-calculator"></i>
                                ANNUAL TOTAL
                            </th>
                            <th class="total-value users-total"><?= number_format($totals['users']) ?></th>
                            <th class="total-value equipment-total"><?= number_format($totals['equipment']) ?></th>
                            <th class="total-value products-total"><?= number_format($totals['products']) ?></th>
                            <th class="total-value orders-total"><?= number_format($totals['orders']) ?></th>
                            <th class="total-value bookings-total"><?= number_format($totals['bookings']) ?></th>
                            <th class="total-value product-revenue-total">₹<?= number_format($totals['product_revenue']) ?></th>
                            <th class="total-value booking-revenue-total">₹<?= number_format($totals['booking_revenue']) ?></th>
                            <th class="total-value grand-total">₹<?= number_format($totals['product_revenue'] + $totals['booking_revenue']) ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>

<style>
/* Agricultural Color Scheme */
:root {
    --agri-green: #234a23;
    --agri-light-green: #2d5a2d;
    --agri-dark-green: #1a3a1a;
    --agri-brown: #8B4513;
    --agri-light-brown: #A0522D;
    --agri-yellow: #FFD700;
    --agri-orange: #FF8C00;
    --agri-red: #DC143C;
    --agri-blue: #4682B4;
    --agri-light-blue: #87CEEB;
    --agri-cream: #F5F5DC;
    --agri-wheat: #F5DEB3;
}

/* Reset and Base */
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

.main-content {
    padding: 20px;
    background: linear-gradient(135deg, 
        rgba(35, 74, 35, 0.1) 0%, 
        rgba(245, 245, 220, 0.3) 25%, 
        rgba(139, 69, 19, 0.1) 50%, 
        rgba(255, 215, 0, 0.2) 75%, 
        rgba(35, 74, 35, 0.1) 100%);
    min-height: 100vh;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    position: relative;
}

.main-content::before {
    content: '';
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-image: 
        radial-gradient(circle at 25% 25%, rgba(35, 74, 35, 0.05) 0%, transparent 25%),
        radial-gradient(circle at 75% 75%, rgba(255, 215, 0, 0.05) 0%, transparent 25%);
    pointer-events: none;
    z-index: -1;
}

/* Animated Dashboard Header */
.dashboard-header {
    background: linear-gradient(135deg, var(--agri-green), var(--agri-light-green));
    border-radius: 20px;
    padding: 0;
    margin-bottom: 30px;
    box-shadow: 
        0 20px 40px rgba(35, 74, 35, 0.2),
        0 0 0 1px rgba(255, 255, 255, 0.1);
    position: relative;
    overflow: hidden;
}

.header-pattern {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-image: 
        url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.05'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
    opacity: 0.3;
}

.header-content {
    padding: 30px;
    position: relative;
    z-index: 2;
}

.header-title-container {
    display: flex;
    align-items: center;
    gap: 20px;
    margin-bottom: 20px;
}

.agri-icon {
    position: relative;
    width: 80px;
    height: 80px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 20px;
    backdrop-filter: blur(10px);
}

.agri-icon .fa-chart-line {
    font-size: 32px;
    color: var(--agri-yellow);
}

.agri-icon .fa-leaf {
    position: absolute;
    font-size: 20px;
    color: var(--agri-wheat);
    top: 10px;
    right: 10px;
    animation: leafFloat 3s ease-in-out infinite;
}

@keyframes leafFloat {
    0%, 100% { transform: translateY(0) rotate(0deg); }
    50% { transform: translateY(-5px) rotate(5deg); }
}

.animated-title {
    color: white;
    font-size: 2.8rem;
    font-weight: 700;
    background: linear-gradient(45deg, white, var(--agri-yellow));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    margin-bottom: 8px;
}

.header-subtitle {
    color: rgba(255, 255, 255, 0.9);
    font-size: 1.2rem;
    display: flex;
    align-items: center;
    gap: 15px;
}

.live-indicator {
    display: flex;
    align-items: center;
    gap: 8px;
    background: rgba(255, 255, 255, 0.1);
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.9rem;
}

.pulse-dot {
    width: 8px;
    height: 8px;
    background: var(--agri-yellow);
    border-radius: 50%;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0% { box-shadow: 0 0 0 0 rgba(255, 215, 0, 0.7); }
    70% { box-shadow: 0 0 0 10px rgba(255, 215, 0, 0); }
    100% { box-shadow: 0 0 0 0 rgba(255, 215, 0, 0); }
}

.header-controls {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 20px;
}

.filters-container {
    display: flex;
    gap: 20px;
    align-items: end;
    flex-wrap: wrap;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.filter-group label {
    color: rgba(255, 255, 255, 0.9);
    font-size: 0.9rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 6px;
}

.custom-select {
    padding: 12px 16px;
    border: 2px solid rgba(255, 255, 255, 0.2);
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.1);
    color: white;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.3s ease;
    backdrop-filter: blur(10px);
    min-width: 140px;
}

.custom-select:focus {
    outline: none;
    border-color: var(--agri-yellow);
    box-shadow: 0 0 0 3px rgba(255, 215, 0, 0.2);
}

.custom-select option {
    background: var(--agri-green);
    color: white;
}

.export-actions {
    display: flex;
    gap: 12px;
}

.action-btn {
    padding: 12px 20px;
    border: none;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
    color: white;
}

.action-btn::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
    transition: left 0.5s;
}

.action-btn:hover::before {
    left: 100%;
}

.excel-btn {
    background: linear-gradient(135deg, #22c55e, #16a34a);
    box-shadow: 0 4px 15px rgba(34, 197, 94, 0.3);
}

.pdf-btn {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3);
}

.print-btn {
    background: linear-gradient(135deg, #6366f1, #4f46e5);
    box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3);
}

.action-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
}

/* Enhanced KPI Dashboard */
.kpi-dashboard {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
    gap: 25px;
    margin-bottom: 40px;
}

.kpi-card {
    background: rgba(255, 255, 255, 0.95);
    border-radius: 20px;
    padding: 25px;
    box-shadow: 
        0 20px 40px rgba(0, 0, 0, 0.1),
        0 0 0 1px rgba(255, 255, 255, 0.2);
    cursor: pointer;
    transition: all 0.4s ease;
    position: relative;
    overflow: hidden;
    backdrop-filter: blur(10px);
}

.kpi-background {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    opacity: 0.1;
    z-index: 1;
}

.agri-pattern {
    width: 100%;
    height: 100%;
    background-size: 40px 40px;
}

.users-pattern {
    background-image: 
        radial-gradient(circle at 20px 20px, var(--agri-green) 2px, transparent 2px);
}

.equipment-pattern {
    background-image: 
        linear-gradient(45deg, var(--agri-brown) 25%, transparent 25%),
        linear-gradient(-45deg, var(--agri-brown) 25%, transparent 25%);
}

.products-pattern {
    background-image: 
        radial-gradient(circle at 20px 20px, var(--agri-yellow) 2px, transparent 2px);
}

.revenue-pattern {
    background-image: 
        repeating-linear-gradient(45deg, var(--agri-green) 0px, var(--agri-green) 2px, transparent 2px, transparent 10px);
}

.bookings-pattern {
    background-image: 
        radial-gradient(circle at 10px 10px, var(--agri-blue) 1px, transparent 1px);
}

.orders-pattern {
    background-image: 
        linear-gradient(90deg, var(--agri-orange) 50%, transparent 50%),
        linear-gradient(var(--agri-orange) 50%, transparent 50%);
}

.kpi-content {
    position: relative;
    z-index: 2;
}

.kpi-card:hover {
    transform: translateY(-10px) scale(1.02);
    box-shadow: 
        0 30px 60px rgba(0, 0, 0, 0.15),
        0 0 0 1px rgba(255, 255, 255, 0.3);
}

.kpi-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.kpi-icon {
    width: 70px;
    height: 70px;
    border-radius: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    color: white;
    position: relative;
    box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
}

.users-icon { background: linear-gradient(135deg, var(--agri-green), var(--agri-light-green)); }
.equipment-icon { background: linear-gradient(135deg, var(--agri-brown), var(--agri-light-brown)); }
.products-icon { background: linear-gradient(135deg, var(--agri-yellow), var(--agri-orange)); }
.revenue-icon { background: linear-gradient(135deg, #22c55e, #16a34a); }
.bookings-icon { background: linear-gradient(135deg, var(--agri-blue), var(--agri-light-blue)); }
.orders-icon { background: linear-gradient(135deg, var(--agri-orange), var(--agri-red)); }

.kpi-trend {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
}

.trend-up {
    background: linear-gradient(135deg, #dcfce7, #bbf7d0);
    color: #16a34a;
}

.trend-down {
    background: linear-gradient(135deg, #fef2f2, #fecaca);
    color: #dc2626;
}

.kpi-number {
    font-size: 2.8rem;
    font-weight: 800;
    color: var(--agri-dark-green);
    margin-bottom: 8px;
    line-height: 1;
    background: linear-gradient(135deg, var(--agri-green), var(--agri-dark-green));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.kpi-label {
    font-size: 1.1rem;
    color: var(--agri-green);
    font-weight: 600;
    margin-bottom: 12px;
}

.kpi-detail {
    font-size: 0.9rem;
    color: #6b7280;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.progress-track {
    width: 100%;
    height: 8px;
    background: rgba(35, 74, 35, 0.1);
    border-radius: 4px;
    overflow: hidden;
    margin-bottom: 8px;
}

.progress-fill {
    height: 100%;
    border-radius: 4px;
    transition: width 0.8s ease;
}

.users-progress { background: linear-gradient(90deg, var(--agri-green), var(--agri-light-green)); }
.equipment-progress { background: linear-gradient(90deg, var(--agri-brown), var(--agri-light-brown)); }
.products-progress { background: linear-gradient(90deg, var(--agri-yellow), var(--agri-orange)); }
.revenue-progress { background: linear-gradient(90deg, #22c55e, #16a34a); }
.bookings-progress { background: linear-gradient(90deg, var(--agri-blue), var(--agri-light-blue)); }
.orders-progress { background: linear-gradient(90deg, var(--agri-orange), var(--agri-red)); }

.progress-label {
    font-size: 0.8rem;
    color: #9ca3af;
    font-weight: 500;
}

/* Enhanced Charts Dashboard */
.charts-dashboard {
    margin-bottom: 40px;
}

.main-chart-container {
    background: rgba(255, 255, 255, 0.95);
    border-radius: 20px;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
    margin-bottom: 25px;
    overflow: hidden;
    backdrop-filter: blur(10px);
}

.chart-header {
    background: linear-gradient(135deg, var(--agri-green), var(--agri-light-green));
    color: white;
    padding: 25px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.chart-title {
    display: flex;
    align-items: center;
    gap: 15px;
}

.chart-icon {
    font-size: 24px;
    color: var(--agri-yellow);
}

.chart-title h3 {
    margin: 0;
    font-size: 1.4rem;
    font-weight: 600;
}

.chart-controls {
    display: flex;
    gap: 12px;
}

.chart-tab {
    padding: 10px 18px;
    border: 2px solid rgba(255, 255, 255, 0.2);
    background: rgba(255, 255, 255, 0.1);
    color: white;
    border-radius: 12px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
}

.chart-tab.active,
.chart-tab:hover {
    background: rgba(255, 255, 255, 0.2);
    border-color: var(--agri-yellow);
    transform: translateY(-2px);
}

.chart-canvas {
    padding: 30px;
    height: 400px;
    position: relative;
}

.status-charts-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
}

.status-chart-card {
    background: rgba(255, 255, 255, 0.95);
    border-radius: 16px;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.08);
    overflow: hidden;
    backdrop-filter: blur(10px);
}

.chart-mini-header {
    background: linear-gradient(135deg, var(--agri-green), var(--agri-light-green));
    color: white;
    padding: 18px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.chart-mini-header h4 {
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
}

.chart-mini-body {
    padding: 20px;
    height: 280px;
    position: relative;
}

/* Enhanced Data Tables */
.tables-dashboard {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 30px;
    margin-bottom: 40px;
}

.data-table-card {
    background: rgba(255, 255, 255, 0.95);
    border-radius: 20px;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
    overflow: hidden;
    backdrop-filter: blur(10px);
}

.table-header {
    background: linear-gradient(135deg, var(--agri-green), var(--agri-light-green));
    color: white;
    padding: 25px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.table-title {
    display: flex;
    align-items: center;
    gap: 12px;
}

.table-title h3 {
    margin: 0;
    font-size: 1.3rem;
    font-weight: 600;
}

.table-badge {
    background: rgba(255, 255, 255, 0.2);
    color: white;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
}

.table-actions {
    display: flex;
    gap: 15px;
    align-items: center;
}

.search-container {
    position: relative;
}

.search-container i {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: rgba(255, 255, 255, 0.7);
}

.table-search {
    padding: 10px 15px 10px 35px;
    border: 2px solid rgba(255, 255, 255, 0.2);
    background: rgba(255, 255, 255, 0.1);
    color: white;
    border-radius: 12px;
    font-size: 13px;
    width: 200px;
    backdrop-filter: blur(10px);
}

.table-search::placeholder {
    color: rgba(255, 255, 255, 0.7);
}

.table-btn {
    padding: 10px 16px;
    background: rgba(255, 255, 255, 0.2);
    color: white;
    border: 2px solid rgba(255, 255, 255, 0.2);
    border-radius: 12px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s ease;
}

.table-btn:hover {
    background: rgba(255, 255, 255, 0.3);
    border-color: var(--agri-yellow);
}

.table-container {
    max-height: 600px;
    overflow-y: auto;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table th {
    padding: 18px 15px;
    text-align: left;
    font-weight: 600;
    color: var(--agri-green);
    background: rgba(35, 74, 35, 0.05);
    position: sticky;
    top: 0;
    z-index: 10;
    font-size: 0.9rem;
}

.data-table td {
    padding: 18px 15px;
    border-bottom: 1px solid rgba(35, 74, 35, 0.1);
}

.data-table tbody tr:hover {
    background: rgba(35, 74, 35, 0.05);
}

.rank-container {
    display: flex;
    align-items: center;
    gap: 8px;
    position: relative;
}

.rank-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    font-weight: 700;
    font-size: 16px;
    color: white;
    position: relative;
}

.rank-1 { 
    background: linear-gradient(135deg, #ffd700, #ffed4e); 
    color: #b45309;
    box-shadow: 0 4px 15px rgba(255, 215, 0, 0.4);
}
.rank-2 { 
    background: linear-gradient(135deg, #c0c0c0, #e5e7eb); 
    color: #374151;
    box-shadow: 0 4px 15px rgba(192, 192, 192, 0.4);
}
.rank-3 { 
    background: linear-gradient(135deg, #cd7f32, #d69e2e); 
    color: white;
    box-shadow: 0 4px 15px rgba(205, 127, 50, 0.4);
}
.rank-default { 
    background: linear-gradient(135deg, var(--agri-green), var(--agri-light-green)); 
    color: white;
}

.rank-crown {
    position: absolute;
    top: -8px;
    right: -5px;
    font-size: 12px;
    color: var(--agri-yellow);
    animation: crownFloat 2s ease-in-out infinite;
}

.rank-star {
    position: absolute;
    top: -8px;
    right: -5px;
    font-size: 12px;
    color: var(--agri-yellow);
    animation: starTwinkle 2s ease-in-out infinite;
}

@keyframes crownFloat {
    0%, 100% { transform: translateY(0) rotate(0deg); }
    50% { transform: translateY(-3px) rotate(5deg); }
}

@keyframes starTwinkle {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.7; transform: scale(1.2); }
}

.owner-info,
.product-info,
.seller-info {
    display: flex;
    align-items: center;
    gap: 12px;
}

.owner-avatar,
.product-icon,
.seller-avatar {
    width: 45px;
    height: 45px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    color: white;
    background: linear-gradient(135deg, var(--agri-green), var(--agri-light-green));
}

.owner-details,
.product-details,
.seller-details {
    flex: 1;
}

.owner-name,
.product-name,
.seller-name {
    font-weight: 600;
    color: var(--agri-dark-green);
    margin-bottom: 4px;
    font-size: 0.95rem;
}

.owner-contact,
.product-price,
.seller-email {
    font-size: 0.8rem;
    color: #6b7280;
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 2px;
}

.equipment-stats,
.orders-display {
    text-align: center;
}

.equipment-count,
.orders-count {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--agri-green);
}

.equipment-label,
.orders-label {
    font-size: 0.8rem;
    color: #6b7280;
    font-weight: 500;
}

.earnings-display,
.revenue-display {
    text-align: right;
}

.total-earnings,
.total-revenue {
    font-size: 1.2rem;
    font-weight: 700;
    color: #16a34a;
    margin-bottom: 4px;
}

.avg-earnings,
.avg-order {
    font-size: 0.8rem;
    color: #6b7280;
}

.performance-display {
    display: flex;
    justify-content: center;
}

.performance-circle {
    position: relative;
    width: 60px;
    height: 60px;
}

.performance-text {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--agri-green);
}

.no-data-message {
    text-align: center;
    padding: 60px 20px;
    color: #9ca3af;
}

.no-data-icon {
    font-size: 48px;
    color: #d1d5db;
    margin-bottom: 15px;
}

.no-data-text {
    font-size: 1rem;
    font-weight: 500;
}

/* Enhanced Summary Dashboard */
.summary-dashboard {
    background: rgba(255, 255, 255, 0.95);
    border-radius: 20px;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
    overflow: hidden;
    backdrop-filter: blur(10px);
}

.summary-header {
    background: linear-gradient(135deg, var(--agri-green), var(--agri-light-green));
    color: white;
    padding: 25px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.summary-title {
    display: flex;
    align-items: center;
    gap: 15px;
}

.summary-title h3 {
    margin: 0;
    font-size: 1.4rem;
    font-weight: 600;
}

.summary-period {
    display: flex;
    align-items: center;
    gap: 8px;
    background: rgba(255, 255, 255, 0.1);
    padding: 8px 15px;
    border-radius: 20px;
    font-size: 0.9rem;
    font-weight: 500;
}

.summary-export-btn {
    padding: 12px 20px;
    background: rgba(255, 255, 255, 0.2);
    color: white;
    border: 2px solid rgba(255, 255, 255, 0.2);
    border-radius: 12px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
}

.summary-export-btn:hover {
    background: rgba(255, 255, 255, 0.3);
    border-color: var(--agri-yellow);
    transform: translateY(-2px);
}

.summary-content {
    padding: 30px;
}

.summary-table-container {
    overflow-x: auto;
}

.summary-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1200px;
}

.summary-table th {
    padding: 18px 15px;
    text-align: center;
    font-weight: 600;
    color: var(--agri-green);
    background: rgba(35, 74, 35, 0.05);
    font-size: 0.9rem;
    white-space: nowrap;
}

.summary-table td {
    padding: 15px;
    text-align: center;
    border-bottom: 1px solid rgba(35, 74, 35, 0.1);
    font-size: 0.9rem;
}

.summary-row:hover {
    background: rgba(35, 74, 35, 0.05);
}

.period-cell {
    text-align: left !important;
}

.month-display {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
}

.month-name {
    font-weight: 600;
    color: var(--agri-green);
    font-size: 1rem;
}

.year-name {
    font-size: 0.8rem;
    color: #6b7280;
}

.metric-cell {
    font-weight: 600;
    color: var(--agri-dark-green);
}

.revenue-cell {
    font-weight: 600;
    color: #16a34a;
}

.total-revenue {
    background: rgba(34, 197, 94, 0.1) !important;
    font-weight: 700;
    color: #15803d;
}

.totals-row th {
    background: linear-gradient(135deg, var(--agri-green), var(--agri-light-green)) !important;
    color: white !important;
    font-weight: 700;
    padding: 20px 15px;
}

.total-label {
    display: flex;
    align-items: center;
    gap: 8px;
    justify-content: center;
}

.grand-total {
    background: linear-gradient(135deg, #16a34a, #15803d);
    color: white !important;
    font-weight: 800;
    font-size: 1.1rem;
}

/* Responsive Design */
@media (max-width: 1200px) {
    .kpi-dashboard {
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    }
    
    .tables-dashboard {
        grid-template-columns: 1fr;
    }
    
    .status-charts-grid {
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    }
}

@media (max-width: 768px) {
    .main-content {
        padding: 15px;
    }
    
    .header-content {
        padding: 20px;
    }
    
    .header-title-container {
        flex-direction: column;
        text-align: center;
        gap: 15px;
    }
    
    .animated-title {
        font-size: 2rem;
    }
    
    .header-controls {
        flex-direction: column;
        align-items: stretch;
        gap: 20px;
    }
    
    .filters-container {
        flex-direction: column;
        gap: 15px;
    }
    
    .export-actions {
        flex-direction: column;
    }
    
    .kpi-dashboard {
        grid-template-columns: 1fr;
    }
    
    .chart-header {
        flex-direction: column;
        gap: 20px;
        text-align: center;
    }
    
    .chart-controls {
        flex-wrap: wrap;
        justify-content: center;
    }
    
    .table-header {
        flex-direction: column;
        gap: 15px;
    }
    
    .table-actions {
        flex-direction: column;
        width: 100%;
    }
    
    .table-search {
        width: 100%;
    }
    
    .summary-header {
        flex-direction: column;
        gap: 15px;
        text-align: center;
    }
}

/* Print Styles */
@media print {
    .header-controls,
    .chart-controls,
    .table-actions,
    .export-actions {
        display: none !important;
    }
    
    .main-content {
        background: white !important;
    }
    
    .kpi-card,
    .main-chart-container,
    .status-chart-card,
    .data-table-card,
    .summary-dashboard {
        background: white !important;
        box-shadow: none !important;
        border: 1px solid #ddd !important;
    }
    
    .dashboard-header,
    .chart-header,
    .chart-mini-header,
    .table-header,
    .summary-header {
        background: #f8f9fa !important;
        color: var(--agri-green) !important;
    }
}

/* Loading Animation */
@keyframes shimmer {
    0% { transform: translateX(-100%); }
    100% { transform: translateX(100%); }
}

.loading {
    position: relative;
    overflow: hidden;
}

.loading::after {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    bottom: 0;
    left: 0;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.4), transparent);
    animation: shimmer 1.5s infinite;
}

/* Smooth Animations */
@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(40px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.kpi-card,
.main-chart-container,
.status-chart-card,
.data-table-card,
.summary-dashboard {
    animation: fadeInUp 0.8s ease forwards;
}

.kpi-card:nth-child(1) { animation-delay: 0.1s; }
.kpi-card:nth-child(2) { animation-delay: 0.2s; }
.kpi-card:nth-child(3) { animation-delay: 0.3s; }
.kpi-card:nth-child(4) { animation-delay: 0.4s; }
.kpi-card:nth-child(5) { animation-delay: 0.5s; }
.kpi-card:nth-child(6) { animation-delay: 0.6s; }
</style>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

<script>
// Agricultural Color Palette for Charts
const agriColors = {
    green: '#234a23',
    lightGreen: '#2d5a2d',
    brown: '#8B4513',
    yellow: '#FFD700',
    orange: '#FF8C00',
    blue: '#4682B4',
    red: '#DC143C',
    cream: '#F5F5DC'
};

// Data for charts
const monthlyData = <?= json_encode($monthly_data) ?>;
const equipmentStatusData = <?= json_encode($equipment_status) ?>;
const productStatusData = <?= json_encode($product_status) ?>;
const bookingStatusData = <?= json_encode($booking_status) ?>;
const orderStatusData = <?= json_encode($order_status) ?>;

const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

// Chart instances
let mainChart, equipmentStatusChart, productStatusChart, bookingStatusChart, orderStatusChart;

// Initialize charts when page loads
document.addEventListener('DOMContentLoaded', function() {
    initializeMainChart();
    initializeStatusCharts();
    initializeTableSearch();
    initializeAnimations();
});

function initializeMainChart() {
    const ctx = document.getElementById('mainChart').getContext('2d');
    
    const revenueData = monthlyData.map(item => item.product_revenue + item.booking_revenue);
    
    mainChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: months,
            datasets: [{
                label: 'Agricultural Revenue',
                data: revenueData,
                borderColor: agriColors.green,
                backgroundColor: `${agriColors.green}20`,
                borderWidth: 4,
                fill: true,
                tension: 0.4,
                pointBackgroundColor: agriColors.green,
                pointBorderColor: '#ffffff',
                pointBorderWidth: 3,
                pointRadius: 8,
                pointHoverRadius: 10,
                shadowOffsetX: 3,
                shadowOffsetY: 3,
                shadowBlur: 10,
                shadowColor: `${agriColors.green}40`
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    labels: {
                        usePointStyle: true,
                        padding: 25,
                        font: {
                            size: 16,
                            weight: 'bold'
                        },
                        color: agriColors.green
                    }
                },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    backgroundColor: agriColors.green,
                    titleColor: '#fff',
                    bodyColor: '#fff',
                    borderColor: agriColors.yellow,
                    borderWidth: 2,
                    cornerRadius: 12,
                    displayColors: true,
                    callbacks: {
                        title: function(tooltipItems) {
                            return '🌾 ' + tooltipItems[0].label + ' Agricultural Report';
                        },
                        label: function(context) {
                            return '💰 Revenue: ₹' + context.parsed.y.toLocaleString();
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        color: agriColors.green,
                        font: {
                            size: 14,
                            weight: 'bold'
                        },
                        padding: 10
                    },
                    title: {
                        display: true,
                        text: '🗓️ Agricultural Season',
                        color: agriColors.green,
                        font: {
                            size: 16,
                            weight: 'bold'
                        }
                    }
                },
                y: {
                    beginAtZero: true,
                    grid: {
                        color: `${agriColors.green}15`,
                        lineWidth: 1
                    },
                    ticks: {
                        color: agriColors.green,
                        font: {
                            size: 14,
                            weight: 'bold'
                        },
                        callback: function(value) {
                            return '₹' + value.toLocaleString();
                        }
                    },
                    title: {
                        display: true,
                        text: '💵 Revenue (₹)',
                        color: agriColors.green,
                        font: {
                            size: 16,
                            weight: 'bold'
                        }
                    }
                }
            },
            interaction: {
                intersect: false,
                mode: 'index'
            },
            animation: {
                duration: 2500,
                easing: 'easeInOutQuart',
                onProgress: function(animation) {
                    // Add growing animation effect
                    const progress = animation.currentStep / animation.numSteps;
                    this.options.elements.point.radius = 8 * progress;
                }
            },
            elements: {
                line: {
                    borderJoinStyle: 'round'
                },
                point: {
                    hoverRadius: 12
                }
            }
        }
    });
}

function initializeStatusCharts() {
    const agriStatusColors = [agriColors.yellow, agriColors.green, agriColors.red, agriColors.blue, agriColors.orange];
    
    // Equipment Status Chart
    if (equipmentStatusData.length > 0) {
        const equipmentCtx = document.getElementById('equipmentStatusChart').getContext('2d');
        equipmentStatusChart = createAgriDoughnutChart(equipmentCtx, equipmentStatusData, 'Equipment Status');
    }
    
    // Product Status Chart
    if (productStatusData.length > 0) {
        const productCtx = document.getElementById('productStatusChart').getContext('2d');
        productStatusChart = createAgriDoughnutChart(productCtx, productStatusData, 'Product Status');
    }
    
    // Booking Status Chart
    if (bookingStatusData.length > 0) {
        const bookingCtx = document.getElementById('bookingStatusChart').getContext('2d');
        bookingStatusChart = createAgriDoughnutChart(bookingCtx, bookingStatusData, 'Rental Status', 'status');
    }
    
    // Order Status Chart
    if (orderStatusData.length > 0) {
        const orderCtx = document.getElementById('orderStatusChart').getContext('2d');
        orderStatusChart = createAgriDoughnutChart(orderCtx, orderStatusData, 'Order Status', 'Status');
    }
}

function createAgriDoughnutChart(ctx, data, title, statusField = 'Approval_status') {
    const labels = data.map(item => {
        const status = item[statusField];
        const icons = {
            'PEN': '⏳ Pending',
            'CON': '✅ Approved', 
            'REJ': '❌ Rejected',
            'COM': '🎉 Completed',
            'CAN': '🚫 Cancelled'
        };
        return icons[status] || status;
    });
    
    const values = data.map(item => parseInt(item.count));
    const agriStatusColors = [agriColors.yellow, agriColors.green, agriColors.red, agriColors.blue, agriColors.orange];
    
    return new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: values,
                backgroundColor: agriStatusColors.slice(0, values.length),
                borderColor: '#ffffff',
                borderWidth: 4,
                hoverBorderWidth: 6,
                hoverOffset: 8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '60%',
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        padding: 20,
                        usePointStyle: true,
                        font: {
                            size: 12,
                            weight: 'bold'
                        },
                        color: agriColors.green,
                        generateLabels: function(chart) {
                            const data = chart.data;
                            return data.labels.map((label, index) => ({
                                text: label,
                                fillStyle: data.datasets[0].backgroundColor[index],
                                strokeStyle: data.datasets[0].borderColor,
                                lineWidth: data.datasets[0].borderWidth,
                                pointStyle: 'circle',
                                index: index
                            }));
                        }
                    }
                },
                tooltip: {
                    backgroundColor: agriColors.green,
                    titleColor: '#fff',
                    bodyColor: '#fff',
                    borderColor: agriColors.yellow,
                    borderWidth: 2,
                    cornerRadius: 12,
                    callbacks: {
                        title: function(tooltipItems) {
                            return '📊 ' + title;
                        },
                        label: function(context) {
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = ((context.parsed / total) * 100).toFixed(1);
                            return context.label + ': ' + context.parsed + ' (' + percentage + '%)';
                        }
                    }
                }
            },
            animation: {
                animateRotate: true,
                animateScale: true,
                duration: 2000,
                easing: 'easeInOutBounce'
            },
            elements: {
                arc: {
                    borderAlign: 'center'
                }
            }
        }
    });
}

function switchChart(btn, type) {
    // Update active button
    document.querySelectorAll('.chart-tab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    
    let datasets = [];
    
    switch(type) {
        case 'revenue':
            const revenueData = monthlyData.map(item => item.product_revenue + item.booking_revenue);
            datasets = [{
                label: '🌾 Total Agricultural Revenue',
                data: revenueData,
                borderColor: agriColors.green,
                backgroundColor: `${agriColors.green}20`,
                borderWidth: 4,
                fill: true,
                tension: 0.4,
                pointBackgroundColor: agriColors.green,
                pointBorderColor: '#ffffff',
                pointBorderWidth: 3,
                pointRadius: 8
            }];
            break;
            
        case 'growth':
            const usersData = monthlyData.map(item => item.users);
            const equipmentData = monthlyData.map(item => item.equipment);
            const productsData = monthlyData.map(item => item.products);
            
            datasets = [
                {
                    label: '👥 New Farmers & Owners',
                    data: usersData,
                    borderColor: agriColors.blue,
                    backgroundColor: `${agriColors.blue}20`,
                    borderWidth: 3,
                    tension: 0.4,
                    pointRadius: 6
                },
                {
                    label: '🚜 Equipment Listed',
                    data: equipmentData,
                    borderColor: agriColors.brown,
                    backgroundColor: `${agriColors.brown}20`,
                    borderWidth: 3,
                    tension: 0.4,
                    pointRadius: 6
                },
                {
                    label: '🌱 Products Added',
                    data: productsData,
                    borderColor: agriColors.yellow,
                    backgroundColor: `${agriColors.yellow}20`,
                    borderWidth: 3,
                    tension: 0.4,
                    pointRadius: 6
                }
            ];
            break;
            
        case 'comparison':
            const ordersData = monthlyData.map(item => item.orders);
            const bookingsData = monthlyData.map(item => item.bookings);
            
            datasets = [
                {
                    label: '🛒 Product Orders',
                    data: ordersData,
                    borderColor: agriColors.orange,
                    backgroundColor: `${agriColors.orange}20`,
                    borderWidth: 3,
                    tension: 0.4,
                    pointRadius: 6
                },
                {
                    label: '📅 Equipment Rentals',
                    data: bookingsData,
                    borderColor: agriColors.red,
                    backgroundColor: `${agriColors.red}20`,
                    borderWidth: 3,
                    tension: 0.4,
                    pointRadius: 6
                }
            ];
            break;
    }
    
    mainChart.data.datasets = datasets;
    mainChart.update('show');
}

function initializeTableSearch() {
    // Owners table search
    const ownersSearch = document.getElementById('ownersSearch');
    if (ownersSearch) {
        ownersSearch.addEventListener('input', function() {
            filterTable('ownersTable', this.value, '.owner-row');
        });
    }
    
    // Products table search
    const productsSearch = document.getElementById('productsSearch');
    if (productsSearch) {
        productsSearch.addEventListener('input', function() {
            filterTable('productsTable', this.value, '.product-row');
        });
    }
}

function filterTable(tableId, searchTerm, rowSelector) {
    const table = document.getElementById(tableId);
    if (!table) return;
    
    const rows = table.querySelectorAll(rowSelector);
    
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        if (text.includes(searchTerm.toLowerCase())) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

function initializeAnimations() {
    // Animate progress bars
    const progressBars = document.querySelectorAll('.progress-fill');
    progressBars.forEach(bar => {
        const width = bar.style.width;
        bar.style.width = '0%';
        setTimeout(() => {
            bar.style.width = width;
        }, 500);
    });
    
    // Animate performance circles
    const performanceCircles = document.querySelectorAll('.performance-circle');
    performanceCircles.forEach(circle => {
        const percentage = circle.dataset.percentage;
        const circumference = 2 * Math.PI * 25;
        const offset = circumference - (percentage / 100) * circumference;
        
        const progressCircle = circle.querySelector('circle:last-child');
        if (progressCircle) {
            progressCircle.style.strokeDashoffset = circumference;
            setTimeout(() => {
                progressCircle.style.strokeDashoffset = offset;
            }, 1000);
        }
    });
}

function drillDown(type) {
    // Add agricultural-themed animation
    event.currentTarget.style.transform = 'scale(0.95) rotate(1deg)';
    setTimeout(() => {
        event.currentTarget.style.transform = '';
    }, 200);
    
    // Add some agricultural-themed feedback
    const icons = {
        'users': '👥',
        'equipment': '🚜',
        'products': '🌱',
        'revenue': '💰',
        'bookings': '📅',
        'orders': '🛒'
    };
    
    console.log(`${icons[type]} Exploring ${type} insights...`);
    
    // Here you could implement drill-down functionality
    // For example, redirect to detailed pages or show modals
}

function applyFilters() {
    const year = document.getElementById('yearFilter').value;
    const month = document.getElementById('monthFilter').value;
    const reportType = document.getElementById('reportTypeFilter').value;
    
    // Show loading state
    document.body.style.cursor = 'wait';
    
    const params = new URLSearchParams();
    params.append('year', year);
    params.append('month', month);
    params.append('report_type', reportType);
    
    window.location.href = '?' + params.toString();
}

function exportTable(tableId, filename) {
    const table = document.getElementById(tableId);
    if (!table) return;
    
    const rows = [];
    
    // Get headers
    const headers = [];
    table.querySelectorAll('thead th').forEach(th => {
        // Remove icons from headers for export
        const text = th.innerText.replace(/[📊👥🚜🌱💰📅🛒⭐🏆]/g, '').trim();
        headers.push(text);
    });
    rows.push(headers);
    
    // Get data rows
    table.querySelectorAll('tbody tr').forEach(tr => {
        if (tr.style.display !== 'none' && !tr.querySelector('.no-data-message')) {
            const row = [];
            tr.querySelectorAll('td').forEach(td => {
                // Clean up text for export
                const text = td.innerText.replace(/[👥🚜🌱💰📅🛒⭐🏆🌾💵📊]/g, '').trim();
                row.push(text);
            });
            rows.push(row);
        }
    });
    
    // Create CSV content
    const csvContent = rows.map(row => 
        row.map(cell => '"' + cell.replace(/"/g, '""') + '"').join(',')
    ).join('\n');
    
    // Download CSV with agricultural prefix
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    link.setAttribute('href', url);
    link.setAttribute('download', `AgriRent_${filename}_<?= $year ?>.csv`);
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

function exportToExcel() {
    if (typeof XLSX === 'undefined') {
        alert('🌾 Excel export library not loaded. Please refresh the page and try again.');
        return;
    }
    
    const wb = XLSX.utils.book_new();
    
    // Export summary data
    const summaryTable = document.getElementById('summaryTable');
    if (summaryTable) {
        const summaryData = [];
        
        // Add title
        summaryData.push(['🌾 AgriRent Agricultural Business Report - <?= $year ?>']);
        summaryData.push(['']);
        
        // Add headers
        const headers = [];
        summaryTable.querySelectorAll('thead th').forEach(th => {
            const text = th.innerText.replace(/[📊👥🚜🌱💰📅🛒⭐🏆]/g, '').trim();
            headers.push(text);
        });
        summaryData.push(headers);
        
        // Add data rows
        summaryTable.querySelectorAll('tbody tr').forEach(tr => {
            const row = [];
            tr.querySelectorAll('td').forEach(td => {
                const text = td.innerText.replace(/[👥🚜🌱💰📅🛒⭐🏆🌾💵📊]/g, '').trim();
                row.push(text);
            });
            summaryData.push(row);
        });
        
        // Add totals row
        const totalsRow = [];
        summaryTable.querySelectorAll('tfoot th').forEach(th => {
            const text = th.innerText.replace(/[👥🚜🌱💰📅🛒⭐🏆🌾💵📊]/g, '').trim();
            totalsRow.push(text);
        });
        summaryData.push(totalsRow);
        
        const ws = XLSX.utils.aoa_to_sheet(summaryData);
        XLSX.utils.book_append_sheet(wb, ws, 'Agricultural Summary');
    }
    
    // Save the file with agricultural branding
    XLSX.writeFile(wb, 'AgriRent_Agricultural_Report_<?= $year ?>.xlsx');
}

function exportToPDF() {
    window.print();
}

function printReport() {
    window.print();
}

// Add seasonal greetings based on current month
document.addEventListener('DOMContentLoaded', function() {
    const currentMonth = new Date().getMonth() + 1;
    const seasons = {
        3: '🌸 Spring', 4: '🌸 Spring', 5: '🌸 Spring',
        6: '☀️ Summer', 7: '☀️ Summer', 8: '☀️ Summer',
        9: '🍂 Autumn', 10: '🍂 Autumn', 11: '🍂 Autumn',
        12: '❄️ Winter', 1: '❄️ Winter', 2: '❄️ Winter'
    };
    
    console.log(`🌾 Welcome to AgriRent Analytics - ${seasons[currentMonth]} Season Report! 🌾`);
});
</script>

<?php require 'footer.php'; ?>
