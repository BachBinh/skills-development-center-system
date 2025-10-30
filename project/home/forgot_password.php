<?php
require_once(__DIR__ . '/../config/db_connection.php');

// Include thư viện PHPMailer (chạy ter lệnh - Composer: composer require phpmailer/phpmailer)
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require '../vendor/autoload.php'; // Đường dẫn đến autoload của Composer

// cấu hình smtp
function sendPasswordResetEmail($recipientEmail, $recipientName, $resetLink) {
    $mail = new PHPMailer(true);
    try {
        //$mail->SMTPDebug = SMTP::DEBUG_SERVER; 
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'techstorehtt@gmail.com'; 
        $mail->Password   = 'mbkiupcndgxabqka'; 
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; 
        $mail->Port       = 465; 

        // Recipients
        $mail->setFrom('thiendinhsystem@gmail.com', 'Hệ thống ThienDinh');
        $mail->addAddress($recipientEmail, $recipientName); 

        // Content
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = 'Yêu cầu đặt lại mật khẩu cho Hệ thống ThienDinh';
        $mail->Body    = "Xin chào " . htmlspecialchars($recipientName) . ",<br><br>" .
                         "Bạn (hoặc ai đó) đã yêu cầu đặt lại mật khẩu cho tài khoản của bạn.<br>" .
                         "Vui lòng nhấp vào liên kết bên dưới để đặt lại mật khẩu. Liên kết này sẽ hết hạn sau 1 giờ:<br><br>" .
                         "<a href='" . $resetLink . "'>" . $resetLink . "</a><br><br>" .
                         "Nếu bạn không yêu cầu điều này, vui lòng bỏ qua email này.<br><br>" .
                         "Trân trọng,<br>Hệ thống ThienDinh";
        $mail->AltBody = 'Để đặt lại mật khẩu, vui lòng truy cập liên kết sau (hết hạn sau 1 giờ): ' . $resetLink;

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}


$message = '';
$message_type = ''; 

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['email'])) {
        $message = 'Vui lòng nhập địa chỉ email.';
        $message_type = 'danger';
    } else {
        $email = trim($_POST['email']);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Địa chỉ email không hợp lệ.';
            $message_type = 'danger';
        } else {
            $stmt = $conn->prepare("SELECT UserID, FullName FROM user WHERE Email = ? AND Status = 'active'");
            if ($stmt) {
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows === 1) {
                    $user = $result->fetch_assoc();
                    $user_id = $user['UserID'];
                    $user_name = $user['FullName'];

                    // Tạo token reset
                    try {
                         $token = bin2hex(random_bytes(32)); // Token an toàn
                    } catch (Exception $e) {
                        error_log("Could not generate random bytes: " . $e->getMessage());
                         $token = md5(uniqid(rand(), true)); 
                    }
                    $expires = date('Y-m-d H:i:s', strtotime('+1 hour')); // Token hết hạn sau 1 giờ

                    // Cập nhật token vào CSDL
                    $updateStmt = $conn->prepare("UPDATE user SET reset_token = ?, reset_token_expires_at = ? WHERE UserID = ?");
                    if ($updateStmt) {
                        $updateStmt->bind_param("ssi", $token, $expires, $user_id);
                        if ($updateStmt->execute()) {
                            // link reset
                            $resetLink = 'http://localhost/thiendinhsystem/home/reset_password.php?token=' . $token; 

                            
                            if (sendPasswordResetEmail($email, $user_name, $resetLink)) {
                                $message = 'Một liên kết đặt lại mật khẩu đã được gửi đến email của bạn. Vui lòng kiểm tra hộp thư đến (và spam bạn nhé).';
                                $message_type = 'success';
                            } else {
                                $message = 'Có lỗi xảy ra khi gửi email. Vui lòng thử lại sau hoặc liên hệ quản trị viên.';
                                $message_type = 'danger';
                            }
                            
                            //$message = 'Gửi email thành công (DEBUG)! Truy cập link sau để reset: <a href="'.$resetLink.'">'.$resetLink.'</a>';
                            //$message_type = 'info'; // Dùng info cho debug


                        } else {
                             $message = 'Lỗi khi cập nhật mã đặt lại mật khẩu. Vui lòng thử lại.';
                             $message_type = 'danger';
                             error_log("Execute failed (update token): (" . $updateStmt->errno . ") " . $updateStmt->error);
                        }
                         $updateStmt->close();
                    } else {
                        $message = 'Lỗi hệ thống khi chuẩn bị cập nhật. Vui lòng thử lại.';
                        $message_type = 'danger';
                        error_log("Prepare failed (update token): (" . $conn->errno . ") " . $conn->error);
                    }

                } else {
                    // Email không tồn tại hoặc inactive
                    // Hiển thị thông báo chung chung để tránh tiết lộ email nào tồn tại
                    $message = 'Nếu địa chỉ email của bạn tồn tại trong hệ thống, một liên kết đặt lại mật khẩu đã được gửi. Vui lòng kiểm tra hộp thư đến (và spam).';
                    $message_type = 'success'; // Vẫn là success để không lộ thông tin
                }
                 $stmt->close();
            } else {
                 $message = 'Lỗi hệ thống khi tìm kiếm email. Vui lòng thử lại.';
                 $message_type = 'danger';
                 error_log("Prepare failed (find email): (" . $conn->errno . ") " . $conn->error);
            }
        }
    }
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quên mật khẩu - Hệ thống Thiên Đỉnh</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
         html, body { height: 100%; }
        body {
            background: linear-gradient(to right, #6a11cb 0%, #2575fc 100%);
            display: flex; justify-content: center; align-items: center; padding: 15px;
        }
        .forgot-box {
             background-color: white; padding: 30px; border-radius: 8px;
             box-shadow: 0 0 15px rgba(0,0,0,0.1); width: 100%; max-width: 450px;
        }
    </style>
</head>
<body>
<div class="forgot-box">
    <h3 class="mb-3 text-center"><i class="fas fa-key me-2"></i>Quên mật khẩu</h3>
    <p class="text-muted text-center mb-4">Nhập email của bạn để nhận liên kết đặt lại mật khẩu.</p>

    <?php if ($message): ?>
        <div class="alert alert-<?= $message_type ?>"><?= $message ?></div>
    <?php endif; ?>

    <form method="POST" action="forgot_password.php">
        <div class="mb-3 form-floating">
             <input type="email" class="form-control" id="email" name="email" placeholder="Email của bạn" required value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>">
            <label for="email"><i class="fas fa-envelope me-2"></i>Địa chỉ Email</label>
        </div>
        <button type="submit" class="btn btn-primary w-100">
            <i class="fas fa-paper-plane me-2"></i> Gửi liên kết đặt lại
        </button>
    </form>
    <p class="mt-3 text-center">
        <a href="Login.php"><i class="fas fa-arrow-left me-1"></i> Quay lại Đăng nhập</a>
    </p>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>