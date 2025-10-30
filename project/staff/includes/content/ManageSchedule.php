<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
    echo "<div class='alert alert-danger' role='alert'><strong>Truy cập bị từ chối:</strong> Bạn không có quyền truy cập trang này.</div>";
    return; 
}

global $conn;
if (!isset($conn) || !$conn instanceof mysqli || $conn->connect_error) {
    $db_error = isset($conn) ? $conn->connect_error : "Biến \$conn không tồn tại hoặc không phải là đối tượng mysqli.";
    error_log("ManageSchedule.php DB Connection Error: " . $db_error);
    echo "<div class='alert alert-danger' role='alert'><strong>Lỗi hệ thống:</strong> Không thể kết nối đến cơ sở dữ liệu. Vui lòng thử lại sau.</div>";
    return; 
}

$courses = [];
$courseInstructors = [];
$selectedCourseID = 0;
$courseDetails = null;
$scheduleEvents = [];
$primaryInstructorName = '';
$errorMessage = '';
$jsonEvents = '[]';

// Kiểm tra nếu có tìm kiếm
$searchTerm = isset($_GET['search']) ? $_GET['search'] : "";

// Lọc danh sách khóa học theo tìm kiếm
$courseSql = "
    SELECT c.CourseID, c.Title, c.StartDate, c.EndDate, 
           (SELECT COUNT(*) FROM schedule WHERE CourseID = c.CourseID) AS session_count
    FROM course c
    WHERE c.Title LIKE ?
    ORDER BY c.Title ASC
";
$stmt = $conn->prepare($courseSql);
$searchPattern = "%$searchTerm%";
$stmt->bind_param("s", $searchPattern);
$stmt->execute();
$courseResult = $stmt->get_result();
if ($courseResult && $courseResult->num_rows > 0) {
    while($row = $courseResult->fetch_assoc()) {
        $courses[] = $row;
    }
} elseif (!$courseResult) {
    error_log("ManageSchedule.php - SQL Error fetching courses: " . $conn->error);
    $errorMessage = "Không thể tải danh sách khóa học. Vui lòng thử lại.";
}
$stmt->close();

if (isset($_GET['course_id']) && filter_var($_GET['course_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
    $selectedCourseID = (int)$_GET['course_id'];

    $detailSql = "SELECT c.CourseID, c.Title, c.StartDate, c.EndDate, u.FullName AS PrimaryInstructorName, c.InstructorID AS PrimaryInstructorID
                  FROM course c
                  LEFT JOIN instructor i ON c.InstructorID = i.InstructorID
                  LEFT JOIN user u ON i.UserID = u.UserID
                  WHERE c.CourseID = ?";
    $stmtDetail = $conn->prepare($detailSql);
    if ($stmtDetail) {
        $stmtDetail->bind_param("i", $selectedCourseID);
        $stmtDetail->execute();
        $detailResult = $stmtDetail->get_result();
        if ($detailResult->num_rows === 1) {
            $courseDetails = $detailResult->fetch_assoc();
            $primaryInstructorName = $courseDetails['PrimaryInstructorName'] ?? 'Chưa gán GV chính';
        } else {
            $errorMessage = "Không tìm thấy thông tin cho khóa học có ID: " . $selectedCourseID;
            $selectedCourseID = 0;
        }
        $stmtDetail->close();
    } else {
        error_log("ManageSchedule.php - SQL Prepare Error fetching course details: " . $conn->error);
        $errorMessage = "Lỗi hệ thống khi truy vấn chi tiết khóa học.";
        $selectedCourseID = 0;
    }

    if ($courseDetails) {
        $primaryInstructorID = $courseDetails['PrimaryInstructorID'] ?? null;

        $relatedInstructorIDs = [];
        if ($primaryInstructorID) {
            $relatedInstructorIDs[] = $primaryInstructorID;
        }

        $scheduledInstructorSql = "SELECT DISTINCT InstructorID FROM schedule WHERE CourseID = ? AND InstructorID IS NOT NULL";
        $stmtScheduledInst = $conn->prepare($scheduledInstructorSql);
        if ($stmtScheduledInst) {
            $stmtScheduledInst->bind_param("i", $selectedCourseID);
            $stmtScheduledInst->execute();
            $scheduledResult = $stmtScheduledInst->get_result();
            while ($rowInst = $scheduledResult->fetch_assoc()) {
                if (!in_array($rowInst['InstructorID'], $relatedInstructorIDs)) {
                    $relatedInstructorIDs[] = $rowInst['InstructorID'];
                }
            }
            $stmtScheduledInst->close();
        } else {
            error_log("ManageSchedule.php - SQL Prepare Error fetching scheduled instructors: " . $conn->error);
        }

        if (!empty($relatedInstructorIDs)) {
            $placeholders = implode(',', array_fill(0, count($relatedInstructorIDs), '?'));
            $types = str_repeat('i', count($relatedInstructorIDs));

            $instructorDetailSql = "SELECT i.InstructorID, u.FullName
                                    FROM instructor i
                                    JOIN user u ON i.UserID = u.UserID
                                    WHERE i.InstructorID IN ($placeholders) AND u.Status = 'active'
                                    ORDER BY u.FullName ASC";
            $stmtRelatedInst = $conn->prepare($instructorDetailSql);
            if ($stmtRelatedInst) {
                $stmtRelatedInst->bind_param($types, ...$relatedInstructorIDs);
                $stmtRelatedInst->execute();
                $relatedResult = $stmtRelatedInst->get_result();
                while ($rowRel = $relatedResult->fetch_assoc()){
                    $courseInstructors[$rowRel['InstructorID']] = $rowRel['FullName'];
                }
                $stmtRelatedInst->close();
            } else {
                error_log("ManageSchedule.php - SQL Prepare Error fetching related instructor details: " . $conn->error);
            }
        }

        $scheduleSql = "SELECT s.ScheduleID, s.`Date`, s.StartTime, s.EndTime, s.Room, s.InstructorID
                        FROM schedule s
                        WHERE s.CourseID = ?
                        ORDER BY s.`Date` ASC, s.StartTime ASC";
        $stmtSchedule = $conn->prepare($scheduleSql);
        if ($stmtSchedule) {
            $stmtSchedule->bind_param("i", $selectedCourseID);
            $stmtSchedule->execute();
            $scheduleResult = $stmtSchedule->get_result();
            if ($scheduleResult->num_rows > 0) {
                while($row = $scheduleResult->fetch_assoc()) {
                    $sessionInstructorName = isset($courseInstructors[$row['InstructorID']]) ? $courseInstructors[$row['InstructorID']] : 'Chưa gán GV';
                    $event = [
                        'id'           => $row['ScheduleID'],
                        'title'        => htmlspecialchars($row['Room'] . ' (' . $sessionInstructorName . ')'),
                        'start'        => $row['Date'] . 'T' . $row['StartTime'],
                        'end'          => $row['Date'] . 'T' . $row['EndTime'],
                        'extendedProps'=> [
                            'room'         => $row['Room'],
                            'rawDate'      => $row['Date'],
                            'rawStartTime' => $row['StartTime'],
                            'rawEndTime'   => $row['EndTime'],
                            'instructorId' => $row['InstructorID']
                        ],
                        'backgroundColor' => $row['InstructorID'] ? '#E0E0E0' : '#6c757d',
                        'borderColor'     => $row['InstructorID'] ? '#0a58ca' : '#5c636a'
                    ];
                    $scheduleEvents[] = $event;
                }
                $jsonEvents = json_encode($scheduleEvents, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
                if ($jsonEvents === false) {
                    error_log("ManageSchedule.php - json_encode failed: " . json_last_error_msg());
                    $errorMessage = "Lỗi hệ thống khi tạo dữ liệu lịch. Vui lòng thử lại.";
                    $jsonEvents = '[]';
                }
            }
            $stmtSchedule->close();
        } else {
            error_log("ManageSchedule.php - SQL Prepare Error fetching schedule: " . $conn->error);
            $errorMessage = "Lỗi hệ thống khi tải lịch trình.";
        }
    } 
}
?>

<style>
#calendar { max-width: 100%; margin: 0 auto; }
.fc-day-today { background: #e9f5ff !important; border: 1px solid #b6d4fe !important; }
.fc-event { 
    cursor: pointer; 
    border: none !important; 
    padding: 3px 6px !important; 
    font-size: 0.9em; 
    opacity: 1 !important; /* Ensure full opacity */
    transition: filter 0.2s ease; /* Smooth hover transition */
}
.fc-event:hover {
    filter: brightness(85%) !important; /* Darken on hover for better visibility */
}
.fc-event-main-custom { 
    line-height: 1.4; 
    color: white !important; /* Ensure text is white for contrast */
    overflow: hidden; 
    text-overflow: ellipsis; 
}
/* Ensure events in all views are fully opaque */
.fc-daygrid-event, .fc-timegrid-event, .fc-list-event { 
    opacity: 1 !important; 
}
.fc-daygrid-event-dot { 
    display: none !important; /* Remove dot in month view */
}
.fc-daygrid-block-event .fc-event-main, 
.fc-timegrid-event .fc-event-main, 
.fc-list-event .fc-event-main { 
    background-color: inherit !important; /* Inherit background from fc-event */
    opacity: 1 !important; 
}
.fc-event-main-custom { 
    line-height: 1.4; 
    color: #003087 !important; /* This applies to the time text */
    overflow: hidden; 
    text-overflow: ellipsis; 
}
.fc-event-main-custom div small {
    color: #003087 !important; /* This applies to the room and instructor text */
}
.fc-list-event td { 
    background-color: inherit !important; /* Ensure list view events have proper background */
}
#scheduleModal .modal-body { max-height: 70vh; overflow-y: auto; }
</style>

<div class="container-fluid">
    <h3 class="mb-4"><i class="fas fa-calendar-alt me-2"></i>Quản lý Lịch học</h3>

    <?php if (!empty($errorMessage)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($errorMessage) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Form tìm kiếm khóa học -->
    <form method="GET" action="Dashboard.php" class="mb-4" id="searchForm">
        <input type="hidden" name="page" value="ManageSchedule">
        <div class="row">
            <div class="col-md-8">
                <input type="text" name="search" class="form-control form-control-sm" placeholder="🔍 Tìm khóa học..." value="<?= htmlspecialchars($searchTerm) ?>">
            </div>
        </div>
        <div class="mt-2 d-flex justify-content-start gap-2">
            <button type="submit" class="btn btn-primary btn-sm">🔍 Tìm kiếm</button>
            <a href="Dashboard.php?page=ManageSchedule" class="btn btn-secondary btn-sm">♻️ Reset</a>
        </div>
    </form>

    <?php if (empty($courses)): ?>
        <p class="text-danger fw-bold text-center">🚫 Không tìm thấy dữ liệu</p>
    <?php else: ?>
        <div class="row row-cols-1 row-cols-md-3 g-4 mb-4">
            <?php foreach ($courses as $course): ?>
                <div class="col">
                    <div class="card text-center <?= $selectedCourseID == $course['CourseID'] ? 'border-primary' : '' ?>">
                        <div class="card-body">
                            <h5 class="card-title"><?= htmlspecialchars($course['Title']) ?></h5>
                            <p class="text-muted">
                                📅 Số buổi học: <strong><?= $course['session_count'] ?></strong><br>
                                🕒 Thời gian: <strong>
                                    <?= htmlspecialchars($course['StartDate'] ? date("d/m/Y", strtotime($course['StartDate'])) : 'N/A') ?> - 
                                    <?= htmlspecialchars($course['EndDate'] ? date("d/m/Y", strtotime($course['EndDate'])) : 'N/A') ?>
                                </strong>
                            </p>
                            <a href="Dashboard.php?page=ManageSchedule&course_id=<?= $course['CourseID'] ?>&search=<?= urlencode($searchTerm) ?>" class="btn btn-outline-primary">📅 Xem lịch học</a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($selectedCourseID > 0 && $courseDetails): ?>
        <hr class="my-4">
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-primary bg-gradient text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0 card-title"><i class="fas fa-info-circle me-2"></i>Thông tin: <strong><?= htmlspecialchars($courseDetails['Title']) ?></strong></h5>
                <?php if (!empty($courseInstructors)): ?>
                    <button class="btn btn-light text-primary fw-bold" data-bs-toggle="modal" data-bs-target="#scheduleModal" onclick="prepareAddModal(null)">
                        <i class="fas fa-plus-circle me-1"></i> Thêm Buổi Học
                    </button>
                <?php else: ?>
                    <span class="badge bg-warning text-dark fst-italic p-2">⚠️ Không có giảng viên để thêm lịch</span>
                <?php endif; ?>
            </div>
            <div class="card-body row">
                <div class="col-md-6 mb-2 mb-md-0">
                    <p class="mb-1"><strong><i class="fas fa-chalkboard-teacher text-primary me-1"></i>GV Chính:</strong> <?= htmlspecialchars($primaryInstructorName) ?></p>
                </div>
                <div class="col-md-6">
                    <p class="mb-1"><strong><i class="fas fa-calendar-check text-success me-1"></i>Thời gian:</strong> <?= htmlspecialchars($courseDetails['StartDate'] ? date("d/m/Y", strtotime($courseDetails['StartDate'])) : 'N/A') ?> - <?= htmlspecialchars($courseDetails['EndDate'] ? date("d/m/Y", strtotime($courseDetails['EndDate'])) : 'N/A') ?></p>
                    <small class="text-muted d-block">Lịch học chỉ nên được tạo trong khoảng thời gian này.</small>
                </div>
            </div>
        </div>

        <?php if (isset($_SESSION['schedule_message'])): ?>
            <div class="alert alert-<?= $_SESSION['schedule_message_type'] ?? 'info' ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($_SESSION['schedule_message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['schedule_message'], $_SESSION['schedule_message_type']); ?>
        <?php endif; ?>

        <div class="bg-white p-3 p-md-4 rounded border shadow-sm">
            <div id='calendar'>
                <div id="calendar-status" class="alert alert-light text-center border">
                    <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                    Đang chuẩn bị hiển thị lịch...
                </div>
            </div>
        </div>

        <?php if ($courseDetails && !empty($courseInstructors)): ?>
        <div class="modal fade" id="scheduleModal" tabindex="-1" aria-labelledby="scheduleModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="scheduleModalLabel"><i class="fas fa-edit me-2"></i>Thêm/Sửa Buổi học</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form id="scheduleForm" method="POST" action="actions/process_schedule.php" novalidate>
                        <div class="modal-body">
                            <input type="hidden" name="course_id" value="<?= $selectedCourseID ?>">
                            <input type="hidden" name="schedule_id" id="schedule_id">

                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label for="schedule_date" class="form-label">Ngày học <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" id="schedule_date" name="schedule_date" required
                                           min="<?= htmlspecialchars($courseDetails['StartDate'] ?? '') ?>"
                                           max="<?= htmlspecialchars($courseDetails['EndDate'] ?? '') ?>">
                                    <div class="invalid-feedback">Vui lòng chọn ngày học hợp lệ.</div>
                                    <div class="form-text mt-1">Trong khoảng: <?= htmlspecialchars($courseDetails['StartDate'] ? date("d/m/Y", strtotime($courseDetails['StartDate'])) : 'N/A') ?> - <?= htmlspecialchars($courseDetails['EndDate'] ? date("d/m/Y", strtotime($courseDetails['EndDate'])) : 'N/A') ?></div>
                                </div>
                                <div class="col-md-6">
                                    <label for="room" class="form-label">Phòng học <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="room" name="room" placeholder="VD: Phòng A101, Online, Link Zoom..." required maxlength="100">
                                    <div class="invalid-feedback">Vui lòng nhập phòng học.</div>
                                </div>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label for="start_time" class="form-label">Giờ bắt đầu <span class="text-danger">*</span></label>
                                    <input type="time" class="form-control" id="start_time" name="start_time" required>
                                    <div class="invalid-feedback">Vui lòng nhập giờ bắt đầu.</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="end_time" class="form-label">Giờ kết thúc <span class="text-danger">*</span></label>
                                    <input type="time" class="form-control" id="end_time" name="end_time" required>
                                    <div class="invalid-feedback">Vui lòng nhập giờ kết thúc (phải sau giờ bắt đầu).</div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="instructor_id_select" class="form-label">Giảng viên phụ trách <span class="text-danger">*</span></label>
                                <select class="form-select" id="instructor_id_select" name="instructor_id" required>
                                    <option value="" disabled selected>-- Chọn giảng viên --</option>
                                    <?php foreach ($courseInstructors as $id => $name): ?>
                                        <option value="<?= $id ?>">
                                             <?= htmlspecialchars($name) ?> (ID: <?= $id ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="invalid-feedback">Vui lòng chọn giảng viên.</div>
                                <div class="form-text mt-1">Chỉ hiển thị giảng viên liên quan đến khóa học này.</div>
                            </div>

                            <p class="text-danger mt-3 mb-0"><small>* Các trường là bắt buộc.</small></p>

                            <div id="deleteButtonContainer" class="mt-4 pt-3 border-top" style="display: none;">
                                <p class="text-danger mb-2"><small><strong>Lưu ý:</strong> Hành động này không thể hoàn tác.</small></p>
                                <button type="button" class="btn btn-danger w-100" id="deleteScheduleBtn"><i class="fas fa-trash-alt me-1"></i> Xóa Buổi Học Này</button>
                            </div>
                        </div>
                        <div class="modal-footer bg-light">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>Hủy bỏ</button>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Lưu Thay Đổi</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

    <?php elseif ($selectedCourseID > 0): ?>
    <?php else: ?>
        <div class="alert alert-info text-center mt-3 shadow-sm" role="alert">
            <i class="fas fa-info-circle fa-lg me-2 align-middle"></i>
            Vui lòng chọn một khóa học từ danh sách bên trên để xem hoặc quản lý lịch trình chi tiết.
        </div>
    <?php endif; ?>
</div>

<?php if ($selectedCourseID > 0 && $courseDetails): ?>
<script>
(function() {
    let scheduleModalInstance = null;
    let currentClickedEventId = null;
    let calendarInstance = null;

    const prepareAddModal = (selectionInfo) => {
        const modalElement = document.getElementById('scheduleModal');
        const scheduleForm = document.getElementById('scheduleForm');
        if (!modalElement || !scheduleForm) { console.error("Add Modal/Form not found."); return; }
        if (!scheduleModalInstance) scheduleModalInstance = bootstrap.Modal.getOrCreateInstance(modalElement);

        const modalTitle = document.getElementById('scheduleModalLabel');
        const scheduleIdInput = document.getElementById('schedule_id');
        const dateInput = document.getElementById('schedule_date');
        const startTimeInput = document.getElementById('start_time');
        const endTimeInput = document.getElementById('end_time');
        const roomInput = document.getElementById('room');
        const instructorSelect = document.getElementById('instructor_id_select');
        const deleteButtonContainer = document.getElementById('deleteButtonContainer');
        const courseStartDate = '<?= $courseDetails["StartDate"] ?? "" ?>';
        const courseEndDate = '<?= $courseDetails["EndDate"] ?? "" ?>';
        const primaryInstructorId = '<?= $courseDetails["PrimaryInstructorID"] ?? "" ?>';

        modalTitle.innerHTML = '<i class="fas fa-plus-circle me-2"></i>Thêm Buổi học mới';
        scheduleForm.reset();
        scheduleForm.classList.remove('was-validated');
        scheduleForm.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
        scheduleIdInput.value = '';
        if (deleteButtonContainer) deleteButtonContainer.style.display = 'none';
        currentClickedEventId = null;

        if (courseStartDate) dateInput.min = courseStartDate;
        if (courseEndDate) dateInput.max = courseEndDate;

        if (primaryInstructorId && instructorSelect) { instructorSelect.value = primaryInstructorId; }
        else if (instructorSelect) { instructorSelect.value = ""; }

        scheduleModalInstance.show();
    };

    const prepareEditModal = (event) => {
        const modalElement = document.getElementById('scheduleModal');
        const scheduleForm = document.getElementById('scheduleForm');
        if (!modalElement || !scheduleForm || !event) { console.error("Edit Modal/Form/Event not found."); return; }
        if (!scheduleModalInstance) scheduleModalInstance = bootstrap.Modal.getOrCreateInstance(modalElement);

        const modalTitle = document.getElementById('scheduleModalLabel');
        const scheduleIdInput = document.getElementById('schedule_id');
        const dateInput = document.getElementById('schedule_date');
        const startTimeInput = document.getElementById('start_time');
        const endTimeInput = document.getElementById('end_time');
        const roomInput = document.getElementById('room');
        const instructorSelect = document.getElementById('instructor_id_select');
        const deleteButtonContainer = document.getElementById('deleteButtonContainer');
        const courseStartDate = '<?= $courseDetails["StartDate"] ?? "" ?>';
        const courseEndDate = '<?= $courseDetails["EndDate"] ?? "" ?>';

        modalTitle.innerHTML = '<i class="fas fa-edit me-2"></i>Sửa Buổi học';
        scheduleForm.reset();
        scheduleForm.classList.remove('was-validated');
        scheduleForm.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));

        const scheduleId = event.id;
        currentClickedEventId = scheduleId;
        const props = event.extendedProps;

        scheduleIdInput.value = scheduleId;
        dateInput.value = props.rawDate || '';
        startTimeInput.value = props.rawStartTime ? props.rawStartTime.slice(0, 5) : '';
        endTimeInput.value = props.rawEndTime ? props.rawEndTime.slice(0, 5) : '';
        roomInput.value = props.room || '';
        if (instructorSelect) instructorSelect.value = props.instructorId || "";

        if (courseStartDate) dateInput.min = courseStartDate;
        if (courseEndDate) dateInput.max = courseEndDate;

        if (deleteButtonContainer) deleteButtonContainer.style.display = 'block';
        scheduleModalInstance.show();
    };

    const confirmDelete = (scheduleId, courseId) => {
        Swal.fire({
            title: 'Xác nhận xóa buổi học?',
            html: "Hành động này không thể hoàn tác. Dữ liệu điểm danh liên quan (nếu có) có thể bị ảnh hưởng.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: '<i class="fas fa-trash-alt me-1"></i> Xóa ngay',
            cancelButtonText: '<i class="fas fa-times me-1"></i> Hủy bỏ'
        }).then((result) => {
            if (result.isConfirmed) {
                if (scheduleModalInstance) scheduleModalInstance.hide();
                Swal.fire({ title: 'Đang xóa...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'actions/delete_schedule.php';

                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'schedule_id';
                idInput.value = scheduleId;
                form.appendChild(idInput);

                const courseInput = document.createElement('input');
                courseInput.type = 'hidden';
                courseInput.name = 'course_id';
                courseInput.value = courseId;
                form.appendChild(courseInput);

                document.body.appendChild(form);
                form.submit();
            }
        });
    };

    const initializeSchedulePage = () => {
        const calendarEl = document.getElementById('calendar');
        const calendarStatusEl = document.getElementById('calendar-status');
        const modalElement = document.getElementById('scheduleModal');

        if (!calendarEl) {
            console.error("Calendar element not found.");
            return;
        }

        if (typeof FullCalendar === 'undefined' || typeof bootstrap === 'undefined') {
            console.error("FullCalendar or Bootstrap not loaded.");
            if (calendarStatusEl) {
                calendarStatusEl.innerHTML = '<i class="fas fa-exclamation-triangle text-danger me-2"></i>Lỗi tải thư viện lịch. Vui lòng làm mới trang.';
                calendarStatusEl.className = 'alert alert-danger text-center';
                calendarStatusEl.style.display = 'block';
            }
            return;
        }

        if (calendarStatusEl) calendarStatusEl.style.display = 'none';

        const eventsJsonString = '<?php echo addslashes($jsonEvents); ?>';
        let calendarEvents = [];
        try {
            calendarEvents = JSON.parse(eventsJsonString);
            console.log("Parsed events:", calendarEvents);
        } catch (e) {
            console.error("Error parsing events JSON:", e);
            if (calendarStatusEl) {
                calendarStatusEl.innerHTML = '<i class="fas fa-exclamation-triangle text-danger me-2"></i>Lỗi dữ liệu lịch. Vui lòng liên hệ quản trị viên.';
                calendarStatusEl.className = 'alert alert-danger text-center';
                calendarStatusEl.style.display = 'block';
            }
            return;
        }

        calendarInstance = new FullCalendar.Calendar(calendarEl, {
        themeSystem: 'bootstrap',
        headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,listWeek' },
        locale: 'vi',
        buttonText: { today: 'Hôm nay', month: 'Tháng', week: 'Tuần', list: 'Danh sách' },
        initialView: 'timeGridWeek',
        slotMinTime: '06:00:00',
        slotMaxTime: '24:00:00',
        navLinks: true,
        selectable: true,
        selectMirror: true,
        dayMaxEvents: true,
        events: calendarEvents,
        eventClick: function(info) {
            info.jsEvent.preventDefault();
            prepareEditModal(info.event);
        },
        select: function(info) {
            prepareAddModal(info);
            if (calendarInstance) calendarInstance.unselect();
        },
        eventContent: function(arg) {
            let timeText = arg.timeText;
            let titleParts = arg.event.title.split('(');
            let roomDisplay = titleParts[0] ? titleParts[0].trim() : 'N/A';
            let instructorDisplay = titleParts[1] ? '(' + titleParts[1] : '(Chưa gán GV)';
            let customHtml = `<div class="fc-event-main-custom p-1"><small><strong>${timeText}</strong></small><div><small>${roomDisplay} ${instructorDisplay}</small></div></div>`;
            return { html: customHtml };
        },
        slotLabelFormat: {
            hour: '2-digit',
            minute: '2-digit',
            hour12: false // Chuyển sang định dạng 24 giờ
        },
        eventTimeFormat: {
            hour: '2-digit',
            minute: '2-digit',
            hour12: false // Chuyển thời gian của sự kiện sang định dạng 24 giờ
        },
        validRange: {
            start: '<?= $courseDetails["StartDate"] ?? '' ?>',
            end: '<?= $courseDetails["EndDate"] ? date("Y-m-d", strtotime($courseDetails["EndDate"] . ' +1 day')) : '' ?>'
        }
    });
    calendarInstance.render();

        if (modalElement) {
            if (!scheduleModalInstance) scheduleModalInstance = bootstrap.Modal.getOrCreateInstance(modalElement);
            modalElement.addEventListener('hidden.bs.modal', () => {
                const scheduleForm = document.getElementById('scheduleForm');
                if (scheduleForm) {
                    scheduleForm.reset();
                    scheduleForm.classList.remove('was-validated');
                    scheduleForm.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
                }
            });
        }

        const deleteScheduleBtn = document.getElementById('deleteScheduleBtn');
        if (deleteScheduleBtn) {
            deleteScheduleBtn.onclick = () => {
                if (currentClickedEventId) confirmDelete(currentClickedEventId, <?= $selectedCourseID ?>);
                else Swal.fire('Lỗi', 'Không xác định được buổi học.', 'error');
            };
        }

        const scheduleForm = document.getElementById('scheduleForm');
        if (scheduleForm) {
            scheduleForm.addEventListener('submit', function(event) {
                if (!scheduleForm.checkValidity()) {
                    event.preventDefault();
                    event.stopPropagation();
                    scheduleForm.classList.add('was-validated');
                }
            });
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeSchedulePage);
    } else {
        initializeSchedulePage();
    }
})();
</script>
<?php endif; ?>