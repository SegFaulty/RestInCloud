#!/bin/bash

# remove old
rm -rf tmpBuild
mkdir -p tmpBuild
cp Dockerfile tmpBuild/
cp php.ini tmpBuild/
cp config.json tmpBuild/
cd tmpBuild

git clone https://github.com/SegFaulty/RestInCloud.git

docker build -t ric-server .

echo "docker run -d -p 3070:3070 ric-server"
echo "or for debuging:"
echo "docker run -it -p 3070:3070 ric-server /bin/bash"
echo "then point your browser to http://containerIp:3070/"
echo "change config.json and rerun to inject a config"