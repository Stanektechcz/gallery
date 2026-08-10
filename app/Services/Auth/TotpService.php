<?php

namespace App\Services\Auth;

use Illuminate\Support\Str;

/**
 * Time-based one-time passwords, RFC 6238.
 *
 * Written out rather than pulled in. The algorithm is a truncated HMAC and fits on one
 * screen; a dependency for it would be more code to keep current than the thing it
 * replaces, and this one can be checked against the RFC's own published vectors — which
 * is a stronger guarantee than trusting a package nobody here has read.
 *
 * Six digits, thirty seconds, SHA-1: not because they are the best available, but because
 * they are what every authenticator app implements. A stronger variant nobody can scan is
 * worth nothing.
 */
class TotpService
{
    private const PERIOD = 30;
    private const DIGITS = 6;

    /**
     * One step either side of now.
     *
     * Phone clocks drift and people finish typing late. Accepting the neighbouring windows
     * costs an attacker nothing meaningful — they still need the secret — while refusing
     * them turns a correct code into a wrong one for reasons the person cannot see.
     */
    private const DRIFT = 1;

    /** Base32 without padding, as every authenticator expects it. */
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(int $bytes = 20): string
    {
        return $this->base32Encode(random_bytes($bytes));
    }

    /** What the QR code contains. The issuer appears twice because authenticators disagree on which they read. */
    public function provisioningUri(string $secret, string $account, string $issuer = 'MAKI Gallery'): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
            . '?secret=' . $secret
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    public function verify(string $secret, string $code, ?int $at = null): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== self::DIGITS) return false;

        $counter = intdiv($at ?? time(), self::PERIOD);

        for ($offset = -self::DRIFT; $offset <= self::DRIFT; $offset++) {
            // hash_equals, not ===: comparing codes character by character leaks how much
            // of a guess was right, and this runs on an unauthenticated-ish path.
            if (hash_equals($this->at($secret, $counter + $offset), $code)) return true;
        }

        return false;
    }

    public function at(string $secret, int $counter): string
    {
        $binary = hash_hmac('sha1', pack('J', $counter), $this->base32Decode($secret), true);

        // Dynamic truncation, RFC 4226 §5.4: the low nibble of the last byte picks where
        // the four-byte window starts.
        $start = ord($binary[19]) & 0x0F;
        $value = (
            ((ord($binary[$start]) & 0x7F) << 24)
            | (ord($binary[$start + 1]) << 16)
            | (ord($binary[$start + 2]) << 8)
            | ord($binary[$start + 3])
        ) % (10 ** self::DIGITS);

        return str_pad((string) $value, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Codes for when the phone is gone.
     *
     * The whole point of a second factor is that losing it is possible, so a way back in
     * that does not depend on it is not a convenience — it is the difference between a
     * locked account and a recoverable one.
     *
     * @return list<string>
     */
    public function recoveryCodes(int $count = 8): array
    {
        return array_map(
            fn () => strtoupper(Str::random(5) . '-' . Str::random(5)),
            range(1, $count),
        );
    }

    public function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $out;
    }

    public function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $secret) ?? '');

        $bits = '';
        foreach (str_split($secret) as $character) {
            $position = strpos(self::ALPHABET, $character);
            if ($position === false) continue;
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) $out .= chr(bindec($chunk));
        }

        return $out;
    }
}
