<?php

declare(strict_types=1);

namespace App\Integrations;

use App\Core\App;

/**
 * Merges config/integrations.php with storage/integrations.secrets.json.
 */
final class IntegrationConfig
{
    public function __construct(private App $app) {}

    /** @return array<string, mixed> */
    public function all(): array
    {
        $base = (array) $this->app->config->get('integrations', []);
        $secrets = $this->secrets();
        foreach ($secrets as $provider => $vals) {
            if (!is_array($vals)) {
                continue;
            }
            $base[$provider] = array_merge((array) ($base[$provider] ?? []), $vals);
        }
        return $base;
    }

    /** @return array<string, mixed> */
    public function provider(string $key): array
    {
        return (array) ($this->all()[$key] ?? []);
    }

    public function get(string $provider, string $key, mixed $default = ''): mixed
    {
        $cfg = $this->provider($provider);
        return $cfg[$key] ?? $default;
    }

    /** @param array<string, array<string, mixed>> $data */
    public function saveSecrets(array $data): void
    {
        $path = $this->secretsPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL
        );
    }

    /** @return array<string, array<string, mixed>> */
    public function secrets(): array
    {
        $path = $this->secretsPath();
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function secretsPath(): string
    {
        return $this->app->basePath . '/storage/integrations.secrets.json';
    }
}
