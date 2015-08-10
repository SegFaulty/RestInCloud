## Build
* login to your docker host

    git clone https://github.com/SegFaulty/RestInCloud.git
    cd RestInCloud/deployment/docker
    cp example-config.json config.json
    # create volumeDir
	mkdir -p /var/ric3777
	sudo chmod 777 /var/ric3777
    # change hostPort and tokens
    vi config.json
    sh docker.sh

## Run
* docker run -d -p 3070:3070 -v /var/ric3070:/var/ric/ ric-server
* browse to: http://serverIp:3070/?info
* then: http://serverIp:3070/?info&token=admin (use your adminToken)
* help: http://serverIp:3070