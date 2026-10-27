#!/usr/bin/env bash
# Creates a fresh Stellar testnet account through Friendbot, waits until
# Horizon knows it, and prints its public key (G…).
#
# The key pair is generated here (libsodium's Ed25519, StrKey-encoded) and
# the secret is thrown away: the account only ever receives. Friendbot funds
# it with a create_account operation, which the core's sync skips — it is
# not a payment. Needs PHP with sodium, curl and jq.
set -euo pipefail

DEADLINE_SECONDS=60

address=$(php -r '
    $data = chr(6 << 3) . sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
    $crc = 0;
    foreach (str_split($data) as $byte) {
        $crc ^= ord($byte) << 8;
        for ($i = 0; $i < 8; $i++) {
            $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
        }
    }
    $data .= pack("v", $crc);
    $alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";
    $bits = "";
    foreach (str_split($data) as $byte) {
        $bits .= str_pad(decbin(ord($byte)), 8, "0", STR_PAD_LEFT);
    }
    $out = "";
    foreach (str_split($bits, 5) as $chunk) {
        $out .= $alphabet[bindec(str_pad($chunk, 5, "0"))];
    }
    echo $out;
')

if ! curl -sf --connect-timeout 5 --max-time 30 "https://friendbot.stellar.org/?addr=$address" >/dev/null; then
  echo "Friendbot did not fund $address." >&2
  exit 1
fi

SECONDS=0
while [ "$SECONDS" -lt "$DEADLINE_SECONDS" ]; do
  found=$(curl -sf --connect-timeout 5 --max-time 10 "https://horizon-testnet.stellar.org/accounts/$address" \
    | jq -r '.account_id // empty' || true)
  if [ "$found" = "$address" ]; then
    echo "$address"
    exit 0
  fi
  sleep 2
done

echo "Stellar account $address was not visible on Horizon within ${DEADLINE_SECONDS} s." >&2
exit 1
