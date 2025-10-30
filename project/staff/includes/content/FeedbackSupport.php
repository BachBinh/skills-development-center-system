<?php
require_once __DIR__ . '/../../../config/db_connection.php';
$searchTerm = isset($_GET['search']) ? $_GET['search'] : "";
$stmt = $conn->prepare("
    SELECT c.CourseID, c.Title, 
        (SELECT COUNT(*) FROM evaluation WHERE CourseID = c.CourseID) AS feedback_count,
        (SELECT COUNT(*) FROM registration WHERE CourseID = c.CourseID) AS total_students,
        (SELECT AVG(Rating) FROM evaluation WHERE CourseID = c.CourseID) AS avg_rating
    FROM course c
    WHERE c.Title LIKE ? ORDER BY c.Title ASC
");

$searchPattern = "%$searchTerm%";
$stmt->bind_param("s", $searchPattern);
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Phản hồi học viên</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-4">
        <h3 class="mb-3">📚 Danh sách khóa học & phản hồi</h3>

        <!-- ✅ Ô tìm kiếm khóa học -->
        <form method="GET" action="Dashboard.php" class="mb-3" id="searchForm">
            <input type="hidden" name="page" value="FeedbackSupport">
            <div class="row">
                <div class="col-md-8">
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="🔍 Tìm khóa học..." value="<?= htmlspecialchars($searchTerm) ?>">
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary btn-sm w-100">🔍 Tìm kiếm</button>
                </div>
            </div>
        </form>

        <!-- ✅ Kiểm tra nếu không tìm thấy khóa học -->
        <?php if ($result->num_rows === 0): ?>
            <p class="text-danger fw-bold text-center">🚫 Không tìm thấy khóa học với từ khóa "<strong><?= htmlspecialchars($searchTerm) ?></strong>"</p>
        <?php else: ?>
            <div class="row row-cols-1 row-cols-md-3 g-4">
                <?php while ($course = $result->fetch_assoc()): ?>
                    <div class="col">
                        <div class="card text-center">
                            <div class="card-body">
                                <h5 class="card-title"><?= htmlspecialchars($course['Title']) ?></h5>
                                <p class="text-muted">
                                    📝 Phản hồi: <strong><?= $course['feedback_count'] ?></strong> / <strong><?= $course['total_students'] ?></strong> học viên <br>
                                    ⭐️ Trung bình: <strong><?= number_format($course['avg_rating'], 1) ?>/5</strong>
                                </p>
                                <a href="includes/content/CourseFeedbackDetail.php?course_id=<?= $course['CourseID'] ?>" class="btn btn-outline-primary">📜 Xem danh sách phản hồi</a>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>  
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
