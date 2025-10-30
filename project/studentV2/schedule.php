<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: ../home/Login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$fullName = $_SESSION['fullname'] ?? 'Học viên';

// 2. Kết nối CSDL
require_once(__DIR__ . '../../config/db_connection.php'); 
date_default_timezone_set('Asia/Ho_Chi_Minh');

$page_error = '';

// 3. Lấy StudentID
$studentId = 0;
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
     $page_error = "Lỗi khi chuẩn bị truy vấn thông tin học viên.";
     error_log("Prepare failed (get student id): (" . $conn->errno . ") " . $conn->error);
}

if ($studentId === 0 && empty($page_error)) {
    $page_error = "Lỗi: Không tìm thấy thông tin học viên liên kết với tài khoản này.";
}

// 4. Lấy giá trị bộ lọc và tìm kiếm từ GET
$filterCourse = isset($_GET['course_id']) ? (int)$_GET['course_id'] : '';
$filterInstructor = isset($_GET['instructor_id']) ? (int)$_GET['instructor_id'] : '';
$filterStartDate = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$filterEndDate = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';
$searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';
$hasFilter = !empty($filterCourse) || !empty($filterInstructor) || !empty($filterStartDate) || !empty($filterEndDate) || !empty($searchTerm);

// 5. Lấy danh sách Khóa học và Giảng viên để lọc (CHỈ TỪ CÁC KHÓA ĐÃ ĐƯỢC DUYỆT)
$registeredCoursesList = [];
$instructorsList = [];
if ($studentId > 0 && empty($page_error)) { 
    $sqlFilterOptions = "SELECT DISTINCT
                            c.CourseID,
                            c.Title AS CourseTitle,
                            i.InstructorID,
                            u.FullName AS InstructorName
                         FROM registration r
                         JOIN course c ON r.CourseID = c.CourseID
                         JOIN schedule sch ON c.CourseID = sch.CourseID 
                         LEFT JOIN instructor i ON sch.InstructorID = i.InstructorID
                         LEFT JOIN user u ON i.UserID = u.UserID
                         WHERE r.StudentID = ? AND r.Status = 'completed'"; // 'registered' 'completed'

    $stmtFilter = $conn->prepare($sqlFilterOptions);
    if($stmtFilter) {
        $stmtFilter->bind_param("i", $studentId);
        $stmtFilter->execute();
        $resultFilter = $stmtFilter->get_result();
        $tempInstructors = []; 
        while($row = $resultFilter->fetch_assoc()) {
            if (!isset($registeredCoursesList[$row['CourseID']])) {
                $registeredCoursesList[$row['CourseID']] = $row['CourseTitle'];
            }
            if ($row['InstructorID'] && !isset($tempInstructors[$row['InstructorID']])) {
                $instructorsList[$row['InstructorID']] = $row['InstructorName'];
                $tempInstructors[$row['InstructorID']] = true; 
            }
        }
        $stmtFilter->close();
        asort($registeredCoursesList); // sx tên khóa học
        asort($instructorsList);   // sx tên giảng viên
    } else {
        $page_error = "Lỗi khi lấy dữ liệu bộ lọc.";
        error_log("Prepare failed (filter options): (" . $conn->errno . ") " . $conn->error);
    }
}

// 6. Xây dựng và thực thi câu lệnh SQL chính
$schedule_list = [];
if ($studentId > 0 && empty($page_error)) {
    $sql = "SELECT
                sch.ScheduleID,
                c.CourseID,
                c.Title AS CourseTitle,
                sch.Date,
                sch.StartTime,
                sch.EndTime,
                sch.Room,
                i.InstructorID,
                u.FullName AS InstructorName
            FROM schedule sch
            JOIN course c ON sch.CourseID = c.CourseID
            JOIN registration r ON sch.CourseID = r.CourseID
            LEFT JOIN instructor i ON sch.InstructorID = i.InstructorID
            LEFT JOIN user u ON i.UserID = u.UserID
            WHERE r.StudentID = ?
              AND r.Status = 'completed'
              AND sch.Date >= CURDATE()";

    $params = [$studentId]; $types = "i"; $conditions = [];

    if (!empty($filterCourse)) { $conditions[] = "c.CourseID = ?"; $params[] = $filterCourse; $types .= "i"; }
    if (!empty($filterInstructor)) { $conditions[] = "sch.InstructorID = ?"; $params[] = $filterInstructor; $types .= "i"; }
    if (!empty($filterStartDate)) {
        $startDateFilter = max($filterStartDate, date('Y-m-d')); // Lấy ngày lớn hơn giữa bộ lọc và hôm nay
        $conditions[] = "sch.Date >= ?"; $params[] = $startDateFilter; $types .= "s";
    }
    if (!empty($filterEndDate)) {
        $conditions[] = "sch.Date <= ?"; $params[] = $filterEndDate; $types .= "s";
    }
    if (!empty($searchTerm)) { $conditions[] = "(c.Title LIKE ? OR u.FullName LIKE ?)"; $searchTermLike = '%' . $searchTerm . '%'; $params[] = $searchTermLike; $params[] = $searchTermLike; $types .= "ss"; }

    if (!empty($conditions)) {
        $sql .= " AND " . implode(" AND ", $conditions);
    }

    $sql .= " ORDER BY sch.Date ASC, sch.StartTime ASC"; 

    $stmt_schedule = $conn->prepare($sql);
    if ($stmt_schedule) {
        $stmt_schedule->bind_param($types, ...$params);
        $stmt_schedule->execute();
        $result_schedule = $stmt_schedule->get_result();
        while ($row = $result_schedule->fetch_assoc()) {
            $schedule_list[] = $row;
        }
        $stmt_schedule->close();
    } else {
        $page_error = "Lỗi khi truy vấn lịch học.";
        error_log("Prepare failed (main schedule query): (" . $conn->errno . ") " . $conn->error);
    }
}

$conn->close(); 

$todayDate = date('Y-m-d');

function getVietnameseDayOfWeek($englishDay) {
    $days_en = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    $days_vi = ['Thứ Hai', 'Thứ Ba', 'Thứ Tư', 'Thứ Năm', 'Thứ Sáu', 'Thứ Bảy', 'Chủ Nhật'];
    return str_replace($days_en, $days_vi, $englishDay);
}

?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <title>Lịch học - Kỹ Năng Pro</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="../img/favicon.ico" rel="icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
     <style>
        body { background-color: #f4f7f6; }
        .page-header-alt { background-color: #ffffff; padding: 25px 0; border-bottom: 1px solid #e0e0e0; margin-bottom: 30px; }
        .page-header-alt h1 { color: #343a40; font-size: 1.75rem; font-weight: 600; }
        .filter-panel { background-color: #ffffff; padding: 20px 25px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); border: 1px solid #e9ecef; }
        .filter-panel label { font-size: 0.85em; color: #6c757d; margin-bottom: 0.3rem; font-weight: 500;}
        .filter-panel .form-control-sm, .filter-panel .form-select-sm { font-size: 0.9em; }
        .schedule-day-group { margin-bottom: 30px; }
        .schedule-date-header { font-size: 1.3rem; font-weight: 600; color: #0d6efd; padding-bottom: 10px; margin-bottom: 20px; border-bottom: 2px solid #0d6efd; display: flex; align-items: center; }
        .schedule-date-header .badge { font-size: 0.8em; }
        .schedule-date-header.is-today { color: #dc3545; border-bottom-color: #dc3545; }
        .schedule-item { background-color: #ffffff; border: 1px solid #e9ecef; border-left: 5px solid #6c757d; padding: 15px 20px; margin-bottom: 15px; border-radius: 5px; box-shadow: 0 1px 2px rgba(0,0,0,0.04); display: flex; flex-wrap: wrap; align-items: center; transition: box-shadow 0.2s ease-in-out; }
        .schedule-item:hover { box-shadow: 0 4px 8px rgba(0,0,0,0.08); }
        .schedule-item.course-color-1 { border-left-color: #0d6efd; }
        .schedule-item.course-color-2 { border-left-color: #198754; }
        .schedule-item.course-color-3 { border-left-color: #ffc107; }
        .schedule-item.course-color-4 { border-left-color: #fd7e14; }
        .schedule-item.course-color-5 { border-left-color: #6f42c1; }
        .schedule-time { font-weight: 600; font-size: 1.1rem; color: #343a40; flex-basis: 150px; margin-bottom: 5px; }
        .schedule-details { flex-grow: 1; padding-left: 20px; border-left: 1px dashed #dee2e6; margin-left: 15px; margin-bottom: 5px; }
        .schedule-course-title { font-size: 1.1rem; font-weight: 500; margin-bottom: 5px; }
        .schedule-course-title a { color: inherit; text-decoration: none; transition: color 0.2s; }
        .schedule-course-title a:hover { color: #0d6efd; }
        .schedule-meta { font-size: 0.9em; color: #6c757d; }
        .schedule-meta i { width: 16px; text-align: center; margin-right: 5px; }
        .schedule-actions { flex-basis: 100px; text-align: right; margin-left: auto; }
        @media (max-width: 768px) {
            .schedule-item { flex-direction: column; align-items: flex-start; }
            .schedule-time { flex-basis: auto; margin-bottom: 10px; width: 100%; font-size: 1rem; }
            .schedule-details { padding-left: 0; margin-left: 0; border-left: none; width: 100%; }
            .schedule-course-title { font-size: 1rem; }
            .schedule-actions { margin-left: 0; margin-top: 10px; width: 100%; text-align: left; }
        }
        .alert i { vertical-align: middle; }
        .breadcrumb-item + .breadcrumb-item::before { color: #6c757d; }
     </style>
</head>

<body>
    <!-- Navbar -->
    <?php include 'nav.php'; ?>

    <!-- Page Header Alt -->
     <div class="container-fluid page-header-alt py-4">
        <div class="container">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center">
                 <h1 class="m-0 text-center text-md-start mb-2 mb-md-0"><i class="fas fa-calendar-alt me-2"></i>Lịch Học Của Bạn</h1>
                 <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-0 bg-transparent p-0 justify-content-center justify-content-md-end">
                        <li class="breadcrumb-item"><a href="index.php">Trang chủ</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Lịch học</li>
                    </ol>
                 </nav>
            </div>
        </div>
    </div>


    <!-- Schedule Section Start -->
    <div class="container pb-5 mt-4"> 

        <?php if (!empty($page_error)): ?>
            <div class="alert alert-danger" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($page_error) ?>
            </div>
        <?php endif; ?>

        <!-- Bộ lọc và Tìm kiếm Panel -->
        <div class="filter-panel mb-4">
            <form method="GET" action="schedule.php" class="row g-3 align-items-end">
                 <input type="hidden" name="page" value="schedule">

                <div class="col-lg-3 col-md-6 mb-lg-0 mb-2"> 
                    <label for="search" class="form-label"><i class="fas fa-search"></i> Tìm kiếm</label>
                    <input type="search" name="search" id="search" class="form-control form-control-sm" placeholder="Tên khóa học, giảng viên..." value="<?= htmlspecialchars($searchTerm) ?>">
                </div>
                <div class="col-lg col-md-6 mb-lg-0 mb-2">
                    <label for="course_id" class="form-label"><i class="fas fa-book"></i> Khóa học</label>
                    <select name="course_id" id="course_id" class="form-select form-select-sm">
                        <option value="">Tất cả khóa đã duyệt</option> 
                        <?php foreach ($registeredCoursesList as $id => $title): ?>
                            <option value="<?= $id ?>" <?= ($filterCourse == $id) ? 'selected' : '' ?>><?= htmlspecialchars($title) ?></option>
                        <?php endforeach; ?>
                         <?php if (empty($registeredCoursesList) && $studentId > 0): ?>
                            <option value="" disabled>Bạn chưa được duyệt vào khóa học nào</option>
                         <?php endif; ?>
                    </select>
                </div>
                <div class="col-lg col-md-6 mb-lg-0 mb-2">
                        <label for="instructor_id" class="form-label"><i class="fas fa-chalkboard-teacher"></i> Giảng viên</label>
                    <select name="instructor_id" id="instructor_id" class="form-select form-select-sm">
                        <option value="">Tất cả</option>
                            <?php foreach ($instructorsList as $id => $name): ?>
                            <option value="<?= $id ?>" <?= ($filterInstructor == $id) ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                        <?php endforeach; ?>
                         <?php if (empty($instructorsList) && !empty($registeredCoursesList)): ?>
                             <option value="" disabled>Chưa có GV được gán lịch</option>
                         <?php endif; ?>
                    </select>
                </div>
                <div class="col-lg-3 col-md-6 mb-lg-0 mb-2">
                    <label class="form-label"><i class="fas fa-calendar-alt"></i> Khoảng ngày</label>
                    <div class="input-group input-group-sm">
                        <input type="text" name="start_date" class="form-control flatpickr-date" placeholder="Từ" value="<?= htmlspecialchars($filterStartDate) ?>">
                        <input type="text" name="end_date" class="form-control flatpickr-date" placeholder="Đến" value="<?= htmlspecialchars($filterEndDate) ?>">
                    </div>
                </div>
                <div class="col-lg-auto col-md-12 d-flex align-items-end mt-2 mt-lg-0"> 
                    <div class="d-flex gap-2 w-100 justify-content-md-start justify-content-lg-end">
                            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Lọc</button>
                        <a href="schedule.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-times"></i> Reset</a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Hiển thị Lịch học -->
        <?php if ($studentId > 0 && empty($page_error)): ?>
             <?php if (!empty($schedule_list)): ?>
                <?php
                    $currentDisplayDate = null; $courseColorIndex = 0; $courseColors = [];
                    $availableColors = ['course-color-1', 'course-color-2', 'course-color-3', 'course-color-4', 'course-color-5']; // Các class màu CSS
                ?>
                 <?php foreach ($schedule_list as $item): ?>
                    <?php
                        if (!isset($courseColors[$item['CourseID']])) {
                            $courseColors[$item['CourseID']] = $availableColors[$courseColorIndex % count($availableColors)];
                            $courseColorIndex++;
                        }
                        $itemColorClass = $courseColors[$item['CourseID']];

                        if ($item['Date'] !== $currentDisplayDate) {
                            if ($currentDisplayDate !== null) { echo '</div>'; }
                            $currentDisplayDate = $item['Date'];
                            $dateFormatted = date('d/m/Y', strtotime($currentDisplayDate));
                            $dayOfWeekVi = getVietnameseDayOfWeek(date('l', strtotime($currentDisplayDate)));
                            $isToday = ($currentDisplayDate == $todayDate);
                    ?>
                        <div class="schedule-day-group">
                             <h4 class="schedule-date-header <?= $isToday ? 'is-today' : '' ?>">
                                <i class="fas fa-calendar-day fa-sm me-2"></i><?= $dayOfWeekVi ?>, Ngày <?= $dateFormatted ?>
                                <?= $isToday ? '<span class="badge bg-danger ms-auto">Hôm nay</span>' : '' ?>
                            </h4>
                    <?php }  ?>

                        <!-- Hiển thị từng buổi học -->
                        <div class="schedule-item <?= $itemColorClass ?>">
                            <div class="schedule-time">
                                <i class="far fa-clock me-1"></i><?= date('H:i', strtotime($item['StartTime'])) ?> - <?= date('H:i', strtotime($item['EndTime'])) ?>
                            </div>
                            <div class="schedule-details">
                                <div class="schedule-course-title">
                                     <?= htmlspecialchars($item['CourseTitle']) ?>
                                </div>
                                <div class="schedule-meta">
                                    <span class="me-3"><i class="fas fa-chalkboard-teacher text-secondary"></i> <?= htmlspecialchars($item['InstructorName'] ?: 'Chưa gán') ?></span>
                                    <span><i class="fas fa-map-marker-alt text-secondary"></i> <?= htmlspecialchars($item['Room'] ?: 'N/A') ?></span>
                                </div>
                            </div>
                    
                        </div>
                 <?php endforeach; ?>
                <?php if ($currentDisplayDate !== null) { echo '</div>'; }  ?>

             <?php else:  ?>
                <div class="alert alert-warning text-center mt-4" role="alert">
                    <i class="fas fa-calendar-times fa-lg me-2"></i>
                    <strong>Không tìm thấy lịch học!</strong><br>
                    <?= $hasFilter ? 'Không có buổi học nào phù hợp với bộ lọc của bạn cho các khóa học đã được duyệt.' : 'Bạn hiện chưa có lịch học nào cho các khóa học đã được duyệt.' ?>
                </div>
            <?php endif; ?>
        <?php elseif (empty($page_error)):  ?>
             <div class="alert alert-info text-center mt-4">Vui lòng đăng nhập để xem lịch học.</div>
        <?php endif;  ?>

    </div>

    <!-- Footer -->
    <?php include 'footer.php'; ?>


    <!-- Back to Top -->
    <a href="#" class="btn btn-lg btn-primary btn-lg-square back-to-top"><i class="fa fa-angle-double-up"></i></a>

    <!-- JavaScript Libraries -->
    <script src="https://code.jquery.com/jquery-3.4.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://npmcdn.com/flatpickr/dist/l10n/vn.js"></script>
    <script>
        flatpickr(".flatpickr-date", { dateFormat: "Y-m-d", locale: "vn", allowInput: true });
    </script>

</body>
</html>