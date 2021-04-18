FROM php:8.1-cli

WORKDIR /app

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

RUN apt-get update && \
    apt-get -y install git libcurl4-openssl-dev libonig-dev zlib1g-dev libzip-dev

RUN docker-php-ext-install curl mbstring zip


RUN apt-get -y install memcached libmemcached11 libmemcached-dev

RUN pecl install memcached

RUN docker-php-ext-enable memcached


RUN apt-get -y install libxml2-dev
RUN docker-php-ext-install simplexml xmlwriter

RUN docker-php-source delete && apt-get autoremove --purge -y && apt-get autoclean -y && apt-get clean -y

STOPSIGNAL SIGKILL

CMD tail -f /var/log/*.log -n 2