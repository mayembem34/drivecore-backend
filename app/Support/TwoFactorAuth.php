<?php

namespace App\Support;

use App\Models\TwoFactorChallenge;
use App\Models\User;
use Illuminate\Http\JsonResponse;

final class TwoFactorAuth
{
    public static function secret(): string
    {
        $bytes = random_bytes(20);
        $bits = '';
        foreach (unpack('C*', $bytes) as $b) {
            $bits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
        } $result = '';
        foreach (str_split($bits, 5) as $chunk) {
            $result .= 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'[bindec($chunk)];
        }

        return $result;
    }

    public static function code(string $secret, ?int $timestamp = null): string
    {
        $bits = '';
        foreach (str_split($secret) as $char) {
            $position = strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', $char);
            if ($position === false) {
                throw new \InvalidArgumentException('Invalid TOTP secret.');
            } $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        } $key = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $key .= chr(bindec($byte));
            }
        } $counter = intdiv($timestamp ?? now()->timestamp, 30);
        $hash = hash_hmac('sha1', pack('N2', 0, $counter), $key, true);
        $offset = ord($hash[19]) & 15;
        $binary = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($binary % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public static function verify(string $secret, string $code): bool
    {
        if (! preg_match('/^\d{6}$/D', $code)) {
            return false;
        } foreach ([-30, 0, 30] as $offset) {
            if (hash_equals(self::code($secret, now()->timestamp + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    public static function challenge(User $user, string $mode, ?string $device = null): JsonResponse
    {
        $raw = bin2hex(random_bytes(32));
        TwoFactorChallenge::create(['user_id' => $user->id, 'token_hash' => hash('sha256', $raw), 'auth_version' => $user->auth_version, 'mode' => $mode, 'device_name' => $device, 'expires_at' => now()->addMinutes(5)]);

        return response()->json(['two_factor_required' => true, 'challenge_token' => $raw]);
    }
}
