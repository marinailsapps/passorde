#!/usr/bin/env bash
# Send one test SMS through the OVH API (checks the OVH keys by hand).
# Reads OVH_APP_KEY, OVH_APP_SECRET, OVH_CONSUMER_KEY, OVH_SMS_SERVICE from the
# environment or from .env; never prints the secrets.
#
#   tools/ovh-sms-test.sh +33612345678 "Bonjour, test"
#   tools/ovh-sms-test.sh +33612345678 "..." MYSENDER   # once the sender is validated
set -euo pipefail

if [[ -f .env ]]; then
  while IFS='=' read -r k v; do
    [[ "$k" == OVH_* && -z "${!k:-}" ]] && export "$k=$v"
  done < <(grep -E '^OVH_[A-Z_]+=' .env | tr -d '\r')
fi
: "${OVH_APP_KEY:?missing}" "${OVH_APP_SECRET:?missing}" "${OVH_CONSUMER_KEY:?missing}" "${OVH_SMS_SERVICE:?missing (e.g. sms-fq16217-1)}"

to="${1:?usage: $0 +33XXXXXXXXX \"message\" [SENDER]}"
message="${2:?message}"
sender="${3:-}"

api="https://eu.api.ovh.com/1.0"
path="/sms/${OVH_SMS_SERVICE}/jobs"
if [[ -n "$sender" ]]; then
  body=$(printf '{"receivers":["%s"],"message":"%s","sender":"%s","noStopClause":true,"charset":"UTF-8","class":"phoneDisplay"}' "$to" "$message" "$sender")
else
  body=$(printf '{"receivers":["%s"],"message":"%s","senderForResponse":true,"noStopClause":true,"charset":"UTF-8","class":"phoneDisplay"}' "$to" "$message")
fi

ts=$(curl -s "${api}/auth/time")
sig="\$1\$$(printf '%s+%s+POST+%s+%s+%s' "$OVH_APP_SECRET" "$OVH_CONSUMER_KEY" "${api}${path}" "$body" "$ts" | openssl sha1 | awk '{print $NF}')"

curl -s -X POST "${api}${path}" \
  -H "Content-Type: application/json" \
  -H "X-Ovh-Application: ${OVH_APP_KEY}" \
  -H "X-Ovh-Consumer: ${OVH_CONSUMER_KEY}" \
  -H "X-Ovh-Timestamp: ${ts}" \
  -H "X-Ovh-Signature: ${sig}" \
  --data "$body"
echo
