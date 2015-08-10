#!/bin/bash


# make volume dirs
mkdir -p /var/ric3777
# todo find a better way for volume permissions
chmod 777 /var/ric3777

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

docker run -d -p 3777:80 -v /var/ric3777:/var/ric/ ric-server