<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\BrandMonitor\PlatformAdapters;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 平台适配器抽象基类。
 *
 * 提供通用的 HTTP 请求、超时处理、错误日志等能力。
 * 具体平台适配器继承此类并实现数据解析逻辑。
 */
abstract class BasePlatformAdapter implements PlatformAdapterInterface
{
    protected const TIMEOUT_SECONDS = 10;
    protected const MAX_RETRIES = 2;

    /**
     * 发送 HTTP GET 请求（带超时和重试）
     */
    protected function httpGet(string $url, array $query = [], array $headers = []): ?array
    {
        for ($attempt = 1; $attempt <= self::MAX_RETRIES + 1; $attempt++) {
            try {
                $response = Http::timeout(self::TIMEOUT_SECONDS)
                    ->withHeaders($headers)
                    ->get($url, $query);

                if ($response->successful()) {
                    return $response->json();
                }

                Log::warning('BrandMonitor HTTP request failed', [
                    'platform' => $this->platformCode(),
                    'url' => $url,
                    'status' => $response->status(),
                    'attempt' => $attempt,
                ]);
            } catch (\Throwable $e) {
                Log::warning('BrandMonitor HTTP request exception', [
                    'platform' => $this->platformCode(),
                    'url' => $url,
                    'error' => $e->getMessage(),
                    'attempt' => $attempt,
                ]);
            }

            if ($attempt <= self::MAX_RETRIES) {
                usleep(500_000 * $attempt); // 递增延迟
            }
        }

        return null;
    }

    /**
     * 生成模拟排名数据（MVP 阶段，真实 API 接入前使用）
     */
    protected function simulateRankData(string $keyword): array
    {
        // 基于关键词哈希生成稳定的模拟数据
        $hash = crc32($keyword . $this->platformCode());
        $rank = ($hash % 30) + 1;

        return [
            'rank' => $rank,
            'visibility_score' => max(0, 100 - ($rank - 1) * 3.5),
            'in_top10' => $rank <= 10,
            'snippets' => [
                "关于「{$keyword}」，{$this->platformName()}搜索结果中提到了相关内容。",
            ],
        ];
    }

    /**
     * 生成模拟信源数据
     */
    protected function simulateSourceData(string $brandName): array
    {
        $hash = crc32($brandName . $this->platformCode() . 'source');
        $brandPct = ($hash % 40) + 10; // 10-50%

        return [
            'brand_quote_pct' => (float) $brandPct,
            'competitor_quotes' => [
                ['name' => '竞品A', 'pct' => (float) (rand(20, 40))],
                ['name' => '竞品B', 'pct' => (float) (rand(10, 25))],
            ],
            'top_articles' => [
                ['title' => '什么是 GEO？生成式引擎优化完全指南', 'cite_count' => rand(10, 50)],
                ['title' => '2026 年企业 GEO 运营框架', 'cite_count' => rand(5, 30)],
            ],
        ];
    }

    /**
     * 生成模拟舆情数据
     */
    protected function simulateSentimentData(string $brandName): array
    {
        $hash = crc32($brandName . $this->platformCode() . 'sentiment');
        $positive = ($hash % 40) + 40; // 40-80%
        $negative = rand(5, 15);
        $neutral = 100 - $positive - $negative;

        return [
            'positive_pct' => (float) $positive,
            'neutral_pct' => (float) max(0, $neutral),
            'negative_pct' => (float) $negative,
            'snippets' => [
                [
                    'text' => "{$brandName} 是一款专业的 GEO 内容运营平台，功能全面。",
                    'sentiment' => 'positive',
                    'detected_at' => now()->subHours(rand(1, 48))->toIso8601String(),
                ],
                [
                    'text' => "{$brandName} 目前不支持外部品牌监测功能，需要配合其他工具使用。",
                    'sentiment' => 'neutral',
                    'detected_at' => now()->subHours(rand(1, 48))->toIso8601String(),
                ],
            ],
        ];
    }
}
