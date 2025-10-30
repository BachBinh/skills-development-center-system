<?php
require_once(__DIR__ . '/../../../config/db_connection.php'); 

$currentYear = date('Y');
$currentMonth = date('m');
$currentQuarter = ceil($currentMonth / 3);
$filterType = $_GET['filter_type'] ?? 'month'; 
$filterYear = $_GET['filter_year'] ?? $currentYear;
$filterMonth = $_GET['filter_month'] ?? $currentMonth;
$filterQuarter = $_GET['filter_quarter'] ?? $currentQuarter;
$startDate = $_GET['start_date'] ?? null;
$endDate = $_GET['end_date'] ?? null;

$dateConditionReg = ""; 
$dateConditionPayment = ""; 
$dateConditionEval = ""; 
$reportTitle = "Tháng $currentMonth/$currentYear"; 

$paramsReg = []; $typesReg = "";
$paramsPayment = []; $typesPayment = "";
$paramsEval = []; $typesEval = "";

switch ($filterType) {
    case 'month':
        $startDate = date('Y-m-01', strtotime("$filterYear-$filterMonth-01"));
        $endDate = date('Y-m-t', strtotime("$filterYear-$filterMonth-01"));
        $reportTitle = "Tháng $filterMonth/$filterYear";
        break;
    case 'quarter':
        $startMonth = ($filterQuarter - 1) * 3 + 1;
        $endMonth = $startMonth + 2;
        $startDate = date('Y-m-01', strtotime("$filterYear-$startMonth-01"));
        $endDate = date('Y-m-t', strtotime("$filterYear-$endMonth-01"));
        $reportTitle = "Quý $filterQuarter/$filterYear";
        break;
    case 'year':
        $startDate = "$filterYear-01-01";
        $endDate = "$filterYear-12-31";
        $reportTitle = "Năm $filterYear";
        break;
    case 'custom':
        $startDate = !empty($startDate) ? date('Y-m-d', strtotime($startDate)) : null;
        $endDate = !empty($endDate) ? date('Y-m-d', strtotime($endDate)) : null;
         if ($startDate && $endDate) {
             $reportTitle = "Từ " . date('d/m/Y', strtotime($startDate)) . " đến " . date('d/m/Y', strtotime($endDate));
         } elseif ($startDate) {
             $reportTitle = "Từ " . date('d/m/Y', strtotime($startDate));
         } elseif ($endDate) {
              $reportTitle = "Đến " . date('d/m/Y', strtotime($endDate));
         } else {
             $filterType = 'all';
         }
        break;
    case 'all':
    default:
        $startDate = null;
        $endDate = null;
        $reportTitle = "Toàn bộ thời gian";
        break;
}

if ($startDate && $endDate) {
    $dateConditionReg = " WHERE r.RegisteredAt BETWEEN ? AND ?";
    $dateConditionPayment = " WHERE p.PaidAt BETWEEN ? AND ?";
    $dateConditionEval = " WHERE ev.EvalDate BETWEEN ? AND ?";
    $paramsReg = $paramsPayment = $paramsEval = [$startDate . ' 00:00:00', $endDate . ' 23:59:59']; 
    $typesReg = $typesPayment = $typesEval = "ss"; 
} elseif ($startDate) {
     $dateConditionReg = " WHERE r.RegisteredAt >= ?";
     $dateConditionPayment = " WHERE p.PaidAt >= ?";
     $dateConditionEval = " WHERE ev.EvalDate >= ?";
     $paramsReg = $paramsPayment = $paramsEval = [$startDate . ' 00:00:00'];
     $typesReg = $typesPayment = $typesEval = "s";
} elseif ($endDate) {
     $dateConditionReg = " WHERE r.RegisteredAt <= ?";
     $dateConditionPayment = " WHERE p.PaidAt <= ?";
     $dateConditionEval = " WHERE ev.EvalDate <= ?";
     $paramsReg = $paramsPayment = $paramsEval = [$endDate . ' 23:59:59'];
     $typesReg = $typesPayment = $typesEval = "s";
}


function executeAndFetch($conn, $sql, $params, $types, $columnName, $defaultValue = 0) {
    try {
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            $value = $result->fetch_assoc()[$columnName] ?? $defaultValue;
            $stmt->close();
            return $value;
        }
    } catch (Exception $e) {
    }
    return $defaultValue;
}

$sqlTotalReg = "SELECT COUNT(*) AS total FROM registration r" . $dateConditionReg;
$totalRegistrations = executeAndFetch($conn, $sqlTotalReg, $paramsReg, $typesReg, 'total');

$sqlTotalRevenue = "SELECT SUM(p.Amount) AS total FROM payment p" . $dateConditionPayment . (empty($dateConditionPayment) ? " WHERE p.Status = 'paid'" : " AND p.Status = 'paid'");
$totalRevenue = executeAndFetch($conn, $sqlTotalRevenue, $paramsPayment, $typesPayment, 'total');

$sqlAvgRating = "SELECT AVG(ev.Rating) AS avg FROM evaluation ev" . $dateConditionEval;
$avgRating = executeAndFetch($conn, $sqlAvgRating, $paramsEval, $typesEval, 'avg');

$sqlActiveStudents = "SELECT COUNT(*) AS total FROM user WHERE Role = 'student' AND Status = 'active'";
$activeStudents = executeAndFetch($conn, $sqlActiveStudents, [], '', 'total');

$sqlActiveInstructors = "SELECT COUNT(*) AS total FROM user WHERE Role = 'instructor' AND Status = 'active'";
$activeInstructors = executeAndFetch($conn, $sqlActiveInstructors, [], '', 'total');

// --- Dữ liệu cho Biểu đồ ---

// 1. Doanh thu theo Ngày/Tháng (Tùy thuộc vào khoảng thời gian lọc)
$revenueChartData = ['labels' => [], 'data' => []];
$dateFormatGroup = '%Y-%m-%d'; // Mặc định group theo ngày
$sqlRevenueOverTime = "SELECT DATE_FORMAT(p.PaidAt, ?) AS period, SUM(p.Amount) AS daily_revenue 
                       FROM payment p";
$sqlRevenueOverTime .= $dateConditionPayment . (empty($dateConditionPayment) ? " WHERE p.Status = 'paid'" : " AND p.Status = 'paid'");

// Điều chỉnh GROUP BY và Format dựa trên khoảng thời gian
if ($filterType == 'year' || ($filterType == 'custom' && (strtotime($endDate ?? date('Y-m-d')) - strtotime($startDate ?? '1970-01-01')) / (60*60*24) > 90) || $filterType == 'all') {
     $dateFormatGroup = '%Y-%m'; // Group theo tháng nếu khoảng thời gian dài
} elseif ($filterType == 'quarter') {
     $dateFormatGroup = '%Y-%m'; // Group theo tháng cho quý
}

$sqlRevenueOverTime .= " GROUP BY period ORDER BY period ASC";

$stmtRevTime = $conn->prepare($sqlRevenueOverTime);
if ($stmtRevTime) {
    $paramsRevTime = array_merge([$dateFormatGroup], $paramsPayment); // Thêm format vào đầu params
    $typesRevTime = "s" . $typesPayment; // 
    if (!empty($paramsPayment)) {
        $stmtRevTime->bind_param($typesRevTime, ...$paramsRevTime);
    } else {
         $stmtRevTime->bind_param("s", $dateFormatGroup); 
    }
    $stmtRevTime->execute();
    $resultRevTime = $stmtRevTime->get_result();
    while ($row = $resultRevTime->fetch_assoc()) {
        $revenueChartData['labels'][] = $row['period'];
        $revenueChartData['data'][] = (float)$row['daily_revenue'];
    }
    $stmtRevTime->close();
}

// 2. Top 5 khóa học phổ biến nhất (dựa trên lượt đăng ký trong khoảng thời gian lọc)
$popularCoursesData = ['labels' => [], 'data' => []];
$sqlPopularCourses = "SELECT c.Title, COUNT(r.RegistrationID) AS reg_count 
                      FROM registration r 
                      JOIN course c ON r.CourseID = c.CourseID";
$sqlPopularCourses .= $dateConditionReg; // Áp dụng bộ lọc ngày
$sqlPopularCourses .= " GROUP BY r.CourseID, c.Title ORDER BY reg_count DESC LIMIT 5"; // top 5

$stmtPopCourse = $conn->prepare($sqlPopularCourses);
if ($stmtPopCourse) {
     if (!empty($paramsReg)) {
         $stmtPopCourse->bind_param($typesReg, ...$paramsReg);
     }
     $stmtPopCourse->execute();
     $resultPopCourse = $stmtPopCourse->get_result();
     while($row = $resultPopCourse->fetch_assoc()) {
         $popularCoursesData['labels'][] = $row['Title'];
         $popularCoursesData['data'][] = (int)$row['reg_count'];
     }
     $stmtPopCourse->close();
}

// 3. Đăng ký mới 7 ngày qua
$sevenDaysAgo = date('Y-m-d', strtotime('-6 days')); // Bao gồm cả ngày hôm nay
$sqlRegLast7Days = "SELECT COUNT(*) as total FROM registration WHERE RegisteredAt >= ?";
$regLast7Days = executeAndFetch($conn, $sqlRegLast7Days, [$sevenDaysAgo . ' 00:00:00'], 's', 'total');

// --- Lấy Dữ liệu Thống kê Lương (Lọc theo kỳ lương - PayPeriod) ---
$totalPayrollNet = 0;
$totalPayrollGross = 0;
$payrollByRoleData = ['labels' => ['Nhân viên (Staff)', 'Giảng viên (Instructor)'], 'data' => [0, 0]];
$totalFinalizedPayslips = 0; // Số phiếu lương đã chốt

if ($filterType !== 'all' && $startDate && $endDate) { // Chỉ tính lương theo kỳ nếu có khoảng thời gian cụ thể
    // Xác định PayPeriod từ $startDate (Ví dụ: Lấy YYYY-MM)
    $payPeriodForStats = date('Y-m', strtotime($startDate));

    // Tổng lương thực nhận (Net) trong kỳ (chỉ tính phiếu đã chốt hoặc đã trả)
    $sqlTotalNet = "SELECT SUM(NetSalary) AS total FROM payroll WHERE PayPeriod = ? AND Status IN ('Finalized', 'Paid')";
    $totalPayrollNet = executeAndFetch($conn, $sqlTotalNet, [$payPeriodForStats], 's', 'total');

    // Tổng lương gộp (Gross) trong kỳ (tính cả phiếu chưa chốt để xem chi phí dự kiến)
    $sqlTotalGross = "SELECT SUM(GrossSalary) AS total FROM payroll WHERE PayPeriod = ?";
    $totalPayrollGross = executeAndFetch($conn, $sqlTotalGross, [$payPeriodForStats], 's', 'total');

    // Số phiếu lương đã chốt trong kỳ
    $sqlFinalizedCount = "SELECT COUNT(*) AS total FROM payroll WHERE PayPeriod = ? AND Status = 'Finalized'";
    $totalFinalizedPayslips = executeAndFetch($conn, $sqlFinalizedCount, [$payPeriodForStats], 's', 'total');

    // Phân bổ lương gộp theo vai trò
    $sqlPayrollRole = "SELECT u.Role, SUM(p.GrossSalary) as total_gross
                       FROM payroll p
                       JOIN user u ON p.UserID = u.UserID
                       WHERE p.PayPeriod = ?
                       GROUP BY u.Role";
    $stmtPayrollRole = $conn->prepare($sqlPayrollRole);
    if ($stmtPayrollRole) {
        $stmtPayrollRole->bind_param("s", $payPeriodForStats);
        $stmtPayrollRole->execute();
        $resultPayrollRole = $stmtPayrollRole->get_result();
        while($row = $resultPayrollRole->fetch_assoc()) {
            if ($row['Role'] === 'staff') {
                $payrollByRoleData['data'][0] = (float)$row['total_gross'];
            } elseif ($row['Role'] === 'instructor') {
                $payrollByRoleData['data'][1] = (float)$row['total_gross'];
            }
        }
        $stmtPayrollRole->close();
    }
} else {
    // Nếu lọc "Toàn bộ thời gian", các thống kê lương theo kỳ này sẽ không có ý nghĩa
    $reportTitle .= " (Không áp dụng Thống kê Lương theo kỳ)";
}

?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<style>
    .border-left-primary { border-left: .25rem solid #4e73df !important; }
    .border-left-success { border-left: .25rem solid #1cc88a !important; }
    .border-left-info { border-left: .25rem solid #36b9cc !important; }
    .border-left-warning { border-left: .25rem solid #f6c23e !important; }
    .border-left-danger { border-left: .25rem solid #e74a3b !important; }
    .border-left-secondary { border-left: .25rem solid #858796 !important; }
    .text-xs { font-size: .8rem; }
    .text-gray-300 { color: #dddfeb !important; }
    .text-gray-800 { color: #5a5c69 !important; }
    .font-weight-bold { font-weight: 700 !important; }
    .no-gutters { margin-right: 0; margin-left: 0; }
    .no-gutters > .col, .no-gutters > [class*="col-"] { padding-right: 0; padding-left: 0; }
    .card .card-body { padding: 1.0rem 1.25rem; } /* Giảm padding dọc */
    .h-100 { height: 100% !important; }
    .shadow { box-shadow: 0 .15rem 1.75rem 0 rgba(58,59,69,.15) !important; }
    .icon-circle {
        height: 2.5rem;
        width: 2.5rem;
        border-radius: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 1rem;
    }
    #filter-form label { font-size: 0.9em; margin-bottom: 0.2rem;}
    #filter-form .form-control, #filter-form .form-select { font-size: 0.9em; }
</style>

<div class="container-fluid">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800"><i class="fas fa-tachometer-alt me-2"></i>Dashboard Thống Kê</h1>
        <!-- SỬA LẠI NÚT NÀY -->
        <button type="button" class="btn btn-sm btn-success shadow-sm" data-bs-toggle="modal" data-bs-target="#exportOptionsModal">
            <i class="fas fa-file-excel fa-sm text-white-50 me-1"></i> Xuất Excel
        </button>
        <!-- KẾT THÚC SỬA -->
    </div>

    <!-- Bộ lọc thời gian -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-filter me-2"></i>Bộ lọc thời gian</h6>
        </div>
        <div class="card-body">
            <form id="filter-form" method="GET" class="row g-3 align-items-end">
                <input type="hidden" name="page" value="Statistics"> <!-- Hoặc tên page của bạn -->
                <div class="col-md-2">
                    <label for="filter_type" class="form-label">Lọc theo</label>
                    <select name="filter_type" id="filter_type" class="form-select form-select-sm">
                        <option value="month" <?= $filterType == 'month' ? 'selected' : '' ?>>Tháng</option>
                        <option value="quarter" <?= $filterType == 'quarter' ? 'selected' : '' ?>>Quý</option>
                        <option value="year" <?= $filterType == 'year' ? 'selected' : '' ?>>Năm</option>
                        <option value="custom" <?= $filterType == 'custom' ? 'selected' : '' ?>>Tùy chỉnh</option>
                        <option value="all" <?= $filterType == 'all' ? 'selected' : '' ?>>Toàn bộ</option>
                    </select>
                </div>

                <!-- Các ô lọc chi tiết (ẩn/hiện bằng JS) -->
                <div class="col-md-2 filter-option" id="month-filter" style="<?= $filterType != 'month' ? 'display: none;' : '' ?>">
                    <label for="filter_month" class="form-label">Chọn tháng</label>
                    <select name="filter_month" id="filter_month" class="form-select form-select-sm">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= str_pad($m, 2, '0', STR_PAD_LEFT) ?>" <?= $filterMonth == $m ? 'selected' : '' ?>><?= "Tháng " . $m ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-2 filter-option" id="quarter-filter" style="<?= $filterType != 'quarter' ? 'display: none;' : '' ?>">
                     <label for="filter_quarter" class="form-label">Chọn quý</label>
                     <select name="filter_quarter" id="filter_quarter" class="form-select form-select-sm">
                         <option value="1" <?= $filterQuarter == 1 ? 'selected' : '' ?>>Quý 1</option>
                         <option value="2" <?= $filterQuarter == 2 ? 'selected' : '' ?>>Quý 2</option>
                         <option value="3" <?= $filterQuarter == 3 ? 'selected' : '' ?>>Quý 3</option>
                         <option value="4" <?= $filterQuarter == 4 ? 'selected' : '' ?>>Quý 4</option>
                     </select>
                </div>
                 <div class="col-md-2 filter-option" id="year-filter" style="<?= !in_array($filterType, ['month', 'quarter', 'year']) ? 'display: none;' : '' ?>">
                    <label for="filter_year" class="form-label">Chọn năm</label>
                    <select name="filter_year" id="filter_year" class="form-select form-select-sm">
                        <?php for ($y = $currentYear; $y >= $currentYear - 5; $y--): // Lấy 5 năm gần nhất ?>
                            <option value="<?= $y ?>" <?= $filterYear == $y ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="col-md-3 filter-option" id="custom-range-filter" style="<?= $filterType != 'custom' ? 'display: none;' : '' ?>">
                    <label class="form-label">Khoảng ngày</label>
                    <div class="input-group input-group-sm">
                         <input type="text" name="start_date" id="start_date" class="form-control flatpickr-date" placeholder="Từ ngày" value="<?= htmlspecialchars($startDate ?? '') ?>">
                         <input type="text" name="end_date" id="end_date" class="form-control flatpickr-date" placeholder="Đến ngày" value="<?= htmlspecialchars($endDate ?? '') ?>">
                    </div>
                </div>

                <div class="col-md-1">
                     <button type="submit" class="btn btn-sm btn-primary w-100"><i class="fas fa-check"></i> Lọc</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Hàng thẻ thống kê tổng quan -->
    <h5 class="mb-3 text-gray-600">Thống kê cho: <span class="text-primary fw-bold"><?= $reportTitle ?></span></h5>
    <div class="row g-3 mb-4"> 
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="card border-left-info shadow h-100">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Lượt đăng ký</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= number_format($totalRegistrations) ?></div>
                        </div>
                         <div class="col-auto"><i class="fas fa-clipboard-list fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <!-- Tổng Doanh Thu (Lọc) -->
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="card border-left-success shadow h-100">
                 <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Doanh thu (Đã TT)</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= number_format($totalRevenue, 0, ',', '.') ?> <small>VNĐ</small></div>
                        </div>
                         <div class="col-auto"><i class="fas fa-dollar-sign fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <!-- Đánh giá TB (Lọc) -->
        <div class="col-xl-2 col-md-4 col-sm-6">
             <div class="card border-left-danger shadow h-100">
                 <div class="card-body">
                     <div class="row no-gutters align-items-center">
                         <div class="col">
                            <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Đánh giá TB</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $avgRating > 0 ? number_format($avgRating, 1) : 'N/A' ?> <small>/ 5</small></div>
                        </div>
                        <div class="col-auto"><i class="fas fa-star-half-alt fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="card border-left-primary shadow h-100">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Tổng Lương Gộp (Kỳ)</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= ($filterType !== 'all') ? number_format($totalPayrollGross, 0, ',', '.') . ' <small>VNĐ</small>' : 'N/A' ?></div>
                        </div>
                        <div class="col-auto"><i class="fas fa-money-bill-wave fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="card border-left-success shadow h-100">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Tổng Lương Net (Kỳ)</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= ($filterType !== 'all') ? number_format($totalPayrollNet, 0, ',', '.') . ' <small>VNĐ</small>' : 'N/A' ?></div>
                        </div>
                        <div class="col-auto"><i class="fas fa-hand-holding-usd fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="card border-left-warning shadow h-100">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Phiếu lương đã chốt (Kỳ)</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= ($filterType !== 'all') ? number_format($totalFinalizedPayslips) : 'N/A' ?></div>
                        </div>
                        <div class="col-auto"><i class="fas fa-user-check fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-5 mb-4">
        <div class="card shadow h-100">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-pie-chart me-2"></i>Phân bổ Chi phí Lương Gộp (<?= $reportTitle ?>)</h6>
            </div>
            <div class="card-body">
                 <?php if ($filterType !== 'all' && ($payrollByRoleData['data'][0] > 0 || $payrollByRoleData['data'][1] > 0) ): ?>
                    <div class="chart-pie pt-4 pb-2" style="height: 300px;">
                        <canvas id="payrollRoleChart"></canvas>
                    </div>
                    <div class="mt-4 text-center small">
                        <span class="me-2"><i class="fas fa-circle text-primary"></i> Staff</span>
                        <span><i class="fas fa-circle text-warning"></i> Instructor</span>
                    </div>
                <?php else: ?>
                     <p class="text-center text-muted mt-5">Không có dữ liệu lương để hiển thị phân bổ trong kỳ này.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

        <!-- ĐK 7 ngày qua (Không lọc) -->
         <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="card border-left-secondary shadow h-100">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col">
                            <div class="text-xs font-weight-bold text-secondary text-uppercase mb-1">ĐK (7 ngày qua)</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= number_format($regLast7Days) ?></div>
                        </div>
                         <div class="col-auto"><i class="fas fa-calendar-day fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <!-- Học viên Active (Không lọc) -->
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="card border-left-primary shadow h-100">
                 <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">HV hoạt động</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= number_format($activeStudents) ?></div>
                        </div>
                         <div class="col-auto"><i class="fas fa-users fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>
        <!-- Giảng viên Active (Không lọc) -->
        <div class="col-xl-2 col-md-4 col-sm-6">
            <div class="card border-left-warning shadow h-100">
                 <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">GV hoạt động</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= number_format($activeInstructors) ?></div>
                        </div>
                         <div class="col-auto"><i class="fas fa-chalkboard-teacher fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Hàng chứa Biểu đồ -->
    <div class="row">
        <!-- Biểu đồ Doanh thu theo thời gian -->
        <div class="col-lg-7 mb-4">
            <div class="card shadow h-100">
                <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                    <h6 class="m-0 font-weight-bold text-success"><i class="fas fa-chart-area me-2"></i>Biểu đồ Doanh thu (<?= $reportTitle ?>)</h6>
                </div>
                <div class="card-body">
                    <div class="chart-area" style="height: 300px;">
                        <canvas id="revenueChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Biểu đồ Top Khóa học phổ biến -->
        <div class="col-lg-5 mb-4">
            <div class="card shadow h-100">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-info"><i class="fas fa-award me-2"></i>Top 5 Khóa học phổ biến (<?= $reportTitle ?>)</h6>
                </div>
                <div class="card-body">
                     <?php if (!empty($popularCoursesData['labels'])): ?>
                        <div class="chart-bar" style="height: 300px;">
                            <canvas id="popularCoursesChart"></canvas>
                        </div>
                    <?php else: ?>
                         <p class="text-center text-muted mt-5">Không có dữ liệu đăng ký khóa học trong khoảng thời gian này.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <!-- Modal Chọn Nội dung và Thời gian Xuất Excel -->
    <div class="modal fade" id="exportOptionsModal" tabindex="-1" aria-labelledby="exportOptionsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg"> <!-- Thêm modal-lg để rộng hơn -->
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="exportOptionsModalLabel"><i class="fas fa-file-excel me-2"></i>Tùy chọn Xuất Báo cáo Excel</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-3">Chọn khoảng thời gian và các phần dữ liệu bạn muốn bao gồm trong file Excel.</p>

                    <!-- BỘ LỌC THỜI GIAN TRONG MODAL -->
                    <h6 class="text-primary mb-2"><i class="fas fa-calendar-alt me-2"></i>Chọn Khoảng Thời Gian</h6>
                    <div class="row g-3 mb-4 border p-3 rounded bg-light">
                        <div class="col-md-4">
                            <label for="modal_filter_type" class="form-label form-label-sm">Lọc theo</label>
                            <select name="modal_filter_type" id="modal_filter_type" class="form-select form-select-sm">
                                <option value="month">Tháng</option>
                                <option value="quarter">Quý</option>
                                <option value="year">Năm</option>
                                <option value="custom">Tùy chỉnh</option>
                                <option value="all">Toàn bộ</option>
                            </select>
                        </div>
                        <div class="col-md-4 modal-filter-option" id="modal-month-filter">
                            <label for="modal_filter_month" class="form-label form-label-sm">Chọn tháng</label>
                            <select name="modal_filter_month" id="modal_filter_month" class="form-select form-select-sm">
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?= str_pad($m, 2, '0', STR_PAD_LEFT) ?>"><?= "Tháng " . $m ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-4 modal-filter-option" id="modal-quarter-filter" style="display: none;">
                            <label for="modal_filter_quarter" class="form-label form-label-sm">Chọn quý</label>
                            <select name="modal_filter_quarter" id="modal_filter_quarter" class="form-select form-select-sm">
                                <option value="1">Quý 1</option> <option value="2">Quý 2</option> <option value="3">Quý 3</option> <option value="4">Quý 4</option>
                            </select>
                        </div>
                        <div class="col-md-4 modal-filter-option" id="modal-year-filter">
                            <label for="modal_filter_year" class="form-label form-label-sm">Chọn năm</label>
                            <select name="modal_filter_year" id="modal_filter_year" class="form-select form-select-sm">
                                <?php for ($y = $currentYear; $y >= $currentYear - 5; $y--): ?>
                                    <option value="<?= $y ?>"><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-8 modal-filter-option" id="modal-custom-range-filter" style="display: none;">
                            <label class="form-label form-label-sm">Khoảng ngày tùy chỉnh</label>
                            <div class="input-group input-group-sm">
                                <input type="text" name="modal_start_date" id="modal_start_date" class="form-control flatpickr-date-modal" placeholder="Từ ngày">
                                <input type="text" name="modal_end_date" id="modal_end_date" class="form-control flatpickr-date-modal" placeholder="Đến ngày">
                            </div>
                        </div>
                    </div>
                    <!-- KẾT THÚC BỘ LỌC THỜI GIAN -->

                    <hr>

                    <!-- CHỌN NỘI DUNG XUẤT -->
                    <h6 class="text-primary mb-2 mt-3"><i class="fas fa-list-check me-2"></i>Chọn Nội dung Xuất</h6>
                    <div class="form-check mb-2">
                        <input class="form-check-input export-option" type="checkbox" value="overview" id="exportOverview" checked>
                        <label class="form-check-label" for="exportOverview">Thống kê tổng quan</label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input export-option" type="checkbox" value="revenue_detail" id="exportRevenueDetail" checked>
                        <label class="form-check-label" for="exportRevenueDetail">Doanh thu chi tiết theo thời gian</label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input export-option" type="checkbox" value="popular_courses" id="exportPopularCourses" checked>
                        <label class="form-check-label" for="exportPopularCourses">Top 5 Khóa học phổ biến</label>
                    </div>
                    <!-- Thêm các tùy chọn khác nếu muốn -->
                    <div id="exportErrorMsg" class="text-danger small mt-2" style="display: none;">Vui lòng chọn ít nhất một mục để xuất.</div>
                    <!-- KẾT THÚC CHỌN NỘI DUNG -->

                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="button" class="btn btn-success" id="confirmExportBtn"><i class="fas fa-check me-1"></i> Xác nhận Xuất</button>
                </div>
            </div>
        </div>
    </div>
    <!-- Modal Chọn Nội dung và Thời gian Xuất Excel -->
    <div class="modal fade" id="exportOptionsModal" ...>
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <!-- ... modal header ... -->
                <div class="modal-body">
                    <!-- ... Phần lọc thời gian ... -->
                    <hr>
                    <!-- CHỌN NỘI DUNG XUẤT -->
                    <h6 class="text-primary mb-2 mt-3"><i class="fas fa-list-check me-2"></i>Chọn Nội dung Xuất</h6>
                    <div class="form-check mb-2">
                        <input class="form-check-input export-option" type="checkbox" value="overview" id="exportOverview" checked>
                        <label class="form-check-label" for="exportOverview">Thống kê tổng quan</label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input export-option" type="checkbox" value="revenue_detail" id="exportRevenueDetail" checked>
                        <label class="form-check-label" for="exportRevenueDetail">Doanh thu chi tiết theo thời gian</label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input export-option" type="checkbox" value="popular_courses" id="exportPopularCourses" checked>
                        <label class="form-check-label" for="exportPopularCourses">Top 5 Khóa học phổ biến</label>
                    </div>
                    <!-- ===== THÊM OPTION XUẤT LƯƠNG ===== -->
                    <div class="form-check mb-2">
                        <input class="form-check-input export-option" type="checkbox" value="payroll_detail" id="exportPayrollDetail">
                        <label class="form-check-label" for="exportPayrollDetail">
                            Chi tiết Bảng lương Kỳ đã chọn
                        </label>
                    </div>
                    <!-- ================================= -->
                    <div id="exportErrorMsg" class="text-danger small mt-2" style="display: none;">...</div>
                </div>
                <!-- ... modal footer ... -->
            </div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://npmcdn.com/flatpickr/dist/l10n/vn.js"></script> 

<script>
document.addEventListener('DOMContentLoaded', function() {

    // --- Khởi tạo Biểu đồ ---
    Chart.defaults.font.family = '"Nunito", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif';
    Chart.defaults.font.size = 12;
    Chart.defaults.color = '#858796';
    Chart.defaults.scale.grid.color = '#e3e6f0';
    Chart.defaults.scale.grid.borderColor = '#e3e6f0';
    Chart.defaults.scale.ticks.color = '#858796';

    // 1. Biểu đồ Doanh thu
    const revenueCtx = document.getElementById('revenueChart');
    if (revenueCtx) {
        const revenueChartData = <?= json_encode($revenueChartData) ?>;
        new Chart(revenueCtx, {
            type: 'line',
            data: {
                labels: revenueChartData.labels,
                datasets: [{
                    label: "Doanh thu",
                    data: revenueChartData.data,
                    fill: true, 
                    borderColor: 'rgb(28, 200, 138)', // xlc
                    backgroundColor: 'rgba(28, 200, 138, 0.1)', 
                    tension: 0.3, 
                    pointBackgroundColor: 'rgb(28, 200, 138)',
                    pointBorderColor: '#fff',
                    pointHoverRadius: 5,
                    pointHoverBackgroundColor: 'rgb(28, 200, 138)',
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value, index, values) {
                                // Định dạng tiền tệ VNĐ
                                return new Intl.NumberFormat('vi-VN').format(value) + ' VNĐ';
                            }
                        }
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) { label += ': '; }
                                if (context.parsed.y !== null) {
                                     label += new Intl.NumberFormat('vi-VN').format(context.parsed.y) + ' VNĐ';
                                }
                                return label;
                            }
                        }
                    }
                }
            }
        });
    }

    // 2. Biểu đồ Khóa học phổ biến
    const popularCoursesCtx = document.getElementById('popularCoursesChart');
    if (popularCoursesCtx) {
         const popularCoursesData = <?= json_encode($popularCoursesData) ?>;
         if (popularCoursesData.labels && popularCoursesData.labels.length > 0) {
             new Chart(popularCoursesCtx, {
                type: 'bar', // hoặc 'pie', 'doughnut'
                data: {
                    labels: popularCoursesData.labels,
                    datasets: [{
                        label: 'Số lượt đăng ký',
                        data: popularCoursesData.data,
                        backgroundColor: [ // Mảng màu cho từng cột/phần
                            'rgba(54, 162, 235, 0.6)',
                            'rgba(255, 206, 86, 0.6)',
                            'rgba(75, 192, 192, 0.6)',
                            'rgba(153, 102, 255, 0.6)',
                            'rgba(255, 159, 64, 0.6)'
                        ],
                        borderColor: [
                            'rgba(54, 162, 235, 1)',
                            'rgba(255, 206, 86, 1)',
                            'rgba(75, 192, 192, 1)',
                            'rgba(153, 102, 255, 1)',
                            'rgba(255, 159, 64, 1)'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                     responsive: true,
                     maintainAspectRatio: false,
                     indexAxis: 'y', 
                     scales: {
                        x: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false 
                        }
                    }
                }
            });
         }
    }

    // 3. Biểu đồ Phân bổ Lương theo Vai trò
    const payrollRoleCtx = document.getElementById('payrollRoleChart');
    if (payrollRoleCtx) {
        const payrollRoleData = <?= json_encode($payrollByRoleData) ?>;
        // Chỉ vẽ nếu có dữ liệu > 0
        if (payrollRoleData.data && (payrollRoleData.data[0] > 0 || payrollRoleData.data[1] > 0)) {
            new Chart(payrollRoleCtx, {
                type: 'doughnut', // hoặc 'pie'
                data: {
                    labels: payrollRoleData.labels,
                    datasets: [{
                        data: payrollRoleData.data,
                        backgroundColor: ['#4e73df', '#f6c23e'], // Màu primary và warning
                        hoverBackgroundColor: ['#2e59d9', '#dda20a'],
                        hoverBorderColor: "rgba(234, 236, 244, 1)",
                    }],
                },
                options: {
                    maintainAspectRatio: false,
                    responsive: true,
                    plugins: {
                        legend: {
                            display: false // Đã có chú thích riêng bên dưới
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.label || '';
                                    let value = context.parsed || 0;
                                    if (label) { label += ': '; }
                                    label += new Intl.NumberFormat('vi-VN').format(value) + ' VNĐ';
                                    return label;
                                },
                                // (Optional) Hiển thị %
                                // footer: function(tooltipItems) {
                                //     let sum = 0;
                                //     let dataArr = tooltipItems[0].chart.data.datasets[0].data;
                                //     dataArr.map(data => { sum += data; });
                                //     let percentage = (tooltipItems[0].parsed * 100 / sum).toFixed(2) + '%';
                                //     return 'Tỷ lệ: ' + percentage;
                                // }
                            }
                        }
                    },
                    cutout: '70%', // Cho biểu đồ doughnut
                },
            });
        }
    }


flatpickr(".flatpickr-date", { dateFormat: "Y-m-d", locale: "vn" });
// Khởi tạo riêng cho modal để tránh xung đột nếu cần
flatpickr(".flatpickr-date-modal", { dateFormat: "Y-m-d", locale: "vn" });


// --- Xử lý hiển thị bộ lọc chi tiết TRÊN TRANG CHÍNH (Giữ nguyên) ---
const filterTypeSelect = document.getElementById('filter_type');
const filterOptions = document.querySelectorAll('.filter-option:not(.modal-filter-option)'); // Loại trừ của modal

function toggleFilterOptions() {
    const selectedType = filterTypeSelect.value;
    filterOptions.forEach(option => { option.style.display = 'none'; });
    if (selectedType === 'month') { document.getElementById('month-filter').style.display = 'block'; document.getElementById('year-filter').style.display = 'block'; }
    else if (selectedType === 'quarter') { document.getElementById('quarter-filter').style.display = 'block'; document.getElementById('year-filter').style.display = 'block'; }
    else if (selectedType === 'year') { document.getElementById('year-filter').style.display = 'block'; }
    else if (selectedType === 'custom') { document.getElementById('custom-range-filter').style.display = 'block'; }
}
if(filterTypeSelect) { filterTypeSelect.addEventListener('change', toggleFilterOptions); toggleFilterOptions(); }


// --- Xử lý hiển thị bộ lọc chi tiết TRONG MODAL ---
const modalFilterTypeSelect = document.getElementById('modal_filter_type');
const modalFilterOptions = document.querySelectorAll('.modal-filter-option');

function toggleModalFilterOptions() {
    const selectedType = modalFilterTypeSelect.value;
    modalFilterOptions.forEach(option => { option.style.display = 'none'; });
    if (selectedType === 'month') { document.getElementById('modal-month-filter').style.display = 'block'; document.getElementById('modal-year-filter').style.display = 'block'; }
    else if (selectedType === 'quarter') { document.getElementById('modal-quarter-filter').style.display = 'block'; document.getElementById('modal-year-filter').style.display = 'block'; }
    else if (selectedType === 'year') { document.getElementById('modal-year-filter').style.display = 'block'; }
    else if (selectedType === 'custom') { document.getElementById('modal-custom-range-filter').style.display = 'block'; }
}
if(modalFilterTypeSelect) { modalFilterTypeSelect.addEventListener('change', toggleModalFilterOptions); /* Không cần gọi lần đầu */ }


// --- Xử lý Modal và Nút Xác nhận Xuất Excel ---
const exportOptionsModalElement = document.getElementById('exportOptionsModal');
const confirmExportBtn = document.getElementById('confirmExportBtn');
const exportErrorMsg = document.getElementById('exportErrorMsg');

if (exportOptionsModalElement && confirmExportBtn) {
    const exportOptionsModal = new bootstrap.Modal(exportOptionsModalElement);

    // Sự kiện khi modal được mở -> Lấy giá trị lọc hiện tại trên trang gán vào modal
    exportOptionsModalElement.addEventListener('show.bs.modal', () => {
        document.getElementById('modal_filter_type').value = document.getElementById('filter_type').value;
        document.getElementById('modal_filter_year').value = document.getElementById('filter_year').value;
        document.getElementById('modal_filter_month').value = document.getElementById('filter_month').value;
        document.getElementById('modal_filter_quarter').value = document.getElementById('filter_quarter').value;
        const mainStartDate = document.getElementById('start_date').value;
        const mainEndDate = document.getElementById('end_date').value;
        flatpickr("#modal_start_date", {defaultDate: mainStartDate, dateFormat: "Y-m-d", locale: "vn"});
        flatpickr("#modal_end_date", {defaultDate: mainEndDate, dateFormat: "Y-m-d", locale: "vn"});

        toggleModalFilterOptions(); // Cập nhật hiển thị bộ lọc trong modal
        if(exportErrorMsg) exportErrorMsg.style.display = 'none'; // Ẩn lỗi cũ
        document.querySelectorAll('.export-option').forEach(cb => cb.checked = true);
    });


    confirmExportBtn.addEventListener('click', function() {
        const selectedSections = [];
        document.querySelectorAll('.export-option:checked').forEach(checkbox => {
            selectedSections.push(checkbox.value);
        });

        if (selectedSections.length === 0) {
            if(exportErrorMsg) exportErrorMsg.style.display = 'block';
            return;
        } else {
             if(exportErrorMsg) exportErrorMsg.style.display = 'none';
        }

        // Lấy giá trị lọc TỪ TRONG MODAL
        const filterType = document.getElementById('modal_filter_type').value;
        const filterYear = document.getElementById('modal_filter_year').value;
        const filterMonth = document.getElementById('modal_filter_month').value;
        const filterQuarter = document.getElementById('modal_filter_quarter').value;
        const startDate = document.getElementById('modal_start_date').value;
        const endDate = document.getElementById('modal_end_date').value;

        const absolutePath = '/thiendinhsystem/admin/includes/content/export_statistics.php'; 
        const exportUrl = new URL(absolutePath, window.location.origin);

        // Thêm các tham số lọc thời gian từ modal
        exportUrl.searchParams.set('filter_type', filterType);
        if (filterYear && (filterType === 'month' || filterType === 'quarter' || filterType === 'year')) exportUrl.searchParams.set('filter_year', filterYear); else exportUrl.searchParams.delete('filter_year');
        if (filterMonth && filterType === 'month') exportUrl.searchParams.set('filter_month', filterMonth); else exportUrl.searchParams.delete('filter_month');
        if (filterQuarter && filterType === 'quarter') exportUrl.searchParams.set('filter_quarter', filterQuarter); else exportUrl.searchParams.delete('filter_quarter');
        if (startDate && filterType === 'custom') exportUrl.searchParams.set('start_date', startDate); else exportUrl.searchParams.delete('start_date');
        if (endDate && filterType === 'custom') exportUrl.searchParams.set('end_date', endDate); else exportUrl.searchParams.delete('end_date');

        selectedSections.forEach(section => {
            exportUrl.searchParams.append('sections[]', section);
        });

        console.log("Final Export URL:", exportUrl.toString());

        exportOptionsModal.hide();

        window.location.href = exportUrl.toString();
    });

     document.querySelectorAll('.export-option').forEach(checkbox => {
        checkbox.addEventListener('change', () => {
            if(exportErrorMsg) exportErrorMsg.style.display = 'none';
        });
     });

} else {
    console.error("Modal or Confirm button for export not found.");
}


    });
</script>