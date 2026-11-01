# Use the php:8.3-fpm-alpine base image
FROM php:8.3-fpm-alpine

# Set working directory to /var/www/html
WORKDIR /var/www/html

# Update packages and install dependencies
RUN apk upgrade --no-cache && \
    apk add --no-cache dumb-init shadow sqlite-dev libpng libpng-dev libjpeg-turbo libjpeg-turbo-dev freetype freetype-dev curl autoconf libgomp icu-dev icu-data-full nginx dcron tzdata libzip-dev sqlite libwebp-dev && \
    docker-php-ext-install pdo pdo_sqlite calendar && \
    docker-php-ext-enable pdo pdo_sqlite && \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp && \
    docker-php-ext-install -j$(nproc) gd intl zip

# Upgrade again in a layer of its own so security fixes land on every build.
# The layer above only reruns when its text or the base image changes, so the
# build cache can otherwise ship packages that Alpine has since patched.
# Pass a new APK_CACHE_BUST value to skip the cache here.
ARG APK_CACHE_BUST
RUN echo "apk cache bust: ${APK_CACHE_BUST}" && apk upgrade --no-cache

# Copy your PHP application files into the container
COPY . .

# Copy Nginx configuration
COPY nginx.conf /etc/nginx/nginx.conf
COPY nginx.default.conf /etc/nginx/http.d/default.conf

# Remove nginx conf files from webroot
RUN rm -rf /var/www/html/nginx.conf && \
    rm -rf /var/www/html/nginx.default.conf

# Copy the custom crontab file
COPY cronjobs /etc/cron.d/cronjobs

# Convert the line endings, allow read access to the cron file, and create cron log folder
RUN dos2unix /etc/cron.d/cronjobs && \
    chmod 0644 /etc/cron.d/cronjobs && \
    /usr/bin/crontab /etc/cron.d/cronjobs && \
    mkdir /var/log/cron && \
    chown -R www-data:www-data /var/www/html && \
    chmod +x /var/www/html/startup.sh && \
    echo 'pm.max_children = 15' >> /usr/local/etc/php-fpm.d/zz-docker.conf && \
    echo 'pm.max_requests = 500' >> /usr/local/etc/php-fpm.d/zz-docker.conf && \
    printf 'upload_max_filesize = 256M\npost_max_size = 256M\n' > /usr/local/etc/php/conf.d/wallos-uploads.ini

# Expose port 80 for Nginx
EXPOSE 80

ENTRYPOINT ["dumb-init", "--"]

# Requires docker engine 25+ for the --start-interval flag
HEALTHCHECK --interval=2m --timeout=2s --start-period=20s --start-interval=5s --retries=3 \
    CMD ["curl", "-fsS", "http://127.0.0.1/health.php"]

# Start both PHP-FPM, Nginx
CMD ["/var/www/html/startup.sh"]
