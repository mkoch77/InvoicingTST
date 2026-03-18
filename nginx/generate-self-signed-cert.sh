#!/bin/sh
# Generiert ein selbstsigniertes Zertifikat als Platzhalter.
# Spaeter durch Let's Encrypt ersetzen (siehe README).

CERT_DIR="$(dirname "$0")/certs"
mkdir -p "$CERT_DIR"

if [ -f "$CERT_DIR/fullchain.pem" ] && [ -f "$CERT_DIR/privkey.pem" ]; then
    echo "Zertifikate existieren bereits in $CERT_DIR — uebersprungen."
    exit 0
fi

openssl req -x509 -nodes -days 365 \
    -newkey rsa:2048 \
    -keyout "$CERT_DIR/privkey.pem" \
    -out "$CERT_DIR/fullchain.pem" \
    -subj "/CN=localhost/O=InvoicingTST/C=DE"

echo "Selbstsigniertes Zertifikat erstellt in $CERT_DIR"
