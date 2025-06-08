FROM php:8.2-apache
WORKDIR /var/www/html
COPY app/ .
EXPOSE 80
