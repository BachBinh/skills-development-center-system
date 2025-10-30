<?php
require_once(__DIR__ . '/../../../config/db_connection.php');
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: ../../home/Login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $fullname = $_POST['fullname'];
    $phone = $_POST['phone'];
    $password = $_POST['password'];

    if ($password !== '') {
        $stmt = $conn->prepare("UPDATE user SET FullName = ?, Phone = ?, Password = ? WHERE UserID = ?");
        $stmt->bind_param("sssi", $fullname, $phone, $password, $user_id);
    } else {
        $stmt = $conn->prepare("UPDATE user SET FullName = ?, Phone = ? WHERE UserID = ?");
        $stmt->bind_param("ssi", $fullname, $phone, $user_id);
    }

    if ($stmt->execute()) {
        $success = "Cập nhật thông tin thành công.";
    } else {
        $error = "Có lỗi xảy ra khi cập nhật.";
    }
}

// Lấy thông tin hiện tại
$stmt = $conn->prepare("SELECT FullName, Email, Phone FROM user WHERE UserID = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
?>

<div class="container mt-4">
    <h3 class="mb-3">👤 Thông tin cá nhân</h3>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= $success ?></div>
    <?php elseif ($error): ?>
        <div class="alert alert-danger"><?= $error ?></div>
    <?php endif; ?>

    <form method="POST" class="row g-3">
        <div class="col-md-6">
            <label class="form-label">Họ và tên</label>
            <input type="text" name="fullname" value="<?= htmlspecialchars($user['FullName']) ?>" class="form-control" required>
        </div>
        <div class="col-md-6">
            <label class="form-label">Email (không thay đổi)</label>
            <input type="email" value="<?= htmlspecialchars($user['Email']) ?>" class="form-control" readonly>
        </div>
        <div class="col-md-6">
            <label class="form-label">Số điện thoại</label>
            <input type="text" name="phone" value="<?= htmlspecialchars($user['Phone']) ?>" class="form-control">
        </div>
        <div class="col-md-6">
            <label class="form-label">Mật khẩu mới (nếu muốn đổi)</label>
            <input type="password" name="password" class="form-control" placeholder="Để trống nếu không thay đổi">
        </div>
        <div class="col-12">
            <button type="submit" class="btn btn-primary">Lưu thay đổi</button>
        </div>
    </form>
</div>
