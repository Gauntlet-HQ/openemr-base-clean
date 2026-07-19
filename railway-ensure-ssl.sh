#!/bin/sh
# Self-signed SSL certs for Apache httpd -t (flex openemr.conf references :443 vhost).
set -eu

mkdir -p /etc/ssl/private /etc/ssl/certs

if [ ! -f /etc/ssl/private/selfsigned.key.pem ] || [ ! -f /etc/ssl/certs/selfsigned.cert.pem ]; then
    echo "Railway SSL: generating self-signed certificate"
    openssl req -x509 -newkey rsa:4096 \
        -keyout /etc/ssl/private/selfsigned.key.pem \
        -out /etc/ssl/certs/selfsigned.cert.pem \
        -days 365 -nodes \
        -subj "/C=xx/ST=x/L=x/O=x/OU=x/CN=localhost"
fi

if [ ! -f /etc/ssl/certs/webserver.cert.pem ] || [ ! -f /etc/ssl/private/webserver.key.pem ]; then
    ln -sf /etc/ssl/certs/selfsigned.cert.pem /etc/ssl/certs/webserver.cert.pem
    ln -sf /etc/ssl/private/selfsigned.key.pem /etc/ssl/private/webserver.key.pem
    echo "Railway SSL: linked webserver certificate files"
fi

if [ ! -s /etc/ssl/certs/webserver.cert.pem ] || [ ! -s /etc/ssl/private/webserver.key.pem ]; then
    echo "Railway SSL: certificate files missing or empty" >&2
    exit 1
fi

echo "Railway SSL: ready"
