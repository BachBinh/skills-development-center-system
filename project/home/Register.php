<?php
require_once(__DIR__ . '/../config/db_connection.php'); 

if (isset($_SESSION['user_id'])) {
    $redirect_url = '../studentV2/index.php';
    if(isset($_SESSION['role'])) {
         switch ($_SESSION['role']) {
            case 'instructor': $redirect_url = "../instructor/Dashboard.php"; break;
            case 'staff': $redirect_url = "../staff/Dashboard.php"; break;
            case 'admin': $redirect_url = "../admin/Dashboard.php"; break;
         }
    }
    header("Location: " . $redirect_url);
    exit();
}


$error = '';
$success = '';

// Xử lý form khi được gửi đi
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullname = trim($_POST['fullname'] ?? ''); // Dùng ?? để tránh lỗi nếu không tồn tại
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? ''); 

    // --- Validation cơ bản ---
    if (empty($fullname) || empty($email) || empty($password) || empty($confirm_password)) {
        $error = "Vui lòng điền đầy đủ các trường bắt buộc.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Địa chỉ email không hợp lệ.";
    } elseif (strlen($password) < 6) {
        $error = "Mật khẩu phải có ít nhất 6 ký tự.";
    } elseif ($password !== $confirm_password) {
        $error = "Mật khẩu và xác nhận mật khẩu không khớp.";
    } else {
        // --- Kiểm tra email đã tồn tại chưa ---
        $stmt_check = $conn->prepare("SELECT UserID FROM user WHERE Email = ?");
        if ($stmt_check) {
            $stmt_check->bind_param("s", $email);
            $stmt_check->execute();
            $result_check = $stmt_check->get_result();

            if ($result_check->num_rows > 0) {
                $error = "Email này đã được sử dụng để đăng ký.";
            } else {
                // --- Email hợp lệ và chưa tồn tại, tiến hành thêm user ---

                // **Mã hóa mk**
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

                // **Thêm vào bảng user**
                $stmt_user = $conn->prepare("INSERT INTO user (FullName, Email, Password, Phone, Role, Status) VALUES (?, ?, ?, ?, 'student', 'active')");
                if ($stmt_user) {
                    $phone_to_insert = !empty($phone) ? $phone : null; 
                    $stmt_user->bind_param("ssss", $fullname, $email, $hashedPassword, $phone_to_insert); 

                    if ($stmt_user->execute()) {
                        $userId = $conn->insert_id; 
                        $today = date('Y-m-d');

                        // **Thêm vào bảng student**
                        $stmt_student = $conn->prepare("INSERT INTO student (UserID, EnrollmentDate) VALUES (?, ?)");
                        if ($stmt_student) {
                             $stmt_student->bind_param("is", $userId, $today);
                             if ($stmt_student->execute()) {
                                 $success = "🎉 Đăng ký thành công! Bây giờ bạn có thể <a href='Login.php'>đăng nhập</a>.";
                                 

                             } else {
                                 $error = "Lỗi khi tạo hồ sơ học viên. Vui lòng liên hệ quản trị viên.";
                                 error_log("Student insert failed: (" . $stmt_student->errno . ") " . $stmt_student->error);
                                 // Cân nhắc xóa user vừa tạo nếu student lỗi: $conn->query("DELETE FROM user WHERE UserID = $userId");
                             }
                             $stmt_student->close();
                        } else {
                             $error = "Lỗi hệ thống khi chuẩn bị tạo hồ sơ học viên.";
                             error_log("Prepare failed (student): (" . $conn->errno . ") " . $conn->error);
                             // Cân nhắc xóa user vừa tạo: $conn->query("DELETE FROM user WHERE UserID = $userId");
                        }

                    } else {
                        $error = "Lỗi trong quá trình tạo tài khoản. Vui lòng thử lại.";
                         error_log("User insert failed: (" . $stmt_user->errno . ") " . $stmt_user->error);
                    }
                    $stmt_user->close();
                } else {
                     $error = "Lỗi hệ thống khi chuẩn bị tạo tài khoản.";
                     error_log("Prepare failed (user): (" . $conn->errno . ") " . $conn->error);
                }
            }
            $stmt_check->close();
        } else {
             $error = "Lỗi hệ thống khi kiểm tra email.";
             error_log("Prepare failed (check email): (" . $conn->errno . ") " . $conn->error);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng ký tài khoản - Hệ thống ThienDinh</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        html, body { height: 100%; }
        body {
            /* background: linear-gradient(to right, #6a11cb 0%, #2575fc 100%); */
             background-color: #eef1f4;
            display: flex; justify-content: center; align-items: center; padding: 20px;
        }
        .register-card {
            background-color: white;
            padding: 25px 35px;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            width: 100%;
            max-width: 700px;
        }
        .register-card .card-title {
             font-weight: 600;
             color: #4a5568; 
             margin-bottom: 20px;
        }
        .form-control:focus {
            border-color: #8a5cfd;
            box-shadow: 0 0 0 0.2rem rgba(138, 92, 253, 0.25);
        }
        .btn-success {
            background-color: #28a745; 
            border-color: #28a745;
            padding: 10px 20px;
            font-weight: 500;
        }
         .btn-success:hover {
             background-color: #218838;
             border-color: #1e7e34;
         }
         .login-link a {
              color: #6a11cb;
              text-decoration: none;
              font-weight: 500;
         }
         .login-link a:hover {
             text-decoration: underline;
         }
    </style>
</head>
<body>

<div class="register-card">
    <div class="text-center mb-4">
         <i class="fas fa-user-plus fa-3x text-success"></i> <!-- Icon đăng ký -->
    </div>
    <h3 class="card-title text-center">Đăng Ký Tài Khoản Học Viên</h3>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
           <i class="fas fa-times-circle me-2"></i> <?= htmlspecialchars($error) ?>
           <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php elseif ($success): ?>
        <div class="alert alert-success" role="alert">
            <?= $success ?>
        </div>
    <?php endif; ?>

    <!-- Chỉ hiển thị form nếu chưa đăng ký thành công -->
    <?php if (empty($success)): ?>
        <form method="POST" action="Register.php" class="row g-3 needs-validation" novalidate>
            <div class="col-md-6">
                <label for="fullname" class="form-label"><i class="fas fa-user me-1"></i> Họ và tên <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="fullname" name="fullname" required value="<?= htmlspecialchars($_POST['fullname'] ?? '') ?>">
                <div class="invalid-feedback">
                    Vui lòng nhập họ tên.
                </div>
            </div>
            <div class="col-md-6">
                <label for="phone" class="form-label"><i class="fas fa-phone me-1"></i> Số điện thoại</label>
                <input type="tel" class="form-control" id="phone" name="phone" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" pattern="[0-9]{10,11}" placeholder="VD: 0912345678">
                 <div class="invalid-feedback">
                    Số điện thoại không hợp lệ.
                </div>
            </div>
            <div class="col-md-12">
                <label for="email" class="form-label"><i class="fas fa-envelope me-1"></i> Địa chỉ Email <span class="text-danger">*</span></label>
                <input type="email" class="form-control" id="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" placeholder="vidu@email.com">
                 <div class="invalid-feedback">
                    Vui lòng nhập email hợp lệ.
                </div>
            </div>
            <div class="col-md-6">
                <label for="password" class="form-label"><i class="fas fa-lock me-1"></i> Mật khẩu <span class="text-danger">*</span></label>
                <input type="password" class="form-control" id="password" name="password" required minlength="6">
                <div class="form-text">Ít nhất 6 ký tự.</div>
                 <div class="invalid-feedback">
                    Mật khẩu phải có ít nhất 6 ký tự.
                </div>
            </div>
             <div class="col-md-6">
                <label for="confirm_password" class="form-label"><i class="fas fa-check-circle me-1"></i> Xác nhận mật khẩu <span class="text-danger">*</span></label>
                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                 <div class="invalid-feedback">
                    Vui lòng xác nhận mật khẩu.
                </div>
            </div>

            <div class="col-12 text-center mt-4">
                <button type="submit" class="btn btn-success px-5">
                    <i class="fas fa-user-check me-2"></i> Đăng ký Ngay
                </button>
            </div>
        </form>
    <?php endif; ?>

    <div class="text-center mt-4 login-link">
        <small>Đã có tài khoản? <a href="Login.php">Đăng nhập tại đây</a></small>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Example starter JavaScript for disabling form submissions if there are invalid fields
    (function () {
      'use strict'

      // Fetch all the forms we want to apply custom Bootstrap validation styles to
      var forms = document.querySelectorAll('.needs-validation')

      // Loop over them and prevent submission
      Array.prototype.slice.call(forms)
        .forEach(function (form) {
          form.addEventListener('submit', function (event) {
            if (!form.checkValidity()) {
              event.preventDefault()
              event.stopPropagation()
            }

            form.classList.add('was-validated')
          }, false)
        })
    })()
</script>
</body>
</html>