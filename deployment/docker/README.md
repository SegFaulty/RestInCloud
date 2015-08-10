## Build
* login to your docker host

    git clone https://github.com/SegFaulty/RestInCloud.git
    cd RestInCloud/deployment/docker
    # change hostPort and tokens
    vi config.json
    sh docker.sh

## Run
* docker run -d -p 3070:3070 -v /var/ric3070:/var/ric/ ric-server
* browse to: http://serverIp:3070/?info
* then: http://serverIp:3070/?info&token=admin (use your adminToken)
* help: http://serverIp:3070