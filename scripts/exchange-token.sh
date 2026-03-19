#!/bin/bash
# Jira OAuth Token Exchange
# Aufruf: ./exchange-token.sh CLIENT_ID CLIENT_SECRET CODE

CLIENT_ID="$1"
CLIENT_SECRET="$2"
CODE="$3"

if [ -z "$CLIENT_ID" ] || [ -z "$CLIENT_SECRET" ] || [ -z "$CODE" ]; then
  echo "Verwendung: ./exchange-token.sh CLIENT_ID CLIENT_SECRET CODE"
  exit 1
fi

curl -s -X POST https://auth.atlassian.com/oauth/token \
  -H "Content-Type: application/json" \
  -d "{
    \"grant_type\": \"authorization_code\",
    \"client_id\": \"$CLIENT_ID\",
    \"client_secret\": \"$CLIENT_SECRET\",
    \"code\": \"$CODE\",
    \"redirect_uri\": \"http://localhost:19876/callback\"
  }" | python3 -m json.tool 2>/dev/null || cat
