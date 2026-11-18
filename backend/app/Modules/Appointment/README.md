# Appointment

Module port AppointmentController, AppointmentServiceImpl và AppointmentPaymentReservationScheduler
của Java. Controller, service, repository, request, resource, command và test nằm trong thư mục này.
Model/enum/migration dùng chung thuộc Người 2; nghiệp vụ Payment của Người 3 chưa tích hợp đầy đủ.

## API và quyền

| Method | Path | Quyền | Thành công |
|---|---|---|---|
| POST | `/api/appointments` | Token hợp lệ | 201, AppointmentResponse |
| POST | `/api/appointments/walk-in` | RECEPTIONIST | 201, AppointmentResponse |
| GET | `/api/appointments` | Token hợp lệ | 200, mảng AppointmentResponse |
| GET | `/api/appointments/{id}` | Token hợp lệ | 200, AppointmentResponse |
| POST | `/api/appointments/{id}/check-in` | Token hợp lệ | 200, AppointmentResponse |
| POST | `/api/appointments/{id}/start` | Token hợp lệ | 200, AppointmentResponse |
| POST | `/api/appointments/{id}/cancel` | Token hợp lệ | 200, AppointmentResponse |
| POST | `/api/appointments/{id}/confirm-payment-manual` | RECEPTIONIST | 200, AppointmentResponse |
| POST | `/api/appointments/sepay/webhook` | Public, secret SePay được service kiểm tra | 200, map xác nhận |

ADMIN có quyền kế thừa RECEPTIONIST theo middleware dùng chung. Không thêm điều kiện bệnh nhân
phải sở hữu appointment hoặc bác sĩ phải sở hữu lịch khi Java không kiểm tra điều kiện đó.
Hai luồng thu tiền mặt còn tìm account của principal trong DB, ghi nhận account xác nhận vào transaction.

GET danh sách nhận `patientId`, `doctorId`, `doctorScheduleId`, `status`, kết hợp các điều kiện và
sắp xếp createdAt giảm. Đây là mảng, không phải SpringPage. Status là tên enum, phân biệt hoa thường.
SpringParameters giữ cách parse query/path của Spring; lỗi chuyển kiểu query/path có thể trả 500
với tiền tố `An unexpected error occurred: `, không tự đổi thành map validation 400.

## Request và response

CreateAppointmentRequest bắt buộc `patientId` (Long), `doctorScheduleId` (Long), initialSymptoms
không blank/tối đa 4000 ký tự, visitReason không blank/tối đa 500 ký tự. feeId là Long tùy chọn.
Ví dụ body với ID minh họa:

```json
{"patientId":1,"doctorScheduleId":2,"initialSymptoms":"Đau đầu","visitReason":"Khám tư vấn","feeId":null}
```

CreateWalkInAppointmentRequest có các field trên, thêm receivedAmount (BigDecimal, không null,
lớn hơn 0), receiptNumber tùy chọn/tối đa 100 ký tự, note tùy chọn/tối đa 1000 ký tự.
ConfirmManualPaymentRequest có receiptNumber bắt buộc, không blank/tối đa 100 ký tự và note
tùy chọn/tối đa 500 ký tự; không nhận receivedAmount để thay giá tiền phải thu.

Các DTO này dùng BaseRequest với validation theo `@Valid` Java; message nằm trong các class
[Requests](Requests). Field ngoài DTO bị bỏ qua. Sai kiểu, enum, ngày hoặc JSON hỏng trả 400 text
`Invalid request body. Please check JSON syntax and date format yyyy-MM-dd`; lỗi constraint DTO
trả 400 JSON map field/message. Không thêm field version vào request.

[AppointmentResponse](Resources/AppointmentResponse.php) trả đủ 30 field, không bọc ApiResponse:

```text
appointmentId patientId patientName doctorScheduleId doctorId doctorName
feeId feeName feePrice scheduleDate shift appointmentCode queueNum status
initialSymptoms visitReason createdAt updatedAt paidAt paymentExpiresAt
expectedPaymentAmount receivedPaymentAmount paymentStatus sepayTransactionId
paymentReferenceCode paymentContent checkedInAt cancelledAt roomCode appointmentTime
```

Tên/giá phí lấy từ snapshot của appointment. Số tiền JSON là số, enum là tên, field null vẫn giữ.
Ngày trả `yyyy-MM-dd`, datetime `yyyy-MM-ddTHH:mm:ss`, không timezone hoặc microseconds.
appointmentTime ghép giờ bắt đầu/kết thúc của ShiftType, ví dụ `07:00 - 11:00`.
version_number là chi tiết lưu trữ nội bộ, không xuất ra DTO này.

## Tạo online và walk-in

[AppointmentService](Services/AppointmentService.php) khóa patient rồi DoctorSchedule trước khi
kiểm tra/cấp chỗ. Patient phải tồn tại và active; schedule phải tồn tại, ngày không ở quá khứ,
còn sức chứa. Patient không được có appointment ở một trong PENDING, CONFIRMED, CHECKED_IN,
IN_PROGRESS. Khi feeId trống, tìm phí active đầu tiên theo specialty của bác sĩ; bác sĩ cần có specialty.
Nếu truyền feeId trực tiếp, tìm theo ID rồi kiểm tra phí active, giữ nguyên việc không so specialty.

Online tăng currentBookingCount và lastQueueNumber, cấp queueNum, tạo mã `APT-` với 8 ký tự đầu từ
UUID đã bỏ dấu gạch, thử tối đa 10 lần để tránh trùng. Appointment có status PENDING,
paymentExpiresAt bằng now cộng 10 phút; PaymentRecord có requestCode bằng mã appointment,
receivedAmount bằng 0, paymentStatus UNPAID. Tên và giá phí được snapshot tại lúc đặt.

Walk-in dùng cùng kiểm tra patient/schedule/phí và chỉ chấp nhận receivedAmount bằng đúng giá phí.
Appointment đi thẳng vào CHECKED_IN, paidAt và checkedInAt được gán cùng thời điểm, không có hạn
giữ chỗ thanh toán. PaymentRecord PAID và PaymentTransaction gateway CASH được tạo cùng transaction;
transferType của walk-in là `cash`. Receipt/note và account thu tiền được lưu như Java.

## Thanh toán SePay và tiền mặt

SepayWebhookRequest parse id, transferAmount, accumulated như Long; các field còn lại là String:
gateway, transactionDate, accountNumber, code, content, transferType, subAccount, referenceCode,
description. Webhook không có `@Valid`; không thêm constraints DTO. Sau khi parse, service kiểm tra:

1. Secret đã cấu hình và khớp chính xác. X-Secret-Key không blank được ưu tiên, không tự trim.
   Nếu dùng Authorization, chỉ prefix chính xác `Apikey ` được bỏ rồi trim; `Bearer` được giữ nguyên.
2. transferType bằng `in` không phân biệt hoa thường, không tự trim. Nếu cấu hình account-number
   không blank thì accountNumber phải khớp; transferAmount phải lớn hơn 0.
3. Với APT/DK: tìm appointment, lấy các khóa, kiểm tra PENDING, hạn giữ chỗ, số tiền khớp chính xác
   PaymentRecord.totalPrice và schedule chưa hết ngày. Hạn bằng now cũng bị xem là đã hết.

Controller tìm loại mã theo content, rồi description, rồi code; trong từng field ưu tiên MR, PR,
APT, DK. Service appointment trích `APT-[A-Z0-9]{8}` hoặc `DK` theo sau bởi ID appointment; không
thay đổi thứ tự tìm mã này. Nhánh APT/DK thành công trả đúng ba field:

```json
{"success":true,"appointmentId":1,"appointmentCode":"APT-1234ABCD"}
```

Appointment được CONFIRMED và ghi paidAt/SePay ID/reference/content; PaymentRecord cập nhật số
tiền/status và PaymentTransaction được chèn atomically. Gateway mặc định là SEPAY, còn gateway
không blank từ payload được giữ. transactionDate được thử parse như OffsetDateTime của Java,
giữ phần ngày/giờ local; thiếu hoặc parse không được thì dùng now khi tạo transaction.

Thanh toán thủ công cũng cần appointment PENDING, schedule chưa hết ngày và account thu ngân.
Service thu đúng tổng tiền trong PaymentRecord, gán CONFIRMED/PAID, tạo transaction CASH với
transferType `in`, receiptNumber từ request và nội dung `Manual payment confirmed at counter`.
Lỗi integrity khi xác nhận SePay/thủ công được chuyển thành 400 `Payment confirmation conflict detected`.

Các QUIRK-NEW được giữ trong service/helper:

- Tra transaction ID SePay toàn cục có thể trả appointment đã ghi nhận trước khi so code/số tiền
  mới. Duplicate cùng ID được xử lý idempotent; không có ID thì lần gọi lại có thể bị từ chối vì đã CONFIRMED.
- Thanh toán thủ công không kiểm tra paymentExpiresAt; vẫn thu được khi PENDING dù đã qua hạn,
  nếu scheduler chưa chuyển sang PAYMENT_EXPIRED. Không tự áp dụng quy tắc SePay cho nhánh này.
- feeId tường minh không bị kiểm tra specialty của bác sĩ như nhánh tự tìm phí.
- Raw JSON nội bộ chỉ escape dấu nháy và backslash; control characters vẫn được giữ nguyên.
- Prefix Authorization chỉ bỏ `Apikey ` đúng chữ hoa/thường như mô tả trên.

### Hai nhánh còn chờ Payment

Controller gọi `App\Modules\Payment\Services\PaymentRecordService` cho MR/PR qua hai method:
`confirmMedicalRecordPaymentFromSepayWebhook` và `confirmPrescriptionPaymentFromSepayWebhook`.
Service PHP của Người 3 chưa có trong checkout/main đã đối chiếu. Hai nhánh này chưa được kiểm chứng
tích hợp; test dispatch chỉ dùng stub. Không fallback MR/PR sang appointment và không tự viết nghiệp vụ
Payment ở module này. Cần ghép service và đối chiếu DTO/status thực tế khi dependency xuất hiện.

## Check-in, start, cancel và hết hạn

| Thao tác | Trạng thái đầu | Trạng thái sau / hệ quả |
|---|---|---|
| checkIn | CONFIRMED | CHECKED_IN, gán checkedInAt |
| start | CHECKED_IN | IN_PROGRESS |
| cancel | PENDING | CANCELLED, gán cancelledAt, release slot |
| expirePendingPaymentReservations | PENDING và paymentExpiresAt không null, nhỏ hơn hoặc bằng now | PAYMENT_EXPIRED, gán cancelledAt, release slot |

Cancel từ CONFIRMED/CHECKED_IN/IN_PROGRESS/COMPLETED bị chặn với `Paid appointments cannot be cancelled`;
đã CANCELLED trả `Appointment is already cancelled`; trạng thái còn lại không PENDING trả
`Only pending appointments can be cancelled`. Module không có endpoint tự chuyển sang COMPLETED.

Release chỉ giảm currentBookingCount nếu có queueNum và count đang lớn hơn 0; lastQueueNumber
không giảm, nên số thứ tự có thể có khoảng trống. Cancel/expire không tự tạo hoàn tiền hoặc giao dịch thu.
Lỗi check-in/start sai trạng thái lần lượt là `Only confirmed appointments can be checked in` và
`Only checked-in appointments can be started`.

## Transaction, khóa và scheduler

Mọi method ghi của service dùng DB::transaction theo Java. Booking khóa patient rồi schedule;
check-in/start khóa appointment; cancel/expire khóa appointment rồi schedule. Confirm payment
khóa appointment, schedule và PaymentRecord, refresh appointment rồi kiểm tra lại trạng thái.
Query PESSIMISTIC_WRITE được port thành lockForUpdate trong repository của module.

Appointment và DoctorSchedule update qua OptimisticLock có điều kiện version, với lifecycle
callback hiện có. Unique SePay ID và các ràng buộc DB hỗ trợ chống ghi trùng; không dùng trigger,
procedure hoặc function Oracle/MySQL để thay logic PHP. Không viết thêm nghiệp vụ MedicalRecord/Payment.

Command [AppointmentPaymentReservationScheduler](Console/AppointmentPaymentReservationScheduler.php)
gọi expirePendingPaymentReservations. `routes/console.php` đăng ký mỗi phút với withoutOverlapping;
cache store phải hợp lệ và được chia sẻ giữa các release trên cùng VPS. Chạy trong `backend/`:

```sh
php artisan schedule:list
php artisan appointments:expire-payment-reservations
```

Đây là cơ chế scheduler Laravel theo cấu hình đã chọn; không dùng biến delay Java để tự đổi lịch
everyMinute. now/today lấy timezone của config app, hiện là UTC; chưa xác minh timezone JVM trên server Java.

## Kiểm chứng và nguồn đối chiếu

Trong `backend/`, trên database MySQL riêng `healthcare_test`:

```sh
php artisan test --filter='Appointment(Test|ConcurrencyTest|PaymentConcurrencyTest)'
```

AppointmentTest kiểm tra HTTP/DTO, lỗi, transaction rollback và scheduler; AppointmentConcurrencyTest
và AppointmentPaymentConcurrencyTest dùng các process riêng để kiểm tra khóa DB, replay và hai thứ
tự payment/expiry. Runner HTTP gửi request tới server thật, quan sát SQL lock barrier, chỉ nhận
loopback/healthcare_test và kiểm tra fixture trên từng origin trước khi gửi request ghi.
Xem [cách chạy HTTP và cấu hình CI](../../../../README.md) cho Nginx/PHP-FPM hoặc hai server độc lập.
Không coi test này là kết quả Newman hoặc so sánh với Java server; khung contract test vẫn chờ Người 3.

Nguồn Java dưới `backend-java/src/main/java/com/healthcare/backend/`: AppointmentController,
AppointmentService/AppointmentServiceImpl, AppointmentPaymentReservationScheduler, request/response
DTO tương ứng, AppointmentMapper, AppointmentRepository, PatientRepository, DoctorScheduleRepository,
ConsultationFeeRepository, PaymentRecordRepository, PaymentTransactionRepository, Account,
Appointment, DoctorSchedule, PaymentRecord, PaymentTransaction và enum liên quan. Đối chiếu thêm
BUSINESS_RULES mục 7, TRANSACTION và CONCURRENCY_HANDLING cho booking/payment/expiry; code Java
là nguồn quyết định khi tài liệu cũ chưa cập nhật hoặc khác hành vi.
