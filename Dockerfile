# PHP + Apache image with everything OcrProcessor.php needs to keep
# working: tesseract-ocr (image OCR) and poppler-utils (pdftotext /
# pdftoppm, for PDF scans). This is what a normal XAMPP install gives
# you "for free" locally -- on Render, we have to install it ourselves.
FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
        tesseract-ocr \
        poppler-utils \
        libzip-dev \
        unzip \
    && docker-php-ext-install pdo pdo_mysql \
    && a2enmod rewrite \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Render assigns a random $PORT at runtime and expects the app to
# listen on it -- these two files use ${PORT} instead of a hardcoded 80.
COPY docker/ports.conf /etc/apache2/ports.conf
COPY docker/000-default.conf /etc/apache2/sites-available/000-default.conf

COPY . /var/www/html/

# uploads/ and temp_scans/ must be writable by the web server user.
# NOTE: on Render's free tier this is NOT persistent storage -- files
# written here can be lost when the service restarts or redeploys.
# Fine for demoing the system; not fine for real production use without
# upgrading to a paid Render disk or wiring in external file storage.
RUN mkdir -p /var/www/html/uploads /var/www/html/temp_scans \
    && chown -R www-data:www-data /var/www/html

ENV PORT=80
EXPOSE 80

CMD ["apache2-foreground"]
