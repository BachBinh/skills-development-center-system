<?php
$currentPage = basename($_SERVER['PHP_SELF']);
$isStudent = isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'student';
$fullName = $_SESSION['fullname'] ?? 'Tài khoản';
?>

<!-- Header + Navbar Start -->
<div class="container-fluid bg-light py-3 px-xl-5">
    <div class="row align-items-center justify-content-between">
        <!-- Logo -->
        <div class="col-lg-3 col-12 text-lg-start text-center mb-3 mb-lg-0">
            <a href="index.php" class="text-decoration-none">
                <h1 class="m-0"><span class="text-primary">Kỹ</span>Năng Pro</h1>
            </a>
        </div>

        <!-- Navbar -->
        <div class="col-lg-9">
            <nav class="navbar navbar-expand-lg navbar-light p-0 justify-content-center">
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarCollapse" aria-controls="navbarCollapse" aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>

                <div class="collapse navbar-collapse justify-content-between align-items-center" id="navbarCollapse">
                    <div class="navbar-nav mx-auto text-center">
                        <a href="index.php" class="nav-item nav-link <?= $currentPage === 'index.php' ? 'active' : '' ?>"><i class="fas fa-home me-1"></i>Trang chủ</a>
                        <a href="course.php" class="nav-item nav-link <?= $currentPage === 'course.php' ? 'active' : '' ?>"><i class="fas fa-book-open me-1"></i>Khóa học</a>
                        <?php if ($isStudent): ?>
                            <a href="schedule.php" class="nav-item nav-link <?= $currentPage === 'schedule.php' ? 'active' : '' ?>"><i class="fas fa-calendar-alt me-1"></i>Lịch học</a>
                        <?php endif; ?>
                        <a href="teacher.php" class="nav-item nav-link <?= $currentPage === 'teacher.php' ? 'active' : '' ?>"><i class="fas fa-chalkboard-teacher me-1"></i>Giảng viên</a>
                        <a href="contact.php" class="nav-item nav-link <?= $currentPage === 'contact.php' ? 'active' : '' ?>"><i class="fas fa-address-card me-1"></i>Liên hệ</a>
                    </div>

                    <!-- Buttons bên phải -->
                    <div class="d-flex align-items-center ms-lg-3 mt-3 mt-lg-0 flex-column flex-lg-row">
                        <?php if ($isStudent): ?>
                            <div class="dropdown">
                                <button class="btn btn-outline-primary dropdown-toggle py-2 px-4 mb-2 mb-lg-0 me-lg-2" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                    <i class="fas fa-user me-1"></i> <?= htmlspecialchars($fullName) ?>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                                <li>
                                    <a class="dropdown-item" href="./Dashboard.php?page=profile">
                                        <i class="fas fa-id-card me-2"></i>Hồ sơ
                                    </a>
                                    </li> 
                                    <li><hr class="dropdown-divider"></li> 

                                    <li>
                                    <a class="dropdown-item" href="./Dashboard.php?page=Result">
                                        <i class="fas fa-chart-line me-2"></i>Kết quả học tập
                                    </a>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>

                                    <li>
                                        <a class="dropdown-item" href="./Dashboard.php?page=EvaluateCourse">
                                            <i class="fas fa-star me-2"></i>Đánh giá Khóa học
                                        </a>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>

                                    <li>
                                    <a class="dropdown-item" href="./Dashboard.php?page=PaymentHistory">
                                        <i class="fas fa-receipt me-2"></i>Lịch sử thanh toán
                                    </a>
                                    </li>  
                                    <li><hr class="dropdown-divider"></li>

                                    <li>
                                    <a class="dropdown-item text-danger" href="../home/Logout.php">
                                        <i class="fas fa-sign-out-alt me-2"></i>Đăng xuất
                                    </a>
                                    </li>
                                </ul>
                            </div>
                        <?php else: ?>
                            <a href="../home/Register.php" class="btn btn-primary py-2 px-4">Đăng ký ngay</a>
                        <?php endif; ?>
                    </div>

                </div>
            </nav>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>