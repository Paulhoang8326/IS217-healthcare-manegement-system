# Scheduling

Module port `DoctorScheduleController` và `DoctorScheduleServiceImpl` của Java. Code Laravel nằm
trong thư mục này; model, enum và migration dùng chung do Người 2 phụ trách.

## API và quyền

Tất cả route cần JWT. Các path dưới đây đã bao gồm tiền tố `/api` do ModuleServiceProvider nạp.
ADMIN được kế thừa các role theo middleware dùng chung.

| Method | Path | Role | Kết quả |
|---|---|---|---|
| POST | `/api/doctor-schedules` | ADMIN, RECEPTIONIST | 201, DoctorScheduleResponse |
| POST | `/api/doctor-schedules/import` | ADMIN, RECEPTIONIST | 201, DoctorScheduleImportResponse nếu import hoàn tất |
| GET | `/api/doctor-schedules` | ADMIN, RECEPTIONIST, DOCTOR, PATIENT | 200, mảng DoctorScheduleResponse |
| GET | `/api/doctor-schedules/{id}` | ADMIN, RECEPTIONIST, DOCTOR, PATIENT | 200, DoctorScheduleResponse |
| PUT | `/api/doctor-schedules/{id}` | ADMIN, RECEPTIONIST | 200, DoctorScheduleResponse |
| DELETE | `/api/doctor-schedules/{id}` | ADMIN, RECEPTIONIST | 400 nếu tồn tại, 404 nếu không tồn tại |

DELETE có nhánh `noContent()` trong controller nhưng service luôn từ chối xóa lịch tồn tại với
message `Không hỗ trợ xóa lịch làm việc bác sĩ`. Không xem đây là API xóa thành công trả 204.

## Request và danh sách

Create/Update đều dùng JSON với các field:

| Field | Kiểu Java | Quy tắc |
|---|---|---|
| doctorId | Long | Không null |
| roomId | Long | Không null |
| scheduleDate | LocalDate | Không null, hôm nay hoặc tương lai |
| shift | ShiftType | Không null; MORNING hoặc AFTERNOON |
| maxCapacity | Integer | Không null, lớn hơn 0, tối đa 99999 |
| note | String | Có thể null; tối đa 500 ký tự |

Ví dụ body, các ID chỉ minh họa:

```json
{"doctorId":1,"roomId":2,"scheduleDate":"2030-12-20","shift":"MORNING","maxCapacity":20,"note":null}
```

Thông báo validation nằm trong [CreateDoctorScheduleRequest](Requests/CreateDoctorScheduleRequest.php);
UpdateDoctorScheduleRequest kế thừa cùng quy tắc. Field ngoài DTO bị bỏ qua. Thiếu note khi PUT
được map thành null. Request không có field version; version được repository quản lý phía server.

GET danh sách nhận `date`, `startDate`, `endDate`, `doctorId`, `roomId`; các điều kiện được kết hợp,
hai mốc start/end bao gồm cả ngày biên. Kết quả là mảng, không phân trang. Service sắp xếp ngày tăng,
rồi thứ tự enum MORNING trước AFTERNOON, rồi doctorId tăng, dù query repository ban đầu dùng ngày giảm.
`SpringParameters` parse query/path theo Spring, kể cả tham số lặp; không dùng cast số của Laravel.

## Response và lỗi

[DoctorScheduleResponse](Resources/DoctorScheduleResponse.php) trả đủ 19 field:

```text
doctorScheduleId doctorId doctorName specialtyId specialtyName
roomId roomCode branchId branchName scheduleDate shift shiftStartTime shiftEndTime
maxCapacity currentBookingCount lastQueueNumber note createdAt updatedAt
```

Body không bọc ApiResponse. Field null vẫn có mặt. JavaDate trả ngày `yyyy-MM-dd`, datetime
`yyyy-MM-ddTHH:mm:ss`, không timezone/microseconds. Giờ ca: MORNING `07:00`–`11:00`,
AFTERNOON `13:00`–`17:00`. Cột `version_number` không được thêm vào response.

Validation DTO trả 400 JSON map field/message. JSON, enum hoặc kiểu body sai trả 400 text
`Invalid request body. Please check JSON syntax and date format yyyy-MM-dd`.
Query/path chuyển kiểu thất bại giữ hành vi Java: 500 text có tiền tố `An unexpected error occurred: `.
Không tìm thấy lịch, bác sĩ hoặc phòng trả 404 text theo exception. Trùng bác sĩ/phòng cùng ngày và
ca trả 409 với `Bác sĩ đã có lịch ở ca này trong ngày đã chọn` hoặc
`Phòng đã được phân công cho bác sĩ khác ở ca này trong ngày đã chọn`.
Optimistic conflict trả 409 `Data was changed by another transaction, please try again`.

## Import Excel

Gửi multipart field tên `file`. PhpSpreadsheet đọc sheet đầu tiên, bỏ dòng tiêu đề; các cột là:

| Cột | Nội dung |
|---|---|
| A | Tên đầy đủ bác sĩ active; so sánh trim và không phân biệt hoa thường, lấy kết quả đầu tiên |
| B | Room code, so sánh theo query chuẩn hóa của RoomRepository |
| C | Ô ngày Excel hoặc chuỗi `yyyy-MM-dd` / `dd/MM/yyyy` |
| D | Ca trực, được chuyển uppercase và trim |

Dòng có cả tên bác sĩ và room code rỗng được bỏ qua. Lỗi nghiệp vụ giữ số dòng Excel trong message.
File rỗng trả 400 `File tải lên trống`; thiếu multipart part trả 500 text
`An unexpected error occurred: Required part 'file' is not present.` như handler Java.
Response thành công có dạng `{"createdCount":0,"schedules":[]}` với file chỉ chứa tiêu đề.

Các QUIRK-NEW đang được giữ trong [DoctorScheduleService](Services/DoctorScheduleService.php):

- Import gán maxCapacity bằng 0 trong khi CHECK MySQL yêu cầu `max_capacity > 0`. Dòng hợp lệ tới
  bước lưu vẫn có thể lỗi; không mô tả import là luồng đã tạo được lịch bình thường. Các ghi nhận
  trong transaction bị rollback; lỗi đọc/lưu không thuộc BusinessException được bọc tiền tố
  `Lỗi khi đọc file Excel: `, phần chi tiết phụ thuộc driver/runtime.
- Formatter `dd/MM/yyyy` của Java dùng SMART resolution: `31/02/2028` thành `2028-02-29`.
- Message ca không hợp lệ vẫn liệt kê `MORNING, AFTERNOON, EVENING, NIGHT`, nhưng enum Java/PHP
  chỉ định nghĩa MORNING và AFTERNOON. EVENING/NIGHT vẫn bị từ chối, không thêm enum cho khớp message.
- Create/update thử doctorId như ID bác sĩ, rồi như account ID. Service có thể bật lại cả doctor
  và account đang inactive trong cùng transaction. Kiểm tra trùng lịch trước khi lưu giữ ID đầu vào.
- DELETE luôn bị từ chối như bảng API trên.

## Transaction và MySQL

Create, update, delete và toàn bộ import dùng DB::transaction theo method Java. Unique constraint
cho `(doctor_id, schedule_date, shift)` và `(room_id, schedule_date, shift)` là lớp bảo vệ khi hai
request cùng vượt qua kiểm tra trùng. `saveWithDuplicateHandling` nhận diện đúng tên constraint.

Update không reset currentBookingCount hoặc lastQueueNumber và từ chối maxCapacity thấp hơn số
đang đặt với message `Sức chứa tối đa không được nhỏ hơn số lượng đã đặt hiện tại`.
[DoctorScheduleRepository](Repositories/DoctorScheduleRepository.php) chạy lifecycle callback hiện có
và dùng OptimisticLock để UPDATE có điều kiện version, không ghi đè snapshot đã cũ.
Khóa cấp/release slot nằm trong module Appointment; xem [Appointment](../Appointment/README.md).
Không dùng trigger, procedure hoặc function; giữ schema/model/enum dùng chung hiện có.

## Kiểm chứng và nguồn đối chiếu

Trong `backend/`, chạy `php artisan test --filter=DoctorScheduleTest` trên MySQL `healthcare_test`
riêng. [DoctorScheduleTest](Tests/DoctorScheduleTest.php) kiểm tra route/quyền, DTO, validation,
duplicate, stale version, import rollback và các QUIRK nêu trên. Test PHP không thay thế so sánh
với Java server; collection Newman chưa có trong checkout này.

Nguồn Java dưới `backend-java/src/main/java/com/healthcare/backend/`: DoctorScheduleController,
DoctorScheduleService/DoctorScheduleServiceImpl, CreateDoctorScheduleRequest, UpdateDoctorScheduleRequest,
DoctorScheduleResponse, DoctorScheduleImportResponse, DoctorScheduleMapper, DoctorScheduleRepository,
DoctorRepository, RoomRepository, DoctorSchedule và ShiftType. Nội dung nghiệp vụ và concurrency
được đối chiếu thêm với `backend-java/docs/BUSINESS_RULES.md` mục 6 và
`backend-java/docs/CONCURRENCY_HANDLING.md` mục 17–19; khi docs cũ lệch thì theo code.
