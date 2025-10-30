<?php
require_once __DIR__ . '/../../../config/db_connection.php';

// ✅ Lấy dữ liệu tìm kiếm
$searchName  = isset($_GET['name']) ? trim($_GET['name']) : '';
$searchEmail = isset($_GET['email']) ? trim($_GET['email']) : '';
$searchPhone = isset($_GET['phone']) ? trim($_GET['phone']) : '';

// ✅ Debug giá trị tìm kiếm
error_log("🔍 Giá trị tìm kiếm:");
error_log("Tên: " . $searchName);
error_log("Email: " . $searchEmail);
error_log("Số điện thoại: " . $searchPhone);

// ✅ Chỉ lấy học viên có đăng ký ít nhất một khóa học
$sql = "
    SELECT DISTINCT s.StudentID, u.FullName, u.Email, u.Phone, s.EnrollmentDate
    FROM registration r
    JOIN student s ON r.StudentID = s.StudentID
    JOIN user u ON s.UserID = u.UserID
    WHERE r.Status = 'completed'
";

$params = [];
$types = '';

if (!empty($searchName)) {
    $sql .= " AND u.FullName LIKE ?";
    $params[] = '%' . $searchName . '%';
    $types .= 's';
}
if (!empty($searchEmail)) {
    $sql .= " AND u.Email LIKE ?";
    $params[] = '%' . $searchEmail . '%';
    $types .= 's';
}
if (!empty($searchPhone)) {
    $sql .= " AND u.Phone LIKE ?";
    $params[] = '%' . $searchPhone . '%';
    $types .= 's';
}

$sql .= " ORDER BY s.EnrollmentDate DESC";

$stmt = $conn->prepare($sql);

// ✅ Debug lỗi SQL nếu có
if (!$stmt) {
    error_log("❌ Lỗi SQL: " . $conn->error);
    die("Lỗi truy vấn SQL.");
}

if (!empty($params)) {
    if (!$stmt->bind_param($types, ...$params)) {
        error_log("❌ Lỗi bind_param: " . $stmt->error);
        die("Lỗi khi truyền tham số.");
    }
}

$stmt->execute();
$result = $stmt->get_result();

// ✅ Debug số lượng học viên lấy được
error_log("📊 Số lượng học viên tìm thấy: " . $result->num_rows);
?>


<h3 class="mb-4">📘 Danh sách học viên đã đăng ký khóa học</h3>

<!-- ✅ Form tìm kiếm -->
<form method="GET" action="Dashboard.php" class="mb-3" id="searchForm">
    <input type="hidden" name="page" value="ManageStudents">

    <div class="row">
        <div class="col-md-6">
            <input type="text" name="name" class="form-control form-control-sm" placeholder="🔍 Tìm học viên..." value="<?= htmlspecialchars($searchName) ?>">
        </div>
        <div class="col-md-3">
            <input type="text" name="email" class="form-control form-control-sm" placeholder="📧 Email..." value="<?= htmlspecialchars($searchEmail) ?>">
        </div>
        <div class="col-md-3">
            <input type="text" name="phone" class="form-control form-control-sm" placeholder="📱 Số điện thoại..." value="<?= htmlspecialchars($searchPhone) ?>">
        </div>
    </div>

    <!-- ✅ Hai nút tìm kiếm & reset -->
    <div class="mt-2 d-flex justify-content-start gap-2">
        <button type="submit" class="btn btn-primary btn-sm">🔍 Tìm kiếm</button>
        <a href="Dashboard.php?page=ManageStudents" class="btn btn-secondary btn-sm">♻️ Reset</a>
    </div>
</form>

<!-- ✅ Kiểm tra nếu không có dữ liệu -->
<?php if ($result->num_rows === 0): ?>
    <p class="text-danger fw-bold text-center">🚫 Không tìm thấy học viên nào có đăng ký khóa học.</p>
<?php else: ?>
    <!-- ✅ Bảng danh sách học viên -->
    <div class="table-responsive">
        <table class="table table-bordered table-hover align-middle">
            <thead class="table-dark text-center">
                <tr>
                    <th>#</th>
                    <th>Họ tên</th>
                    <th>Email</th>
                    <th>Điện thoại</th>
                    <th>Ngày ghi danh</th>
                    <th>Khóa học đã đăng ký</th>
                </tr>
            </thead>
            <tbody>
            <?php $i = 1; while ($row = $result->fetch_assoc()): ?>
                <?php
                    $studentID = $row['StudentID'];
                    $stmtCourses = $conn->prepare("
                        SELECT c.Title
                        FROM registration r
                        JOIN course c ON r.CourseID = c.CourseID
                        WHERE r.StudentID = ?
                    ");

                    if (!$stmtCourses) {
                        die("Lỗi SQL khóa học: " . $conn->error);
                    }

                    $stmtCourses->bind_param("i", $studentID);
                    $stmtCourses->execute();
                    $coursesResult = $stmtCourses->get_result();

                    $courseList = [];
                    while ($courseRow = $coursesResult->fetch_assoc()) {
                        $courseList[] = $courseRow['Title'];
                    }
                ?>

                <tr>
                    <td><?= $i++ ?></td>
                    <td><?= htmlspecialchars($row['FullName']) ?></td>
                    <td><?= htmlspecialchars($row['Email']) ?></td>
                    <td><?= htmlspecialchars($row['Phone']) ?></td>
                    <td><?= htmlspecialchars($row['EnrollmentDate']) ?></td>
                    <td>
                        <?php if (!empty($courseList)): ?>
                            <ul class="list-unstyled mb-0">
                                <?php foreach ($courseList as $title): ?>
                                    <li>- <?= htmlspecialchars($title) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <span class="text-muted">🚫 Không có khóa học nào đã đăng ký</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
