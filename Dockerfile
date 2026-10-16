FROM php:8.3-apache

RUN a2enmod rewrite headers \
 && sed -ri 's#AllowOverride None#AllowOverride All#g' /etc/apache2/apache2.conf \
 && echo 'ServerTokens Prod' > /etc/apache2/conf-enabled/zz-yt.conf \
 && echo 'ServerSignature Off' >> /etc/apache2/conf-enabled/zz-yt.conf

WORKDIR /var/www/html
COPY --chown=www-data:www-data . /var/www/html
RUN rm -rf .git Dockerfile docker-compose.yml \
 && mkdir -p data && chown -R www-data:www-data data

# Zip önbelleği ve site durumu burada; kalıcı volume bağlayın.
VOLUME ["/var/www/html/data"]

EXPOSE 80
HEALTHCHECK --interval=60s --timeout=5s CMD php -r 'exit(@file_get_contents("http://127.0.0.1/v1/health") ? 0 : 1);'
