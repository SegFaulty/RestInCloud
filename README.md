# RestInCloud

simple restfully dockerized distributed open source cloud backup server ;-)

## Help

 * GET http://my.coldstore.server.de/?help - show this help
 * GET http://my.coldstore.server.de/?list - list all files ... &pattern=~regEx~i&limit=100&start=100&showDeleted=1 (ordered by random!)
 * GET http://my.coldstore.server.de/?listDetails -  list all files with details - parameters like ?list
 * GET http://my.coldstore.server.de/?info - show server infos (and quota if set)

 * PUT http://my.coldstore.server.de/error.log - upload a file to the store
   - use ?timestamp=1422653.. to set correct modificationTime [default:requestTime]
   - use &retention=last3 to select the backup retention strategy [default:last3]
   - use &noSync to suppress syncronisation to replication servers (used for internal sync)
   - retention strategies (versions sorted by timestamp):
{retentionList}
   - with curl:
     curl -X PUT --upload /home/www/phperror.log http://my.coldstore.server.de/error.log
     curl -X PUT --upload "/home/www/phperror.log" "http://my.coldstore.server.de/error.log&retention=last7&timestamp=1429628531"

 * POST http://my.coldstore.server.de/error.log?sha1=23423ef3d..&timestamp=1422653.. - check and refresh a file
   - use &noSync to suppress syncronisation to replication servers (used for internal sync)
   - check if version exists and updates timestamp, no need to upload the same version
   - returns 1 if version was updated, 0 if version not exists

 * POST http://my.coldstore.server.de/error.log  ?action=delete  - delete a file !! Attention if version is omitted, ALL Versions will be deleted (Files are marked for deletion, purge will delete them)
 * DELETE http://my.coldstore.server.de/error.log   - delete a file !! Attention if version is omitted, ALL Versions will be deleted (Files are marked for deletion, purge will delete them)


 * GET http://my.coldstore.server.de/error.log - download a file (etag and lastmodified supported)
 * GET http://my.coldstore.server.de/error.log?&version=13445afe23423423 - version selects a specific version, if omitted the latest version is assumed (must not: ...error.log?version... )
 * GET http://my.coldstore.server.de/error.log?list - show all (or &limit) versions for this file; (ordered by latest); &showDeleted=1 to include files marked for deletion
 * GET http://my.coldstore.server.de/error.log?head - show first (10) lines of file
 * GET http://my.coldstore.server.de/error.log?head=20 - show first n lines of the file
 * GET http://my.coldstore.server.de/error.log?size - return the filesize
 * GET http://my.coldstore.server.de/error.log?grep=EAN:.*\d+501 - scan the file for this (regex) pattern
 * GET http://my.coldstore.server.de/error.log?verify&sha=1234ef23&minSize=40000&minReplicas=2&minTimestamp=14234234
    - verify that the file (1) exists, (2) sha1, (3) size >40k [default:1], (4) fileTime>=minTimestamp [default:8d], (5) min 2 replicas (3 files) [default:0]
    - returns json result with status: OK/WARNING/CRITICAL, a msg and fileInfo

 * check php Server.php for commandline (purge)

 admin Commands:
 * addServer=s1.cs.io:3723 - add Server to local list,
 * removeServer=s1.cs.io:3723 - remove Server from local list
 * removeServer=all - remove all Servers from local list

 auth (only as parameter supported yet)
 * use &token=YourAdminToken to authenticate as admin (e.g. for info command)

## License

The MIT License (MIT)