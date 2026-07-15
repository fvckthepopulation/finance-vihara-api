FROM php:8.4-cli-alpine

RUN apk add --no-cache \
  git \
  unzip \
  libzip-dev \
  libpq-dev \
  oniguruma-dev \
  icu-dev \
  libpng-dev \
  libjpeg-turbo-dev \
  freetype-dev \
  autoconf \
  g++ \
  make \
  && docker-php-ext-configure gd --with-freetype --with-jpeg \
  && docker-php-ext-install \
    pdo_pgsql \
    pgsql \
    mbstring \
    bcmath \
    intl \
    gd \
    zip \
  && apk del autoconf g++ make

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Raise PHP upload limits (default 2M/8M drops most phone photos before Laravel
# can validate them — breaks Tukar Faktur / attachment uploads).
COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/zz-uploads.ini

WORKDIR /var/www/html

COPY . .

RUN if [ -f composer.json ]; then composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader; fi

EXPOSE 80

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=80"]