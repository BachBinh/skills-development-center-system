<?php
require_once(__DIR__ . '/../../../config/db_connection.php'); 

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once(__DIR__ . '/../../../vendor/autoload.php'); 

function sendConfirmationEmail($recipientEmail, $recipientName, $courseTitle) {
    $mail = new PHPMailer(true);
    try {
        // $mail->SMTPDebug = SMTP::DEBUG_OFF; 
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';        
        $mail->SMTPAuth   = true;                    
        $mail->Username   = 'techstorehtt@gmail.com';
        $mail->Password   = 'mbkiupcndgxabqka';     
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; 
        $mail->Port       = 465;                     

        // Recipients
        $mail->setFrom('thiendinhsystem@gmail.com', 'Hệ thống Kỹ Năng Pro'); 
        $mail->addAddress($recipientEmail, $recipientName);     

        // Content
        $mail->isHTML(true);                                  
        $mail->CharSet = 'UTF-8';                             
        $mail->Subject = 'Xác nhận thanh toán và đăng ký thành công khóa học: ' . $courseTitle;
        $mail->Body    = "Xin chào " . htmlspecialchars($recipientName) . ",<br><br>" .
                         "Chúc mừng bạn! Chúng tôi đã nhận được thanh toán học phí và xác nhận đăng ký thành công của bạn cho khóa học: <strong>" . htmlspecialchars($courseTitle) . "</strong> tại Hệ thống Kỹ Năng Pro.<br><br>" .
                         "Bây giờ bạn có thể đăng nhập vào tài khoản của mình để:<br>" .
                         "- Xem lịch học chi tiết.<br>" .
                         "- Chuẩn bị cho buổi học đầu tiên!<br><br>" .
                         "Nếu bạn có bất kỳ câu hỏi nào, đừng ngần ngại liên hệ với chúng tôi.<br><br>" .
                         "Chúc bạn có một khóa học hiệu quả và thú vị!<br><br>" .
                         "Trân trọng,<br>Ban quản trị Hệ thống Kỹ Năng Pro";
        $mail->AltBody = 'Xác nhận thanh toán và đăng ký thành công khóa học ' . $courseTitle . ' tại Hệ thống Kỹ Năng Pro. Vui lòng đăng nhập vào hệ thống để xem lịch học và các thông tin khác.';

        $mail->send();
        return true; 

    } catch (Exception $e) {
        error_log("Confirmation email could not be sent to {$recipientEmail}. Mailer Error: {$mail->ErrorInfo}");
        return false; 
    }
}

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'staff'])) {
    header("Location: ../../home/Login.php"); 
    exit();
}

// --- 4. Lấy Course ID và Thông tin Khóa học ---
$courseID = isset($_GET['course_id']) ? intval($_GET['course_id']) : 0; 

if (!$courseID) {
    $_SESSION['message'] = ['type' => 'danger', 'text' => 'ID khóa học không hợp lệ.'];
    header("Location: ../../Dashboard.php?page=ManageRegistration"); 
    exit();
}

// Tên khóa học
$courseTitle = 'Không xác định';
$stmt_course = $conn->prepare("SELECT Title FROM course WHERE CourseID = ?");
if ($stmt_course) {
    $stmt_course->bind_param("i", $courseID);
    $stmt_course->execute();
    $result_course = $stmt_course->get_result();
    if ($courseData = $result_course->fetch_assoc()) {
        $courseTitle = $courseData['Title'];
    } else {
         $_SESSION['message'] = ['type' => 'warning', 'text' => 'Không tìm thấy khóa học với ID này.'];
         header("Location: ../../Dashboard.php?page=ManageRegistration");
         exit();
    }
    $stmt_course->close();
} else {
    die("Lỗi khi chuẩn bị truy vấn thông tin khóa học: " . $conn->error);
}

// --- 5. Xử lý hành động Approve/Reject từ POST ---
$action_message = '';
$action_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registration_id'])) {
    $registration_id = (int)$_POST['registration_id'];
    $action = '';

    if (isset($_POST['confirm'])) {
        $action = 'approve';
    } elseif (isset($_POST['reject'])) {
        $action = 'reject';
    }

    if (($action === 'approve' || $action === 'reject') && $registration_id > 0) {

        $new_reg_status = '';
        if ($action === 'approve') $new_reg_status = 'completed';
        if ($action === 'reject') $new_reg_status = 'cancelled';

        $conn->begin_transaction();
        $email_sent = false; 

        try {
            // B1: Lấy thông tin (StudentID, CourseID, Email, Name) từ Registration ID
            $studentId = null;
            $courseId = null;
            $studentEmail = null;
            $studentName = null;
            $currentRegStatus = null;

            $stmt_info = $conn->prepare("
                SELECT r.StudentID, r.CourseID, r.Status, u.Email, u.FullName
                FROM registration r
                JOIN student s ON r.StudentID = s.StudentID
                JOIN user u ON s.UserID = u.UserID
                WHERE r.RegistrationID = ?
            ");
            if (!$stmt_info) throw new Exception("Lỗi chuẩn bị lấy thông tin đăng ký: " . $conn->error);

            $stmt_info->bind_param("i", $registration_id);
            $stmt_info->execute();
            $result_info = $stmt_info->get_result();
            if ($info = $result_info->fetch_assoc()) {
                $studentId = $info['StudentID'];
                $courseId = $info['CourseID'];
                $studentEmail = $info['Email'];
                $studentName = $info['FullName'];
                $currentRegStatus = $info['Status'];
            } else {
                throw new Exception("Lỗi: Không tìm thấy đăng ký với ID $registration_id.");
            }
            $stmt_info->close();

            if ($currentRegStatus !== 'registered') {
                 throw new Exception("Hành động không hợp lệ. Đăng ký này không ở trạng thái 'Chờ duyệt'.");
            }

            // B2: Cập nhật trạng thái Registration 
            $stmt_update_reg = $conn->prepare("UPDATE registration SET Status = ? WHERE RegistrationID = ? AND Status = 'registered'");
            if (!$stmt_update_reg) throw new Exception("Lỗi chuẩn bị cập nhật đăng ký: " . $conn->error);

            $stmt_update_reg->bind_param("si", $new_reg_status, $registration_id);
            if (!$stmt_update_reg->execute()) throw new Exception("Lỗi khi cập nhật trạng thái đăng ký: " . $stmt_update_reg->error);

            // Kiểm tra xem có thực sự cập nhật được không
            if ($stmt_update_reg->affected_rows <= 0) {
                 throw new Exception("Không thể cập nhật đăng ký (ID: $registration_id). Có thể đã được xử lý.");
            }
            $stmt_update_reg->close();

            // B3: Nếu là Approve, Cập nhật trạng thái Payment 
            if ($action === 'approve') {
                $stmt_update_pay = $conn->prepare("UPDATE payment SET Status = 'paid', PaidAt = NOW() WHERE StudentID = ? AND CourseID = ? AND Status = 'unpaid'");
                 if (!$stmt_update_pay) throw new Exception("Lỗi chuẩn bị cập nhật thanh toán: " . $conn->error);

                 $stmt_update_pay->bind_param("ii", $studentId, $courseId);
                 if (!$stmt_update_pay->execute()) {
                    error_log("Lỗi khi cập nhật payment thành paid (RegID: $registration_id, StudentID: $studentId, CourseID: $courseId): " . $stmt_update_pay->error);
                     $action_message .= " (Cảnh báo: Có lỗi khi cập nhật trạng thái thanh toán)"; // Nối vào thông báo cuối
                     $action_type = 'warning'; 
                 } else {
                 }
                 $stmt_update_pay->close();

                // B4: Nếu Approve và có email, Gửi Email Xác nhận
                if (!empty($studentEmail)) {
                    if (sendConfirmationEmail($studentEmail, $studentName, $courseTitle)) {
                        $email_sent = true; // gửi thành công
                    } else {
                         $action_message .= " (Cảnh báo: Có lỗi khi gửi email xác nhận)";
                         $action_type = 'warning';
                    }
                }
            }

            // B5: Commit Transaction
            $conn->commit();

             if ($action === 'approve') {
                 $action_message = "Đã duyệt đăng ký cho '".htmlspecialchars($studentName)."' thành công.";
                 if ($email_sent) {
                     $action_message .= " Email xác nhận đã được gửi.";
                 }
                 if(empty($action_type)) $action_type = 'success'; // Đảm bảo có type
             } elseif ($action === 'reject') {
                 $action_message = "Đã từ chối đăng ký cho '".htmlspecialchars($studentName)."'.";
                 $action_type = 'info';
             }

        } catch (Exception $e) {
            $conn->rollback();
            $action_message = $e->getMessage(); // Lấy thông báo lỗi từ Exception
            $action_type = 'danger';
            error_log("Lỗi xử lý Approve/Reject Registration (RegID: $registration_id): " . $e->getMessage());
        }

    } else { // Trường hợp action hoặc registration_id không hợp lệ
        $action_message = "Hành động không hợp lệ hoặc ID đăng ký không đúng.";
        $action_type = 'danger';
    }

    $_SESSION['action_message'] = ['type' => $action_type, 'text' => $action_message];
    header("Location: " . $_SERVER['PHP_SELF'] . "?course_id=" . $courseID . (!empty($searchStudent) ? '&search='.urlencode($searchStudent) : '') . (!empty($filterStatus) ? '&filter='.$filterStatus : '')); // Giữ lại bộ lọc/tìm kiếm
    exit();
}

// --- 6. Lấy giá trị tìm kiếm và lọc từ GET (giữ nguyên) ---
$searchStudent = isset($_GET['search']) ? trim($_GET['search']) : "";
$filterStatus = isset($_GET['filter']) ? trim($_GET['filter']) : "";
$hasFilterOrSearch = !empty($searchStudent) || !empty($filterStatus); // Biến kiểm tra có lọc/tìm kiếm không

// --- 7. Lấy danh sách học viên đăng ký với tìm kiếm và lọc (giữ nguyên) ---
$students = []; // Khởi tạo mảng
$sql_list = "SELECT r.RegistrationID, u.FullName, u.Email, u.Phone, r.Status, r.RegisteredAt FROM registration r JOIN student s ON r.StudentID = s.StudentID JOIN user u ON s.UserID = u.UserID WHERE r.CourseID = ? ";
$params_list = [$courseID]; $types_list = "i";
if (!empty($searchStudent)) { $sql_list .= " AND u.FullName LIKE ? "; $params_list[] = "%" . $searchStudent . "%"; $types_list .= "s"; }
if (!empty($filterStatus) && in_array($filterStatus, ['registered', 'completed', 'cancelled'])) { $sql_list .= " AND r.Status = ? "; $params_list[] = $filterStatus; $types_list .= "s"; }
$sql_list .= " ORDER BY CASE r.Status WHEN 'registered' THEN 1 ELSE 2 END, r.RegisteredAt DESC";
$stmt_list = $conn->prepare($sql_list);
if ($stmt_list) {
    if (count($params_list) > 1) { $stmt_list->bind_param($types_list, ...$params_list); } else { $stmt_list->bind_param($types_list, $courseID); }
    $stmt_list->execute(); $result_list = $stmt_list->get_result();
    while ($row = $result_list->fetch_assoc()) { $students[] = $row; }
    $stmt_list->close();
} else { $page_error = "Lỗi khi lấy danh sách học viên đăng ký."; error_log("Prepare failed (get student list): (" . $conn->errno . ") " . $conn->error); }



if (isset($_SESSION['action_message'])) {
    $action_message = $_SESSION['action_message']['text'];
    $action_type = $_SESSION['action_message']['type'];
    unset($_SESSION['action_message']);
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Danh sách đăng ký - <?= htmlspecialchars($courseTitle) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .status-badge { font-weight: 500; padding: 0.4em 0.7em; font-size: 0.85em; }
        .table th, .table td { vertical-align: middle; }
        .breadcrumb-item a { text-decoration: none; color: #0d6efd;}
        .action-buttons form { margin-bottom: 0; } 
        .search-card { background-color: #f8f9fa; border: 1px solid #dee2e6; }
    </style>
</head>

<body>
    <div class="container mt-4">

        <h3 class="mb-3">📜 Danh sách đăng ký: <strong><?= htmlspecialchars($courseTitle) ?></strong></h3>

        <?php if (!empty($action_message)): ?>
            <div class="alert alert-<?= $action_type ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($action_message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
         <?php if (!empty($page_error)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($page_error) ?></div>
        <?php endif; ?>

        <div class="card search-card mb-4">
            <div class="card-body p-3">
                <form method="GET" action="CourseRegistrationDetail.php" class="row g-3 align-items-end" id="searchForm">
                    <input type="hidden" name="course_id" value="<?= $courseID ?>">
                    <div class="col-md-8 col-lg-7">
                        <label for="searchStudent" class="form-label fw-bold small">Tìm học viên</label>
                        <input type="search" name="search" id="searchStudent" class="form-control form-control-sm" placeholder="Nhập tên..." value="<?= htmlspecialchars($searchStudent) ?>">
                    </div>
                    <div class="col-md-6 col-lg-5">
                        <label for="filterSelect" class="form-label fw-bold small">Trạng thái</label>
                        <select name="filter" class="form-select form-select-sm" id="filterSelect">
                            <option value="">Tất cả</option>
                            <option value="registered" <?= $filterStatus === "registered" ? "selected" : "" ?>>Chờ duyệt</option>
                            <option value="completed" <?= $filterStatus === "completed" ? "selected" : "" ?>>Đã duyệt</option>
                            <option value="cancelled" <?= $filterStatus === "cancelled" ? "selected" : "" ?>>Đã hủy/Từ chối</option>
                        </select>
                    </div>
                    <div class="col-md-2 col-lg-1">
                        <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fas fa-search"></i> Tìm</button>
                    </div>
                     <div class="col-md-2 col-lg-1">
                        <a href="CourseRegistrationDetail.php?course_id=<?= $courseID ?>" class="btn btn-secondary btn-sm w-100"><i class="fas fa-times"></i> Reset</a>
                    </div>
                </form>
            </div>
        </div>

         <!-- Bảng danh sách học viên -->
        <?php if (empty($page_error)): ?>
            <?php if (!empty($students)): ?>
                <div class="table-responsive shadow-sm rounded">
                    <table class="table table-bordered table-striped table-hover mb-0">
                        <thead thead class="table-dark text-center">
                            <tr>
                                <th style="width: 5%;">#</th>
                                <th style="width: 25%;">Họ tên</th>
                                <th style="width: 20%;">Email</th>
                                <th style="width: 15%;">Điện thoại</th>
                                <th style="width: 15%;">Ngày ĐK</th>
                                <th style="width: 10%;">Trạng thái</th>
                                <th style="width: 10%;">Hành động</th>
                            </tr>
                        </thead>
                        <tbody class="align-middle">
                            <?php foreach ($students as $index => $row): ?>
                                <tr>
                                    <td class="text-center"><?= $index + 1 ?></td>
                                    <td><?= htmlspecialchars($row['FullName']) ?></td>
                                    <td><?= htmlspecialchars($row['Email']) ?></td>
                                    <td><?= htmlspecialchars($row['Phone'] ?: 'N/A') ?></td>
                                    <td class="text-center"><?= date('d/m/Y H:i', strtotime($row['RegisteredAt'])) ?></td>
                                    <td class="text-center">
                                        <?php
                                            $status_badge = ''; $status_text = '';
                                            switch ($row['Status']) {
                                                case 'registered': $status_badge = 'bg-warning text-dark'; $status_text = 'Chờ duyệt'; break;
                                                case 'completed': $status_badge = 'bg-success text-white'; $status_text = 'Đã duyệt'; break;
                                                case 'cancelled': $status_badge = 'bg-danger text-white'; $status_text = 'Đã hủy/Từ chối'; break;
                                                default: $status_badge = 'bg-secondary text-white'; $status_text = ucfirst(htmlspecialchars($row['Status']));
                                            }
                                        ?>
                                        <span class="badge rounded-pill <?= $status_badge ?> status-badge"><?= $status_text ?></span>
                                    </td>
                                    <td class="text-center action-buttons">
                                        <?php if ($row['Status'] === 'registered'): ?>
                                            <!-- Form xác nhận v2 -->
                                            <form method="POST" action="<?= $_SERVER['PHP_SELF'] ?>?course_id=<?= $courseID ?>" class="d-inline-block me-2">
                                                <input type="hidden" name="registration_id" value="<?= $row['RegistrationID'] ?>">
                                                <button type="submit" name="confirm" class="btn btn-success btn-sm" title="Duyệt và gửi email xác nhận">
                                                <i class="fas fa-check-circle"></i>
                                                </button>
                                            </form>
                                            <!-- Nút từ chối -->
                                            <button type="button"
                                                    class="btn btn-outline-danger btn-sm border-1 reject-btn" 
                                                    data-registration-id="<?= $row['RegistrationID'] ?>"
                                                    data-student-name="<?= htmlspecialchars($row['FullName']) ?>"
                                                    title="Từ chối đăng ký">
                                                <i class="fas fa-times-circle"></i>
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted small fst-italic">Đã xử lý</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: // Không có sinh viên nào ?>
                <div class="alert alert-info text-center mt-3">
                    <i class="fas fa-info-circle me-2"></i>
                    <?php if ($hasFilterOrSearch): ?>
                        Không tìm thấy học viên nào phù hợp với tiêu chí tìm kiếm/lọc của bạn.
                    <?php else: ?>
                        Chưa có học viên nào đăng ký khóa học này.
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; // Kết thúc kiểm tra page_error ?>

        <div class="mt-4">
             <a href="../../Dashboard.php?page=ManageRegistration" class="btn btn-secondary"><i class="fas fa-arrow-left me-1"></i> Quay lại danh sách khóa học</a>
         </div>

        <div class="modal fade" id="rejectReasonModal" tabindex="-1" aria-labelledby="rejectReasonModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="rejectReasonModalLabel"><i class="fas fa-comment-slash me-2"></i>Chọn lý do từ chối</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Vui lòng chọn lý do từ chối đăng ký cho học viên <strong id="rejectStudentName"></strong>:</p>
                <input type="hidden" id="rejectRegistrationId">
                <div class="form-check mb-2">
                <input class="form-check-input" type="radio" name="rejectionReason" id="reasonPayment" value="payment_not_received" checked>
                <label class="form-check-label" for="reasonPayment">
                    Chưa nhận được thanh toán học phí.
                </label>
                </div>
                <div class="form-check mb-2">
                <input class="form-check-input" type="radio" name="rejectionReason" id="reasonCourseCancel" value="course_cancelled">
                <label class="form-check-label" for="reasonCourseCancel">
                    Khóa học bị hủy (Sẽ liên hệ hoàn tiền).
                </label>
                </div>
                <div class="form-check">
                <input class="form-check-input" type="radio" name="rejectionReason" id="reasonOther" value="other">
                <label class="form-check-label" for="reasonOther">
                    Lý do khác (Sẽ thông báo chung).
                </label>
                </div>
                <!-- <textarea id="otherReasonText" class="form-control mt-2" rows="2" placeholder="Nhập lý do khác (nếu có)" style="display: none;"></textarea> -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy bỏ</button>
                <button type="button" class="btn btn-danger" id="confirmRejectBtn"><i class="fas fa-times-circle me-2"></i>Xác nhận Từ chối</button>
            </div>
            </div>
        </div>
        </div>
    </div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script> 
<script>
document.getElementById("filterSelect").addEventListener("change", function() {
    document.getElementById("searchForm").submit();
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const rejectReasonModalElement = document.getElementById('rejectReasonModal');
    const rejectReasonModal = new bootstrap.Modal(rejectReasonModalElement);
    const confirmRejectBtn = document.getElementById('confirmRejectBtn');
    const rejectRegistrationIdInput = document.getElementById('rejectRegistrationId');
    const rejectStudentNameSpan = document.getElementById('rejectStudentName');

    // --- Xử lý khi nhấn nút Từ chối trong bảng ---
    document.querySelectorAll('.reject-btn').forEach(button => {
        button.addEventListener('click', function(event) {
            event.preventDefault(); // Ngăn form submit mặc định

            const registrationId = this.dataset.registrationId; //ID từ data attribute
            const studentName = this.dataset.studentName; //tên từ data attribute
            if (rejectRegistrationIdInput) rejectRegistrationIdInput.value = registrationId;
            if (rejectStudentNameSpan) rejectStudentNameSpan.textContent = studentName;

            rejectReasonModal.show();
        });
    });

    if (confirmRejectBtn) {
        confirmRejectBtn.addEventListener('click', function() {
            const registrationId = rejectRegistrationIdInput.value;
            const selectedReason = document.querySelector('input[name="rejectionReason"]:checked');
            
            if (!registrationId || !selectedReason) {
                Swal.fire('Lỗi', 'Vui lòng chọn lý do từ chối.', 'error');
                return;
            }

            const reasonValue = selectedReason.value;

            const rejectButtonInModal = this; // Lưu lại nút để xử lý loading
            rejectButtonInModal.disabled = true;
            rejectButtonInModal.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Đang xử lý...';

            const formData = new FormData();
            formData.append('registration_id', registrationId);
            formData.append('action', 'reject');
            formData.append('reason', reasonValue); 
            fetch('process_reject_registration.php', { // Gọi file PHP xử lý riêng
                method: 'POST',
                body: formData 
            })
            .then(response => response.json().catch(() => { throw new Error('Phản hồi không hợp lệ từ server.') }))
            .then(data => {
                if (data.success) {
                    rejectReasonModal.hide(); // Đóng modal
                    Swal.fire({
                        title: 'Đã từ chối!',
                        text: data.message || 'Đăng ký đã được từ chối và email đã được gửi.',
                        icon: 'success',
                        timer: 2000,
                        showConfirmButton: false
                    }).then(() => {
                        const urlParams = new URLSearchParams(window.location.search);
                        const backParams = urlParams.get('search') ? `&search=${encodeURIComponent(urlParams.get('search'))}` : '';
                        const backFilter = urlParams.get('filter') ? `&filter=${encodeURIComponent(urlParams.get('filter'))}` : '';
                        window.location.href = `CourseRegistrationDetail.php?course_id=${urlParams.get('course_id')}${backParams}${backFilter}`;
                    });
                } else {
                    Swal.fire('Lỗi', data.message || 'Không thể từ chối đăng ký.', 'error');
                }
            })
            .catch(error => {
                console.error('Reject Fetch Error:', error);
                Swal.fire('Lỗi', 'Có lỗi xảy ra khi gửi yêu cầu: ' + error.message, 'error');
            })
             .finally(() => {
                rejectButtonInModal.disabled = false;
                rejectButtonInModal.innerHTML = '<i class="fas fa-times-circle me-2"></i>Xác nhận Từ chối';
            });
        });
    }

     <?php if (!empty($action_message)): ?>
     setTimeout(() => {
         Swal.fire({
             title: '<?= $action_type === 'success' ? 'Thành công!' : ($action_type === 'warning' ? 'Lưu ý!' : 'Lỗi!') ?>',
             text: '<?= addslashes(htmlspecialchars($action_message)) ?>',
             icon: '<?= $action_type ?>', // success, error, warning, info, question
             timer: 3000, 
             showConfirmButton: false
         });
     }, 100); // Delay nhỏ
    <?php endif; ?>


}); 
</script>
</body>
</html>