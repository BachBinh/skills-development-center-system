<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once(__DIR__ . '/../../../config/db_connection.php'); 

if (!isset($_SESSION['user_id'])) {
    header("HTTP/1.1 401 Unauthorized"); 
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized access. Please login.']);
    exit(); 
}


$courseID = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;


if ($courseID <= 0) {
    header("HTTP/1.1 400 Bad Request");
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid or missing Course ID.']);
    exit();
}

$students = []; 

$sql = "
    SELECT 
        u.FullName, 
        u.Email, 
        u.Phone, 
        r.RegisteredAt -- Lấy ngày gốc từ DB
    FROM registration r
    JOIN student s ON r.StudentID = s.StudentID
    JOIN user u ON s.UserID = u.UserID
    WHERE r.CourseID = ? AND r.Status = 'completed' 
    ORDER BY r.RegisteredAt DESC -- Sắp xếp theo ngày đăng ký mới nhất
";

$stmt = $conn->prepare($sql);

if ($stmt) {
    $stmt->bind_param("i", $courseID); 
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
             $row['RegisteredAt'] = !empty($row['RegisteredAt']) ? date('d/m/Y H:i', strtotime($row['RegisteredAt'])) : null;
            $students[] = $row;
        }
    } else {
        error_log("Error getting result in get_course_students.php: " . $stmt->error);
        header("HTTP/1.1 500 Internal Server Error");
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Failed to retrieve student data.']);
        $stmt->close(); 
        $conn->close();
        exit();
    }
    $stmt->close();

} else {
    error_log("SQL Prepare Error in get_course_students.php: " . $conn->error); 
    header("HTTP/1.1 500 Internal Server Error"); 
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database query preparation failed.']);
    $conn->close(); 
    exit();
}

$conn->close();
header('Content-Type: application/json; charset=utf-8'); 
echo json_encode($students);
exit(); 

?>