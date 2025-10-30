<?php
require_once(__DIR__ . '/../../../config/db_connection.php');
$userId = $_SESSION['user_id'];
$studentId = $conn->query("SELECT StudentID FROM student WHERE UserID = $userId")->fetch_assoc()['StudentID'];

$courses = $conn->query("SELECT * FROM course WHERE CourseID NOT IN (
    SELECT CourseID FROM registration WHERE StudentID = $studentId
)");
?>
<h4>📝 Đăng ký Khóa Học</h4>
<form method="post">
    <table class="table table-hover">
        <thead><tr><th>Tên Khóa</th><th>Ngày học</th><th>Học phí</th><th></th></tr></thead>
        <tbody>
            <?php while($row = $courses->fetch_assoc()): ?>
                <tr>
                    <td><?= htmlspecialchars($row['Title']) ?></td>
                    <td><?= $row['StartDate'] ?> → <?= $row['EndDate'] ?></td>
                    <td><?= number_format($row['Fee'], 0, ',', '.') ?> VNĐ</td>
                    <td>
                        <form method="post">
                            <input type="hidden" name="course_id" value="<?= $row['CourseID'] ?>">
                            <button name="register" class="btn btn-sm btn-primary">Đăng ký</button>
                        </form>
                    </td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</form>
<?php
if (isset($_POST['register'])) {
    $courseId = $_POST['course_id'];
    $stmt = $conn->prepare("INSERT INTO registration (StudentID, CourseID, Status, RegisteredAt) VALUES (?, ?, 'registered', NOW())");
    $stmt->bind_param("ii", $studentId, $courseId);
    $stmt->execute();
    echo "<div class='alert alert-success mt-3'>Đăng ký thành công!</div>";
    echo "<meta http-equiv='refresh' content='1'>";
}
?>
