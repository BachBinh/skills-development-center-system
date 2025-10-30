<?php
// admin/Dashboard.php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../home/Login.php");
    exit();
}

$page = $_GET['page'] ?? 'home';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            display: flex;
            min-height: 100vh;
            margin: 0;
        }
        .sidebar {
            min-width: 220px;
            height: 150vh;
            background-color: #212529;
            color: white;
            padding-top: 1rem;
        }
        .sidebar a {
            color: #ddd;
            padding: 10px 20px;
            display: block;
            text-decoration: none;
        }
        .sidebar a:hover {
            background-color: #343a40;
        }
        .content {
            flex-grow: 1;
            padding: 20px;
            background: #f8f9fa;
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <h4 class="text-center">Admin Panel</h4>
        <a href="?page=home">🏠 Trang chủ</a>
        <a href="?page=Profile">👨‍💻 Hồ sơ của tôi</a>
        <a href="?page=ManageCourses">📚 Quản lý khóa học</a>
        <a href="?page=ManageUsers">👥 Quản lý người dùng</a>
        <a href="?page=Statistics">📈 Thống kê</a>
        <a href="?page=ManagePayroll">  💰 Quản lý lương</a>
        <a href="../home/Logout.php">🚪 Đăng xuất</a>
    </div>
    <div class="content">
        <?php
        $file = __DIR__ . "/includes/content/{$page}.php";
        if (file_exists($file)) {
            include $file;
        } else {
            echo "<h4>Không tìm thấy nội dung '$page'</h4>";
        }
        ?>
    </div>
</body>
</html>

<!-- Ở gần cuối file, trước thẻ đóng </body> -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>

<!-- Các file script tùy chỉnh của bạn nên đặt SAU khi nhúng Bootstrap JS -->
<!-- Ví dụ: -->
<?php if ($page === 'Statistics'): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://npmcdn.com/flatpickr/dist/l10n/vn.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const exportOptionsModalElement = document.getElementById('exportOptionsModal');
            const confirmExportBtn = document.getElementById('confirmExportBtn');
            const exportErrorMsg = document.getElementById('exportErrorMsg');

            if (exportOptionsModalElement && confirmExportBtn) {
                // Dòng 480 có thể nằm trong khối này, ví dụ khi khởi tạo Modal
                const exportOptionsModal = new bootstrap.Modal(exportOptionsModalElement); // <<< Dòng này cần đối tượng 'bootstrap'

                confirmExportBtn.addEventListener('click', function() {
                });


            } else {
                console.error("Modal or Confirm button for export not found.");
            }

        });
    </script>
<?php endif; ?>
