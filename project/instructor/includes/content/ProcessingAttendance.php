<?php
require_once(__DIR__ . '/../../../config/db_connection.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $schedule_id = $_POST['schedule_id'];
    $statuses = $_POST['status']; // mảng [StudentID => status]

    foreach ($statuses as $student_id => $status) {
        // Kiểm tra xem đã có điểm danh chưa
        $check = $conn->prepare("SELECT * FROM student_attendance WHERE StudentID = ? AND ScheduleID = ?");
        $check->bind_param("ii", $student_id, $schedule_id);
        $check->execute();
        $res = $check->get_result();

        if ($res->num_rows > 0) {
            // Cập nhật
            $update = $conn->prepare("UPDATE student_attendance SET Status = ? WHERE StudentID = ? AND ScheduleID = ?");
            $update->bind_param("sii", $status, $student_id, $schedule_id);
            $update->execute();
        } else {
            // Thêm mới
            $insert = $conn->prepare("INSERT INTO student_attendance (StudentID, ScheduleID, Status) VALUES (?, ?, ?)");
            $insert->bind_param("iis", $student_id, $schedule_id, $status);
            $insert->execute();
        }
    }

    $_SESSION['success'] = "Điểm danh đã được lưu.";
    header("Location: Dashboard.php?page=Attendance");
    exit();
} else {
    header("Location: Dashboard.php?page=Attendance");
    exit();
}
