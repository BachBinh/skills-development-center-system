<?php

if (session_status() == PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo "<div class='alert alert-danger'>Bạn không có quyền truy cập trang này.</div>";
    exit();
}

require_once(__DIR__ . '/../../../config/db_connection.php');
date_default_timezone_set('Asia/Ho_Chi_Minh');

$config_message = ''; $config_message_type = '';
$calc_message = ''; $calc_message_type = '';
$finalize_message = ''; $finalize_message_type = '';
$page_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_config'])) {
    $staff_fixed_salary = filter_input(INPUT_POST, 'staff_fixed_salary', FILTER_VALIDATE_FLOAT);
    $instructor_hourly_rate = filter_input(INPUT_POST, 'instructor_hourly_rate', FILTER_VALIDATE_FLOAT);
    $valid = true;
    if ($staff_fixed_salary === false || $staff_fixed_salary < 0) { $config_message = "Lương Staff không hợp lệ."; $config_message_type = 'danger'; $valid = false; }
    if ($instructor_hourly_rate === false || $instructor_hourly_rate < 0) { $config_message = "Lương Instructor không hợp lệ."; $config_message_type = 'danger'; $valid = false; }

    if ($valid) {
        $conn->begin_transaction();
        try {
            // Staff
            $stmt_staff = $conn->prepare("INSERT INTO salary_config (Role, Amount, EffectiveDate) VALUES ('staff', ?, CURDATE()) ON DUPLICATE KEY UPDATE Amount = ?"); // Thêm EffectiveDate nếu có
            if (!$stmt_staff) throw new Exception("Lỗi chuẩn bị cấu hình Staff: " . $conn->error);
            $stmt_staff->bind_param("dd", $staff_fixed_salary, $staff_fixed_salary);
            if (!$stmt_staff->execute()) throw new Exception("Lỗi lưu cấu hình Staff: " . $stmt_staff->error);
            $stmt_staff->close();
            // Instructor
            $stmt_instructor = $conn->prepare("INSERT INTO salary_config (Role, Amount, EffectiveDate) VALUES ('instructor', ?, CURDATE()) ON DUPLICATE KEY UPDATE Amount = ?"); // Thêm EffectiveDate nếu có
            if (!$stmt_instructor) throw new Exception("Lỗi chuẩn bị cấu hình Instructor: " . $conn->error);
            $stmt_instructor->bind_param("dd", $instructor_hourly_rate, $instructor_hourly_rate);
            if (!$stmt_instructor->execute()) throw new Exception("Lỗi lưu cấu hình Instructor: " . $stmt_instructor->error);
            $stmt_instructor->close();
            $conn->commit();
            $config_message = "Đã cập nhật cấu hình lương thành công!"; $config_message_type = 'success';
        } catch (Exception $e) { $conn->rollback(); $config_message = "Lỗi: " . $e->getMessage(); $config_message_type = 'danger'; error_log("Salary Config Save Error: " . $e->getMessage()); }
    }
}


// --- XỬ LÝ TÍNH LƯƠNG ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['calculate_payroll'])) {
    $pay_period_month = isset($_POST['pay_period_month']) ? intval($_POST['pay_period_month']) : 0;
    $pay_period_year = isset($_POST['pay_period_year']) ? intval($_POST['pay_period_year']) : 0;

    if ($pay_period_month <= 0 || $pay_period_month > 12 || $pay_period_year < 2020 || $pay_period_year > (date('Y') + 1)) {
        $calc_message = "Lỗi: Vui lòng chọn kỳ lương hợp lệ.";
        $calc_message_type = 'danger';
    } else {
        $payPeriod = sprintf('%04d-%02d', $pay_period_year, $pay_period_month); 
        $periodStartDate = $payPeriod . '-01';
        $periodEndDate = date('Y-m-t', strtotime($periodStartDate)); // Ngày cuối cùng của tháng
        $calculationSuccess = true; // Cờ kiểm tra lỗi trong vòng lặp
        $processedCount = 0;      // Đếm số người được tính lương

        $conn->begin_transaction();
        try {
            $config = [];
            $result_cfg = $conn->query("SELECT Role, Amount FROM salary_config WHERE Role IN ('staff', 'instructor')"); // Lấy config mới nhất (cần logic phức tạp hơn nếu có EffectiveDate)
            if ($result_cfg) {
                while ($row = $result_cfg->fetch_assoc()) {
                    $config[$row['Role']] = $row['Amount'];
                }
            }
            // Kiểm tra có đủ config không
            if (!isset($config['staff']) || !isset($config['instructor'])) {
                throw new Exception("Chưa cấu hình đủ mức lương cho Staff và Instructor.");
            }

            // --- Lấy danh sách user Staff/Instructor đang active ---
             $stmt_users = $conn->prepare("SELECT u.UserID, u.Role, i.InstructorID
                                        FROM user u
                                        LEFT JOIN instructor i ON u.UserID = i.UserID
                                        WHERE u.Status = 'active' AND u.Role IN ('staff', 'instructor')");
            if (!$stmt_users) {
                throw new Exception("Lỗi khi chuẩn bị lấy danh sách nhân viên/giảng viên: " . $conn->error);
            }
            $stmt_users->execute();
            $result_users = $stmt_users->get_result();
            if ($result_users->num_rows === 0) {
                 throw new Exception("Không có nhân viên hoặc giảng viên nào đang hoạt động để tính lương.");
            }


            // Lấy tổng giờ dạy của Instructor trong kỳ
            $stmt_get_hours = $conn->prepare("SELECT StartTime, EndTime FROM attendance WHERE InstructorID = ? AND Date BETWEEN ? AND ?");
            if (!$stmt_get_hours) {
                throw new Exception("Lỗi chuẩn bị câu lệnh lấy giờ dạy: " . $conn->error);
            }

            // INSERT hoặc UPDATE bản ghi payroll
            $stmt_upsert_payroll = $conn->prepare("
                INSERT INTO payroll (
                    UserID, PayPeriod, PeriodStartDate, PeriodEndDate, SalaryType,
                    FixedSalary, TotalTeachingHours, HourlyRate, GrossSalary,
                    Bonuses, IncomeTaxDeduction, SocialInsuranceDeduction, HealthInsuranceDeduction, UnemploymentInsuranceDeduction, OtherDeductions,
                    NetSalary, CalculationDate, Status, Notes
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'Calculated', ?)
                ON DUPLICATE KEY UPDATE
                    PeriodStartDate = VALUES(PeriodStartDate), PeriodEndDate = VALUES(PeriodEndDate), SalaryType = VALUES(SalaryType),
                    FixedSalary = VALUES(FixedSalary), TotalTeachingHours = VALUES(TotalTeachingHours), HourlyRate = VALUES(HourlyRate),
                    GrossSalary = VALUES(GrossSalary), Bonuses = VALUES(Bonuses), IncomeTaxDeduction = VALUES(IncomeTaxDeduction),
                    SocialInsuranceDeduction = VALUES(SocialInsuranceDeduction), HealthInsuranceDeduction = VALUES(HealthInsuranceDeduction),
                    UnemploymentInsuranceDeduction = VALUES(UnemploymentInsuranceDeduction), OtherDeductions = VALUES(OtherDeductions),
                    NetSalary = VALUES(NetSalary), CalculationDate = NOW(), Status = 'Calculated',
                    FinalizedByUserID = NULL, FinalizedDate = NULL, PaymentDate = NULL, Notes = VALUES(Notes)
            ");
             if (!$stmt_upsert_payroll) {
                throw new Exception("Lỗi chuẩn bị câu lệnh lưu lương: " . $conn->error);
            }


            // --- Lặp qua từng User để tính lương ---
            while ($user = $result_users->fetch_assoc()) {
                $userId = $user['UserID'];
                $role = $user['Role'];
                $instructorId = $user['InstructorID']; // Sẽ là NULL nếu là Staff

                $salaryType = ($role === 'staff') ? 'fixed' : 'hourly';
                $fixedSalary = null;
                $totalHours = null;
                $hourlyRate = null;
                $grossSalary = 0;
                $bonuses = 0; // Reset cho mỗi người
                $notes = ''; // Reset ghi chú

                if ($role === 'staff') {
                    $fixedSalary = $config['staff'];
                    $grossSalary = $fixedSalary;
                    $notes = 'Lương cơ bản tháng ' . $pay_period_month . '/' . $pay_period_year;
                } else { // Instructor
                    $hourlyRate = $config['instructor'];
                    $totalSeconds = 0;

                    if ($instructorId) { // Chỉ tính giờ nếu là instructor hợp lệ
                        $stmt_get_hours->bind_param("iss", $instructorId, $periodStartDate, $periodEndDate);
                        $stmt_get_hours->execute();
                        $result_hours = $stmt_get_hours->get_result();
                        while ($att = $result_hours->fetch_assoc()) {
                             if ($att['StartTime'] && $att['EndTime']) {
                                try {
                                    $start = new DateTime($att['StartTime']);
                                    $end = new DateTime($att['EndTime']);
                                    if ($end > $start) {
                                        $diff = $end->getTimestamp() - $start->getTimestamp();
                                        $totalSeconds += $diff;
                                    }
                                } catch (Exception $timeEx) {
                                    error_log("Lỗi tính thời gian cho User $userId, Date " . ($att['Date'] ?? 'N/A') . ": " . $timeEx->getMessage());
                                    // Có thể bỏ qua hoặc đánh dấu lỗi ở đây
                                }
                            }
                        } 
                        $stmt_get_hours->free_result();
                    } 

                    $totalHours = round($totalSeconds / 3600, 2); // Giờ = Giây / 3600
                    $grossSalary = $totalHours * $hourlyRate;
                    $notes = 'Lương theo ' . $totalHours . ' giờ dạy thực tế.';
                    if ($totalHours == 0) {
                         $notes = 'Không có giờ dạy trong kỳ.';
                    }

                }

                //Tính Thuế TNCN và BHXH
                $incomeTax = 0; $socialInsurance = 0; $healthInsurance = 0; $unemploymentInsurance = 0;
                $taxableIncome = $grossSalary + $bonuses; // Thu nhập chịu thuế (chưa trừ BHXH, giảm trừ)

                // 1. Tính BHXH, BHYT, BHTN
                 $maxSalaryForSI = 36000000; // Cần cập nhật theo luật
                 $salaryForSI = min($grossSalary, $maxSalaryForSI);

                 $socialInsurance = round($salaryForSI * 0.08);    // 8%
                 $healthInsurance = round($salaryForSI * 0.015);   // 1.5%
                 // BHTN có thể theo vùng lương tối thiểu, tạm tính trên lương gộp
                 $unemploymentInsurance = round($grossSalary * 0.01); // 1%

                 // 2. Tính Thuế TNCN
                 // Giảm trừ bản thân: 11.000.000 VND
                 // Giảm trừ người phụ thuộc: 4.400.000 VND/người
                 $personalDeduction = 11000000;
                 $dependentDeduction = 0;
                 $insuranceDeductionsTotal = $socialInsurance + $healthInsurance + $unemploymentInsurance;

                 $incomeBeforeTax = $taxableIncome - $insuranceDeductionsTotal - $personalDeduction - $dependentDeduction;

                if ($incomeBeforeTax > 0) {
                    if ($incomeBeforeTax <= 5000000) { $incomeTax = round($incomeBeforeTax * 0.05); }
                    elseif ($incomeBeforeTax <= 10000000) { $incomeTax = round(250000 + ($incomeBeforeTax - 5000000) * 0.10); }
                    elseif ($incomeBeforeTax <= 18000000) { $incomeTax = round(750000 + ($incomeBeforeTax - 10000000) * 0.15); }
                    else { $incomeTax = round(1950000 + ($incomeBeforeTax - 18000000) * 0.20); } // Ví dụ bậc 20%
                } else {
                    $incomeTax = 0;
                }

                // --- Tính Lương Thực nhận ---
                $otherDeductions = 0; // Các khoản trừ khác (nếu có)
                $totalDeductions = $incomeTax + $socialInsurance + $healthInsurance + $unemploymentInsurance + $otherDeductions;
                $netSalary = $grossSalary + $bonuses - $totalDeductions;
                if ($netSalary < 0) $netSalary = 0; // Đảm bảo lương không âm

                // --- Lưu vào bảng payroll ---
                // Bind các giá trị đã tính toán
                $stmt_upsert_payroll->bind_param("issssddddddddddds",
                    $userId, $payPeriod, $periodStartDate, $periodEndDate, $salaryType,
                    $fixedSalary, $totalHours, $hourlyRate, $grossSalary,
                    $bonuses, $incomeTax, $socialInsurance, $healthInsurance, $unemploymentInsurance, $otherDeductions,
                    $netSalary, $notes
                );

                if (!$stmt_upsert_payroll->execute()) {
                    error_log("Lỗi lưu payroll cho UserID $userId, Kỳ $payPeriod: " . $stmt_upsert_payroll->error);
                    $calculationSuccess = false; // Đánh dấu lỗi
                    // Không nên throw exception ở đây để các user khác vẫn có thể được xử lý
                } else {
                    $processedCount++;
                }

            } 
            $stmt_users->close();
            $stmt_get_hours->close();
            $stmt_upsert_payroll->close();
            if ($calculationSuccess && $processedCount > 0) {
                $conn->commit();
                $calc_message = "Đã tính toán và lưu/cập nhật lương cho $processedCount người dùng trong kỳ $payPeriod.";
                $calc_message_type = 'success';
            } elseif ($processedCount == 0 && $calculationSuccess) { // Không có lỗi nhưng không xử lý ai
                 $conn->rollback(); // Hoàn tác vì không có gì thay đổi
                 $calc_message = "Không có nhân viên/giảng viên nào được tìm thấy để tính lương cho kỳ $payPeriod.";
                 $calc_message_type = 'info';
            } else { // $calculationSuccess là false
                $conn->rollback();
                $calc_message = "Có lỗi xảy ra trong quá trình tính/lưu lương cho kỳ $payPeriod. Một số bản ghi có thể chưa được xử lý. Vui lòng kiểm tra log lỗi.";
                $calc_message_type = 'danger';
            }

        } catch (Exception $e) {
            $conn->rollback();
            $calc_message = "Lỗi nghiêm trọng khi tính lương: " . $e->getMessage();
            $calc_message_type = 'danger';
            error_log("Payroll Calculation Error: " . $e->getMessage());
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['finalize_payroll'])) {
     $payroll_ids_to_finalize = $_POST['payroll_ids'] ?? [];
     $finalizedByUserID = $_SESSION['user_id'];
     if (empty($payroll_ids_to_finalize)) { $finalize_message = "Vui lòng chọn mục để chốt."; $finalize_message_type = 'warning'; }
     else { $conn->begin_transaction(); try { $placeholders = implode(',', array_fill(0, count($payroll_ids_to_finalize), '?')); $types = str_repeat('i', count($payroll_ids_to_finalize)); $stmt_finalize = $conn->prepare("UPDATE payroll SET Status = 'Finalized', FinalizedByUserID = ?, FinalizedDate = NOW() WHERE PayrollID IN ($placeholders) AND Status = 'Calculated'"); if(!$stmt_finalize) throw new Exception("Lỗi prepare chốt: " . $conn->error); $params = array_merge([$finalizedByUserID], $payroll_ids_to_finalize); $types = "i" . $types; $stmt_finalize->bind_param($types, ...$params); if ($stmt_finalize->execute()) { $affected_rows = $stmt_finalize->affected_rows; $conn->commit(); if ($affected_rows > 0) { $finalize_message = "Đã chốt thành công $affected_rows phiếu lương."; $finalize_message_type = 'success'; } else { $finalize_message = "Không có phiếu lương nào được chốt."; $finalize_message_type = 'info'; } } else { throw new Exception("Lỗi execute chốt: " . $stmt_finalize->error); } $stmt_finalize->close(); } catch (Exception $e) { $conn->rollback(); $finalize_message = "Lỗi khi chốt: " . $e->getMessage(); $finalize_message_type = 'danger'; error_log("Finalize Error: " . $e->getMessage()); } }
}

$current_config = ['staff' => 0.00, 'instructor' => 0.00];
$sql_get_cfg = "SELECT Role, Amount FROM salary_config WHERE Role IN ('staff', 'instructor')";
$result_get_cfg = $conn->query($sql_get_cfg);
if ($result_get_cfg) { while ($row = $result_get_cfg->fetch_assoc()) { $current_config[$row['Role']] = $row['Amount']; } }

$view_pay_period_month = $_GET['view_month'] ?? date('m');
$view_pay_period_year = $_GET['view_year'] ?? date('Y');
$viewPayPeriod = sprintf('%04d-%02d', $view_pay_period_year, $view_pay_period_month);
$payroll_data = [];
$sql_get_payroll = "SELECT p.*, u.FullName, u.Role
                    FROM payroll p
                    JOIN user u ON p.UserID = u.UserID
                    WHERE p.PayPeriod = ? -- Sử dụng PayPeriod thay vì Start/End Date
                    ORDER BY u.Role, u.FullName";
$stmt_get_payroll = $conn->prepare($sql_get_payroll);
if ($stmt_get_payroll) {
    $stmt_get_payroll->bind_param("s", $viewPayPeriod); // Bind PayPeriod

    $stmt_get_payroll->execute();
    $result_payroll = $stmt_get_payroll->get_result();
    while($row = $result_payroll->fetch_assoc()){ $payroll_data[] = $row; }
    $stmt_get_payroll->close();
} else { /* Xử lý lỗi */ $page_error = "Lỗi tải bảng lương."; error_log("Get Payroll Prepare failed: " . $conn->error); }


$conn->close();
?>

<div class="container-fluid py-3">
    <h3 class="mb-4"><i class="fas fa-calculator me-2"></i>Quản lý & Tính lương</h3>

    <?php if (!empty($page_error)): ?> <div class="alert alert-danger"><?= htmlspecialchars($page_error) ?></div> <?php endif; ?>

    <!-- 1. Phần Cấu hình Lương -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light"><h5 class="mb-0"><i class="fas fa-cogs me-2"></i>Cấu hình Lương Cơ bản</h5></div>
        <div class="card-body">
            <?php if (!empty($config_message)): ?> <div class="alert alert-<?= $config_message_type ?> alert-dismissible fade show" role="alert"><?= htmlspecialchars($config_message) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
            <form method="POST" action="Dashboard.php?page=ManagePayroll">
                <div class="row g-3 align-items-end">
                    <div class="col-md-5"><label for="staff_fixed_salary" class="form-label">Lương cứng Staff (VNĐ/Tháng)</label><input type="number" class="form-control form-control-sm" id="staff_fixed_salary" name="staff_fixed_salary" value="<?= htmlspecialchars($current_config['staff']) ?>" min="0" step="10000" required></div>
                    <div class="col-md-5"><label for="instructor_hourly_rate" class="form-label">Lương giờ Instructor (VNĐ/Giờ)</label><input type="number" class="form-control form-control-sm" id="instructor_hourly_rate" name="instructor_hourly_rate" value="<?= htmlspecialchars($current_config['instructor']) ?>" min="0" step="1000" required></div>
                    <div class="col-md-2"><button type="submit" name="save_config" class="btn btn-success btn-sm w-100"><i class="fas fa-save me-1"></i> Lưu</button></div>
                </div>
            </form>
        </div>
    </div>

    <!-- 2. Phần Tính lương Định kỳ -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light"><h5 class="mb-0"><i class="fas fa-calendar-alt me-2"></i>Tính lương Định kỳ</h5></div>
        <div class="card-body">
             <?php if (!empty($calc_message)): ?><div class="alert alert-<?= $calc_message_type ?> alert-dismissible fade show" role="alert"><?= htmlspecialchars($calc_message) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
             <form method="POST" action="Dashboard.php?page=ManagePayroll">
                <div class="row g-3 align-items-end">
                    <div class="col-md-4"><label for="pay_period_month" class="form-label">Chọn tháng</label><select name="pay_period_month" id="pay_period_month" class="form-select form-select-sm" required><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= str_pad($m, 2, '0', STR_PAD_LEFT) ?>" <?= (date('m') == $m) ? 'selected' : '' ?>><?= "Tháng " . $m ?></option><?php endfor; ?></select></div>
                    <div class="col-md-4"><label for="pay_period_year" class="form-label">Chọn năm</label><select name="pay_period_year" id="pay_period_year" class="form-select form-select-sm" required><?php for ($y = date('Y'); $y >= date('Y') - 2; $y--): ?><option value="<?= $y ?>" <?= (date('Y') == $y) ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?></select></div>
                    <div class="col-md-4"><button type="submit" name="calculate_payroll" class="btn btn-info btn-sm w-100" onclick="return confirm('Bạn có chắc muốn tính lại lương cho kỳ đã chọn? Dữ liệu lương chưa chốt của kỳ này sẽ bị ghi đè.')"><i class="fas fa-calculator me-1"></i> Tính lương cho kỳ đã chọn</button></div>
                </div>
            </form>
         </div>
    </div>

    <!-- 3. Phần Xem xét và Chốt lương -->
     <div class="card shadow-sm mb-4">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
             <h5 class="mb-0"><i class="fas fa-list-alt me-2"></i>Bảng lương Kỳ: <?= sprintf('%02d/%04d', $view_pay_period_month, $view_pay_period_year) ?></h5> <!-- Hiển thị kỳ đang xem -->
             <form method="GET" action="Dashboard.php" class="d-flex gap-2">
                  <input type="hidden" name="page" value="ManagePayroll">
                  <select name="view_month" class="form-select form-select-sm" onchange="this.form.submit()"><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= str_pad($m, 2, '0', STR_PAD_LEFT) ?>" <?= ($view_pay_period_month == $m) ? 'selected' : '' ?>><?= "Tháng " . $m ?></option><?php endfor; ?></select>
                  <select name="view_year" class="form-select form-select-sm" onchange="this.form.submit()"><?php for ($y = date('Y'); $y >= date('Y') - 2; $y--): ?><option value="<?= $y ?>" <?= ($view_pay_period_year == $y) ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?></select>
             </form>
        </div>
         <div class="card-body">
            <?php if (!empty($finalize_message)): ?><div class="alert alert-<?= $finalize_message_type ?> alert-dismissible fade show" role="alert"><?= htmlspecialchars($finalize_message) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
            <?php if (!empty($payroll_data)): ?>
                 <form method="POST" action="Dashboard.php?page=ManagePayroll&view_month=<?= $view_pay_period_month ?>&view_year=<?= $view_pay_period_year ?>" id="finalizeForm">
                    <div class="table-responsive">
                         <table class="table table-bordered table-hover table-sm align-middle">
                            <thead class="table-light text-center">
                                <tr>
                                    <th><input type="checkbox" id="selectAllCalculated" title="Chọn/Bỏ chọn tất cả mục có thể chốt"></th>
                                    <th>Nhân viên/GV</th> <th>Vai trò</th><th>Lương cứng</th><th>Tổng giờ</th><th>Đơn giá giờ</th><th>Lương gộp</th> <th>Thưởng</th> <th>Trừ</th> <th>Thực nhận</th><th>Trạng thái</th><th>Ghi chú</th><th>Ngày tính</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payroll_data as $payroll): ?>
                                    <tr class="<?= $payroll['Status'] !== 'Calculated' ? 'table-secondary text-muted' : '' ?>"> <!-- Làm mờ dòng đã chốt/trả -->
                                        <td class="text-center"> <?php if($payroll['Status'] === 'Calculated'): ?><input type="checkbox" class="form-check-input payroll-checkbox" name="payroll_ids[]" value="<?= $payroll['PayrollID'] ?>"><?php endif; ?></td>
                                        <td><?= htmlspecialchars($payroll['FullName']) ?></td>
                                        <td class="text-center"><span class="badge <?= $payroll['Role'] === 'staff' ? 'bg-primary' : 'bg-warning text-dark' ?>"><?= ucfirst($payroll['Role']) ?></span></td>
                                        <td class="text-end"><?= $payroll['FixedSalary'] !== null ? number_format($payroll['FixedSalary'], 0, ',', '.') . ' đ' : '-' ?></td>
                                        <td class="text-center"><?= $payroll['TotalTeachingHours'] !== null ? number_format($payroll['TotalTeachingHours'], 1) . ' h' : '-' ?></td>
                                        <td class="text-end"><?= $payroll['HourlyRate'] !== null ? number_format($payroll['HourlyRate'], 0, ',', '.') . ' đ' : '-' ?></td>
                                        <td class="text-end"><?= number_format($payroll['GrossSalary'], 0, ',', '.') ?> đ</td>
                                        <td class="text-end text-success"><?= number_format($payroll['Bonuses'], 0, ',', '.') ?> đ</td>
                                        <td class="text-end text-danger"><?= number_format($payroll['IncomeTaxDeduction'] + $payroll['SocialInsuranceDeduction'] + $payroll['HealthInsuranceDeduction'] + $payroll['UnemploymentInsuranceDeduction'] + $payroll['OtherDeductions'], 0, ',', '.') ?> đ</td>
                                        <td class="text-end fw-bold"><?= number_format($payroll['NetSalary'], 0, ',', '.') ?> đ</td>
                                        <td class="text-center">
                                            <?php $status_badge = 'bg-secondary'; $status_text = ucfirst(htmlspecialchars($payroll['Status'])); if ($payroll['Status'] === 'Calculated') { $status_badge = 'bg-warning text-dark'; $status_text = 'Đã tính'; } elseif ($payroll['Status'] === 'Finalized') { $status_badge = 'bg-info'; $status_text = 'Đã chốt'; } elseif ($payroll['Status'] === 'Paid') { $status_badge = 'bg-success'; $status_text = 'Đã trả'; } ?>
                                            <span class="badge rounded-pill <?= $status_badge ?>"><?= $status_text ?></span>
                                        </td>
                                         <td><small><?= nl2br(htmlspecialchars($payroll['Notes'] ?? '')) ?></small></td>
                                         <td class="text-center small text-muted"><?= date('d/m/Y H:i', strtotime($payroll['CalculationDate'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                         </table>
                    </div>
                     <div class="mt-3 text-end"> <button type="submit" name="finalize_payroll" class="btn btn-primary" id="finalizeBtn" disabled onclick="return confirm('Xác nhận chốt lương cho các mục đã chọn? Hành động này không thể hoàn tác dễ dàng.')"><i class="fas fa-check-circle me-1"></i> Chốt lương các mục đã chọn</button></div>
                 </form>
            <?php else: ?> <div class="alert alert-light text-center border">Chưa có dữ liệu lương cho kỳ <?= sprintf('%02d/%04d', $view_pay_period_month, $view_pay_period_year) ?>. Vui lòng thực hiện tính lương trước.</div><?php endif; ?>
         </div>
     </div>

</div>

<!-- JavaScript giữ nguyên -->
<script> /* ... code JS xử lý checkbox và nút chốt ... */
document.addEventListener('DOMContentLoaded', function() {
    const selectAllCheckbox = document.getElementById('selectAllCalculated');
    const payrollCheckboxes = document.querySelectorAll('.payroll-checkbox');
    const finalizeBtn = document.getElementById('finalizeBtn');

    function toggleFinalizeButton() {
        if (!finalizeBtn) return; // Thêm kiểm tra nút tồn tại
        let oneChecked = false;
        payrollCheckboxes.forEach(cb => { if (cb.checked) { oneChecked = true; } });
        finalizeBtn.disabled = !oneChecked;
    }

    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            payrollCheckboxes.forEach(cb => { cb.checked = this.checked; });
            toggleFinalizeButton();
        });
    }

    payrollCheckboxes.forEach(cb => {
        cb.addEventListener('change', function() {
            toggleFinalizeButton(); 
             if (selectAllCheckbox && !this.checked) {
                 selectAllCheckbox.checked = false;
             } else if (selectAllCheckbox) { 
                 let allChecked = true;
                 payrollCheckboxes.forEach(otherCb => { if (!otherCb.checked) { allChecked = false; } });
                 selectAllCheckbox.checked = allChecked;
             }
        });
    });

    toggleFinalizeButton();
});
</script>