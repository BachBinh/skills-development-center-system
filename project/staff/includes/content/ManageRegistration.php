<?php
require_once __DIR__ . '/../../../config/db_connection.php';

// ✅ Kiểm tra nếu có tìm kiếm
$searchTerm = isset($_GET['search']) ? $_GET['search'] : "";
$filterPending = isset($_GET['filter']) && $_GET['filter'] === "pending";

// ✅ Lọc danh sách khóa học theo tìm kiếm và trạng thái chờ duyệt
$stmt = $conn->prepare("
    SELECT c.CourseID, c.Title, 
        (SELECT COUNT(*) FROM registration WHERE CourseID = c.CourseID AND Status = 'registered') AS pending_count,
        (SELECT COUNT(*) FROM registration WHERE CourseID = c.CourseID AND Status = 'completed') AS approved_count,
        (SELECT COUNT(*) FROM registration WHERE CourseID = c.CourseID AND Status = 'cancelled') AS rejected_count
    FROM course c
    WHERE c.Title LIKE ?
    " . ($filterPending ? "AND (SELECT COUNT(*) FROM registration WHERE CourseID = c.CourseID AND Status = 'registered') > 0" : "") . "
    ORDER BY c.Title ASC
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
    <title>Quản lý đăng ký khóa học</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-4">
        <h3 class="mb-3">📚 Danh sách khóa học</h3>

        <!-- ✅ Form tìm kiếm khóa học -->
        <form method="GET" action="Dashboard.php" class="mb-3" id="searchForm">
            <input type="hidden" name="page" value="ManageRegistration">
            <div class="row">
                <div class="col-md-8">
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="🔍 Tìm khóa học..." value="<?= htmlspecialchars($searchTerm) ?>">
                </div>
                <div class="col-md-4">
                    <select name="filter" class="form-select form-select-sm" id="filterSelect">
                        <option value="">Tất cả khóa học</option>
                        <option value="pending" <?= $filterPending ? "selected" : "" ?>>Chỉ hiển thị khóa học có người chờ duyệt</option>
                    </select>
                </div>
            </div>
            <div class="mt-2 d-flex justify-content-start gap-2">
                <button type="submit" class="btn btn-primary btn-sm">🔍 Tìm kiếm</button>
                <a href="Dashboard.php?page=ManageRegistration" class="btn btn-secondary btn-sm">♻️ Reset</a>
            </div>
        </form>

        <!-- ✅ JavaScript tự động lọc khi thay đổi trạng thái -->
        <script>
        document.getElementById("filterSelect").addEventListener("change", function() {
            document.getElementById("searchForm").submit();
        });
        </script>

        <!-- ✅ Kiểm tra nếu không tìm thấy dữ liệu -->
        <?php if ($result->num_rows === 0): ?>
            <p class="text-danger fw-bold text-center">🚫 Không tìm thấy dữ liệu</p>
        <?php else: ?>
            <div class="row row-cols-1 row-cols-md-3 g-4">
                <?php while ($course = $result->fetch_assoc()): ?>
                    <div class="col">
                        <div class="card text-center">
                            <div class="card-body">
                                <h5 class="card-title"><?= htmlspecialchars($course['Title']) ?></h5>
                                <p class="text-muted">
                                    🔄 Chờ duyệt: <strong><?= $course['pending_count'] ?></strong> <br>
                                    ✅ Đã duyệt: <strong><?= $course['approved_count'] ?></strong> <br>
                                    ❌ Đã từ chối: <strong><?= $course['rejected_count'] ?></strong>
                                </p>
                                <a href="includes/content/CourseRegistrationDetail.php?course_id=<?= $course['CourseID'] ?>" class="btn btn-outline-primary">📜 Xem danh sách đăng ký</a>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>

