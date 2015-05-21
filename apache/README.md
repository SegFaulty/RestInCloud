# Installation

    git clone https://github.com/SegFaulty/RestInCloud.git
    cd RestInCloud

change: tokens, path, quota
    cp config__dist__.json config.json
    vi config.json

change: host
    cp ric-apache-host__dist__.conf ric-apache-host.conf
	vi ric-apache-host.conf
    cd /etc/apache/sites-enabled
    ln -s /home/www/RestInCloud/apache/ric-apache-host.conf
    apachectl restart
