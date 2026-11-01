<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\BrandActivityLog;
use App\Models\BrandMonitorResult;
use App\Models\GeoDiagnosisRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 清理过期的诊断和监测记录。
 *
 * - 每篇文章保留最近 10 次诊断记录
 * - 品牌监测结果保留最近 30 天
 * - 活动日志保留最近 30 天
 */
final class CleanupOldDiagnosisJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function handle(): void
    {
        $this->cleanupDiagnosisRuns();
        $this->cleanupMonitorResults();
        $this->cleanupActivityLogs();

        Log::info('CleanupOldDiagnosisJob: completed');
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('CleanupOldDiagnosisJob: failed', [
            'error' => $exception?->getMessage(),
        ]);
    }

    /**
     * 每篇文章保留最近 10 次诊断记录
     */
    private function cleanupDiagnosisRuns(): void
    {
        // 获取所有有诊断记录的文章 ID
        $articleIds = GeoDiagnosisRun::query()
            ->select('article_id')
            ->distinct()
            ->pluck('article_id');

        $deletedCount = 0;
        foreach ($articleIds as $articleId) {
            // 获取该文章保留范围外的记录 ID
            $keepIds = GeoDiagnosisRun::query()
                ->where('article_id', $articleId)
                ->orderByDesc('created_at')
                ->limit(10)
                ->pluck('id');

            $toDelete = GeoDiagnosisRun::query()
                ->where('article_id', $articleId)
                ->whereNotIn('id', $keepIds)
                ->delete();

            $deletedCount += $toDelete;
        }

        if ($deletedCount > 0) {
            Log::info('CleanupOldDiagnosisJob: cleaned diagnosis runs', ['deleted' => $deletedCount]);
        }
    }

    /**
     * 清理 30 天前的品牌监测结果
     */
    private function cleanupMonitorResults(): void
    {
        $deleted = BrandMonitorResult::query()
            ->where('detected_at', '<', now()->subDays(30))
            ->delete();

        if ($deleted > 0) {
            Log::info('CleanupOldDiagnosisJob: cleaned monitor results', ['deleted' => $deleted]);
        }
    }

    /**
     * 清理 30 天前的活动日志
     */
    private function cleanupActivityLogs(): void
    {
        $deleted = BrandActivityLog::query()
            ->where('created_at', '<', now()->subDays(30))
            ->delete();

        if ($deleted > 0) {
            Log::info('CleanupOldDiagnosisJob: cleaned activity logs', ['deleted' => $deleted]);
        }
    }
}
