<?php

declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Laravel\Tests\Support;

/**
 * Fresh Stellar public keys (StrKey `G…`) for tests that need an account
 * nobody has created — a fixed example key may well exist on the testnet.
 * The same encoding as dev/fund-stellar-testnet-account.sh: version byte
 * 6 << 3, the Ed25519 key, CRC16-XModem little-endian, base32 unpadded.
 */
final class StellarKeys
{
    public static function randomPublicKey(): string
    {
        $data = chr(6 << 3) . sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
        $data .= pack('v', self::crc16Xmodem($data));

        $bits = '';
        foreach (str_split($data) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $key = '';
        foreach (str_split($bits, 5) as $chunk) {
            $key .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
        }

        return $key;
    }

    private static function crc16Xmodem(string $data): int
    {
        $crc = 0;
        foreach (str_split($data) as $byte) {
            $crc ^= ord($byte) << 8;
            for ($i = 0; $i < 8; $i++) {
                $crc = ($crc & 0x8000) !== 0 ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
            }
        }

        return $crc;
    }
}
