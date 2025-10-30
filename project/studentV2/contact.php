<?php
session_start();
$title = "Giới thiệu trung tâm";
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <title><?= $title ?> - Kỹ Năng Pro</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="../img/favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.10.0/css/all.min.css">
    <link rel="stylesheet" href="../lib/owlcarousel/assets/owl.carousel.min.css">
    <link rel="stylesheet" href="../css/style.css">
</head>

<body>
    <?php include 'nav.php'; ?>

    <!-- Header Start -->
    <div class="container-fluid page-header mb-5">
        <div class="container">
            <div class="d-flex flex-column justify-content-center" style="min-height: 300px;">
                <h1 class="display-4 text-white text-uppercase"><?= $title ?></h1>
                <nav class="d-inline-flex text-white">
                    <p class="m-0 text-uppercase"><a href="index.php" class="text-white">Trang chủ</a></p>
                    <i class="fa fa-angle-double-right px-3 pt-1"></i>
                    <p class="m-0 text-uppercase">Giới thiệu</p>
                </nav>
            </div>
        </div>
    </div>
    <!-- Header End -->

    <!-- About Start -->
    <div class="container-fluid py-5">
        <div class="container py-5">
            <div class="row align-items-center">
                <div class="col-lg-5">
                    <img class="img-fluid rounded shadow mb-4 mb-lg-0" src="../img/about.jpg" alt="Về Kỹ Năng Pro">
                </div>

                <div class="col-lg-7">
                    <div class="mb-4">
                        <h5 class="text-primary text-uppercase mb-3" style="letter-spacing: 4px;">Về chúng tôi</h5>
                        <h2 class="fw-bold">Phương pháp học tập đột phá</h2>
                    </div>
                    <p>
                        <strong>Kỹ Năng Pro</strong> là trung tâm đào tạo kỹ năng mềm hàng đầu, với phương pháp giảng dạy hiện đại và đội ngũ giảng viên giàu kinh nghiệm. Chúng tôi hướng đến việc phát triển toàn diện các kỹ năng thiết yếu, từ giao tiếp, lãnh đạo, thuyết trình đến quản lý thời gian và làm việc nhóm.
                    </p>
                    <p>
                        Các khóa học tại trung tâm được xây dựng bài bản, kết hợp giữa lý thuyết và thực hành, nhằm mang lại hiệu quả học tập cao nhất cho học viên ở mọi độ tuổi và lĩnh vực.
                    </p>
                </div>
            </div>

        </div>
    </div>
    <!-- About End -->

    <?php include 'footer.php'; ?>
</body>
</html>
