<?php
require_once(__DIR__ . '/../../../config/db_connection.php');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'instructor') {
    header("Location: ../home/Login.php");
    exit();
}

$userID = $_SESSION['user_id'];
$result = $conn->query("SELECT FullName, Email, Phone FROM user WHERE UserID = $userID");
$info = $result->fetch_assoc();

// ✅ Xử lý cập nhật tên & số điện thoại
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['update_info'])) {
    $newName = trim($_POST['FullName']);
    $newPhone = trim($_POST['Phone']);
    
    if (!empty($newName) && !empty($newPhone)) {
        $conn->query("UPDATE user SET FullName = '$newName', Phone = '$newPhone' WHERE UserID = $userID");
        $_SESSION['message'] = "Thông tin đã được cập nhật!";
        header("Location: Dashboard.php?page=Profile");
        exit();
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['change_password'])) {
    $oldPassword = $_POST['old_password'];
    $newPassword = $_POST['new_password'];
    $confirmPassword = $_POST['confirm_password'];

    // ✅ Lấy mật khẩu cũ từ database
    $currentPasswordHash = $conn->query("SELECT Password FROM user WHERE UserID = $userID")->fetch_assoc()['Password'];

    // ✅ Kiểm tra xem mật khẩu mới có trùng với mật khẩu cũ không
    if (password_verify($newPassword, $currentPasswordHash)) {
        $_SESSION['error'] = "Mật khẩu mới không được trùng với mật khẩu cũ!";
    } elseif (!password_verify($oldPassword, $currentPasswordHash)) {
        $_SESSION['error'] = "Mật khẩu cũ không đúng!";
    } elseif ($newPassword !== $confirmPassword) {
        $_SESSION['error'] = "Mật khẩu mới và xác nhận không khớp!";
    } else {
        // ✅ Đổi mật khẩu nếu hợp lệ
        $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $conn->query("UPDATE user SET Password = '$newPasswordHash' WHERE UserID = $userID");

        echo "<script>
            alert('🔒 Mật khẩu đã được đổi thành công!');
            setTimeout(function() {
                window.location.href = 'Dashboard.php?page=Profile';
            }, 3000);
        </script>";

        exit();
    }
}



?>

<h3 class="mb-3">👤 Hồ sơ cá nhân</h3>

<?php if (isset($_SESSION['message'])): ?>
    <div class="alert alert-success"><?= $_SESSION['message']; unset($_SESSION['message']); ?></div>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger"><?= $_SESSION['error']; unset($_SESSION['error']); ?></div>
<?php endif; ?>

<!-- Hiển thị thông tin cá nhân với nút Edit -->
<form method="POST">
    <table class="table">
        <tr>
            <th>Họ và Tên:</th>
            <td>
                <span id="displayName"><?= htmlspecialchars($info['FullName']) ?></span>
                <input type="text" name="FullName" id="editNameInput" class="form-control d-none" value="<?= htmlspecialchars($info['FullName']) ?>">
                <button type="button" class="btn btn-link text-primary" id="editNameBtn">✏️ Edit</button>
            </td>
        </tr>
        <tr><th>Email:</th><td><?= htmlspecialchars($info['Email']) ?></td></tr>
        <tr>
            <th>Số điện thoại:</th>
            <td>
                <span id="displayPhone"><?= htmlspecialchars($info['Phone']) ?></span>
                <input type="text" name="Phone" id="editPhoneInput" class="form-control d-none" value="<?= htmlspecialchars($info['Phone']) ?>">
                <button type="button" class="btn btn-link text-primary" id="editPhoneBtn">✏️ Edit</button>
            </td>
        </tr>
    </table>
    <button type="submit" name="update_info" class="btn btn-primary d-none" id="saveBtn">💾 Lưu cập nhật</button>
</form>

<!-- Form đổi mật khẩu -->
<h4 class="mt-4">🔒 Đổi mật khẩu</h4>
<form method="POST" onsubmit="return confirm('Bạn có chắc chắn muốn đổi mật khẩu?')">
    <input type="password" name="old_password" class="form-control mb-2" placeholder="Mật khẩu hiện tại" required>
    <input type="password" name="new_password" class="form-control mb-2" placeholder="Mật khẩu mới" required>
    <input type="password" name="confirm_password" class="form-control mb-2" placeholder="Xác nhận mật khẩu mới" required>
    <button type="submit" name="change_password" class="btn btn-danger">🔄 Cập nhật mật khẩu</button>
</form>

<!-- JavaScript để bật ô nhập -->
<script>
document.getElementById("editNameBtn").addEventListener("click", function() {
    document.getElementById("displayName").classList.add("d-none");
    document.getElementById("editNameInput").classList.remove("d-none");
    document.getElementById("editNameBtn").classList.add("d-none");
    document.getElementById("saveBtn").classList.remove("d-none");
});

document.getElementById("editPhoneBtn").addEventListener("click", function() {
    document.getElementById("displayPhone").classList.add("d-none");
    document.getElementById("editPhoneInput").classList.remove("d-none");
    document.getElementById("editPhoneBtn").classList.add("d-none");
    document.getElementById("saveBtn").classList.remove("d-none");
});
</script>
