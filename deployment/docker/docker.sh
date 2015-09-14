#!/bin/bash

# remove old
rm -rf tmpBuild
mkdir -p tmpBuild
cp Dockerfile tmpBuild/
cp config.json tmpBuild/
cd tmpBuild

git clone https://github.com/SegFaulty/RestInCloud.git

docker build -t ric-server .

# inject hostname from docker host server into the container
# core@nginx ~/docker-nginx-base $ docker run -e HOST_HOSTNAME=`hostname` -ti nginx-base /bin/bash
# root@fe268098920a:/# echo $HOST_HOSTNAME

docker rm ric-server-3777
docker run -d -p 3777:80 -v /var/ric3777:/var/ric/ --name ric-server-3777 ric-server
