<?php
session_start();
require_once('../auth/config.php');

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'O') {
    header("Location: ../login.php");
    exit();
}

$owner_id = $_SESSION['user_id'];

// Get equipment bookings earnings
$eq_query = "SELECT COALESCE(SUM(CASE WHEN eb.status = 'CON' THEN eb.total_amount END),0) as equipment_earnings,
                    COUNT(DISTINCT CASE WHEN eb.status = 'CON' THEN eb.booking_id END) as confirmed_bookings
             FROM equipment_bookings eb
             JOIN equipment e ON eb.equipment_id = e.Equipment_id
             WHERE e.Owner_id = ?";
$eq_stmt = $conn->prepare($eq_query);
$eq_stmt->bind_param("i", $owner_id);
$eq_stmt->execute();
$eq_stats = $eq_stmt->get_result()->fetch_assoc();
$eq_stmt->close();

// Get product orders earnings
$prod_query = "SELECT COALESCE(SUM(CASE WHEN po.Status = 'COM' THEN po.total_price END),0) as product_earnings,
                      COUNT(DISTINCT CASE WHEN po.Status = 'COM' THEN po.Order_id END) as completed_orders
               FROM product_orders po
               JOIN product p ON po.Product_id = p.product_id
               WHERE p.seller_id = ?";
$prod_stmt = $conn->prepare($prod_query);
$prod_stmt->bind_param("i", $owner_id);
$prod_stmt->execute();
$prod_stats = $prod_stmt->get_result()->fetch_assoc();
$prod_stmt->close();

$eq_earnings = $eq_stats['equipment_earnings'] ?? 0;
$prod_earnings = $prod_stats['product_earnings'] ?? 0;
$total_earnings = $eq_earnings + $prod_earnings;

// Get detailed equipment bookings
$eq_detail_query = "SELECT eb.*, e.Title as equipment_title, e.Brand, e.Model, u.Name as customer_name
                    FROM equipment_bookings eb
                    JOIN equipment e ON eb.equipment_id = e.Equipment_id
                    JOIN users u ON eb.customer_id = u.user_id
                    WHERE e.Owner_id = ? AND eb.status = 'CON'
                    ORDER BY eb.booking_id DESC";
$eq_detail_stmt = $conn->prepare($eq_detail_query);
$eq_detail_stmt->bind_param("i", $owner_id);
$eq_detail_stmt->execute();
$eq_bookings = $eq_detail_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$eq_detail_stmt->close();

// Get detailed product orders
$prod_detail_query = "SELECT po.*, p.Name as product_name, u.Name as buyer_name, u.Phone as buyer_phone
                      FROM product_orders po
                      JOIN product p ON po.Product_id = p.product_id
                      JOIN users u ON po.buyer_id = u.user_id
                      WHERE p.seller_id = ? AND po.Status = 'COM'
                      ORDER BY po.order_date DESC";
$prod_detail_stmt = $conn->prepare($prod_detail_query);
$prod_detail_stmt->bind_param("i", $owner_id);
$prod_detail_stmt->execute();
$prod_orders = $prod_detail_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$prod_detail_stmt->close();

// Handle export
if (isset($_GET['export'])) {
    $export_type = $_GET['export'];
    
    if ($export_type == 'excel') {
        // Excel CSV Export
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="earnings_report_' . date('Y-m-d-His') . '.csv"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        $output = fopen('php://output', 'w');
        fwrite($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
        
        // Title
        fputcsv($output, ['EARNINGS REPORT']);
        fputcsv($output, ['Generated: ' . date('Y-m-d H:i:s')]);
        fputcsv($output, []);
        
        // Summary Section
        fputcsv($output, ['EARNINGS SUMMARY']);
        fputcsv($output, ['Equipment Earnings (Confirmed Bookings)', '₹' . number_format($eq_earnings, 2)]);
        fputcsv($output, ['Product Sales Earnings (Completed Orders)', '₹' . number_format($prod_earnings, 2)]);
        fputcsv($output, ['TOTAL EARNINGS', '₹' . number_format($total_earnings, 2)]);
        fputcsv($output, []);
        fputcsv($output, []);
        
        // Equipment Section
        fputcsv($output, ['EQUIPMENT RENTAL EARNINGS DETAILS']);
        fputcsv($output, ['Booking ID', 'Equipment', 'Brand', 'Model', 'Customer Name', 'Start Date', 'End Date', 'Hours', 'Amount (₹)']);
        
        $eq_total = 0;
        foreach ($eq_bookings as $booking) {
            $eq_total += $booking['total_amount'];
            fputcsv($output, [
                $booking['booking_id'],
                $booking['equipment_title'],
                $booking['Brand'] ?? 'N/A',
                $booking['Model'] ?? 'N/A',
                $booking['customer_name'],
                date('Y-m-d', strtotime($booking['start_date'])),
                date('Y-m-d', strtotime($booking['end_date'])),
                $booking['Hours'] ?? 0,
                number_format($booking['total_amount'], 2)
            ]);
        }
        fputcsv($output, ['', '', '', '', '', '', '', 'TOTAL EQUIPMENT EARNINGS', number_format($eq_total, 2)]);
        fputcsv($output, []);
        fputcsv($output, []);
        
        // Product Section
        fputcsv($output, ['PRODUCT SALES EARNINGS DETAILS']);
        fputcsv($output, ['Order ID', 'Product Name', 'Buyer Name', 'Buyer Phone', 'Quantity', 'Order Date', 'Amount (₹)']);
        
        $prod_total = 0;
        foreach ($prod_orders as $order) {
            $prod_total += $order['total_price'];
            fputcsv($output, [
                $order['Order_id'],
                $order['product_name'],
                $order['buyer_name'],
                $order['buyer_phone'],
                number_format($order['quantity'], 2),
                date('Y-m-d', strtotime($order['order_date'])),
                number_format($order['total_price'], 2)
            ]);
        }
        fputcsv($output, ['', '', '', '', '', 'TOTAL PRODUCT EARNINGS', number_format($prod_total, 2)]);
        fputcsv($output, []);
        fputcsv($output, ['GRAND TOTAL', '', '', '', '', '', number_format($total_earnings, 2)]);
        
        fclose($output);
        exit;
        
    } elseif ($export_type == 'pdf') {
        // PDF Export with proper formatting
        $temp_dir = __DIR__ . '/temp';
        if (!is_dir($temp_dir)) {
            mkdir($temp_dir, 0777, true);
        }
        
        $filename = 'earnings_report_' . date('Y-m-d-His') . '.pdf';
        $filepath = $temp_dir . '/' . $filename;
        
        $pdf_content = generate_pdf($eq_bookings, $prod_orders, $eq_earnings, $prod_earnings, $total_earnings);
        file_put_contents($filepath, $pdf_content);
        
        if (file_exists($filepath)) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($filepath));
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');
            
            readfile($filepath);
            unlink($filepath);
            exit;
        }
    }
}

// Function to generate PDF with proper formatting - NO OVERLAP
function generate_pdf($eq_bookings, $prod_orders, $eq_earnings, $prod_earnings, $total_earnings) {
    $eq_total = 0;
    $prod_total = 0;
    
    foreach ($eq_bookings as $b) {
        $eq_total += $b['total_amount'];
    }
    foreach ($prod_orders as $p) {
        $prod_total += $p['total_price'];
    }
    
    // Start PDF
    $pdf = "%PDF-1.4\n";
    $pdf .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >> endobj\n";
    $pdf .= "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n";
    $pdf .= "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> >> endobj\n";
    $pdf .= "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj\n";
    $pdf .= "6 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >> endobj\n";
    
    // PDF Content
    $content = "";
    
    // Title
    $content .= "BT /F2 18 Tf 50 770 Td (EARNINGS REPORT) Tj ET\n";
    $content .= "BT /F1 9 Tf 50 755 Td (Generated: " . date('Y-m-d H:i:s') . ") Tj ET\n";
    $content .= "BT /F1 9 Tf 50 743 Td (AgriRent System) Tj ET\n";
    $content .= "BT /F1 1 Tf 50 733 Td ( ) Tj ET\n"; // Space
    
    // Summary Section - Highlighted
    $content .= "BT /F2 12 Tf 50 720 Td (EARNINGS SUMMARY) Tj ET\n";
    $content .= "q 0.9 0.9 0.9 rg 40 690 532 60 re f Q\n"; // Gray background
    $content .= "BT /F1 10 Tf 50 710 Td (Equipment Earnings : Rs. " . number_format($eq_earnings, 2) . ") Tj ET\n";
    $content .= "BT /F1 10 Tf 50 695 Td (Product Sales Earnings : Rs. " . number_format($prod_earnings, 2) . ") Tj ET\n";
    $content .= "BT /F2 11 Tf 50 678 Td (TOTAL EARNINGS : Rs. " . number_format($total_earnings, 2) . ") Tj ET\n";
    $content .= "BT /F1 1 Tf 50 665 Td ( ) Tj ET\n"; // Space
    
    // Equipment Section
    $content .= "BT /F2 12 Tf 50 655 Td (EQUIPMENT RENTAL EARNINGS) Tj ET\n";
    
    // Table Headers
    $content .= "q 0.7 0.7 0.7 rg 40 638 532 12 re f Q\n"; // Dark gray header
    $content .= "BT /F2 8 Tf 45 643 Td (ID) Tj ET\n";
    $content .= "BT /F2 8 Tf 75 643 Td (Equipment) Tj ET\n";
    $content .= "BT /F2 8 Tf 170 643 Td (Brand) Tj ET\n";
    $content .= "BT /F2 8 Tf 220 643 Td (Customer) Tj ET\n";
    $content .= "BT /F2 8 Tf 320 643 Td (Start Date) Tj ET\n";
    $content .= "BT /F2 8 Tf 390 643 Td (End Date) Tj ET\n";
    $content .= "BT /F2 8 Tf 460 643 Td (Amount) Tj ET\n";
    
    // Table Data
    $y = 628;
    $row_count = 0;
    foreach ($eq_bookings as $b) {
        if ($row_count >= 8) break; // Max 8 rows per page
        
        $content .= "BT /F1 7 Tf 45 " . $y . " Td (" . $b['booking_id'] . ") Tj ET\n";
        $content .= "BT /F1 7 Tf 75 " . $y . " Td (" . substr($b['equipment_title'], 0, 12) . ") Tj ET\n";
        $content .= "BT /F1 7 Tf 170 " . $y . " Td (" . ($b['Brand'] ?? 'N/A') . ") Tj ET\n";
        $content .= "BT /F1 7 Tf 220 " . $y . " Td (" . substr($b['customer_name'], 0, 10) . ") Tj ET\n";
        $content .= "BT /F1 7 Tf 320 " . $y . " Td (" . date('m/d/Y', strtotime($b['start_date'])) . ") Tj ET\n";
        $content .= "BT /F1 7 Tf 390 " . $y . " Td (" . date('m/d/Y', strtotime($b['end_date'])) . ") Tj ET\n";
        $content .= "BT /F1 7 Tf 460 " . $y . " Td (Rs. " . number_format($b['total_amount'], 2) . ") Tj ET\n";
        $y -= 11;
        $row_count++;
    }
    
    // Equipment Total - Highlighted
    $content .= "q 0.85 0.9 0.85 rg 40 " . ($y - 2) . " 532 10 re f Q\n"; // Light green
    $content .= "BT /F2 8 Tf 320 " . $y . " Td (TOTAL EQUIPMENT) Tj ET\n";
    $content .= "BT /F2 8 Tf 460 " . $y . " Td (Rs. " . number_format($eq_total, 2) . ") Tj ET\n";
    
    $y -= 25;
    
    // Product Section
    
    $content .= "\nBT /F2 12 Tf 50 " . $y . " Td (PRODUCT SALES EARNINGS) Tj ET\n";
    $y -= 15;
    
    // Product Table Headers
    $content .= "\n\nq 0.7 0.7 0.7 rg 40 " . ($y + 3) . " 532 12 re f Q\n";
    $content .= "BT /F2 8 Tf 45 " . ($y + 8) . " Td (ID) Tj ET\n";
    $content .= "BT /F2 8 Tf 85 " . ($y + 8) . " Td (Product) Tj ET\n";
    $content .= "BT /F2 8 Tf 200 " . ($y + 8) . " Td (Buyer) Tj ET\n";
    $content .= "BT /F2 8 Tf 300 " . ($y + 8) . " Td (Phone) Tj ET\n";
    $content .= "BT /F2 8 Tf 390 " . ($y + 8) . " Td (Date) Tj ET\n";
    $content .= "BT /F2 8 Tf 460 " . ($y + 8) . " Td (Amount) Tj ET\n";
    
    $y -= 3;
    $row_count = 0;
    foreach ($prod_orders as $p) {
        if ($row_count >= 6) break; // Max 6 rows per page
        
        $content .= "BT /F1 7 Tf 45 " . $y . " Td (" . $p['Order_id'] . ") Tj ET\n";
        $content .= "BT /F1 7 Tf 85 " . $y . " Td (" . substr($p['product_name'], 0, 12) . ") Tj ET\n";
        $content .= "BT /F1 7 Tf 200 " . $y . " Td (" . substr($p['buyer_name'], 0, 10) . ") Tj ET\n";
        $content .= "BT /F1 7 Tf 300 " . $y . " Td (" . substr($p['buyer_phone'], 0, 10) . ") Tj ET\n";
        $content .= "BT /F1 7 Tf 390 " . $y . " Td (" . date('m/d/Y', strtotime($p['order_date'])) . ") Tj ET\n";
        $content .= "BT /F1 7 Tf 460 " . $y . " Td (Rs. " . number_format($p['total_price'], 2) . ") Tj ET\n";
        $y -= 11;
        $row_count++;
    }
    
    // Product Total - Highlighted
    $content .= "q 0.85 0.9 0.85 rg 40 " . ($y - 2) . " 532 10 re f Q\n";
    $content .= "BT /F2 8 Tf 300 " . $y . " Td (TOTAL PRODUCTS) Tj ET\n";
    $content .= "BT /F2 8 Tf 460 " . $y . " Td (Rs. " . number_format($prod_total, 2) . ") Tj ET\n";
    
    $pdf .= "4 0 obj\n<< /Length " . strlen($content) . " >> stream\n" . $content . "endstream\nendobj\n";
    
    $pdf .= "xref\n0 7\n";
    $pdf .= "0000000000 65535 f \n";
    $pdf .= "0000000009 00000 n \n";
    $pdf .= "0000000058 00000 n \n";
    $pdf .= "0000000115 00000 n \n";
    $pdf .= "0000000280 00000 n \n";
    $pdf .= "0000000370 00000 n \n";
    $pdf .= "0000000470 00000 n \n";
    
    $pdf .= "trailer\n<< /Size 7 /Root 1 0 R >>\nstartxref\n" . (strlen($pdf) + strlen($content) + 100) . "\n%%EOF";
    
    return $pdf;
}

require 'oheader.php';
require 'owner_nav.php';
?>

<link rel="stylesheet" href="../assets/css/equipment.css">

<style>
    .header-section {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
    }
    
    .export-btn {
        position: relative;
        display: inline-block;
    }
    
    .export-dropdown {
        display: none;
        position: absolute;
        background: white;
        min-width: 150px;
        box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2);
        padding: 0;
        z-index: 1;
        border-radius: 4px;
        top: 100%;
        right: 0;
        border: 1px solid #ddd;
    }
    
    .export-dropdown a {
        color: #333;
        padding: 12px 16px;
        text-decoration: none;
        display: block;
        cursor: pointer;
    }
    
    .export-dropdown a:first-child { border-radius: 4px 4px 0 0; }
    .export-dropdown a:last-child { border-radius: 0 0 4px 4px; }
    .export-dropdown a:hover { background: #f0f0f0; }
    
    .export-btn button {
        background: #28a745;
        color: white;
        padding: 10px 20px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-weight: bold;
    }
    
    .export-btn button:hover { background: #218838; }
    .export-btn:hover .export-dropdown { display: block; }
    
    .earnings-cards {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 15px;
        margin-bottom: 30px;
    }
    
    .earning-card {
        background: white;
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    
    .earning-card h3 { color: #666; font-size: 14px; margin-bottom: 10px; }
    .earning-card .amount { font-size: 28px; font-weight: bold; color: #28a745; }
    
    .section-title {
        color: #333;
        margin-top: 30px;
        margin-bottom: 15px;
        font-size: 18px;
        font-weight: bold;
        padding-bottom: 10px;
    }
</style>

<div class="main-content">
    <div class="header-section">
        <div>
            <h1>Earnings Report</h1>
            <p>Comprehensive analysis of your equipment rental and product sales earnings</p>
        </div>
        
        <div class="export-btn">
            <button>📥 Export Report</button>
            <div class="export-dropdown">
                <a href="?export=excel">📊 Export to Excel</a>
                <a href="?export=pdf">📄 Export to PDF</a>
            </div>
        </div>
    </div>
    
    <div class="earnings-cards">
        <div class="earning-card">
            <h3>Equipment Earnings</h3>
            <div class="amount">₹<?= number_format($eq_earnings, 2); ?></div>
            <small><?= $eq_stats['confirmed_bookings'] ?? 0; ?> Confirmed</small>
        </div>
        
        <div class="earning-card">
            <h3>Product Sales Earnings</h3>
            <div class="amount">₹<?= number_format($prod_earnings, 2); ?></div>
            <small><?= $prod_stats['completed_orders'] ?? 0; ?> Completed</small>
        </div>
        
        <div class="earning-card">
            <h3>Total Earnings</h3>
            <div class="amount">₹<?= number_format($total_earnings, 2); ?></div>
            <small>Equipment + Product</small>
        </div>
    </div>
    
    <h2 class="section-title">Equipment Rental Earnings</h2>
    <?php if (count($eq_bookings) > 0): ?>
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 30px;">
            <thead>
                <tr style="background: #f8f9fa;">
                    <th style="padding: 12px; text-align: left; border-bottom: 1px solid #ddd; font-weight: bold;">Booking ID</th>
                    <th style="padding: 12px; text-align: left; border-bottom: 1px solid #ddd; font-weight: bold;">Equipment</th>
                    <th style="padding: 12px; text-align: left; border-bottom: 1px solid #ddd; font-weight: bold;">Brand</th>
                    <th style="padding: 12px; text-align: left; border-bottom: 1px solid #ddd; font-weight: bold;">Customer</th>
                    <th style="padding: 12px; text-align: left; border-bottom: 1px solid #ddd; font-weight: bold;">Start Date</th>
                    <th style="padding: 12px; text-align: left; border-bottom: 1px solid #ddd; font-weight: bold;">End Date</th>
                    <th style="padding: 12px; text-align: left; border-bottom: 1px solid #ddd; font-weight: bold;">Amount (₹)</th>
                </tr>
            </thead>
            <tbody>
                <?php $sum = 0; foreach ($eq_bookings as $b): $sum += $b['total_amount']; ?>
                <tr>
                    <td style="padding: 12px; border-bottom: 1px solid #eee;"><?= $b['booking_id']; ?></td>
                    <td style="padding: 12px; border-bottom: 1px solid #eee;"><?= htmlspecialchars($b['equipment_title']); ?></td>
                    <td style="padding: 12px; border-bottom: 1px solid #eee;"><?= htmlspecialchars($b['Brand'] ?? 'N/A'); ?></td>
                    <td style="padding: 12px; border-bottom: 1px solid #eee;"><?= htmlspecialchars($b['customer_name']); ?></td>
                    <td style="padding: 12px; border-bottom: 1px solid #eee;"><?= date('Y-m-d', strtotime($b['start_date'])); ?></td>
                    <td style="padding: 12px; border-bottom: 1px solid #eee;"><?= date('Y-m-d', strtotime($b['end_date'])); ?></td>
                    <td style="padding: 12px; border-bottom: 1px solid #eee;"><?= number_format($b['total_amount'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
                <tr style="background: #f0f0f0; font-weight: bold;">
                    <td colspan="6" style="padding: 12px; border-bottom: 1px solid #ddd;">TOTAL EQUIPMENT EARNINGS</td>
                    <td style="padding: 12px; border-bottom: 1px solid #ddd;"><?= number_format($sum, 2); ?></td>
                </tr>
            </tbody>
        </table>
    <?php else: ?>
        <p style="text-align: center; color: #999;">No equipment earnings data</p>
    <?php endif; ?>
    
    <h2 class="section-title">Product Sales Earnings</h2>
    <?php if (count($prod_orders) > 0): ?>
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 30px;">
            <thead>
                <tr style="background: #f8f9fa;">
                    <th style="padding: 12px; text-align: left; border-bottom: 1px solid #ddd; font-weight: bold;">Order ID</th>
                    <th style="padding: 12px; text-align: left; border-bottom: 1px solid #ddd; font-weight: bold;">Product Name</th>
                    <th style="padding: 12px; text-align: left; border-bottom: 1px solid #ddd; font-weight: bold;">Buyer Name</th>
                    <th style="padding: 12px; text-align: left; border-bottom: 1px solid #ddd; font-weight: bold;">Buyer Phone</th>
                    <th style="padding: 12px; text-align: left; border-bottom: 1px solid #ddd; font-weight: bold;">Quantity</th>
                    <th style="padding: 12px; text-align: left; border-bottom: 1px solid #ddd; font-weight: bold;">Order Date</th>
                    <th style="padding: 12px; text-align: left; border-bottom: 1px solid #ddd; font-weight: bold;">Amount (₹)</th>
                </tr>
            </thead>
            <tbody>
                <?php $sum = 0; foreach ($prod_orders as $p): $sum += $p['total_price']; ?>
                <tr>
                    <td style="padding: 12px; border-bottom: 1px solid #eee;"><?= $p['Order_id']; ?></td>
                    <td style="padding: 12px; border-bottom: 1px solid #eee;"><?= htmlspecialchars($p['product_name']); ?></td>
                    <td style="padding: 12px; border-bottom: 1px solid #eee;"><?= htmlspecialchars($p['buyer_name']); ?></td>
                    <td style="padding: 12px; border-bottom: 1px solid #eee;"><?= $p['buyer_phone']; ?></td>
                    <td style="padding: 12px; border-bottom: 1px solid #eee;"><?= number_format($p['quantity'], 2); ?></td>
                    <td style="padding: 12px; border-bottom: 1px solid #eee;"><?= date('Y-m-d', strtotime($p['order_date'])); ?></td>
                    <td style="padding: 12px; border-bottom: 1px solid #eee;"><?= number_format($p['total_price'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
                <tr style="background: #f0f0f0; font-weight: bold;">
                    <td colspan="6" style="padding: 12px; border-bottom: 1px solid #ddd;">TOTAL PRODUCT EARNINGS</td>
                    <td style="padding: 12px; border-bottom: 1px solid #ddd;"><?= number_format($sum, 2); ?></td>
                </tr>
            </tbody>
        </table>
    <?php else: ?>
        <p style="text-align: center; color: #999;">No product earnings data</p>
    <?php endif; ?>
    
    <br/><br/>
</div>

<?php require 'ofooter.php'; ?>
