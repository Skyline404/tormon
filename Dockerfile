FROM php:8.2-apache

ARG UID=1000
ARG GID=1000

# Создаем группу и пользователя с переданными UID/GID
RUN groupadd -g ${GID} tormon && \
    useradd -u ${UID} -g tormon -m tormon

# Устанавливаем системные зависимости
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    libsqlite3-dev \
    libonig-dev \
    zlib1g-dev \
    libxml2-dev \
    && rm -rf /var/lib/apt/lists/*

# Устанавливаем расширения PHP
RUN docker-php-ext-install curl pdo pdo_mysql pdo_sqlite mbstring xml

# Включаем mod_rewrite
RUN a2enmod rewrite

# Меняем порт Apache на 8080, так как обычный пользователь не может слушать порт 80
RUN sed -i 's/80/8080/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf

# Выдаем права нашему пользователю tormon на системные папки Apache
RUN mkdir -p /var/run/apache2 /var/lock/apache2 /var/log/apache2 \
    && chown -R tormon:tormon /var/www/html /var/run/apache2 /var/lock/apache2 /var/log/apache2

# Настраиваем запуск Apache от имени нового пользователя
RUN echo "export APACHE_RUN_USER=tormon" >> /etc/apache2/envvars \
    && echo "export APACHE_RUN_GROUP=tormon" >> /etc/apache2/envvars

# Переключаемся на пользователя tormon (внутри контейнера мы больше не root!)
USER tormon

COPY . /var/www/html/
