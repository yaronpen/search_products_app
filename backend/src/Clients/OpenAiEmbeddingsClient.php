<?php
declare(strict_types=1);

namespace App\Clients;

/**
 * HTTP client for OpenAI's embeddings endpoint. No caching or storage here; see EmbeddingService.
 * Model and dimensions come from config; text-embedding-3-* can shorten vectors natively
 * (we use 512) to keep storage and in-PHP cosine similarity cheap. Vectors come back unit-normalized.
 */
final class OpenAiEmbeddingsClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $url,
        public readonly string $model,
        public readonly int $dimensions,
        private readonly float $timeoutSeconds,
    ) {
    }

    /**
     * @param list<string> $texts
     * @return list<list<float>>
     */
    public function embed(array $texts): array
    {
        $ch = curl_init($this->url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => (int) ($this->timeoutSeconds * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => 2000,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $this->model,
                'input' => $texts,
                'dimensions' => $this->dimensions,
            ], JSON_UNESCAPED_UNICODE),
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);

        if ($body === false) {
            throw new \RuntimeException("Embeddings request failed: {$error}");
        }
        $data = json_decode((string) $body, true);
        if ($status !== 200 || !isset($data['data'])) {
            $message = $data['error']['message'] ?? substr((string) $body, 0, 200);
            throw new \RuntimeException("Embeddings API returned {$status}: {$message}");
        }

        usort($data['data'], fn(array $a, array $b) => $a['index'] <=> $b['index']);
        return array_map(fn(array $row) => $row['embedding'], $data['data']);
    }
}
