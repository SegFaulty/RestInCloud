# DUMPER

a command line tool to store different type of resources (like mysql, redis, dirs) in a (compressed, encrypted) file
and restore it vice versa

# Usage
dump

	dumper dump {resource} [{targetFilePath}]

restore

	dumper restore {resource} {sourceFilePath}

if targetFilePath is omitted, the content is piped to stdout

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

	restore dir [{dirPath}] {sourceFilePath}

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
dump mysql db / tables / sql

	dump mysql [{user}:{pass}@]{server}[:{port}]/{dataBase}[/{tableNamePattern}] {targetFilePath}

restore works only on database level

	restore mysql [{user}:{pass}@]{server}[:{port}]/{dataBase} {sourceFilePath}

default port: mysql-default port


## Compression

default compression is bzip2 because, gzip adds a timestamp to compressed file, that's really bad for backup purposes - files with same source content will be different - ever
add `--compressLevel=1` to `--compressLevel=9` to select the compression, default is level 1 (because its usually better then best ratio on gzip)

## Encryption

add `--pass` or `--passFile` to encrypt the file with openssl

## global options

`--prefix` will added to source and target files but will not add to restored resources (dir, file)

