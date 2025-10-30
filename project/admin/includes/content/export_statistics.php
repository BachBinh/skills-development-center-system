<?php
// admin/includes/content/export_statistics.php

// --- Bắt đầu Session và Kiểm tra quyền Admin ---
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("HTTP/1.1 403 Forbidden");
    // Có thể hiển thị trang lỗi thân thiện hơn
    exit("Lỗi: Bạn không có quyền truy cập chức năng này.");
}

// --- Include thư viện PhpSpreadsheet và Kết nối DB ---
require_once(__DIR__ . '/../../../vendor/autoload.php'); // *** Đảm bảo đường dẫn đúng ***
require_once(__DIR__ . '/../../../config/db_connection.php'); // *** Đảm bảo đường dẫn đúng ***

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\DataType; // Để định dạng số

date_default_timezone_set('Asia/Ho_Chi_Minh');

// --- Lấy và Xử lý Bộ lọc thời gian (Lấy từ tham số GET) ---
$currentYear = date('Y');
$currentMonth = date('m');
$currentQuarter = ceil($currentMonth / 3);

// Lấy filter từ $_GET (do JS gửi đến)
$filterType = $_GET['filter_type'] ?? 'month';
$filterYear = $_GET['filter_year'] ?? $currentYear;
$filterMonth = $_GET['filter_month'] ?? $currentMonth;
$filterQuarter = $_GET['filter_quarter'] ?? $currentQuarter;
$startDateParam = $_GET['start_date'] ?? null;
$endDateParam = $_GET['end_date'] ?? null;

// --- Lấy các section được chọn để xuất ---
$selectedSections = $_GET['sections'] ?? []; // Lấy mảng các section
if (empty($selectedSections) || !is_array($selectedSections)) {
     // Nếu không có section nào được chọn hoặc không phải mảng, dừng lại
     exit("Lỗi: Vui lòng chọn ít nhất một mục nội dung để xuất báo cáo.");
}


$dateConditionReg = "";
$dateConditionPayment = "";
$dateConditionEval = "";
$reportTitle = "Thang{$currentMonth}-{$currentYear}"; // Tiêu đề mặc định

$paramsReg = []; $typesReg = "";
$paramsPayment = []; $typesPayment = "";
$paramsEval = []; $typesEval = "";

// --- Xác định khoảng ngày và title báo cáo DỰA TRÊN FILTER TRONG GET ---
// (Logic switch case và if/elseif tạo $dateCondition..., $params..., $types... giống hệt code cũ)
$startDate = null; // Khởi tạo
$endDate = null;   // Khởi tạo
switch ($filterType) {
    case 'month':
        $startDate = date('Y-m-01', strtotime("$filterYear-$filterMonth-01"));
        $endDate = date('Y-m-t', strtotime("$filterYear-$filterMonth-01"));
        $reportTitle = "Thang{$filterMonth}-{$filterYear}";
        break;
    case 'quarter':
        $startMonth = ($filterQuarter - 1) * 3 + 1; $endMonth = $startMonth + 2;
        $startDate = date('Y-m-01', strtotime("$filterYear-$startMonth-01"));
        $endDate = date('Y-m-t', strtotime("$filterYear-$endMonth-01"));
        $reportTitle = "Quy{$filterQuarter}-{$filterYear}";
        break;
    case 'year':
        $startDate = "$filterYear-01-01"; $endDate = "$filterYear-12-31";
        $reportTitle = "Nam{$filterYear}";
        break;
    case 'custom':
        $startDate = !empty($startDateParam) ? date('Y-m-d', strtotime($startDateParam)) : null;
        $endDate = !empty($endDateParam) ? date('Y-m-d', strtotime($endDateParam)) : null;
        if ($startDate && $endDate) { $reportTitle = "Tu" . date('Ymd', strtotime($startDate)) . "Den" . date('Ymd', strtotime($endDate)); }
        elseif ($startDate) { $reportTitle = "Tu" . date('Ymd', strtotime($startDate)); }
        elseif ($endDate) { $reportTitle = "Den" . date('Ymd', strtotime($endDate)); }
        else { $filterType = 'all'; /* Fallback */ }
        break;
    case 'all': default: $startDate = null; $endDate = null; $reportTitle = "ToanBoThoiGian"; break;
}
// Tạo mệnh đề WHERE dựa trên $startDate, $endDate đã xác định
if ($startDate && $endDate) { $dateConditionReg = " WHERE r.RegisteredAt BETWEEN ? AND ?"; $dateConditionPayment = " WHERE p.PaidAt BETWEEN ? AND ?"; $dateConditionEval = " WHERE ev.EvalDate BETWEEN ? AND ?"; $paramsReg = $paramsPayment = $paramsEval = [$startDate . ' 00:00:00', $endDate . ' 23:59:59']; $typesReg = $typesPayment = $typesEval = "ss"; }
elseif ($startDate) { $dateConditionReg = " WHERE r.RegisteredAt >= ?"; $dateConditionPayment = " WHERE p.PaidAt >= ?"; $dateConditionEval = " WHERE ev.EvalDate >= ?"; $paramsReg = $paramsPayment = $paramsEval = [$startDate . ' 00:00:00']; $typesReg = $typesPayment = $typesEval = "s"; }
elseif ($endDate) { $dateConditionReg = " WHERE r.RegisteredAt <= ?"; $dateConditionPayment = " WHERE p.PaidAt <= ?"; $dateConditionEval = " WHERE ev.EvalDate <= ?"; $paramsReg = $paramsPayment = $paramsEval = [$endDate . ' 23:59:59']; $typesReg = $typesPayment = $typesEval = "s"; }

// --- Hàm trợ giúp executeAndFetch (Giữ nguyên) ---
function executeAndFetch($conn, $sql, $params, $types, $columnName, $defaultValue = 0) {
    try { $stmt = $conn->prepare($sql); if ($stmt) { if (!empty($params)) { $stmt->bind_param($types, ...$params); } $stmt->execute(); $result = $stmt->get_result(); $row = $result->fetch_assoc(); $value = isset($row[$columnName]) ? $row[$columnName] : $defaultValue; $stmt->close(); return $value === null ? $defaultValue : $value; } } catch (Exception $e) { error_log("executeAndFetch Error: " . $e->getMessage() . " SQL: " . $sql); } return $defaultValue;
}

// --- Tạo đối tượng Spreadsheet ---
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('BaoCaoThongKe');

// --- Định nghĩa Styles (Giữ nguyên) ---
$titleStyle = [ 'font' => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FF000080']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER] ];
$headerStyle = [ 'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF4F81BD']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER], 'borders' => ['outline' => ['borderStyle' => Border::BORDER_THIN]] ];
$sectionTitleStyle = [ 'font' => ['bold' => true, 'size' => 12, 'color' => ['argb' => 'FF1F497D']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER] ];
$allBorders = [ 'borders' => [ 'allBorders' => [ 'borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFBFBFBF'], ], ], ]; // Border nhạt hơn
$numberFormat = '#,##0'; $currencyFormat = '#,##0 "VNĐ"'; $ratingFormat = '0.0" / 5"';

// --- Biến theo dõi dòng hiện tại ---
$rowNum = 1;

// --- Ghi Tiêu đề Báo cáo ---
$sheet->mergeCells('A'.$rowNum.':D'.$rowNum);
$sheet->setCellValue('A'.$rowNum, 'BÁO CÁO THỐNG KÊ - HỆ THỐNG KỸ NĂNG PRO');
$sheet->getStyle('A'.$rowNum)->applyFromArray($titleStyle); $sheet->getRowDimension($rowNum)->setRowHeight(20);
$rowNum++;
$reportPeriodText = "Kỳ báo cáo: " . ($filterType === 'all' ? "Toàn bộ thời gian" : ($startDate ? date('d/m/Y', strtotime($startDate)) : '') . ($startDate && $endDate ? ' - ' : '') . ($endDate ? date('d/m/Y', strtotime($endDate)) : ''));
$sheet->mergeCells('A'.$rowNum.':D'.$rowNum);
$sheet->setCellValue('A'.$rowNum, $reportPeriodText);
$sheet->getStyle('A'.$rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$rowNum += 2;

// --- Ghi các Section được chọn ---

// Section 1: Thống kê tổng quan
if (in_array('overview', $selectedSections)) {
    // Fetch data
    $sqlTotalReg = "SELECT COUNT(*) AS total FROM registration r" . $dateConditionReg; $totalRegistrations = executeAndFetch($conn, $sqlTotalReg, $paramsReg, $typesReg, 'total');
    $sqlTotalRevenue = "SELECT SUM(p.Amount) AS total FROM payment p" . $dateConditionPayment . (empty($dateConditionPayment) ? " WHERE p.Status = 'paid'" : " AND p.Status = 'paid'"); $totalRevenue = executeAndFetch($conn, $sqlTotalRevenue, $paramsPayment, $typesPayment, 'total');
    $sqlAvgRating = "SELECT AVG(ev.Rating) AS avg FROM evaluation ev" . $dateConditionEval; $avgRating = executeAndFetch($conn, $sqlAvgRating, $paramsEval, $typesEval, 'avg', null);
    $sqlActiveStudents = "SELECT COUNT(*) AS total FROM user WHERE Role = 'student' AND Status = 'active'"; $activeStudents = executeAndFetch($conn, $sqlActiveStudents, [], '', 'total');
    $sqlActiveInstructors = "SELECT COUNT(*) AS total FROM user WHERE Role = 'instructor' AND Status = 'active'"; $activeInstructors = executeAndFetch($conn, $sqlActiveInstructors, [], '', 'total');
    $sevenDaysAgo = date('Y-m-d', strtotime('-6 days')); $sqlRegLast7Days = "SELECT COUNT(*) as total FROM registration WHERE RegisteredAt >= ?"; $regLast7Days = executeAndFetch($conn, $sqlRegLast7Days, [$sevenDaysAgo . ' 00:00:00'], 's', 'total');

    $startRowSection = $rowNum;
    $sheet->mergeCells('A'.$rowNum.':B'.$rowNum);
    $sheet->setCellValue('A'.$rowNum, 'THỐNG KÊ TỔNG QUAN');
    $sheet->getStyle('A'.$rowNum)->applyFromArray($sectionTitleStyle);
    $rowNum++;
    $sheet->setCellValue('A'.$rowNum, 'Chỉ số')->getStyle('A'.$rowNum)->applyFromArray($headerStyle);
    $sheet->setCellValue('B'.$rowNum, 'Giá trị')->getStyle('B'.$rowNum)->applyFromArray($headerStyle);
    $rowNum++;

    $overviewData = [
        ['Tổng lượt đăng ký (trong kỳ)', $totalRegistrations, $numberFormat],
        ['Tổng doanh thu (trong kỳ - Đã TT)', $totalRevenue, $currencyFormat],
        ['Đánh giá trung bình (trong kỳ)', $avgRating, $ratingFormat],
        ['Đăng ký mới (7 ngày qua)', $regLast7Days, $numberFormat],
        ['Số học viên đang hoạt động', $activeStudents, $numberFormat],
        ['Số giảng viên đang hoạt động', $activeInstructors, $numberFormat],
    ];

    foreach ($overviewData as $dataRow) {
        $sheet->setCellValue('A'.$rowNum, $dataRow[0]);
        if ($dataRow[0] === 'Đánh giá trung bình (trong kỳ)' && $dataRow[1] === null) {
             $sheet->setCellValue('B'.$rowNum, 'N/A');
        } else {
            $sheet->setCellValueExplicit('B'.$rowNum, $dataRow[1], is_numeric($dataRow[1]) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
            if (is_numeric($dataRow[1])) {
                $sheet->getStyle('B'.$rowNum)->getNumberFormat()->setFormatCode($dataRow[2]);
            }
        }
         $sheet->getStyle('B'.$rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT); // Căn phải cho giá trị
        $rowNum++;
    }
    $sheet->getStyle('A'.($startRowSection+1).':B'.($rowNum-1))->applyFromArray($allBorders); // Áp border từ header
     $sheet->getColumnDimension('A')->setAutoSize(true); // Tự chỉnh rộng cột A
     $sheet->getColumnDimension('B')->setWidth(20); // Set chiều rộng cột B
    $rowNum++; // Dòng trống
}

// Section 2: Doanh thu chi tiết
if (in_array('revenue_detail', $selectedSections)) {
    // Fetch data
    $revenueData = []; $dateFormatGroup = '%Y-%m-%d';
    if ($filterType == 'year' || ($filterType == 'custom' && (strtotime($endDate ?? date('Y-m-d')) - strtotime($startDate ?? '1970-01-01')) / (60*60*24) > 90) || $filterType == 'all') { $dateFormatGroup = '%Y-%m'; } elseif ($filterType == 'quarter') { $dateFormatGroup = '%Y-%m'; }
    $sqlRevenueOverTime = "SELECT DATE_FORMAT(p.PaidAt, ?) AS period, SUM(p.Amount) AS revenue FROM payment p"; $sqlRevenueOverTime .= $dateConditionPayment . (empty($dateConditionPayment) ? " WHERE p.Status = 'paid'" : " AND p.Status = 'paid'"); $sqlRevenueOverTime .= " GROUP BY period ORDER BY period ASC";
    $stmtRevTime = $conn->prepare($sqlRevenueOverTime);
    if ($stmtRevTime) { $paramsRevTime = array_merge([$dateFormatGroup], $paramsPayment); $typesRevTime = "s" . $typesPayment; if (!empty($paramsPayment)) { $stmtRevTime->bind_param($typesRevTime, ...$paramsRevTime); } else { $stmtRevTime->bind_param("s", $dateFormatGroup); } $stmtRevTime->execute(); $resultRevTime = $stmtRevTime->get_result(); while ($row = $resultRevTime->fetch_assoc()) { $revenueData[] = $row; } $stmtRevTime->close(); }

    if (!empty($revenueData)) {
        $startRowSection = $rowNum;
        $sheet->mergeCells('A'.$rowNum.':B'.$rowNum);
        $sheet->setCellValue('A'.$rowNum, 'DOANH THU CHI TIẾT THEO THỜI GIAN (' . $reportPeriodText . ')');
        $sheet->getStyle('A'.$rowNum)->applyFromArray($sectionTitleStyle);
        $rowNum++;
        $sheet->setCellValue('A'.$rowNum, 'Kỳ')->getStyle('A'.$rowNum)->applyFromArray($headerStyle);
        $sheet->setCellValue('B'.$rowNum, 'Doanh thu (VNĐ)')->getStyle('B'.$rowNum)->applyFromArray($headerStyle);
        $rowNum++;
        foreach ($revenueData as $data) {
            $sheet->setCellValue('A'.$rowNum, $data['period']);
            $sheet->setCellValueExplicit('B'.$rowNum, $data['revenue'], DataType::TYPE_NUMERIC);
            $sheet->getStyle('B'.$rowNum)->getNumberFormat()->setFormatCode($currencyFormat);
            $sheet->getStyle('B'.$rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $rowNum++;
        }
        $sheet->getStyle('A'.($startRowSection+1).':B'.($rowNum-1))->applyFromArray($allBorders);
        $sheet->getColumnDimension('A')->setAutoSize(true);
        $sheet->getColumnDimension('B')->setWidth(20);
        $rowNum++;
    }
}

// Section 3: Top khóa học
if (in_array('popular_courses', $selectedSections)) {
    // Fetch data
    $popularCoursesData = [];
    $sqlPopularCourses = "SELECT c.Title, COUNT(r.RegistrationID) AS reg_count FROM registration r JOIN course c ON r.CourseID = c.CourseID"; $sqlPopularCourses .= $dateConditionReg; $sqlPopularCourses .= " GROUP BY r.CourseID, c.Title ORDER BY reg_count DESC LIMIT 5";
    $stmtPopCourse = $conn->prepare($sqlPopularCourses);
    if ($stmtPopCourse) { if (!empty($paramsReg)) { $stmtPopCourse->bind_param($typesReg, ...$paramsReg); } $stmtPopCourse->execute(); $resultPopCourse = $stmtPopCourse->get_result(); while($row = $resultPopCourse->fetch_assoc()) { $popularCoursesData[] = $row; } $stmtPopCourse->close(); }

    if (!empty($popularCoursesData)) {
        $startRowSection = $rowNum;
        $sheet->mergeCells('A'.$rowNum.':B'.$rowNum);
        $sheet->setCellValue('A'.$rowNum, 'TOP 5 KHÓA HỌC PHỔ BIẾN (' . $reportPeriodText . ')');
        $sheet->getStyle('A'.$rowNum)->applyFromArray($sectionTitleStyle);
        $rowNum++;
        $sheet->setCellValue('A'.$rowNum, 'Tên khóa học')->getStyle('A'.$rowNum)->applyFromArray($headerStyle);
        $sheet->setCellValue('B'.$rowNum, 'Số lượt đăng ký')->getStyle('B'.$rowNum)->applyFromArray($headerStyle);
        $rowNum++;
        foreach ($popularCoursesData as $data) {
            $sheet->setCellValue('A'.$rowNum, $data['Title']);
            $sheet->setCellValueExplicit('B'.$rowNum, $data['reg_count'], DataType::TYPE_NUMERIC);
            $sheet->getStyle('B'.$rowNum)->getNumberFormat()->setFormatCode($numberFormat);
            $sheet->getStyle('B'.$rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $rowNum++;
        }
        $sheet->getStyle('A'.($startRowSection+1).':B'.($rowNum-1))->applyFromArray($allBorders);
        $sheet->getColumnDimension('A')->setAutoSize(true);
        $sheet->getColumnDimension('B')->setWidth(20);
        $rowNum++;
    }
}

// --- Đóng kết nối CSDL ---
$conn->close();

// --- Thiết lập Headers và Xuất file Excel ---
$filename = "ThongKe_" . preg_replace('/[^A-Za-z0-9\-_]/', '', $reportTitle) . "_" . date('Ymd_His') . ".xlsx";

ob_end_clean(); // Xóa bỏ mọi output đã được buffer trước đó

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Cache-Control: max-age=1');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
header('Cache-Control: cache, must-revalidate');
header('Pragma: public');

$writer = new Xlsx($spreadsheet);
try {
    $writer->save('php://output');
} catch (\PhpOffice\PhpSpreadsheet\Writer\Exception $e) {
    error_log("Error saving spreadsheet: " . $e->getMessage());
    // Tránh gửi thêm header nếu đã gửi
    if (!headers_sent()) {
        header("HTTP/1.1 500 Internal Server Error");
    }
    echo "Lỗi khi tạo file Excel. Vui lòng kiểm tra log lỗi.";
}
exit();

?>