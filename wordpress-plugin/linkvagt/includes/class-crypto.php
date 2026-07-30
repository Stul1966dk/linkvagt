<?php

declare(strict_types=1);

namespace LinkVagt;

final class Crypto
{
    public static function available(): bool
    {
        return wp_get_environment_type() === 'local' || defined('LINKVAGT_ENCRYPTION_KEY');
    }

    public static function encrypt(string $plaintext): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, 'linkvagt', 16);
        if (!is_string($ciphertext)) {
            throw new \RuntimeException('Adgangskoden kunne ikke krypteres.');
        }
        return base64_encode("\x01" . $iv . $tag . $ciphertext);
    }

    public static function decrypt(string $encoded): string
    {
        $payload = base64_decode($encoded, true);
        if (!is_string($payload) || strlen($payload) < 30 || $payload[0] !== "\x01") {
            throw new \RuntimeException('Den krypterede adgangskode er ugyldig.');
        }
        $plaintext = openssl_decrypt(
            substr($payload, 29),
            'aes-256-gcm',
            self::key(),
            OPENSSL_RAW_DATA,
            substr($payload, 1, 12),
            substr($payload, 13, 16),
            'linkvagt'
        );
        if (!is_string($plaintext)) {
            throw new \RuntimeException('Adgangskoden kunne ikke dekrypteres.');
        }
        return $plaintext;
    }

    private static function key(): string
    {
        if (defined('LINKVAGT_ENCRYPTION_KEY')) {
            $key = base64_decode((string) LINKVAGT_ENCRYPTION_KEY, true);
            if (is_string($key) && strlen($key) === 32) {
                return $key;
            }
            throw new \RuntimeException('LINKVAGT_ENCRYPTION_KEY skal være en base64-kodet 32-byte nøgle.');
        }
        if (wp_get_environment_type() === 'local') {
            return hash('sha256', wp_salt('auth') . '|linkvagt-local', true);
        }
        throw new \RuntimeException('LinkVagts krypteringsnøgle mangler i wp-config.php.');
    }
}
