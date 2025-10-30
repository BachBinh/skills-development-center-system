<?php
session_start();

$isLoggedIn = isset($_SESSION['user_id']); 
$userRole = $_SESSION['role'] ?? null;     
$isStudent = $isLoggedIn && ($userRole === 'student'); 

$userId = $_SESSION['user_id'] ?? null; 
$studentId = 0;                         
$studentInfo = null;                    
$fullName = $_SESSION['fullname'] ?? 'Khách'; 
$page_error = '';
$conn = new mysqli("localhost", "root", "", "thiendinhsystem");

$studentResult = $conn->query("SELECT StudentID FROM student WHERE UserID = '$userId'");
$studentRow = $studentResult->fetch_assoc();
$studentId = $studentRow['StudentID'] ?? 0;
$studentInfoResult = $conn->query("SELECT u.FullName, u.Email, u.Phone FROM student s JOIN user u ON s.UserID = u.UserID WHERE s.StudentID = '$studentId'");
$studentInfo = $studentInfoResult->fetch_assoc();

$registeredCourses = $conn->query("
    SELECT
        c.CourseID,
        c.Title,
        c.Description,
        c.Fee,
        c.StartDate,
        c.EndDate,
        c.MaxStudents,
        r.Status,
        u_instructor.FullName AS InstructorName, -- <<-- Lấy tên giảng viên
        (SELECT COUNT(*)
         FROM registration r2
         WHERE r2.CourseID = c.CourseID AND (r2.Status = 'registered' OR r2.Status = 'completed')) AS RegisteredCount
    FROM registration r
    JOIN course c ON r.CourseID = c.CourseID
    LEFT JOIN instructor i ON c.InstructorID = i.InstructorID -- <<-- JOIN instructor
    LEFT JOIN user u_instructor ON i.UserID = u_instructor.UserID -- <<-- JOIN user để lấy tên GV
    WHERE r.StudentID = '$studentId'
");


$suggestedCourses = $conn->query("
    SELECT
        c.CourseID,
        c.Title,
        c.Description,
        c.Fee,
        c.StartDate,
        c.EndDate,
        c.MaxStudents,
        u_instructor.FullName AS InstructorName, -- <<-- Lấy tên giảng viên
        (SELECT COUNT(*)
         FROM registration r
         WHERE r.CourseID = c.CourseID AND (r.Status = 'registered' OR r.Status = 'completed')) AS RegisteredCount
    FROM course c
    LEFT JOIN instructor i ON c.InstructorID = i.InstructorID -- <<-- JOIN instructor
    LEFT JOIN user u_instructor ON i.UserID = u_instructor.UserID -- <<-- JOIN user để lấy tên GV
    WHERE c.CourseID NOT IN (
        SELECT CourseID FROM registration WHERE StudentID = '$studentId'
    )
    AND c.StartDate >= CURDATE()
    ORDER BY c.StartDate ASC
    LIMIT 6
");

?>

<!DOCTYPE html>
<html lang="vi">
<head>
        <meta charset="utf-8">
        <title>Khóa học - Kỹ Năng Pro</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="keywords" content="Khóa học kỹ năng mềm">
        <meta name="description" content="Danh sách các khóa học kỹ năng mềm tại Kỹ Năng Pro">
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.10.0/css/all.min.css" rel="stylesheet">
        <link href="../css/style.css" rel="stylesheet">
        <link href="course.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    </head>
    <body>

<?php if (isset($_SESSION['register_success'])): ?>
    <div class="alert alert-success text-center"><?= $_SESSION['register_success']; unset($_SESSION['register_success']); ?></div>
<?php endif; ?>

<?php if (isset($_SESSION['register_error'])): ?>
    <div class="alert alert-danger text-center"><?= $_SESSION['register_error']; unset($_SESSION['register_error']); ?></div>
<?php endif; ?>

    <!-- Nav -->
    <?php include 'nav.php'; ?>

    <?php if ($isStudent && $studentId > 0): ?>
        <div class="container-fluid pt-5">
            <div class="container py-5">
                <div class="text-center mb-5">
                    <h5 class="text-primary text-uppercase mb-3" style="letter-spacing: 5px;">Khóa học của bạn</h5>
                    <h1>Những khóa học bạn đã đăng ký</h1>
                </div>
                <div class="row g-4" id="registeredCourses">
                    <?php if (!empty($registeredCourses)): ?>
                        <?php $imgIndexReg = 1; ?>
                        <?php foreach ($registeredCourses as $row): ?>
                            <div class="col-lg-4 col-md-6 mb-4">
                            <div class="rounded overflow-hidden mb-2">
                                <img class="img-fluid" src="../img/course-1.jpg" alt="<?= htmlspecialchars($row['Title']) ?>">
                                <div class="bg-secondary p-4">
                                <div class="d-flex justify-content-between mb-3">
                                    <small>
                                        <i class="fa fa-users text-primary me-2"></i>
                                        <?= (int)$row['RegisteredCount'] ?> / <?= (int)$row['MaxStudents'] ?> học viên
                                    </small>
                                    <small>
                                        <i class="fa fa-calendar text-primary me-2"></i>
                                        <?= date('d/m/Y', strtotime($row['StartDate'])) ?> - <?= date('d/m/Y', strtotime($row['EndDate'])) ?>
                                    </small>
                                </div>  
                                <div class="mb-2"> 
                                    <small> 
                                        <i class="fa fa-chalkboard-teacher text-primary me-2"></i>
                                        GV: <?= htmlspecialchars($row['InstructorName'] ?? 'Chưa gán') ?>
                                    </small>
                                </div>

                                    <h5 class="mb-2"><?= htmlspecialchars($row['Title']) ?></h5>
                                    <p><?= htmlspecialchars(substr($row['Description'], 0, 100)) ?>...</p>
                                    <div class="d-flex justify-content-between align-items-center border-top pt-3">
                                        <span class="badge bg-<?= $row['Status'] === 'completed' ? 'success' : ($row['Status'] === 'cancelled' ? 'danger' : 'warning text-dark') ?>">
                                            <?= ucfirst($row['Status']) ?>
                                        </span>
                                        <h5 class="m-0"><?= number_format($row['Fee'], 0, ',', '.') ?> VNĐ</h5>
                                    </div>
                                    <div class="text-center mt-3">
                                        <?php if ($row['Status'] === 'registered'): ?>
                                            <button class="btn btn-outline-danger cancel-course-btn" 
                                                    data-id="<?= $row['CourseID'] ?>" 
                                                    data-status="registered">Hủy đăng ký</button>
                                        <?php elseif ($row['Status'] === 'completed'): ?>
                                            <button class="btn btn-outline-secondary" 
                                                    onclick="alert('Vui lòng liên hệ nhân viên để hủy khóa học đã hoàn tất.')">
                                                Đã hoàn tất
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php $imgIndexReg++; endforeach; ?>
                    <?php else: ?>
                        <div class="col-12"> <p id="noRegistered" class="text-center text-muted mt-3"><i class="fas fa-folder-open me-2"></i>Bạn chưa đăng ký khóa học nào.</p> </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?> 

    <div class="container-fluid py-5 bg-light">
        <div class="container py-5">
            <div class="text-center mb-5">
                <h5 class="text-primary text-uppercase mb-3" style="letter-spacing: 5px;">Đề xuất</h5>
                <h1>Gợi ý thêm khóa học cho bạn</h1>
            </div>
            <div class="row" id="suggestedCourses">
            <?php while ($row = $suggestedCourses->fetch_assoc()): ?>
                    <div class="col-lg-4 col-md-6 mb-4">
                        <div class="rounded overflow-hidden mb-2">
                            <img class="img-fluid" src="../img/course-1.jpg" alt="<?= htmlspecialchars($row['Title']) ?>">
                            <div class="bg-secondary p-4">
                            <div class="d-flex justify-content-between mb-3">
                                <small>
                                    <i class="fa fa-users text-primary me-2"></i>
                                    <?= (int)$row['RegisteredCount'] ?> / <?= (int)$row['MaxStudents'] ?> học viên
                                </small>
                                <small>
                                    <i class="fa fa-calendar text-primary me-2"></i>
                                    <?= date('d/m/Y', strtotime($row['StartDate'])) ?> - <?= date('d/m/Y', strtotime($row['EndDate'])) ?>
                                </small>
                            </div>
                            <div class="mb-2">
                                <small>
                                    <i class="fa fa-chalkboard-teacher text-primary me-2"></i>
                                    GV: <?= htmlspecialchars($row['InstructorName'] ?? 'Chưa gán') ?>
                                </small>
                            </div>

                                <h5><?= htmlspecialchars($row['Title']) ?></h5>
                                <p><?= htmlspecialchars(substr($row['Description'], 0, 100)) ?>...</p>
                                <div class="d-flex justify-content-between mt-3">
                                    <span class="text-light fw-bold"><?= number_format($row['Fee'], 0, ',', '.') ?> VNĐ</span>
                                </div>
                                <div class="text-center mt-3">
                                    <button
                                        class="btn btn-primary open-register-modal"
                                        data-id="<?= $row['CourseID'] ?>"
                                        data-title="<?= htmlspecialchars($row['Title'], ENT_QUOTES) ?>"
                                        data-fee="<?= number_format($row['Fee'], 0, ',', '.') ?> VNĐ"
                                        <?php if (!$isStudent): // Kiểm tra nếu không phải là student (hoặc chưa đăng nhập) ?>
                                        data-require-login="true"
                                        <?php endif; ?>
                                    >
                                        Đăng ký khóa học
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
                <?php if ($suggestedCourses->num_rows === 0): ?>
                    <p class="text-center text-muted w-100" id="noSuggestions">Không còn khóa học gợi ý nào.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Modal Form -->
    <div id="registerModal" class="modal-overlay d-none">
        <div class="modal-content">
            <span id="closeModal" class="close">×</span>
            <h3 class="mb-3">Xác nhận đăng ký khóa học</h3>
            <form id="registerCourseForm">
                <input type="hidden" name="course_id" id="modalCourseId">
                <input type="hidden" name="course_fee_value" id="modalCourseFeeValue"> <!-- Thêm input ẩn để lưu giá trị số của học phí -->

                <!-- Các input thông tin học viên và khóa học giữ nguyên -->
                <div class="mb-3">
                    <label class="form-label">Họ và tên:</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($studentInfo['FullName'] ?? '') ?>" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label">Email:</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($studentInfo['Email'] ?? '') ?>" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label">Số điện thoại:</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($studentInfo['Phone'] ?? '') ?>" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label">Khóa học:</label>
                    <input type="text" class="form-control" id="modalCourseTitle" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label">Học phí:</label>
                    <input type="text" class="form-control" id="modalCourseFee" readonly>
                </div>

                <!-- ===== PHẦN THÊM MỚI: CHỌN PHƯƠNG THỨC THANH TOÁN ===== -->
                <hr class="my-3">
                <div class="mb-3">
                    <label class="form-label fw-bold">Chọn phương thức thanh toán:</label>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="payment_method" id="paymentBankTransfer" value="bank_transfer" checked>
                        <label class="form-check-label" for="paymentBankTransfer">
                            <i class="fas fa-university me-1"></i> Chuyển khoản ngân hàng
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="payment_method" id="paymentCash" value="cash">
                        <label class="form-check-label" for="paymentCash">
                            <i class="fas fa-money-bill-wave me-1"></i> Thanh toán tiền mặt tại trung tâm
                        </label>
                        <small class="d-block text-muted ps-4"><em>(Vui lòng đến trung tâm hoàn tất thanh toán và ghi danh chính thức trước ngày khai giảng ít nhất 7 ngày.)</em></small>
                    </div>
                </div>
                <!-- ====================================================== -->

                <button type="submit" class="btn btn-success w-100 mt-2">Xác nhận đăng ký</button>
            </form>
        </div>
    </div>
    <!-- Modal xác nhận hủy đăng ký -->
    <div id="confirmCancelModal" class="modal-overlay d-none">
        <div class="modal-content text-center">
            <h4 class="mb-3">Xác nhận hủy đăng ký</h4>
            <p>Bạn có chắc chắn muốn hủy đăng ký khóa học này?</p>
            <div class="d-flex justify-content-center gap-2 mt-4">
                <button id="cancelConfirmBtn" class="btn btn-danger px-4">Hủy đăng ký</button>
                <button id="cancelCloseBtn" class="btn btn-secondary px-4">Đóng</button>
            </div>
        </div>
    </div>

        <div id="paymentInfoModal" class="modal-overlay d-none">
        <div class="modal-content">
             <span id="closePaymentModal" class="close">×</span>
             <h3 class="mb-3 text-center"><i class="fas fa-credit-card text-success me-2"></i>Thông tin thanh toán</h3>
             <p class="text-center text-muted">Cảm ơn bạn đã đăng ký! Vui lòng hoàn tất học phí cho khóa học <strong id="paymentCourseName" class="text-primary"></strong> bằng cách chuyển khoản theo thông tin dưới đây:</p>
             <hr class="my-3">
             <div class="bank-info" style="border: 1px dashed #0dcaf0; padding: 15px; border-radius: 5px; background-color: #f0f9ff;">
                 <dl class="row mb-0">
                    <dt class="col-sm-5">Ngân hàng:</dt>
                    <dd class="col-sm-7 fw-bold">MB BANK</dd> 

                    <dt class="col-sm-5">Số tài khoản:</dt>
                    <dd class="col-sm-7">
                        <span id="bankAccountNumber" class="fw-bold text-primary">9704 2292 3868 7888</span> 
                        <button class="btn btn-outline-secondary btn-sm py-0 px-1 copy-btn ms-1" data-clipboard-target="#bankAccountNumber" title="Copy số tài khoản"><i class="far fa-copy fa-xs"></i></button>
                    </dd>

                    <dt class="col-sm-5">Chủ tài khoản:</dt>
                    <dd class="col-sm-7 fw-bold">TRAN MY VAN</dd> 

                    <dt class="col-sm-5">Số tiền:</dt>
                    <dd class="col-sm-7 text-danger fw-bold">
                        <span id="paymentAmount">0</span> VNĐ
                        <button class="btn btn-outline-secondary btn-sm py-0 px-1 copy-btn ms-1" data-clipboard-target="#paymentAmount" title="Copy số tiền"><i class="far fa-copy fa-xs"></i></button>
                    </dd>

                     <dt class="col-sm-5">Nội dung CK (*):</dt>
                     <dd class="col-sm-7">
                        <span id="paymentContent" class="fw-bold">[Tên Bạn] [SĐT] DK [Mã Khóa Học]</span> 
                        <button class="btn btn-outline-secondary btn-sm py-0 px-1 copy-btn ms-1" data-clipboard-target="#paymentContent" title="Copy nội dung"><i class="far fa-copy fa-xs"></i></button>
                        <small class="d-block text-muted">(Vui lòng ghi đúng nội dung để được xác nhận nhanh chóng)</small>
                     </dd>
                 </dl>
             </div>
             <div class="text-center mt-3">
                 <div class="qr-code" style="text-align: center;">
                     <img src="../img/qrcode.png" alt="QR Code Thanh toán" style="max-width: 180px; height: auto; border: 1px solid #ddd; padding: 5px; border-radius: 4px;">
                     <p class="text-muted small mt-1 mb-0">Quét mã QR VietQR để thanh toán</p>
                 </div>
             </div>
             <hr class="my-3">
             <p class="text-center small text-muted mb-3">Sau khi chuyển khoản thành công, chúng tôi sẽ xác nhận và gửi thông tin khóa học qua email của bạn trong thời gian sớm nhất.</p>
              <button type="button" id="completePaymentBtn" class="btn btn-primary w-100"><i class="fas fa-check-circle me-2"></i>Đã hiểu & Hoàn tất</button>
        </div>
    </div>



    <?php include 'footer.php'; ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/clipboard.js/2.0.11/clipboard.min.js"></script>
    <script>
        function checkEmptySections() {
        const registeredCoursesContainer = document.getElementById('registeredCourses'); // Lấy container
        const suggestedCoursesContainer = document.getElementById('suggestedCourses'); // Lấy container

        // --- Xử lý phần khóa học đã đăng ký ---
        if (registeredCoursesContainer) { // Chỉ xử lý nếu container tồn tại
            const registeredItems = registeredCoursesContainer.querySelectorAll('.col-lg-4'); // Tìm item bên trong
            const noRegisteredMsg = document.getElementById('noRegistered');
            if (registeredItems.length === 0) {
                if (!noRegisteredMsg) {
                    const msg = document.createElement('p');
                    msg.id = 'noRegistered';
                    msg.className = 'text-center text-muted w-100 mt-4';
                    msg.innerHTML = '<i class="fas fa-folder-open me-2"></i>Bạn chưa đăng ký khóa học nào.';
                    registeredCoursesContainer.appendChild(msg); // An toàn vì container tồn tại
                }
            } else if (noRegisteredMsg) {
                noRegisteredMsg.remove();
            }
        } // Kết thúc if (registeredCoursesContainer)

        // --- Xử lý phần khóa học gợi ý ---
        if (suggestedCoursesContainer) { // Kiểm tra container gợi ý (thường luôn tồn tại)
            const suggestedItems = suggestedCoursesContainer.querySelectorAll('.col-lg-4');
            const noSuggestionsMsg = document.getElementById('noSuggestions');
            if (suggestedItems.length === 0) {
                if (!noSuggestionsMsg) {
                    const msg = document.createElement('p');
                    msg.id = 'noSuggestions';
                    msg.className = 'text-center text-muted w-100 mt-4';
                    msg.innerHTML = '<i class="fas fa-check-circle me-2"></i>Không còn khóa học gợi ý nào.';
                    suggestedCoursesContainer.appendChild(msg); // An toàn
                }
            } else if (noSuggestionsMsg) {
                noSuggestionsMsg.remove();
            }
        } // Kết thúc if (suggestedCoursesContainer)
    }

    document.addEventListener('DOMContentLoaded', function () {
        const registerModalElement = document.getElementById('registerModal');
        const paymentInfoModalElement = document.getElementById('paymentInfoModal');
        const closeModalBtn = document.getElementById('closeModal');
        const closePaymentModalBtn = document.getElementById('closePaymentModal');
        const completePaymentBtn = document.getElementById('completePaymentBtn');

        // Hàm show/hide modal giữ nguyên
        function showModal(modalElement) {
            if (modalElement) {
                modalElement.classList.remove('d-none');
                document.body.style.overflow = 'hidden';
            }
        }
        function hideModal(modalElement) {
            if (modalElement) {
                modalElement.classList.add('d-none');
                document.body.style.overflow = '';
            }
        }

        document.querySelectorAll('.open-register-modal').forEach(button => {
            button.addEventListener('click', function () {
                // Kiểm tra xem có cần đăng nhập không
                const requiresLogin = this.dataset.requireLogin === 'true';

                if (requiresLogin) {
                    // *** Nếu cần đăng nhập -> CHUYỂN HƯỚNG NGAY LẬP TỨC ***
                    const courseId = this.dataset.id;
                    const currentPage = encodeURIComponent(window.location.pathname + window.location.search);
                    // *** THAY ĐỔI ĐƯỜNG DẪN Login.php NẾU CẦN ***
                    const loginUrl = `../home/Login.php?action=register&course_id=${courseId}&redirect_url=${currentPage}`;
                    window.location.href = loginUrl; // Chuyển hướng thẳng, không hỏi
                    // Dừng thực thi tiếp trong hàm này
                    return;
                } else {
                    // *** Nếu đã đăng nhập và là student -> Mở modal đăng ký như cũ ***
                    const feeText = this.dataset.fee;
                    const feeValue = feeText.replace(/[^0-9]/g, '');
                    document.getElementById('modalCourseId').value = this.dataset.id;
                    document.getElementById('modalCourseTitle').value = this.dataset.title;
                    document.getElementById('modalCourseFee').value = feeText;
                    document.getElementById('modalCourseFeeValue').value = feeValue;
                    const bankTransferRadio = document.getElementById('paymentBankTransfer');
                    if (bankTransferRadio) bankTransferRadio.checked = true;
                    showModal(registerModalElement);
                }
            });
        });

        // === KIỂM TRA VÀ MỞ MODAL TỰ ĐỘNG SAU KHI ĐĂNG NHẬP ===
        // ... (Phần code PHP echo JavaScript để mở modal tự động giữ nguyên) ...
        <?php
        if (isset($_SESSION['pending_action']) && $_SESSION['pending_action'] === 'register' && isset($_SESSION['pending_course_id'])) {
            // ... (Code PHP để lấy thông tin khóa học và tạo JS mở modal giữ nguyên) ...
             $pendingCourseId = (int)$_SESSION['pending_course_id'];
             $stmtPendingCourse = $conn->prepare("SELECT Title, Fee FROM course WHERE CourseID = ?");
              $pendingCourseTitle = 'N/A';
              $pendingCourseFee = 0;
              $pendingCourseFeeFormatted = '0 VNĐ';
             if ($stmtPendingCourse) {
                 $stmtPendingCourse->bind_param("i", $pendingCourseId);
                 $stmtPendingCourse->execute();
                 $resultPending = $stmtPendingCourse->get_result();
                 if ($pendingCourseData = $resultPending->fetch_assoc()) {
                      $pendingCourseTitle = htmlspecialchars($pendingCourseData['Title'], ENT_QUOTES);
                      $pendingCourseFee = (float)$pendingCourseData['Fee'];
                      $pendingCourseFeeFormatted = number_format($pendingCourseFee, 0, ',', '.') . ' VNĐ';
                 }
                 $stmtPendingCourse->close();
             }
             unset($_SESSION['pending_action']);
             unset($_SESSION['pending_course_id']);
             unset($_SESSION['pending_redirect_url']); // Nên unset cả redirect_url

             echo "
             document.addEventListener('DOMContentLoaded', function() {
                 const modalElement = document.getElementById('registerModal');
                 const courseIdInput = document.getElementById('modalCourseId');
                 const courseTitleInput = document.getElementById('modalCourseTitle');
                 const courseFeeInput = document.getElementById('modalCourseFee');
                 const courseFeeValueInput = document.getElementById('modalCourseFeeValue');
                 const bankRadio = document.getElementById('paymentBankTransfer');
                 if (modalElement && courseIdInput && courseTitleInput && courseFeeInput && courseFeeValueInput) {
                     courseIdInput.value = '{$pendingCourseId}';
                     courseTitleInput.value = '" . addslashes($pendingCourseTitle) . "'; // Dùng addslashes cho title
                     courseFeeInput.value = '{$pendingCourseFeeFormatted}';
                     courseFeeValueInput.value = '{$pendingCourseFee}';
                      if(bankRadio) bankRadio.checked = true;
                     showModal(modalElement);
                 } else { console.error('Không tìm thấy element của modal đăng ký.'); }
             });
             ";
        }
        ?>

        if (closeModalBtn) {
            closeModalBtn.addEventListener('click', () => hideModal(registerModalElement));
        }
        if (registerModalElement) {
            registerModalElement.addEventListener('click', function (e) {
                if (e.target === this) hideModal(this);
            });
        }

        // === Xử lý Modal Thanh toán ===
         if (closePaymentModalBtn) {
            closePaymentModalBtn.addEventListener('click', () => {
                hideModal(paymentInfoModalElement);
                Swal.fire({
                    title: 'Đang chờ xác nhận',
                    text: 'Chúng tôi sẽ thông báo khi thanh toán của bạn được xác nhận. Trang sẽ tải lại.',
                    icon: 'info',
                    timer: 2500, 
                    showConfirmButton: false
                }).then(() => {
                    location.reload();
                });
            });
        }
         if (completePaymentBtn) {
            completePaymentBtn.addEventListener('click', () => {
                hideModal(paymentInfoModalElement);
                 Swal.fire({
                    title: 'Đã hiểu',
                    text: 'Cảm ơn bạn! Chúng tôi sẽ xử lý và thông báo kết quả sớm. Trang sẽ tải lại.',
                    icon: 'success',
                    timer: 2500,
                    showConfirmButton: false
                }).then(() => {
                    location.reload();
                });
            });
        }
        if (paymentInfoModalElement) {
             paymentInfoModalElement.addEventListener('click', function (e) {
                if (e.target === this) {
                     hideModal(this);
                     location.reload(); // Có thể thêm thông báo trước khi reload nếu muốn
                }
            });
        }


                // === Xử lý Form Đăng ký AJAX ===
                const registerForm = document.getElementById('registerCourseForm');
        if (registerForm) {
            registerForm.addEventListener('submit', function (e) {
                e.preventDefault();
                const courseId = document.getElementById('modalCourseId').value;
                const courseTitle = document.getElementById('modalCourseTitle').value;
                // Lấy giá trị số từ input ẩn mới
                const feeValue = document.getElementById('modalCourseFeeValue').value;
                // Lấy phương thức thanh toán được chọn
                const paymentMethod = registerForm.querySelector('input[name="payment_method"]:checked').value;

                const submitButton = registerForm.querySelector('button[type="submit"]');
                submitButton.disabled = true;
                submitButton.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Đang xử lý...';

                // Tạo body cho fetch request
                const params = new URLSearchParams();
                params.append('course_id', courseId);
                params.append('payment_method', paymentMethod); 
                params.append('course_fee', feeValue); 

                fetch('process_register_course.php', { 
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: params.toString()
                })
                .then(response => {
                    // Kiểm tra lỗi HTTP trước
                    if (!response.ok) {
                         return response.text().then(text => {
                            if (!text) throw new Error(`Lỗi HTTP ${response.status}: ${response.statusText}`);
                            throw new Error(`Lỗi HTTP ${response.status}: ${text.substring(0,200)}...`);
                         });
                    }
                     // Đọc response body MỘT LẦN dưới dạng text
                     return response.text();
                })
                .then(text => {
                    // Thử parse text thành JSON
                    try {
                        const data = JSON.parse(text);
                        // Xử lý data JSON thành công
                        if (data.success) {
                            hideModal(registerModalElement); // Đóng modal đăng ký

                            if (paymentMethod === 'bank_transfer') {
                                // Hiển thị modal chuyển khoản
                                document.getElementById('paymentCourseName').textContent = courseTitle;
                                document.getElementById('paymentAmount').textContent = Number(feeValue).toLocaleString('vi-VN');
                                const studentNameJS = "<?= addslashes(htmlspecialchars($studentInfo['FullName'] ?? 'HocVien')) ?>";
                                const studentPhoneJS = "<?= addslashes(htmlspecialchars($studentInfo['Phone'] ?? '')) ?>";
                                const courseCode = courseTitle.split(' ').map(word => word[0]).join('').substring(0, 3).toUpperCase();
                                document.getElementById('paymentContent').textContent = `DK ${studentNameJS} ${studentPhoneJS} ${courseCode}${courseId}`;
                                showModal(paymentInfoModalElement);
                            } else {
                                // Hiển thị thông báo thanh toán tiền mặt
                                Swal.fire({
                                    title: 'Đăng ký tạm thời thành công!',
                                    html: `Bạn đã chọn thanh toán bằng tiền mặt cho khóa học <strong>${courseTitle}</strong>.<br><br>Vui lòng đến trung tâm để hoàn tất học phí và ghi danh chính thức <strong>trước ngày khai giảng ít nhất 7 ngày</strong>. Chúng tôi sẽ liên hệ với bạn sớm.<br><br>Trang sẽ được tải lại sau giây lát.`,
                                    icon: 'info',
                                    timer: 7000,
                                    showConfirmButton: true,
                                    confirmButtonText: 'Đã hiểu',
                                    timerProgressBar: true
                                }).then(() => {
                                    location.reload();
                                });
                            }
                        } else {
                             Swal.fire('Đăng ký thất bại', data.message || 'Đã xảy ra lỗi không xác định.', 'error');
                        }
                    } catch (e) {
                        // Parse JSON thất bại
                        console.error("Failed to parse JSON response:", text);
                        if (text.toLowerCase().includes('error') || text.toLowerCase().includes('warning') || text.startsWith('<br') || text.startsWith('<div')) {
                             Swal.fire('Lỗi Server', 'Server trả về lỗi không mong muốn. Chi tiết: ' + text.substring(0, 150) + '...', 'error');
                        } else {
                             Swal.fire('Lỗi Phản hồi', 'Phản hồi từ máy chủ không hợp lệ. Nội dung: ' + text.substring(0,100) + '...', 'error');
                        }
                    }
                })
                .catch(error => {
                    // Xử lý lỗi mạng hoặc lỗi đã throw
                    console.error('Fetch Register Error:', error);
                     Swal.fire('Lỗi', 'Không thể gửi yêu cầu đăng ký. Lỗi: ' + error.message, 'error');
                })
                .finally(() => {
                     // Khôi phục lại nút submit
                     submitButton.disabled = false;
                     submitButton.innerHTML = '<i class="fas fa-check-circle me-2"></i>Xác nhận đăng ký';
                });
            });
        }

         // === Cập nhật JS mở Modal Đăng ký để lưu giá trị số học phí ===
         document.querySelectorAll('.open-register-modal').forEach(button => {
            button.addEventListener('click', function () {
                debugger; // <--- Thêm dòng này
               const requiresLogin = this.dataset.requireLogin === 'true';
               console.log('Requires Login:', requiresLogin); // In ra console
                const feeText = this.dataset.fee;
                const feeValue = feeText.replace(/[^0-9]/g, ''); // Lấy số
                document.getElementById('modalCourseId').value = this.dataset.id;
                document.getElementById('modalCourseTitle').value = this.dataset.title;
                document.getElementById('modalCourseFee').value = feeText; // Hiển thị dạng text
                document.getElementById('modalCourseFeeValue').value = feeValue; // Lưu dạng số
                // Reset lựa chọn payment về mặc định (bank_transfer)
                const bankTransferRadio = document.getElementById('paymentBankTransfer');
                if (bankTransferRadio) bankTransferRadio.checked = true;
                showModal(registerModalElement);
            });
        });

        //Xử lý Hủy Đăng ký
        document.addEventListener('click', function (e) {
            const cancelBtn = e.target.closest('.cancel-course-btn');
            if (cancelBtn) {
                 const courseId = cancelBtn.dataset.id;
                 Swal.fire({
                     title: 'Bạn có chắc muốn hủy?', text: "Hủy đăng ký sẽ xóa bạn khỏi khóa học này.", icon: 'warning',
                     showCancelButton: true, confirmButtonColor: '#d33', cancelButtonColor: '#6c757d',
                     confirmButtonText: 'Đồng ý', cancelButtonText: 'Không'
                 }).then((result) => {
                    if (result.isConfirmed) {
                        cancelBtn.disabled = true;
                        cancelBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Hủy...';
                        fetch('cancel_registration.php', { 
                            method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'course_id=' + encodeURIComponent(courseId)
                        })
                        .then(res => res.json().catch(e => { console.error("Cancel response error:", e); return {success: false, message: "Lỗi phản hồi từ máy chủ."}; }))
                        .then(data => {
                            if (data.success) {
                                Swal.fire({ 
                                title: 'Đã hủy!', 
                                html: 'Khóa học đã được hủy đăng ký.<br><br><strong>Lưu ý:</strong> Nếu bạn đã thanh toán học phí, vui lòng liên hệ hotline <a href="tel:09xxxxxxxx" style="color:#0d6efd; font-weight:bold;"><strong>09xx xxx xxx</strong></a> (thay số) kèm minh chứng để được hỗ trợ hoàn tiền. Nếu chưa thanh toán, bạn có thể bỏ qua thông báo này.', 
                                icon: 'success', 
                                showConfirmButton: true,
                                confirmButtonText: 'Đã hiểu' // Text của nút xác nhận
                            }).then(() => location.reload());
                            } else {
                                Swal.fire('Lỗi', data.message || 'Không thể hủy đăng ký.', 'error');
                                cancelBtn.disabled = false;
                                cancelBtn.innerHTML = '<i class="fas fa-times-circle me-1"></i>Hủy đăng ký';
                            }
                        })
                         .catch(error => {
                             console.error('Fetch Cancel Error:', error);
                             Swal.fire('Lỗi', 'Không thể gửi yêu cầu hủy. Vui lòng thử lại.', 'error');
                              cancelBtn.disabled = false;
                             cancelBtn.innerHTML = '<i class="fas fa-times-circle me-1"></i>Hủy đăng ký';
                         });
                    }
                });
            }
        });

        // Clipboard.js
        if (typeof ClipboardJS !== 'undefined') {
             var clipboard = new ClipboardJS('.copy-btn');
             clipboard.on('success', function(e) { /* ... code feedback ... */ });
             clipboard.on('error', function(e) { /* ... code feedback lỗi ... */ });
        } else { console.warn("ClipboardJS chưa được tải."); }

        checkEmptySections();
    });
</script>

</body>
</html> 