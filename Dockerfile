# 1. Usamos una imagen oficial de PHP con Apache incluido
FROM php:8.2-apache

# 2. Instalar dependencias del sistema necesarias para extensiones y Composer
RUN apt-get update && apt-get install -y \
    libzip-dev \
    zip \
    unzip \
    git \
    && docker-php-ext-install zip pdo pdo_mysql

# 3. Habilitar el módulo rewrite de Apache (útil para proyectos web)
RUN a2enmod rewrite

# 4. Instalar Composer de forma oficial dentro del contenedor
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 5. Copiar los archivos de nuestro proyecto al servidor Apache
COPY . /var/www/html/

# 6. Decirle a Composer que instale las librerías del proyecto (como el generador de QR)
RUN composer install --no-interaction --optimize-autoloader

# 7. Asegurar los permisos correctos para que Apache lea los archivos
RUN chown -R www-data:www-data /var/www/html/

# 8. Render expone un puerto dinámico mediante la variable $PORT
CMD sed -i 's/Listen 80/Listen '"${PORT:-80}"'/g' /etc/apache2/ports.conf && apache2-foreground
