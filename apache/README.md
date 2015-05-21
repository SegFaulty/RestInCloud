# Installation

checkout and copy configs

    git clone https://github.com/SegFaulty/RestInCloud.git
    cd RestInCloud/apache
	chown www-data.www-data -R html
	chown www-data.www-data ../var/data/ -R
    cp config__dist__.json config.json
    cp ric-apache-host__dist__.conf ric-apache-host.conf

change: tokens, path, quota

    vi config.json

change: host, paths

	vi ric-apache-host.conf

activate and restart apache, maybe it is /etc/apache2/sites-enabled (ubuntu)

    cd /etc/apache/sites-enabled
    ln -s /home/www/RestInCloud/apache/ric-apache-host.conf
    apachectl restart



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