<?php
/**
 * lib/Encryption.php — AES-256-CBC encrypt/decrypt.
 * Used to store each user's Apple app-specific password at rest
 * (Apple Reminders sync).
 */

class Encryption {
    private static string $cipher = 'AES-256-CBC';

    public static function encrypt(string $plaintext): string {
        $key = self::deriveKey();
        $iv  = random_bytes(openssl_cipher_iv_length(self::$cipher));
        $enc = openssl_encrypt($plaintext, self::$cipher, $key, OPENSSL_RAW_DATA, $iv);
        if ($enc === false) throw new RuntimeException('Encryption failed');
        return base64_encode($iv . $enc);
    }

    public static function decrypt(string $ciphertext): string {
        $key   = self::deriveKey();
        $raw   = base64_decode($ciphertext);
        $ivLen = openssl_cipher_iv_length(self::$cipher);
        $iv    = substr($raw, 0, $ivLen);
        $enc   = substr($raw, $ivLen);
        $dec   = openssl_decrypt($enc, self::$cipher, $key, OPENSSL_RAW_DATA, $iv);
        if ($dec === false) throw new RuntimeException('Decryption failed');
        return $dec;
    }

    private static function deriveKey(): string {
        if (!ENCRYPTION_KEY) throw new RuntimeException('ENCRYPTION_KEY is not configured');
        return hash_pbkdf2('sha256', ENCRYPTION_KEY, 'taskstick_salt_v1', 100000, 32, true);
    }
}
