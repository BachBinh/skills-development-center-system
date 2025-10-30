<?php
if (session_status() == PHP_SESSION_NONE) { session_start(); }

require_once(__DIR__ . '/../../../config/db_connection.php'); 

// --- KIỂM TRA QUYỀN ADMIN ---
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../../home/Login.php"); 
    exit();
}

// --- HÀM KIỂM TRA XUNG ĐỘT TIỀM NĂNG CHO LỊCH CỐ ĐỊNH ---
function checkPotentialScheduleConflicts($conn, $instructorId, $room, $startDate, $endDate, $scheduleDaysStr, $startTime, $endTime, $excludeCourseId = null) {
    $conflictDetails = []; // Mảng chứa chi tiết xung đột theo ngày
    $fatalErrorMsg = null; // Thông báo lỗi nghiêm trọng (nếu có)

    // --- Kiểm tra thông tin đầu vào cơ bản ---
    if (empty($startDate) || empty($endDate) || empty($scheduleDaysStr) || empty($startTime) || empty($endTime)) {
        // Không đủ thông tin -> Trả về ngay, không thể kiểm tra
        return ['intended' => null, 'conflicts' => [], 'error' => "Thiếu thông tin lịch (Ngày/Giờ/Thứ) để kiểm tra xung đột."];
    }

    // --- Lấy tên giảng viên dự định (nếu có) ---
    $intendedInstructorName = null;
    if (!empty($instructorId)) {
        $stmt_get_name = $conn->prepare("SELECT u.FullName FROM user u JOIN instructor i ON u.UserID = i.UserID WHERE i.InstructorID = ?");
        if ($stmt_get_name) {
            $stmt_get_name->bind_param("i", $instructorId);
            $stmt_get_name->execute();
            $result_name = $stmt_get_name->get_result();
            if ($row_name = $result_name->fetch_assoc()) {
                $intendedInstructorName = htmlspecialchars($row_name['FullName']);
            }
            $stmt_get_name->close();
        } else {
             error_log("checkPotentialScheduleConflicts: Lỗi lấy tên GV dự định - " . $conn->error);
             // Có thể set $fatalErrorMsg nếu muốn báo lỗi này
             // $fatalErrorMsg = "Lỗi hệ thống khi lấy tên giảng viên.";
        }
    }

    // --- Xây dựng thông tin "ý định" ---
    $intendedInfo = [
        'room' => !empty($room) ? htmlspecialchars($room) : null,
        'instructor_id' => $instructorId, // Giữ lại ID nếu cần
        'instructor_name' => $intendedInstructorName // Tên đã được escape
    ];

    // --- Chuẩn bị dữ liệu ngày học ---
    $scheduleDays = explode(',', preg_replace('/\s+/', '', $scheduleDaysStr));
    $dayMap = ['Sun' => 0, 'Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6];
    $targetWeekdays = [];
    foreach ($scheduleDays as $day) { if (isset($dayMap[$day])) { $targetWeekdays[] = $dayMap[$day]; } }
    if (empty($targetWeekdays)) {
        // Không có ngày hợp lệ -> Không thể có xung đột
        return ['intended' => $intendedInfo, 'conflicts' => [], 'error' => null];
    }

    // --- Lặp qua các ngày để kiểm tra ---
    try {
        $currentDate = new DateTime($startDate);
        $endDateObj = new DateTime($endDate);
        // Thêm 1 ngày vào endDateObj để bao gồm cả ngày kết thúc trong vòng lặp <=
        // $endDateObj->modify('+1 day'); // Nếu logic <= thì không cần cái này
        $interval = new DateInterval('P1D');
    } catch (Exception $e) {
        error_log("checkPotentialScheduleConflicts: Lỗi ngày không hợp lệ - " . $e->getMessage());
        // Trả về lỗi nghiêm trọng
        return ['intended' => $intendedInfo, 'conflicts' => [], 'error' => "Lỗi định dạng ngày bắt đầu hoặc kết thúc."];
    }

    while ($currentDate <= $endDateObj) {
        $currentWeekday = (int)$currentDate->format('w');
        if (in_array($currentWeekday, $targetWeekdays)) {
            $dateStr = $currentDate->format('Y-m-d');
            $existingConflictDetails = ''; // Reset chi tiết xung đột cho ngày này

            // *** KIỂM TRA XUNG ĐỘT GIẢNG VIÊN (với lịch của các khóa học KHÁC) ***
            if (!empty($instructorId)) {
                $sql_check_inst = "SELECT sch.ScheduleID, c.Title AS CourseTitle
                                   FROM schedule sch JOIN course c ON sch.CourseID = c.CourseID
                                   WHERE sch.InstructorID = ? AND sch.Date = ? AND sch.StartTime < ? AND sch.EndTime > ?";
                $params_inst = [$instructorId, $dateStr, $endTime, $startTime]; $types_inst = "isss";
                if ($excludeCourseId !== null) { $sql_check_inst .= " AND sch.CourseID != ?"; $params_inst[] = $excludeCourseId; $types_inst .= "i"; }

                $stmt_check_inst = $conn->prepare($sql_check_inst);
                if ($stmt_check_inst) {
                    $stmt_check_inst->bind_param($types_inst, ...$params_inst); $stmt_check_inst->execute(); $result_inst = $stmt_check_inst->get_result();
                    $instConflictsCourses = [];
                    while ($row = $result_inst->fetch_assoc()) { $instConflictsCourses[] = htmlspecialchars($row['CourseTitle']); }
                    $stmt_check_inst->close();
                    if (!empty($instConflictsCourses)) {
                        $existingConflictDetails .= "GV đã có lịch với: " . implode(', ', array_unique($instConflictsCourses));
                    }
                } else { error_log("checkPotentialScheduleConflicts (GV): Prepare failed - " . $conn->error); $fatalErrorMsg = "Lỗi hệ thống khi kiểm tra lịch GV.";}
            }

            // *** KIỂM TRA XUNG ĐỘT PHÒNG HỌC (với lịch của các khóa học KHÁC) ***
            if (!empty($room) && !$fatalErrorMsg) { // Chỉ kiểm tra nếu chưa có lỗi nghiêm trọng
                 $sql_check_room = "SELECT sch.ScheduleID, c.Title AS CourseTitle, u.FullName AS InstructorName
                                    FROM schedule sch
                                    JOIN course c ON sch.CourseID = c.CourseID
                                    LEFT JOIN instructor i ON sch.InstructorID = i.InstructorID
                                    LEFT JOIN user u ON i.UserID = u.UserID
                                    WHERE sch.Room = ? AND sch.Date = ? AND sch.StartTime < ? AND sch.EndTime > ?";
                $params_room = [$room, $dateStr, $endTime, $startTime]; $types_room = "ssss";
                 if ($excludeCourseId !== null) { $sql_check_room .= " AND sch.CourseID != ?"; $params_room[] = $excludeCourseId; $types_room .= "i"; }

                 $stmt_check_room = $conn->prepare($sql_check_room);
                 if ($stmt_check_room) {
                    $stmt_check_room->bind_param($types_room, ...$params_room); $stmt_check_room->execute(); $result_room = $stmt_check_room->get_result();
                    $roomConflictsDetails = [];
                     while ($row = $result_room->fetch_assoc()) { $roomConflictsDetails[] = htmlspecialchars($row['CourseTitle']) . " (GV: " . htmlspecialchars($row['InstructorName'] ?? 'N/A') . ")"; }
                     $stmt_check_room->close();
                     if (!empty($roomConflictsDetails)) {
                         if (!empty($existingConflictDetails)) $existingConflictDetails .= ' | '; // Ngăn cách nếu cả GV và Phòng đều trùng
                         $existingConflictDetails .= "Phòng đã có lịch với: " . implode(', ', array_unique($roomConflictsDetails));
                     }
                 } else { error_log("checkPotentialScheduleConflicts (Room): Prepare failed - " . $conn->error); $fatalErrorMsg = "Lỗi hệ thống khi kiểm tra lịch phòng.";}
            }

            // Nếu có xung đột cho ngày này, thêm vào mảng kết quả
            if (!empty($existingConflictDetails)) {
                $conflictDetails[] = [
                    'date' => $dateStr,
                    'start_time' => $startTime, // Lưu lại để hiển thị
                    'end_time' => $endTime,
                    'details' => $existingConflictDetails // Chỉ lưu phần bị trùng
                ];
            }

            // Nếu có lỗi nghiêm trọng thì dừng kiểm tra
            if ($fatalErrorMsg) {
                break;
            }
        }
        $currentDate->add($interval);
    }

    // Trả về kết quả cuối cùng
    return [
        'intended' => $intendedInfo,
        'conflicts' => $conflictDetails, // Mảng các ngày bị xung đột
        'error' => $fatalErrorMsg // Lỗi nghiêm trọng (nếu có)
    ];
}

// --- HÀM KIỂM TRA XUNG ĐỘT PHÒNG HỌC ---
function checkRoomScheduleConflict($conn, $room, $date, $startTime, $endTime, $excludeScheduleId = null) {
    if (empty($room)) { return []; }
    $conflicts = [];
    $sql_check = "SELECT sch.ScheduleID, c.Title AS CourseTitle, sch.Date, sch.StartTime, sch.EndTime, u.FullName AS InstructorName 
                  FROM schedule sch
                  JOIN course c ON sch.CourseID = c.CourseID 
                  LEFT JOIN instructor i ON sch.InstructorID = i.InstructorID 
                  LEFT JOIN user u ON i.UserID = u.UserID
                  WHERE sch.Room = ? AND sch.Date = ? AND sch.StartTime < ? AND sch.EndTime > ?";
    $params = [$room, $date, $endTime, $startTime]; $types = "ssss";
    if ($excludeScheduleId !== null) { $sql_check .= " AND sch.ScheduleID != ?"; $params[] = $excludeScheduleId; $types .= "i"; }

    $stmt_check = $conn->prepare($sql_check);
    if ($stmt_check) {
        $stmt_check->bind_param($types, ...$params); $stmt_check->execute(); $result = $stmt_check->get_result();
        while ($row = $result->fetch_assoc()) { $conflicts[] = $row; }
        $stmt_check->close();
    } else { error_log("checkRoomScheduleConflict: Prepare failed - " . $conn->error); }
    return $conflicts; 
}

// --- HÀM KIỂM TRA XUNG ĐỘT GIẢNG VIÊN ---
function checkInstructorScheduleConflict($conn, $instructorId, $date, $startTime, $endTime, $excludeScheduleId = null) {
    if (empty($instructorId)) { return []; }
    $conflicts = [];
    $sql_check = "SELECT sch.ScheduleID, c.Title AS CourseTitle, sch.Date, sch.StartTime, sch.EndTime 
                  FROM schedule sch
                  JOIN course c ON sch.CourseID = c.CourseID 
                  WHERE sch.InstructorID = ? AND sch.Date = ? AND sch.StartTime < ? AND sch.EndTime > ?";
    $params = [$instructorId, $date, $endTime, $startTime]; $types = "isss";
    if ($excludeScheduleId !== null) { $sql_check .= " AND sch.ScheduleID != ?"; $params[] = $excludeScheduleId; $types .= "i"; }

    $stmt_check = $conn->prepare($sql_check);
    if ($stmt_check) {
        $stmt_check->bind_param($types, ...$params); $stmt_check->execute(); $result = $stmt_check->get_result();
        while ($row = $result->fetch_assoc()) { $conflicts[] = $row; }
        $stmt_check->close();
    } else { error_log("checkInstructorScheduleConflict: Prepare failed - " . $conn->error); }
    return $conflicts; 
}

// --- HÀM TẠO LỊCH CHI TIẾT (Thêm kiểm tra xung đột Giảng viên VÀ Phòng) ---
function generateDetailedSchedule($conn, $courseId, $startDate, $endDate, $scheduleDaysStr, $startTime, $endTime, $instructorId, $defaultRoom) { 
    if (empty($courseId) || empty($startDate) || empty($endDate) || empty($scheduleDaysStr) || empty($startTime) || empty($endTime)) {
        error_log("generateDetailedSchedule: Thiếu thông tin cần thiết cho CourseID: $courseId");
        $_SESSION['schedule_message'] = ['type' => 'danger', 'text' => "Lỗi: Thiếu thông tin lịch cố định để tạo lịch chi tiết."];
        return false; 
    }

    $today = date('Y-m-d');
    // --- 2. Xóa lịch cũ (chưa diễn ra) ---
    $stmt_delete = $conn->prepare("DELETE FROM schedule WHERE CourseID = ? AND Date >= ?");
    if ($stmt_delete) {
        $stmt_delete->bind_param("is", $courseId, $today);
        $stmt_delete->execute(); 
        $stmt_delete->close();
    } else {
         error_log("generateDetailedSchedule: Lỗi chuẩn bị xóa lịch cũ - CourseID: $courseId - " . $conn->error);
    }

    // --- 3. Chuẩn bị dữ liệu ngày học ---
    $scheduleDays = explode(',', preg_replace('/\s+/', '', $scheduleDaysStr)); 
    $dayMap = ['Sun' => 0, 'Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6];
    $targetWeekdays = [];
    foreach ($scheduleDays as $day) { if (isset($dayMap[$day])) { $targetWeekdays[] = $dayMap[$day]; } }
    if (empty($targetWeekdays)) {
        error_log("generateDetailedSchedule: Không có ngày hợp lệ cho CourseID: $courseId");
         $_SESSION['schedule_message'] = ['type' => 'warning', 'text' => "Không tạo được lịch chi tiết do ngày học không hợp lệ."];
        return false;
    }

    // --- 4. Chuẩn bị INSERT ---
    $stmt_insert = $conn->prepare("INSERT INTO schedule (CourseID, InstructorID, Date, StartTime, EndTime, Room) VALUES (?, ?, ?, ?, ?, ?)"); 
    if (!$stmt_insert) {
         error_log("generateDetailedSchedule: Lỗi chuẩn bị INSERT - " . $conn->error);
         $_SESSION['schedule_message'] = ['type' => 'danger', 'text' => "Lỗi hệ thống khi chuẩn bị tạo lịch chi tiết."];
         return false;
    }

    // --- 5. Lặp và INSERT ---
    try {
        $currentDate = new DateTime($startDate);
        $endDateObj = new DateTime($endDate);
        $interval = new DateInterval('P1D'); 
    } catch (Exception $e) {
         error_log("generateDetailedSchedule: Lỗi ngày bắt đầu/kết thúc không hợp lệ - " . $e->getMessage());
         $_SESSION['schedule_message'] = ['type' => 'danger', 'text' => "Lỗi định dạng ngày bắt đầu hoặc kết thúc."];
         $stmt_insert->close(); 
         return false;
    }
   
    $successCount = 0; $errorCount = 0; $conflictMessages = []; 

    while ($currentDate <= $endDateObj) {
        $currentWeekday = (int)$currentDate->format('w');
        if (in_array($currentWeekday, $targetWeekdays)) {
            $dateStr = $currentDate->format('Y-m-d');
            $currentInstructorId = $instructorId;
            $currentRoom = $defaultRoom;

            $canInsert = true;
            $instructorConflictDetails = '';
            $roomConflictDetails = '';

            // *** KIỂM TRA XUNG ĐỘT GIẢNG VIÊN ***
            if ($currentInstructorId) { // Chỉ kiểm tra nếu có giảng viên được chọn
                $instructorConflicts = checkInstructorScheduleConflict($conn, $currentInstructorId, $dateStr, $startTime, $endTime);
                if (!empty($instructorConflicts)) {
                    $canInsert = false;
                    $instructorConflictDetails = "GV trùng lịch với: ";
                    $tempCourses = [];
                    foreach($instructorConflicts as $conflict) { $tempCourses[] = htmlspecialchars($conflict['CourseTitle']); }
                    $instructorConflictDetails .= implode(', ', array_unique($tempCourses));
                }
            }

            // *** KIỂM TRA XUNG ĐỘT PHÒNG HỌC ***
            if ($canInsert && !empty($currentRoom)) {
                $roomConflicts = checkRoomScheduleConflict($conn, $currentRoom, $dateStr, $startTime, $endTime);
                if (!empty($roomConflicts)) {
                    $canInsert = false;
                    $roomConflictDetails = "Phòng trùng lịch với: ";
                    $tempCourses = [];
                    foreach($roomConflicts as $conflict) { $tempCourses[] = htmlspecialchars($conflict['CourseTitle']) . " (GV: " . htmlspecialchars($conflict['InstructorName'] ?? 'N/A') . ")"; }
                    $roomConflictDetails .= implode(', ', array_unique($tempCourses));
                }
            }

            // *** INSERT hoặc LƯU LỖI XUNG ĐỘT ***
            if ($canInsert) {
                 // Chỉ insert nếu có đủ thông tin giờ giấc
                 if($startTime && $endTime) {
                    $stmt_insert->bind_param("iissss",
                        $courseId, $currentInstructorId, $dateStr, $startTime, $endTime, $currentRoom
                    );
                    if ($stmt_insert->execute()) {
                        $successCount++;
                    } else {
                        $errorCount++;
                        $conflictMessages[] = "Ngày $dateStr: Lỗi CSDL khi thêm lịch.";
                        error_log("generateDetailedSchedule: Lỗi INSERT lịch cho CourseID: $courseId ngày $dateStr - " . $stmt_insert->error);
                    }
                 } else {
                     // Nếu không có giờ bắt đầu/kết thúc, coi như không tạo được buổi này (nhưng không phải lỗi)
                     // Hoặc bạn có thể quyết định đây là lỗi nếu giờ là bắt buộc
                     // errorCount++;
                     // $conflictMessages[] = "Ngày $dateStr: Thiếu thông tin giờ học.";
                 }
            } else {
                $errorCount++;
                $conflictMsg = "<strong>Ngày ".date('d/m/Y', strtotime($dateStr))."</strong> (".($startTime ? date('H:i', strtotime($startTime)) : '?')." - ".($endTime ? date('H:i', strtotime($endTime)) : '?')."): ";
                if ($instructorConflictDetails) $conflictMsg .= $instructorConflictDetails;
                if ($roomConflictDetails) $conflictMsg .= ($instructorConflictDetails ? ' | ' : '') . $roomConflictDetails;
                $conflictMessages[] = $conflictMsg;
            }
        }
        $currentDate->add($interval);
    }
    $stmt_insert->close();

    // --- 6. Trả về kết quả chi tiết ---
    $overallSuccess = ($successCount > 0 || ($successCount == 0 && $errorCount == 0)); // Thành công nếu tạo được ít nhất 1 hoặc ko có lỗi/xung đột nào

    // Xây dựng thông báo tóm tắt (có thể dùng hoặc không ở nơi gọi)
    $summaryMessage = '';
     if ($successCount > 0) {
        $summaryMessage = "Đã tự động tạo $successCount buổi học chi tiết. ";
    }
    if ($errorCount > 0) {
        $summaryMessage .= ($successCount > 0 ? "" : "") . "Có $errorCount buổi học không thể tạo do lỗi hoặc trùng lịch.";
        // Không cần thêm chi tiết vào đây, vì conflictMessages đã có
    }
     if ($successCount == 0 && $errorCount == 0 && !empty($scheduleDaysStr) && $startTime && $endTime) {
         // Kiểm tra nếu có lịch cố định nhưng không tạo được buổi nào (có thể do date range không chứa ngày học nào)
        $summaryMessage = "Không có buổi học nào được tạo tự động trong khoảng thời gian đã chọn với các ngày học đã định.";
     } elseif ($successCount == 0 && $errorCount == 0 && (empty($scheduleDaysStr) || !$startTime || !$endTime)) {
         $summaryMessage = "Chưa đủ thông tin lịch cố định (ngày/giờ) để tạo lịch chi tiết tự động.";
     }


    return [
        'success' => $overallSuccess,
        'successCount' => $successCount,
        'errorCount' => $errorCount,
        'conflictMessages' => $conflictMessages, // Trả về mảng chi tiết lỗi
        'summaryMessage' => $summaryMessage, // Thêm thông báo tóm tắt
        'fatalError' => false // Không có lỗi nghiêm trọng ở giai đoạn này
    ];
}
// --- KẾT THÚC HÀM TẠO LỊCH ---


// --- XỬ LÝ CRUD ---

// Search
$searchTitle = isset($_GET['title']) ? trim($_GET['title']) : '';
$searchInstructor = isset($_GET['instructor']) ? trim($_GET['instructor']) : '';
$fromDate = isset($_GET['from_date']) ? $_GET['from_date'] : '';
$toDate = isset($_GET['to_date']) ? $_GET['to_date'] : '';
$hasSearch = !empty($searchTitle) || !empty($searchInstructor) || !empty($fromDate) || !empty($toDate);


// --- Xử lý Add Course ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_course'])) {
    $isAdding = true; 
    $title = $_POST['title'];
    $description = $_POST['description'];
    $startDate = $_POST['start_date'];
    $endDate = $_POST['end_date'];
    $fee = $_POST['fee'];
    $instructorID = !empty($_POST['instructor_id']) ? (int)$_POST['instructor_id'] : null; 
    $maxStudents = $_POST['max_students']; // Thêm dòng này nếu bị thiếu
    $scheduleDaysStr = isset($_POST['schedule_days_combined']) ? trim($_POST['schedule_days_combined']) : ''; 
    $scheduleStartTime = !empty($_POST['schedule_start_time']) ? $_POST['schedule_start_time'] : null;
    $scheduleEndTime = !empty($_POST['schedule_end_time']) ? $_POST['schedule_end_time'] : null;
    $defaultRoom = !empty($_POST['default_room']) ? trim($_POST['default_room']) : null;
    
    $conflictCheckResult = checkPotentialScheduleConflicts(
        $conn,
        $instructorID,
        $defaultRoom,
        $startDate,
        $endDate,
        $scheduleDaysStr,
        $scheduleStartTime,
        $scheduleEndTime,
        isset($courseID) ? $courseID : null // $courseID chỉ tồn tại khi update
    );

    // --- Xử lý lỗi nghiêm trọng (nếu có) từ hàm kiểm tra ---
    if (!empty($conflictCheckResult['error'])) {
         $_SESSION['message'] = ['type' => 'danger', 'text' => "Lỗi khi kiểm tra lịch: " . htmlspecialchars($conflictCheckResult['error']) . " Vui lòng thử lại hoặc liên hệ quản trị viên."];
         header("Location: Dashboard.php?page=ManageCourses");
         exit();
    }

    // --- Kiểm tra xem có xung đột lịch cụ thể không ---
    if (!empty($conflictCheckResult['conflicts'])) {
        // *** CÓ XUNG ĐỘT -> BÁO LỖI, KHÔNG LƯU ***
        $messageType = 'danger';
        $messageText = "Không thể lưu khóa học do phát hiện xung đột lịch sau:<br>"; // Tiêu đề chính

        // --- Hiển thị "Ý định" một lần ---
        $intended = $conflictCheckResult['intended']; // Lấy thông tin ý định
        $intendedTextParts = [];
        if (!empty($intended['room'])) {
            $intendedTextParts[] = "Phòng <strong>" . $intended['room'] . "</strong>";
        }
         if (!empty($intended['instructor_name'])) {
            $intendedTextParts[] = "GV <strong>" . $intended['instructor_name'] . "</strong>";
        } elseif(!empty($intended['instructor_id'])) {
             $intendedTextParts[] = "GV ID <strong>" . $intended['instructor_id'] . "</strong> (chưa có tên)"; // Fallback
        }

        if (!empty($intendedTextParts)) {
             // Hiển thị thông tin phòng/GV bạn đang cố gắng xếp
             $messageText .= "<span class='text-primary d-block ms-2 my-1'>↳ <strong>Bạn muốn xếp:</strong> " . implode(' và ', $intendedTextParts) . ".</span>";
        } else {
             // Trường hợp không chọn phòng hoặc GV cụ thể (ít xảy ra nếu form yêu cầu)
             $messageText .= "<span class='text-muted d-block ms-2 my-1'>↳ Không có thông tin phòng/GV cụ thể được chỉ định trong yêu cầu này.</span>";
        }

        // --- Liệt kê các ngày bị xung đột ---
        $messageText .= "<span class='d-block ms-2 mb-1'><strong>Chi tiết các ngày bị trùng:</strong></span>";
        // Sử dụng list-group để hiển thị danh sách xung đột đẹp hơn
        $messageText .= "<ul class='list-group list-group-flush mt-1 mb-0 small' style='max-height: 180px; overflow-y: auto; background-color: transparent;'>";
        foreach ($conflictCheckResult['conflicts'] as $conflict) {
            // Mỗi xung đột là một list item
            $messageText .= "<li class='list-group-item bg-transparent border-bottom px-2 py-1'>";
            // Hiển thị ngày/giờ bị trùng
            $messageText .= "<strong>Ngày ".date('d/m/Y', strtotime($conflict['date']))."</strong>";
            $messageText .= " (".date('H:i', strtotime($conflict['start_time']))." - ".date('H:i', strtotime($conflict['end_time']))."):<br>";
            // Hiển thị chi tiết lịch hiện có đang gây ra xung đột
            $messageText .= "<span class='text-danger ms-3'>↳ <i class='fas fa-times-circle me-1'></i>Đã có lịch: " . $conflict['details'] . "</span>";
            $messageText .= "</li>";
        }
        $messageText .= "</ul><span class='d-block mt-2 small'>Vui lòng điều chỉnh Lịch cố định (Giảng viên, Phòng, Thời gian) và thử lại.</span>"; // Hướng dẫn người dùng

        // Lưu thông báo vào session
        $_SESSION['message'] = ['type' => $messageType, 'text' => $messageText];

        // Chuyển hướng về trang quản lý
        header("Location: Dashboard.php?page=ManageCourses");
        exit(); // Dừng script để không thực hiện lưu

    } else {
        // *** KHÔNG CÓ XUNG ĐỘT TIỀM NĂNG -> TIẾN HÀNH LƯU ***
        // ... (Toàn bộ code INSERT hoặc UPDATE và gọi generateDetailedSchedule như cũ) ...
         $sql_action = ""; // Xác định câu lệnh SQL (INSERT hoặc UPDATE)
         $params = [];     // Mảng tham số
         $types = "";      // Chuỗi kiểu dữ liệu

         if (isset($_POST['add_course'])) { // Nếu là THÊM MỚI
             $sql_action = "INSERT INTO course (Title, Description, StartDate, EndDate, Fee, InstructorID, MaxStudents,
                                               schedule_days, schedule_start_time, schedule_end_time, default_room)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
             $params = [
                 $title, $description, $startDate, $endDate, $fee, $instructorID, $maxStudents,
                 $scheduleDaysStr, $scheduleStartTime, $scheduleEndTime, $defaultRoom
             ];
             $types = "ssssdiissss";
         } elseif (isset($_POST['update_course'])) { // Nếu là CẬP NHẬT
             $sql_action = "UPDATE course SET Title = ?, Description = ?, StartDate = ?, EndDate = ?, Fee = ?, InstructorID = ?, MaxStudents = ?,
                                           schedule_days = ?, schedule_start_time = ?, schedule_end_time = ?, default_room = ?
                            WHERE CourseID = ?";
             $params = [
                 $title, $description, $startDate, $endDate, $fee, $instructorID, $maxStudents,
                 $scheduleDaysStr, $scheduleStartTime, $scheduleEndTime, $defaultRoom,
                 $courseID // Thêm courseID vào cuối cho WHERE
             ];
             $types = "ssssdiissssi";
         }

         if (!empty($sql_action)) {
             $stmt = $conn->prepare($sql_action);
             if ($stmt) {
                 $stmt->bind_param($types, ...$params);
                 if ($stmt->execute()) {
                     $currentCourseId = isset($courseID) ? $courseID : $conn->insert_id; // Lấy ID khóa học

                     // Gọi hàm tạo/cập nhật lịch chi tiết
                     $scheduleResult = generateDetailedSchedule(
                         $conn, $currentCourseId, $startDate, $endDate, $scheduleDaysStr,
                         $scheduleStartTime, $scheduleEndTime, $instructorID, $defaultRoom
                     );

                     // Xây dựng thông báo thành công (có thể kèm cảnh báo nếu generateDetailedSchedule gặp vấn đề lạ)
                     $messageType = 'success';
                     $actionText = isset($_POST['add_course']) ? "Thêm" : "Cập nhật";
                     $messageText = "$actionText khóa học thành công!";

                     if ($scheduleResult['successCount'] > 0) {
                         $messageText .= " Đã tự động tạo/cập nhật " . $scheduleResult['successCount'] . " buổi học chi tiết.";
                     }
                     if ($scheduleResult['errorCount'] > 0 && !$scheduleResult['fatalError']) {
                         $messageType = 'warning';
                         $messageText .= " Tuy nhiên, có " . $scheduleResult['errorCount'] . " buổi không thể tạo/cập nhật được (kiểm tra lỗi CSDL hoặc logic khác).";
                         // Optional: Add details for debugging
                         // $messageText .= "<br><strong>Chi tiết lỗi phát sinh:</strong>...";
                     } elseif ($scheduleResult['successCount'] == 0 && $scheduleResult['errorCount'] == 0 && !$scheduleResult['fatalError']) {
                          // Chỉ thêm summary nếu nó không rỗng
                          if(!empty($scheduleResult['summaryMessage'])) {
                             $messageText .= " " . htmlspecialchars($scheduleResult['summaryMessage']);
                          } elseif (!empty($scheduleDaysStr) && !empty($scheduleStartTime) && !empty($scheduleEndTime)) {
                             // Thêm thông báo mặc định nếu có lịch cố định nhưng ko tạo đc buổi nào
                             $messageText .= " Không có buổi học chi tiết nào được tạo (có thể do khoảng ngày không phù hợp).";
                          } else {
                             $messageText .= " Chưa đủ thông tin lịch cố định để tạo lịch chi tiết.";
                          }
                     }
                     if ($scheduleResult['fatalError']) {
                         $messageType = 'danger';
                         $messageText = "$actionText khóa học thành công, nhưng gặp lỗi nghiêm trọng khi tạo/tập nhật lịch: " . htmlspecialchars($scheduleResult['message']);
                     }

                     $_SESSION['message'] = ['type' => $messageType, 'text' => $messageText];

                 } else { // Lỗi thực thi INSERT/UPDATE
                      $actionTextLower = isset($_POST['add_course']) ? "thêm" : "cập nhật";
                     $_SESSION['message'] = ['type' => 'danger', 'text' => "Lỗi khi $actionTextLower khóa học: " . $stmt->error];
                     error_log("Execute failed ($actionTextLower course): (" . $stmt->errno . ") " . $stmt->error);
                 }
                 $stmt->close();
             } else { // Lỗi chuẩn bị INSERT/UPDATE
                  $actionTextLower = isset($_POST['add_course']) ? "thêm" : "cập nhật";
                 $_SESSION['message'] = ['type' => 'danger', 'text' => "Lỗi hệ thống khi chuẩn bị $actionTextLower khóa học: " . $conn->error];
                 error_log("Prepare failed ($actionTextLower course): (" . $conn->errno . ") " . $conn->error);
             }
         } // end if (!empty($sql_action))

        // Chuyển hướng sau khi xử lý xong (dù thành công hay lỗi)
        header("Location: Dashboard.php?page=ManageCourses");
        exit();

    } // Kết thúc nhánh else (không có xung đột)


    header("Location: Dashboard.php?page=ManageCourses");
    exit();
}

// --- Xử lý Update Course ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_course'])) {
    $isAdding = false;
    $courseID = $_POST['course_id'];
    $title = $_POST['title'];
    $description = $_POST['description'];
    $startDate = $_POST['start_date'];
    $endDate = $_POST['end_date'];
    $fee = $_POST['fee'];
    $instructorID = !empty($_POST['instructor_id']) ? (int)$_POST['instructor_id'] : null;
    $maxStudents = $_POST['max_students'];
    $scheduleDaysStr = isset($_POST['schedule_days_combined']) ? trim($_POST['schedule_days_combined']) : '';
    $scheduleStartTime = !empty($_POST['schedule_start_time']) ? $_POST['schedule_start_time'] : null;
    $scheduleEndTime = !empty($_POST['schedule_end_time']) ? $_POST['schedule_end_time'] : null;
    $defaultRoom = !empty($_POST['default_room']) ? trim($_POST['default_room']) : null;

    $conflictCheckResult = checkPotentialScheduleConflicts(
        $conn,
        $instructorID,
        $defaultRoom,
        $startDate,
        $endDate,
        $scheduleDaysStr,
        $scheduleStartTime,
        $scheduleEndTime,
        isset($courseID) ? $courseID : null // $courseID chỉ tồn tại khi update
    );

    // --- Xử lý lỗi nghiêm trọng (nếu có) từ hàm kiểm tra ---
    if (!empty($conflictCheckResult['error'])) {
         $_SESSION['message'] = ['type' => 'danger', 'text' => "Lỗi khi kiểm tra lịch: " . htmlspecialchars($conflictCheckResult['error']) . " Vui lòng thử lại hoặc liên hệ quản trị viên."];
         header("Location: Dashboard.php?page=ManageCourses");
         exit();
    }

    // --- Kiểm tra xem có xung đột lịch cụ thể không ---
    if (!empty($conflictCheckResult['conflicts'])) {
        // *** CÓ XUNG ĐỘT -> BÁO LỖI, KHÔNG LƯU ***
        $messageType = 'danger';
        $messageText = "Không thể lưu khóa học do phát hiện xung đột lịch sau:<br>"; // Tiêu đề chính

        // --- Hiển thị "Ý định" một lần ---
        $intended = $conflictCheckResult['intended']; // Lấy thông tin ý định
        $intendedTextParts = [];
        if (!empty($intended['room'])) {
            $intendedTextParts[] = "Phòng <strong>" . $intended['room'] . "</strong>";
        }
         if (!empty($intended['instructor_name'])) {
            $intendedTextParts[] = "GV <strong>" . $intended['instructor_name'] . "</strong>";
        } elseif(!empty($intended['instructor_id'])) {
             $intendedTextParts[] = "GV ID <strong>" . $intended['instructor_id'] . "</strong> (chưa có tên)"; // Fallback
        }

        if (!empty($intendedTextParts)) {
             // Hiển thị thông tin phòng/GV bạn đang cố gắng xếp
             $messageText .= "<span class='text-primary d-block ms-2 my-1'>↳ <strong>Bạn muốn xếp:</strong> " . implode(' và ', $intendedTextParts) . ".</span>";
        } else {
             // Trường hợp không chọn phòng hoặc GV cụ thể (ít xảy ra nếu form yêu cầu)
             $messageText .= "<span class='text-muted d-block ms-2 my-1'>↳ Không có thông tin phòng/GV cụ thể được chỉ định trong yêu cầu này.</span>";
        }

        // --- Liệt kê các ngày bị xung đột ---
        $messageText .= "<span class='d-block ms-2 mb-1'><strong>Chi tiết các ngày bị trùng:</strong></span>";
        // Sử dụng list-group để hiển thị danh sách xung đột đẹp hơn
        $messageText .= "<ul class='list-group list-group-flush mt-1 mb-0 small' style='max-height: 180px; overflow-y: auto; background-color: transparent;'>";
        foreach ($conflictCheckResult['conflicts'] as $conflict) {
            // Mỗi xung đột là một list item
            $messageText .= "<li class='list-group-item bg-transparent border-bottom px-2 py-1'>";
            // Hiển thị ngày/giờ bị trùng
            $messageText .= "<strong>Ngày ".date('d/m/Y', strtotime($conflict['date']))."</strong>";
            $messageText .= " (".date('H:i', strtotime($conflict['start_time']))." - ".date('H:i', strtotime($conflict['end_time']))."):<br>";
            // Hiển thị chi tiết lịch hiện có đang gây ra xung đột
            $messageText .= "<span class='text-danger ms-3'>↳ <i class='fas fa-times-circle me-1'></i>Đã có lịch: " . $conflict['details'] . "</span>";
            $messageText .= "</li>";
        }
        $messageText .= "</ul><span class='d-block mt-2 small'>Vui lòng điều chỉnh Lịch cố định (Giảng viên, Phòng, Thời gian) và thử lại.</span>"; // Hướng dẫn người dùng

        // Lưu thông báo vào session
        $_SESSION['message'] = ['type' => $messageType, 'text' => $messageText];

        // Chuyển hướng về trang quản lý
        header("Location: Dashboard.php?page=ManageCourses");
        exit(); // Dừng script để không thực hiện lưu

    } else {
        // *** KHÔNG CÓ XUNG ĐỘT TIỀM NĂNG -> TIẾN HÀNH LƯU ***
        // ... (Toàn bộ code INSERT hoặc UPDATE và gọi generateDetailedSchedule như cũ) ...
         $sql_action = ""; // Xác định câu lệnh SQL (INSERT hoặc UPDATE)
         $params = [];     // Mảng tham số
         $types = "";      // Chuỗi kiểu dữ liệu

         if (isset($_POST['add_course'])) { // Nếu là THÊM MỚI
             $sql_action = "INSERT INTO course (Title, Description, StartDate, EndDate, Fee, InstructorID, MaxStudents,
                                               schedule_days, schedule_start_time, schedule_end_time, default_room)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
             $params = [
                 $title, $description, $startDate, $endDate, $fee, $instructorID, $maxStudents,
                 $scheduleDaysStr, $scheduleStartTime, $scheduleEndTime, $defaultRoom
             ];
             $types = "ssssdiissss";
         } elseif (isset($_POST['update_course'])) { // Nếu là CẬP NHẬT
             $sql_action = "UPDATE course SET Title = ?, Description = ?, StartDate = ?, EndDate = ?, Fee = ?, InstructorID = ?, MaxStudents = ?,
                                           schedule_days = ?, schedule_start_time = ?, schedule_end_time = ?, default_room = ?
                            WHERE CourseID = ?";
             $params = [
                 $title, $description, $startDate, $endDate, $fee, $instructorID, $maxStudents,
                 $scheduleDaysStr, $scheduleStartTime, $scheduleEndTime, $defaultRoom,
                 $courseID // Thêm courseID vào cuối cho WHERE
             ];
             $types = "ssssdiissssi";
         }

         if (!empty($sql_action)) {
             $stmt = $conn->prepare($sql_action);
             if ($stmt) {
                 $stmt->bind_param($types, ...$params);
                 if ($stmt->execute()) {
                     $currentCourseId = isset($courseID) ? $courseID : $conn->insert_id; // Lấy ID khóa học

                     // Gọi hàm tạo/cập nhật lịch chi tiết
                     $scheduleResult = generateDetailedSchedule(
                         $conn, $currentCourseId, $startDate, $endDate, $scheduleDaysStr,
                         $scheduleStartTime, $scheduleEndTime, $instructorID, $defaultRoom
                     );

                     // Xây dựng thông báo thành công (có thể kèm cảnh báo nếu generateDetailedSchedule gặp vấn đề lạ)
                     $messageType = 'success';
                     $actionText = isset($_POST['add_course']) ? "Thêm" : "Cập nhật";
                     $messageText = "$actionText khóa học thành công!";

                     if ($scheduleResult['successCount'] > 0) {
                         $messageText .= " Đã tự động tạo/cập nhật " . $scheduleResult['successCount'] . " buổi học chi tiết.";
                     }
                     if ($scheduleResult['errorCount'] > 0 && !$scheduleResult['fatalError']) {
                         $messageType = 'warning';
                         $messageText .= " Tuy nhiên, có " . $scheduleResult['errorCount'] . " buổi không thể tạo/cập nhật được (kiểm tra lỗi CSDL hoặc logic khác).";
                         // Optional: Add details for debugging
                         // $messageText .= "<br><strong>Chi tiết lỗi phát sinh:</strong>...";
                     } elseif ($scheduleResult['successCount'] == 0 && $scheduleResult['errorCount'] == 0 && !$scheduleResult['fatalError']) {
                          // Chỉ thêm summary nếu nó không rỗng
                          if(!empty($scheduleResult['summaryMessage'])) {
                             $messageText .= " " . htmlspecialchars($scheduleResult['summaryMessage']);
                          } elseif (!empty($scheduleDaysStr) && !empty($scheduleStartTime) && !empty($scheduleEndTime)) {
                             // Thêm thông báo mặc định nếu có lịch cố định nhưng ko tạo đc buổi nào
                             $messageText .= " Không có buổi học chi tiết nào được tạo (có thể do khoảng ngày không phù hợp).";
                          } else {
                             $messageText .= " Chưa đủ thông tin lịch cố định để tạo lịch chi tiết.";
                          }
                     }
                     if ($scheduleResult['fatalError']) {
                         $messageType = 'danger';
                         $messageText = "$actionText khóa học thành công, nhưng gặp lỗi nghiêm trọng khi tạo/tập nhật lịch: " . htmlspecialchars($scheduleResult['message']);
                     }

                     $_SESSION['message'] = ['type' => $messageType, 'text' => $messageText];

                 } else { // Lỗi thực thi INSERT/UPDATE
                      $actionTextLower = isset($_POST['add_course']) ? "thêm" : "cập nhật";
                     $_SESSION['message'] = ['type' => 'danger', 'text' => "Lỗi khi $actionTextLower khóa học: " . $stmt->error];
                     error_log("Execute failed ($actionTextLower course): (" . $stmt->errno . ") " . $stmt->error);
                 }
                 $stmt->close();
             } else { // Lỗi chuẩn bị INSERT/UPDATE
                  $actionTextLower = isset($_POST['add_course']) ? "thêm" : "cập nhật";
                 $_SESSION['message'] = ['type' => 'danger', 'text' => "Lỗi hệ thống khi chuẩn bị $actionTextLower khóa học: " . $conn->error];
                 error_log("Prepare failed ($actionTextLower course): (" . $conn->errno . ") " . $conn->error);
             }
         } // end if (!empty($sql_action))

        header("Location: Dashboard.php?page=ManageCourses");
        exit();

    } // Kết thúc nhánh else (không có xung đột)

    header("Location: Dashboard.php?page=ManageCourses"); // Dòng này thực ra đã có trong cả 2 nhánh if/else
    exit();
}

// --- Xử lý Delete Course ---
if (isset($_GET['delete'])) {
    $courseID = (int)$_GET['delete'];
    $canDelete = true; // Cờ kiểm tra có thể xóa
    $delete_error_msg = '';

    // 1. Kiểm tra đăng ký
    $stmt_check = $conn->prepare("SELECT COUNT(*) FROM registration WHERE CourseID = ? AND Status IN ('registered', 'completed')");
    if ($stmt_check) {
        $stmt_check->bind_param("i", $courseID); $stmt_check->execute(); $count_reg = $stmt_check->get_result()->fetch_row()[0]; $stmt_check->close();
        if ($count_reg > 0) { $canDelete = false; $delete_error_msg = 'Có học viên đang học hoặc đã hoàn thành.'; }
    } else { $canDelete = false; $delete_error_msg = 'Lỗi kiểm tra đăng ký.';}

    // 2. Kiểm tra các ràng buộc khác (evaluation, content, payment, student_result - Tùy chọn xóa theo)
    // Ví dụ kiểm tra evaluation:
    // $stmt_check_eval = $conn->prepare("SELECT COUNT(*) FROM evaluation WHERE CourseID = ?"); ...
    // if ($count_eval > 0) { $canDelete = false; $delete_error_msg .= ' Có đánh giá.'; }

    // 3. Nếu có thể xóa
    if ($canDelete) {
        $conn->begin_transaction(); // Bắt đầu transaction
        try {
            // Xóa các bảng phụ thuộc trước (theo thứ tự ngược lại của khóa ngoại hoặc đặt ON DELETE CASCADE)
            // Ví dụ: Xóa schedule trước (vì nó không có ràng buộc với bảng khác cần xóa ở đây)
             $stmt_del_schedule = $conn->prepare("DELETE FROM schedule WHERE CourseID = ?");
             if ($stmt_del_schedule) { $stmt_del_schedule->bind_param("i", $courseID); $stmt_del_schedule->execute(); $stmt_del_schedule->close(); } 
             else { throw new Exception("Lỗi xóa lịch học: " . $conn->error); }

            // Xóa course content
             $stmt_del_content = $conn->prepare("DELETE FROM coursecontent WHERE CourseID = ?");
             if ($stmt_del_content) { $stmt_del_content->bind_param("i", $courseID); $stmt_del_content->execute(); $stmt_del_content->close(); } 
             else { throw new Exception("Lỗi xóa nội dung: " . $conn->error); }

            // Xóa các đăng ký đã hủy (nếu còn)
            $stmt_del_reg_cancel = $conn->prepare("DELETE FROM registration WHERE CourseID = ?");
             if ($stmt_del_reg_cancel) { $stmt_del_reg_cancel->bind_param("i", $courseID); $stmt_del_reg_cancel->execute(); $stmt_del_reg_cancel->close(); } 
             else { throw new Exception("Lỗi xóa đăng ký: " . $conn->error); }
             
             // Xóa payment, evaluation, student_result nếu không đặt ON DELETE CASCADE
             // ... (Thêm các lệnh DELETE tương tự) ...


            // Cuối cùng, xóa khóa học chính
            $stmt_del_course = $conn->prepare("DELETE FROM course WHERE CourseID = ?");
            if ($stmt_del_course) {
                $stmt_del_course->bind_param("i", $courseID);
                if ($stmt_del_course->execute()) {
                     $conn->commit(); // Hoàn tất transaction thành công
                     $_SESSION['message'] = ['type' => 'success', 'text' => 'Xóa khóa học và dữ liệu liên quan thành công!'];
                } else { throw new Exception("Lỗi xóa khóa học chính: " . $stmt_del_course->error); }
                $stmt_del_course->close();
            } else { throw new Exception("Lỗi chuẩn bị xóa khóa học chính: " . $conn->error); }

        } catch (Exception $e) {
            $conn->rollback(); // Hoàn tác nếu có lỗi
             $_SESSION['message'] = ['type' => 'danger', 'text' => 'Lỗi khi xóa khóa học: ' . $e->getMessage()];
             error_log("Delete Course Error (CourseID: $courseID): " . $e->getMessage());
        }
    } else {
        // Không thể xóa do ràng buộc
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Không thể xóa khóa học này. Lý do: ' . $delete_error_msg];
    }
    
    header("Location: Dashboard.php?page=ManageCourses");
    exit();
}

// --- Lấy danh sách giảng viên ---
$instructorsResult = $conn->query("SELECT i.InstructorID, u.FullName FROM instructor i JOIN user u ON i.UserID = u.UserID ORDER BY u.FullName");
$instructorsList = [];
if ($instructorsResult) { while ($row = $instructorsResult->fetch_assoc()) { $instructorsList[] = $row; } }

// --- Truy vấn lấy danh sách khóa học ---
$sql = "SELECT c.CourseID, c.Title, c.Description, c.StartDate, c.EndDate, c.Fee, c.MaxStudents, 
               c.schedule_days, c.schedule_start_time, c.schedule_end_time, c.default_room, /* Các cột lịch mới */
               u.FullName AS InstructorName, i.InstructorID,
               (SELECT COUNT(*) FROM registration r WHERE r.CourseID = c.CourseID AND r.Status = 'completed') AS RegisteredStudents
        FROM course c
        LEFT JOIN instructor i ON c.InstructorID = i.InstructorID
        LEFT JOIN user u ON i.UserID = u.UserID
        WHERE 1=1 "; 
$params = []; 
$types = "";

if (!empty($searchTitle)) {
    $sql .= " AND c.Title LIKE ?";
    $params[] = '%' . $searchTitle . '%';
    $types .= "s";
}

if (!empty($searchInstructor)) {
    $sql .= " AND u.FullName LIKE ?";
    $params[] = '%' . $searchInstructor . '%';
    $types .= "s";
}

if (!empty($fromDate)) {
    $sql .= " AND c.StartDate >= ?";
    $params[] = $fromDate;
    $types .= "s";
}

if (!empty($toDate)) {
    // Nếu chỉ muốn các khóa học KẾT THÚC trước ngày này
    // $sql .= " AND c.EndDate <= ?"; 
    // Nếu muốn các khóa học BẮT ĐẦU trước ngày này 
    $sql .= " AND c.StartDate <= ?"; 
    $params[] = $toDate;
    $types .= "s";
}
$sql .= " ORDER BY c.StartDate DESC, c.CourseID DESC"; 

$stmt = $conn->prepare($sql);
$courses = false; // Khởi tạo $courses
if ($stmt) {
    if (!empty($params)) { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $courses = $stmt->get_result(); // Gán kết quả vào $courses
} else {
    echo "Lỗi chuẩn bị câu lệnh SQL: " . $conn->error;
}

$days = ['Mon' => 'Thứ 2', 'Tue' => 'Thứ 3', 'Wed' => 'Thứ 4', 'Thu' => 'Thứ 5', 'Fri' => 'Thứ 6', 'Sat' => 'Thứ 7', 'Sun' => 'Chủ Nhật']; 

?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý khóa học</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .schedule-pattern { font-size: 0.85em; color: #6c757d; }
        .modal-backdrop { z-index: 1040 !important; } .modal { z-index: 1050 !important; }
        .table-responsive { min-height: 300px; } .card { box-shadow: 0 0.15rem 1.75rem 0 rgba(33, 40, 50, 0.15); }
        .card-header { font-weight: 600; } .badge-instructor { background-color: #fd7e14; color: white; }
        .student-count { font-size: 0.9em; text-align: center; margin-bottom: 5px; }
    </style>
</head>
<body>
    <div class="container-fluid py-3">
        <h3 class="mb-4">📚 Quản lý khóa học</h3>

        <!-- Hiển thị thông báo -->
        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert alert-<?= $_SESSION['message']['type'] ?> alert-dismissible fade show" role="alert">
                <?php echo $_SESSION['message']['text']; // Sử dụng echo trực tiếp vì đã xử lý HTML trong PHP ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['message']); ?>
        <?php endif; ?>
        <?php
        if (isset($_SESSION['schedule_message'])): ?>
            <div class="alert alert-<?= $_SESSION['schedule_message']['type'] ?> alert-dismissible fade show" role="alert">
                <!-- <i class="fas fa-calendar-check me-2 ms-2"></i><?php echo $_SESSION['schedule_message']['text']; ?> -->
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['schedule_message']); ?>
        <?php endif;  ?>

        <!-- Form tìm kiếm (Giữ nguyên) -->
         <div class="card mb-4">
            <div class="card-body">
            <form method="GET" class="row g-3">
                    <input type="hidden" name="page" value="ManageCourses">

                    <div class="col-md-3">
                        <label class="form-label">🔍 Tên khóa học</label>
                        <input type="text" name="title" value="<?= htmlspecialchars($searchTitle) ?>" class="form-control form-control-sm" placeholder="Tìm theo tên">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">👨‍🏫 Giảng viên</label>
                        <input type="text" name="instructor" value="<?= htmlspecialchars($searchInstructor) ?>" class="form-control form-control-sm" placeholder="Tìm giảng viên">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">📆 Từ ngày bắt đầu</label>
                        <input type="date" name="from_date" value="<?= htmlspecialchars($fromDate) ?>" class="form-control form-control-sm">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">📆 Đến ngày kết thúc</label> 
                        <input type="date" name="to_date" value="<?= htmlspecialchars($toDate) ?>" class="form-control form-control-sm">
                    </div>

                    <div class="col-md-2 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-sm btn-primary flex-grow-1">
                            <i class="fas fa-search me-1"></i> Tìm
                        </button>
                        <a href="Dashboard.php?page=ManageCourses" class="btn btn-sm btn-outline-secondary" title="Xóa bộ lọc">
                            <i class="fas fa-times"></i>
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Thông báo kết quả tìm kiếm -->
        <?php if ($hasSearch && $courses && $courses->num_rows > 0): // Thêm kiểm tra $courses tồn tại ?>
            <div class="alert alert-info mb-4">
                 <i class="fas fa-info-circle me-2"></i> Tìm thấy <strong><?= $courses->num_rows ?></strong> khóa học phù hợp.
            </div>
        <?php endif; ?>

        <!-- Nút thêm và bảng danh sách -->
        <div class="card">
             <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Danh sách khóa học</h5>
                 <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                    <i class="fas fa-plus me-1"></i> Thêm khóa học
                </button>
            </div>
            <div class="card-body p-0"> <!-- Thêm p-0 -->
                <div class="table-responsive">
                    <table class="table table-hover table-striped table-bordered mb-0"> <!-- Thêm mb-0 -->
                        <thead class="table-light text-center"> 
                            <tr>
                                <th width="15%">Tên khóa học</th>
                                <th width="15%">Mô tả</th>
                                <th width="10%">Giảng viên</th>
                                <th width="15%">Thời gian học</th>
                                <th width="15%">Lịch cố định</th> 
                                <th width="10%">Học phí</th>
                                <th width="10%">Sỉ số</th>
                                <th width="10%">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody class="align-middle"> 
                            <?php if ($courses && $courses->num_rows > 0): ?>
                                <?php while ($course = $courses->fetch_assoc()): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($course['Title']) ?></td>
                                        <td><small><?= htmlspecialchars(substr($course['Description'], 0, 70)) . (strlen($course['Description']) > 70 ? '...' : '') ?></small></td>
                                        <td class="text-center"> 
                                            <?php if ($course['InstructorName']): ?>
                                                <span class="badge badge-instructor"><?= htmlspecialchars($course['InstructorName']) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted fst-italic">Chưa gán</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center"> 
                                             <?= date('d/m/Y', strtotime($course['StartDate'])) ?><br>
                                            <span class="text-muted"> - </span><br>
                                            <?= date('d/m/Y', strtotime($course['EndDate'])) ?>
                                        </td>
                                        <td class="text-center schedule-pattern">
                                            <?php 
                                                $display_days = [];
                                                if (!empty($course['schedule_days'])) {
                                                    $days_arr = explode(',', $course['schedule_days']);
                                                    foreach ($days_arr as $day_en) {
                                                        if (isset($days[$day_en])) { // Sử dụng mảng $days đã định nghĩa ở đầu
                                                            $display_days[] = $days[$day_en];
                                                        }
                                                    }
                                                }
                                                echo !empty($display_days) ? implode(', ', $display_days) : '<i class="text-muted">Chưa có</i>';
                                                echo (!empty($course['schedule_start_time']) && !empty($course['schedule_end_time'])) 
                                                     ? '<br><small>('.date('H:i', strtotime($course['schedule_start_time'])).' - '.date('H:i', strtotime($course['schedule_end_time'])).')</small>' 
                                                     : '';
                                                 echo !empty($course['default_room']) ? '<br><small><i class="fas fa-map-marker-alt fa-xs"></i> '.htmlspecialchars($course['default_room']).'</small>' : '';
                                            ?>
                                        </td>
                                        <td class="text-end"><?= number_format($course['Fee'], 0, ',', '.') ?> VNĐ</td>
                                        <td class="text-center">
                                             <div class="student-count">
                                                <span class="fw-bold <?= ($course['RegisteredStudents'] >= $course['MaxStudents'] && $course['MaxStudents'] > 0) ? 'text-danger' : '' ?>">
                                                    <?= $course['RegisteredStudents'] ?>
                                                </span> / <?= $course['MaxStudents'] ?>
                                            </div>
                                            <button class="btn btn-xs btn-outline-info w-100 view-students-btn" 
                                                    data-courseid="<?= $course['CourseID'] ?>"
                                                    data-coursetitle="<?= htmlspecialchars($course['Title']) ?>">
                                                <i class="fas fa-users me-1"></i> Xem
                                            </button>
                                        </td>
                                        <td class="text-center"> 
                                            <div class="btn-group btn-group-sm">
                                                <button class="btn btn-outline-primary edit-btn" 
                                                        title="Sửa khóa học"
                                                        data-courseid="<?= $course['CourseID'] ?>"
                                                        data-title="<?= htmlspecialchars($course['Title']) ?>"
                                                        data-description="<?= htmlspecialchars($course['Description']) ?>"
                                                        data-startdate="<?= $course['StartDate'] ?>"
                                                        data-enddate="<?= $course['EndDate'] ?>"
                                                        data-fee="<?= $course['Fee'] ?>"
                                                        data-maxstudents="<?= $course['MaxStudents'] ?>"
                                                        data-instructorid="<?= $course['InstructorID'] ?? '' ?>"
                                                        data-schedule-days="<?= htmlspecialchars($course['schedule_days'] ?? '') ?>"
                                                        data-schedule-start-time="<?= htmlspecialchars($course['schedule_start_time'] ?? '') ?>"
                                                        data-schedule-end-time="<?= htmlspecialchars($course['schedule_end_time'] ?? '') ?>"
                                                        data-default-room="<?= htmlspecialchars($course['default_room'] ?? '') ?>"
                                                        data-bs-toggle="modal" data-bs-target="#editModal"> 
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <a href="Dashboard.php?page=ManageCourses&delete=<?= $course['CourseID'] ?>" 
                                                   class="btn btn-outline-danger delete-btn"
                                                   title="Xóa khóa học">
                                                    <i class="fas fa-trash-alt"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                                <?php if($stmt) $stmt->close(); ?>
                            <?php else: ?>
                                <tr><td colspan="8" class="text-center py-4 text-muted fst-italic">
                                    <?= $hasSearch ? 'Không tìm thấy khóa học nào phù hợp.' : 'Chưa có khóa học nào được tạo.' ?>
                                </td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal sửa khóa học -->
    <div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <form method="POST" class="modal-content" id="editCourseForm"> 
                <input type="hidden" name="course_id" id="edit_course_id">
                <div class="modal-header bg-primary text-white">
                     <h5 class="modal-title" id="editModalLabel">✏️ Chỉnh sửa khóa học</h5>
                     <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                     <div class="row g-3">
                        <div class="col-md-6">
                            <label for="edit_title" class="form-label">Tên khóa học <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" name="title" id="edit_title" required>
                        </div>
                        <div class="col-md-6">
                            <label for="edit_instructor_id" class="form-label">Giảng viên</label>
                            <select name="instructor_id" class="form-select form-select-sm" id="edit_instructor_id">
                                <option value="">-- Chọn giảng viên --</option>
                                <?php foreach ($instructorsList as $instructor): ?>
                                    <option value="<?= $instructor['InstructorID'] ?>"><?= htmlspecialchars($instructor['FullName']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label for="edit_description" class="form-label">Mô tả</label>
                            <textarea class="form-control form-control-sm" name="description" id="edit_description" rows="3"></textarea>
                        </div>
                        <div class="col-md-4">
                            <label for="edit_start_date" class="form-label">Ngày bắt đầu <span class="text-danger">*</span></label>
                            <input type="date" class="form-control form-control-sm" name="start_date" id="edit_start_date" required>
                        </div>
                        <div class="col-md-4">
                            <label for="edit_end_date" class="form-label">Ngày kết thúc <span class="text-danger">*</span></label>
                            <input type="date" class="form-control form-control-sm" name="end_date" id="edit_end_date" required>
                        </div>
                        <div class="col-md-4">
                            <label for="edit_max_students" class="form-label">Sỉ số tối đa <span class="text-danger">*</span></label>
                            <input type="number" class="form-control form-control-sm" name="max_students" id="edit_max_students" min="1" required>
                        </div>
                        <div class="col-md-6">
                            <label for="edit_fee" class="form-label">Học phí (VNĐ) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control form-control-sm" name="fee" id="edit_fee" min="0" required>
                        </div>

                        <hr class="my-3">
                         <h6 class="text-primary mb-1">Lịch học cố định (Tự động tạo lịch chi tiết)</h6>
                         <div class="col-12 mb-2">
                            <label class="form-label small">Ngày học trong tuần:</label>
                            <div class="d-flex flex-wrap gap-3">
                                <?php foreach ($days as $key => $label): ?>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input schedule-day-checkbox" type="checkbox" name="schedule_days[]" value="<?= $key ?>" id="edit_day_<?= $key ?>"> 
                                    <label class="form-check-label small" for="edit_day_<?= $key ?>"><?= $label ?></label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                             <input type="hidden" name="schedule_days_combined" id="edit_schedule_days_combined"> 
                        </div>
                        <div class="col-md-6">
                            <label for="edit_schedule_start_time" class="form-label">Giờ bắt đầu</label>
                            <input type="time" class="form-control form-control-sm" id="edit_schedule_start_time" name="schedule_start_time">
                        </div>
                        <div class="col-md-6">
                            <label for="edit_schedule_end_time" class="form-label">Giờ kết thúc</label>
                            <input type="time" class="form-control form-control-sm" id="edit_schedule_end_time" name="schedule_end_time">
                        </div>
                        <div class="col-md-4">
                            <label for="edit_default_room" class="form-label">Phòng mặc định</label>
                            <input type="text" class="form-control form-control-sm" id="edit_default_room" name="default_room" placeholder="VD: Room A">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" name="update_course" class="btn btn-sm btn-primary">
                       <i class="fas fa-save me-1"></i> Lưu thay đổi
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal thêm khóa học mới -->
    <div class="modal fade" id="addModal" tabindex="-1" aria-labelledby="addModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <form method="POST" class="modal-content" id="addCourseForm"> 
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="addModalLabel">➕ Thêm khóa học mới</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                         <div class="col-md-6">
                            <label for="add_title" class="form-label">Tên khóa học <span class="text-danger">*</span></label>
                            <input type="text" name="title" id="add_title" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-6">
                            <label for="add_instructor_id" class="form-label">Giảng viên</label>
                            <select name="instructor_id" id="add_instructor_id" class="form-select form-select-sm">
                                <option value="">-- Chọn giảng viên --</option>
                                <?php foreach ($instructorsList as $instructor): ?>
                                    <option value="<?= $instructor['InstructorID'] ?>"><?= htmlspecialchars($instructor['FullName']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label for="add_description" class="form-label">Mô tả</label>
                            <textarea class="form-control form-control-sm" id="add_description" name="description" rows="3"></textarea>
                        </div>
                        <div class="col-md-4">
                            <label for="add_start_date" class="form-label">Ngày bắt đầu <span class="text-danger">*</span></label>
                            <input type="date" name="start_date" id="add_start_date" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-4">
                            <label for="add_end_date" class="form-label">Ngày kết thúc <span class="text-danger">*</span></label>
                            <input type="date" name="end_date" id="add_end_date" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-4">
                            <label for="add_max_students" class="form-label">Sỉ số tối đa <span class="text-danger">*</span></label>
                            <input type="number" name="max_students" id="add_max_students" class="form-control form-control-sm" min="1" required>
                        </div>
                        <div class="col-md-6">
                            <label for="add_fee" class="form-label">Học phí (VNĐ) <span class="text-danger">*</span></label>
                            <input type="number" name="fee" id="add_fee" class="form-control form-control-sm" min="0" required>
                        </div>
                        
                         <hr class="my-3">
                          <h6 class="text-primary mb-1">Lịch học cố định (Tự động tạo lịch chi tiết)</h6>
                          <div class="col-12 mb-2">
                            <label class="form-label small">Ngày học trong tuần:</label>
                            <div class="d-flex flex-wrap gap-3">
                                <?php foreach ($days as $key => $label): ?>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input schedule-day-checkbox" type="checkbox" name="schedule_days[]" value="<?= $key ?>" id="add_day_<?= $key ?>">
                                    <label class="form-check-label small" for="add_day_<?= $key ?>"> <?= $label ?> </label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" name="schedule_days_combined" id="add_schedule_days_combined">
                        </div>
                        <div class="col-md-6">
                            <label for="add_schedule_start_time" class="form-label">Giờ bắt đầu</label>
                            <input type="time" class="form-control form-control-sm" id="add_schedule_start_time" name="schedule_start_time">
                        </div>
                        <div class="col-md-6">
                            <label for="add_schedule_end_time" class="form-label">Giờ kết thúc</label>
                            <input type="time" class="form-control form-control-sm" id="add_schedule_end_time" name="schedule_end_time">
                        </div>
                        <div class="col-md-4">
                            <label for="add_default_room" class="form-label">Phòng mặc định</label>
                            <input type="text" class="form-control form-control-sm" id="add_default_room" name="default_room" placeholder="VD: Room A">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" name="add_course" class="btn btn-sm btn-success">
                        <i class="fas fa-plus-circle me-1"></i> Thêm khóa học
                    </button>
                </div>
            </form>
        </div>
    </div>

     <!-- Modal danh sách học viên -->
     <div class="modal fade" id="studentsModal" tabindex="-1" aria-labelledby="studentsModalTitleLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable"> <!-- Thêm modal-dialog-scrollable -->
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title" id="studentsModalTitleLabel">Danh sách học viên</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="table-responsive">
                        <table class="table table-hover table-sm" id="studentsTable"> <!-- table-sm cho bảng gọn hơn -->
                            <thead class="table-light sticky-top"> <!-- sticky-top cho header bảng -->
                                <tr>
                                    <th>#</th>
                                    <th>Họ tên</th>
                                    <th>Email</th>
                                    <th>Điện thoại</th>
                                    <th>Ngày đăng ký</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Dữ liệu sẽ được load bằng AJAX -->
                                <tr><td colspan="5" class="text-center py-5"><i class="fas fa-spinner fa-spin"></i> Đang tải...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // --- HÀM XỬ LÝ CHECKBOX NGÀY HỌC ---
        function handleScheduleDayCheckboxes(formId) {
            const form = document.getElementById(formId);
            if (!form) return;
            const combinedInput = form.querySelector('input[name="schedule_days_combined"]');
            const checkboxes = form.querySelectorAll('.schedule-day-checkbox');

            form.addEventListener('submit', function(e) {
                if (combinedInput) {
                    const selectedDays = [];
                    checkboxes.forEach(cb => { if (cb.checked) { selectedDays.push(cb.value); } });
                    combinedInput.value = selectedDays.join(','); 
                }
            });
        }
        // --- KẾT THÚC HÀM XỬ LÝ CHECKBOX ---

        document.addEventListener('DOMContentLoaded', function() {
             handleScheduleDayCheckboxes('addCourseForm'); 
             handleScheduleDayCheckboxes('editCourseForm'); 

            // Xử lý modal sửa 
            const editModalElement = document.getElementById('editModal');
            if (editModalElement) { 
                // const editModal = new bootstrap.Modal(editModalElement); // Chỉ cần nếu mở bằng JS
                editModalElement.addEventListener('show.bs.modal', function(event) { // Sự kiện khi modal bắt đầu mở
                    const button = event.relatedTarget; // Nút đã trigger modal (edit-btn)
                    if (!button) return; // Thoát nếu không tìm thấy nút

                    // Lấy dữ liệu từ data attributes của nút
                    document.getElementById('edit_course_id').value = button.dataset.courseid || '';
                    document.getElementById('edit_title').value = button.dataset.title || '';
                    document.getElementById('edit_description').value = button.dataset.description || '';
                    document.getElementById('edit_start_date').value = button.dataset.startdate || '';
                    document.getElementById('edit_end_date').value = button.dataset.enddate || '';
                    document.getElementById('edit_fee').value = button.dataset.fee || '';
                    document.getElementById('edit_max_students').value = button.dataset.maxstudents || '';
                    document.getElementById('edit_instructor_id').value = button.dataset.instructorid || ""; 
                    
                    // --- LẤY VÀ SET DỮ LIỆU LỊCH CỐ ĐỊNH ---
                    const scheduleDaysString = button.dataset.scheduleDays || ''; 
                    const scheduleDaysArray = scheduleDaysString ? scheduleDaysString.split(',') : [];
                    document.querySelectorAll('#editModal .schedule-day-checkbox').forEach(cb => cb.checked = false);
                    scheduleDaysArray.forEach(day => {
                        const checkbox = document.getElementById(`edit_day_${day}`); 
                        if (checkbox) checkbox.checked = true;
                    });
                     document.getElementById('edit_schedule_start_time').value = button.dataset.scheduleStartTime || '';
                     document.getElementById('edit_schedule_end_time').value = button.dataset.scheduleEndTime || '';
                     document.getElementById('edit_default_room').value = button.dataset.defaultRoom || ''; 
                });
            }

            
            
            // Xử lý xem danh sách học viên (Cập nhật với xử lý lỗi tốt hơn)
            const studentsModalElement = document.getElementById('studentsModal');
             if (studentsModalElement) { // Kiểm tra modal tồn tại
                const studentsModal = new bootstrap.Modal(studentsModalElement);
                const studentsModalTitle = document.getElementById('studentsModalTitleLabel'); // Sửa ID cho khớp HTML
                const studentsTableBody = document.querySelector('#studentsTable tbody');
                
                document.querySelectorAll('.view-students-btn').forEach(btn => {
                    btn.addEventListener('click', function() {
                        const courseId = this.dataset.courseid;
                        const courseTitle = this.dataset.coursetitle;
                        
                        if (studentsModalTitle) {
                             studentsModalTitle.textContent = `Danh sách học viên - ${courseTitle}`;
                        }
                        
                        if (studentsTableBody) {
                             studentsTableBody.innerHTML = '<tr><td colspan="5" class="text-center py-4"><i class="fas fa-spinner fa-spin me-2"></i>Đang tải dữ liệu...</td></tr>';
                        }
                        studentsModal.show();
                        
                        // check path (v3)
                        const fetchUrl = `includes/content/get_course_students.php?course_id=${courseId}`; 

                        fetch(fetchUrl)
                            .then(response => {
                                if (!response.ok) {
                                    //lỗi HTTP (404, 500, 401,...)
                                    throw new Error(`Lỗi HTTP: ${response.status} ${response.statusText}`);
                                }
                                return response.json(); // response => JSON
                            })
                            .then(data => {
                                if (!studentsTableBody) return;

                                if (data.error) {
                                     console.error('Server error:', data.error);
                                     studentsTableBody.innerHTML = `<tr><td colspan="5" class="text-center py-4 text-danger"><i class="fas fa-exclamation-triangle me-2"></i>Lỗi từ máy chủ: ${data.error}</td></tr>`;
                                     return;
                                }

                                // Kiểm tra nếu dữ liệu là mảng (dù rỗng hay có phần tử)
                                if (Array.isArray(data)) {
                                    if (data.length > 0) {
                                        let html = '';
                                        data.forEach((student, index) => {
                                            // Kiểm tra null hoặc undefined cho các trường
                                            const fullName = student.FullName || '<i class="text-muted">Chưa có</i>';
                                            const email = student.Email ? `<a href="mailto:${student.Email}">${student.Email}</a>` : '<i class="text-muted">Chưa có</i>';
                                            const phone = student.Phone ? `<a href="tel:${student.Phone}">${student.Phone}</a>` : '<i class="text-muted">Chưa có</i>';
                                            const registeredAt = student.RegisteredAt || '<i class="text-muted">Không rõ</i>'; // Sử dụng tên cột đã format ở PHP

                                            html += `
                                                <tr>
                                                    <td>${index + 1}</td>
                                                    <td>${fullName}</td>
                                                    <td>${email}</td>
                                                    <td>${phone}</td>
                                                    <td>${registeredAt}</td>
                                                </tr>
                                            `;
                                        });
                                        studentsTableBody.innerHTML = html;
                                    } else {
                                        studentsTableBody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted fst-italic">Chưa có học viên nào đăng ký khóa học này.</td></tr>';
                                    }
                                } else {
                                     // Trường hợp dữ liệu trả về không phải mảng và không phải lỗi đã biết
                                     console.error('Unexpected data format:', data);
                                      studentsTableBody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-warning"><i class="fas fa-exclamation-circle me-2"></i>Định dạng dữ liệu trả về không đúng.</td></tr>';
                                }
                            })
                            .catch(error => {
                                // Xử lý lỗi fetch (mạng, phân tích JSON sai, lỗi HTTP đã throw)
                                console.error('Fetch Error:', error);
                                if (studentsTableBody) {
                                     studentsTableBody.innerHTML = `<tr><td colspan="5" class="text-center py-4 text-danger"><i class="fas fa-exclamation-triangle me-2"></i>Có lỗi xảy ra khi tải dữ liệu: ${error.message}. Vui lòng kiểm tra console (F12).</td></tr>`;
                                }
                            });
                    });
                });
            }

            // Xử lý confirm trước khi xóa
             document.querySelectorAll('.delete-btn').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    // Lấy tên khóa học từ nút edit gần nhất
                    const courseRow = btn.closest('tr');
                    const courseTitle = courseRow ? courseRow.querySelector('td:first-child').textContent : 'này';
                    if (!confirm(`Bạn có chắc chắn muốn xóa khóa học "${courseTitle}" không?\nHành động này không thể hoàn tác và có thể ảnh hưởng đến dữ liệu liên quan!`)) {
                        e.preventDefault();
                    }
                });
            });

        });
    </script>
</body>
</html>