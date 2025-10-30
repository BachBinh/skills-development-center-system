<?php
require_once(__DIR__ . '/../../../config/db_connection.php');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: ../../home/Login.php");
    exit();
}

$getStudent = $conn->prepare("SELECT StudentID FROM student WHERE UserID = ?");
$getStudent->bind_param("i", $_SESSION['user_id']);
$getStudent->execute();
$studentResult = $getStudent->get_result();
$student = $studentResult->fetch_assoc();

if (!$student) {
    echo "<div class='alert alert-warning'>Không tìm thấy thông tin học viên.</div>";
    return;
}

$studentID = $student['StudentID'];

$sql = "
    SELECT c.Title AS CourseTitle, sr.Grade, sr.Comment, sr.MarkedAt
    FROM student_result sr
    JOIN course c ON sr.CourseID = c.CourseID
    WHERE sr.StudentID = ?
    ORDER BY sr.MarkedAt DESC
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo "<div class='alert alert-danger'>Lỗi truy vấn: " . $conn->error . "</div>";
    return;
}

$stmt->bind_param("i", $studentID);
$stmt->execute();
$results = $stmt->get_result();
?>

<h3 class="mb-4">📈 Kết quả học tập</h3>

<?php if ($results->num_rows > 0): ?>
    <table class="table table-striped">
        <thead>
            <tr>
                <th>Khóa học</th>
                <th>Điểm</th>
                <th>Nhận xét</th>
                <th>Ngày chấm</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($row = $results->fetch_assoc()): ?>
                <tr>
                    <td><?= htmlspecialchars($row['CourseTitle']) ?></td>
                    <td><strong><?= $row['Grade'] ?></strong></td>
                    <td><?= htmlspecialchars($row['Comment']) ?></td>
                    <td><?= date('d/m/Y', strtotime($row['MarkedAt'])) ?></td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
<?php else: ?>
    <div class="alert alert-info">Chưa có kết quả học tập nào được cập nhật.</div>
<?php endif; ?>
