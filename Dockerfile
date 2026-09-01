FROM php:8.3-cli

COPY . /app
WORKDIR /app

# Die Daten liegen absolut unter /data – außerhalb des Document-Roots /app und
# damit über das Web gar nicht erreichbar. Ist dort ein Volume eingehängt,
# überleben die Kommentare einen Neustart.
ENV SP_DATA_DIR=/data
RUN mkdir -p /data/ip

EXPOSE 10000

# Vor dem Start aufräumen, dann den Server mit mehreren Workern starten.
# Alle Anfragen laufen über router.php und damit über die Traffic-Sperre.
ENV PHP_CLI_SERVER_WORKERS=8
CMD ["sh", "-c", "php /app/cleanup.php; exec php -S 0.0.0.0:10000 -t /app /app/router.php"]
