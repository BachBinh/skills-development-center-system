<?php
require_once(__DIR__ . '/../config/db_connection.php'); // Điều chỉnh đường dẫn

$token = $_GET['token'] ?? null;
$error = '';
$success = '';
$user_id = null;
$showForm = false; // Chỉ hiển thị form nếu token hợp lệ

if (!$token) {
    $error = "Thiếu mã đặt lại mật khẩu.";
} else {
    // --- Kiểm tra Token ---
    $stmt = $conn->prepare("SELECT UserID FROM user WHERE reset_token = ? AND reset_token_expires_at > NOW() AND Status = 'active'");
    if ($stmt) {
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            // Token hợp lệ
            $user = $result->fetch_assoc();
            $user_id = $user['UserID'];
            $showForm = true; // Cho phép hiển thị form đặt lại mk
        } else {
            // Token không hợp lệ hoặc đã hết hạn
            $error = "Mã đặt lại mật khẩu không hợp lệ hoặc đã hết hạn.";
        }
        $stmt->close();
    } else {
        $error = "Lỗi hệ thống khi kiểm tra mã.";
        error_log("Prepare failed (check token): (" . $conn->errno . ") " . $conn->error);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $showForm) { // Chỉ xử lý POST nếu token ban đầu hợp lệ
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $submitted_token = $_POST['token']; // Lấy token từ hidden field

    if ($submitted_token !== $token) {
        $error = "Yêu cầu không hợp lệ.";
        $showForm = false; // Không cho submit nữa
    } elseif (empty($password) || empty($confirm_password)) {
        $error = 'Vui lòng nhập cả mật khẩu mới và xác nhận mật khẩu.';
    } elseif (strlen($password) < 6) { // Kiểm tra độ dài tối thiểu
        $error = 'Mật khẩu mới phải có ít nhất 6 ký tự.';
    } elseif ($password !== $confirm_password) {
        $error = 'Mật khẩu mới và xác nhận mật khẩu không khớp.';
    } else {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT); // Hash mật khẩu mới

        $updateStmt = $conn->prepare("UPDATE user SET Password = ?, reset_token = NULL, reset_token_expires_at = NULL WHERE UserID = ? AND reset_token = ?"); // Thêm đk token để chắc chắn
        if ($updateStmt) {
            $updateStmt->bind_param("sis", $hashedPassword, $user_id, $token);

            if ($updateStmt->execute()) {
                $_SESSION['flash_message'] = ['type' => 'success', 'text' => 'Mật khẩu của bạn đã được đặt lại thành công. Vui lòng đăng nhập.'];
                header("Location: Login.php"); 
                exit();
            } else {
                $error = "Lỗi khi cập nhật mật khẩu. Vui lòng thử lại.";
                 error_log("Execute failed (update password): (" . $updateStmt->errno . ") " . $updateStmt->error);
            }
             $updateStmt->close();
        } else {
            $error = "Lỗi hệ thống khi chuẩn bị cập nhật mật khẩu.";
            error_log("Prepare failed (update password): (" . $conn->errno . ") " . $conn->error);
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đặt lại mật khẩu - Hệ thống Thiên Đỉnh</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
     <style>
         html, body { height: 100%; }
        body {
            background: linear-gradient(to right, #6a11cb 0%, #2575fc 100%);
            display: flex; justify-content: center; align-items: center; padding: 15px;
        }
        .reset-box {
             background-color: white; padding: 30px; border-radius: 8px;
             box-shadow: 0 0 15px rgba(0,0,0,0.1); width: 100%; max-width: 450px;
        }
    </style>
</head>
<body>
<div class="reset-box">
    <h3 class="mb-3 text-center"><i class="fas fa-lock-open me-2"></i>Đặt lại mật khẩu</h3>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($showForm): // Chỉ hiển thị form nếu token hợp lệ ?>
        <form method="POST" action="reset_password.php?token=<?= htmlspecialchars($token) ?>">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
            <div class="mb-3 form-floating">
                <input type="password" class="form-control" id="password" name="password" placeholder="Mật khẩu mới" required minlength="6">
                <label for="password"><i class="fas fa-lock me-2"></i>Mật khẩu mới</label>
                 <div class="form-text">Ít nhất 6 ký tự.</div>
            </div>
            <div class="mb-3 form-floating">
                 <input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="Xác nhận mật khẩu mới" required>
                <label for="confirm_password"><i class="fas fa-check-circle me-2"></i>Xác nhận mật khẩu mới</label>
            </div>
            <button type="submit" class="btn btn-primary w-100">
                <i class="fas fa-save me-2"></i> Đặt lại mật khẩu
            </button>
        </form>
    <?php else: ?>
         <div class="text-center mt-4">
             <a href="Login.php" class="btn btn-secondary"><i class="fas fa-arrow-left me-1"></i> Quay lại Đăng nhập</a>
         </div>
    <?php endif; ?>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>