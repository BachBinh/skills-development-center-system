<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['action']) || $_POST['action'] !== 'send_receipt') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit();
}

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit();
}

require_once(__DIR__ . '/../../../vendor/autoload.php'); 
require_once(__DIR__ . '/../../../config/db_connection.php'); 

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$paymentId = filter_input(INPUT_POST, 'payment_id', FILTER_VALIDATE_INT);
$studentEmail = filter_input(INPUT_POST, 'student_email', FILTER_VALIDATE_EMAIL);
$studentName = filter_input(INPUT_POST, 'student_name', FILTER_SANITIZE_SPECIAL_CHARS);
$courseTitle = filter_input(INPUT_POST, 'course_title', FILTER_SANITIZE_SPECIAL_CHARS);
$amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
$paidAt = filter_input(INPUT_POST, 'paid_at', FILTER_SANITIZE_SPECIAL_CHARS);

if (!$paymentId || !$studentEmail || !$studentName || !$courseTitle || $amount === false || !$paidAt) {
     echo json_encode(['success' => false, 'message' => 'Thiếu thông tin hoặc dữ liệu không hợp lệ để gửi hóa đơn.']);
     exit();
}

$mail = new PHPMailer(true);

try {
    // $mail->SMTPDebug = SMTP::DEBUG_OFF; 
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com'; 
    $mail->SMTPAuth   = true;
    $mail->Username   = 'techstorehtt@gmail.com';
    $mail->Password   = 'mbkiupcndgxabqka'; 
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port       = 465;

    // Recipients
    $mail->setFrom('thiendinhsystem@gmail.com', 'Hệ thống Kỹ Năng Pro'); 
    $mail->addAddress($studentEmail, $studentName);

    // Content
    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';
    $mail->Subject = 'Hóa đơn thanh toán khóa học tại Kỹ Năng Pro - Mã GD: #' . $paymentId;

    $emailBody = "
        <p>Xin chào " . htmlspecialchars($studentName) . ",</p>
        <p>Cảm ơn bạn đã hoàn tất thanh toán cho khóa học tại Kỹ Năng Pro. Dưới đây là chi tiết giao dịch của bạn:</p>
        <hr>
        <p><strong>Mã giao dịch:</strong> #" . $paymentId . "</p>
        <p><strong>Khóa học:</strong> " . htmlspecialchars($courseTitle) . "</p>
        <p><strong>Số tiền đã thanh toán:</strong> <strong style='color: red;'>" . number_format($amount, 0, ',', '.') . " VNĐ</strong></p>
        <p><strong>Ngày thanh toán:</strong> " . htmlspecialchars($paidAt) . "</p>
        <hr>
        <p>Nếu có bất kỳ thắc mắc nào, vui lòng liên hệ với chúng tôi.</p>
        <p>Trân trọng,<br>Ban quản trị Hệ thống Kỹ Năng Pro</p>
    ";

    $mail->Body    = $emailBody;
    $mail->AltBody = "Hóa đơn thanh toán khóa học " . $courseTitle . " tại Kỹ Năng Pro. Mã GD: #" . $paymentId . ". Số tiền: " . number_format($amount, 0, ',', '.') . " VNĐ. Ngày TT: " . htmlspecialchars($paidAt);

    $mail->send();
    echo json_encode(['success' => true, 'message' => 'Hóa đơn đã được gửi thành công đến email ' . $studentEmail]);

} catch (Exception $e) {
    error_log("Payment Receipt email could not be sent to {$studentEmail}. Mailer Error: {$mail->ErrorInfo}. PHP Error: {$e->getMessage()}");
    echo json_encode(['success' => false, 'message' => 'Lỗi khi gửi email hóa đơn. Vui lòng thử lại sau hoặc liên hệ hỗ trợ.']);
}

exit();
?>