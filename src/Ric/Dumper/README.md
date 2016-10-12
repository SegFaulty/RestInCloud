# DUMPER

a command line tool to store different type of resources (like mysql, redis, dirs) in a (compressed, encrypted) file
and restore it vice versa

# Usage
dump

	dumper dump {resource} [{targetFilePath}]

restore

	dumper restore {resource} {sourceFilePath}

if targetFilePath is omitted or `STDOUT`, the content is piped to stdout

## Resource

### File
only compress and encrypt it

	dump file {filePath} {targetFilePath}

restore with optionally filePath, if omitted it will be restored in working dir with sourceFileName

	restore file [{filePath}] {sourceFilePath}

### Dir
will tar a dir to file

	dump dir {dirPath} {targetFilePath}
	
restore with optionally dirPath, if omitted it will be restored in working dir with sourceFileName

	restore dir {sourceFilePath}
	

### Redis
dump some (or all) redis keys
keyPattern is a "match" pattern for redis command `scan`

	dump redis [{pass}@]{server}[:{port}][/{keyPattern}] {targetFilePath}

if restore is called with keyPattern, it will delete all matching keys before restore

	restore redis [{pass}@]{server}[:{port}][/{keyPattern}] {sourceFilePath}

without keyPattern, it only sets the keys

	restore redis [{pass}@]{server}[:{port}] {sourceFilePath}


default port: redis-default port

### Mysql
dump mysql db / tables / sql (based on mysqldump)

	dump mysql [{user}:{pass}@][{server}[:{port}]]/{dataBase}[/{tableNamePattern}] {targetFilePath} 
	
* `--mysqlDefaultFile=/etc/mysql/debian.cnf` in ini style for host, user, password, database  (needs read privileges) see http://dev.mysql.com/doc/refman/5.5/en/option-files.html#option_general_defaults-file
* default port: mysql-default port
* default server: localhost


restore works only on database level

	restore mysql [{user}:{pass}@]{server}[:{port}]/{dataBase} {sourceFilePath}



tableNamePattern: list of tables: t1,t2,t3 or with wildcard configTable,dataTable1,dataTable*


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

deterministically means: same input data are encrypted to same output data (that is good for backups / deplucation, etc.)

## global options

* `--force` in dump mode - force overwrite dump file; in restore mode - force overwrite resource
* `--prefix=pref_` will added to dump file but will not add to restored resources (dir, file)   
	* dump: `dump mysql.cnf mysql.cnf --prefix=server0815-`
	* dump-file: `server0815-mysql.cnf`  
	* restore: `restore mysql.cnf mysql.cnf --prefix=server0815-`
* `--verbose` show more infos
* `--test` only show commands, don't execute 

