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

Kiểm tra `GET http://localhost:8000/api/health` trả `{"status":"ok"}`.
Schema nghiệp vụ và seeder sẽ được bổ sung ở task riêng.

### Database test

Test chạy trên MySQL, database `healthcare_test`, dùng chung `DB_HOST`, `DB_PORT`,
`DB_USERNAME`, `DB_PASSWORD` trong `.env`. `phpunit.xml` ép `DB_CONNECTION` và `DB_DATABASE`
nên test không bao giờ chạm vào database `healthcare` của bản chạy thật.

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
CI chỉ chạy khi `backend/**` thay đổi. Module Health là mẫu kiểm tra nạp route và test;
các helper xác thực, validation và phân trang được triển khai ở task hạ tầng tiếp theo.

## Commit

Dạng `<type>: <mô tả>`, type là `feat`, `fix`, `refactor`, `docs`, `test` hoặc `chore`.
Mô tả tối đa 5 từ tiếng Anh, chữ thường, bắt đầu bằng động từ, không dấu chấm cuối.
Ví dụ: `chore: initialize laravel backend`.
