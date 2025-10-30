<?php
session_start();
require_once __DIR__ . '/../../config/db_connection.php'; // Đường dẫn đúng

// Kiểm tra đăng nhập và vai trò Staff
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
    die('Lỗi: Bạn không có quyền truy cập.');
}

// Lấy ID từ URL
$schedule_id = filter_input(INPUT_GET, 'schedule_id', FILTER_VALIDATE_INT);
$course_id = filter_input(INPUT_GET, 'course_id', FILTER_VALIDATE_INT); // Lấy course_id để quay lại đúng trang

if (!$schedule_id || !$course_id) {
    $_SESSION['schedule_message'] = 'Lỗi: ID buổi học hoặc khóa học không hợp lệ.';
    $_SESSION['schedule_message_type'] = 'danger';
    // Chuyển hướng về trang dashboard chung nếu không có course_id
    header("Location: ../Dashboard.php?page=ManageSchedule" . ($course_id ? "&course_id=$course_id" : ""));
    exit();
}

// Cân nhắc: Kiểm tra xem có bản ghi student_attendance nào liên kết không trước khi xóa?
// $checkSql = "SELECT COUNT(*) as count FROM student_attendance WHERE ScheduleID = ?";
// $stmtCheck = $conn->prepare($checkSql);
// $stmtCheck->bind_param("i", $schedule_id);
// $stmtCheck->execute();
// $checkResult = $stmtCheck->get_result()->fetch_assoc();
// $stmtCheck->close();
// if ($checkResult['count'] > 0) {
//     $_SESSION['schedule_message'] = 'Lỗi: Không thể xóa buổi học đã có sinh viên điểm danh.';
//     $_SESSION['schedule_message_type'] = 'warning';
//     header("Location: ../Dashboard.php?page=ManageSchedule&course_id=" . $course_id);
//     exit();
// }


// Thực hiện xóa
$sql = "DELETE FROM schedule WHERE ScheduleID = ?";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $schedule_id);
    if ($stmt->execute()) {
         if ($stmt->affected_rows > 0) {
             $_SESSION['schedule_message'] = 'Xóa buổi học thành công!';
             $_SESSION['schedule_message_type'] = 'success';
         } else {
             $_SESSION['schedule_message'] = 'Không tìm thấy buổi học để xóa.';
             $_SESSION['schedule_message_type'] = 'warning';
         }
    } else {
         error_log("Lỗi SQL DELETE schedule: " . $stmt->error);
        $_SESSION['schedule_message'] = 'Lỗi: Không thể xóa buổi học. ' . $stmt->error;
        $_SESSION['schedule_message_type'] = 'danger';
    }
    $stmt->close();
} else {
    error_log("Lỗi SQL prepare DELETE schedule: " . $conn->error);
    $_SESSION['schedule_message'] = 'Lỗi hệ thống khi chuẩn bị xóa.';
    $_SESSION['schedule_message_type'] = 'danger';
}


$conn->close();

// Chuyển hướng về trang quản lý lịch học với khóa học đã chọn
header("Location: ../Dashboard.php?page=ManageSchedule&course_id=" . $course_id);
exit();
?>