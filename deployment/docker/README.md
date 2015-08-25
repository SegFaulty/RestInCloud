## INSTALL
	login to your docker host

	git clone https://github.com/SegFaulty/RestInCloud.git
	cd RestInCloud/deployment/docker
	cp example-config.json config.json

# create volumeDir

	sudo mkdir -p /var/ric3777
	sudo chmod 777 /var/ric3777
    # change hostPort and tokens, empty hostPort means autoDetection at the first request
    vi config.json
    ./docker.sh

## UPDATE to new version, with same config

	cd RestInCloud/
	git pull
	cd RestInCloud/deployment/docker
	sh docker.sh



## debug run
* docker run -d -p 3070:3070 -v /var/ric3070:/var/ric/ ric-server
* browse to: http://serverIp:3070/?info
* then: http://serverIp:3070/?info&token=admin (use your adminToken)
* help: http://serverIp:3070

