FROM nginx:1.9.3

RUN mkdir -p /var/ric && \
	chmod 777 /var/ric && \
	rm /etc/nginx/conf.d/default.conf && \

	apt-get -y update && apt-get -y install \
	php5-fpm \
	php5-curl \
	&& \
	rm -rf /var/lib/apt/lists/*

COPY ./RestInCloud/deployment/docker/nginx-ric.conf /etc/nginx/conf.d/
COPY ./RestInCloud/deployment/docker/nginx.conf /etc/nginx/nginx.conf
COPY ./RestInCloud/ /usr/src/RestInCloud/
COPY config.json /usr/src/RestInCloud/config/

CMD service php5-fpm start && nginx