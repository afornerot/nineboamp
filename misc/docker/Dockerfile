FROM reg.cadoles.com/envole/nineapache:8.5

USER root

RUN echo "* * * * * php /app/bin/console app:cron >> /app/var/log/cron.log 2>&1" > /crontab \
 && chmod g+w /crontab

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
