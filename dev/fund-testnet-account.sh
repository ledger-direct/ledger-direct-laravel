#!/usr/bin/env bash
# Creates and funds a fresh XRPL testnet account from the faucet, waits until
# the funding payment is validated, and prints the classic address.
#
# A fresh account per run: the testnet is reset now and then, so a stored
# address goes stale. The funding payment is also what the sync checks find.
# Needs curl and jq.
set -euo pipefail

# Every request is bounded, and so is the wait as a whole: a faucet or node
# that keeps a connection open must not hold a CI job until GitHub's
# six-hour limit.
DEADLINE_SECONDS=60

# Each failure stops here with its own message, not later in a test that
# finds no account.
if ! response=$(curl -sf --connect-timeout 5 --max-time 20 -X POST https://faucet.altnet.rippletest.net/accounts); then
  echo "The faucet request failed." >&2
  exit 1
fi

address=$(jq -r '.account.address // .account.classicAddress // empty' <<<"$response")

if [ -z "$address" ]; then
  echo "The faucet returned no account: $response" >&2
  exit 1
fi

SECONDS=0
while [ "$SECONDS" -lt "$DEADLINE_SECONDS" ]; do
  funded=$(curl -sf --connect-timeout 5 --max-time 10 -H 'Content-Type: application/json' https://s.altnet.rippletest.net:51234/ \
    -d "{\"method\":\"account_info\",\"params\":[{\"account\":\"$address\",\"ledger_index\":\"validated\"}]}" \
    | jq -r '.result.account_data.Account // empty' || true)
  if [ "$funded" = "$address" ]; then
    echo "$address"
    exit 0
  fi
  sleep 2
done

echo "Faucet account $address was not validated within ${DEADLINE_SECONDS} s." >&2
exit 1
