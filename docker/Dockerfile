FROM php:5.5-cli

COPY ./RestInCloud /usr/src/

COPY config.json /usr/src/RestInCloud/src/Ric/Server/

RUN mkdir -p /tmp/cs \
    && chmod 777 /tmp/cs

WORKDIR /usr/src/RestInCloud/src/Ric/Server/

CMD php -S 0.0.0.0:3070 -t /tmp/cs Server.php
