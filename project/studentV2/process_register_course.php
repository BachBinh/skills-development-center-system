<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    echo json_encode(['success' => false, 'message' => 'Lỗi: Yêu cầu đăng nhập với tài khoản học viên.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Lỗi: Phương thức không hợp lệ.']);
    exit();
}

require_once(__DIR__ . '../../config/db_connection.php'); 

if (!isset($conn) || !$conn instanceof mysqli) {
     error_log("Lỗi kết nối DB trong process_register_course.php");
     echo json_encode(['success' => false, 'message' => 'Lỗi: Không thể kết nối cơ sở dữ liệu.']);
     exit();
}


$user_id = $_SESSION['user_id'];
$studentId = 0;
$conn_error = '';

// --- Lấy StudentID ---
$stmt_student = $conn->prepare("SELECT StudentID FROM student WHERE UserID = ?");
if ($stmt_student) {
    $stmt_student->bind_param("i", $user_id);
    $stmt_student->execute();
    $result_student = $stmt_student->get_result();
    if ($row_student = $result_student->fetch_assoc()) {
        $studentId = $row_student['StudentID'];
    } else { $conn_error = "Lỗi: Không tìm thấy thông tin học viên."; }
    $stmt_student->close();
} else { $conn_error = "Lỗi hệ thống khi lấy thông tin học viên (Prepare failed: " . $conn->error . ")"; }

if (!empty($conn_error) || $studentId <= 0) { // Kiểm tra cả studentId > 0
    error_log($conn_error ?: "Lỗi: StudentID không hợp lệ ($studentId) cho UserID $user_id");
    echo json_encode(['success' => false, 'message' => $conn_error ?: "Lỗi: Thông tin học viên không hợp lệ."]);
    exit();
}

$courseId = filter_input(INPUT_POST, 'course_id', FILTER_VALIDATE_INT);
$paymentMethodInput = filter_input(INPUT_POST, 'payment_method', FILTER_SANITIZE_SPECIAL_CHARS); // Đổi tên để rõ ràng
$courseFee = filter_input(INPUT_POST, 'course_fee', FILTER_VALIDATE_FLOAT);

$paymentMethodDb = null; // ('cash' 'bank')
if ($paymentMethodInput === 'cash') {
    $paymentMethodDb = 'cash';
} elseif ($paymentMethodInput === 'bank_transfer') {
    $paymentMethodDb = 'bank';
}

if (!$courseId || $paymentMethodDb === null) { 
    echo json_encode(['success' => false, 'message' => 'Lỗi: Dữ liệu khóa học hoặc phương thức thanh toán không hợp lệ.']);
    exit();
}

if ($courseFee === false || $courseFee < 0) {
     echo json_encode(['success' => false, 'message' => 'Lỗi: Học phí không hợp lệ.']);
     exit();
}


// --- Xử lý đăng ký ---
$conn->begin_transaction(); 

try {
    // 1. Kiểm tra xem đã đăng ký khóa này chưa (tránh đăng ký trùng)
    $stmt_check = $conn->prepare("SELECT RegistrationID, Status FROM registration WHERE StudentID = ? AND CourseID = ?");
    if (!$stmt_check) throw new Exception("Lỗi chuẩn bị kiểm tra đăng ký: " . $conn->error);
    $stmt_check->bind_param("ii", $studentId, $courseId);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();
    $existing_registration = $result_check->fetch_assoc();
    $stmt_check->close();

    $is_re_register = false; 

    if ($existing_registration) {
        if ($existing_registration['Status'] === 'cancelled') {
            $stmt_re_register = $conn->prepare("UPDATE registration SET Status = 'registered', RegisteredAt = NOW() WHERE RegistrationID = ?");
             if (!$stmt_re_register) throw new Exception("Lỗi chuẩn bị đăng ký lại: " . $conn->error);
             $stmt_re_register->bind_param("i", $existing_registration['RegistrationID']);
             if (!$stmt_re_register->execute()) throw new Exception("Lỗi khi đăng ký lại: " . $stmt_re_register->error);
             $stmt_re_register->close();
             $is_re_register = true; // Đánh dấu là đăng ký lại
        } else {
            throw new Exception('Bạn đã đăng ký hoặc hoàn thành khóa học này rồi.');
        }
    } else {
        $stmt_insert_reg = $conn->prepare("INSERT INTO registration (StudentID, CourseID, Status, RegisteredAt) VALUES (?, ?, 'registered', NOW())");
        if (!$stmt_insert_reg) throw new Exception("Lỗi chuẩn bị đăng ký mới: " . $conn->error);
        $stmt_insert_reg->bind_param("ii", $studentId, $courseId);
        if (!$stmt_insert_reg->execute()) throw new Exception("Lỗi khi đăng ký mới: " . $stmt_insert_reg->error);
        $stmt_insert_reg->close();
    }

    // 2. XỬ LÝ TẠO BẢN GHI PAYMENT (CHO CẢ CASH VÀ BANK)
    $stmt_check_pay = $conn->prepare("SELECT PaymentID FROM payment WHERE StudentID = ? AND CourseID = ? AND Method = ? AND Status = 'unpaid'");
    if (!$stmt_check_pay) throw new Exception("Lỗi chuẩn bị kiểm tra thanh toán: " . $conn->error);
    $stmt_check_pay->bind_param("iis", $studentId, $courseId, $paymentMethodDb); // Sử dụng giá trị DB ('cash' hoặc 'bank')
    $stmt_check_pay->execute();
    $result_check_pay = $stmt_check_pay->get_result();
    $existing_unpaid_payment = ($result_check_pay->num_rows > 0); // True nếu đã tồn tại
    $stmt_check_pay->close();

    if (!$existing_unpaid_payment) { 
        $payment_status = 'unpaid';
        $paid_at = null; // PaidAt luôn NULL khi tạo mới
        $stmt_insert_pay = $conn->prepare("INSERT INTO payment (StudentID, CourseID, Amount, Method, Status, PaidAt) VALUES (?, ?, ?, ?, ?, ?)");
        if (!$stmt_insert_pay) throw new Exception("Lỗi chuẩn bị tạo bản ghi thanh toán: " . $conn->error);
        $stmt_insert_pay->bind_param("iidsss", $studentId, $courseId, $courseFee, $paymentMethodDb, $payment_status, $paid_at);
        if (!$stmt_insert_pay->execute()) throw new Exception("Lỗi khi tạo bản ghi thanh toán: " . $stmt_insert_pay->error);
        $stmt_insert_pay->close();
    }

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Đăng ký khóa học thành công!']);

} catch (Exception $e) {
    $conn->rollback(); 
    error_log("Registration Error (UserID: $user_id, CourseID: $courseId): " . $e->getMessage()); 
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close(); 
exit();
?>