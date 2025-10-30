<?php
require_once(__DIR__ . '/../../../config/db_connection.php'); 

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../../home/Login.php"); 
    exit();
}

$user_id = $_SESSION['user_id'];
$currentFullName = '';
$currentEmail = '';
$currentPhone = '';

// --- Biến cho thông báo ---
$profile_error = '';
$profile_success = '';
$password_error = '';
$password_success = '';

// --- Lấy thông tin hiện tại của Admin ---
$stmt_get = $conn->prepare("SELECT FullName, Email, Phone FROM user WHERE UserID = ?");
if ($stmt_get) {
    $stmt_get->bind_param("i", $user_id);
    $stmt_get->execute();
    $result_get = $stmt_get->get_result();
    if ($user_data = $result_get->fetch_assoc()) {
        $currentFullName = $user_data['FullName'];
        $currentEmail = $user_data['Email'];
        $currentPhone = $user_data['Phone'];
    } else {
        session_destroy();
        header("Location: ../../home/Login.php");
        exit();
    }
    $stmt_get->close();
} else {
     $profile_error = "Lỗi khi lấy thông tin người dùng."; 
     error_log("Prepare failed (get user profile): (" . $conn->errno . ") " . $conn->error);
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $newFullName = trim($_POST['fullname'] ?? '');
    $newPhone = trim($_POST['phone'] ?? '');

    if (empty($newFullName)) {
        $profile_error = "Họ và tên không được để trống.";
    } else {
        $stmt_update_profile = $conn->prepare("UPDATE user SET FullName = ?, Phone = ? WHERE UserID = ?");
        if ($stmt_update_profile) {
            $phone_to_update = !empty($newPhone) ? $newPhone : null; // Cho phép phone là NULL
            $stmt_update_profile->bind_param("ssi", $newFullName, $phone_to_update, $user_id);
            if ($stmt_update_profile->execute()) {
                $profile_success = "Cập nhật thông tin hồ sơ thành công!";
                $currentFullName = $newFullName; 
                $currentPhone = $phone_to_update;
                $_SESSION['fullname'] = $newFullName; 
            } else {
                $profile_error = "Lỗi khi cập nhật hồ sơ: " . $stmt_update_profile->error;
                error_log("Execute failed (update profile): (" . $stmt_update_profile->errno . ") " . $stmt_update_profile->error);
            }
            $stmt_update_profile->close();
        } else {
            $profile_error = "Lỗi hệ thống khi chuẩn bị cập nhật hồ sơ.";
            error_log("Prepare failed (update profile): (" . $conn->errno . ") " . $conn->error);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $password_error = "Vui lòng điền đầy đủ các trường mật khẩu.";
    } elseif (strlen($new_password) < 6) {
        $password_error = "Mật khẩu mới phải có ít nhất 6 ký tự.";
    } elseif ($new_password !== $confirm_password) {
        $password_error = "Mật khẩu mới và xác nhận mật khẩu không khớp.";
    } else {
        $stmt_check_pass = $conn->prepare("SELECT Password FROM user WHERE UserID = ?");
        if ($stmt_check_pass) {
            $stmt_check_pass->bind_param("i", $user_id);
            $stmt_check_pass->execute();
            $result_pass = $stmt_check_pass->get_result();
            if ($user_pass_data = $result_pass->fetch_assoc()) {
                $currentHashedPassword = $user_pass_data['Password'];

                if (password_verify($current_password, $currentHashedPassword)) {
                    $newHashedPassword = password_hash($new_password, PASSWORD_DEFAULT);

                    $stmt_update_pass = $conn->prepare("UPDATE user SET Password = ? WHERE UserID = ?");
                    if ($stmt_update_pass) {
                        $stmt_update_pass->bind_param("si", $newHashedPassword, $user_id);
                        if ($stmt_update_pass->execute()) {
                            $password_success = "Đổi mật khẩu thành công!";
                        } else {
                            $password_error = "Lỗi khi cập nhật mật khẩu mới: " . $stmt_update_pass->error;
                             error_log("Execute failed (update password): (" . $stmt_update_pass->errno . ") " . $stmt_update_pass->error);
                        }
                        $stmt_update_pass->close();
                    } else {
                        $password_error = "Lỗi hệ thống khi chuẩn bị cập nhật mật khẩu.";
                        error_log("Prepare failed (update password): (" . $conn->errno . ") " . $conn->error);
                    }

                } else {
                    $password_error = "Mật khẩu hiện tại không chính xác.";
                }
            } else {
                 $password_error = "Không tìm thấy thông tin người dùng."; // Lỗi lạ
            }
            $stmt_check_pass->close();
        } else {
             $password_error = "Lỗi hệ thống khi kiểm tra mật khẩu.";
             error_log("Prepare failed (check password): (" . $conn->errno . ") " . $conn->error);
        }
    }
}

?>

<div class="container-fluid py-3">
    <h3 class="mb-4"><i class="fas fa-user-cog me-2"></i>Hồ sơ của tôi</h3>

    <div class="row">
        <!-- Cột Thông tin Hồ sơ -->
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-id-card me-2"></i>Thông tin cá nhân</h5>
                </div>
                <div class="card-body">
                    <?php if ($profile_error): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?= htmlspecialchars($profile_error) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php elseif ($profile_success): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <?= htmlspecialchars($profile_success) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="Dashboard.php?page=Profile">
                        <input type="hidden" name="update_profile" value="1">
                        <div class="mb-3">
                            <label for="email" class="form-label"><i class="fas fa-envelope me-2"></i>Email (Không thể thay đổi)</label>
                            <input type="email" class="form-control" id="email" value="<?= htmlspecialchars($currentEmail) ?>" readonly disabled>
                            <div class="form-text">Email được sử dụng để đăng nhập và nhận thông báo.</div>
                        </div>
                        <div class="mb-3">
                             <label for="fullname" class="form-label"><i class="fas fa-user me-2"></i>Họ và tên <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="fullname" name="fullname" value="<?= htmlspecialchars($currentFullName) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="phone" class="form-label"><i class="fas fa-phone me-2"></i>Số điện thoại</label>
                            <input type="tel" class="form-control" id="phone" name="phone" value="<?= htmlspecialchars($currentPhone ?? '') ?>" pattern="[0-9]{10,11}" placeholder="Nhập số điện thoại (tùy chọn)">
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i> Cập nhật thông tin
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Cột Đổi Mật khẩu -->
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-warning text-dark">
                     <h5 class="mb-0"><i class="fas fa-key me-2"></i>Đổi mật khẩu</h5>
                </div>
                <div class="card-body">
                     <?php if ($password_error): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?= htmlspecialchars($password_error) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php elseif ($password_success): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                             <?= htmlspecialchars($password_success) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="Dashboard.php?page=Profile">
                        <input type="hidden" name="change_password" value="1">
                        <div class="mb-3">
                             <label for="current_password" class="form-label"><i class="fas fa-lock me-2"></i>Mật khẩu hiện tại <span class="text-danger">*</span></label>
                             <input type="password" class="form-control" id="current_password" name="current_password" required>
                        </div>
                        <div class="mb-3">
                            <label for="new_password" class="form-label"><i class="fas fa-lock-open me-2"></i>Mật khẩu mới <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" id="new_password" name="new_password" required minlength="6">
                            <div class="form-text">Ít nhất 6 ký tự.</div>
                        </div>
                        <div class="mb-3">
                             <label for="confirm_password" class="form-label"><i class="fas fa-check-circle me-2"></i>Xác nhận mật khẩu mới <span class="text-danger">*</span></label>
                             <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                        </div>
                        <button type="submit" class="btn btn-warning">
                           <i class="fas fa-sync-alt me-2"></i> Đổi mật khẩu
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>