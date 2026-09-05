server {
    listen 80;
    server_name r.nayanovaacademy.ru;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name r.nayanovaacademy.ru;

    ssl_certificate     /etc/ssl/certs/nayanovaacademy.ru/cert.pem;
    ssl_certificate_key /etc/ssl/private/nayanovaacademy.ru/key.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384;
    ssl_prefer_server_ciphers off;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 1d;
    add_header Strict-Transport-Security "max-age=63072000; includeSubDomains; preload" always;
    add_header X-Frame-Options "DENY" always;
    add_header X-Content-Type-Options "nosniff" always;

    root /var/www/r.nayanovaacademy.ru/public;
    index index.html index.php;

    access_log /var/log/nginx/r.nayanovaacademy.ru.access.log;
    error_log  /var/log/nginx/r.nayanovaacademy.ru.error.log;

    location / {
        try_files $uri $uri/ =404;
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
        expires 1h;
        add_header Cache-Control "public, no-cache";
        access_log off;
    }

    location ^~ /src/ {
        deny all;
        access_log off;
        log_not_found off;
    }

    location ~ /\. {
        deny all;
        access_log off;
        log_not_found off;
    }
}
