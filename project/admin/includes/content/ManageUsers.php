<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once(__DIR__ . '/../../../config/db_connection.php');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../../home/Login.php");
    exit();
}

$searchName = isset($_GET['name']) ? trim($_GET['name']) : '';
$searchEmail = isset($_GET['email']) ? trim($_GET['email']) : '';
$searchRole = isset($_GET['role']) ? $_GET['role'] : '';
$searchStatus = isset($_GET['status']) ? $_GET['status'] : '';
$hasSearch = !empty($searchName) || !empty($searchEmail) || !empty($searchRole) || !empty($searchStatus);
$managedRoles = ['student', 'instructor', 'staff']; 

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $name = $_POST['fullname'];
    $email = $_POST['email'];
    $phone = !empty($_POST['phone']) ? $_POST['phone'] : null; 
    $role = $_POST['role'];
    $passwordInput = $_POST['password']; 
    $hashedPassword = password_hash($passwordInput, PASSWORD_DEFAULT);
    
    $status = $_POST['status'];

    if (!in_array($role, $managedRoles)) {
         $_SESSION['message'] = ['type' => 'danger', 'text' => 'Vai trò không hợp lệ.'];
    } else {
        $checkEmailStmt = $conn->prepare("SELECT UserID FROM user WHERE Email = ?");
        $checkEmailStmt->bind_param("s", $email);
        $checkEmailStmt->execute();
        $checkEmailResult = $checkEmailStmt->get_result();

        if ($checkEmailResult->num_rows > 0) {
            $_SESSION['message'] = ['type' => 'warning', 'text' => 'Email này đã được sử dụng.'];
            $checkEmailStmt->close();
        } else {
            $checkEmailStmt->close();
            $stmt = $conn->prepare("INSERT INTO user (FullName, Email, Phone, Role, Password, Status) VALUES (?, ?, ?, ?, ?, ?)");

            $stmt->bind_param("ssssss", $name, $email, $phone, $role, $hashedPassword, $status); 

            if ($stmt->execute()) {
                $user_id = $conn->insert_id; // Lấy ID của user vừa được thêm
                $insertRelated = true; 

                if ($role === 'student') {
                    $studentStmt = $conn->prepare("INSERT INTO student (UserID, EnrollmentDate) VALUES (?, CURDATE())");
                    $studentStmt->bind_param("i", $user_id);
                    if (!$studentStmt->execute()) $insertRelated = false;
                    $studentStmt->close();
                } elseif ($role === 'instructor') {
                    $instructorStmt = $conn->prepare("INSERT INTO instructor (UserID) VALUES (?)"); 
                    $instructorStmt->bind_param("i", $user_id);
                     if (!$instructorStmt->execute()) $insertRelated = false;
                    $instructorStmt->close();
                } elseif ($role === 'staff') {
                    $staffStmt = $conn->prepare("INSERT INTO staff (UserID, HireDate, EmploymentStatus) VALUES (?, CURDATE(), ?)");
                    $defaultStatus = 'Full-Time'; // Đặt trạng thái mặc định
                    $staffStmt->bind_param("is", $user_id, $defaultStatus);
                     if (!$staffStmt->execute()) $insertRelated = false;
                    $staffStmt->close();
                }

                if ($insertRelated) {
                    $_SESSION['message'] = ['type' => 'success', 'text' => 'Thêm người dùng thành công!'];
                } else {
                     $_SESSION['message'] = ['type' => 'danger', 'text' => 'Thêm người dùng thành công, nhưng có lỗi khi thêm thông tin chi tiết vai trò.'];
                }

            } else {
                $_SESSION['message'] = ['type' => 'danger', 'text' => 'Lỗi khi thêm người dùng: ' . $stmt->error];
            }
            $stmt->close();
            header("Location: Dashboard.php?page=ManageUsers");
            exit();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user'])) {
    $id = $_POST['user_id'];
    $name = $_POST['fullname'];
    $phone = !empty($_POST['phone']) ? $_POST['phone'] : null;
    $status = $_POST['status'];
    $checkAdminStmt = $conn->prepare("SELECT Role FROM user WHERE UserID = ?");
    $checkAdminStmt->bind_param("i", $id);
    $checkAdminStmt->execute();
    $userToUpdate = $checkAdminStmt->get_result()->fetch_assoc();
    $checkAdminStmt->close();

    if ($userToUpdate && $userToUpdate['Role'] === 'admin' && $id != $_SESSION['user_id']) {
         $_SESSION['message'] = ['type' => 'danger', 'text' => 'Không thể chỉnh sửa thông tin của quản trị viên khác.'];
    } 
    elseif ($id == $_SESSION['user_id'] && $status == 'inactive') {
         $_SESSION['message'] = ['type' => 'warning', 'text' => 'Bạn không thể tự chuyển trạng thái của mình thành Inactive.'];
    }
    elseif ($userToUpdate && in_array($userToUpdate['Role'], $managedRoles)) { // Chỉ cho sửa các role được quản lý
        $stmt = $conn->prepare("UPDATE user SET FullName = ?, Phone = ?, Status = ? WHERE UserID = ?");
        $stmt->bind_param("sssi", $name, $phone, $status, $id);
        if ($stmt->execute()) {
            $_SESSION['message'] = ['type' => 'success', 'text' => 'Cập nhật người dùng thành công!'];
        } else {
            $_SESSION['message'] = ['type' => 'danger', 'text' => 'Lỗi khi cập nhật người dùng: ' . $stmt->error];
        }
        $stmt->close();
    } else {
         $_SESSION['message'] = ['type' => 'danger', 'text' => 'Người dùng không tồn tại hoặc không thể sửa vai trò này.'];
    }

    header("Location: Dashboard.php?page=ManageUsers");
    exit();
}

if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];

    // Không cho phép xóa chính mình
    if ($id === $_SESSION['user_id']) {
        $_SESSION['message'] = ['type' => 'danger', 'text' => 'Bạn không thể xóa chính mình.'];
    } else {
        $checkRoleStmt = $conn->prepare("SELECT Role FROM user WHERE UserID = ?");
        $checkRoleStmt->bind_param("i", $id);
        $checkRoleStmt->execute();
        $userToDelete = $checkRoleStmt->get_result()->fetch_assoc();
        $checkRoleStmt->close();

        if ($userToDelete && $userToDelete['Role'] === 'admin') {
            $_SESSION['message'] = ['type' => 'danger', 'text' => 'Không thể xóa tài khoản quản trị viên.'];
        } elseif ($userToDelete && in_array($userToDelete['Role'], $managedRoles)) {
            $deleteStmt = $conn->prepare("DELETE FROM user WHERE UserID = ?");
            $deleteStmt->bind_param("i", $id);
            if ($deleteStmt->execute()) {
                 if ($userToDelete['Role'] === 'student') {
                    $conn->query("DELETE FROM student WHERE UserID = $id");
                 } elseif ($userToDelete['Role'] === 'instructor') {
                     $conn->query("DELETE FROM instructor WHERE UserID = $id"); 
                 }

                $_SESSION['message'] = ['type' => 'success', 'text' => 'Xóa người dùng thành công!'];
            } else {
                $_SESSION['message'] = ['type' => 'danger', 'text' => 'Lỗi khi xóa người dùng: ' . $deleteStmt->error . '. Có thể do ràng buộc dữ liệu.'];
            }
             $deleteStmt->close();

        } else {
             $_SESSION['message'] = ['type' => 'warning', 'text' => 'Người dùng không tồn tại hoặc không thể xóa vai trò này.'];
        }
    }
    header("Location: Dashboard.php?page=ManageUsers");
    exit();
}

$sql = "SELECT UserID, FullName, Email, Phone, Role, Status FROM user WHERE Role IN (?, ?, ?)"; // Sử dụng placeholder
$params = $managedRoles; 
$types = "sss"; // 3 chuỗi

if (!empty($searchName)) {
    $sql .= " AND FullName LIKE ?";
    $params[] = '%' . $searchName . '%';
    $types .= "s";
}

if (!empty($searchEmail)) {
    $sql .= " AND Email LIKE ?";
    $params[] = '%' . $searchEmail . '%';
    $types .= "s";
}

if (!empty($searchRole) && in_array($searchRole, $managedRoles)) {
    $sql = "SELECT UserID, FullName, Email, Phone, Role, Status FROM user WHERE Role = ?";
    $params = [$searchRole]; // Chỉ tìm role này
    $types = "s";
    if (!empty($searchName)) { $sql .= " AND FullName LIKE ?"; $params[] = '%' . $searchName . '%'; $types .= "s"; }
    if (!empty($searchEmail)) { $sql .= " AND Email LIKE ?"; $params[] = '%' . $searchEmail . '%'; $types .= "s"; }
    if (!empty($searchStatus)) { $sql .= " AND Status = ?"; $params[] = $searchStatus; $types .= "s"; }
} else {
     if (!empty($searchStatus)) {
        $sql .= " AND Status = ?";
        $params[] = $searchStatus;
        $types .= "s";
    }
}


$sql .= " ORDER BY Role, FullName";

$stmt = $conn->prepare($sql);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $users = $stmt->get_result();
} else {
    echo "Lỗi chuẩn bị câu lệnh SQL: " . $conn->error;
    $users = false;
}

?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý người dùng</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .modal-backdrop { z-index: 1040 !important; }
        .modal { z-index: 1050 !important; }
        .badge-student { background-color: #0d6efd; color: white; }
        .badge-instructor { background-color: #fd7e14; color: white; }
        .badge-staff { background-color: #6610f2; color: white; } 
        .badge-active { background-color: #198754; color: white; }
        .badge-inactive { background-color: #6c757d; color: white; }
        .table-responsive { min-height: 300px; }
        .card { box-shadow: 0 0.15rem 1.75rem 0 rgba(33, 40, 50, 0.15); }
        .card-header { font-weight: 600; }
    </style>
</head>
<body>
    <div class="container-fluid py-3">
        <h3 class="mb-4">👥 Quản lý người dùng (Sinh viên, Giảng viên, Nhân viên)</h3>
        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert alert-<?= $_SESSION['message']['type'] ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($_SESSION['message']['text']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['message']); ?>
        <?php endif; ?>

        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end">
                    <input type="hidden" name="page" value="ManageUsers">
                    <div class="col-md-3">
                        <label for="search_name" class="form-label">🔍 Họ tên</label>
                        <input type="text" id="search_name" name="name" value="<?= htmlspecialchars($searchName) ?>" class="form-control form-control-sm" placeholder="Tìm theo tên">
                    </div>
                    <div class="col-md-3">
                        <label for="search_email" class="form-label">📧 Email</label>
                        <input type="text" id="search_email" name="email" value="<?= htmlspecialchars($searchEmail) ?>" class="form-control form-control-sm" placeholder="Tìm theo email">
                    </div>
                    <div class="col-md-2">
                        <label for="search_role" class="form-label">👤 Vai trò</label>
                        <select id="search_role" name="role" class="form-select form-select-sm">
                            <option value="">Tất cả vai trò</option>
                            <option value="student" <?= $searchRole === 'student' ? 'selected' : '' ?>>Student</option>
                            <option value="instructor" <?= $searchRole === 'instructor' ? 'selected' : '' ?>>Instructor</option>
                            <option value="staff" <?= $searchRole === 'staff' ? 'selected' : '' ?>>Staff</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="search_status" class="form-label">🔄 Trạng thái</label>
                        <select id="search_status" name="status" class="form-select form-select-sm">
                            <option value="">Tất cả</option>
                            <option value="active" <?= $searchStatus === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $searchStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-sm btn-primary flex-grow-1">
                            <i class="fas fa-search me-1"></i> Tìm
                        </button>
                        <a href="Dashboard.php?page=ManageCourses" class="btn btn-sm btn-outline-secondary" title="Xóa bộ lọc">
                            <i class="fas fa-times"></i>
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($hasSearch && $users): ?>
            <div class="alert alert-info mb-4">
                <i class="fas fa-info-circle me-2"></i> Tìm thấy <strong><?= $users->num_rows ?></strong> người dùng phù hợp.
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center bg-light">
                <h5 class="mb-0">Danh sách người dùng</h5>
                <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#addModal">
                    <i class="fas fa-user-plus me-1"></i> Thêm người dùng
                </button>
            </div>
            
            <div class="card-body p-0"> 
                <div class="table-responsive">
                    <table class="table table-hover table-striped mb-0"> 
                        <thead class="table-light text-center">
                            <tr>
                                <th>Họ tên</th>
                                <th>Email</th>
                                <th>Điện thoại</th>
                                <th>Vai trò</th>
                                <th>Trạng thái</th>
                                <th>Thao tác</th>
                            </tr>
                        </thead>
                        <tbody class="align-middle">
                            <?php if ($users && $users->num_rows > 0): ?>
                                <?php while ($user = $users->fetch_assoc()): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($user['FullName']) ?></td>
                                        <td><?= htmlspecialchars($user['Email']) ?></td>
                                        <td><?= htmlspecialchars($user['Phone'] ?: 'N/A') ?></td>
                                        <td class="text-center">
                                            <?php 
                                                $roleClass = '';
                                                switch ($user['Role']) {
                                                    case 'student': $roleClass = 'badge-student'; break;
                                                    case 'instructor': $roleClass = 'badge-instructor'; break;
                                                    case 'staff': $roleClass = 'badge-staff'; break; 
                                                    default: $roleClass = 'bg-secondary'; 
                                                }
                                            ?>
                                            <span class="badge <?= $roleClass ?>">
                                                <?= ucfirst(htmlspecialchars($user['Role'])) ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge badge-<?= $user['Status'] === 'active' ? 'active' : 'inactive' ?>">
                                                <?= ucfirst(htmlspecialchars($user['Status'])) ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <div class="btn-group btn-group-sm">
                                                <button class="btn btn-outline-primary edit-btn" 
                                                        title="Sửa người dùng"
                                                        data-userid="<?= $user['UserID'] ?>"
                                                        data-fullname="<?= htmlspecialchars($user['FullName']) ?>"
                                                        data-phone="<?= htmlspecialchars($user['Phone'] ?? '') ?>"
                                                        data-status="<?= $user['Status'] ?>">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <a href="?page=ManageUsers&delete=<?= $user['UserID'] ?>" 
                                                   class="btn btn-outline-danger delete-btn"
                                                   title="Xóa người dùng">
                                                    <i class="fas fa-trash-alt"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                                <?php $stmt->close(); ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted fst-italic">
                                        <?= $hasSearch ? 'Không tìm thấy người dùng phù hợp' : 'Chưa có người dùng nào (ngoài admin)' ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal sửa người dùng -->
    <div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <form method="POST" class="modal-content">
                <input type="hidden" name="user_id" id="edit_user_id">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="editModalLabel">✏️ Chỉnh sửa thông tin người dùng</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_fullname" class="form-label">Họ tên <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="fullname" id="edit_fullname" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_phone" class="form-label">Điện thoại</label>
                        <input type="tel" class="form-control" name="phone" id="edit_phone" placeholder="Số điện thoại (tùy chọn)">
                    </div>
                    <div class="mb-3">
                        <label for="edit_status" class="form-label">Trạng thái <span class="text-danger">*</span></label>
                        <select name="status" class="form-select" id="edit_status" required>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                     <p class="text-muted small">Lưu ý: Email và Vai trò không thể thay đổi sau khi tạo.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" name="update_user" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i> Lưu thay đổi
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal thêm người dùng mới -->
    <div class="modal fade" id="addModal" tabindex="-1" aria-labelledby="addModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <form method="POST" class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="addModalLabel">➕ Thêm người dùng mới</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="add_fullname" class="form-label">Họ tên <span class="text-danger">*</span></label>
                        <input type="text" name="fullname" id="add_fullname" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label for="add_email" class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" name="email" id="add_email" class="form-control" required placeholder="example@domain.com">
                    </div>
                    <div class="mb-3">
                        <label for="add_phone" class="form-label">Điện thoại</label>
                        <input type="tel" name="phone" id="add_phone" class="form-control" placeholder="Số điện thoại (tùy chọn)">
                    </div>
                    <div class="mb-3">
                        <label for="add_password" class="form-label">Mật khẩu <span class="text-danger">*</span></label>
                        <input type="password" name="password" id="add_password" class="form-control" required minlength="6">
                         <div class="form-text">Ít nhất 6 ký tự.</div>
                    </div>
                    <div class="mb-3">
                        <label for="add_role" class="form-label">Vai trò <span class="text-danger">*</span></label>
                        <select name="role" id="add_role" class="form-select" required>
                            <option value="" disabled selected>-- Chọn vai trò --</option>
                            <option value="student">Student</option>
                            <option value="instructor">Instructor</option>
                            <!-- --- Thêm Staff vào Thêm mới --- -->
                            <option value="staff">Staff</option> 
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="add_status" class="form-label">Trạng thái <span class="text-danger">*</span></label>
                        <select name="status" id="add_status" class="form-select" required>
                            <option value="active" selected>Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" name="add_user" class="btn btn-success">
                        <i class="fas fa-plus-circle me-1"></i> Thêm người dùng
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Xử lý modal sửa (Cập nhật data attribute nếu cần)
            const editModalElement = document.getElementById('editModal');
            if (editModalElement) {
                 const editModal = new bootstrap.Modal(editModalElement);
                 document.querySelectorAll('.edit-btn').forEach(button => {
                    button.addEventListener('click', function() {
                        document.getElementById('edit_user_id').value = this.dataset.userid;
                        document.getElementById('edit_fullname').value = this.dataset.fullname;
                        document.getElementById('edit_phone').value = this.dataset.phone || ''; // Xử lý phone null
                        document.getElementById('edit_status').value = this.dataset.status;
                        editModal.show();
                    });
                });
            }

            // Xử lý confirm trước khi xóa (Cập nhật thông báo)
            document.querySelectorAll('.delete-btn').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    const row = btn.closest('tr');
                    const userName = row ? row.querySelector('td:first-child').textContent : 'này';
                    const userRole = row ? row.querySelector('.badge[class*="badge-"]').textContent.trim() : 'người dùng';
                    
                    if (!confirm(`Bạn có chắc chắn muốn xóa ${userRole.toLowerCase()} "${userName}" không?\nHành động này sẽ xóa tài khoản đăng nhập và có thể ảnh hưởng đến các dữ liệu liên quan!`)) {
                        e.preventDefault(); 
                    }
                });
            });
        });
    </script>
</body>
</html>