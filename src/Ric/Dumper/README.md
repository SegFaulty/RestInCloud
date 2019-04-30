# DUMPER

a command line tool to store different type of resources (like mysql, redis, dirs) in a (compressed, encrypted) file
and restore it vice versa

dump:

	#> dumper dump mysql /myLocalDatabase /data/backup/myLocalDatabase.sql.bz2 

restore:

	#> dumper restore mysql /myLocalDatabase /data/backup/myLocalDatabase.sql.bz2 

the idea is the restore command has the same parameters (and order) as the dump command you only have to replace `dump` with `restore`  


supported resources:
* STDIN / STDOUT
* file
* dir
* redis instance
* mysql database
* influxdb instance or single database

the file can optionally be:
* compressed (default bz2, fast lzop or hard with xz)
* encrypted (aes-256, or asymmetrically (not ready yet))
* rotated (multi version as "config.sql.1" etc.)
* with read/write-permission for current user only (default, see skipUmask)
 
# Usage
dump

	dumper dump {resource} [{dumpFile}]

restore

	dumper restore {resource} {dumpFile}


if _dumpFile_ is omitted or `STDOUT`, the content is piped to stdout

## Resources

### Help STDIN/STDOUT
dump piped content or restore to stdout

	dump std STDIN {dumpFile}

restore  

	restore std STDIN {dumpFile} 

or you can use (same) 

	restore std STDOUT {dumpFile} 

### Help File
only compress and encrypt it

	dump file {filePath} {dumpFile}

restore with optionally filePath, if omitted it will be restored in working dir with dumpFileName

	restore file [{filePath}] {dumpFile}

### Help Dir
will tar a dir to file

	dump dir {dirPath} {dumpFile}

option:
--exclude={pattern} (exclude files/dirs; see tar --exclude;  for multiple patterns use: --exclude="{pattern}|{pattern}|{pattern}"


restore with dirPath

	restore dir {dirPath} {dumpFile}

	

### Help Redis
dump some (or all) redis keys
keyPattern is a "match" pattern for redis command `scan`

	dump redis [{pass}@]{server}[:{port}][/{keyPattern}] {dumpFile}

if restore is called with keyPattern, it will delete all matching keys before restore

	restore redis [{pass}@]{server}[:{port}][/{keyPattern}] {dumpFile}

without keyPattern, it only sets the keys

	restore redis [{pass}@]{server}[:{port}] {dumpFile}


default port: redis-default port

### Help Mysql
dump mysql db / tables / sql (based on mysqldump)

	dump mysql [{user}:{pass}@][{server}[:{port}]]/{dataBase}[/{tableNamePattern}] {dumpFile} 
	
* use `--mysqlDefaultFile=/etc/mysql/debian.cnf` in ini style for host, user, password, database  (needs read privileges) see http://dev.mysql.com/doc/refman/5.5/en/option-files.html#option_general_defaults-file
* default port: mysql-default port
* default server: localhost
* default user: current use (on unbutu root needs no password, so its pretty easy to use)
* tableNamePattern: list of tables: t1,t2,t3 or with wildcard configTable,dataTable1,dataTable*  or exclude with "-" e.g.: dump mysql mysql.sql.bz2 "/mysql/*,-help*,-events"
* table patterns are processed in list order so thats correct: /mysql/*,-event  that will not work as intented: /mysql/-event,*
* if wildcard table pattern (*) are given, a mysql command will be executed to get the corresponding tables (even if dumper "--test" option is in effect)

restore works only on database level

	restore mysql [{user}:{pass}@]{server}[:{port}]/{dataBase} {dumpFile}

you can restore the dump as plain sql file as well (makes only sense if the dumpFile ist encrypted and/or compressed):

	restore file dump.sql {dumpFile}


### Help InfluxDb
dump influx instance (meta and all databases) or meta or single database

	dump influx instance|meta|{database} {dumpFile} 

restore works only on database level

see https://docs.influxdata.com/influxdb/v1.1/administration/backup_and_restore/#backing-up-a-database


## Compression

default  compression is bzip2 because, gzip adds a timestamp to compressed file, that's really bad for backup purposes - files with same source content will be different - ever
### Compression Modes
* `--compress=off` to disable compression
* `--compress=fast` to try to compress with lzop -1  - the currently fastest compressor
* `--compress=hard` to try to compress with xz -6 - the slowest but strongest compressor 
* `--compress=extreme` to try to compress with xz -9 -e - the slowest but strongest compressor  with exteme settings 

Examples from http://catchchallenger.first-world.info//wiki/Quick_Benchmark:_Gzip_vs_Bzip2_vs_LZMA_vs_XZ_vs_LZ4_vs_LZO

* source is 445M File
* fast: with lzop (level 1) - target: 161M (36.0%) in 1.6s with 0.7MB
* default:  with bzip2 (level 9) - target: 76M (16.9%) in 1m3s with 7.2MB
* hard: with xz (level 6) - target: 67M (14.9%) in 3m6s with 93MB
* hard: with xz (level 9) - target: 63M (14.0%) in 6m40s with 673MB!!

choose the correct targetFile-suffix for the the used compression mode (example for a directory:)
* default (bzip2) -> bla.tar.bz2
* --compress=fast (lzop) -> bla.tar.bz2
* --compress=hard (xz) -> bla.tar.xz
* --compress=extreme (xz) -> bla.tar.xz


## Encryption

### symmetrically (deterministically) (password)
use `--pass=secret` or `--passFile=/root/mysecret.txt` to encrypt the file (symmetrically with openssl (enc -aes-256-cbc)


### asymmetrically (encrypt with public cert, decrypt with private key) (not deterministically) 
* because of a random secret it will output every time called differentd data (not deterministically)
* use `--publicCert=/root/backupPublicCert.pem` to encrypt the file asymetrically with a public certificate 
* use `--privateKey=/root/backupPrivateKey.pem` to decrypt this file

make Cert and PrivateKey: ("-nodes" disabled password for privatekey)

`openssl req -x509 -sha256 -days 10000 -newkey rsa:2048 -subj '/' -keyout backupEncryptionPrivateKey.pem -out backupEncryptionPublicCert.pem -nodes`

show cert content:

`openssl x509 -in backupEncryptionPublicCert.pem -text`

deterministically means: same input data are encrypted to same output data (that is good for backups / deplucation, etc.)

## File Creation Options

* `--force` in dump mode - force overwrite dump file; in restore mode - force overwrite resource
* `--rotate` or `--rotate=4` (default: 3) to rotate the targetFile if already exists (it adds ".1" etc.) 
* `--skipUmask` skip the default umask of 0077 (this gives only the current user read/write acces to the target-file) 

## Global options

* `--test` only show commands, don't execute 
* `--prefix=pref_` will added to dump file but will not add to restored resources (dir, file)   
	* dump: `dump mysql.cnf mysql.cnf --prefix=server0815-`
	* dump-file: `server0815-mysql.cnf`  
	* restore: `restore mysql.cnf mysql.cnf --prefix=server0815-`
* `--verbose` show more infos
