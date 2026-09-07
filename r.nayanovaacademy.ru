server {
    listen 80;
    server_name r.nayanovaacademy.ru;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name r.nayanovaacademy.ru;

    # Не раскрываем версию nginx в заголовке Server и на страницах ошибок
    server_tokens off;

    ssl_certificate     /etc/ssl/certs/nayanovaacademy.ru/cert.pem;
    ssl_certificate_key /etc/ssl/private/nayanovaacademy.ru/key.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384;
    ssl_prefer_server_ciphers off;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 1d;
    # OCSP stapling отключён: в сертификате нет OCSP-URL в AIA, nginx
    # выдавал warning "ssl_stapling ignored". Если когда-нибудь перевыпустите
    # сертификат у CA с OCSP-респондером — верните три строки ниже:
    # ssl_stapling on;
    # ssl_stapling_verify on;
    # resolver 77.88.8.8 77.88.8.1 valid=300s;
    add_header Strict-Transport-Security "max-age=63072000; includeSubDomains" always;
    add_header X-Frame-Options "DENY" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'" always;

    gzip on;
    gzip_types text/plain text/css application/json application/javascript image/svg+xml;
    gzip_min_length 1024;
    gzip_vary on;

    root /var/www/r.nayanovaacademy.ru/public;
    index index.html index.php;

    access_log /var/log/nginx/r.nayanovaacademy.ru.access.log;
    error_log  /var/log/nginx/r.nayanovaacademy.ru.error.log;

    location / {
        try_files $uri $uri/ =404;
    }

    # Блокировки должны идти ДО location ~ \.php$ — regex-локации nginx
    # проверяются в порядке объявления, иначе cron-скрипты исполнялись бы
    # через PHP-FPM по прямому запросу из веба.
    location ^~ /src/ {
        deny all;
        access_log off;
        log_not_found off;
    }

    location ^~ /cron_ {
        deny all;
        access_log off;
        log_not_found off;
    }

    location ~ /\. {
        deny all;
        access_log off;
        log_not_found off;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 60s;
        fastcgi_send_timeout 60s;
    }

    location ~* \.(css|js|png|jpg|jpeg|gif|ico|svg|webp|woff|woff2|ttf|eot)$ {
        # Версионирование через ?v= в HTML — можно кэшировать агрессивно
        # ВАЖНО: при наличии add_header на уровне location заголовки сервера
        # НЕ наследуются — дублируем security-заголовки здесь
        add_header Cache-Control "public, max-age=604800";
        add_header Strict-Transport-Security "max-age=63072000; includeSubDomains" always;
        add_header X-Frame-Options "DENY" always;
        add_header X-Content-Type-Options "nosniff" always;
        add_header Referrer-Policy "strict-origin-when-cross-origin" always;
        add_header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'" always;
        access_log off;
    }

    # admin.php: импорт может идти дольше 60 секунд (несколько источников,
    # медленный Яндекс) — увеличенный таймаут FPM
    location = /admin.php {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300s;
        fastcgi_send_timeout 300s;
    }
}
