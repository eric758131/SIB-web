<?php

namespace App\Support;

/**
 * Password hashing compatible with WordPress (wp_hash_password / wp_check_password),
 * so users keep their current passwords and WordPress can still read ours.
 */
class WordPressHasher
{
    private const ITOA64 = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    /**
     * Hash a password the way WordPress 6.8+ does: bcrypt over a SHA-384 HMAC, prefixed with "$wp".
     */
    public static function make(string $password): string
    {
        $prehashed = base64_encode(hash_hmac('sha384', trim($password), 'wp-sha384', true));

        return '$wp'.password_hash($prehashed, PASSWORD_BCRYPT);
    }

    public static function check(string $password, string $hash): bool
    {
        if ($hash === '' || strlen($password) > 4096) {
            return false;
        }

        // WordPress 6.8+ format.
        if (str_starts_with($hash, '$wp')) {
            $prehashed = base64_encode(hash_hmac('sha384', $password, 'wp-sha384', true));

            return password_verify($prehashed, substr($hash, 3));
        }

        // Legacy phpass portable hashes (WordPress < 6.8).
        if (str_starts_with($hash, '$P$') || str_starts_with($hash, '$H$')) {
            return hash_equals($hash, self::phpassCrypt($password, $hash));
        }

        // Very old plain MD5 hashes.
        if (strlen($hash) <= 32) {
            return hash_equals($hash, md5($password));
        }

        // Plain bcrypt / argon hashes.
        return password_verify($password, $hash);
    }

    /**
     * Whether the stored hash should be upgraded to the current format.
     */
    public static function needsRehash(string $hash): bool
    {
        return ! str_starts_with($hash, '$wp');
    }

    private static function phpassCrypt(string $password, string $setting): string
    {
        $output = '*0';
        if (substr($setting, 0, 2) === $output) {
            $output = '*1';
        }

        $countLog2 = strpos(self::ITOA64, $setting[3]);
        if ($countLog2 < 7 || $countLog2 > 30) {
            return $output;
        }

        $count = 1 << $countLog2;
        $salt = substr($setting, 4, 8);
        if (strlen($salt) !== 8) {
            return $output;
        }

        $hash = md5($salt.$password, true);
        do {
            $hash = md5($hash.$password, true);
        } while (--$count);

        return substr($setting, 0, 12).self::encode64($hash, 16);
    }

    private static function encode64(string $input, int $count): string
    {
        $output = '';
        $i = 0;
        do {
            $value = ord($input[$i++]);
            $output .= self::ITOA64[$value & 0x3F];
            if ($i < $count) {
                $value |= ord($input[$i]) << 8;
            }
            $output .= self::ITOA64[($value >> 6) & 0x3F];
            if ($i++ >= $count) {
                break;
            }
            if ($i < $count) {
                $value |= ord($input[$i]) << 16;
            }
            $output .= self::ITOA64[($value >> 12) & 0x3F];
            if ($i++ >= $count) {
                break;
            }
            $output .= self::ITOA64[($value >> 18) & 0x3F];
        } while ($i < $count);

        return $output;
    }
}
