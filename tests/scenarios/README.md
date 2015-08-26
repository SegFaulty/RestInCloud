# build a test cluster

we build a test cluster with 4 ric-servers to check the scenarios

login to your docker host and then

## inital setup

	git clone https://github.com/SegFaulty/RestInCloud.git
	# copy scenarios-config.json with empty hostPort (for self initializing at the first request)
	cp RestInCloud/tests/scenarios/scenarios-config.json RestInCloud/deployment/docker/config.json
	cd RestInCloud/deployment/docker
	sudo mkdir -p /var/ric3777
	sudo mkdir -p /var/ric3778
	sudo mkdir -p /var/ric3779
	sudo mkdir -p /var/ric3780
	sudo chmod 777 /var/ric37* -R
	# build and start first server
    ./docker.sh

	# start other servers
	docker run -d -p 3778:80 -v /var/ric3778:/var/ric/ --name ric-server-3778 ric-server
	docker run -d -p 3779:80 -v /var/ric3779:/var/ric/ --name ric-server-3779 ric-server
	docker run -d -p 3780:80 -v /var/ric3780:/var/ric/ --name ric-server-3780 ric-server

	# from your remote server fire the hostPort initializing first requests
	curl -L "46.101.154.98:3777/?health&token=admin"
	curl -L "46.101.154.98:3778/?health&token=admin"
	curl -L "46.101.154.98:3779/?health&token=admin"
	curl -L "46.101.154.98:3780/?health&token=admin"

	# done

	#show the server info check for hostPort
	curl -L "46.101.154.98:3780/?info&token=admin"

## update, after code changes

	docker stop ric-server-3777 ric-server-3778 ric-server-3779 ric-server-3780
	docker rm ric-server-3777 ric-server-3778 ric-server-3779 ric-server-3780
	cd ../.. && git pull && cd deployment/docker && ./docker.sh
	docker run -d -p 3778:80 -v /var/ric3778:/var/ric/ --name ric-server-3778 ric-server
	docker run -d -p 3779:80 -v /var/ric3779:/var/ric/ --name ric-server-3779 ric-server
	docker run -d -p 3780:80 -v /var/ric3780:/var/ric/ --name ric-server-3780 ric-server

