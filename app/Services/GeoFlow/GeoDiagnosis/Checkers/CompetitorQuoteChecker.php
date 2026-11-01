<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\GeoDiagnosis\Checkers;

use App\Models\Article;
use App\Models\BrandCompetitor;
use App\Models\BrandMonitorResult;
use App\Services\GeoFlow\GeoDiagnosis\Contracts\DiagnosisCheckerInterface;

/**
 * AV-02: 竞品引用占比 ≤ 50%
 *
 * 检查在 AI 搜索结果中，竞品的引用占比是否超过 50%。
 * 过高的竞品引用占比意味着本站内容在 AI 回答中被边缘化。
 */
final class CompetitorQuoteChecker implements DiagnosisCheckerInterface
{
    private const MAX_COMPETITOR_PCT = 50.0;

    public function checkId(): string
    {
        return 'AV-02';
    }

    public function dimension(): string
    {
        return 'ai_visibility';
    }

    public function defaultSeverity(): string
    {
        return 'medium';
    }

    public function check(Article $article, array $context = []): array
    {
        // 从上下文获取信源分析数据
        $sourceData = $context['source_analysis'] ?? null;

        if ($sourceData === null) {
            // 从数据库获取最新的信源分析数据
            $latestResult = BrandMonitorResult::query()
                ->latestDetected()
                ->whereNotNull('source_analysis')
                ->where('source_analysis', '!=', '{}')
                ->first();

            $sourceData = $latestResult?->source_analysis;
        }

        if (empty($sourceData)) {
            return [
                'passed' => false,
                'score' => 50,
                'finding' => [
                    'id' => $this->checkId(),
                    'title' => '信源数据不可用',
                    'description' => '暂无 AI 信源分析数据，请先运行品牌监测',
                    'severity' => 'info',
                    'dimension' => $this->dimension(),
                ],
                'recommendation' => null,
            ];
        }

        $brandQuotePct = (float) ($sourceData['brand_quote_pct'] ?? 0);
        $competitorQuotes = $sourceData['competitor_quotes'] ?? [];

        // 计算竞品总引用占比
        $competitorTotalPct = 0;
        $topCompetitor = null;
        foreach ($competitorQuotes as $cq) {
            $pct = (float) ($cq['pct'] ?? 0);
            $competitorTotalPct += $pct;
            if ($topCompetitor === null || $pct > $topCompetitor['pct']) {
                $topCompetitor = ['name' => $cq['name'] ?? '未知', 'pct' => $pct];
            }
        }

        $passed = $competitorTotalPct <= self::MAX_COMPETITOR_PCT;

        if ($passed) {
            return [
                'passed' => true,
                'score' => min(100, (int) (100 - $competitorTotalPct)),
                'finding' => [
                    'id' => $this->checkId(),
                    'title' => '竞品引用占比健康',
                    'description' => "本站引用 {$brandQuotePct}%，竞品总引用 {$competitorTotalPct}%",
                    'severity' => 'info',
                    'dimension' => $this->dimension(),
                ],
                'recommendation' => null,
            ];
        }

        return [
            'passed' => false,
            'score' => max(10, (int) (100 - $competitorTotalPct)),
            'finding' => [
                'id' => $this->checkId(),
                'title' => '竞品引用占比过高',
                'description' => "竞品总引用 {$competitorTotalPct}%（超过 " . self::MAX_COMPETITOR_PCT . '% 警戒线）'
                    . ($topCompetitor ? "，最高：{$topCompetitor['name']}（{$topCompetitor['pct']}%）" : ''),
                'severity' => $this->defaultSeverity(),
                'dimension' => $this->dimension(),
            ],
            'recommendation' => [
                'id' => $this->checkId(),
                'title' => '降低竞品引用占比',
                'description' => 'AI 搜索中竞品被大量引用，本站内容存在感不足',
                'impact_score' => 7,
                'fix_guide' => '1) 增加高质量原创内容 2) 优化核心关键词的 GEO 就绪度 3) 确保 Schema.org 结构化数据完整 4) 在知识库中补充竞品未覆盖的事实',
                'auto_fixable' => false,
            ],
        ];
    }
}
