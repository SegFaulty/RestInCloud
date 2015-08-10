FROM nginx:1.9.3

COPY ./RestInCloud/ /usr/src/RestInCloud/

COPY config.json /usr/src/RestInCloud/config/

COPY nginx-ric.conf /etc/nginx/sites-enabled/

RUN mkdir -p /var/ric \
    && chmod 777 /var/ric

CMD ["/usr/sbin/nginx"]