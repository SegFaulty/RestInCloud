# RestInCloud Client

## install

	cd /usr/local/sbin/
    wget https://raw.githubusercontent.com/SegFaulty/RestInCloud/master/src/Ric/Client/phar/ric.phar
    chmod 755 ric.phar
	ln -s ric.phar ric
	ric help

## Help Summary

backup and restore localFiles in restInCloud Cluster

### commands

* ric help - show help
* ric backup - store a file in RestInCloud Backup Server
* ric check - check if a file is valid backuped
* ric list - all versions of a backupFile
* ric restore - restore a backuped file
* ric delete - delete a backupFile
* ric admin - manage RestInCloud Server and Cluster

use ric help {command} for command details

### global options and configuration

you can define every option as environment variable with prefix "ric" (server -> ricServer)

* --configFile configFilePath (you can define all options in this config file, one per line "option: value" ...
* --verbose show debug details default: false
* --quite don't print anything except failures default: false
* --auth {token}  default: ENV ricAuth -> ''
* --authFile {tokenFilePath} read auth from file default: ENV ricAuthFile -> ''
* --server RicServer default: ENV ricServer -> ''
* --prefix prefix all target/backup names default: ENV ricPrefix -> ''
* --ignoreVersion ignore no matching server version errors

the configuration order is
* use commandline option if present,
* if not use option from config file if given and option present
* if not use ric* environment variable if set
* if not use application default

## Help backup
    ric backup {sourceFilePath} [{targetFileName}] [options]

    ric backup /etc/apache.conf
    ric backup /etc/apache.conf myhost03-apache.conf --retention=last7

backups the config with last7 versions
procedure:
* (optionally) password encrypt the file (salted with targetFileName)
* (optionally) check minSize
* try to refresh this file backup per post request (meta-data only) to the server
* if file not exists as backup or has changed upload the file per put request
* check the file (sha1, minSize, minReplicas)

* use "STDIN" as resource string to backup the piped content
*  useless example to backup the your hard-disk state for a year:
*  df -h | ric backup STDIN partions.txt --retentions=365d


### backup options

* --pass Password
* --passFile {passFilePath} read pass from file default: ENV ricPassFile -> ''
* --retention default: last3
* --timestamp as int or 'now' or 'file' default: file (modification time of source file)
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

* --sic send sic (nur nötig wenn keine andere sic option)
* --sicChannel default --target
* --sicServer default ENV: sicServer
// setzt sic auf critical, if verified failed


## Help restore

	ric restore {backupFileName} [{localFilePath}] [options]

    ric restore hostname03-www-files.tar.gz /tmp/

### restore options

* --version (sha1) for restore an older version
* --pass Password (use --pass "" (empty) to restore file to restore files from version 0.1
* --passFile {passFilePath} read pass from file default: ENV ricPassFile -> ''
* --overwrite   overwrite existing resource
* --prefix

 if --prefix is set, it is added to the backupFileName, the restored file will not contains the prefix!

## Help list

    ric list {fileName}

## Help delete

    ric delete {fileName} {version}

    use version: "all" to delete all versions of {fileName}

    ric delete error.config all
    ric delete error.config 8aaa6c7bd96811293a2879ed45879b3cf5e4165b
### delete options

* --prefix

## Help admin

* list - list all file(name)s; or all matching fileNames if (regex-)pattern given e.g. "admin list /mysql-.*/i"
* inventory - list files with version and size informations
* info - sever info, as admin config included
* health - cluster info, as admin with quota infos and failure details  "OK" / "WARNING" / "CRITICAL" // command exit status and STDERR supported
* joinCluster - join/build a cluster (join the connected server to an existing cluster (via clusterServer), or build a cluster with the other server)
* leaveCluster - disconnect the server from a cluster and remove from all clusterNodes
* removeFromCluster - remove given server from all clusterNodes
* addServer - add a replication client
* removeServer - remove a replication client
* copyServer - connected server to targetServer
* checkConsistency - check files and version off all servers (optionally only for {pattern} matching files)  // command exit status and STDERR supported

a cluster is a bunch of servers, where all of them are added (addServer) to all servers, every server is a replicant of every server .. u got it

    ric admin list [{pattern}]
    ric admin inventory [{pattern}] [{sortby:[file]|time|versions|size|allsize}]
    ric admin info
    ric admin health
    ric admin joinCluster {clusterServer}
    ric admin leaveCluster
    ric admin removeFromCluster {server}
    ric admin addServer {server}
    ric admin removeServer {server}
    ric admin copyServer {targetServer}
    ric admin checkConsistency [{pattern}]
    ric admin snapshot targetdir




