# CoreOS

## Installation in rescue mode

* reboot in rescue
* login
* cd tmp
* wget --no-check-certificate https://raw.github.com/coreos/init/master/bin/coreos-install
* chmod +x coreos-install
* nano cloud-config.yaml
* /coreos-install -d /dev/sda -c ./cloud-config.yaml -C stable

