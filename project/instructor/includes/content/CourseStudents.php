<?php
require_once(__DIR__ . '/../../../config/db_connection.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'instructor') {
    header("Location: ../../home/Login.php");
    exit();
}

if (!isset($_GET['course_id'])) {
    echo "<div class='alert alert-danger'>❌ Không nhận được course_id!</div>";
    exit();
}

$courseId = intval($_GET['course_id']);

// Lấy danh sách học viên và đếm số buổi đã tham gia
$students = $conn->query("
    SELECT 
        st.StudentID, 
        u.FullName, 
        COALESCE(sr.Grade, 'Chưa có') AS Grade, 
        COALESCE(sr.Comment, 'Chưa có') AS Comment,
        (SELECT COUNT(*) 
         FROM student_attendance sa
         JOIN schedule s ON sa.ScheduleID = s.ScheduleID
         WHERE sa.StudentID = st.StudentID 
         AND s.CourseID = $courseId 
         AND sa.Status = 'present') AS AttendedSessions
    FROM registration r
    JOIN student st ON r.StudentID = st.StudentID
    JOIN user u ON st.UserID = u.UserID
    LEFT JOIN student_result sr ON sr.StudentID = st.StudentID AND sr.CourseID = $courseId
    WHERE r.CourseID = $courseId
    ORDER BY u.FullName ASC
");
?>

<h3 class="mb-3">🎓 Danh sách học viên</h3>

<!-- ✅ Nút quay lại trang Evaluation -->
<div class="mb-3">
    <a href="Dashboard.php?page=Evaluation&course_id=<?= htmlspecialchars($courseId) ?>" class="btn btn-secondary">🔙 Quay lại đánh giá</a>
</div>

<!-- ✅ Ô tìm kiếm ngay trên trang -->
<div class="mb-3">
    <input type="text" id="searchStudent" class="form-control form-control-sm" placeholder="🔍 Nhập tên học viên...">
</div>

<table class="table table-bordered" id="studentTable">
    <thead class="table-dark">
        <tr>
            <th>Học viên</th>
            <th>Điểm</th>
            <th>Nhận xét</th>
            <th>Số buổi đã tham gia</th>
            <th>Thao tác</th>
        </tr>
    </thead>
    <tbody>
        <?php if ($students->num_rows > 0): ?>
            <?php while ($row = $students->fetch_assoc()): ?>
                <tr class="student-row">
                    <td class="student-name"><?= htmlspecialchars($row['FullName']) ?></td>
                    <td><?= htmlspecialchars($row['Grade']) ?></td>
                    <td>
                        <?php if (strlen($row['Comment']) > 100): ?>
                            <?= htmlspecialchars(substr($row['Comment'], 0, 100)) ?>...
                            <button type="button" class="btn btn-link text-primary view-comment" data-comment="<?= htmlspecialchars($row['Comment']) ?>">Xem thêm</button>
                        <?php else: ?>
                            <?= htmlspecialchars($row['Comment']) ?>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($row['AttendedSessions']) ?></td>
                    <td>
                        <a href="Dashboard.php?page=MarkingForm&student_id=<?= $row['StudentID'] ?>&course_id=<?= $courseId ?>&return=CourseStudents&course_id=<?= $courseId ?>" class="btn btn-success btn-sm">
                            ✏️ Cập nhật đánh giá
                        </a>
                    </td>
                </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr class="no-data"><td colspan="5" class="text-center">❌ Không có học viên nào.</td></tr>
        <?php endif; ?>
    </tbody>
</table>

<!-- Modal để hiển thị nhận xét đầy đủ -->
<div class="modal fade" id="commentModal" tabindex="-1" aria-labelledby="commentModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="commentModalLabel">Nhận xét đầy đủ</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p id="commentContent"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<!-- ✅ JavaScript tìm kiếm trực tiếp trong bảng -->
<script>
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

    noDataMessage.style.display = found ? "none" : "";
});

$(".view-comment").click(function() {
    var fullComment = $(this).data("comment");
    $("#commentContent").text(fullComment);
    $("#commentModal").modal("show");
});
</script>