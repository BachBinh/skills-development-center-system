<?php
// config/db_connection.php
// Đặt múi giờ mặc định cho toàn bộ ứng dụng PHP (Ví dụ: Giờ Việt Nam)
date_default_timezone_set('Asia/Ho_Chi_Minh'); 

$db_host = "localhost";
$db_user = "root";
$db_pass = ""; // Để trống nếu không có mật khẩu
$db_name = "thiendinhsystem";

// Tạo kết nối OOP
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

// Kiểm tra kết nối
if ($conn->connect_error) {
    // Không nên hiển thị chi tiết lỗi cho người dùng cuối trong production
    // Ghi log lỗi thay vào đó
    error_log("Database Connection Failed: " . $conn->connect_error);
    die("Không thể kết nối đến cơ sở dữ liệu. Vui lòng thử lại sau."); 
}

// Thiết lập charset UTF-8
if (!$conn->set_charset("utf8mb4")) {
     error_log("Error loading character set utf8mb4: " . $conn->error);
     // Có thể không cần die ở đây nếu ứng dụng vẫn chạy được
}

// Thường khởi động session ở đây để dùng chung
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
?>