<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once(__DIR__ . '/../../../config/db_connection.php');
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: ../../home/Login.php");
    exit();
}
if (!isset($conn) || !$conn instanceof mysqli) {
     echo "<div class='alert alert-danger'>Lỗi: Không thể thiết lập kết nối cơ sở dữ liệu.</div>";
     return;
}

$studentID = null;
$getStudent = $conn->prepare("SELECT StudentID FROM student WHERE UserID = ?");
if (!$getStudent) {
    error_log("Lỗi Prepare (getStudent): " . $conn->error);
    echo "<div class='alert alert-danger'>Lỗi hệ thống khi lấy thông tin học viên.</div>";
    return;
}
$getStudent->bind_param("i", $_SESSION['user_id']);
$getStudent->execute();
$studentResult = $getStudent->get_result();
$student = $studentResult->fetch_assoc();
$getStudent->close();
if (!$student) {
    echo "<div class='alert alert-warning'>Không tìm thấy thông tin học viên liên kết.</div>";
    return;
}
$studentID = $student['StudentID'];


$message = '';
$message_type = 'info';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_evaluation'])) {

    if (!isset($_POST['rating'])) {
        $message = "Lỗi: Bạn chưa chọn đánh giá sao cho ít nhất một khóa học.";
        $message_type = 'danger';
    } else {
        $ratings = $_POST['rating'];
        $comments = $_POST['comment'] ?? [];
        $processed_count = 0;
        $error_count = 0;
        $validation_errors = [];

        $conn->begin_transaction();
        try {
            $valid_course_ids_to_evaluate = [];
            $sql_check = "SELECT c.CourseID FROM registration r JOIN course c ON r.CourseID = c.CourseID LEFT JOIN evaluation e ON e.CourseID = c.CourseID AND e.StudentID = r.StudentID WHERE r.StudentID = ? AND c.EndDate < CURDATE() AND e.EvalID IS NULL";
            $stmt_check = $conn->prepare($sql_check);
            if(!$stmt_check) throw new Exception("Lỗi Prepare (check valid courses): " . $conn->error);
            $stmt_check->bind_param("i", $studentID);
            $stmt_check->execute();
            $check_result = $stmt_check->get_result();
            while($row = $check_result->fetch_assoc()) { $valid_course_ids_to_evaluate[] = $row['CourseID']; }
            $stmt_check->close();

            $sql_insert = "INSERT INTO evaluation (StudentID, CourseID, Rating, Comment, EvalDate) VALUES (?, ?, ?, ?, CURDATE())";
            $stmt_insert = $conn->prepare($sql_insert);
             if(!$stmt_insert) throw new Exception("Lỗi Prepare (insert evaluation): " . $conn->error);

            foreach ($ratings as $courseId => $rating) {
                if (!filter_var($courseId, FILTER_VALIDATE_INT) || !in_array($courseId, $valid_course_ids_to_evaluate)) { $validation_errors[] = "Khóa học ID '{$courseId}' không hợp lệ hoặc không được phép đánh giá."; $error_count++; continue; }
                $rating_int = filter_var($rating, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]);
                if ($rating_int === false) { $validation_errors[] = "Đánh giá sao cho khóa học ID '{$courseId}' không hợp lệ (phải từ 1 đến 5)."; $error_count++; continue; }
                $comment = isset($comments[$courseId]) ? trim($comments[$courseId]) : '';
                $comment_sanitized = htmlspecialchars($comment, ENT_QUOTES, 'UTF-8');
                $stmt_insert->bind_param("iiis", $studentID, $courseId, $rating_int, $comment_sanitized);
                if ($stmt_insert->execute()) { $processed_count++; } else { $validation_errors[] = "Lỗi khi lưu đánh giá cho khóa học ID '{$courseId}': " . $stmt_insert->error; $error_count++; }
            }
            $stmt_insert->close();

            if ($error_count > 0) { $conn->rollback(); $message = "Đã xảy ra lỗi. Không thể lưu đánh giá. Chi tiết:<br>" . implode("<br>", $validation_errors); $message_type = 'danger'; }
            elseif ($processed_count > 0) { $conn->commit(); $message = "Đã gửi thành công {$processed_count} đánh giá."; $message_type = 'success'; }
            else { $conn->rollback(); $message = "Không có đánh giá nào hợp lệ được xử lý."; if(!empty($validation_errors)) { $message .= "<br>Chi tiết:<br>" . implode("<br>", $validation_errors); } $message_type = 'warning'; }
        } catch (Exception $e) { $conn->rollback(); error_log("Lỗi Transaction Đánh giá: " . $e->getMessage()); $message = "Lỗi hệ thống nghiêm trọng. Vui lòng thử lại sau."; $message_type = 'danger'; }
    }
}

$eligible_courses_list = [];
try {
    $sql_fetch_eligible = "SELECT c.CourseID, c.Title, c.EndDate
                           FROM registration r
                           JOIN course c ON r.CourseID = c.CourseID
                           LEFT JOIN evaluation e ON e.CourseID = c.CourseID AND e.StudentID = r.StudentID
                           WHERE r.StudentID = ? AND c.EndDate < CURDATE() AND e.EvalID IS NULL
                           ORDER BY c.EndDate DESC, c.Title ASC";
    $stmt_fetch_eligible = $conn->prepare($sql_fetch_eligible);
    if (!$stmt_fetch_eligible) throw new Exception("Lỗi Prepare (fetch eligible): " . $conn->error);
    $stmt_fetch_eligible->bind_param("i", $studentID);
    $stmt_fetch_eligible->execute();
    $eligible_result = $stmt_fetch_eligible->get_result();
    while ($row = $eligible_result->fetch_assoc()) { $eligible_courses_list[] = $row; }
    $stmt_fetch_eligible->close();
} catch (Exception $e) {
     error_log("Lỗi khi lấy danh sách khóa học để đánh giá: " . $e->getMessage());
     echo "<div class='alert alert-danger'>Không thể tải danh sách khóa học cần đánh giá.</div>";
     $eligible_courses_list = [];
}

$evaluated_courses_list = [];
try {
    $sql_fetch_evaluated = "SELECT c.Title, e.Rating, e.Comment, e.EvalDate
                           FROM evaluation e
                           JOIN course c ON e.CourseID = c.CourseID
                           WHERE e.StudentID = ?
                           ORDER BY e.EvalDate DESC, c.Title ASC"; // Sắp xếp theo ngày đánh giá mới nhất
    $stmt_fetch_evaluated = $conn->prepare($sql_fetch_evaluated);
     if (!$stmt_fetch_evaluated) throw new Exception("Lỗi Prepare (fetch evaluated): " . $conn->error);
    $stmt_fetch_evaluated->bind_param("i", $studentID);
    $stmt_fetch_evaluated->execute();
    $evaluated_result = $stmt_fetch_evaluated->get_result();
    while ($row = $evaluated_result->fetch_assoc()) { $evaluated_courses_list[] = $row; }
    $stmt_fetch_evaluated->close();
} catch (Exception $e) {
     error_log("Lỗi khi lấy lịch sử đánh giá: " . $e->getMessage());
     echo "<div class='alert alert-danger'>Không thể tải lịch sử đánh giá.</div>";
     $evaluated_courses_list = [];
}

?>

<div class="container mt-4">
    <h3 class="mb-4">📝 Đánh giá Khóa học</h3>
    <hr>

    <?php if (!empty($message)): ?>
        <div class="alert alert-<?= htmlspecialchars($message_type) ?> alert-dismissible fade show" role="alert">
            <?= $message ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Phần Form Đánh giá các khóa học chưa đánh giá -->
    <div class="evaluation-form-section mb-5">
        <h4>Khóa học cần đánh giá</h4>
        <p class="text-muted">Vui lòng chọn sao và để lại nhận xét cho các khóa học bạn đã hoàn thành dưới đây.</p>

        <?php if (empty($eligible_courses_list)): ?>
            <div class="alert alert-success">
                <?php if ($message_type !== 'success'): ?>
                     Bạn đã đánh giá tất cả các khóa học đã hoàn thành hoặc chưa có khóa học nào kết thúc để đánh giá.
                <?php else: ?>
                    Cảm ơn bạn đã hoàn thành việc đánh giá!
                <?php endif; ?>
            </div>
        <?php else: ?>
            <form action="?page=EvaluateCourse" method="post" id="evaluateCourseForm">
                <?php foreach ($eligible_courses_list as $course): ?>
                    <div class="card mb-3 shadow-sm">
                        <div class="card-header bg-light">
                            <h5 class="mb-0"><?= htmlspecialchars($course['Title']) ?></h5>
                            <small class="text-muted">Ngày kết thúc: <?= date("d/m/Y", strtotime($course['EndDate'])) ?></small>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label d-block"><strong>Đánh giá chung:</strong> <span class="text-danger">*</span></label>
                                 <div class="rating-stars">
                                    <?php for ($i = 5; $i >= 1; $i--): ?>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="rating[<?= $course['CourseID'] ?>]" id="rating-<?= $course['CourseID'] ?>-<?= $i ?>" value="<?= $i ?>" required>
                                            <label class="form-check-label" for="rating-<?= $course['CourseID'] ?>-<?= $i ?>"><?= $i ?> <i class="fas fa-star text-warning"></i></label>
                                        </div>
                                    <?php endfor; ?>
                                 </div>
                            </div>
                            <div class="mb-0">
                                <label for="comment-<?= $course['CourseID'] ?>" class="form-label"><strong>Nhận xét thêm (tùy chọn):</strong></label>
                                <textarea class="form-control" id="comment-<?= $course['CourseID'] ?>" name="comment[<?= $course['CourseID'] ?>]" rows="3" placeholder="Cảm nhận về nội dung, giảng viên, phương pháp,..."></textarea>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <div class="mt-4">
                    <button type="submit" name="submit_evaluation" class="btn btn-primary btn-lg">
                         <i class="fas fa-paper-plane me-2"></i> Gửi Đánh Giá
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <!-- Phần Hiển thị Lịch sử Đánh giá -->
    <div class="evaluation-history-section">
        <h4>Lịch sử đánh giá của bạn</h4>
        <hr>
        <?php if (empty($evaluated_courses_list)): ?>
            <div class="alert alert-secondary">Bạn chưa gửi đánh giá nào trước đây.</div>
        <?php else: ?>
            <div class="list-group">
                <?php foreach ($evaluated_courses_list as $eval): ?>
                    <div class="list-group-item list-group-item-action flex-column align-items-start mb-2 shadow-sm border-start border-5 <?= $eval['Rating'] >= 4 ? 'border-success' : ($eval['Rating'] == 3 ? 'border-warning' : 'border-danger') ?>">
                        <div class="d-flex w-100 justify-content-between">
                            <h5 class="mb-1"><?= htmlspecialchars($eval['Title']) ?></h5>
                            <small class="text-muted"><?= date("d/m/Y", strtotime($eval['EvalDate'])) ?></small>
                        </div>
                        <p class="mb-1">
                            <strong>Đánh giá:</strong>
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <i class="fas fa-star <?= $i <= $eval['Rating'] ? 'text-warning' : 'text-muted' ?>"></i>
                            <?php endfor; ?>
                            (<?= $eval['Rating'] ?>/5)
                        </p>
                        <?php if (!empty($eval['Comment'])): ?>
                            <p class="mb-0 fst-italic"><strong>Nhận xét:</strong> "<?= nl2br(htmlspecialchars($eval['Comment'])) ?>"</p>
                        <?php else: ?>
                             <p class="mb-0 text-muted fst-italic">Không có nhận xét.</p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

</div>