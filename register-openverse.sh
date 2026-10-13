#!/bin/bash
# Registers envirolink.org with the Openverse API (free) and saves the
# client_id / client_secret to ~/.openverse-credentials (readable only by you).
# Openverse then emails a verification link; click it to get the higher rate limit.

if [ -s "$HOME/.openverse-credentials" ]; then
    echo "Already registered: ~/.openverse-credentials exists. Delete it first to register again."
    exit 1
fi

printf "Email address for Openverse to contact you about this API use: "
read -r EMAIL
if [ -z "$EMAIL" ]; then
    echo "No email entered - nothing sent."
    exit 1
fi

BODY=$(python3 -I -c 'import json, sys; print(json.dumps({
    "name": "EnviroLink News Aggregator",
    "description": "envirolink.org is an ad-free, non-commercial environmental news site. Its WordPress plugin searches Openverse for openly licensed photos (CC0, public domain, CC BY, BY-SA, BY-NC, BY-NC-SA) that illustrate each day'"'"'s environmental news stories, and credits the photographer and license under each image. Expected volume: a few hundred searches per day.",
    "email": sys.argv[1]}))' "$EMAIL")

umask 077
RESPONSE=$(curl -s -X POST "https://api.openverse.org/v1/auth_tokens/register/" \
    -H "Content-Type: application/json" -H "User-Agent: envirolink-news-aggregator" \
    -d "$BODY")

if echo "$RESPONSE" | grep -q '"client_secret"'; then
    echo "$RESPONSE" > "$HOME/.openverse-credentials"
    echo "saved - credentials are in ~/.openverse-credentials"
    echo "Now check $EMAIL for an email from Openverse and click the verification link."
else
    echo "Registration failed. Openverse replied:"
    echo "$RESPONSE"
fi
