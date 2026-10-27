#!/usr/bin/env bash
# Creates and funds a fresh XRPL testnet account from the faucet, waits until
# the funding payment is validated, and prints the classic address.
#
# A fresh account per run: the testnet is reset now and then, so a stored
# address goes stale. The funding payment is also what the sync checks find.
# Needs curl and jq.
set -euo pipefail

address=$(curl -sf -X POST https://faucet.altnet.rippletest.net/accounts \
  | jq -r '.account.address // .account.classicAddress // empty')

if [ -z "$address" ]; then
  echo "The faucet returned no account." >&2
  exit 1
fi

for _ in $(seq 1 30); do
  funded=$(curl -sf -H 'Content-Type: application/json' https://s.altnet.rippletest.net:51234/ \
    -d "{\"method\":\"account_info\",\"params\":[{\"account\":\"$address\",\"ledger_index\":\"validated\"}]}" \
    | jq -r '.result.account_data.Account // empty' || true)
  if [ "$funded" = "$address" ]; then
    echo "$address"
    exit 0
  fi
  sleep 2
done

echo "Faucet account $address was not validated within 60 s." >&2
exit 1
