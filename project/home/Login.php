<?php
// Đảm bảo session đã được khởi tạo và kết nối CSDL đã được include
require_once(__DIR__ . '/../config/db_connection.php'); // Điều chỉnh đường dẫn nếu cần

// Nếu đã đăng nhập, chuyển hướng về dashboard tương ứng
if (isset($_SESSION['user_id'])) {
    switch ($_SESSION['role']) {
        case 'student': header("Location: ../studentV2/index.php"); exit;
        case 'instructor': header("Location: ../instructor/Dashboard.php"); exit;
        case 'staff': header("Location: ../staff/Dashboard.php"); exit;
        case 'admin': header("Location: ../admin/Dashboard.php"); exit;
        default: session_destroy(); // Vai trò không xác định, hủy session
    }
}


$error = '';
$success = ''; // Biến cho thông báo thành công (ví dụ: sau khi reset)

// Kiểm tra thông báo flash từ session (ví dụ: sau khi reset thành công)
if (isset($_SESSION['flash_message'])) {
    $messageData = $_SESSION['flash_message'];
    if ($messageData['type'] === 'success') {
        $success = $messageData['text'];
    } else {
        $error = $messageData['text'];
    }
    unset($_SESSION['flash_message']); // Xóa thông báo sau khi hiển thị
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- Validate Input ---
    if (empty($_POST['email']) || empty($_POST['password'])) {
        $error = 'Vui lòng nhập cả email và mật khẩu.';
    } else {
        $email = trim($_POST['email']);
        $password = trim($_POST['password']); // Mật khẩu người dùng nhập

        // --- Truy vấn CSDL an toàn ---
        $stmt = $conn->prepare("SELECT UserID, Email, Password, Role, FullName FROM user WHERE Email = ? AND Status = 'active'");
        if ($stmt === false) {
             error_log("Prepare failed: (" . $conn->errno . ") " . $conn->error);
             $error = "Lỗi hệ thống, vui lòng thử lại sau.";
        } else {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();

                // --- **QUAN TRỌNG: Xác thực mật khẩu đã hash** ---
                // if ($password === $user['Password']) { // <-- SAI! Không bao giờ so sánh plain text
                if (password_verify($password, $user['Password'])) { 
                    // Mật khẩu khớp!
                    
                    // Tái tạo session ID để tăng bảo mật (chống session fixation)
                    session_regenerate_id(true);

                    // Lưu thông tin cần thiết vào session
                    $_SESSION['user_id'] = $user['UserID'];
                    $_SESSION['email'] = $user['Email'];
                    $_SESSION['role'] = $user['Role'];
                    $_SESSION['fullname'] = $user['FullName']; // Thêm tên vào session nếu cần

                    // Chuyển hướng dựa trên vai trò
                    switch ($user['Role']) {
                        case 'student':
                            header("Location: ../studentV2/index.php"); exit;
                        case 'instructor':
                            header("Location: ../instructor/Dashboard.php"); exit;
                        case 'staff':
                            header("Location: ../staff/Dashboard.php"); exit;
                        case 'admin':
                            header("Location: ../admin/Dashboard.php"); exit;
                        default:
                            // Vai trò không hợp lệ (dù hiếm khi xảy ra nếu DB đúng)
                            session_destroy();
                            $error = "Vai trò người dùng không được hỗ trợ.";
                    }
                } else {
                    // Sai mật khẩu
                    $error = "Email hoặc mật khẩu không chính xác.";
                }
            } else {
                // Không tìm thấy email hoặc tài khoản inactive
                $error = "Email hoặc mật khẩu không chính xác."; // Thông báo chung chung để bảo mật
            }
            $stmt->close();
        }
    }
    $conn->close(); // Đóng kết nối sau khi xử lý xong
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng nhập - Hệ thống Thiên Đỉnh</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        html, body {
            height: 100%;
        }
        body {
            background: linear-gradient(to right, #6a11cb 0%, #2575fc 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 15px;
        }
        .login-container {
            display: flex;
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
            overflow: hidden;
            max-width: 800px; /* Tăng max-width */
            width: 100%;
        }
        .login-image {
            flex-basis: 50%; /* Chia đôi container */
            background: url('path/to/your/login-image.jpg') no-repeat center center; /* Thay bằng ảnh của bạn */
            background-size: cover;
        }
        .login-form {
            flex-basis: 50%; /* Chia đôi container */
            padding: 40px 35px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .login-form h2 {
            color: #333;
            margin-bottom: 10px;
            font-weight: 600;
        }
        .login-form .form-text {
            color: #6c757d;
            margin-bottom: 25px;
            font-size: 0.95em;
        }
        .form-control:focus {
            border-color:rgb(180, 156, 241);
            box-shadow: 0 0 0 0.2rem rgba(138, 92, 253, 0.25);
        }
        .btn-primary {
            background-color: #7e41f5;
            border-color: #7e41f5;
            padding: 10px;
            font-weight: 500;
        }
        .btn-primary:hover {
            background-color: #6a11cb;
            border-color: #6a11cb;
        }
        .extra-links {
            margin-top: 20px;
            font-size: 0.9em;
            display: flex;
            justify-content: space-between; /* Đẩy link sang 2 bên */
        }
         .extra-links a {
             color: #6a11cb;
             text-decoration: none;
         }
         .extra-links a:hover {
             text-decoration: underline;
         }

         /* Responsive */
         @media (max-width: 768px) {
             .login-image {
                 display: none; /* Ẩn ảnh trên màn hình nhỏ */
             }
             .login-form {
                 flex-basis: 100%;
             }
             .login-container {
                  max-width: 450px;
             }
         }

    </style>
</head>
<body>
<div class="login-container">
    <!-- Phần ảnh (chỉ hiện trên màn hình lớn) -->
    <div class="login-image"></div>

    <!-- Phần Form -->
    <div class="login-form">
        <div class="text-center mb-4">
            <i class="fas fa-graduation-cap fa-3x text-primary"></i> <!-- Icon ví dụ -->
        </div>
        <h2>Đăng nhập hệ thống</h2>
        <p class="form-text">Chào mừng trở lại! Vui lòng nhập thông tin.</p>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
               <i class="fas fa-exclamation-triangle me-2"></i> <?= htmlspecialchars($error) ?>
               <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
         <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
               <i class="fas fa-check-circle me-2"></i> <?= htmlspecialchars($success) ?>
               <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <form method="POST" action="Login.php"> <!-- action="Login.php" rõ ràng hơn -->
            <div class="mb-3 form-floating">
                <input type="email" class="form-control" id="email" name="email" placeholder="Email của bạn" required value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>">
                <label for="email"><i class="fas fa-envelope me-2"></i>Email</label>
            </div>
            <div class="mb-3 form-floating">
                <input type="password" class="form-control" id="password" name="password" placeholder="Mật khẩu" required>
                 <label for="password"><i class="fas fa-lock me-2"></i>Mật khẩu</label>
            </div>
            <button type="submit" class="btn btn-primary w-100 mt-3">
                <i class="fas fa-sign-in-alt me-2"></i> Đăng nhập
            </button>
        </form>

        <div class="extra-links">
            <a href="forgot_password.php">Quên mật khẩu?</a>
            <a href="Register.php">Đăng ký tài khoản</a>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>