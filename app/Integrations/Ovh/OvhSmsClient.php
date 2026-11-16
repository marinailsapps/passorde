<?php

namespace App\Integrations\Ovh;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use SensitiveParameter;

/**
 * Sends one SMS through the OVH API (POST /sms/{service}/jobs), signed the
 * way tools/ovh-sms-test.sh does it:
 *
 *   X-Ovh-Signature = "$1$" . sha1(secret+consumer+METHOD+url+body+timestamp)
 *
 * with the timestamp taken from GET /auth/time. Only call it from queued jobs.
 */
class OvhSmsClient
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $endpoint,
        private readonly string $appKey,
        #[SensitiveParameter] private readonly string $appSecret,
        #[SensitiveParameter] private readonly string $consumerKey,
        private readonly string $service,
        private readonly ?string $sender = null,
    ) {}

    /**
     * @return int|null OVH's job id
     *
     * @throws OvhSmsException
     */
    public function send(string $to, string $message): ?int
    {
        if ($this->appKey === '' || $this->appSecret === '' || $this->consumerKey === '' || $this->service === '') {
            throw new OvhSmsException(__('OVH SMS keys are missing from .env.'));
        }

        $url = $this->endpoint.'/sms/'.rawurlencode($this->service).'/jobs';
        $body = (string) json_encode($this->body($to, $message), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $timestamp = $this->serverTime();

        try {
            $response = $this->http
                ->acceptJson()
                ->timeout(20)
                ->withHeaders([
                    'X-Ovh-Application' => $this->appKey,
                    'X-Ovh-Consumer' => $this->consumerKey,
                    'X-Ovh-Timestamp' => (string) $timestamp,
                    'X-Ovh-Signature' => self::signature($this->appSecret, $this->consumerKey, 'POST', $url, $body, $timestamp),
                ])
                ->withBody($body, 'application/json')
                ->post($url);
        } catch (ConnectionException $e) {
            throw new OvhSmsException(__('OVH does not answer: :message', ['message' => $e->getMessage()]), previous: $e);
        }

        if ($response->failed()) {
            throw new OvhSmsException(self::describe($response));
        }

        $invalid = $response->json('invalidReceivers');
        if (is_array($invalid) && $invalid !== []) {
            throw new OvhSmsException(__('OVH refused the number :phone.', ['phone' => implode(', ', $invalid)]));
        }

        $id = $response->json('ids.0');

        return is_numeric($id) ? (int) $id : null;
    }

    public static function signature(#[SensitiveParameter] string $appSecret, #[SensitiveParameter] string $consumerKey, string $method, string $url, string $body, int $timestamp): string
    {
        return '$1$'.sha1(implode('+', [$appSecret, $consumerKey, $method, $url, $body, (string) $timestamp]));
    }

    /**
     * @return array<string, mixed>
     */
    private function body(string $to, string $message): array
    {
        return [
            'receivers' => [$to],
            'message' => $message,
            // A validated sender name, otherwise OVH's short number (replies possible).
            ...($this->sender !== null && $this->sender !== '' ? ['sender' => $this->sender] : ['senderForResponse' => true]),
            'noStopClause' => true,
            'charset' => 'UTF-8',
            'class' => 'phoneDisplay',
        ];
    }

    private function serverTime(): int
    {
        try {
            $response = $this->http->timeout(10)->get($this->endpoint.'/auth/time');
        } catch (ConnectionException $e) {
            throw new OvhSmsException(__('OVH does not answer: :message', ['message' => $e->getMessage()]), previous: $e);
        }

        if ($response->failed() || ! is_numeric(trim($response->body()))) {
            throw new OvhSmsException(self::describe($response));
        }

        return (int) trim($response->body());
    }

    private static function describe(Response $response): string
    {
        $message = $response->json('message');

        return 'HTTP '.$response->status().(is_string($message) && $message !== '' ? ' — '.$message : '');
    }
}
