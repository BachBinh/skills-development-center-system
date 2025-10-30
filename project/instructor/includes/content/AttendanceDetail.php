<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once(__DIR__ . '/../../../config/db_connection.php');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'instructor') {
    header("Location: /thiendinhsystem/home/Login.php");
    exit();
}

if (!isset($_GET['schedule_id'])) {
    echo "<div class='alert alert-danger'>❌ Không nhận được schedule_id!</div>";
    exit();
}
$scheduleId = intval($_GET['schedule_id']);

// Lấy thông tin lịch học
$scheduleQuery = $conn->prepare("
    SELECT s.ScheduleID, c.Title, s.Date, s.StartTime, s.EndTime
    FROM schedule s
    JOIN course c ON s.CourseID = c.CourseID
    WHERE s.ScheduleID = ?
");
$scheduleQuery->bind_param("i", $scheduleId);
$scheduleQuery->execute();
$scheduleResult = $scheduleQuery->get_result();

if ($scheduleResult->num_rows > 0) {
    $schedule = $scheduleResult->fetch_assoc();
} else {
    $schedule = ["Title" => "Không có dữ liệu", "Date" => "-", "StartTime" => "-", "EndTime" => "-"];
}
$scheduleQuery->close();

// Xử lý điểm danh
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['student_id'], $_POST['status'])) {
    $studentId = intval($_POST['student_id']);
    $status = $_POST['status'];

    $check = $conn->prepare("SELECT AttendanceID FROM student_attendance WHERE StudentID = ? AND ScheduleID = ?");
    $check->bind_param("ii", $studentId, $scheduleId);
    $check->execute();
    $result = $check->get_result();

    if ($result->num_rows > 0) {
        $update = $conn->prepare("UPDATE student_attendance SET Status = ? WHERE StudentID = ? AND ScheduleID = ?");
        $update->bind_param("sii", $status, $studentId, $scheduleId);
        $update->execute();
    } else {
        $insert = $conn->prepare("INSERT INTO student_attendance (StudentID, ScheduleID, Status) VALUES (?, ?, ?)");
        $insert->bind_param("iis", $studentId, $scheduleId, $status);
        $insert->execute();
    }

    echo "success";
    exit();
}

// Lấy danh sách học viên (không cần lọc server-side nữa)
$studentsQuery = $conn->prepare("
    SELECT st.StudentID, u.FullName, COALESCE(sa.Status, 'absent') AS Status
    FROM registration r
    JOIN student st ON r.StudentID = st.StudentID
    JOIN user u ON st.UserID = u.UserID
    LEFT JOIN student_attendance sa ON sa.StudentID = st.StudentID AND sa.ScheduleID = ?
    WHERE r.CourseID = (SELECT CourseID FROM schedule WHERE ScheduleID = ?)
    ORDER BY u.FullName ASC
");
$studentsQuery->bind_param("ii", $scheduleId, $scheduleId);
$studentsQuery->execute();
$students = $studentsQuery->get_result();
?>

<style>
.status-btn {
    border: 2px solid black; 
    font-weight: bold;
    text-align: center;
    color: black !important; 
}

.status-btn.btn-success, .status-btn.btn-outline-success {
    border: 2px solid #28a745 !important; 
}

.status-btn.btn-danger, .status-btn.btn-outline-danger {
    border: 2px solid #dc3545 !important;
}

.status-btn.btn-warning, .status-btn.btn-outline-warning {
    border: 2px solid #ffc107 !important; 
}

.table {
    width: 100%;
}

.table th, .table td {
    width: 45%; 
    text-align: center; 
    vertical-align: middle; 
}
.table td:nth-child(1), .table th:nth-child(1) {
    text-align: left !important;
}
</style>

<h3>✅ Điểm danh buổi học</h3>
<p><strong>Khóa học:</strong> <?= htmlspecialchars($schedule['Title']) ?> | <strong>Ngày:</strong> <?= htmlspecialchars($schedule['Date']) ?> | <strong>Giờ:</strong> <?= htmlspecialchars($schedule['StartTime']) ?> - <?= htmlspecialchars($schedule['EndTime']) ?></p>

<!-- Ô tìm kiếm ngay trên trang -->
<div class="mb-3">
    <input type="text" id="searchStudent" class="form-control form-control-sm" placeholder="🔍 Nhập tên học viên...">
</div>

<table class="table table-bordered" id="studentTable">
    <thead class="table-dark">
        <tr>
            <th>Học viên</th>
            <th>Trạng thái</th>
        </tr>
    </thead>
    <tbody>
        <?php if ($students->num_rows > 0): ?>
            <?php while ($row = $students->fetch_assoc()): ?>
                <tr class="student-row">
                    <td class="student-name"><?= htmlspecialchars($row['FullName']) ?></td>
                    <td>
                        <div class="attendance-options" data-student="<?= $row['StudentID'] ?>">
                            <button class="btn status-btn <?= $row['Status'] === 'present' ? 'btn-success' : 'btn-outline-success' ?>" data-status="present">✅ Có mặt</button>
                            <button class="btn status-btn <?= $row['Status'] === 'absent' ? 'btn-danger' : 'btn-outline-danger' ?>" data-status="absent">❌ Vắng</button>
                            <button class="btn status-btn <?= $row['Status'] === 'late' ? 'btn-warning' : 'btn-outline-warning' ?>" data-status="late">⏰ Đi trễ</button>
                        </div>
                    </td>
                </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr class="no-data"><td colspan="2" class="text-center">❌ Không có học viên nào.</td></tr>
        <?php endif; ?>
    </tbody>
</table>

<script>
document.addEventListener("DOMContentLoaded", function() {
    // Xử lý tìm kiếm học viên
    document.getElementById("searchStudent").addEventListener("keyup", function() {
        let searchValue = this.value.toLowerCase();
        let rows = document.querySelectorAll(".student-row");
        let noDataMessage = document.querySelector(".no-data");

        let found = false;
        rows.forEach(row => {
            let name = row.querySelector(".student-name").textContent.toLowerCase();
            row.style.display = name.includes(searchValue) ? "" : "none";
            if (name.includes(searchValue)) found = true;
        });

        if (noDataMessage) {
            noDataMessage.style.display = found ? "none" : "";
        }
    });

    // Xử lý điểm danh
    document.querySelectorAll(".status-btn").forEach(button => {
        button.addEventListener("click", function() {
            let studentId = this.closest(".attendance-options").getAttribute("data-student");
            let status = this.getAttribute("data-status");

            fetch("/thiendinhsystem/instructor/includes/content/AttendanceDetail.php?schedule_id=<?= $scheduleId ?>", { 
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: `student_id=${studentId}&status=${status}`
            })
            .then(response => response.text())
            .then(data => {
                if (data.trim() === "success") {
                    let parent = this.closest(".attendance-options");
                    
                    parent.querySelectorAll(".status-btn").forEach(btn => {
                        btn.classList.remove("btn-success", "btn-danger", "btn-warning");
                        btn.classList.add("btn-outline-" + (btn.getAttribute("data-status") === "present" ? "success" : btn.getAttribute("data-status") === "absent" ? "danger" : "warning"));
                    });

                    this.classList.remove("btn-outline-" + status);
                    this.classList.add(status === "present" ? "btn-success" : status === "absent" ? "btn-danger" : "btn-warning");
                } else {
                    alert("Lỗi cập nhật điểm danh! Phản hồi: " + data);
                }
            })
            .catch(error => {
                alert("Lỗi hệ thống: " + error);
            });
        });
    });
});
</script>