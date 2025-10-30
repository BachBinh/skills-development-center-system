<?php
require_once(__DIR__ . '/../../../config/db_connection.php'); 

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'instructor') {
    header("Location: ../../home/Login.php"); 
    exit(); 
}

$instructorId = null;
$userId = intval($_SESSION['user_id']); 
$stmtInstructor = $conn->prepare("SELECT InstructorID FROM instructor WHERE UserID = ?");

if ($stmtInstructor === false) {
    error_log("SQL Prepare Error (fetching instructor ID): " . $conn->error);
    echo "Đã xảy ra lỗi khi truy vấn cơ sở dữ liệu. Vui lòng thử lại sau.";
    exit();
}

$stmtInstructor->bind_param("i", $userId); 
$stmtInstructor->execute();
$resultInstructor = $stmtInstructor->get_result();

if ($resultInstructor->num_rows > 0) {
    $instructorData = $resultInstructor->fetch_assoc();
    $instructorId = $instructorData['InstructorID'];
} else {
    error_log("No InstructorID found for UserID: " . $userId);
    echo "Lỗi: Không tìm thấy thông tin giảng viên hợp lệ cho tài khoản này.";
    exit();
}
$stmtInstructor->close(); 
$searchTitle = isset($_GET['search_title']) ? trim($_GET['search_title']) : '';

$sql = "
    SELECT
        c.CourseID,
        c.Title,
        (SELECT COUNT(*) FROM registration WHERE CourseID = c.CourseID) AS StudentCount
    FROM
        course c
    WHERE
        c.InstructorID = ? -- Filter by the instructor's ID
";

$params = [$instructorId]; 
$types = "i";             

if (!empty($searchTitle)) {
    $sql .= " AND c.Title LIKE ? "; 
    $searchParam = '%' . $searchTitle . '%'; 
    $params[] = $searchParam; 
    $types .= "s";      
}


$sql .= " ORDER BY c.Title ASC "; 
$stmt = $conn->prepare($sql);

if ($stmt === false) {
    error_log("SQL Prepare Error (fetching courses): " . $conn->error);
    echo "Đã xảy ra lỗi khi chuẩn bị truy vấn danh sách khóa học. Vui lòng thử lại sau.";
    exit();
}

if (!empty($types)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$courses = $stmt->get_result();
?>

<h3 class="mb-3">📚 Danh sách các khóa học</h3>

<!-- Search Form -->
<form method="GET" action="Dashboard.php" class="row g-3 mb-4 align-items-end">
    <?php
        $currentPage = isset($_GET['page']) ? htmlspecialchars($_GET['page']) : 'CourseList';
    ?>
    <input type="hidden" name="page" value="<?php echo $currentPage; ?>">

    <div class="col-md-6">
        <label for="search_title_input" class="form-label">🔍 Tìm theo tên khóa học</label>
        <input type="text" id="search_title_input" name="search_title" value="<?php echo htmlspecialchars($searchTitle); ?>" class="form-control" placeholder="Nhập tên khóa học...">
    </div>
    <div class="col-md-2">
        <button type="submit" class="btn btn-primary w-100">
            <i class="fas fa-search"></i> Tìm kiếm
        </button> <?php // Added an icon for visual cue ?>
    </div>
    <div class="col-md-2">
        <?php // Link to reset the search - points back to the same page without the search_title parameter ?>
        <a href="Dashboard.php?page=<?php echo $currentPage; ?>" class="btn btn-secondary w-100" title="Xóa bộ lọc tìm kiếm">
            <i class="fas fa-times"></i> Đặt lại
        </a> <?php // Added an icon for visual cue ?>
    </div>
</form>

<!-- Display search result information -->
<?php if (!empty($searchTitle)): ?>
    <p class="text-muted mb-3">
        🔎 Tìm thấy <strong><?php echo $courses->num_rows; ?></strong> khóa học phù hợp với tìm kiếm "<strong><?php echo htmlspecialchars($searchTitle); ?></strong>".
    </p>
<?php endif; ?>


<!-- Embedded CSS for Button Styling -->
<style>
    .btn-orange {
        background-color: #FF9800 !important; /* Orange background */
        border-color: rgb(241, 145, 1) !important; /* Darker orange border */
        color: black !important; /* Black text for contrast */
        font-weight: bold !important; /* Make text bold */
        transition: background-color 0.2s ease-in-out, border-color 0.2s ease-in-out; /* Smooth transition */
    }
    .btn-orange:hover,
    .btn-orange:focus { /* Added focus state */
        background-color: #E68900 !important; /* Darker orange on hover/focus */
        border-color: #d37f00 !important;
        color: black !important; /* Ensure text color remains black */
    }
    .btn i {
        margin-right: 5px;
    }
    .course-card {
        height: 200px; /* Fixed height */
        display: flex;
        flex-direction: column;
        justify-content: space-between; /* Pushes title/text up and button down */
    }
</style>

<!-- Course List Display -->
<div class="row g-4">
    <?php if ($courses->num_rows > 0): ?>
        <?php // Loop through each course found ?>
        <?php while ($course = $courses->fetch_assoc()): ?>
            <div class="col-md-4">
                <?php // Use custom class for easier styling/JS targeting ?>
                <div class="card course-card p-4 shadow-lg text-center">
                    <div> <?php // Group title and student count ?>
                        <h5 class="fw-bold card-title">
                            <?php echo htmlspecialchars($course['Title']); ?>
                        </h5>
                        <p class="text-muted mb-2 card-text">
                            <i class="fas fa-users"></i> <?php // Icon for students ?>
                            Số học viên: <strong><?php echo $course['StudentCount']; ?></strong>
                        </p>
                    </div>
                    <a href="Dashboard.php?page=CourseStudents&course_id=<?php echo $course['CourseID']; ?>" class="btn btn-orange mt-3">
                        <i class="fas fa-eye"></i> <?php // Icon for view ?>
                        Xem danh sách học viên
                    </a>
                </div>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <?php // Display message when no courses are found ?>
        <div class="col-12">
            <div class="alert alert-warning text-center" role="alert">
                <?php if (!empty($searchTitle)): ?>
                    <i class="fas fa-exclamation-triangle"></i> Không tìm thấy khóa học nào phù hợp với tìm kiếm "<strong><?php echo htmlspecialchars($searchTitle); ?></strong>".
                <?php else: ?>
                    <i class="fas fa-info-circle"></i> Hiện tại bạn chưa được phân công giảng dạy khóa học nào.
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php
$stmt->close();

?>