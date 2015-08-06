# RestInCloud

simple restfully dockerized distributed open source cloud backup server ;-)

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
   - use &noSync to suppress syncronisation to replication servers (used for internal sync)
   - check if version exists and updates timestamp, no need to upload the same version
   - returns 1 if version was updated, 0 if version not exists

 * POST http://ric1.server/error.log  ?action=delete  - delete a file !! Attention if version is omitted, ALL Versions will be deleted (Files are marked for deletion, purge will delete them)
 * DELETE http://ric1.server/error.log   - delete a file !! Attention if version is omitted, ALL Versions will be deleted (Files are marked for deletion, purge will delete them)


 * GET http://ric1.server/error.log - download a file (etag and lastmodified supported)
 * GET http://ric1.server/error.log?&version=13445afe23423423 - version selects a specific version, if omitted the latest version is assumed (must not: ...error.log?version... )
 * GET http://ric1.server/error.log?list - show all (or &limit) versions for this file; (ordered by latest); &showDeleted=1 to include files marked for deletion
 * GET http://ric1.server/error.log?head - show first (10) lines of file
 * GET http://ric1.server/error.log?head=20 - show first n lines of the file
 * GET http://ric1.server/error.log?size - return the filesize
 * GET http://ric1.server/error.log?grep=EAN:.*\d+501 - scan the file for this (regex) pattern
 * GET http://ric1.server/error.log?check&minSize=40000&minReplicas=2&minTimestamp=14234234&sha=1234ef23
    - check that the file
    - (1) exists,
    - (2) size >40k [default:1],
    - (3) fileTime>=minTimestamp [default:8d],
    - (4) min 2 replicas (3 files) [default:max(1,count(servers)-1)]
    - (5) sha1, (if sha give, the size is irrelevant)
    - returns json result with status: OK/WARNING/CRITICAL, a msg and fileInfo

 * check php Server.php for commandline (purge)

 admin Commands:
 * action=addServer=s1.cs.io:3723 - add Server to local list,
 * removeServer=s1.cs.io:3723 - remove Server from local list
 * removeServer=all - remove all Servers from local list
 * joinCluster=s2.cs.io - join to existing cluster (or join a single node and create a cluster)
 * leaveCluster - leave a cluster (send removeServer=self to all cluster nodes an clear my servers list)
 * removeFromCluster=s3.cs.io - kick a server from the servers list of all known nodes (use this if the server is unresponsive and you can't send a leaveCluster)


 auth (only as parameter supported yet)
 * use &token=YourAdminToken to authenticate as admin or writer or reader (e.g. for info command)

## License

The MIT License (MIT)