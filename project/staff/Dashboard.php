<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
    header("Location: ../home/Login.php");
    exit();
}

$allowed_pages = ['home', 'ManageRegistration', 'ManageStudents', 'ManageSchedule', 'FeedbackSupport', 'Profile','PayslipHistory'];
$page = $_GET['page'] ?? 'home';
if (!in_array($page, $allowed_pages)) {
    $page = 'home'; 
}

require_once(__DIR__ . '/../config/db_connection.php');

$userID = $_SESSION['user_id'];
$info = null;
$stmtInfo = $conn->prepare("SELECT UserID, FullName, Email, Phone FROM user WHERE UserID = ?");
if ($stmtInfo) {
    $stmtInfo->bind_param("i", $userID);
    $stmtInfo->execute();
    $resultInfo = $stmtInfo->get_result();
    if ($resultInfo->num_rows > 0) {
        $info = $resultInfo->fetch_assoc();
    } else {
        error_log("Staff Dashboard: Không tìm thấy thông tin cho UserID: " . $userID);
    }
    $stmtInfo->close();
} else {
    error_log("Lỗi SQL prepare khi lấy thông tin staff: " . $conn->error);
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0"> 
    <title>Staff Dashboard<?= isset($info['FullName']) ? ' - ' . htmlspecialchars($info['FullName']) : '' ?> (<?= htmlspecialchars(ucfirst($page)) ?>)</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">

    <?php if ($page === 'ManageSchedule'): ?>
        <link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" rel="stylesheet">
    <?php endif; ?>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" integrity="sha512-9usAa10IRO0HhonpyAIVpjrylPvoDwiPUiKdWk5t3PyolY1cOd4DSE0Ga+ri4AuTroPR5aQvXU9xC6qOPnzFeg==" crossorigin="anonymous" referrerpolicy="no-referrer" />

    <style>
        html, body {
            height: 100%;
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .wrapper {
            display: flex;
            height: 100vh;
             overflow: hidden; 
        }
        .sidebar {
            width: 250px; 
            background-color: #212529;
            color: #adb5bd;
            padding: 1.5rem 1rem;
            flex-shrink: 0; /* Không co lại */
            display: flex;
            flex-direction: column;
             overflow-y: auto; /* Scroll sidebar nếu nội dung dài */
             border-right: 1px solid #343a40;
        }
        .sidebar .sidebar-header { /* Phần header của sidebar */
             color: #fff;
             margin-bottom: 1.5rem;
             padding-bottom: 1rem;
             border-bottom: 1px solid #495057;
             text-align: center;
         }
        .sidebar .sidebar-header i { font-size: 1.5em; }
        .sidebar .sidebar-header h4 { font-weight: 600; margin-top: 0.5rem; }

        .sidebar .nav-link { /* Sử dụng class nav-link của Bootstrap */
            color: #ced4da;
            padding: 12px 20px;
            display: block;
            text-decoration: none;
            border-radius: 5px;
            margin-bottom: 5px;
            transition: background-color 0.2s ease, color 0.2s ease;
            white-space: nowrap; /* Ngăn xuống dòng */
            overflow: hidden;
            text-overflow: ellipsis; /* Thêm dấu ... nếu quá dài */
        }
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            background-color: #495057;
            color: #fff;
        }
        .sidebar .nav-link i {
            margin-right: 12px; /* Khoảng cách icon */
            width: 20px;
            text-align: center;
            font-size: 1.1em; /* Kích thước icon */
        }
        .sidebar .logout-link {
             margin-top: auto; /* Đẩy xuống cuối */
             padding-top: 1rem;
             border-top: 1px solid #495057;
         }

        .content {
            flex-grow: 1;
            padding: 30px;
            background-color: #f8f9fa; /* Màu nền sáng */
            overflow-y: auto; /* Chỉ scroll vùng content */
        }

         <?php if ($page === 'ManageSchedule'): ?>
         #calendar { max-width: 100%; margin: 0 auto; }
         .fc-day-today { background: #e9f5ff !important; border: 1px solid #b6d4fe !important; }
         .fc-event { cursor: pointer; border: none !important; padding: 3px 6px !important; font-size: 0.9em;}
         .fc-event-main-custom { /* Class tự định nghĩa trong JS cho event content */
             line-height: 1.4;
             color: white; /* Hoặc màu khác tùy theme */
             overflow: hidden;
             text-overflow: ellipsis;
         }
         #scheduleModal .modal-body { max-height: 70vh; overflow-y: auto; } /* Scroll modal body nếu dài */
         <?php endif; ?>

         .card-container { display: flex; gap: 20px; }
         .card { flex: 1; padding: 20px; border: 1px solid #ddd; border-radius: 5px; background-color: #fff; display: flex; flex-direction: column; justify-content: space-between; text-align: center; /* height: 220px; Bỏ height cố định */ min-height: 180px; /* Đặt chiều cao tối thiểu */ }

    </style>
</head>
<body>
    <div class="wrapper">
        <div class="sidebar">
            <div class="sidebar-header">
                 <i class="fas fa-user-cog"></i>
                 <h4>Staff Panel</h4>
                 <?php if (isset($info['FullName'])): ?>
                     <small class="text-muted">Xin chào, <?= htmlspecialchars($info['FullName']) ?></small>
                 <?php endif; ?>
            </div>

            <!-- Sử dụng class nav-link và active động -->
            <a href="?page=home" class="nav-link <?= $page === 'home' ? 'active' : '' ?>"><i class="fas fa-home"></i>Trang chủ</a>
            <a href="?page=ManageRegistration" class="nav-link <?= $page === 'ManageRegistration' ? 'active' : '' ?>"><i class="fas fa-clipboard-check"></i>Xác nhận đăng ký</a>
            <a href="?page=ManageStudents" class="nav-link <?= $page === 'ManageStudents' ? 'active' : '' ?>"><i class="fas fa-users"></i>Quản lý học viên</a>
            <a href="?page=ManageSchedule" class="nav-link <?= $page === 'ManageSchedule' ? 'active' : '' ?>"><i class="fas fa-calendar-alt"></i>Quản lý lịch học</a>
            <a href="?page=FeedbackSupport" class="nav-link <?= $page === 'FeedbackSupport' ? 'active' : '' ?>"><i class="fas fa-comments"></i>Phản hồi học viên</a>
            <a href="?page=PayslipHistory" class="nav-link <?= $page === 'PayslipHistory' ? 'active' : '' ?>"><i class="fas fa-money-check-alt fa-fw"></i>Xem Phiếu lương</a>
            <a href="?page=Profile" class="nav-link <?= $page === 'Profile' ? 'active' : '' ?>"><i class="fas fa-user-circle"></i>Hồ sơ cá nhân</a>

            <!-- Nút đăng xuất giữ nguyên, đặt cuối cùng -->
            <a href="#" id="logoutBtn" class="nav-link logout-link"><i class="fas fa-sign-out-alt"></i>Đăng xuất</a>
        </div>

        <div class="content">
            <?php
            $file_path = __DIR__ . "/includes/content/{$page}.php";
            if (file_exists($file_path)) {
                include $file_path;
            } else {
                echo "<div class='alert alert-danger'>Lỗi: Không tìm thấy file nội dung '{$page}.php'. Vui lòng liên hệ quản trị viên.</div>";
            }
            ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>

    <?php if ($page === 'ManageSchedule'): ?>
        <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.4/jquery.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.29.4/moment.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/locales/vi.js"></script>
        <script>
            console.log("FullCalendar libraries loaded for ManageSchedule page.");
        </script>
    <?php endif; ?>

    <!-- Script cho logout popup (Giữ nguyên) -->
    <script>
        const logoutBtn = document.getElementById("logoutBtn");
        if(logoutBtn) {
            logoutBtn.addEventListener("click", function(event) {
                event.preventDefault();
                Swal.fire({
                     title: "Bạn có chắc muốn đăng xuất?",
                     text: "Phiên đăng nhập hiện tại sẽ bị kết thúc.",
                     icon: "warning",
                     showCancelButton: true,
                     confirmButtonColor: "#d33",
                     cancelButtonColor: "#6c757d",
                     confirmButtonText: '<i class="fas fa-check me-1"></i> Có, đăng xuất!',
                     cancelButtonText: '<i class="fas fa-times me-1"></i> Hủy'
                 }).then((result) => {
                    if (result.isConfirmed) {
                         Swal.fire({ title: 'Đang đăng xuất...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
                         window.location.href = "../home/Logout.php";
                    }
                });
            });
        } else {
            console.warn("Logout button (#logoutBtn) not found.");
        }
    </script>

</body>
</html>