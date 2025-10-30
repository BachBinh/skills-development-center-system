<?php
require_once __DIR__ . '/../../../config/db_connection.php';

$courseID = isset($_GET['course_id']) ? intval($_GET['course_id']) : null;

if (!$courseID) {
    die("<h4 class='text-danger'>Không tìm thấy khóa học!</h4>");
}

// ✅ Lấy tên khóa học
$courseData = $conn->query("SELECT Title FROM course WHERE CourseID = $courseID")->fetch_assoc();
$courseTitle = $courseData['Title'] ?? 'Không xác định';

// ✅ Kiểm tra nếu có lọc
$filterRating = isset($_GET['filter']) ? intval($_GET['filter']) : "";
$sortOrder = isset($_GET['sort_order']) ? $_GET['sort_order'] : "DESC"; // Mặc định từ cao đến thấp

// ✅ Lấy danh sách phản hồi với lọc và sắp xếp
$stmt = $conn->prepare("
    SELECT u.FullName, e.EvalDate, e.Rating, e.Comment
    FROM evaluation e
    JOIN student s ON e.StudentID = s.StudentID
    JOIN user u ON s.UserID = u.UserID
    WHERE e.CourseID = ?
    " . ($filterRating ? "AND e.Rating = ?" : "") . "
    ORDER BY e.Rating $sortOrder, e.EvalDate DESC
");

if ($filterRating) {
    $stmt->bind_param("ii", $courseID, $filterRating);
} else {
    $stmt->bind_param("i", $courseID);
}

$stmt->execute();
$feedbacks = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Phản hồi - <?= htmlspecialchars($courseTitle) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body>
    <div class="container mt-4">
        <h3 class="mb-3">📜 Danh sách phản hồi cho khóa học: <strong><?= htmlspecialchars($courseTitle) ?></strong></h3>

        <!-- ✅ Form lọc phản hồi -->
        <form method="GET" action="CourseFeedbackDetail.php" class="mb-3" id="searchForm">
            <input type="hidden" name="course_id" value="<?= $courseID ?>">

            <div class="row">
                <div class="col-md-6">
                    <select name="filter" class="form-select form-select-sm" id="filterSelect">
                        <option value="">⭐️ Tất cả điểm đánh giá</option>
                        <?php for ($r = 1; $r <= 5; $r++): ?>
                            <option value="<?= $r ?>" <?= ($filterRating == $r) ? "selected" : "" ?>><?= $r ?> sao</option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <select name="sort_order" class="form-select form-select-sm" id="sortSelect">
                        <option value="DESC" <?= ($sortOrder == "DESC") ? "selected" : "" ?>>⭐️ Từ cao đến thấp</option>
                        <option value="ASC" <?= ($sortOrder == "ASC") ? "selected" : "" ?>>⭐️ Từ thấp đến cao</option>
                    </select>
                </div>
            </div>
        </form>

        <!-- ✅ JavaScript để lọc ngay khi chọn -->
        <script>
        document.getElementById("filterSelect").addEventListener("change", function() {
            document.getElementById("searchForm").submit();
        });

        document.getElementById("sortSelect").addEventListener("change", function() {
            document.getElementById("searchForm").submit();
        });
        </script>

        <!-- ✅ Kiểm tra nếu không có phản hồi -->
        <?php if ($feedbacks->num_rows === 0): ?>
            <p class="text-muted text-center">🚫 Không có phản hồi nào</p>
        <?php else: ?>
            <table class="table table-bordered table-striped">
                <thead class="table-dark text-center">
                    <tr>
                        <th>Học viên</th>
                        <th>Ngày đánh giá</th>
                        <th>Điểm</th>
                        <th>Bình luận</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $feedbacks->fetch_assoc()): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['FullName']) ?></td>
                            <td><?= $row['EvalDate'] ?></td>
                            <td class="text-center">⭐️ <?= $row['Rating'] ?>/5</td>
                            <td><?= htmlspecialchars($row['Comment']) ?></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <a href="../../Dashboard.php?page=FeedbackSupport" class="btn btn-secondary mt-3">⬅️ Quay lại danh sách khóa học</a>

    </div>
</body>
</html>
