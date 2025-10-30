<?php
require_once(__DIR__ . '/../../../config/db_connection.php');

header('Content-Type: application/json'); // Đảm bảo dữ liệu trả về là JSON

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode([]);
    exit();
}

$instructorId = $conn->query("SELECT InstructorID FROM instructor WHERE UserID = " . $_SESSION['user_id'])->fetch_assoc()['InstructorID'];

$colors = ["#FF5722", "#4CAF50", "#2196F3", "#9C27B0", "#E91E63"];
$sql = "SELECT s.ScheduleID, c.Title, s.Date, s.StartTime, s.EndTime, s.Room FROM schedule s
        JOIN course c ON s.CourseID = c.CourseID
        WHERE s.InstructorID = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $instructorId);
$stmt->execute();
$result = $stmt->get_result();

$events = [];
while ($row = $result->fetch_assoc()) {
    $randomColor = $colors[array_rand($colors)];

    $events[] = [
        'id'    => $row['ScheduleID'],
        'title' => $row['Title'],
        'start' => $row['Date'] . 'T' . $row['StartTime'],
        'end'   => $row['Date'] . 'T' . $row['EndTime'],
        'room'  => $row['Room'],
        'color' => $randomColor
    ];
}

echo json_encode($events);
exit();
