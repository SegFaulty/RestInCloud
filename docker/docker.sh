#!/bin/bash

# remove old
rm -rf tmpBuild
mkdir -p tmpBuild
cp Dockerfile tmpBuild/
cp php.ini tmpBuild/
cd tmpBuild

git clone https://github.com/SegFaulty/RestInCloud.git

docker build .

echo "docker run -it -p 3070:3070 [ConId] /bin/bash"
echo "then opne your browser http://ip:3070/"