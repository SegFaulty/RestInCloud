# RestInCloud on Docker
tested on coreos

## Build
* login to your docker host
* git clone https://github.com/SegFaulty/RestInCloud.git
* cd RestInCloud/docker
* change config.json
* sh docker.sh

## Run
* docker run -d -p 3070:3070 -v /var/ric3070:/var/ric/ ric-server
* browse to: http://serverIp:3070/?info
* then: http://serverIp:3070/?info&token=admin
* help: http://serverIp:3070

## Upload
* curl -X PUT --upload /home/www/phperror.log "http://serverIp:3070/error.log?&token=writer"

## Test Cluster
* docker run -d -p 3072:3070  -v /var/ric3072:/var/ric/ ric-server
* docker run -d -p 3074:3070  -v /var/ric3074:/var/ric/ ric-server
* connect servers: http://serverIp:3072/?joinCluster=serverIp:3070&token=admin
* connect servers: http://serverIp:3074/?joinCluster=serverIp:3070&token=admin

## File persistence
-v /var/ric3070:/var/ric/
this mounts (and creates) the (host) dir /var/ric3070 to the container
this will survive restarts/reboots and you can play with the files, backup ?! ;-)

## Debugging
for testing u can use it without the volume:
docker run -d -p 3070:3070 ric-server
or for debuging:
docker run -it -p 3070:3070 ric-server /bin/bash
