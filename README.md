# [RestInCloud](http://segfaulty.github.io/RestInCloud/)

simple restfully dockerized distributed open source cloud backup server ;-)


## Client for CLI

if your ric-servers are running, you can use the smart "ric" cli tool to backup etc. and manage the cluster

[Client-README](src/Ric/Client/README.md)


## Help

 * GET http://ric1.server/?help - show this help
 * GET http://ric1.server/?list - list all files ... &pattern=~regEx~i&limit=100&start=100&showDeleted=1 (ordered by random!)
 * GET http://ric1.server/?listDetails -  list all files with details - parameters like ?list
 * GET http://ric1.server/?info - show server infos (and quota if set)

 * PUT http://ric1.server/error.log - upload a file to the store
   - use ?timestamp=1422653.. to set correct modificationTime [default:requestTime]
   - use &retention=last3 to select the backup retention strategy [default:last3]
   - use &noSync to suppress syncronisation to replication servers (used for internal sync)
   - retention strategies (versions sorted by timestamp):
{retentionList}
   - with curl:
     curl -X PUT --upload /home/www/phperror.log http://ric1.server/error.log
     curl -X PUT --upload "/home/www/phperror.log" "http://ric1.server/error.log&retention=last7&timestamp=1429628531"

 * POST http://ric1.server/error.log?sha1=23423ef3d..&timestamp=1422653.. - check and refresh a file
   - checks if version exists and updates timestamp
   - returns 1 if version was updated, 0 if version not exists
   - if 1 is returned, there is no need to upload the same version
   - use &noSync to suppress syncronisation to replication servers (used for internal sync)
   - if &noSync is not set, refresh is also performed on the replication servers (with &noSync)

 * POST http://ric1.server/error.log  ?action=delete  - delete a file !! Attention if version is omitted, ALL Versions will be deleted
 * DELETE http://ric1.server/error.log   - delete a file !! Attention if version is omitted, ALL Versions will be deleted


 * GET http://ric1.server/error.log - download a file (etag and lastmodified supported)
 * GET http://ric1.server/error.log?&version=13445afe23423423 - version selects a specific version, if omitted the latest version is assumed (must not: ...error.log?version... )
 * GET http://ric1.server/error.log?list - show all (or &limit) versions for this file; (ordered by latest); &showDeleted=1 to include files marked for deletion
 * GET http://ric1.server/error.log?check&minSize=40000&minReplicas=2&minTimestamp=14234234&sha=1234ef23
    - check that the file
    - (1) exists,
    - (2) size >40k [default:1],
    - (3) fileTime>=minTimestamp [default:8d],
    - (4) min 2 replicas (3 files) [default:max(1,count(servers)-1)]
    - (5) sha1, (if sha give, the size is irrelevant)
    - returns json result with status: OK/WARNING/CRITICAL, a msg and fileInfo

 admin Commands:
 Post: with parameters: [action: addServer, addServer: s1.cs.io:3723]
 * addServer s1.cs.io:3723 - add Server to local list,
 * removeServer s1.cs.io:3723 - remove Server from local list
 * removeServer all - remove all Servers from local list
 * joinCluster s2.cs.io - join to existing cluster (or join a single node and create a cluster)
 * leaveCluster - leave a cluster (send removeServer=self to all cluster nodes an clear my servers list)
 * removeFromCluster s3.cs.io - kick a server from the servers list of all known nodes (use this if the server is unresponsive and you can't send a leaveCluster)


 auth (only as parameter supported yet)
 * use &token=YourAdminToken to authenticate as admin or writer or reader (e.g. for info command)

 serverVersion
 * use &minServerVersion=1.4.0 to require a minimal ServerVersion - it also acts reverse - if the client major version not matches the server major version - the request is rejected - to protect old clients from doing  mad things

## Usecase

### Backup a Dir

dir to back up: /home/www/configs/
server identification: myServer
encryption password: fooSecret
ricServer: ric1.server.de
ricWriterToke: barSecret

####store the passwords

	echo "fooSecret" > /home/www/ricPassFile.txt
	chmod 600 /home/www/ricPassFile.txt
	echo "barSecret" > /home/www/ricWriterFile.txt
	chmod 600 /home/www/ricPassFile.txt

#### cronjobs (with sic monitoring)

    */5 * * * * /usr/local/sbin/ric backup /home/www/configs/ myServer-configs.tar.bz2 --retention=last7 --passFile=/home/www/ricPassFile.txt --prefix=myServer- --authFile=/home/www/ricWriterFile.txt --server=ric1.server.de 2>&1 >/dev/null | /usr/local/sbin/sic /myServer/ric-backup --STDINasCRITICAL
    */2 * * * * /usr/local/sbin/ric check myServer-configs.tar.bz2 --prefix=myServer- --authFile=/home/www/ricWriterFile.txt --server=ric1.server.de --minTimestamp=-300  2>&1 >/dev/null | /usr/local/sbin/sic /myServer/ric-backup/check --STDINasCRITICAL

#### manual actions

show versions

    ric list myServer-configs.tar.bz2 --prefix=myServer- --authFile=/home/www/ricWriterFile.txt --server=ric1.server.de

    ric check myServer-configs.tar.bz2 --prefix=myServer- --authFile=/home/www/ricWriterFile.txt --server=ric1.server.de --verbose



## License

The MIT License (MIT)
