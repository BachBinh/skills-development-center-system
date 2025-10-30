<?php
require_once(__DIR__ . '/../../../config/db_connection.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'instructor') {
    header("Location: ../../home/Login.php"); 
    exit();
}

$instructorId = null;
$userId = intval($_SESSION['user_id']);

$stmtInstructor = $conn->prepare("SELECT InstructorID FROM instructor WHERE UserID = ?");
if ($stmtInstructor) {
    $stmtInstructor->bind_param("i", $userId);
    $stmtInstructor->execute();
    $resultInstructor = $stmtInstructor->get_result();
    if ($resultInstructor->num_rows > 0) {
        $instructorId = $resultInstructor->fetch_assoc()['InstructorID'];
    }
    $stmtInstructor->close();
}

if ($instructorId === null) {
    error_log("Could not find InstructorID for UserID: " . $userId);
    echo "<div class='alert alert-danger'>Lỗi: Không thể xác định thông tin giảng viên. Vui lòng thử đăng nhập lại.</div>";
    exit();
}
$instructorCourses = [];
$sqlCourses = "SELECT CourseID, Title FROM course WHERE InstructorID = ? ORDER BY Title ASC";
$stmtCourses = $conn->prepare($sqlCourses);
if ($stmtCourses) {
    $stmtCourses->bind_param("i", $instructorId);
    $stmtCourses->execute();
    $resultCourses = $stmtCourses->get_result();
    $instructorCourses = $resultCourses->fetch_all(MYSQLI_ASSOC); 
    $stmtCourses->close();
} else {
    error_log("SQL Prepare Error (fetching instructor courses): " . $conn->error);

}
$selectedCourseId = isset($_GET['course_id']) ? intval($_GET['course_id']) : 0; 
$fromDateInput = isset($_GET['from_date']) ? $_GET['from_date'] : '';
$toDateInput = isset($_GET['to_date']) ? $_GET['to_date'] : '';  

$todayDate = date('Y-m-d');
$defaultEndDate = date('Y-m-d', strtotime('+7 days'));
$effectiveStartDate = (!empty($fromDateInput) && strtotime($fromDateInput) !== false) ? $fromDateInput : $todayDate;
$effectiveEndDate = (!empty($toDateInput) && strtotime($toDateInput) !== false) ? $toDateInput : $defaultEndDate;
$sql = "
    SELECT s.ScheduleID, c.Title AS CourseTitle, s.Date, s.StartTime, s.EndTime, s.Room
    FROM schedule s
    JOIN course c ON s.CourseID = c.CourseID
    WHERE s.InstructorID = ?
    AND s.Date >= ? -- Apply the effective start date
    AND s.Date <= ? -- Apply the effective end date
";

$params = [$instructorId, $effectiveStartDate, $effectiveEndDate];
$types = "iss";

if (!empty($selectedCourseId)) { 
    $sql .= " AND s.CourseID = ?"; 
    $params[] = $selectedCourseId;
    $types .= "i"; 
}

$sql .= " ORDER BY s.Date ASC, s.StartTime ASC";

$stmt = $conn->prepare($sql);

if ($stmt === false) {
    error_log("SQL Prepare Error (Attendance Schedule): " . $conn->error);
    echo "<div class='alert alert-danger'>Lỗi khi chuẩn bị truy vấn lịch dạy. Vui lòng thử lại.</div>";
    exit();
}

if (!empty($types)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

$_SESSION['attendance_filter'] = [
    'course_id' => $selectedCourseId, 
    'from_date' => $fromDateInput,
    'to_date' => $toDateInput,
];

$selectedCourseName = '';
if (!empty($selectedCourseId)) {
    foreach ($instructorCourses as $course) {
        if ($course['CourseID'] == $selectedCourseId) {
            $selectedCourseName = $course['Title'];
            break;
        }
    }
}

?>

<h3 class="mb-3">📅 Lịch Dạy & Điểm Danh Sắp Tới</h3>
<p class="text-muted small">
    <?php
    if (empty($fromDateInput) && empty($toDateInput)) {
        echo "💡 Hiển thị lịch dạy từ hôm nay (" . date("d/m/Y", strtotime($todayDate)) . ") đến (" . date("d/m/Y", strtotime($defaultEndDate)) . ")";
    } else {
        echo "💡 Hiển thị lịch dạy theo bộ lọc: Từ " . date("d/m/Y", strtotime($effectiveStartDate)) . " đến " . date("d/m/Y", strtotime($effectiveEndDate)) . "";
    }
    if (!empty($selectedCourseId) && !empty($selectedCourseName)) {
        echo " cho khóa học '<strong>" . htmlspecialchars($selectedCourseName) . "</strong>'.";
    } elseif (empty($selectedCourseId)) {
        echo " cho tất cả các khóa học của bạn.";
    }
     if (empty($fromDateInput) && empty($toDateInput) && empty($selectedCourseId)) {
         echo " Sử dụng bộ lọc để xem lịch cụ thể.";
     } elseif (!empty($selectedCourseId) || !empty($fromDateInput) || !empty($toDateInput)) {
         echo " Xóa bộ lọc để xem lịch mặc định (7 ngày tới).";
     }
    ?>
</p>

<form method="GET" action="Dashboard.php" class="row g-3 mb-4 align-items-end">
    <input type="hidden" name="page" value="Attendance">

    <div class="col-md-4">
        <label for="att_course_id" class="form-label">📚 Lọc theo khóa học</label>
        <select id="att_course_id" name="course_id" class="form-select">
            <option value="">-- Tất cả khóa học --</option> <?php // Option to show all courses ?>
            <?php foreach ($instructorCourses as $course): ?>
                <option value="<?= $course['CourseID'] ?>" <?= ($selectedCourseId == $course['CourseID']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($course['Title']) ?>
                </option>
            <?php endforeach; ?>
             <?php if (empty($instructorCourses)): ?>
                <option value="" disabled>Bạn chưa được phân công khóa học nào</option>
            <?php endif; ?>
        </select>
    </div>
    <div class="col-md-3">
        <label for="att_from_date" class="form-label">📅 Lọc từ ngày</label>
        <input type="date" id="att_from_date" name="from_date" value="<?= htmlspecialchars($fromDateInput) ?>" class="form-control">
    </div>
    <div class="col-md-3">
        <label for="att_to_date" class="form-label">📅 Lọc đến ngày</label>
        <input type="date" id="att_to_date" name="to_date" value="<?= htmlspecialchars($toDateInput) ?>" class="form-control">
    </div>
    <div class="col-md-1 d-flex align-items-end">
        <button type="submit" class="btn btn-primary w-100">Tìm</button>
    </div>
    <div class="col-md-1 d-flex align-items-end">
        <a href="Dashboard.php?page=Attendance" class="btn btn-secondary w-100">🗑</a>
    </div>
</form>

<?php
    $isFiltered = !empty($selectedCourseId) || !empty($fromDateInput) || !empty($toDateInput);
    if ($isFiltered): ?>
    <p class="text-muted">🔎 Tìm thấy <strong><?= $result->num_rows ?></strong> lịch học phù hợp với bộ lọc.</p>
<?php elseif ($result && $result->num_rows > 0): // Only show default count if not filtering ?>
     <p class="text-muted">🗓️ Có <strong><?= $result->num_rows ?></strong> buổi học trong 7 ngày tới (cho tất cả khóa học).</p>
<?php endif; ?>

<div class="table-responsive">
    <table class="table table-bordered table-hover">
        <thead class="table-dark">
            <tr>
                <th>Khóa học</th>
                <th>Ngày</th>
                <th>Giờ</th>
                <th>Phòng</th>
                <th>Thao tác</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($result && $result->num_rows > 0): ?>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['CourseTitle']) ?></td>
                        <td><?= date("d/m/Y", strtotime($row['Date'])) ?></td>
                        <td><?= date("H:i", strtotime($row['StartTime'])) . " - " . date("H:i", strtotime($row['EndTime'])) ?></td>
                        <td><?= htmlspecialchars($row['Room']) ?></td>
                        <td>
                            <a href="Dashboard.php?page=AttendanceDetail&schedule_id=<?= $row['ScheduleID'] ?>" class="btn btn-sm btn-primary">
                                <i class="fas fa-user-check"></i> Điểm danh
                            </a>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="5" class="text-center">
                        <i class="fas fa-calendar-times"></i> Không có lịch học nào được tìm thấy
                        <?php
                            if ($isFiltered) {
                                echo " phù hợp với bộ lọc của bạn ";
                            } else {
                                echo " trong khoảng thời gian từ <strong>" . date("d/m/Y", strtotime($effectiveStartDate)) . "</strong> đến <strong>" . date("d/m/Y", strtotime($effectiveEndDate)) . "</strong>";
                            }
                            if (!empty($selectedCourseId) && !empty($selectedCourseName)) {
                                echo " cho khóa học '<strong>" . htmlspecialchars($selectedCourseName) . "</strong>'";
                            } elseif (empty($selectedCourseId) && $isFiltered) {
                                 echo " cho tất cả các khóa học ";
                            }
                         ?>.
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php
if ($stmt) {
    $stmt->close();
}
?>