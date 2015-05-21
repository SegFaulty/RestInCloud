# RestInCloud Client

## install

    git clone https://github.com/SegFaulty/RestInCloud.git
	cd /usr/local/sbin/
	ln -s /home/www/RestInCloud/src/Ric/Client/ric
	ric help

## Help global

this commandline tool hilft dir resourcen (files, dirs, databases) als file in einem RestInCloud-Cluster zu backupen

aus einer resource wird immer eine datei generiert, diese wird gzipped und encrypted und dann upgeloaded

* wird das password weggelassen, wird die datei immernoch mit einem salt encrypted, kann also nicht im klartext gelesen werden, allerdings kann sie jeder entschluesseln


### commands

* ric help - show help
* ric backup - store a a resource (file/dir/dump) in RestInCloud Backup Server
* ric verify - verify if a resource is valid backuped
* ric list - all versions of a resource
* ric restore - restore a backuped resource
* ric delete - delete a resource
* ric admin - manage RestInCloud Server and Cluster

use ric help {command} for command details

### global options

you can define every option as environment variable with prefix "ric" (server -> ricServer)

* --verbose show debug details
* --auth {token}  default: ENV ricAuth
* --server RicServer default: ENV ricServer
* --prefix prefix all target names default: ENV ricPrefix

## Help backup
    ric backup {resource} [{targetFileName}] [options]

    ric backup /home/www/ric/config/
    ric backup /home/www/ric/config/ testService_host1_config.tar.gz --retention=last7

backups the config dir (as tar.gz) with last7 versions
ablauf:
* detect resource type (file, dir, mysql, redis ..) and make a file of it
* encrypt the file with salt and (optionally) password
* (optionaly check minSize)
* refresh this file with post request
* if failed store file with put request
* verify the file (sha1, minSize, minReplicas)

### backup options

* --pass Password
* --retention default: last3
* --timestamp default: call time
* --minReplicas default: max(1, count(servers)-1)
* --minSize default: 1

## Help verify

    ric verify testService_host1_config.tar.gz --minSize=100000


### verify options

* --minTimestamp 123131231
* --minSize 23423
* --sha1
* --minReplicas 3

* --sic aktivere sic (nur nötig wenn keine andere sic option)
* --sicChannel default --target
* --sicServer default ENV: sicServer
// setzt sic auf critical, if verified failed


## Help restore

	ric restore {fileName} [{localResource}] [options]

    ric restore hostname%??%??homewww/ric/config.tar.gz /tmp/restore/

## restore options

* --pass Password
* --overwrite   overwrite existing resource

## Help list

    ric verify /home/www/ric/config/

## Help delete

    ric delete {fileName} [{version}]

    ric delete error.config

## Help admin

* info - sever info, as admin config included
* health - cluster info, as admin with quota infos and failure details  "OK" / "WARNING" / "CRITICAL"
* list - list all file(name)s
* listDetails - list all files with details incl. all versions
* joinCluster - join/build a cluster
* leaveCluster - disconnect from a cluser and remove from all clusterNodes
* removeFromCluster - remove given server from all clusterNodes
* addServer - add a replication client
* removeServer - remove a replication client

a cluster is a bunch of servers, where all of them are added (addServer) to all servers, every server is a replicant of every server .. u got it

    ric admin list
    ric admin listDetails
    ric admin info
    ric admin health
    ric admin joinCluster {clusterServer}
    ric admin leaveCluster
    ric admin removeFromCluster {server}
    ric admin addServer {server}
    ric admin removeServer {server}

## Sic integration

use the shell capabilities, wobei das, kein failed sendet, vielleicht gibts da noch was besseres, pipen oder so

    ric backup /home/www/ric/config/ && sic /RicService/config-backup