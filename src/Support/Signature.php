<?php

namespace Folklore\Eclair\Support;

/**
 * The signatures of Eclair's webhooks and http storage requests: `t={timestamp},v1={hmac}`, where
 * the HMAC-SHA256 covers `{timestamp}.{payload}`.
 */
final class Signature
{
    /** The SHA-256 of an empty body, signed for the `GET` of an http storage. */
    public const EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    /** The age, in seconds, past which a signature is refused. */
    public const TOLERANCE = 300;

    public static function sign(string $secret, string $payload, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return sprintf('t=%d,v1=%s', $timestamp, hash_hmac('sha256', $timestamp.'.'.$payload, $secret));
    }

    /**
     * Checks a signature header: a timestamp within the tolerance, and one `v1` value that matches.
     */
    public static function verify(
        ?string $header,
        string $secret,
        string $payload,
        int $tolerance = self::TOLERANCE,
        ?int $now = null,
    ): bool {
        if ($header === null || $header === '' || $secret === '') {
            return false;
        }
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }
        if ($timestamp === null || abs(($now ?? time()) - $timestamp) > $tolerance) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The payload of an http storage request: the storage path, not URL-encoded, and the SHA-256
     * of the body.
     */
    public static function storagePayload(string $path, string $sha256): string
    {
        return $path.'.'.$sha256;
    }
}
