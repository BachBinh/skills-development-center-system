<?php
require_once(__DIR__ . '/../../../config/db_connection.php'); 
use PHPMailer\PHPMailer\PHPMailer; use PHPMailer\PHPMailer\SMTP; use PHPMailer\PHPMailer\Exception;
require_once(__DIR__ . '/../../../vendor/autoload.php'); 

function sendRejectionEmail($recipientEmail, $recipientName, $courseTitle, $reason) {
    $mail = new PHPMailer(true);
    try {
        //$mail->SMTPDebug = SMTP::DEBUG_OFF; 
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';        
        $mail->SMTPAuth   = true;                    
        $mail->Username   = 'techstorehtt@gmail.com';  
        $mail->Password   = 'mbkiupcndgxabqka';      
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; 
        $mail->Port       = 465;                     

        // Recipients
        $mail->setFrom('thiendinhsystem@gmail.com', 'Hệ thống Kỹ Năng Pro'); 
        $mail->addAddress($recipientEmail, $recipientName);     

        // Content
        $mail->isHTML(true);                                  
        $mail->CharSet = 'UTF-8';                             
        
        // === NỘI DUNG EMAIL TỪ CHỐI ===
        $mail->Subject = 'Thông báo về đăng ký khóa học: ' . $courseTitle;
        
        $reasonText = ''; 
        $nextSteps = ''; 
        switch ($reason) {
            case 'payment_not_received':
                $reasonText = "Chúng tôi rất tiếc chưa nhận được thanh toán học phí của bạn cho khóa học này.";
                $nextSteps = "Vui lòng kiểm tra lại thông tin chuyển khoản hoặc liên hệ với chúng tôi (hotline: 0795440313) để được hỗ trợ nếu bạn đã thanh toán.";
                break;
            case 'course_cancelled':
                $reasonText = "Chúng tôi rất tiếc phải thông báo khóa học <strong>" . htmlspecialchars($courseTitle) . "</strong> đã bị hủy vì lý do bất khả kháng.";
                $nextSteps = "Chúng tôi sẽ liên hệ với bạn trong vòng 7 ngày làm việc để tiến hành hoàn trả học phí (nếu bạn đã thanh toán).";
                break;
            default: 
                $reasonText = "Đăng ký của bạn không được chấp thuận vào thời điểm này.";
                $nextSteps = "Vui lòng liên hệ với bộ phận tư vấn (hotline: 0795440313) để biết thêm chi tiết.";
        }

        $mail->Body    = "Xin chào " . htmlspecialchars($recipientName) . ",<br><br>" .
                         "Chúng tôi rất tiếc phải thông báo về việc đăng ký khóa học <strong>" . htmlspecialchars($courseTitle) . "</strong> của bạn tại Hệ thống Kỹ Năng Pro của bạn đã bị từ chối.<br><br>" .
                         "Lý do: " . $reasonText . "<br><br>" .
                         $nextSteps . "<br><br>" .
                         "Chúng tôi xin lỗi vì sự bất tiện này và mong có cơ hội phục vụ bạn trong các khóa học khác.<br><br>" .
                         "Trân trọng,<br>Ban quản trị Hệ thống Kỹ Năng Pro";
        
        $mail->AltBody = 'Thông báo về đăng ký khóa học ' . $courseTitle . '. Lý do: ' . strip_tags($reasonText) . ' ' . strip_tags($nextSteps);

        $mail->send();
        return true; 

    } catch (Exception $e) {
        error_log("Rejection email could not be sent to {$recipientEmail}. Mailer Error: {$mail->ErrorInfo}");
        return false; 
    }
}

header('Content-Type: application/json');
$response = ['success' => false, 'message' => 'Yêu cầu không hợp lệ.'];

// --- 2. Kiểm tra quyền và dữ liệu POST ---
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'staff'])) {
    http_response_code(403); // Forbidden
    $response['message'] = 'Bạn không có quyền thực hiện hành động này.';
    echo json_encode($response);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['registration_id']) || !isset($_POST['reason'])) {
     http_response_code(400); // Bad Request
     $response['message'] = 'Thiếu thông tin cần thiết.';
     echo json_encode($response);
     exit();
}

$registration_id = (int)$_POST['registration_id'];
$reason = trim($_POST['reason']);

if ($registration_id <= 0 || !in_array($reason, ['payment_not_received', 'course_cancelled', 'other'])) {
    http_response_code(400);
    $response['message'] = 'Lý do từ chối không hợp lệ.';
    echo json_encode($response);
    exit();
}


$studentEmail = '';
$studentName = '';
$courseTitle = '';

$stmt_info = $conn->prepare("
    SELECT u.Email, u.FullName, c.Title 
    FROM registration r 
    JOIN student s ON r.StudentID = s.StudentID 
    JOIN user u ON s.UserID = u.UserID 
    JOIN course c ON r.CourseID = c.CourseID
    WHERE r.RegistrationID = ? AND r.Status = 'registered' -- Chỉ từ chối cái đang chờ
");

if ($stmt_info) {
    $stmt_info->bind_param("i", $registration_id);
    $stmt_info->execute();
    $result_info = $stmt_info->get_result();
    if ($info = $result_info->fetch_assoc()) {
        $studentEmail = $info['Email'];
        $studentName = $info['FullName'];
        $courseTitle = $info['Title'];
    } else {
        $response['message'] = 'Không tìm thấy đăng ký hợp lệ để từ chối hoặc đã được xử lý.';
        echo json_encode($response);
        exit();
    }
    $stmt_info->close();
} else {
     http_response_code(500);
     $response['message'] = 'Lỗi hệ thống khi lấy thông tin đăng ký.';
     error_log("Prepare failed (get info for reject): (" . $conn->errno . ") " . $conn->error);
     echo json_encode($response);
     exit();
}

$new_status = 'cancelled';
$stmt_update = $conn->prepare("UPDATE registration SET Status = ? WHERE RegistrationID = ? AND Status = 'registered'");
if ($stmt_update) {
    $stmt_update->bind_param("si", $new_status, $registration_id);
    if ($stmt_update->execute()) {
        if ($stmt_update->affected_rows > 0) {
            // --- 5. Gửi Email Từ chối ---
            if (sendRejectionEmail($studentEmail, $studentName, $courseTitle, $reason)) {
                 $response['success'] = true;
                 $response['message'] = 'Đã từ chối đăng ký và gửi email thông báo thành công.';
            } else {
                 $response['success'] = true; // Vẫn thành công về mặt cập nhật DB
                 $response['message'] = 'Đã từ chối đăng ký nhưng có lỗi khi gửi email thông báo.';
            }
        } else {
             $response['message'] = 'Đăng ký này có thể đã được xử lý trước đó.';
             $response['success'] = true; 
        }
    } else {
        http_response_code(500);
        $response['message'] = 'Lỗi khi cập nhật trạng thái từ chối.';
        error_log("Execute failed (update reject status): (" . $stmt_update->errno . ") " . $stmt_update->error);
    }
    $stmt_update->close();
} else {
    http_response_code(500);
    $response['message'] = 'Lỗi hệ thống khi chuẩn bị cập nhật từ chối.';
    error_log("Prepare failed (update reject status): (" . $conn->errno . ") " . $conn->error);
}

$conn->close();
echo json_encode($response);
exit();
?>