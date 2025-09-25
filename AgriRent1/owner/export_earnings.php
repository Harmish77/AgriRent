<?php
session_start();
require_once('../config.php');

// Check authentication
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'O') {
    header('Location: ../login.php');
    exit();
}

$owner_id = $_SESSION['user_id'];
$format = $_GET['format'] ?? 'excel';

// Get same filters from the main report
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$period = $_GET['period'] ?? 'all';

// Set date ranges based on period
if ($period === 'today') {
    $start_date = date('Y-m-d');
    $end_date = date('Y-m-d');
} elseif ($period === 'week') {
    $start_date = date('Y-m-d', strtotime('-7 days'));
    $end_date = date('Y-m-d');
} elseif ($period === 'month') {
    $start_date = date('Y-m-01');
    $end_date = date('Y-m-t');
} elseif ($period === 'year') {
    $start_date = date('Y-01-01');
    $end_date = date('Y-12-31');
}

// Build query with same filters
$where_clause = "WHERE e.Owner_id = ?";
$params = [$owner_id];
$param_types = "i";

if ($start_date) {
    $where_clause .= " AND DATE(eb.start_date) >= ?";
    $params[] = $start_date;
    $param_types .= "s";
}
if ($end_date) {
    $where_clause .= " AND DATE(eb.end_date) <= ?";
    $params[] = $end_date;
    $param_types .= "s";
}

// Fetch earnings data
$query = "SELECT eb.booking_id, e.Title as equipment_title, e.Brand, e.Model,
                 eb.start_date, eb.end_date, eb.Hours, eb.total_amount, eb.status,
                 u.Name as customer_name
          FROM equipment_bookings eb 
          JOIN equipment e ON eb.equipment_id = e.Equipment_id 
          JOIN users u ON eb.customer_id = u.user_id 
          $where_clause 
          ORDER BY eb.start_date DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param($param_types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$records = [];
$total_earnings = 0;

while ($row = $result->fetch_assoc()) {
    $records[] = $row;
    if ($row['status'] == 'CON') {
        $total_earnings += $row['total_amount'];
    }
}
$stmt->close();

// Get owner name for reports
$owner_name = $_SESSION['user_name'] ?? 'Equipment Owner';

if ($format == 'excel') {
    // Export to Excel
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=earnings_report_" . date("Y-m-d") . ".xls");
    header("Pragma: no-cache");
    header("Expires: 0");
    
    // Excel content
    echo "AgriRent - Equipment Owner Earnings Report\n";
    echo "Generated on: " . date("Y-m-d H:i:s") . "\n";
    echo "Owner: " . htmlspecialchars($owner_name) . "\n";
    
    if ($start_date && $end_date) {
        echo "Period: " . date('M j, Y', strtotime($start_date)) . " to " . date('M j, Y', strtotime($end_date)) . "\n";
    } elseif ($period != 'all') {
        echo "Period: " . ucfirst($period) . "\n";
    }
    echo "\n";
    
    // Headers
    echo "Booking ID\tEquipment Title\tBrand\tModel\tCustomer Name\tStart Date\tEnd Date\tHours\tAmount\tStatus\n";
    
    // Data rows
    foreach ($records as $rec) {
        echo $rec['booking_id'] . "\t";
        echo $rec['equipment_title'] . "\t";
        echo $rec['Brand'] . "\t";
        echo $rec['Model'] . "\t";
        echo $rec['customer_name'] . "\t";
        echo $rec['start_date'] . "\t";
        echo $rec['end_date'] . "\t";
        echo ($rec['Hours'] ?? 'N/A') . "\t";
        echo $rec['total_amount'] . "\t";
        echo ($rec['status'] == 'CON' ? 'Confirmed' : ($rec['status'] == 'PEN' ? 'Pending' : 'Rejected')) . "\n";
    }
    
    // Summary
    echo "\nSUMMARY\n";
    echo "Total Bookings:\t" . count($records) . "\n";
    echo "Confirmed Bookings:\t" . count(array_filter($records, fn($r) => $r['status'] == 'CON')) . "\n";
    echo "Total Confirmed Earnings:\t" . $total_earnings . "\n";
    
    exit();

} elseif ($format == 'pdf') {
    // For PDF, you need FPDF library
    // Create folder: owner/fpdf/
    // Download FPDF from: http://www.fpdf.org/
    // Put fpdf.php file in owner/fpdf/ folder
    
    require_once('fpdf/fpdf.php');
    
    class EarningsReport extends FPDF {
        private $owner_name;
        private $period_info;
        
        function __construct($owner_name, $period_info) {
            parent::__construct();
            $this->owner_name = $owner_name;
            $this->period_info = $period_info;
        }
        
        function Header() {
            $this->SetFont('Arial','B',16);
            $this->Cell(0,10,'AgriRent - Earnings Report',0,1,'C');
            $this->SetFont('Arial','',10);
            $this->Cell(0,10,'Owner: ' . $this->owner_name,0,1,'C');
            if ($this->period_info) {
                $this->Cell(0,10,$this->period_info,0,1,'C');
            }
            $this->Cell(0,10,'Generated on: ' . date('Y-m-d H:i:s'),0,1,'C');
            $this->Ln(10);
        }
        
        function Footer() {
            $this->SetY(-15);
            $this->SetFont('Arial','I',8);
            $this->Cell(0,10,'Page '.$this->PageNo().'/{nb}',0,0,'C');
        }
    }
    
    // Create period info for PDF
    $period_info = '';
    if ($start_date && $end_date) {
        $period_info = 'Period: ' . date('M j, Y', strtotime($start_date)) . ' to ' . date('M j, Y', strtotime($end_date));
    } elseif ($period != 'all') {
        $period_info = 'Period: ' . ucfirst($period);
    }
    
    $pdf = new EarningsReport($owner_name, $period_info);
    $pdf->AliasNbPages();
    $pdf->AddPage();
    $pdf->SetFont('Arial','B',9);
    
    // Table Header
    $pdf->Cell(20, 8, 'Booking ID', 1, 0, 'C');
    $pdf->Cell(35, 8, 'Equipment', 1, 0, 'C');
    $pdf->Cell(30, 8, 'Customer', 1, 0, 'C');
    $pdf->Cell(25, 8, 'Start Date', 1, 0, 'C');
    $pdf->Cell(25, 8, 'End Date', 1, 0, 'C');
    $pdf->Cell(15, 8, 'Hours', 1, 0, 'C');
    $pdf->Cell(25, 8, 'Amount', 1, 0, 'C');
    $pdf->Cell(20, 8, 'Status', 1, 1, 'C');
    
    $pdf->SetFont('Arial','',8);
    
    // Table Data
    foreach ($records as $rec) {
        // Check if we need a new page
        if ($pdf->GetY() > 250) {
            $pdf->AddPage();
            // Re-add header
            $pdf->SetFont('Arial','B',9);
            $pdf->Cell(20, 8, 'Booking ID', 1, 0, 'C');
            $pdf->Cell(35, 8, 'Equipment', 1, 0, 'C');
            $pdf->Cell(30, 8, 'Customer', 1, 0, 'C');
            $pdf->Cell(25, 8, 'Start Date', 1, 0, 'C');
            $pdf->Cell(25, 8, 'End Date', 1, 0, 'C');
            $pdf->Cell(15, 8, 'Hours', 1, 0, 'C');
            $pdf->Cell(25, 8, 'Amount', 1, 0, 'C');
            $pdf->Cell(20, 8, 'Status', 1, 1, 'C');
            $pdf->SetFont('Arial','',8);
        }
        
        $pdf->Cell(20, 6, '#' . $rec['booking_id'], 1, 0, 'C');
        $pdf->Cell(35, 6, substr($rec['equipment_title'], 0, 20), 1, 0, 'L');
        $pdf->Cell(30, 6, substr($rec['customer_name'], 0, 18), 1, 0, 'L');
        $pdf->Cell(25, 6, date('M j, Y', strtotime($rec['start_date'])), 1, 0, 'C');
        $pdf->Cell(25, 6, date('M j, Y', strtotime($rec['end_date'])), 1, 0, 'C');
        $pdf->Cell(15, 6, ($rec['Hours'] ?? 'N/A'), 1, 0, 'C');
        $pdf->Cell(25, 6, '₹' . number_format($rec['total_amount'], 2), 1, 0, 'R');
        
        $status_text = $rec['status'] == 'CON' ? 'Confirmed' : ($rec['status'] == 'PEN' ? 'Pending' : 'Rejected');
        $pdf->Cell(20, 6, $status_text, 1, 1, 'C');
    }
    
    // Summary
    $pdf->Ln(5);
    $pdf->SetFont('Arial','B',10);
    $pdf->Cell(0, 8, 'SUMMARY', 0, 1, 'L');
    $pdf->SetFont('Arial','',9);
    $pdf->Cell(0, 6, 'Total Bookings: ' . count($records), 0, 1, 'L');
    $pdf->Cell(0, 6, 'Confirmed Bookings: ' . count(array_filter($records, fn($r) => $r['status'] == 'CON')), 0, 1, 'L');
    $pdf->Cell(0, 6, 'Total Confirmed Earnings: ₹' . number_format($total_earnings, 2), 0, 1, 'L');
    
    $pdf->Output('I', 'Earnings_Report_' . date('Ymd') . '.pdf');
    exit();
}

// If format not recognized, redirect back
header('Location: earnings_report.php');
exit();
?>
