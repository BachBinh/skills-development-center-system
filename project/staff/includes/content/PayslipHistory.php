<?php
// Shared file for both Staff and Instructor to view payslips
// Location: (Choose one, e.g., includes/content/PayslipHistory.php within both staff/ and instructor/ folders,
// or create a shared 'common/includes/content/' folder)

// Ensure session is started and basic authentication/role check is done by the calling Dashboard.php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['staff', 'instructor'])) {
    echo "<div class='alert alert-danger'>Lỗi: Bạn không có quyền truy cập trang này.</div>";
    exit();
}

require_once(__DIR__ . '/../../../config/db_connection.php'); // *** Adjust path as needed ***

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role']; // Get the role to display relevant info
$page_error = '';
$payslips = [];

// --- Filtering by Pay Period ---
// Determine available pay periods for the dropdown (optional but helpful)
$availablePeriods = [];
$stmt_periods = $conn->prepare("SELECT DISTINCT PayPeriod FROM payroll WHERE UserID = ? AND Status IN ('Finalized', 'Paid') ORDER BY PayPeriod DESC");
if ($stmt_periods) {
    $stmt_periods->bind_param("i", $user_id);
    $stmt_periods->execute();
    $result_periods = $stmt_periods->get_result();
    while ($row = $result_periods->fetch_assoc()) {
        $availablePeriods[] = $row['PayPeriod'];
    }
    $stmt_periods->close();
}

// Get the selected period or default to the latest available
$selectedPeriod = $_GET['period'] ?? ($availablePeriods[0] ?? null); // Default to latest if available

// --- Fetch Payslip History ---
$sql = "SELECT
            PayrollID, PayPeriod, PeriodStartDate, PeriodEndDate,
            SalaryType, FixedSalary, TotalTeachingHours, HourlyRate,
            GrossSalary, Bonuses,
            IncomeTaxDeduction, SocialInsuranceDeduction, HealthInsuranceDeduction,
            UnemploymentInsuranceDeduction, OtherDeductions,
            NetSalary, FinalizedDate, Status, Notes
        FROM payroll
        WHERE UserID = ? AND Status IN ('Finalized', 'Paid')"; // Only show finalized/paid slips

$params = [$user_id];
$types = "i";

// Add period filter if selected
if (!empty($selectedPeriod)) {
    $sql .= " AND PayPeriod = ?";
    $params[] = $selectedPeriod;
    $types .= "s";
}

$sql .= " ORDER BY PeriodEndDate DESC, PeriodStartDate DESC"; // Order by most recent period

$stmt_payslips = $conn->prepare($sql);
if ($stmt_payslips) {
    $stmt_payslips->bind_param($types, ...$params);
    $stmt_payslips->execute();
    $result_payslips = $stmt_payslips->get_result();
    while ($row = $result_payslips->fetch_assoc()) {
        $payslips[] = $row;
    }
    $stmt_payslips->close();
} else {
    $page_error = "Lỗi khi truy vấn lịch sử lương.";
    error_log(ucfirst($user_role) . " Payslip Prepare failed: (" . $conn->errno . ") " . $conn->error);
}

$conn->close();
?>

<div class="container-fluid py-3">
    <h3 class="mb-4"><i class="fas fa-file-invoice-dollar me-2"></i>Lịch sử Phiếu lương</h3>

    <?php if (!empty($page_error)): ?>
        <div class="alert alert-danger" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($page_error) ?>
        </div>
    <?php else: ?>
        <!-- Period Filter Form -->
        <div class="card shadow-sm mb-4">
            <div class="card-body p-3">
                <form method="GET" action="Dashboard.php" class="row g-2 align-items-end">
                     <!-- Giữ nguyên page hiện tại -->
                    <input type="hidden" name="page" value="<?= htmlspecialchars($_GET['page'] ?? 'PayslipHistory') ?>">
                    <div class="col-md-4">
                        <label for="filter_period" class="form-label fw-bold small mb-1">Xem kỳ lương</label>
                        <select name="period" id="filter_period" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">-- Tất cả các kỳ --</option>
                            <?php foreach ($availablePeriods as $period): ?>
                                <?php
                                    // Format YYYY-MM to MM/YYYY for display
                                    $displayPeriod = DateTime::createFromFormat('Y-m', $period)->format('m/Y');
                                ?>
                                <option value="<?= htmlspecialchars($period) ?>" <?= ($selectedPeriod == $period) ? 'selected' : '' ?>>
                                    Kỳ <?= htmlspecialchars($displayPeriod) ?>
                                </option>
                            <?php endforeach; ?>
                             <?php if (empty($availablePeriods)): ?>
                                 <option value="" disabled>Chưa có kỳ lương nào</option>
                             <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <a href="Dashboard.php?page=PayslipHistory" class="btn btn-secondary btn-sm w-100"><i class="fas fa-times me-1"></i> Bỏ lọc</a>
                    </div>
                     <?php if ($user_role === 'instructor'): // Thêm link xem giờ dạy cho Instructor ?>
                    <!-- <div class="col-md-4 ms-auto text-end">
                         <a href="Dashboard.php?page=ViewTeachingHours" class="btn btn-outline-info btn-sm">
                             <i class="fas fa-clock me-1"></i> Xem chi tiết giờ dạy
                         </a>
                     </div> -->
                     <?php endif; ?>
                </form>
            </div>
        </div>


        <?php if (!empty($payslips)): ?>
            <?php foreach ($payslips as $index => $slip): ?>
                <div class="card shadow-sm mb-3 payslip-card">
                    <div class="card-header bg-light fw-bold d-flex justify-content-between align-items-center">
                        <span>
                            <i class="fas fa-calendar-check me-2 text-primary"></i>
                            Phiếu lương Kỳ: <?= htmlspecialchars(DateTime::createFromFormat('Y-m', $slip['PayPeriod'])->format('m/Y')) ?>
                             (Từ <?= date('d/m/Y', strtotime($slip['PeriodStartDate'])) ?> Đến <?= date('d/m/Y', strtotime($slip['PeriodEndDate'])) ?>)
                        </span>
                        <span class="badge rounded-pill <?= $slip['Status'] === 'Paid' ? 'bg-success' : 'bg-info' ?>">
                            <?= $slip['Status'] === 'Paid' ? 'Đã thanh toán' : 'Đã chốt' ?>
                             <?= !empty($slip['FinalizedDate']) ? ' - ' . date('d/m/Y', strtotime($slip['FinalizedDate'])) : '' ?>
                         </span>
                    </div>
                    <div class="card-body p-3">
                        <div class="row g-3">
                            <!-- Cột Thông tin Cơ bản -->
                            <div class="col-md-6 border-end">
                                <h6 class="text-muted mb-3">Chi tiết Thu nhập</h6>
                                <?php if ($slip['SalaryType'] === 'fixed'): // Staff ?>
                                    <dl class="row mb-1 small">
                                        <dt class="col-sm-5">Lương cứng:</dt>
                                        <dd class="col-sm-7 text-end"><?= number_format($slip['FixedSalary'] ?? 0, 0, ',', '.') ?> VNĐ</dd>
                                    </dl>
                                    <dl class="row mb-1 small">
                                        <dt class="col-sm-5">Lương gộp (Gross):</dt>
                                        <dd class="col-sm-7 text-end fw-bold"><?= number_format($slip['GrossSalary'] ?? 0, 0, ',', '.') ?> VNĐ</dd>
                                    </dl>
                                <?php else: // Instructor ?>
                                     <dl class="row mb-1 small">
                                        <dt class="col-sm-5">Tổng giờ dạy:</dt>
                                        <dd class="col-sm-7 text-end"><?= number_format($slip['TotalTeachingHours'] ?? 0, 2) ?> giờ</dd>
                                    </dl>
                                     <dl class="row mb-1 small">
                                        <dt class="col-sm-5">Đơn giá/giờ:</dt>
                                        <dd class="col-sm-7 text-end"><?= number_format($slip['HourlyRate'] ?? 0, 0, ',', '.') ?> VNĐ</dd>
                                    </dl>
                                    <dl class="row mb-1 small">
                                        <dt class="col-sm-5">Lương gộp (Gross):</dt>
                                        <dd class="col-sm-7 text-end fw-bold"><?= number_format($slip['GrossSalary'] ?? 0, 0, ',', '.') ?> VNĐ</dd>
                                    </dl>
                                <?php endif; ?>
                                <dl class="row mb-1 small">
                                    <dt class="col-sm-5">Thưởng/Phụ cấp:</dt>
                                    <dd class="col-sm-7 text-end text-success"><?= number_format($slip['Bonuses'] ?? 0, 0, ',', '.') ?> VNĐ</dd>
                                </dl>
                            </div>

                            <!-- Cột Khấu trừ và Thực nhận -->
                            <div class="col-md-6">
                                 <h6 class="text-muted mb-3">Các khoản Khấu trừ</h6>
                                 <dl class="row mb-1 small">
                                     <dt class="col-sm-6">Thuế TNCN:</dt>
                                     <dd class="col-sm-6 text-end text-danger"><?= number_format($slip['IncomeTaxDeduction'] ?? 0, 0, ',', '.') ?> VNĐ</dd>
                                </dl>
                                <dl class="row mb-1 small">
                                    <dt class="col-sm-6">BHXH (8%):</dt>
                                    <dd class="col-sm-6 text-end text-danger"><?= number_format($slip['SocialInsuranceDeduction'] ?? 0, 0, ',', '.') ?> VNĐ</dd>
                                </dl>
                                 <dl class="row mb-1 small">
                                    <dt class="col-sm-6">BHYT (1.5%):</dt>
                                    <dd class="col-sm-6 text-end text-danger"><?= number_format($slip['HealthInsuranceDeduction'] ?? 0, 0, ',', '.') ?> VNĐ</dd>
                                </dl>
                                 <dl class="row mb-1 small">
                                    <dt class="col-sm-6">BHTN (1%):</dt>
                                    <dd class="col-sm-6 text-end text-danger"><?= number_format($slip['UnemploymentInsuranceDeduction'] ?? 0, 0, ',', '.') ?> VNĐ</dd>
                                </dl>
                                <dl class="row mb-1 small">
                                    <dt class="col-sm-6">Khấu trừ khác:</dt>
                                    <dd class="col-sm-6 text-end text-danger"><?= number_format($slip['OtherDeductions'] ?? 0, 0, ',', '.') ?> VNĐ</dd>
                                </dl>
                                <hr>
                                <dl class="row mb-0 small">
                                     <dt class="col-sm-6 fs-6 fw-bold">THỰC NHẬN (NET):</dt>
                                     <dd class="col-sm-6 text-end fs-6 fw-bold text-primary"><?= number_format($slip['NetSalary'] ?? 0, 0, ',', '.') ?> VNĐ</dd>
                                </dl>
                            </div>
                        </div>
                         <?php if (!empty($slip['Notes'])): ?>
                            <hr class="my-2">
                            <p class="mb-0 small text-muted"><strong>Ghi chú:</strong> <?= nl2br(htmlspecialchars($slip['Notes'])) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: // Không có phiếu lương nào ?>
            <div class="alert alert-info text-center mt-3">
                <i class="fas fa-info-circle me-2"></i>
                <?php if (!empty($selectedPeriod)): ?>
                    Không tìm thấy phiếu lương cho kỳ <?= htmlspecialchars(DateTime::createFromFormat('Y-m', $selectedPeriod)->format('m/Y')) ?>.
                <?php else: ?>
                    Chưa có lịch sử phiếu lương nào được ghi nhận.
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; // Kết thúc kiểm tra page_error ?>
</div>