# Vận hành Laravel Octane và Swoole

[English canonical guide](octane-swoole.md)

Tài liệu tiếng Anh là hướng dẫn vận hành chuẩn. Tệp tiếng Việt này là bản dịch.
Khi thay đổi một trong hai tài liệu, hãy giữ đồng bộ số thứ tự mục, lệnh, giới
hạn và các yêu cầu an toàn.

> Octane thay đổi mô hình thực thi PHP: ứng dụng chỉ khởi động một lần rồi được
> tái sử dụng cho nhiều request. Việc tái tạo worker giúp hạn chế ảnh hưởng của
> dữ liệu vô tình bị giữ lại, nhưng không làm cho trạng thái toàn cục theo request
> trở nên an toàn. Hãy hoàn tất soak test trong hướng dẫn này trước khi bật Octane
> trên production.

## 1. Phạm vi và mô hình vận hành

Hướng dẫn này áp dụng Laravel Octane với application server Swoole cho dự án
PHP 8.5 / Laravel 13 này. Nội dung bao gồm cài đặt, sử dụng cục bộ, quy trình
production, reverse proxy, triển khai, năng lực, quan sát, xác thực và rollback.

Sử dụng các nhóm process riêng cho từng trách nhiệm:

| Trách nhiệm | Process |
| --- | --- |
| HTTP và streamed response | `php artisan octane:start --server=swoole` |
| Job trong hàng đợi | một hoặc nhiều nhóm `php artisan queue:work` |
| Tác vụ theo lịch | cron gọi `php artisan schedule:run` |
| Telegram long polling, khi được bật | `php artisan starter-kit-telegram:poll` trong process riêng |
| TLS và tệp tĩnh public | Nginx, load balancer hoặc ingress controller |

Không chạy queue worker, `schedule:work` hoặc lệnh polling vô hạn bên trong
Octane request worker. Swoole task worker không thay thế hàng đợi Laravel bền
vững.

Các ví dụ Telegram, KYC, HRM và module khác chỉ áp dụng khi đã cài module
thương mại tương ứng. Public core không kèm các module, queue hoặc lệnh doctor
đó; kiểm tra hướng dẫn của đúng phiên bản module trước khi cấu hình.

## 2. Điều kiện tiên quyết

### 2.1 Runtime được hỗ trợ

Sử dụng đầy đủ các thành phần sau trên production:

- máy chủ hoặc container Linux với bản dựng 64-bit;
- PHP CLI 8.5, cùng phiên bản mà Composer và Supervisor sử dụng;
- bản phát hành Swoole **stable** mới nhất được tổ chức phê duyệt và công bố hỗ
  trợ PHP 8.5; tại thời điểm viết, dòng Swoole 6.2 stable là baseline;
- Laravel Octane từ tệp Composer lock đã commit;
- `pcntl`, `posix`, `sodium`, `mbstring`, `gd`, PDO driver đã chọn và các
  extension khác mà `composer.json` yêu cầu;
- database dùng chung, và ưu tiên Redis cho cache, lock, session và hàng đợi
  thông lượng cao trong triển khai nhiều instance;
- Supervisor, systemd, Kubernetes hoặc một process monitor khác; và
- Nginx hoặc reverse proxy tin cậy tương đương đặt trước Octane.

Ghim patch version Swoole đã kiểm thử trong image hoặc bản dựng máy chủ. Không
cho phép nâng cấp PECL chưa kiểm thử trong khi triển khai ứng dụng. Không chạy
Xdebug hoặc extension profiling khác trong production worker pool.

### 2.2 Xác minh trước khi chạy

Chạy các kiểm tra này bằng đúng PHP binary mà Supervisor sẽ thực thi:

```bash
php -v
php --ini
php --ri swoole
php -m | sort
composer check-platform-reqs
php artisan about
```

`php --ri swoole` phải báo phiên bản stable tương thích PHP 8.5. Nếu lệnh hoạt
động trong PHP-FPM nhưng không hoạt động trong CLI, extension đã được bật trong
sai `php.ini`; Octane sử dụng PHP CLI.

Xác nhận đồng hồ máy chủ được đồng bộ, giới hạn file descriptor và process đủ
lớn, user triển khai có thể ghi vào `storage/` và `bootstrap/cache/`, đồng thời
proxy có thể truy cập địa chỉ Octane private.

## 3. Cài đặt và cấu hình ban đầu

### 3.1 Cài đặt Swoole

Trên máy build có PHP 8.5 development headers, cài đặt bản stable đã được phê
duyệt. Phiên bản cụ thể dưới đây là ví dụ đã biết có hỗ trợ PHP 8.5; chỉ thay
thế sau khi đã kiểm định trên staging:

```bash
pecl channel-update pecl.php.net
pecl install swoole-6.2.2
php --ri swoole
```

PECL thường tự tạo INI entry cho extension. Nếu không, thêm
`extension=swoole` vào cấu hình **CLI** của PHP 8.5 rồi chạy lại bước xác minh.
Khi có thể, hãy build Swoole vào production image bất biến thay vì biên dịch
trên từng máy chủ ứng dụng.

### 3.2 Cài đặt dependency của ứng dụng

Octane và cấu hình của nó là dependency của dự án và phải được commit trước
khi triển khai. Máy chủ production cài đặt theo lock file; không được chạy
`composer require` hoặc tạo lại cấu hình Octane:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan package:discover --ansi
php artisan octane:status
```

Với maintainer khởi tạo Octane lần đầu trên một branch chưa có Octane, các lệnh
chạy một lần là:

```bash
composer require laravel/octane
php artisan octane:install --server=swoole
```

Rà soát và commit các thay đổi Composer và `config/octane.php` được tạo ra.
Không chạy `octane:install` trong mỗi lần triển khai vì lệnh có thể ghi đè cấu
hình đã được review.

### 3.3 Quyền sở hữu cấu hình

Xem `config/octane.php` và lệnh của process monitor như một đơn vị cần review:

- cấu hình cung cấp giá trị mặc định cho server, địa chỉ bind, port, số worker,
  việc recycle, nhận biết HTTPS, danh sách warm/flush, tùy chọn Swoole, theo dõi
  tệp và thời gian thực thi request;
- option tường minh của lệnh process ghi đè giá trị cấu hình tương ứng;
- thay đổi tùy chọn Swoole cấp server hoặc PHP INI cần restart toàn bộ process
  Octane, không chỉ reload application worker; và
- production tuyệt đối không dùng `--watch`.

## 4. Baseline môi trường

Cấu hình sau là điểm khởi đầu, không phải định mức năng lực chung cho mọi hệ
thống:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://example.com

OCTANE_SERVER=swoole
OCTANE_HTTPS=true
OCTANE_HOST=127.0.0.1
OCTANE_PORT=8000
OCTANE_WORKERS=auto
OCTANE_TASK_WORKERS=1
OCTANE_MAX_REQUESTS=500
OCTANE_MAX_EXECUTION_TIME=30
SWOOLE_PACKAGE_MAX_LENGTH=33554432

CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
SESSION_SECURE_COOKIE=true
TRUSTED_PROXIES=10.0.0.0/8

FILESYSTEM_DISK=s3
PRIVATE_DISK=s3
MEDIA_DISK=s3
```

Các giá trị `OCTANE_HOST`, `OCTANE_PORT`, `OCTANE_WORKERS`,
`OCTANE_TASK_WORKERS` và `OCTANE_MAX_REQUESTS` được cấu hình Octane của ứng
dụng đọc khi không truyền CLI option tương ứng. CLI flag tường minh được ưu
tiên. Duy trì một nguồn cấu hình chuẩn trong deployment template và render cả
`.env` lẫn lệnh process từ nguồn đó.

Sử dụng `OCTANE_HOST=127.0.0.1` trên bare host. Container có thể bind
`0.0.0.0`, nhưng port phải được giữ private trong service network. Không bao
giờ công khai port Octane trực tiếp ra Internet.

`TRUSTED_PROXIES` chỉ được chứa IP hoặc dải CIDR của proxy do hệ thống triển
khai kiểm soát. Không tin cậy mọi nguồn. Khi Redis không khả dụng, dùng cache,
session và queue dựa trên database; không dùng `array`, tệp cục bộ hoặc trạng
thái theo pod để điều phối xuyên worker.

## 5. Phát triển cục bộ

Bắt đầu với một request worker để dễ quan sát hành vi:

```bash
php artisan optimize:clear
php artisan octane:start \
  --server=swoole \
  --host=127.0.0.1 \
  --port=8000 \
  --workers=1 \
  --task-workers=1 \
  --max-requests=100
```

Sau khi thay đổi mã nguồn hoặc cấu hình, reload worker:

```bash
php artisan octane:reload
```

`--watch` chỉ là tiện ích cho môi trường development và yêu cầu Chokidar là
development dependency trực tiếp. Khi điều tra việc giữ trạng thái, nên reload
chủ động vì cách này giúp nhìn rõ ranh giới giữa các thế hệ worker.

Chạy Vite và queue worker trong các terminal riêng:

```bash
bun run dev
php artisan queue:work --sleep=1 --tries=3 --max-jobs=100 --max-time=1800
```

Luân phiên kiểm thử ít nhất hai user và hai locale. Một trang hoạt động với một
user chưa đủ để chứng minh singleton không giữ lại trạng thái request, xác
thực, locale hoặc tenant.

## 6. Năng lực và vòng đời production

### 6.1 Định cỡ worker

Bắt đầu từ số liệu đo, không chỉ từ số CPU:

```text
request_workers = min(
    available_vCPU,
    floor(memory_budget_for_octane / measured_p95_worker_RSS)
)
```

Dành riêng bộ nhớ và CPU cho hệ điều hành, proxy, buffer của database client,
queue worker, scheduler và phần overlap khi triển khai. Mỗi Swoole task worker
là một PHP process khác với mức dùng bộ nhớ riêng. Nếu ứng dụng không dùng tác
vụ đồng thời của Octane, giữ số task worker thấp thay vì dùng `auto` một cách
máy móc.

Các kiểm soát khởi đầu được khuyến nghị:

| Kiểm soát | Giá trị ban đầu | Quy tắc điều chỉnh |
| --- | ---: | --- |
| Request worker | `auto` hoặc số vCPU cụ thể | Giảm khi kết nối DB hoặc bộ nhớ bị giới hạn |
| Task worker | `1` hoặc số cụ thể đã đo | Chỉ tăng khi có nhu cầu `Octane::concurrently` đã đo |
| Số request tối đa | `500` | Dùng `100`-`250` trong rollout nếu bật peak KYC/HRM |
| Thời gian request tối đa | `30` giây | Giữ HTTP có giới hạn; chuyển việc dài sang queue |
| PHP `memory_limit` | hard ceiling đã đo, thường là `512M` | Phải đủ cho request hợp lệ lớn nhất nhưng không cho phép cạn bộ nhớ máy chủ |

Recycling là lưới an toàn. Đường RSS tăng đều trong mỗi thế hệ worker vẫn cần
được điều tra, kể cả khi `--max-requests` ngăn được sự cố.

### 6.2 Supervisor: Octane

Dùng một Octane master do Supervisor quản lý. Octane tự tạo request process và
task worker process, vì vậy `numprocs` phải giữ ở `1`, trừ khi chủ đích chạy
nhiều instance trên các port khác nhau.

Repository có sẵn
[mẫu Supervisor](../deploy/supervisor/octane.conf.example) để sao chép. Hãy
điều chỉnh thư mục, user, số worker và nơi ghi log cho máy chủ đích.

```ini
[program:douwyn-octane]
process_name=%(program_name)s
directory=/var/www/douwyn/current
command=/usr/bin/php artisan octane:start --server=swoole --host=127.0.0.1 --port=8000 --workers=4 --task-workers=1 --max-requests=500
user=www-data
numprocs=1
autostart=true
autorestart=true
startsecs=5
startretries=3
stopsignal=TERM
stopasgroup=true
killasgroup=true
stopwaitsecs=70
redirect_stderr=true
stdout_logfile=/var/log/supervisor/douwyn-octane.log
stdout_logfile_maxbytes=50MB
stdout_logfile_backups=10
environment=APP_ENV="production"
```

`stopwaitsecs` phải lớn hơn thời lượng request HTTP tối đa được chấp nhận cộng
thêm khoảng thời gian drain. Đồng bộ lệnh với baseline môi trường và năng lực
đã đo. Dùng đường dẫn PHP tuyệt đối để Supervisor không chọn nhầm bản cài PHP.

Sau khi tạo hoặc thay đổi tệp:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status douwyn-octane
```

### 6.3 Lệnh vòng đời

```bash
php artisan octane:status
php artisan octane:reload
php artisan octane:stop
```

Dùng `octane:reload` sau các thay đổi mã ứng dụng tương thích. Restart toàn bộ
Supervisor sau khi thay đổi PHP extension hoặc INI, địa chỉ bind, port, số
worker, tùy chọn Swoole server hoặc lệnh process:

```bash
sudo supervisorctl restart douwyn-octane
```

## 7. Reverse proxy và giới hạn request

### 7.1 Ví dụ Nginx

Kết thúc TLS và phục vụ asset public tại proxy. Điều chỉnh đường dẫn
certificate, domain, đường dẫn release và proxy CIDR theo môi trường:

```nginx
map $http_upgrade $connection_upgrade {
    default upgrade;
    ''      close;
}

upstream douwyn_octane {
    server 127.0.0.1:8000;
    keepalive 32;
}

server {
    listen 443 ssl;
    server_name example.com;
    root /var/www/douwyn/current/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/example.com/privkey.pem;

    client_max_body_size 24m;
    client_body_timeout 30s;

    location / {
        try_files $uri $uri/ @octane;
    }

    location = /index.php {
        try_files /__octane_front_controller__ @octane;
    }

    location ~* \.php(?:/|$) { return 404; }
    location ~ /\.(?!well-known(?:/|$)) { deny all; }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location @octane {
        proxy_http_version 1.1;
        proxy_set_header Host $http_host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Port $server_port;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $connection_upgrade;
        proxy_read_timeout 40s;
        proxy_send_timeout 40s;
        proxy_buffering off;
        proxy_pass http://douwyn_octane;
    }
}
```

Không tạo tệp `public/__octane_front_controller__`: đây là đường dẫn giả để
chuyển front controller sang Octane. Các PHP path khác và dotfile bị chặn để
Nginx không phục vụ source code. Kiểm tra `/`, `/up`, `/index.php`, một PHP path
khác và dotfile qua proxy trước khi triển khai.

Chuyển hướng HTTP sang HTTPS trong một server block riêng. Đặt `APP_URL` thành
HTTPS origin public, `OCTANE_HTTPS=true`, `SESSION_SECURE_COOKIE=true` và cấu
hình rõ ràng `TRUSTED_PROXIES`. Xác minh URL được sinh, redirect, secure cookie,
Sanctum stateful domain và giới hạn tốc độ theo IP client qua proxy thật.

### 7.2 Giới hạn upload và body

Upload chỉ thành công khi mọi tầng đều cho phép:

```text
module validation limit
    <= PHP upload_max_filesize
    <  PHP post_max_size
    <= Nginx client_max_body_size
    <  Swoole package_max_length
```

Chừa khoảng trống cho multipart boundary và header. Với ứng dụng cần upload
tệp tối đa 20 MiB, profile dưới đây là một ví dụ đồng bộ. Giới hạn thực tế của
module thương mại phải được xác minh theo phiên bản đã cài:

```ini
; php.ini
upload_max_filesize=20M
post_max_size=24M
max_file_uploads=20
```

```dotenv
SWOOLE_PACKAGE_MAX_LENGTH=33554432
```

```nginx
client_max_body_size 24m;
```

Kiểm tra lại giá trị tối đa của mọi module được bật mỗi khi thay đổi thiết lập
upload. Không nâng mọi HTTP worker lên giới hạn body 1 GiB cho artifact của
Licensing; dùng upload trực tiếp lên object storage hoặc đường upload được cô
lập riêng cho tệp rất lớn. Giới hạn request body lớn hơn làm tăng rủi ro bộ nhớ
và đĩa tạm khi có concurrency, vì vậy phải được load test.

## 8. Trạng thái dùng chung và quy tắc ứng dụng an toàn với Octane

Các worker không chia sẻ PHP array hoặc object instance, trong khi mỗi worker
tái sử dụng instance riêng qua nhiều request. Áp dụng các quy tắc sau:

- không lưu `Request`, user đang xác thực, session object, Eloquent model,
  locale, tenant, credential hoặc response data trong static property hay
  singleton sống suốt vòng đời worker;
- dùng binding `scoped` của container cho trạng thái request/job có thể thay
  đổi, hoặc resolve request hiện tại tại thời điểm sử dụng;
- không thay đổi process global như `$_SERVER`, `$_ENV`, locale, timezone,
  error handler hoặc thiết lập parser toàn cục của thư viện trong request;
- chỉ đăng ký listener và extension-registry callback một lần khi boot, không
  bao giờ một lần mỗi request; không đăng ký closure capture request hoặc model;
- đóng stream, tệp tạm, HTTP response body, lock và native handle trong khối
  `finally`;
- giới hạn mọi cache trong bộ nhớ theo số key và thời gian sống, đồng thời vô
  hiệu schema hoặc configuration cache khi triển khai;
- phân trang hoặc dùng cursor qua dữ liệu cardinality cao; streamed response
  không tiết kiệm bộ nhớ nếu collection nguồn đã eager load từ trước; và
- không dựa vào bộ nhớ cục bộ của worker cho rate limit, idempotency,
  distributed lock, session, queue uniqueness hoặc ngăn scheduler chạy chồng.

Với nhiều hơn một worker hoặc host, dùng cache backend nguyên tử dùng chung.
Ưu tiên Redis. Database cache và session là phương án dùng chung hợp lệ nhưng
làm tăng tải database. File session và lock cục bộ không hoạt động xuyên host.
Cache driver `array` chỉ tồn tại theo process và không được dùng để điều phối
production.

Media public và tài liệu private phải nằm trên storage mà mọi instance đều nhìn
thấy. Dùng storage tương thích S3 cho triển khai nhiều host hoặc filesystem thực
sự dùng chung với độ bền và kiểm soát private object tương đương.

## 9. Tách biệt queue và scheduler

### 9.1 Queue worker

Chạy queue worker trong các nhóm process riêng và recycle độc lập với Octane.
Đặt `--timeout` ngắn hơn `retry_after` của queue connection nhưng dài hơn thời
lượng hợp lệ tối đa của job. Luôn dùng `--memory`, `--max-jobs` hoặc `--max-time`
cho worker sống lâu.

Ví dụ worker chung dưới đây dùng `--timeout=60`, ngắn hơn
`retry_after=90` mặc định của Redis connection. Nếu một module có job dài hơn,
cấu hình connection riêng với `retry_after` lớn hơn cả timeout của job và
worker, có khoảng đệm; tăng riêng `--timeout` có thể khiến job chạy hai lần.

Ví dụ worker chung:

```ini
[program:douwyn-queue-default]
process_name=%(program_name)s_%(process_num)02d
directory=/var/www/douwyn/current
command=/usr/bin/php artisan queue:work redis --queue=default --sleep=1 --tries=3 --timeout=60 --memory=384 --max-jobs=500 --max-time=3600
user=www-data
numprocs=2
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=360
redirect_stderr=true
stdout_logfile=/var/log/supervisor/douwyn-queue-default.log
```

Dùng queue riêng cho workload có profile bộ nhớ hoặc timeout khác nhau:

```dotenv
KYC_QUEUE=kyc
KYC_NOTIFICATION_QUEUE=kyc-notifications
DOMAINS_QUEUE=domains
TELEGRAM_INBOUND_QUEUE=telegram-inbound
TELEGRAM_OUTBOUND_QUEUE=telegram-outbound
TELEGRAM_BROADCAST_QUEUE=telegram-broadcast
TELEGRAM_CAMPAIGN_QUEUE=telegram-campaigns
```

Đề xuất tách biệt:

- kiểm tra/export KYC: concurrency thấp, memory ceiling cao hơn, `--max-jobs`
  thấp như `25`-`50`, và timeout cao hơn thời lượng export được cho phép;
- đồng bộ Domains: timeout cao hơn giới hạn operation của module đã cài
  và `stopwaitsecs` đủ dài để drain;
- Telegram inbound: worker ưu tiên latency tách khỏi worker outbound và
  broadcast có rate limit; và
- báo cáo lớn hoặc payroll: một queue riêng khi các operation đó đã bất đồng bộ.

Restart queue worker sau mỗi lần triển khai:

```bash
php artisan queue:restart
```

### 9.2 Scheduler

Ưu tiên cron entry stateless:

```cron
* * * * * cd /var/www/douwyn/current && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

Chạy trên một scheduler host được chỉ định, hoặc giữ các task `onOneServer`
trên atomic cache dùng chung. Nếu dùng `schedule:work`, giám sát nó như một
process riêng và restart khi triển khai. Không bao giờ chạy nó bên trong Octane.

Theo dõi thời lượng scheduled task và overlap lock. Trước khi xóa scheduler
lock, phải chứng minh không có task trước đó còn chạy; xóa lock tùy tiện có thể
khởi chạy trùng công việc tài chính, thông báo hoặc đối soát.

## 10. Triển khai, reload và rollback

### 10.1 Trình tự triển khai

Dùng release directory nguyên tử và migration tương thích ngược. Một trình tự
điển hình là:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
bun install --frozen-lockfile
bun run build
php artisan migrate --force
php artisan optimize
php artisan about
```

Sau đó chuyển symlink `current` một cách nguyên tử và reload mọi process sống
lâu:

```bash
php artisan octane:reload
php artisan queue:restart
```

Laravel 13 cũng cung cấp `php artisan reload` cho các service có thể reload.
Dù dùng lệnh tổng hợp hay các lệnh rõ ràng, hãy xác minh Supervisor khởi động
process thay thế và `/up` thành công qua proxy.

Dùng restart toàn bộ Supervisor thay cho `octane:reload` khi release thay đổi
PHP binary hoặc extension, PHP INI, biến môi trường mà master sử dụng, địa chỉ
listen hoặc port, số worker hay tùy chọn Swoole server:

```bash
sudo supervisorctl restart douwyn-octane
sudo supervisorctl restart douwyn-queue-default:*
```

Không xóa release trước khi worker cũ có thể vẫn tham chiếu đến nó. Không kết
hợp migration schema gây breaking change với rolling worker reload.

### 10.2 Rollback

Giữ ít nhất một release trước đó đã xác minh cùng dependency tree của nó. Để
rollback:

1. dừng traffic hoặc vào maintenance mode nếu schema cũ và mới không tương
   thích lẫn nhau;
2. trỏ `current` nguyên tử về release trước;
3. khôi phục môi trường và cấu hình cache trước đó;
4. chạy `php artisan optimize:clear` trong release đã chọn, rồi chỉ dựng lại
   các cache known-good của release đó;
5. restart toàn bộ Octane, queue worker và mọi process `schedule:work` hoặc
   Telegram poll;
6. xác minh `/up`, hành vi login/session, database request chỉ đọc, thao tác
   cache và lock, xử lý queue cùng doctor command của module được bật; và
7. mở lại traffic từ từ trong khi theo dõi lỗi, latency, RSS và queue lag.

Không tự động đảo migration trong lúc xử lý sự cố. Chỉ khôi phục hoặc migrate
database theo rollback plan đã review và kiểm thử; dữ liệu do release mới ghi
có thể không được release cũ hiểu.

## 11. Health check và khả năng quan sát

Route `/up` có sẵn được cấu hình trong `bootstrap/app.php`. Đây là kiểm tra
liveness và framework boot, không chứng minh database, cache, queue, storage
hoặc external provider đang khỏe mạnh.

Dùng ba tầng:

1. **Liveness:** gọi `/up` qua proxy; giữ route nhẹ và không cần xác thực.
2. **Readiness/kiểm tra tổng hợp:** thực hiện database read an toàn, shared
   cache, distributed lock, thao tác metadata storage và một luồng API xác thực
   đại diện từ private monitor.
3. **Kiểm tra vận hành sâu:** chạy doctor command của module được bật từ
   deployment hoặc monitoring job được bảo vệ, ví dụ
   `starter-kit-blog:doctor`, `starter-kit-domains:doctor`,
   `starter-kit-hrm:doctor`, `starter-kit-infrastructure:doctor`,
   `starter-kit-kyc:doctor`, `starter-kit-ledger:doctor` và
   `starter-kit-licensing:doctor`.

Theo dõi và lưu giữ tối thiểu:

- request rate, concurrency, latency p50/p95/p99, 4xx/5xx, timeout, client
  abort và response size theo nhóm route;
- PID master và từng worker, RSS, CPU, file descriptor, lý do restart, tuổi thế
  hệ và số request đã phục vụ;
- số kết nối database, slow query, thời lượng transaction, deadlock và mức bão
  hòa pool;
- latency cache, hit rate, lỗi, eviction và lỗi lấy lock;
- độ sâu queue, tuổi job cũ nhất, thời gian chạy, retry, failure và recycling
  worker;
- lý do và kích thước upload bị từ chối mà không log nội dung bí mật;
- log Swoole và Supervisor, lỗi PHP fatal, OOM hoặc container kill; và
- phiên bản deploy, phiên bản PHP, phiên bản Swoole và fingerprint cấu hình.

Cảnh báo theo độ dốc RSS cũng như giá trị RSS tuyệt đối. Worker liên tục tăng từ
150 MiB lên 450 MiB trước mỗi lần recycle theo kế hoạch vẫn có vấn đề giữ dữ
liệu hoặc workload, kể cả khi máy chủ còn hoạt động. Tương quan mức tăng với
route, status, payload size, cardinality user/tenant và worker PID. Không đưa
token, credential, plaintext KYC/HRM hoặc request body vào telemetry.

## 12. Checklist soak test và load test

Chạy checklist này trên môi trường staging giống production, sử dụng cùng PHP,
Swoole, proxy, cache, database engine, storage driver, số worker và body limit
như production.

### 12.1 Tách biệt chức năng

- luân phiên request giữa ít nhất hai user, tenant/owner, locale và permission
  set trên cùng keep-alive connection;
- xác minh không có xác thực, phân quyền, locale, session, validation, header
  hoặc response data rò rỉ xuyên request;
- kiểm thử các đường thành công, validation failure, authorization failure,
  exception, redirect, streamed response và client disconnect; và
- xác minh lock, transaction, stream và tệp tạm được giải phóng sau mọi đường.

### 12.2 Bộ nhớ và năng lực

- ghi nhận PID từng worker và RSS baseline sau warm-up;
- chạy ít nhất 1.000 request trên mỗi route quan trọng, sau đó chạy bài test hỗn
  hợp đủ lâu để vượt `OCTANE_MAX_REQUESTS` nhiều lần;
- kiểm thử concurrency `1`, steady state kỳ vọng và một burst có kiểm soát;
- lấy mẫu RSS từng worker, CPU, file descriptor, kết nối database và latency
  xuyên suốt mỗi thế hệ worker;
- xác nhận worker thay thế trở nên ready trước khi worker cũ thoát; và
- xác định ngưỡng đạt cho RSS plateau, error rate, latency p95/p99, queue lag và
  recovery time trước khi bắt đầu test.

### 12.3 Hành vi khi lỗi và triển khai

- làm gián đoạn Redis, database, object storage, DNS và một external provider
  trong các bài test có kiểm soát;
- hủy upload lớn và streamed download giữa chừng;
- buộc queue job đi qua các đường timeout, retry và worker recycle;
- reload khi có traffic liên tục và xác minh không có spike 502 có thể tránh;
- thực hiện một lần triển khai tiến và một lần rollback; và
- xác minh proxy không bao giờ chuyển traffic tới worker thay thế chưa ready.

Giữ time series thô theo PID. Một giá trị bộ nhớ process trước/sau duy nhất
không thể phân biệt leak với phân bổ high-water hợp lệ.

## 13. Lưu ý năng lực theo module

### 13.1 Hotel Booking

Nếu module booking đã cài tính toán theo từng ngày lưu trú, loại phòng hoặc
room line, chi phí xử lý có thể tăng theo tích của các giá trị đó. Xác minh
service đã giới hạn khoảng ngày và booking horizon trước khi mở availability
public; không suy luận mức an toàn chỉ từ một request nhỏ.

Trước khi mở route qua Octane:

- enforce khoảng ngày bảo thủ tại API gateway/WAF hoặc giữ route public ở trạng
  thái tắt cho đến khi service tự từ chối khoảng ngày quá lớn;
- giới hạn tốc độ và concurrency theo danh tính client tin cậy và IP;
- soak test khoảng tối đa được phép nhân với số loại phòng active và room line
  tối đa; và
- theo dõi tăng trưởng inventory row, query count, thời lượng transaction và
  worker RSS.

Rate limiting không phải giới hạn khối lượng công việc trên mỗi request.
Execution timeout 30 giây giới hạn thiệt hại nhưng không hoàn tác các row đã
được tạo bởi request bị gián đoạn.

### 13.2 KYC

Kiểm tra module KYC đã cài để xác định inspection, export hoặc download được
ủy quyền có buffer plaintext đã giải mã trong bộ nhớ hay không. Giới hạn có thể cấu hình khiến một operation
đơn lẻ lớn hơn đáng kể so với worker baseline thông thường, và kiểm tra ảnh có
thể tạm giữ nhiều hơn một biểu diễn plaintext.

Kiểm soát vận hành:

- giữ `KYC_MAX_UPLOAD_SIZE_KB` cùng giới hạn byte/row export ở mức nhỏ nhất mà
  nghiệp vụ chấp nhận;
- đưa inspection/export lên worker `KYC_QUEUE` riêng với concurrency thấp,
  memory ceiling đã đo và recycling thường xuyên;
- route endpoint HTTP KYC dùng nhiều bộ nhớ sang Octane pool cô lập nếu API
  worker thông thường không thể hấp thụ peak an toàn;
- bắt đầu với `--max-requests=100`-`250` cho pool đó và xác minh RSS hồi phục;
- đồng bộ body limit của proxy, PHP và Swoole mà không mở global limit lớn không
  cần thiết; và
- không bao giờ ghi byte đã giải mã, filename chứa PII, lý do truy cập hoặc nội
  dung tài liệu vào log hay trace.

Worker recycling làm giảm việc giữ high-water của allocator nhưng không thay
thế incremental streaming.

### 13.3 HRM

Kiểm tra xem bản HRM đã cài có dựng dữ liệu payroll đồng bộ hoặc preload toàn
bộ item graph trước khi export hay không. Nếu có, tổ chức lớn có thể giữ
worker và transaction lâu hơn request thông thường. Đối chiếu giới hạn upload
và hành vi streaming theo đúng phiên bản module.

Cho đến khi operation payroll lớn được chia chunk hoặc đưa vào queue:

- giới hạn concurrency tính/export payroll và chạy ngoài giờ cao điểm;
- dùng Octane pool nội bộ riêng khi cardinality của tổ chức lớn;
- test tổ chức lớn nhất, số component và số row export, không chỉ payroll trung
  bình;
- cảnh báo theo thời lượng transaction, row lock, query count, response time và
  RSS;
- tránh tăng timeout request toàn cục để đáp ứng payroll; và
- định cỡ đường upload cho giới hạn tài liệu đã cấu hình cộng multipart overhead.

## 14. Khắc phục sự cố

| Triệu chứng | Kiểm tra và xử lý |
| --- | --- |
| Thiếu `octane:start` | Chạy `composer install`, xác minh `laravel/octane` trong lock file, sau đó chạy package discovery. |
| Không tìm thấy Swoole | So sánh `which php`, `php --ini` và đường dẫn PHP tuyệt đối của Supervisor; bật extension cho PHP CLI 8.5. |
| Mã nguồn hoặc route có vẻ cũ | Chạy `php artisan octane:reload`; với thay đổi server/INI/env, restart toàn bộ Supervisor. |
| 502 ngay lập tức hoặc connection refused | Kiểm tra `octane:status`, log Supervisor và Swoole, địa chỉ/port bind, firewall và process khác có đang giữ port hay không. |
| 419 chập chờn, sai scheme hoặc cookie không secure | Xác minh `APP_URL`, `OCTANE_HTTPS`, thiết lập secure cookie, trusted proxy rõ ràng, forwarded header và Sanctum domain. |
| HTTP 413 hoặc không có upload | Đồng bộ limit module, PHP, Nginx và `package_max_length`; chừa multipart overhead rồi reload master. |
| Request kết thúc ở 30 giây | Giữ HTTP có giới hạn; chuyển công việc sang queue. Chỉ thay đổi max execution time khi có SLO rõ ràng và restart toàn bộ. |
| RSS tăng qua nhiều request | Xác định PID và route, tái hiện với concurrency 1, kiểm tra trạng thái singleton/static/global và collection eager, tạm giảm max request, sau đó sửa code giữ dữ liệu. |
| Cạn kết nối database | Giảm tổng HTTP/task/queue worker, kiểm tra transaction chậm và lập ngân sách kết nối cho các thế hệ overlap khi rolling deploy. |
| Session, rate limit hoặc lock không thống nhất | Loại bỏ driver process-local/file và xác minh mọi instance dùng cùng cache/session backend và prefix. |
| Queue job chạy hai lần hoặc timeout | Bảo đảm worker timeout thấp hơn `retry_after`, operation giữ tính idempotent và worker job dài riêng có đủ thời gian drain. |
| Streamed download bị cắt | So sánh idle timeout của ứng dụng, proxy, load balancer và client; kiểm tra lỗi client abort và storage. |
| Reload gây traffic spike | Xác minh `TERM` graceful, readiness gating, CPU/bộ nhớ dự phòng cho các thế hệ overlap và proxy retry policy. |

Khi hành vi bộ nhớ chưa rõ, không liên tục tăng memory limit. Giảm concurrency,
rút ngắn vòng đời worker, cô lập route hoặc queue nghi ngờ và thu thập bằng
chứng theo PID trước.

## 15. Checklist go-live production

- [ ] PHP CLI là 8.5 và bản Swoole stable được ghim đã được xác minh.
- [ ] Composer platform requirement và toàn bộ automated test suite đều pass.
- [ ] Octane private phía sau trusted TLS proxy; `/up` hoạt động qua proxy.
- [ ] Ngân sách worker, task worker, max request, execution time và bộ nhớ đã đo.
- [ ] Cache, session, lock, rate limit, queue và scheduler overlap dùng shared store.
- [ ] Storage public và private có thể được mọi instance truy cập.
- [ ] Giới hạn upload Nginx, Swoole, PHP và module đồng bộ.
- [ ] Các nhóm queue và long polling chạy ngoài Octane và recycle an toàn.
- [ ] Supervisor drain graceful và tự động thay thế mọi process.
- [ ] Deploy reload và rollback đều đã diễn tập dưới traffic.
- [ ] Soak test vượt nhiều thế hệ worker mà không rò trạng thái hay tăng RSS vô hạn.
- [ ] Workload xấu nhất của Hotel, KYC và HRM đáp ứng giới hạn năng lực rõ ràng.
- [ ] Dashboard và cảnh báo có worker PID/RSS, latency, lỗi, DB, cache và queue lag.
- [ ] Log và trace đã được kiểm tra để không chứa credential và dữ liệu module bí mật.

## 16. Tài liệu tham khảo

- [Laravel 13 Octane](https://laravel.com/docs/13.x/octane)
- [Laravel 13 deployment](https://laravel.com/docs/13.x/deployment)
- [Laravel 13 queues](https://laravel.com/docs/13.x/queues)
- [Laravel 13 task scheduling](https://laravel.com/docs/13.x/scheduling)
- [Swoole PECL package and stable releases](https://pecl.php.net/package/swoole)
