<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: ../home/Login.php");
    exit();
}

$page = $_GET['page'] ?? 'profile'; 
$fullName = $_SESSION['fullname'] ?? 'Học viên'; //chào
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0"> 
    <title>Student Dashboard - <?= htmlspecialchars(ucfirst($page)) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css"/>
    <style>
        html, body {
            height: 100%;
            overflow-x: hidden; 
        }
        body {
            display: flex;
            margin: 0;
        }
        .sidebar {
            width: 260px; 
            min-width: 260px;
            height: 100vh; 
            background-color: #343a40; 
            color: #ced4da; 
            padding: 1.5rem 1rem;
            display: flex;
            flex-direction: column; 
            transition: width 0.3s ease; 
            box-shadow: 2px 0 5px rgba(0,0,0,0.1); 
        }
        .sidebar-header {
            text-align: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #495057;
        }
        .sidebar-header .fas { 
            font-size: 2rem;
            color: #0d6efd; 
            margin-bottom: 0.5rem;
        }
        .sidebar-header h5 {
            color: #fff;
            font-weight: 600;
            margin-bottom: 0.2rem;
        }
        .sidebar-header small {
            font-size: 0.85em;
            color: #f8f9fa !important;

        }
        .sidebar-footer .nav-link {
            margin-bottom: 0.5rem; 
        }

        .nav-pills .nav-link { 
            color: #adb5bd;
            padding: 12px 20px;
            margin-bottom: 5px;
            border-radius: 0.375rem; 
            transition: background-color 0.2s ease, color 0.2s ease;
            display: flex; 
            align-items: center; 
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .nav-pills .nav-link:hover {
            background-color: #495057;
            color: #fff;
        }
        .nav-pills .nav-link.active {
            background-color: #0d6efd;
            color: #fff;
            font-weight: 500;
        }
        .nav-pills .nav-link i.fa-fw {
            width: 1.5em;
            text-align: center;
            margin-right: 10px; 
            font-size: 1.1em;
        }

        .sidebar-footer {
            margin-top: auto;
            padding-top: 1rem;
            border-top: 1px solid #495057;
        }

        .content {
            flex-grow: 1;
            padding: 25px; 
            background: #f8f9fa;
            overflow-y: auto; 
            height: 100vh; 
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <i class="fas fa-user-graduate"></i>
            <h5>Student Panel</h5>
            <small class="text-muted">Xin chào, <?= htmlspecialchars($fullName) ?></small>
        </div>

        <ul class="nav nav-pills flex-column mb-auto">
            <li class="nav-item">
                <a href="?page=profile" class="nav-link <?= $page === 'profile' ? 'active' : '' ?>">
                    <i class="fas fa-user-circle fa-fw"></i>
                    Thông tin cá nhân
                </a>
            </li>
            <li class="nav-item">
                <a href="?page=Result" class="nav-link <?= $page === 'Result' ? 'active' : '' ?>">
                    <i class="fas fa-graduation-cap fa-fw"></i>
                    Kết quả học tập
                </a>
            </li>
            <li class="nav-item">
                <a href="?page=EvaluateCourse" class="nav-link <?= $page === 'EvaluateCourse' ? 'active' : '' ?>">
                    <i class="fas fa-star fa-fw"></i>
                    Đánh giá Khóa học
                </a>
            </li>
            <li class="nav-item">
                <a href="?page=PaymentHistory" class="nav-link <?= $page === 'PaymentHistory' ? 'active' : '' ?>">
                    <i class="fas fa-file-invoice-dollar fa-fw"></i>
                    Lịch sử thanh toán
                </a>
            </li>
        </ul>

        <div class="sidebar-footer">
             <a href="./index.php" class="nav-link text-warning"> 
                 <i class="fas fa-arrow-left fa-fw"></i>
                 Quay lại Trang chủ
             </a>
            <a href="../home/Logout.php" class="nav-link text-danger"> 
                <i class="fas fa-sign-out-alt fa-fw"></i>
                Đăng xuất
            </a>
        </div>
    </div>

    <div class="content">
        <?php
        $allowed_dashboard_pages = ['profile', 'Result', 'PaymentHistory', 'EvaluateCourse'];
        if (in_array($page, $allowed_dashboard_pages)) {
             $file = __DIR__ . "/includes/content/{$page}.php";
             if (file_exists($file)) {
                 include $file;
             } else {
                 echo "<div class='alert alert-danger'>Lỗi: Không tìm thấy nội dung trang '{$page}'.</div>";
             }
        } else {
             echo "<div class='alert alert-warning'>Trang yêu cầu không hợp lệ.</div>";
        }
        ?>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>