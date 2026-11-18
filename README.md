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

### Tranh chấp Appointment qua HTTP

Runner `backend/app/Modules/Appointment/Tests/http-concurrency.php` gửi HTTP song song và quan sát
`SHOW FULL PROCESSLIST` trước khi nhả khóa MySQL. Mỗi lượt kiểm tra: tranh suất cuối, cùng bệnh nhân
đặt hai lịch, webhook trùng transaction ID và webhook đã hết hạn cạnh tranh với scheduler thật.
Runner không sửa code app, không tạo lại schema và chỉ xóa fixture thuộc chính lượt chạy.

Điều kiện: CLI và HTTP server cùng dùng MySQL `healthcare_test`, cùng user MySQL, JWT secret và
SePay secret; database không có appointment trước khi chạy. Không chạy chung với bộ test khác.
Server cần ít nhất hai worker, ví dụ Nginx/PHP-FPM. Runner kiểm tra note ngẫu nhiên của fixture qua
API đọc trước khi gửi bất kỳ HTTP request ghi dữ liệu nào; sai server hoặc thiếu worker sẽ fail.
URL chỉ nhận origin loopback, số lượt từ 1 đến 20, mặc định 3. Đây là kiểm tra PHP/MySQL,
chưa phải kết quả so sánh với Java server.

Trên Windows có thể dùng PHP server nhiều worker trong Docker Linux. Từ gốc repo, với `.env`
đã cấu hình theo phần Chạy backend và Database test, mở terminal thứ nhất:

```sh
docker compose --env-file backend/.env up -d mysql
docker compose --env-file backend/.env run --rm --no-deps --name healthcare-http-check -p 127.0.0.1:18080:8000 -e APP_ENV=testing -e DB_DATABASE=healthcare_test -e CACHE_STORE=file -e PHP_CLI_SERVER_WORKERS=4 backend
```

Ở terminal thứ hai, từ gốc repo, sau khi server sẵn sàng:

```sh
docker exec healthcare-http-check php artisan migrate --force
docker exec healthcare-http-check php app/Modules/Appointment/Tests/http-concurrency.php --base-url=http://127.0.0.1:8000 --iterations=3
```

Server Compose dùng `artisan serve --no-reload`, nên giữ được `PHP_CLI_SERVER_WORKERS=4` trên Linux.
Nếu đã dùng `config:cache` cho workspace, cần bỏ cache cũ trước khi tạo server test để các biến
môi trường test có hiệu lực. Không dùng PHP server một worker hoặc bật nhiều worker trực tiếp
trên Windows. Kết quả JSON ra stdout chứa số lượt, số tình huống pass và số lock barrier quan sát
được; lỗi trả exit code 1. Dừng terminal server bằng Ctrl+C khi xong.

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

## Deploy

Quy trình dưới đây chuẩn bị cho một VPS Ubuntu 24.04 LTS, Nginx, PHP 8.4-FPM và MySQL 8.0.
Các lệnh là hướng dẫn cho người vận hành; chưa thực thi trên VPS. Thay domain ví dụ bằng domain của
môi trường, không ghi IP, mật khẩu hoặc private key vào repository. Các module chưa port, hai nhánh
webhook MR/PR và khung contract test vẫn cần hoàn thành trước khi công bố hệ thống đầy đủ.

### Cài runtime

Lock file hiện tại cần PHP 64-bit từ 8.4.1; dùng cùng nhánh PHP 8.4 cho CLI và FPM.
Trên Ubuntu 24.04, PHP 8.4 trong ví dụ lấy từ [PPA của Ondrej Sury](https://launchpad.net/~ondrej/+archive/ubuntu/php),
là nguồn bên thứ ba; quản trị viên cần chọn nguồn package cho môi trường của mình.

```sh
sudo apt-get update
sudo apt-get install -y software-properties-common ca-certificates curl unzip git nginx mysql-server
sudo add-apt-repository -y ppa:ondrej/php
sudo apt-get update
sudo apt-get install -y php8.4-cli php8.4-fpm php8.4-mysql php8.4-mbstring php8.4-intl php8.4-curl php8.4-xml php8.4-gd php8.4-zip php8.4-opcache
php8.4 -v
php8.4 -m
sudo systemctl enable --now mysql php8.4-fpm nginx
```

Cài Composer 2 theo [hướng dẫn chính thức](https://getcomposer.org/download/), xác minh checksum của
installer và đặt binary tại `/usr/local/bin/composer`. Workflow gọi Composer bằng `php8.4` để không
phụ thuộc phiên bản `php` mặc định của hệ điều hành. Kiểm tra `php8.4 /usr/local/bin/composer --version`.

### MySQL

Tạo `/etc/mysql/mysql.conf.d/healthcare.cnf`:

```ini
[mysqld]
transaction-isolation=READ-COMMITTED
character-set-server=utf8mb4
collation-server=utf8mb4_unicode_ci
```

Khởi động lại MySQL rồi xác nhận `SELECT VERSION(), @@transaction_isolation;`.
Tạo database nghiệp vụ và user riêng có quyền trên database đó để chạy migration; điền thông tin
đăng nhập trực tiếp trên server. Không dùng user root cho Laravel. Khớp host của user MySQL với
`DB_HOST` đã chọn. Kết nối Laravel cũng đặt `READ COMMITTED` trong `backend/config/database.php`.
Không tạo trigger, procedure hoặc function. Workflow không seed và không chạy `migrate:fresh`.

### Tài khoản deploy, thư mục và môi trường

Ví dụ dùng tài khoản SSH `hmsdeploy`, group FPM `www-data`, thư mục `/srv/healthcare`.
Tạo tài khoản và cấu hình public key tương ứng trong `authorized_keys` trước khi bật workflow.

```sh
sudo useradd --create-home --shell /bin/bash hmsdeploy
sudo usermod -aG www-data hmsdeploy
sudo install -d -o hmsdeploy -g www-data -m 2750 /srv/healthcare /srv/healthcare/releases /srv/healthcare/incoming /srv/healthcare/shared
sudo install -d -o hmsdeploy -g www-data -m 2770 /srv/healthcare/shared/storage
sudo install -d -o hmsdeploy -g www-data -m 2770 /srv/healthcare/shared/storage/app /srv/healthcare/shared/storage/framework /srv/healthcare/shared/storage/framework/cache
sudo install -d -o hmsdeploy -g www-data -m 2770 /srv/healthcare/shared/storage/app/private /srv/healthcare/shared/storage/app/public
sudo install -d -o hmsdeploy -g www-data -m 2770 /srv/healthcare/shared/storage/framework/cache/data /srv/healthcare/shared/storage/framework/cache/locks /srv/healthcare/shared/storage/framework/sessions /srv/healthcare/shared/storage/framework/views /srv/healthcare/shared/storage/logs
```

Đăng nhập lại tài khoản deploy sau khi thêm group. Sao chép `backend/.env.example` vào
`/srv/healthcare/shared/.env` và điền giá trị riêng của môi trường. Đặt owner `hmsdeploy:www-data`,
quyền `0640`; file này tồn tại độc lập với mỗi release và không được workflow ghi đè.
Các nhóm biến cần cấu hình (chỉ liệt kê tên):

```text
APP_NAME APP_ENV APP_KEY APP_DEBUG APP_URL APP_LOCALE APP_FALLBACK_LOCALE
APP_MAINTENANCE_DRIVER
LOG_CHANNEL LOG_STACK LOG_LEVEL
DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD
CACHE_STORE SESSION_DRIVER QUEUE_CONNECTION FILESYSTEM_DISK BROADCAST_CONNECTION
JWT_SECRET JWT_ACCESS_TOKEN_EXPIRATION_MS JWT_VERIFICATION_TOKEN_EXPIRATION_MS
SEPAY_WEBHOOK_SECRET SEPAY_ACCOUNT_NUMBER GOOGLE_CLIENT_ID
MAIL_MAILER MAIL_SCHEME MAIL_HOST MAIL_PORT MAIL_USERNAME MAIL_PASSWORD
MAIL_FROM_ADDRESS MAIL_FROM_NAME
```

Dùng `APP_ENV` production, `APP_DEBUG` false, locale/fallback en, `DB_CONNECTION` mysql;
`CACHE_STORE` file, `SESSION_DRIVER` file, `QUEUE_CONNECTION` sync và `APP_MAINTENANCE_DRIVER` file
cho cấu hình một VPS này. Chọn log stack/single hoặc cấu hình log phù hợp. Tạo `APP_KEY` một lần bằng
`php8.4 artisan key:generate --show` tại một bản source đã cài dependency, rồi lưu vào file môi trường;
không tạo lại key mỗi lần deploy. Đặt `APP_URL` theo HTTPS thực tế. `JWT_SECRET` và SePay secret phải
khớp cấu hình tích hợp. Không bỏ trống các biến trong `.env.example` mà ứng dụng cần dùng.

Timezone hiện lấy từ `config/app.php` (UTC); code không đọc `APP_TIMEZONE`.
Chưa xác minh timezone của JVM đang chạy bên Java, nên không tự đổi timezone trong bước deploy này.

Cho phép tài khoản deploy chỉ reload service FPM bằng `sudo visudo -f /etc/sudoers.d/healthcare-deploy`:

```sudoers
hmsdeploy ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.4-fpm
```

### Nginx

Tạo `/etc/nginx/sites-available/healthcare` với domain ví dụ `healthcare.example.invalid`:

```nginx
server {
    listen 80;
    server_name healthcare.example.invalid;
    root /srv/healthcare/current/public;
    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        fastcgi_hide_header X-Powered-By;
        internal;
    }

    location ~ \.(php|phar)$ {
        return 404;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Nginx chỉ trỏ vào `public`, không trỏ vào gốc backend.
`$realpath_root` theo đúng thư mục release sau khi đổi symlink. Bật site bằng symlink trong
`sites-enabled`, kiểm tra `sudo nginx -t` rồi reload Nginx. Chỉ công bố domain sau lần deploy đầu
thành công. Cấu hình TLS/certificate cho domain trước khi dùng token hoặc webhook qua Internet;
ví dụ port 80 ở trên dùng để kiểm tra cấu hình ban đầu. Giới hạn upload của Nginx và PHP-FPM phải
được đối chiếu với môi trường Java thực tế; chưa có giới hạn tùy chỉnh trong source Java đã đọc.

### Workflow và GitHub Environment

Workflow `.github/workflows/deploy-backend.yml` đợi workflow `Backend` hiện có hoàn tất thành công
cho một sự kiện push lên `main` của chính repository. Vì `Backend` lọc `backend/**`, thay đổi chỉ ở
frontend hoặc README không deploy. Merge PR vào main có thay đổi backend sẽ kích hoạt chuỗi này;
push trực tiếp vào main cũng vậy. PR check và workflow thất bại không deploy. File workflow phải
có trên default branch để nhận sự kiện `workflow_run`.
Trước khi upload, workflow đối chiếu tree `backend` với main để bỏ qua kết quả CI cũ nếu backend
đã thay đổi tiếp; commit chỉ sửa README không làm mất lượt deploy của cùng backend đã kiểm chứng.
Việc chỉ thêm workflow deploy và README không tự kích hoạt deploy; chuỗi chạy bắt đầu khi có push
vào main làm thay đổi backend và CI tương ứng thành công.

Tạo GitHub Environment `production`; cấu hình reviewer nếu môi trường cần duyệt trước khi chạy.
Khai báo các Environment secrets sau:

| Tên | Nội dung |
|---|---|
| `DEPLOY_HOST` | DNS hoặc IPv4 của VPS, không có scheme hay dấu cách |
| `DEPLOY_PORT` | Cổng SSH; bỏ trống dùng 22 |
| `DEPLOY_USER` | Tài khoản SSH, ví dụ tài khoản đã tạo ở trên |
| `DEPLOY_SSH_KEY` | Private key deploy không có passphrase, public key tương ứng đã có trên VPS |
| `DEPLOY_KNOWN_HOSTS` | Dòng known_hosts của host/port SSH, fingerprint được xác nhận qua kênh quản trị VPS |

Khai báo Environment variable `DEPLOY_PATH` là `/srv/healthcare` hoặc đường dẫn tuyệt đối tương ứng,
không chứa dấu cách, dấu chấm hay ký tự shell. Workflow bật kiểm tra host key; không tự tin cậy kết quả
`ssh-keyscan` chưa đối chiếu. SSH key chỉ nằm trong file tạm của runner, quyền giới hạn và được dọn
ở bước cuối. Không cần cấp GitHub deploy key hoặc clone repository trên VPS: runner chuyển archive
backend của đúng SHA đã được CI kiểm chứng.

Các lần deploy được tuần tự hóa, không hủy một lần đang chạy. Quy trình trên VPS:

1. Giải nén vào `releases/<sha>-<run>-<attempt>`, liên kết `.env` và `storage` dùng chung.
2. Chạy Composer `install --no-dev` từ lock file và `check-platform-reqs --no-dev`, gán group release
   cho `www-data` để FPM đọc được file tạo bởi tài khoản deploy.
3. Bật maintenance, chạy `migrate --force`, `config:cache`, `route:cache`.
4. Đổi symlink `current` sang release mới, reload PHP-FPM rồi tắt maintenance.

Nếu lỗi trước bước đổi symlink, `current` vẫn là release cũ. Nếu đã bật maintenance rồi lỗi,
workflow giữ maintenance để người vận hành kiểm tra log và tình trạng schema. Không tự rollback
migration hoặc tự bật lại phiên bản có thể không tương thích schema. Sao lưu database trước khi
thực sự chạy migration trên dữ liệu cần giữ. Release cũ được giữ lại; việc quay lại source cũ phải
xác nhận tương thích database. Chưa có chính sách tự xóa release cũ.

### Cron và kiểm tra sau deploy

Trong crontab của `hmsdeploy`, thêm một dòng:

```cron
* * * * * cd /srv/healthcare/current && /usr/bin/php8.4 artisan schedule:run >> /srv/healthcare/shared/storage/logs/scheduler.log 2>&1
```

Không chạy thêm `schedule:work` trên cùng VPS. Cache file và storage được dùng chung giữa các
release để `withoutOverlapping()` giữ cùng khóa. Sau deploy, kiểm tra `/up`, `/api/health` với token
hợp lệ, `php8.4 artisan schedule:list`, log Laravel/FPM và quyền ghi vào storage. Sau khi module
Payment và bộ contract test có sẵn, chạy thêm kiểm chứng hai nhánh MR/PR và các collection liên quan.

Tham chiếu: [Laravel deployment](https://laravel.com/framework/docs/13.x/deployment),
[GitHub workflow_run](https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows#workflow_run),
[GitHub deployments](https://docs.github.com/en/actions/how-tos/deploy/configure-and-manage-deployments/control-deployments),
[MySQL transaction isolation](https://dev.mysql.com/doc/refman/8.0/en/set-transaction.html).

## Commit

Dạng `<type>: <mô tả>`, type là `feat`, `fix`, `refactor`, `docs`, `test` hoặc `chore`.
Mô tả tối đa 5 từ tiếng Anh, chữ thường, bắt đầu bằng động từ, không dấu chấm cuối.
Ví dụ: `chore: initialize laravel backend`.
