# Installation

checkout and copy configs

    git clone https://github.com/SegFaulty/RestInCloud.git
    cd RestInCloud
    cp config/example-config.json config/config.json
    cp deployment/apache/example-nginx-ric.conf config/nginx-ric.conf

optionally: create log path

	mkdir -p var/logs

create data dir an set permissions

	mkdir -p var/data
	chown www-data.www-data var/ -R


change: tokens, path, quota

    vi config/config.json

change: host

	vi config/nginx-ric.conf
	
install nginx and php5

	sudo apt-get install nginx php5-fpm

Generate ssl key combination (WITHOUT A PASSPHRASE)
	
	openssl genrsa -des3 -out server.key 1024
	openssl req -new -key server.key -out server.csr
	cp server.key server.key.org
    openssl rsa -in server.key.org -out server.key
	openssl x509 -req -days 365 -in server.csr -signkey server.key -out server.crt
	mv server.key config/
	mv server.crt config/
	rm server.csr server.key.org

activate and restart apache, maybe it is /etc/apache2/sites-enabled (ubuntu)

    cd /etc/nginx/sites-enabled
    ln -s /home/www/RestInCloud/config/nginx-ric.conf
    service nginx restart
	

## Ubuntu

    sudo apt-get install php5-curl

in /etc/apache2/apache2.conf

	<Directory /home/core/RestInCloud/apache/html/>
		   Options Indexes FollowSymLinks
		   AllowOverride None
		   Require all granted
	</Directory>

no default page: in /etc/apache2/sites-enabled/000-default.conf after "DocumentRoot /var/www/html"

    <Directory /var/www/html>
        Options FollowSymLinks MultiViews
        Order deny,allow
        Deny from all
    </Directory>

activate mod_rewrite:

    /etc/apache2/mods-enabled$ sudo ln -s ../mods-available/rewrite.load

@todo aber auch das reicht noch nicht