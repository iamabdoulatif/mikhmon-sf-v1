FROM php:8.2-cli-alpine

LABEL org.opencontainers.image.title="Mikhmon"
LABEL org.opencontainers.image.description="Mikhmon PHP/Apache container for MikroTik RouterOS"

WORKDIR /var/www/html

COPY . /var/www/html/

RUN find /var/www/html -type d -exec chmod 755 {} + \
    && find /var/www/html -type f -exec chmod 644 {} + \
    && mkdir -p /var/www/html/logs /var/www/html/img /var/www/html/wireguard-configs \
    && chown -R www-data:www-data /var/www/html/logs /var/www/html/img /var/www/html/wireguard-configs

EXPOSE 80

CMD ["php", "-S", "0.0.0.0:80", "-t", "/var/www/html"]
