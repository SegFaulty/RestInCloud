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
* ric check - check if a resource is valid backuped
* ric list - all versions of a resource
* ric restore - restore a backuped resource
* ric delete - delete a resource
* ric admin - manage RestInCloud Server and Cluster

use ric help {command} for command details

### global options

you can define every option as environment variable with prefix "ric" (server -> ricServer)

* --verbose show debug details default: false
* --quite don't print anything except failures default: false
* --auth {token}  default: ENV ricAuth -> ''
* --authFile {tokenFilePath} read auth from file default: ENV ricAuthFile -> ''
* --server RicServer default: ENV ricServer -> ''
* --prefix prefix all target names default: ENV ricPrefix -> ''

## Help backup
    ric backup {resource} [{targetFileName}] [options]

    ric backup /home/www/ric/config/
    ric backup /home/www/ric/config/ testService_host1_config.tar.gz --retention=last7

backups the config dir (as tar.gz) with last7 versions
procedure:
* detect resource type (file, dir, mysql, redis ..) and make a file of it
* encrypt the file with salt(based on targetFileName) and (optionally) password
* (optionaly check minSize)
* refresh this file with post request
* if failed store file with put request
* check the file (sha1, minSize, minReplicas)

* use "STDIN" as resource string to backup the piped content
*  useless example to backup the your hard-disk state for a year:
*  df -h | ric backup STDIN partions.txt --retentions=365d


### backup options

* --pass Password
* --passFile {passFilePath} read pass from file default: ENV ricPassFile -> ''
* --retention default: last3
* --timestamp as int or 'now' or 'file' default: now
* --minReplicas default: max(1, count(servers)-1)
* --minSize default: 1
* --prefix

## Help check

    ric check testService_host1_config.tar.gz --minSize=100000 --minTimestamp=-3600

### check options

* --minTimestamp 123131231  or -86400 (see it as maxAge)
* --minSize 23423
* --sha1
* --minReplicas 3
* --prefix

* --sic aktivere sic (nur nötig wenn keine andere sic option)
* --sicChannel default --target
* --sicServer default ENV: sicServer
// setzt sic auf critical, if verified failed


## Help restore

	ric restore {fileName} [{localResource}] [options]

    ric restore hostname%??%??homewww/ric/config.tar.gz /tmp/restore/

### restore options

* --pass Password
* --overwrite   overwrite existing resource
* --prefix

 if --prefix is set, the restored file will not contains the prefix!

## Help list

    ric check /home/www/ric/config/

## Help delete

    ric delete {fileName} {version}

    use version: "all" to delete all versions of {fileName}

    ric delete error.config all
    ric delete error.config 8aaa6c7bd96811293a2879ed45879b3cf5e4165b
### delete options

* --prefix

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




