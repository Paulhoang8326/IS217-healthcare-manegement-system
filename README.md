# IS217-healthcare-manegement-system

## Cấu trúc

- `backend/`: Laravel API, module trong `app/Modules/`, hạ tầng trong `app/Shared/`.
- `frontend/`: sẽ bổ sung ở giai đoạn frontend.
- `contract-tests/`: sẽ bổ sung khung Newman riêng.

## Chạy backend

Cần PHP 8.4, Composer 2 và Docker Compose. Bật các extension mbstring, intl, curl,
xml, dom, gd, zip, fileinfo, pdo_mysql.

Trong `backend/`:

```sh
composer install
cp .env.example .env
```

Điền `.env` trên máy: `APP_NAME=Healthcare`, `APP_ENV=local`, `APP_URL=http://localhost:8000`,
`APP_LOCALE=en`, `APP_FALLBACK_LOCALE=en`, `LOG_CHANNEL=stack`, `LOG_STACK=single`,
`DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_DATABASE=healthcare`,
`DB_USERNAME=healthcare`, cùng `DB_PASSWORD` và `MYSQL_ROOT_PASSWORD` tự chọn.
Đặt `CACHE_STORE=file`, `SESSION_DRIVER=file`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=log`.
Các biến JWT, SePay, Google và mail chỉ điền bằng cấu hình của môi trường mình.
Không đưa secret vào Git. `.env.example` chỉ liệt kê tên biến.

```sh
php artisan key:generate
cd ..
docker compose --env-file backend/.env up -d --build
```

`docker-compose.yml` nằm ở gốc repo và lấy biến từ `backend/.env`, nên mọi lệnh `docker compose`
chạy ở gốc repo đều cần `--env-file backend/.env`. Compose chạy cả MySQL và server Laravel
(service `backend`, cổng 8000). Mã nguồn `backend/` được mount vào container nên sửa code có hiệu lực
ngay; lần đầu container tự chạy `composer install`. Xem log bằng
`docker compose --env-file backend/.env logs -f backend`. Muốn chạy server trên máy thay vì container thì
chạy `docker compose --env-file backend/.env up -d mysql` rồi `php artisan serve --port=8000` trong `backend/`.

Schema, model, enum và seeder nghiệp vụ đã có. Sau khi MySQL sẵn sàng, chạy
`php artisan migrate --seed` trong `backend/` (hoặc qua `docker compose --env-file backend/.env exec backend`).
`migrate:fresh --seed` chỉ dùng khi chủ động muốn xóa và tạo lại dữ liệu.

Kiểm tra server bằng `GET http://localhost:8000/up`. `GET /api/health` trả
`{"status":"ok"}` khi có access token hợp lệ; thiếu token trả 401 như các API được bảo vệ khác.

### Database test

Test chạy trên MySQL, database `healthcare_test`, dùng chung `DB_HOST`, `DB_PORT`,
`DB_USERNAME`, `DB_PASSWORD` trong `.env`. `phpunit.xml` ép `DB_CONNECTION` và `DB_DATABASE`
nên test không bao giờ chạm vào database `healthcare` của bản chạy thật.
Các test integration có thể tạo lại và xóa bảng trong `healthcare_test`; không lưu dữ liệu cần giữ tại đó
và không chạy nhiều lượt test đồng thời trên cùng database test.

`docker compose up` tự tạo `healthcare_test` và cấp quyền cho `DB_USERNAME` bằng script
`docker/mysql-init/01-create-test-database.sh` (ở gốc repo). Script này chỉ chạy khi volume `mysql_data`
còn mới. Máy đã có volume thì tạo thủ công, chạy ở gốc repo:

```sh
docker compose --env-file backend/.env exec mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -e "CREATE DATABASE IF NOT EXISTS healthcare_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON healthcare_test.* TO \"$MYSQL_USER\"@\"%\"; FLUSH PRIVILEGES;"'
```

Kết nối `mysql` luôn đặt `READ COMMITTED` (`isolation_level` trong `config/database.php`),
không phụ thuộc cấu hình server.

## Kiểm tra

```sh
vendor/bin/pint --test
vendor/bin/phpstan analyse
php artisan test
```

PowerShell có thể dùng `php vendor/bin/pint --test` và `php vendor/bin/phpstan analyse`.
CI chỉ chạy khi `backend/**` thay đổi. Chạy riêng hạ tầng bằng
`php artisan test --testsuite=Shared`, các module bằng `php artisan test --testsuite=Modules`.
Test concurrency Appointment chạy các tiến trình PHP riêng trên MySQL; không thay bằng SQLite.

## Phần Người 1 đã triển khai

- Hạ tầng: nạp module, JWT, principal, role middleware, exception renderer, BaseRequest,
  SpringPage, SpringParameters, JavaDate, ApiResponse, OptimisticLock và SePay.
- Scheduling: 6 endpoint `/api/doctor-schedules`, gồm import Excel qua PhpSpreadsheet.
- Appointment: 9 endpoint `/api/appointments`, gồm đặt lịch, walk-in, chuyển trạng thái,
  thanh toán thủ công và webhook SePay cho mã APT/DK.

Các helper nằm trong `backend/app/Shared/`; code và test module nằm trong
`backend/app/Modules/Scheduling/` và `backend/app/Modules/Appointment/`.

Webhook Appointment còn hai nhánh MR/PR cần service
`App\Modules\Payment\Services\PaymentRecordService` của Người 3. Service này chưa có;
test điều hướng dùng stub không phải kết quả tích hợp Payment thực tế.
Khung Newman và Java server chưa được kiểm chứng trong checkout này.

### Scheduler reservation thanh toán

Command `appointments:expire-payment-reservations` được đăng ký mỗi phút với
`withoutOverlapping()`. Cấu hình cache store hợp lệ (ví dụ `CACHE_STORE=file` cho local), rồi chạy
trong `backend/`:

```sh
php artisan schedule:list
php artisan schedule:work
```

Muốn quét một lần, chạy `php artisan appointments:expire-payment-reservations`.
`schedule:work` phải tiếp tục chạy để lịch tự động được thực thi trên máy local.

## Commit

Dạng `<type>: <mô tả>`, type là `feat`, `fix`, `refactor`, `docs`, `test` hoặc `chore`.
Mô tả tối đa 5 từ tiếng Anh, chữ thường, bắt đầu bằng động từ, không dấu chấm cuối.
Ví dụ: `chore: initialize laravel backend`.
