FROM phpdockerio/php56-cli:latest

ENV DEBIAN_FRONTEND noninteractive

WORKDIR /app

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer --1

RUN apt-get update

RUN apt-get -y --force-yes install memcached php5-memcached php5-curl

STOPSIGNAL SIGKILL

CMD tail -f /var/log/*.log -n 2