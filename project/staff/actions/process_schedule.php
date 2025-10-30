<?php
session_start();
require_once __DIR__ . '/../../config/db_connection.php'; // Đường dẫn đúng

// Kiểm tra đăng nhập và vai trò Staff
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
    // Có thể trả về lỗi JSON hoặc chuyển hướng nếu không phải AJAX
    die('Lỗi: Bạn không có quyền truy cập.');
}

// Kiểm tra phương thức POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die('Lỗi: Phương thức không hợp lệ.');
}

// --- Lấy dữ liệu từ form ---
$course_id = filter_input(INPUT_POST, 'course_id', FILTER_VALIDATE_INT);
$instructor_id = filter_input(INPUT_POST, 'instructor_id', FILTER_VALIDATE_INT);
$schedule_id = filter_input(INPUT_POST, 'schedule_id', FILTER_VALIDATE_INT); // Có thể null khi thêm mới
$schedule_date = trim($_POST['schedule_date'] ?? '');
$start_time = trim($_POST['start_time'] ?? '');
$end_time = trim($_POST['end_time'] ?? '');
$room = trim($_POST['room'] ?? '');

// --- Validate dữ liệu cơ bản ---
if (!$course_id || !$instructor_id || empty($schedule_date) || empty($start_time) || empty($end_time) || empty($room)) {
    $_SESSION['schedule_message'] = 'Lỗi: Vui lòng điền đầy đủ thông tin bắt buộc.';
    $_SESSION['schedule_message_type'] = 'danger';
    header("Location: ../Dashboard.php?page=ManageSchedule&course_id=" . $course_id); // Quay lại trang trước
    exit();
}

// Validate định dạng thời gian và ngày (có thể chặt chẽ hơn)
if (strtotime($start_time) === false || strtotime($end_time) === false || strtotime($schedule_date) === false) {
     $_SESSION['schedule_message'] = 'Lỗi: Định dạng ngày hoặc giờ không hợp lệ.';
     $_SESSION['schedule_message_type'] = 'danger';
     header("Location: ../Dashboard.php?page=ManageSchedule&course_id=" . $course_id);
     exit();
}

// Validate logic: End time > Start time
if (strtotime($end_time) <= strtotime($start_time)) {
    $_SESSION['schedule_message'] = 'Lỗi: Giờ kết thúc phải sau giờ bắt đầu.';
    $_SESSION['schedule_message_type'] = 'danger';
    header("Location: ../Dashboard.php?page=ManageSchedule&course_id=" . $course_id);
    exit();
}

// (Nâng cao) Validate ngày học nằm trong khoảng thời gian khóa học
$stmtCourseRange = $conn->prepare("SELECT StartDate, EndDate FROM course WHERE CourseID = ?");
$stmtCourseRange->bind_param("i", $course_id);
$stmtCourseRange->execute();
$courseRangeResult = $stmtCourseRange->get_result();
if($courseRangeData = $courseRangeResult->fetch_assoc()) {
    if ($schedule_date < $courseRangeData['StartDate'] || $schedule_date > $courseRangeData['EndDate']) {
        $_SESSION['schedule_message'] = 'Lỗi: Ngày học phải nằm trong khoảng thời gian diễn ra khóa học ('. $courseRangeData['StartDate'] .' - '. $courseRangeData['EndDate'] .').';
        $_SESSION['schedule_message_type'] = 'danger';
        header("Location: ../Dashboard.php?page=ManageSchedule&course_id=" . $course_id);
        exit();
    }
}
$stmtCourseRange->close();


// --- Thực hiện INSERT hoặc UPDATE ---
if ($schedule_id > 0) {
    // --- Chế độ SỬA ---
    $sql = "UPDATE schedule SET `Date` = ?, StartTime = ?, EndTime = ?, Room = ?, InstructorID = ? WHERE ScheduleID = ? AND CourseID = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("ssssiii", $schedule_date, $start_time, $end_time, $room, $instructor_id, $schedule_id, $course_id);
        if ($stmt->execute()) {
            $_SESSION['schedule_message'] = 'Cập nhật buổi học thành công!';
            $_SESSION['schedule_message_type'] = 'success';
        } else {
            error_log("Lỗi SQL UPDATE schedule: " . $stmt->error);
            $_SESSION['schedule_message'] = 'Lỗi: Không thể cập nhật buổi học. ' . $stmt->error;
            $_SESSION['schedule_message_type'] = 'danger';
        }
        $stmt->close();
    } else {
         error_log("Lỗi SQL prepare UPDATE schedule: " . $conn->error);
        $_SESSION['schedule_message'] = 'Lỗi hệ thống khi chuẩn bị cập nhật.';
        $_SESSION['schedule_message_type'] = 'danger';
    }
} else {
    // --- Chế độ THÊM MỚI ---
    $sql = "INSERT INTO schedule (CourseID, InstructorID, `Date`, StartTime, EndTime, Room) VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
     if ($stmt) {
        $stmt->bind_param("iissss", $course_id, $instructor_id, $schedule_date, $start_time, $end_time, $room);
         if ($stmt->execute()) {
             $_SESSION['schedule_message'] = 'Thêm buổi học mới thành công!';
             $_SESSION['schedule_message_type'] = 'success';
        } else {
            error_log("Lỗi SQL INSERT schedule: " . $stmt->error);
            $_SESSION['schedule_message'] = 'Lỗi: Không thể thêm buổi học mới. ' . $stmt->error;
            $_SESSION['schedule_message_type'] = 'danger';
        }
        $stmt->close();
    } else {
        error_log("Lỗi SQL prepare INSERT schedule: " . $conn->error);
        $_SESSION['schedule_message'] = 'Lỗi hệ thống khi chuẩn bị thêm mới.';
        $_SESSION['schedule_message_type'] = 'danger';
    }
}

$conn->close();

// --- Chuyển hướng về trang quản lý lịch học với khóa học đã chọn ---
header("Location: ../Dashboard.php?page=ManageSchedule&course_id=" . $course_id);
exit();
?>