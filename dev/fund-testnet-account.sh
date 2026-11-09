#!/usr/bin/env bash
# Creates and funds a fresh testnet account from a faucet, waits until the
# funding payment is validated, and prints the classic address.
#
#   dev/fund-testnet-account.sh [xrpl|xahau]      (default: xrpl)
#
# A fresh account per run: the testnets are reset or pruned now and then, so a
# stored address goes stale. The funding payment is also what the sync checks
# find. Needs curl and jq.
#
# The Xahau faucet allows one request per IP and minute or more, and says so
# with HTTP 200 and {"error":"you must wait 59 seconds …"}; that wait is sat
# out, within FAUCET_DEADLINE_SECONDS.
set -euo pipefail

chain=${1:-xrpl}

case "$chain" in
  xrpl)
    faucet=https://faucet.altnet.rippletest.net/accounts
    node=https://s.altnet.rippletest.net:51234/
    ;;
  xahau)
    faucet=https://xahau-test.net/accounts
    node=https://xahau-test.net/
    ;;
  *)
    echo "Unknown chain '$chain': xrpl or xahau." >&2
    exit 2
    ;;
esac

# Every request is bounded, and so is each wait as a whole: a faucet or node
# that keeps a connection open must not hold a CI job until GitHub's
# six-hour limit.
FAUCET_DEADLINE_SECONDS=300
DEADLINE_SECONDS=60

# Each failure stops here with its own message, not later in a test that
# finds no account.
SECONDS=0
while :; do
  if ! response=$(curl -sf --connect-timeout 5 --max-time 20 -X POST "$faucet"); then
    echo "The $chain faucet request failed." >&2
    exit 1
  fi

  if ! jq -e . >/dev/null 2>&1 <<<"$response"; then
    echo "The $chain faucet did not answer with JSON." >&2
    exit 1
  fi

  wait=$(jq -r '((.error // "") | capture("wait (?<s>[0-9]+) second").s)? // empty' <<<"$response")

  if [ -z "$wait" ]; then
    break
  fi

  if [ $((SECONDS + wait)) -ge "$FAUCET_DEADLINE_SECONDS" ]; then
    echo "The $chain faucet asks to wait ${wait} s, beyond the ${FAUCET_DEADLINE_SECONDS} s deadline." >&2
    exit 1
  fi

  sleep $((wait + 1))
done

address=$(jq -r '.account.address // .account.classicAddress // empty' <<<"$response")

if [ -z "$address" ]; then
  # Only the error field: a faucet answer can carry a seed.
  echo "The $chain faucet returned no account: $(jq -r '.error // "no error given"' <<<"$response")" >&2
  exit 1
fi

SECONDS=0
while [ "$SECONDS" -lt "$DEADLINE_SECONDS" ]; do
  funded=$(curl -sf --connect-timeout 5 --max-time 10 -H 'Content-Type: application/json' "$node" \
    -d "{\"method\":\"account_info\",\"params\":[{\"account\":\"$address\",\"ledger_index\":\"validated\"}]}" \
    | jq -r '.result.account_data.Account // empty' || true)
  if [ "$funded" = "$address" ]; then
    echo "$address"
    exit 0
  fi
  sleep 2
done

echo "Faucet account $address on $chain was not validated within ${DEADLINE_SECONDS} s." >&2
exit 1
