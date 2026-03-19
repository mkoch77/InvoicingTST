#!/bin/sh
set -e

CERT_DIR="/etc/nginx/certs"

if [ ! -f "$CERT_DIR/fullchain.pem" ] || [ ! -f "$CERT_DIR/privkey.pem" ]; then
    echo "Keine Zertifikate gefunden — generiere Self-Signed-Zertifikat..."
    apk add --no-cache openssl > /dev/null 2>&1
    openssl req -x509 -nodes -days 365 \
        -newkey rsa:2048 \
        -keyout "$CERT_DIR/privkey.pem" \
        -out "$CERT_DIR/fullchain.pem" \
        -subj "/CN=localhost/O=InvoicingTST/C=DE" 2>/dev/null
    echo "Self-Signed-Zertifikat erstellt."
else
    echo "Zertifikate vorhanden — ueberspringe Generierung."
fi

exec nginx -g "daemon off;"
