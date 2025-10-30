    <?php
    session_start();

    if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
        switch ($_SESSION['role']) {
            case 'instructor':
                header("Location: ../instructor/Dashboard.php"); exit;
            case 'staff':
                header("Location: ../staff/Dashboard.php"); exit;
            case 'admin':
                header("Location: ../admin/Dashboard.php"); exit;
        }
    }

    $isStudent = isset($_SESSION['user_id']) && $_SESSION['role'] === 'student';
    $fullName = $_SESSION['fullname'] ?? 'Tài khoản';

    $conn = new mysqli("localhost", "root", "", "thiendinhsystem");
    $courses = $conn->query("SELECT Title, Description, Fee, StartDate, EndDate FROM course ORDER BY StartDate DESC LIMIT 6");
    //$categories = $conn->query("SELECT DISTINCT Category FROM course LIMIT 8");
    ?>
    <!DOCTYPE html>
    <html lang="vi">

    <head>
        <meta charset="utf-8">
        <title>Kỹ Năng Pro - Trung tâm đào tạo kỹ năng mềm</title>
        <meta content="width=device-width, initial-scale=1.0" name="viewport">
        <meta content="Trung tâm đào tạo kỹ năng mềm chất lượng cao" name="keywords">
        <meta content="Phát triển bản thân - Gặt hái thành công với các khóa học kỹ năng mềm tại Kỹ Năng Pro" name="description">

        <!-- Favicon -->
        <link href="../img/favicon.ico" rel="icon">

        <!-- Google Web Fonts -->
        <link rel="preconnect" href="https://fonts.gstatic.com">
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet"> 

        <!-- Font Awesome -->
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.10.0/css/all.min.css" rel="stylesheet">

        <!-- Libraries Stylesheet -->
        <link href="lib/owlcarousel/assets/owl.carousel.min.css" rel="stylesheet">

        <!-- Customized Bootstrap Stylesheet -->
        <link href="../css/style.css" rel="stylesheet">
    </head>

    <body>
    <!-- Nav -->
    <?php include 'nav.php'; ?>

    <!-- Carousel Start -->
    <div class="container-fluid p-0 pb-5 mb-5">
            <div id="header-carousel" class="carousel slide carousel-fade" data-ride="carousel">
                <ol class="carousel-indicators">
                    <li data-target="#header-carousel" data-slide-to="0" class="active"></li>
                    <li data-target="#header-carousel" data-slide-to="1"></li>
                    <li data-target="#header-carousel" data-slide-to="2"></li>
                </ol>
                <div class="carousel-inner">
                    <div class="carousel-item active" style="min-height: 300px;">
                        <img class="position-relative w-100" src="../img/carousel-1.jpg" style="min-height: 300px; object-fit: cover;">
                        <div class="carousel-caption d-flex align-items-center justify-content-center">
                            <div class="p-5" style="width: 100%; max-width: 900px;">
                                <h5 class="text-white text-uppercase mb-md-3">Khóa học chất lượng</h5>
                                <h1 class="display-3 text-white mb-md-4">Phát triển bản thân từ hôm nay</h1>
                                <a href="course.php" class="btn btn-primary py-md-2 px-md-4 font-weight-semi-bold mt-2">Tìm hiểu thêm</a>
                            </div>
                        </div>
                    </div>
                    <div class="carousel-item" style="min-height: 300px;">
                        <img class="position-relative w-100" src="../img/carousel-2.jpg" style="min-height: 300px; object-fit: cover;">
                        <div class="carousel-caption d-flex align-items-center justify-content-center">
                            <div class="p-5" style="width: 100%; max-width: 900px;">
                                <h5 class="text-white text-uppercase mb-md-3">Giảng viên hàng đầu</h5>
                                <h1 class="display-3 text-white mb-md-4">Học từ những chuyên gia giàu kinh nghiệm</h1>
                                <a href="Register.php" class="btn btn-primary py-md-2 px-md-4 font-weight-semi-bold mt-2">Tìm hiểu thêm</a>
                            </div>
                        </div>
                    </div>
                    <div class="carousel-item" style="min-height: 300px;">
                        <img class="position-relative w-100" src="../img/carousel-3.jpg" style="min-height: 300px; object-fit: cover;">
                        <div class="carousel-caption d-flex align-items-center justify-content-center">
                            <div class="p-5" style="width: 100%; max-width: 900px;">
                                <h5 class="text-white text-uppercase mb-md-3">Phương pháp hiện đại</h5>
                                <h1 class="display-3 text-white mb-md-4">Cách học mới - Kết quả vượt trội</h1>
                                <a href="Register.php" class="btn btn-primary py-md-2 px-md-4 font-weight-semi-bold mt-2">Tìm hiểu thêm</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- Carousel End -->
    <!-- Courses Start -->
        <div class="container-fluid py-5">
            <div class="container py-5">
                <div class="text-center mb-5">
                    <h5 class="text-primary text-uppercase mb-3" style="letter-spacing: 5px;">Khóa học</h5>
                    <h1>Khóa học nổi bật</h1>
                </div>
                <div class="row">
                    <?php while ($row = $courses->fetch_assoc()): ?>
                    <div class="col-lg-4 col-md-6 mb-4">
                        <div class="rounded overflow-hidden mb-2">
                            <img class="img-fluid" src="../img/course-1.jpg" alt="<?= htmlspecialchars($row['Title']) ?>">
                            <div class="bg-secondary p-4">
                                <div class="d-flex justify-content-between mb-3">
                                    <small class="m-0"><i class="fa fa-users text-primary mr-2"></i>25 Học viên</small>
                                    <small class="m-0"><i class="far fa-clock text-primary mr-2"></i><?= date('d/m/Y', strtotime($row['StartDate'])) ?> - <?= date('d/m/Y', strtotime($row['EndDate'])) ?></small>
                                </div>
                                <a class="h5" href="Login.php"><?= htmlspecialchars($row['Title']) ?></a>
                                <p class="mt-2"><?= htmlspecialchars(substr($row['Description'], 0, 100)) ?>...</p>
                                <div class="border-top mt-4 pt-4">
                                    <div class="d-flex justify-content-between">
                                        <!-- <h6 class="m-0"><i class="fa fa-star text-primary mr-2"></i>4.5 <small>(250)</small></h6> -->
                                        <h5 class="m-0"><?= number_format($row['Fee'], 0, ',', '.') ?> VNĐ</h5>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
                <div class="text-center mt-4">
                    <a href="course.php" class="btn btn-primary py-md-3 px-md-5">Xem tất cả khóa học</a>
                </div>
            </div>
        </div>
        <!-- Courses End -->

        <!-- About Start -->
        <div class="container-fluid py-5">
            <div class="container py-5">
                <div class="row align-items-center">
                    <div class="col-lg-5">
                        <img class="img-fluid rounded mb-4 mb-lg-0" src="../img/about.jpg" alt="Về Kỹ Năng Pro">
                    </div>
                    <div class="col-lg-7">
                        <div class="text-left mb-4">
                            <h5 class="text-primary text-uppercase mb-3" style="letter-spacing: 5px;">Về chúng tôi</h5>
                            <h1>Phương pháp học tập đột phá</h1>
                        </div>
                        <p>Kỹ Năng Pro là trung tâm đào tạo kỹ năng mềm hàng đầu với phương pháp giảng dạy hiện đại, giúp học viên phát triển toàn diện các kỹ năng cần thiết cho công việc và cuộc sống. Chúng tôi cam kết mang đến những khóa học chất lượng cao với đội ngũ giảng viên giàu kinh nghiệm và hệ thống bài giảng được thiết kế khoa học.</p>
                        <a href="contact.php" class="btn btn-primary py-md-2 px-md-4 font-weight-semi-bold mt-2">Tìm hiểu thêm</a>
                    </div>
                </div>
            </div>
        </div>
        <!-- About End -->

        

        <!-- Registration Start -->
        <!-- <div class="container-fluid bg-registration py-5" style="margin: 90px 0;">
            <div class="container py-5">
                <div class="row align-items-center">
                    <div class="col-lg-7 mb-5 mb-lg-0">
                        <div class="mb-4">
                            <h5 class="text-primary text-uppercase mb-3" style="letter-spacing: 5px;">Ưu đãi đặc biệt</h5>
                            <h1 class="text-white">Giảm 30% cho học viên mới</h1>
                        </div>
                        <p class="text-white">Đăng ký ngay hôm nay để nhận ưu đãi giảm 30% học phí cho tất cả các khóa học. Cơ hội chỉ dành cho 100 học viên đầu tiên trong tháng này.</p>
                        <ul class="list-inline text-white m-0">
                            <li class="py-2"><i class="fa fa-check text-primary mr-3"></i>Giảng viên chất lượng cao</li>
                            <li class="py-2"><i class="fa fa-check text-primary mr-3"></i>Chương trình học thực tiễn</li>
                            <li class="py-2"><i class="fa fa-check text-primary mr-3"></i>Hỗ trợ học viên 24/7</li>
                        </ul>
                    </div>
                    <div class="col-lg-5">
                        <div class="card border-0">
                            <div class="card-header bg-light text-center p-4">
                                <h1 class="m-0">Đăng ký ngay</h1>
                            </div>
                            <div class="card-body rounded-bottom bg-primary p-5">
                                <form action="Register.php" method="post">
                                    <div class="form-group">
                                        <input type="text" class="form-control border-0 p-4" placeholder="Họ và tên" required="required" />
                                    </div>
                                    <div class="form-group">
                                        <input type="email" class="form-control border-0 p-4" placeholder="Email" required="required" />
                                    </div>
                                    <div class="form-group">
                                        <input type="tel" class="form-control border-0 p-4" placeholder="Số điện thoại" required="required" />
                                    </div>
                                    <div>
                                        <button class="btn btn-dark btn-block border-0 py-3" type="submit">Đăng ký nhận ưu đãi</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div> -->
        <!-- Registration End -->

        <!-- Footer -->
        <?php include 'footer.php'; ?>

        <!-- Back to Top -->
        <a href="#" class="btn btn-lg btn-primary btn-lg-square back-to-top"><i class="fa fa-angle-double-up"></i></a>

        <!-- JavaScript Libraries -->
        <script src="https://code.jquery.com/jquery-3.4.1.min.js"></script>
        <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.4.1/js/bootstrap.bundle.min.js"></script>
        <script src="lib/easing/easing.min.js"></script>
        <script src="lib/owlcarousel/owl.carousel.min.js"></script>

        <!-- Template Javascript -->
        <script src="js/main.js"></script>
    </body>
    </html>
