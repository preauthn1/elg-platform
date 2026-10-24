#!/bin/bash
echo "Hello, World!"
apk update
apk add openssh-client
apk add --update bash
apk add --update --no-cache sshpass
echo "Hello, World2!"
eval $(ssh-agent -s)
mkdir -p ~/.ssh
chmod 600 ~/.ssh

ssh-keyscan 0.0.0.0
ssh-keyscan 0.0.0.0 >> ~/.ssh/known_hosts

sshpass -pdI20tgkd ssh upuser@0.0.0.0 "docker version; exit"
sshpass -pdI20tgkd ssh upuser@0.0.0.0 "cd /data/servers/fake/seo-site; chmod +x build-scripts/build.sh; nohup ./build-scripts/build.sh > build.log < /dev/null &"
