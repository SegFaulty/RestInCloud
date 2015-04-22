FROM php:5.5-cli

COPY ./RestInCloud/ /usr/src/RestInCloud/

COPY config.json /usr/src/RestInCloud/src/Ric/Server/

RUN mkdir -p /var/ric \
    && chmod 777 /var/ric

WORKDIR /usr/src/RestInCloud/src/Ric/Server/

CMD php -S 0.0.0.0:3070 Server.php
