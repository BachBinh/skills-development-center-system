<?php
require_once(__DIR__ . '/../../../config/db_connection.php');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'instructor') {
    header("Location: ../../home/Login.php");
    exit();
}

$studentId = $_GET['student_id'] ?? null;
$courseId = $_GET['course_id'] ?? null;
$returnPage = $_GET['return'] ?? 'CourseStudents'; // ✅ Đảm bảo giá trị mặc định

$instructorId = $conn->query("SELECT InstructorID FROM instructor WHERE UserID = " . $_SESSION['user_id'])->fetch_assoc()['InstructorID'];

if (!$studentId || !$courseId) {
    echo "<div class='alert alert-danger'>Thiếu thông tin học viên hoặc khóa học.</div>";
    exit();
}

// ✅ Debug lỗi hiển thị để kiểm tra giá trị
error_log("🔍 Giá trị return: " . htmlspecialchars($returnPage));
error_log("🔍 Giá trị course_id: " . htmlspecialchars($courseId));

// Xử lý cập nhật khi submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $grade = $_POST['grade'];
    $comment = $_POST['comment'];

    // Kiểm tra tồn tại đánh giá
    $check = $conn->prepare("SELECT * FROM student_result WHERE StudentID = ? AND CourseID = ? AND InstructorID = ?");
    $check->bind_param("iii", $studentId, $courseId, $instructorId);
    $check->execute();
    $result = $check->get_result();

    if ($result->num_rows > 0) {
        $stmt = $conn->prepare("UPDATE student_result SET Grade = ?, Comment = ? WHERE StudentID = ? AND CourseID = ? AND InstructorID = ?");
        $stmt->bind_param("ssiii", $grade, $comment, $studentId, $courseId, $instructorId);
    } else {
        $stmt = $conn->prepare("INSERT INTO student_result (StudentID, CourseID, InstructorID, Grade, Comment) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iiiss", $studentId, $courseId, $instructorId, $grade, $comment);
    }

    $stmt->execute();
    echo "<div class='alert alert-success'>✅ Đã lưu đánh giá học viên thành công!</div>";
}

// Lấy thông tin học viên + khóa học
$info = $conn->query("
    SELECT u.FullName, c.Title 
    FROM student s
    JOIN user u ON s.UserID = u.UserID
    JOIN course c ON c.CourseID = $courseId
    WHERE s.StudentID = $studentId
")->fetch_assoc();

// Lấy đánh giá hiện có nếu có
$existing = $conn->query("
    SELECT * FROM student_result 
    WHERE StudentID = $studentId AND CourseID = $courseId AND InstructorID = $instructorId
")->fetch_assoc();
?>

<h4>📄 Đánh giá học viên: <strong><?= htmlspecialchars($info['FullName']) ?></strong> - Khóa: <strong><?= htmlspecialchars($info['Title']) ?></strong></h4>

<form method="POST" class="mt-4">
    <div class="mb-3">
        <label class="form-label">Điểm/Grade</label>
        <input type="text" name="grade" class="form-control" required value="<?= $existing['Grade'] ?? '' ?>">
    </div>
    <div class="mb-3">
        <label class="form-label">Nhận xét</label>
        <textarea name="comment" class="form-control" rows="4"><?= $existing['Comment'] ?? '' ?></textarea>
    </div>
    <button type="submit" class="btn btn-primary">💾 Lưu đánh giá</button>
    
    <!-- ✅ Sửa lỗi URL của nút quay lại -->
    <a href="Dashboard.php?page=<?= htmlspecialchars($returnPage) ?>&course_id=<?= htmlspecialchars($courseId) ?>" class="btn btn-secondary">🔙 Quay lại danh sách lớp</a>
</form>
