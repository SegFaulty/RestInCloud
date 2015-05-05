#!/bin/bash

# remove old
rm -rf tmpBuild
mkdir -p tmpBuild
cp Dockerfile tmpBuild/
cp config.json tmpBuild/
cd tmpBuild

git clone https://github.com/SegFaulty/RestInCloud.git

docker build -t ric-server .

echo "docker run -d -p 3070:3070 -v /var/ric3070:/var/ric/ ric-server"
echo "read README"

