FROM reg.cadoles.com/envole/nineapache:8.5

USER root

RUN echo "* * * * * php /app/bin/console app:cron >> /app/var/log/cron.log 2>&1" > /crontab \
 && chmod g+w /crontab

ADD --chown=root:root https://github.com/Bornholm/amoxtli/releases/download/v0.17.3/amoxtli_0.17.3_linux_amd64.tar.gz /tmp/amoxtli.tar.gz
RUN tar -xzf /tmp/amoxtli.tar.gz -C /tmp \
 && mv /tmp/amoxtli /usr/local/bin/amoxtli \
 && chmod +x /usr/local/bin/amoxtli \
 && rm -rf /tmp/amoxtli.tar.gz /tmp/LICENSE /tmp/README.md /tmp/docs

RUN apk add --no-cache poppler-utils pandoc

USER apache
WORKDIR /app
COPY --chown=apache:apache . .

RUN composer install --no-interaction --no-scripts
RUN php bin/console assets:install public

RUN mkdir -p /app/uploads \
    && mkdir -p /app/public/uploads/avatar \
    && mkdir -p /app/public/uploads/logo \
    && chmod g+w /app/uploads /app/public/uploads/avatar /app/public/uploads/logo \
    && cp -rf /app/public/medias/logo /app/public/uploads/logo \
    && cp -rf /app/public/medias/avatar /app/public/uploads/avatar

CMD ["/app/misc/script/reconfigure.sh","/etc/apache2/apache2.sh"]
