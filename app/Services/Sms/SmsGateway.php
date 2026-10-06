<?php

namespace App\Services\Sms;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Sends text messages through the cooperative's eTxtMo SMS gateway (config/services.php → etxtmo).
 * The gateway queues each message and sends it from the branch modem the API key belongs to.
 */
class SmsGateway
{
    /**
     * Requests sent to the gateway at the same time, so a reminder to every listed member stays quick.
     */
    private const CONCURRENCY = 20;

    public function isConfigured(): bool
    {
        return filled(config('services.etxtmo.url')) && filled(config('services.etxtmo.key'));
    }

    /**
     * The first Philippine mobile number in the text, in the 09XXXXXXXXX form the gateway expects.
     * Accepts +63 / 63 / 9 prefixes, spaces and dashes, and fields holding two numbers ("0917… / 0918…").
     */
    public static function normalizeNumber(?string $number): ?string
    {
        foreach (preg_split('/[\/,;&]|\bor\b/i', (string) $number) ?: [] as $candidate) {
            $digits = preg_replace('/\D+/', '', $candidate) ?? '';

            $digits = match (true) {
                strlen($digits) === 12 && str_starts_with($digits, '639') => '0'.substr($digits, 2),
                strlen($digits) === 10 && str_starts_with($digits, '9') => '0'.$digits,
                default => $digits,
            };

            if (preg_match('/^09\d{9}$/', $digits) === 1) {
                return $digits;
            }
        }

        return null;
    }

    /**
     * @param  array<int|string, array{to: string, message: string}>  $messages
     * @return array<int|string, array{ok: bool, message_id: ?string, error: ?string}> keyed like $messages
     */
    public function sendMany(array $messages): array
    {
        $url = rtrim((string) config('services.etxtmo.url'), '/').'/sms/send';
        $key = (string) config('services.etxtmo.key');
        $results = [];

        foreach (array_chunk($messages, self::CONCURRENCY, preserve_keys: true) as $chunk) {
            $responses = Http::pool(fn (Pool $pool) => collect($chunk)
                ->map(fn (array $sms, int|string $index) => $pool
                    ->as((string) $index)
                    ->withHeaders(['X-API-Key' => $key])
                    ->acceptJson()
                    ->timeout(20)
                    ->post($url, ['to' => $sms['to'], 'message' => $sms['message']]))
                ->all());

            foreach (array_keys($chunk) as $index) {
                $results[$index] = $this->result($responses[(string) $index] ?? null);
            }
        }

        return $results;
    }

    /**
     * @return array{ok: bool, message_id: ?string, error: ?string}
     */
    private function result(mixed $response): array
    {
        if (! $response instanceof Response) {
            return ['ok' => false, 'message_id' => null, 'error' => 'Could not reach the SMS gateway'];
        }

        if ($response->successful()) {
            return ['ok' => true, 'message_id' => $response->json('message_id'), 'error' => null];
        }

        $reason = $response->json('message') ?? $response->json('error') ?? $response->json('detail');

        return ['ok' => false, 'message_id' => null, 'error' => match (true) {
            is_string($reason) && $reason !== '' => $reason,
            $response->status() === 422 => 'Recipient has opted out of SMS (replied STOP)',
            in_array($response->status(), [401, 403], true) => 'The SMS gateway rejected the API key',
            default => 'SMS gateway error (HTTP '.$response->status().')',
        }];
    }
}
