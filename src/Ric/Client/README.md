# RestInCloud Client


## Help global

### commands

* ric help - show help
* ric backup - store a a resource (file/dir/dump) in RestInCloud Backup Server
* ric restore - restore a backuped resource
* ric verify - verify if a resource is valid backuped
* ric delete - delete a resource
* ric admin - configure RestInCloud-Cluster

use ric help {command} for command details

### global options

* --verbose show debug details
* --auth {token}  default: ENV ricAuth
* --server RicServer default: ENV ricServer
* / --host hostname default: ENV hostname (for default targetnames)

## Help backup
    ric backup {resource} [{targetFaileName}] [options]

    ric backup /home/www/ric/config/
    ric backup /home/www/ric/config/ testService_host1_config.tar.gz --retention=last7

backups the config dir (as tar.gz) with last7 versions
ablauf:
* detect resource type (file, dir, mysql, redis ..) and make a file of it
* (optionaly check minFilesize)
* refresh this file with post request
* if failed store file with put request
* verify the file

### backup options

* --retention default: last3
* --timestamp default: call time
* --minReplicas default: max(1, count(servers)-1)
* --minSize default: 1

## Help restore

    ric restore hostname%??%??homewww/ric/config.tar.gz --target=/tmp/restore/

### restore options

* --host hostname default: ENV hostname
* --name zielname im backup default: hostname:encode(source)
* --target local target  (file/dir/database)

## Help verify

    ric verify /home/www/ric/config/ --server=ric.example.com

### verify options

* --host
* --target
* --minTimestamp 123131231
* --minSize 23423
* --sha1
* --minReplicas 3

* --sic aktivere sic (nur nötig wenn keine andere sic option)
* --sicChannel default --target
* --sicServer default ENV: sicServer
// setzt sic auf critical, if verified failed

## Help admin

    ric admin info
    ric admin health
    ric admin addServer {host:port}
    ric admin removeServer {host:port}

## Sic integration

use the shell capabilities, wobei das, kein failed sendet, vielleicht gibts da noch was besseres, pipen oder so

    ric backup /home/www/ric/config/ && sic /RicService/config-backup