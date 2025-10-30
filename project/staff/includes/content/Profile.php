<h3 class="mb-3">👤 Hồ sơ cá nhân</h3>

<!-- ✅ Hiển thị thông tin Staff -->
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

<!-- ✅ Form đổi mật khẩu -->
<h4 class="mt-4">🔒 Đổi mật khẩu</h4>
<form method="POST" onsubmit="return confirm('Bạn có chắc chắn muốn đổi mật khẩu?')">
    <input type="password" name="old_password" class="form-control mb-2" placeholder="Mật khẩu hiện tại" required>
    <input type="password" name="new_password" class="form-control mb-2" placeholder="Mật khẩu mới" required>
    <input type="password" name="confirm_password" class="form-control mb-2" placeholder="Xác nhận mật khẩu mới" required>
    <button type="submit" name="change_password" class="btn btn-danger">🔄 Cập nhật mật khẩu</button>
</form>

<!-- ✅ JavaScript để bật ô nhập -->
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
