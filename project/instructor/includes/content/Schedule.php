<?php
require_once(__DIR__ . '/../../../config/db_connection.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../home/Login.php");
    exit();
}

$instructorId = $conn->query("SELECT InstructorID FROM instructor WHERE UserID = " . $_SESSION['user_id'])->fetch_assoc()['InstructorID'];
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Lịch giảng dạy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" rel="stylesheet">
    
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.4/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.29.4/moment.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>

    <style>
        .fc-event {
            color: white !important;
            font-weight: bold;
            border-radius: 5px;
            padding: 5px;
            text-align: center;
            display: flex !important;
            justify-content: center !important;
            align-items: center !important;
            cursor: pointer !important;
        }
    </style>
</head>
<body>
    <div class="container mt-4">
        <h3>Lịch giảng dạy</h3>

        <!-- 📌 Thêm ghi chú -->
        <div class="alert alert-info">
            👉 **Click vào ca học để mở danh sách lớp**
        </div>

        <div id="calendar"></div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        console.log("Debug: Khởi động FullCalendar...");

        var calendarEl = document.getElementById('calendar');
        var calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            events: 'includes/content/fetch_schedule.php', // Gọi API từ Dashboard
            eventDidMount: function(info) {
                if (info.view.type === "dayGridMonth") {
                    info.el.style.backgroundColor = info.event.extendedProps.color || "#FF5722"; // Màu từ API
                    info.el.style.whiteSpace = "pre-line"; // Xuống dòng đúng cách
                    info.el.innerText = `${info.event.title}\n${info.event.extendedProps.room}\n${info.event.start.toLocaleTimeString()}`;
                }
            },
            eventClick: function(info) {
                let schedule_id = info.event.id; // Lấy ID từ dữ liệu lịch học
                window.location.href = `Dashboard.php?page=AttendanceDetail&schedule_id=${schedule_id}`;
            },
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,timeGridWeek,timeGridDay'
            },
            dayMaxEventRows: true // Hiển thị nhiều dữ liệu hơn trên chế độ tháng
        });

        console.log("Debug: Lịch đã khởi tạo thành công!");
        calendar.render();
    });
    </script>
</body>

</html>
