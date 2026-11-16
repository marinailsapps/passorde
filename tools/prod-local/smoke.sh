#!/bin/sh
# Runs smoke.mjs in the Playwright image, inside Caddy's network namespace so
# https://localhost is the local production stack, with Caddy's local root CA
# trusted (as a phone trusts Let's Encrypt). Usage, from the repository root:
#
#   SMOKE_LOGIN=admin SMOKE_PASSWORD=… sh tools/prod-local/smoke.sh
#
# SMOKE_MODE: standalone (default) or evoliz (the Evoliz mock and a licence).
# SMOKE_CLIENT / SMOKE_ARTICLE: search terms for the test order (the first
# result is used; search a client by its mobile number to see the SMS go out).
# SMOKE_OUT=<directory> keeps a screenshot of every failed check. PROJECT (passorde-prod)
# names the stack; NODE_MODULES_VOLUME (passorde-playwright-node-modules) holds Playwright
# (docs/RUNBOOK.md "Production stack on a laptop" says how to fill it).
set -eu

project=${PROJECT:-passorde-prod}
caddy=$(docker ps -q --filter "label=com.docker.compose.project=${project}" --filter "label=com.docker.compose.service=caddy")
if [ -z "$caddy" ]; then
	echo "smoke: the ${project} stack is not running (docs/RUNBOOK.md)" >&2
	exit 1
fi

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
docker cp "$caddy:/data/caddy/pki/authorities/local/root.crt" "$tmp/caddy-root.crt"
out=${SMOKE_OUT:-$tmp/out}
mkdir -p "$out"

# Keep the tag in step with "playwright" in package.json (and with docs/RUNBOOK.md).
docker run --rm --init --ipc=host \
	--network "container:${caddy}" \
	-e SMOKE_LOGIN="${SMOKE_LOGIN:?SMOKE_LOGIN}" -e SMOKE_PASSWORD="${SMOKE_PASSWORD:?SMOKE_PASSWORD}" \
	-e SMOKE_CLIENT="${SMOKE_CLIENT:-alpha}" -e SMOKE_ARTICLE="${SMOKE_ARTICLE:-fic-002}" -e SMOKE_MODE="${SMOKE_MODE:-standalone}" \
	-e SMOKE_OUT=/out -v "$out:/out" \
	-v "$PWD:/app:ro" -v "$tmp/caddy-root.crt:/caddy-root.crt:ro" \
	-v "${NODE_MODULES_VOLUME:-passorde-playwright-node-modules}:/app/node_modules:ro" \
	-w /app mcr.microsoft.com/playwright:v1.63.0-noble \
	sh -c 'apt-get update -qq >/dev/null && apt-get install -y -qq libnss3-tools >/dev/null \
		&& mkdir -p "$HOME/.pki/nssdb" && certutil -d "sql:$HOME/.pki/nssdb" -N --empty-password \
		&& certutil -d "sql:$HOME/.pki/nssdb" -A -t "C,," -n caddy-local -i /caddy-root.crt \
		&& node tools/prod-local/smoke.mjs'
