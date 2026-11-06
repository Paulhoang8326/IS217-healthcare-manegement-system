# IS217-healthcare-manegement-system

## Cấu trúc

- `backend/`: Laravel API, module trong `app/Modules/`, hạ tầng trong `app/Shared/`.
- `frontend/`: sẽ bổ sung ở giai đoạn frontend.
- `contract-tests/`: sẽ bổ sung khung Newman riêng.

## Chạy backend

Cần PHP 8.4, Composer 2 và Docker Compose. Bật các extension mbstring, intl, curl,
xml, dom, gd, zip, fileinfo, pdo_mysql; pdo_sqlite dùng cho test.

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
docker compose up -d
php artisan serve --port=8000
```

Kiểm tra `GET http://localhost:8000/api/health` trả `{"status":"ok"}`.
Schema nghiệp vụ và seeder sẽ được bổ sung ở task riêng.

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
