#!/bin/bash
set -Eeuo pipefail
umask 077
export DEBIAN_FRONTEND=noninteractive
state=/var/lib/beltoft-app
request=${BELTOFT_APP_REQUEST:?}
status() {
  printf '{"request":"%s","state":"installing","step":"%s","updated":"%s"}\n' "$request" "$1" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$state/status.tmp"
  mv "$state/status.tmp" "$state/status.json"
}
if ! grep -qx 'ID=ubuntu' /etc/os-release || ! grep -qx 'VERSION_ID="24.04"' /etc/os-release; then
  status os
  exit 1
fi
if [ ! -e "$state/stage.1" ]; then
  status packages
  timeout 300 apt-get update
  timeout 300 apt-get install -y ca-certificates curl gnupg ufw unattended-upgrades
  install -m 0755 -d /etc/apt/keyrings
  timeout 60 curl --proto '=https' --tlsv1.2 -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
  [ "$(gpg --show-keys --with-colons /etc/apt/keyrings/docker.asc 2>/dev/null | awk -F: '$1 == "fpr" { print $10; exit }')" = '9DC858229FC7DD38854AE2D88D81803C0EBFCD88' ]
  chmod a+r /etc/apt/keyrings/docker.asc
  printf 'Types: deb\nURIs: https://download.docker.com/linux/ubuntu\nSuites: noble\nComponents: stable\nArchitectures: %s\nSigned-By: /etc/apt/keyrings/docker.asc\n' "$(dpkg --print-architecture)" > /etc/apt/sources.list.d/docker.sources
  timeout 300 apt-get update
  touch "$state/stage.1"
fi
if [ ! -e "$state/stage.2" ]; then
  status docker
  timeout 600 apt-get install -y \
    'docker-ce=5:29.9.2-1~ubuntu.24.04~noble' \
    'docker-ce-cli=5:29.9.2-1~ubuntu.24.04~noble' \
    'containerd.io=2.4.2-3~ubuntu.24.04~noble' \
    'docker-buildx-plugin=0.38.1-1~ubuntu.24.04~noble' \
    'docker-compose-plugin=5.6.1-1~ubuntu.24.04~noble'
  install -m 0755 -d /etc/docker
  printf '{"log-driver":"json-file","log-opts":{"max-size":"10m","max-file":"3"}}\n' > /etc/docker/daemon.json
  systemctl enable --now docker
  systemctl restart docker
  touch "$state/stage.2"
fi
if [ ! -e "$state/stage.3" ]; then
  status firewall
  ufw default deny incoming
  ufw default allow outgoing
  ufw allow 22/tcp
  ufw --force enable
  systemctl enable --now unattended-upgrades
  touch "$state/stage.3"
fi
if [ ! -e "$state/stage.4" ]; then
  status verify
  timeout 120 docker run --rm 'hello-world:linux@sha256:5e22040d441e5fb3aed38368acbe8486b575d7018df38dbdfbc7311fbb2ef3a9' >/dev/null
  docker compose version >/dev/null
  touch "$state/stage.4"
fi
