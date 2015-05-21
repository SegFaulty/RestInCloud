# Installation

checkout and copy configs

    git clone https://github.com/SegFaulty/RestInCloud.git
    cd RestInCloud
	chown www-data.www-data -R html
    cp config__dist__.json config.json
    cp ric-apache-host__dist__.conf ric-apache-host.conf

change: tokens, path, quota

    vi config.json

change: host

	vi ric-apache-host.conf

activate and restart apache

    cd /etc/apache/sites-enabled
    ln -s /home/www/RestInCloud/apache/ric-apache-host.conf
    apachectl restart
