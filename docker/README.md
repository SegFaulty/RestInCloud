# Build
* login to your docker host
* git clone https://github.com/SegFaulty/RestInCloud.git
* cd RestInCloud
* change config.json
* sh docker.sh

# Run
* docker run -d -p 3070:3070 ric-server
* browse to: http://serverIp:3070/?info

# Cluster
* docker run -d -p 3072:3070 ric-server
* docker run -d -p 3074:3070 ric-server
* connect to servers: http://serverIp:3072/?joinCluster=serverIp:3070&token=admin
* connect to servers: http://serverIp:3074/?joinCluster=serverIp:3070&token=admin

# Upload
* curl -X PUT --upload /home/www/phperror.log "http://serverIp:3070/error.log?&token=writer"
