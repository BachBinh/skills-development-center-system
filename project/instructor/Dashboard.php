<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'instructor') {
    header("Location: ../home/Login.php");
    exit();
}

$page = $_GET['page'] ?? 'Home';
$scheduleID = $_GET['scheduleID'] ?? null;
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Instructor Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script> <!-- ✅ Nhúng thư viện SweetAlert -->
    <style>
        body {
            display: flex;
            min-height: 100vh;
            margin: 0;
        }
        .sidebar {
            min-width: 220px;
            height: 200 vh;
            background-color: #2d3436;
            color: white;
            padding-top: 1rem;
        }
        .sidebar a {
            color: #dcdde1;
            padding: 10px 20px;
            display: block;
            text-decoration: none;
        }
        .sidebar a:hover {
            background-color: #636e72;
        }
        .content {
            flex-grow: 1;
            padding: 20px;
            background: #f1f2f6;
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <h4 class="text-center">Giảng viên</h4>
        <a href="?page=Home">🏠 Trang chủ</a>
        <a href="?page=Schedule">📅 Lịch giảng dạy</a>
        <a href="?page=Attendance">📝 Điểm danh</a>
        <a href="?page=Evaluation">📊 Đánh giá & Nhận xét</a>
        <a href="?page=Profile">👤 Hồ sơ cá nhân</a> 
        <a href="?page=PayslipHistory">💰 Xem phiếu lương</a> 
        <a href="#" id="logoutBtn">🚪 Đăng xuất</a> 
    </div>

    <div class="content">
        <?php
        if ($page === 'fetch_schedule') {
            header('Content-Type: application/json'); 
            include __DIR__ . "/includes/content/fetch_schedule.php";
            exit();
        }

        if ($page === 'AttendanceDetail' && isset($_GET['scheduleID'])) {
            include __DIR__ . "/includes/content/AttendanceDetail.php";
        } elseif ($page === 'Profile') {  
            include __DIR__ . "/includes/content/Profile.php";
        } else {
            $file = __DIR__ . "/includes/content/{$page}.php";
            if (file_exists($file)) {
                include $file;
            } else {
                echo "<h4>Không tìm thấy nội dung '{$page}'</h4>";
            }
        }
        ?>
    </div>

    <!-- ✅ Thêm script hiển thị popup khi đăng xuất -->
    <script>
    document.getElementById("logoutBtn").addEventListener("click", function(event) {
        event.preventDefault(); // Ngăn chặn điều hướng mặc định

        Swal.fire({
            title: "Bạn có chắc muốn đăng xuất?",
            text: "Phiên đăng nhập hiện tại sẽ bị kết thúc.",
            icon: "warning",
            showCancelButton: true,
            confirmButtonColor: "#d33",
            cancelButtonColor: "#3085d6",
            confirmButtonText: "Có, đăng xuất!",
            cancelButtonText: "Hủy"
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = "../home/Logout.php"; // ✅ Điều hướng đến trang đăng xuất
            }
        });
    });
    </script>
</body>
</html>
