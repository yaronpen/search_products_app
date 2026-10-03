<?php
declare(strict_types=1);

namespace App\Core;

final class JsonResponse
{
    /** @param array<string, mixed> $body */
    public function __construct(
        public readonly array $body,
        public readonly int $status = 200,
    ) {
    }

    public static function error(int $status, string $code, string $message): self
    {
        return new self(['error' => $code, 'message' => $message], $status);
    }

    public function send(): void
    {
        [$status, $json] = $this->encode();
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo $json;
    }

    /**
     * @return array{int, string} status and body. If the body can't be encoded (e.g. invalid
     *         UTF-8 slipped through), this becomes a 500 instead of a silent empty 200.
     */
    public function encode(): array
    {
        try {
            return [$this->status, json_encode($this->body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)];
        } catch (\JsonException $e) {
            error_log('Response encoding failed: ' . $e->getMessage());
            return [500, '{"error":"server_error","message":"אירעה שגיאה. נסו שוב בעוד רגע."}'];
        }
    }
}
