<?php

namespace App\Integrations\Sync\Push;

use Illuminate\Support\Str;
use Passorde\Api\ApiError;
use Passorde\Api\Exceptions\ApiException;
use Passorde\Api\Exceptions\LoginFailedException;
use Passorde\Writes\Results\Failed;
use Passorde\Writes\Results\Refused;

/**
 * The French texts of a push that did not go through, as the operator reads them:
 * Evoliz's own "HTTP 400 — field : message", "Evoliz ne répond pas (…)",
 * "Vérifiez les clés API", "mode test".
 */
final class PushMessages
{
    /** How Passorde starts the message of a request that got no answer (ApiError::connectionFailure). */
    private const NO_ANSWER = 'Connexion à Evoliz impossible — ';

    public static function of(Refused|Failed $result): string
    {
        return $result instanceof Refused
            ? $result->message
            : self::error(new ApiError($result->status, $result->message));
    }

    public static function ofException(ApiException $exception): string
    {
        return $exception instanceof LoginFailedException
            ? self::keys($exception->getMessage())
            : self::error($exception->error);
    }

    /** No Evoliz payment term in the installation profile: nothing was sent. */
    public static function noPayterm(): string
    {
        return __('No Evoliz payment term is set (evoliz.payterm_id in the installation profile): nothing was sent to Evoliz.');
    }

    public static function testMode(): string
    {
        return __('Evoliz sending is disabled (test mode)');
    }

    public static function unexpected(\Throwable $exception): string
    {
        return __('Unexpected error: :message', ['message' => Str::limit($exception->getMessage(), 300)]);
    }

    private static function error(ApiError $error): string
    {
        return match (true) {
            $error->status === 401 => self::keys($error->message),
            $error->status === null && str_starts_with($error->message, self::NO_ANSWER) => __('Evoliz did not answer (:detail).', ['detail' => Str::after($error->message, self::NO_ANSWER)]),
            default => $error->message,
        };
    }

    private static function keys(string $detail): string
    {
        return __('Evoliz refused the connection (:detail). Check the API keys.', ['detail' => $detail]);
    }
}
