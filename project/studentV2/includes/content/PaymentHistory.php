<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    echo "<div class='alert alert-danger'>Lỗi: Bạn không có quyền truy cập trang này.</div>";
    exit();
}

require_once(__DIR__ . '/../../../config/db_connection.php');
require_once(__DIR__ . '/../../../vendor/autoload.php');
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$user_id = $_SESSION['user_id'];
$studentId = 0;
$page_error = '';
$payments = [];

// --- Lấy StudentID ---
$stmt_student = $conn->prepare("SELECT StudentID FROM student WHERE UserID = ?");
if ($stmt_student) {
    $stmt_student->bind_param("i", $user_id);
    $stmt_student->execute();
    $result_student = $stmt_student->get_result();
    if ($row_student = $result_student->fetch_assoc()) {
        $studentId = $row_student['StudentID'];
    } else { $page_error = "Lỗi: Không tìm thấy thông tin học viên liên kết."; }
    $stmt_student->close();
} else { $page_error = "Lỗi hệ thống khi lấy thông tin học viên."; error_log("Prepare failed (get student id): (" . $conn->errno . ") " . $conn->error); }

$searchCourse = isset($_GET['search_course']) ? trim($_GET['search_course']) : "";
if ($studentId > 0 && empty($page_error)) {
    $sql = "SELECT
                p.PaymentID,
                p.CourseID,
                c.Title AS CourseTitle,
                p.Amount,
                p.Method,
                p.PaidAt,
                p.Status,
                u.Email AS StudentEmail, 
                u.FullName AS StudentName
            FROM payment p
            JOIN course c ON p.CourseID = c.CourseID
            JOIN student s ON p.StudentID = s.StudentID 
            JOIN user u ON s.UserID = u.UserID          
            WHERE p.StudentID = ?";
    $params = [$studentId];
    $types = "i";

    if (!empty($searchCourse)) {
        $sql .= " AND c.Title LIKE ?";
        $params[] = "%" . $searchCourse . "%";
        $types .= "s";
    }

    $sql .= " ORDER BY p.PaidAt DESC";

    $stmt_payments = $conn->prepare($sql);
    if ($stmt_payments) {
        $stmt_payments->bind_param($types, ...$params);
        $stmt_payments->execute();
        $result_payments = $stmt_payments->get_result();
        while ($row = $result_payments->fetch_assoc()) {
            $payments[] = $row;
        }
        $stmt_payments->close();
    } else {
        $page_error = "Lỗi khi truy vấn lịch sử thanh toán.";
        error_log("Prepare failed (get payment history): (" . $conn->errno . ") " . $conn->error);
    }
}

$conn->close();
?>

<div class="container-fluid py-3">
    <h3 class="mb-4"><i class="fas fa-history me-2"></i>Lịch sử Thanh toán</h3>

    <?php if (!empty($page_error)): ?>
        <div class="alert alert-danger" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($page_error) ?>
        </div>
    <?php else: ?>

        <!-- Form Tìm kiếm -->
        <div class="card search-card mb-4 shadow-sm">
            <div class="card-body p-3">
                <form method="GET" action="Dashboard.php" class="row g-2 align-items-end">
                    <input type="hidden" name="page" value="PaymentHistory">
                    <div class="col-md-8">
                        <label for="search_course" class="form-label fw-bold small mb-1">Tìm theo tên khóa học</label>
                        <input type="search" name="search_course" id="search_course" class="form-control form-control-sm" placeholder="Nhập tên khóa học..." value="<?= htmlspecialchars($searchCourse) ?>">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fas fa-search"></i> Tìm</button>
                    </div>
                    <div class="col-md-2">
                         <a href="Dashboard.php?page=PaymentHistory" class="btn btn-secondary btn-sm w-100"><i class="fas fa-times"></i> Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <?php if (!empty($payments)): ?>
            <div class="table-responsive shadow-sm rounded">
                <table class="table table-bordered table-hover align-middle mb-0" id="paymentTable">
                    <thead class="table-dark text-center">
                        <tr>
                            <th style="width: 5%;">#</th>
                            <th style="width: 30%;">Khóa học</th>
                            <th style="width: 15%;">Số tiền</th>
                            <th style="width: 10%;">Phương thức</th>
                            <th style="width: 15%;">Ngày Thanh toán</th>
                            <th style="width: 10%;">Trạng thái</th>
                            <th style="width: 15%;">Hành động</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $index => $payment): ?>
                            <tr class="payment-row">
                                <td class="text-center"><?= $index + 1 ?></td>
                                <td class="course-title"><?= htmlspecialchars($payment['CourseTitle']) ?></td>
                                <td class="text-end fw-bold text-danger"><?= number_format($payment['Amount'], 0, ',', '.') ?> VNĐ</td>
                                <td class="text-center"><?= ucfirst(htmlspecialchars($payment['Method'])) ?></td>
                                <td class="text-center"><?= !empty($payment['PaidAt']) ? date('d/m/Y H:i', strtotime($payment['PaidAt'])) : 'N/A' ?></td>
                                <td class="text-center">
                                    <?php
                                        $status_badge = ''; $status_text = '';
                                        switch ($payment['Status']) {
                                            case 'paid': $status_badge = 'bg-success'; $status_text = 'Đã thanh toán'; break;
                                            case 'unpaid': $status_badge = 'bg-warning text-dark'; $status_text = 'Chưa thanh toán'; break;
                                            default: $status_badge = 'bg-secondary'; $status_text = ucfirst(htmlspecialchars($payment['Status']));
                                        }
                                    ?>
                                    <span class="badge rounded-pill <?= $status_badge ?>"><?= $status_text ?></span>
                                </td>
                                <td class="text-center">
                                    <?php if ($payment['Status'] === 'paid'): ?>
                                        <button type="button" class="btn btn-sm btn-outline-primary send-receipt-btn"
                                                data-payment-id="<?= $payment['PaymentID'] ?>"
                                                data-student-email="<?= htmlspecialchars($payment['StudentEmail']) ?>"
                                                data-student-name="<?= htmlspecialchars($payment['StudentName']) ?>"
                                                data-course-title="<?= htmlspecialchars($payment['CourseTitle']) ?>"
                                                data-amount="<?= $payment['Amount'] ?>"
                                                data-paid-at="<?= !empty($payment['PaidAt']) ? date('d/m/Y H:i', strtotime($payment['PaidAt'])) : '' ?>"
                                                title="Gửi hóa đơn qua Email">
                                            <i class="fas fa-receipt me-1"></i> Gửi Bill
                                        </button>
                                    <?php else: ?>
                                        <span class="text-muted small">N/A</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="alert alert-info text-center mt-3">
                <i class="fas fa-info-circle me-2"></i>
                <?php if (!empty($searchCourse)): ?>
                    Không tìm thấy lịch sử thanh toán nào cho khóa học chứa "<?= htmlspecialchars($searchCourse) ?>".
                <?php else: ?>
                    Bạn chưa có lịch sử thanh toán nào.
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

</div>


<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Xử lý Gửi Bill
    document.querySelectorAll('.send-receipt-btn').forEach(button => {
        button.addEventListener('click', function() {
            const paymentId = this.dataset.paymentId;
            const studentEmail = this.dataset.studentEmail;
            const studentName = this.dataset.studentName;
            const courseTitle = this.dataset.courseTitle;
            const amount = this.dataset.amount;
            const paidAt = this.dataset.paidAt;

            if (!paymentId || !studentEmail) {
                Swal.fire('Lỗi', 'Thiếu thông tin cần thiết để gửi hóa đơn.', 'error');
                return;
            }

            // Lưu lại trạng thái nút ban đầu
            const originalButtonHtml = this.innerHTML;
            const buttonElement = this;
            buttonElement.disabled = true;
            buttonElement.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Đang gửi...';

            // Tạo dữ liệu gửi đi
            const formData = new FormData();
            formData.append('action', 'send_receipt'); // Thêm action để file PHP biết làm gì
            formData.append('payment_id', paymentId);
            formData.append('student_email', studentEmail);
            formData.append('student_name', studentName);
            formData.append('course_title', courseTitle);
            formData.append('amount', amount);
            formData.append('paid_at', paidAt);

            // Gọi AJAX đến file xử lý gửi mail
                        // *** THAY THẾ TOÀN BỘ PHẦN FETCH NÀY ***
            fetch('/thiendinhsystem/studentV2/includes/content/send_payment_receipt.php', { // *** KIỂM TRA LẠI ĐƯỜNG DẪN NÀY ***
                method: 'POST',
                body: formData
            })
            .then(response => {
                // Kiểm tra lỗi HTTP trước
                if (!response.ok) {
                     // Nếu có lỗi HTTP, cố gắng đọc text để hiển thị lỗi server nếu có
                     return response.text().then(text => {
                         // Nếu không đọc được text lỗi, throw lỗi chung
                         if (!text) throw new Error(`Lỗi HTTP ${response.status}: ${response.statusText}`);
                         // Ngược lại, throw lỗi với nội dung text
                         throw new Error(`Lỗi HTTP ${response.status}: ${text.substring(0,200)}...`); // Giới hạn lỗi hiển thị
                     });
                }
                 // Nếu không lỗi HTTP, đọc response body MỘT LẦN dưới dạng text
                 return response.text();
            })
            .then(text => {
                // Đọc thành công dưới dạng text, bây giờ thử parse thành JSON
                try {
                    const data = JSON.parse(text);
                    // Nếu parse thành công, xử lý data JSON
                    if (data.success) {
                        Swal.fire('Thành công!', data.message || 'Hóa đơn đã được gửi thành công!', 'success');
                    } else {
                        Swal.fire('Lỗi', data.message || 'Không thể gửi hóa đơn. Vui lòng thử lại.', 'error');
                    }
                } catch (e) {
                    // Nếu parse JSON thất bại, nghĩa là response không phải JSON hợp lệ
                    console.error("Failed to parse JSON response:", text); // Log nội dung text để debug
                    // Hiển thị lỗi chung hoặc một phần nội dung text nếu nó trông giống lỗi HTML/PHP
                     if (text.toLowerCase().includes('error') || text.toLowerCase().includes('warning') || text.startsWith('<br') || text.startsWith('<div')) {
                          Swal.fire('Lỗi Server', 'Server trả về lỗi không mong muốn. Chi tiết: ' + text.substring(0, 150) + '...', 'error');
                     } else {
                          Swal.fire('Lỗi Phản hồi', 'Phản hồi từ máy chủ không hợp lệ. Nội dung: ' + text.substring(0,100) + '...', 'error'); // Hiển thị 1 phần text
                     }
                }
            })
            .catch(error => {
                // Xử lý lỗi mạng hoặc lỗi đã throw từ bước trước
                console.error('Send Receipt Fetch/Processing Error:', error);
                Swal.fire('Lỗi hệ thống', 'Có lỗi xảy ra khi gửi yêu cầu: ' + error.message, 'error');
            })
            .finally(() => {
                // Khôi phục lại nút
                buttonElement.disabled = false;
                buttonElement.innerHTML = originalButtonHtml;
            });
            // *** KẾT THÚC PHẦN THAY THẾ ***
        });
    });

    // seach form
     const searchInput = document.getElementById('search_course'); 
     const tableRows = document.querySelectorAll('#paymentTable tbody tr.payment-row');
     const noDataRow = document.querySelector('#paymentTable tbody tr:not(.payment-row)'); 

     if (searchInput) {
         searchInput.addEventListener('keyup', function() {
             const searchTerm = this.value.toLowerCase();
             let hasVisibleRows = false;
             tableRows.forEach(row => {
                 const courseTitleCell = row.querySelector('.course-title'); 
                 if (courseTitleCell) {
                      const titleText = courseTitleCell.textContent.toLowerCase();
                      if (titleText.includes(searchTerm)) {
                          row.style.display = '';
                          hasVisibleRows = true;
                      } else {
                          row.style.display = 'none';
                      }
                 }
             });
              if (noDataRow) { 
                 noDataRow.style.display = hasVisibleRows ? 'none' : '';
             }
         });
     }

});
</script>