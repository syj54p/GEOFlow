<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\BrandMonitor;

use App\Models\BrandPlatform;
use App\Services\GeoFlow\BrandMonitor\PlatformAdapters\ChatGptAdapter;
use App\Services\GeoFlow\BrandMonitor\PlatformAdapters\DoubaoAdapter;
use App\Services\GeoFlow\BrandMonitor\PlatformAdapters\GoogleAiAdapter;
use App\Services\GeoFlow\BrandMonitor\PlatformAdapters\KimiAdapter;
use App\Services\GeoFlow\BrandMonitor\PlatformAdapters\PlatformAdapterInterface;
use App\Services\GeoFlow\BrandMonitor\PlatformAdapters\QianwenAdapter;
use App\Services\GeoFlow\BrandMonitor\PlatformAdapters\WenxinAdapter;
use Illuminate\Support\Facades\Log;

/**
 * 品牌监测编排器。
 *
 * 协调各平台适配器执行品牌监测任务，管理适配器注册和调度。
 */
final class BrandMonitorOrchestrator
{
    /** @var array<string, PlatformAdapterInterface> */
    private array $adapters = [];

    public function __construct(
        private readonly BrandVisibilityService $visibilityService,
        private readonly BrandSourceService $sourceService,
        private readonly BrandSentimentService $sentimentService,
        private readonly ActivityLogger $activityLogger,
    ) {
        $this->registerDefaultAdapters();
    }

    /**
     * 运行全平台品牌监测
     *
     * @return array{results_count: int, errors: list<string>}
     */
    public function runFullMonitor(?int $siteId = null): array
    {
        $errors = [];
        $totalCount = 0;

        // 1. 刷新可见性数据
        try {
            $count = $this->visibilityService->refreshVisibility($this->adapters, $siteId);
            $totalCount += $count;
        } catch (\Throwable $e) {
            $errors[] = '可见性检测失败：' . $e->getMessage();
            Log::error('BrandMonitor visibility refresh failed', ['error' => $e->getMessage()]);
        }

        // 2. 刷新信源数据
        foreach ($this->getEnabledAdapters($siteId) as $code => $adapter) {
            try {
                $this->refreshSourceData($adapter, $siteId);
            } catch (\Throwable $e) {
                $errors[] = "{$adapter->platformName()} 信源分析失败：{$e->getMessage()}";
                Log::error('BrandMonitor source analysis failed', [
                    'platform' => $code,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 3. 刷新舆情数据
        foreach ($this->getEnabledAdapters($siteId) as $code => $adapter) {
            try {
                $this->refreshSentimentData($adapter, $siteId);
            } catch (\Throwable $e) {
                $errors[] = "{$adapter->platformName()} 舆情分析失败：{$e->getMessage()}";
                Log::error('BrandMonitor sentiment analysis failed', [
                    'platform' => $code,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['results_count' => $totalCount, 'errors' => $errors];
    }

    /**
     * 获取可见性数据
     */
    public function getVisibilityData(?array $platformIds = null, ?array $keywordIds = null, ?int $siteId = null): \Illuminate\Support\Collection
    {
        return $this->visibilityService->getVisibilityData($platformIds, $keywordIds, $siteId);
    }

    /**
     * 获取信源分析数据
     */
    public function getSourceAnalysis(string $period = '7d', ?int $siteId = null): array
    {
        return $this->sourceService->getSourceAnalysis($period, $siteId);
    }

    /**
     * 获取舆情分析数据
     */
    public function getSentimentAnalysis(string $period = '7d', ?int $siteId = null): array
    {
        return $this->sentimentService->getSentimentAnalysis($period, $siteId);
    }

    /**
     * 注册平台适配器
     */
    public function registerAdapter(PlatformAdapterInterface $adapter): void
    {
        $this->adapters[$adapter->platformCode()] = $adapter;
    }

    /**
     * 获取已注册的适配器
     *
     * @return array<string, PlatformAdapterInterface>
     */
    public function getAdapters(): array
    {
        return $this->adapters;
    }

    /**
     * 获取已启用平台的适配器
     *
     * @return array<string, PlatformAdapterInterface>
     */
    private function getEnabledAdapters(?int $siteId = null): array
    {
        $enabledCodes = BrandPlatform::query()
            ->when($siteId !== null, fn($q) => $q->where('site_id', $siteId))
            ->enabled()
            ->pluck('code')
            ->toArray();

        return array_filter(
            $this->adapters,
            fn($code) => in_array($code, $enabledCodes, true),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * 刷新信源数据
     */
    private function refreshSourceData(PlatformAdapterInterface $adapter, ?int $siteId = null): void
    {
        $platform = BrandPlatform::query()
            ->where('code', $adapter->platformCode())
            ->when($siteId !== null, fn($q) => $q->where('site_id', $siteId))
            ->first();

        if ($platform === null) {
            return;
        }

        $config = $platform->api_config ?? [];
        // 信源分析需要品牌名称，从站点设置获取
        $brandName = 'GEOFlow'; // TODO: 从 SiteSetting 获取

        $sourceData = $adapter->analyzeSources($brandName, $config);

        // 将信源数据写入最新的监测结果（或创建新记录）
        Log::info('BrandMonitor source data refreshed', [
            'platform' => $adapter->platformCode(),
            'brand_quote_pct' => $sourceData['brand_quote_pct'] ?? 0,
        ]);
    }

    /**
     * 刷新舆情数据
     */
    private function refreshSentimentData(PlatformAdapterInterface $adapter, ?int $siteId = null): void
    {
        $platform = BrandPlatform::query()
            ->where('code', $adapter->platformCode())
            ->when($siteId !== null, fn($q) => $q->where('site_id', $siteId))
            ->first();

        if ($platform === null) {
            return;
        }

        $config = $platform->api_config ?? [];
        $brandName = 'GEOFlow'; // TODO: 从 SiteSetting 获取

        $sentimentData = $adapter->analyzeSentiment($brandName, $config);

        Log::info('BrandMonitor sentiment data refreshed', [
            'platform' => $adapter->platformCode(),
            'positive_pct' => $sentimentData['positive_pct'] ?? 0,
            'negative_pct' => $sentimentData['negative_pct'] ?? 0,
        ]);
    }

    /**
     * 注册默认平台适配器
     */
    private function registerDefaultAdapters(): void
    {
        $this->registerAdapter(new DoubaoAdapter());
        $this->registerAdapter(new KimiAdapter());
        $this->registerAdapter(new QianwenAdapter());
        $this->registerAdapter(new WenxinAdapter());
        $this->registerAdapter(new GoogleAiAdapter());
        $this->registerAdapter(new ChatGptAdapter());
    }
}
