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
     error_log("Lỗi kết nối DB trong cancel_registration.php");
     echo json_encode(['success' => false, 'message' => 'Lỗi: Không thể kết nối cơ sở dữ liệu.']);
     exit();
}

// --- Lấy dữ liệu ---
$user_id = $_SESSION['user_id'];
$courseId = filter_input(INPUT_POST, 'course_id', FILTER_VALIDATE_INT);
$studentId = 0;

// --- Lấy StudentID ---
$stmt_student = $conn->prepare("SELECT StudentID FROM student WHERE UserID = ?");
if ($stmt_student) {
    $stmt_student->bind_param("i", $user_id);
    $stmt_student->execute();
    $result_student = $stmt_student->get_result();
    if ($row_student = $result_student->fetch_assoc()) {
        $studentId = $row_student['StudentID'];
    }
    $stmt_student->close();
} else {
    error_log("Prepare failed (get student id cancel): (" . $conn->errno . ") " . $conn->error);
    echo json_encode(['success' => false, 'message' => 'Lỗi hệ thống khi lấy thông tin học viên.']);
    exit();
}

// --- Xác thực đầu vào ---
if (!$studentId || !$courseId) {
    echo json_encode(['success' => false, 'message' => 'Lỗi: Dữ liệu không hợp lệ (thiếu ID học viên hoặc khóa học).']);
    exit();
}

// --- Bắt đầu Transaction ---
$conn->begin_transaction();

try {
    // 1. Xóa bản ghi đăng ký (registration)
    // Chỉ nên xóa những đăng ký đang ở trạng thái 'registered'
    $stmt_delete_reg = $conn->prepare("DELETE FROM registration WHERE StudentID = ? AND CourseID = ? AND Status = 'registered'");
    if (!$stmt_delete_reg) throw new Exception("Lỗi chuẩn bị xóa đăng ký: " . $conn->error);
    $stmt_delete_reg->bind_param("ii", $studentId, $courseId);
    $execute_reg = $stmt_delete_reg->execute();
    $affected_rows_reg = $stmt_delete_reg->affected_rows; // Số hàng bị xóa
    $stmt_delete_reg->close();

    if (!$execute_reg) {
         throw new Exception("Lỗi khi thực thi xóa đăng ký: " . $conn->error);
    }

    // Kiểm tra xem có thực sự xóa được đăng ký không
    if ($affected_rows_reg <= 0) {

         throw new Exception("Không tìm thấy đăng ký hợp lệ để hủy, hoặc bạn không có quyền hủy trạng thái này.");
    }

    // 2. Xóa bản ghi thanh toán (payment) tương ứng NẾU unpaid
    $stmt_delete_pay = $conn->prepare("DELETE FROM payment WHERE StudentID = ? AND CourseID = ? AND Status = 'unpaid'");
     if (!$stmt_delete_pay) throw new Exception("Lỗi chuẩn bị xóa thanh toán: " . $conn->error);
    $stmt_delete_pay->bind_param("ii", $studentId, $courseId);
    $execute_pay = $stmt_delete_pay->execute();
    $stmt_delete_pay->close();

    if (!$execute_pay) {
        throw new Exception("Lỗi khi thực thi xóa thanh toán chưa thanh toán: " . $conn->error);
    }

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Hủy đăng ký thành công!']);

} catch (Exception $e) {
    $conn->rollback();
    error_log("Cancel Registration Error (StudentID: $studentId, CourseID: $courseId): " . $e->getMessage()); // Ghi log
    echo json_encode(['success' => false, 'message' => $e->getMessage()]); // Trả lỗi về client
}

$conn->close(); 
exit();
?>