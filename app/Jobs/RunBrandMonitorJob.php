<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\GeoFlow\BrandMonitor\BrandMonitorOrchestrator;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 运行品牌监测任务。
 *
 * 遍历所有已启用平台和活跃关键词，调用各平台适配器获取排名/信源/舆情数据。
 */
final class RunBrandMonitorJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly ?int $siteId = null,
    ) {}

    public function uniqueId(): string
    {
        return 'brand_monitor_' . ($this->siteId ?? 'all');
    }

    public function handle(BrandMonitorOrchestrator $orchestrator): void
    {
        $result = $orchestrator->runFullMonitor($this->siteId);

        Log::info('RunBrandMonitorJob: completed', [
            'site_id' => $this->siteId,
            'results_count' => $result['results_count'],
            'errors_count' => count($result['errors']),
        ]);

        if (!empty($result['errors'])) {
            Log::warning('RunBrandMonitorJob: had errors', [
                'site_id' => $this->siteId,
                'errors' => $result['errors'],
            ]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('RunBrandMonitorJob: failed', [
            'site_id' => $this->siteId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
