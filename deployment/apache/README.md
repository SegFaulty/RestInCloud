# Installation

checkout and copy configs

    git clone https://github.com/SegFaulty/RestInCloud.git
    cd RestInCloud
    cp config/example-config.json config/config.json
    cp deployment/apache/example-apache-ric-vhost.conf config/apache-ric-vhost.conf

optionally: create log path

	mkdir -p var/logs

create data dir an set permissions

	mkdir -p var/data
	chown www-data.www-data var/ -R


change: tokens, path, quota

    vi config/config.json

change: host, log-location

	vi config/apache-ric-vhost.conf

activate and restart apache, maybe it is /etc/apache2/sites-enabled (ubuntu)

    cd /etc/apache2/sites-enabled
    ln -s /home/www/RestInCloud/config/apache-ric-vhost.conf
    apache2ctl restart



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

	sudo a2enmod rewrite
	
activate mod_ssl

	sudo a2enmod ssl

@todo aber auch das reicht noch nicht