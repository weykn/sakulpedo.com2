FROM php:8.3-cli

COPY . /app
WORKDIR /app

# Datenverzeichnis anlegen (router.php sperrt den Web-Zugriff darauf).
RUN mkdir -p /app/data/ip

EXPOSE 10000

# Vor dem Start aufräumen, dann den Server mit mehreren Workern starten.
# Alle Anfragen laufen über router.php und damit über die Traffic-Sperre.
ENV PHP_CLI_SERVER_WORKERS=8
CMD ["sh", "-c", "php /app/cleanup.php; exec php -S 0.0.0.0:10000 -t /app /app/router.php"]
