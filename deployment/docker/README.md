# INSTALL
	login to your docker host

## create volume 
create a dir on docker host as the file storage

	sudo mkdir -p /var/ric3777
	sudo chmod 777 /var/ric3777

## install RestInCloud

	git clone https://github.com/SegFaulty/RestInCloud.git
	cd RestInCloud/deployment/docker
	cp example-config.json config.json

## change config
change hostPort and tokens, empty hostPort means autoDetection at the first request

    vi config.json

## build docker image and start it

    ./docker.sh

## set hostPort by first request
if host is empty in your configuration, ric-server will determine the hostname (and port) by the first request it will receive
so call it from a different server with the public hostname

    curl -L "host2.ric-cluster.com:3778/?health&token=_admin_"


# UPDATE to new version, with same config

	cd ~/RestInCloud/
	git pull
	cd deployment/docker
	# stop and remove old container
	docker stop ric-server-3777
	docker rm ric-server-3777
	sh docker.sh



## debug run
* docker run -d -p 3070:3070 -v /var/ric3070:/var/ric/ ric-server
* browse to: http://serverIp:3070/?info
* then: http://serverIp:3070/?info&token=admin (use your adminToken)
* help: http://serverIp:3070

