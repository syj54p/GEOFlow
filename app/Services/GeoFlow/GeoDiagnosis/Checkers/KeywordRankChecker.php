<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\GeoDiagnosis\Checkers;

use App\Models\Article;
use App\Models\BrandMonitorResult;
use App\Services\GeoFlow\GeoDiagnosis\Contracts\DiagnosisCheckerInterface;

/**
 * AV-01: 至少 1 个关键词进入 AI 搜索前 10
 *
 * 检查文章关联的监控关键词是否至少有一个在 AI 平台搜索中进入前 10 名。
 * 这是衡量文章 AI 可见度的核心指标。
 */
final class KeywordRankChecker implements DiagnosisCheckerInterface
{
    public function checkId(): string
    {
        return 'AV-01';
    }

    public function dimension(): string
    {
        return 'ai_visibility';
    }

    public function defaultSeverity(): string
    {
        return 'high';
    }

    public function check(Article $article, array $context = []): array
    {
        // 从上下文获取品牌监测数据，或从数据库查询
        $monitorResults = $context['monitor_results'] ?? null;

        if ($monitorResults === null) {
            // 获取与文章关键词相关的最新监测结果
            $keywords = $this->getArticleKeywords($article);
            if (empty($keywords)) {
                return $this->noDataResult('文章未设置关键词，无法评估 AI 搜索排名');
            }

            $monitorResults = BrandMonitorResult::query()
                ->whereIn('keyword_id', function ($query) use ($keywords) {
                    $query->select('id')
                        ->from('brand_keywords')
                        ->whereIn('keyword', $keywords);
                })
                ->latestDetected()
                ->limit(20)
                        ->get();
        }

        if ($monitorResults->isEmpty()) {
            return $this->noDataResult('暂无品牌监测数据，请先在"品牌监测"中配置关键词和平台');
        }

        // 统计进入前 10 的关键词数
        $top10Keywords = [];
        $allRanks = [];

        foreach ($monitorResults as $result) {
            if ($result->search_rank !== null) {
                $keywordName = $result->keyword->keyword ?? '未知';
                $allRanks[] = [
                    'keyword' => $keywordName,
                    'rank' => $result->search_rank,
                    'platform' => $result->platform->name ?? '未知',
                ];

                if ($result->is_in_top10) {
                    $top10Keywords[] = $keywordName;
                }
            }
        }

        if (!empty($top10Keywords)) {
            $uniqueTop10 = array_unique($top10Keywords);
            return [
                'passed' => true,
                'score' => min(100, 60 + count($uniqueTop10) * 15),
                'finding' => [
                    'id' => $this->checkId(),
                    'title' => '关键词进入 AI 搜索前 10',
                    'description' => count($uniqueTop10) . ' 个关键词进入前 10：' . implode(', ', array_slice($uniqueTop10, 0, 3)),
                    'severity' => 'info',
                    'dimension' => $this->dimension(),
                ],
                'recommendation' => null,
            ];
        }

        // 没有进入前 10 的关键词
        $bestRank = !empty($allRanks) ? min(array_column($allRanks, 'rank')) : null;
        $bestInfo = null;
        if ($bestRank !== null) {
            foreach ($allRanks as $r) {
                if ($r['rank'] === $bestRank) {
                    $bestInfo = $r;
                    break;
                }
            }
        }

        return [
            'passed' => false,
            'score' => $bestRank !== null ? max(10, 60 - ($bestRank - 10) * 3) : 10,
            'finding' => [
                'id' => $this->checkId(),
                'title' => '无关键词进入 AI 搜索前 10',
                'description' => $bestInfo
                    ? "最佳排名：「{$bestInfo['keyword']}」在 {$bestInfo['platform']} 排第 {$bestInfo['rank']} 名"
                    : '所有监控关键词排名均在 10 名之后',
                'severity' => $this->defaultSeverity(),
                'dimension' => $this->dimension(),
            ],
            'recommendation' => [
                'id' => $this->checkId(),
                'title' => '提升关键词 AI 搜索排名',
                'description' => '至少 1 个关键词需进入 AI 搜索前 10 才能有效提升可见度',
                'impact_score' => 9,
                'fix_guide' => '优化文章内容和结构，确保核心关键词在标题、正文和元数据中充分出现',
                'auto_fixable' => false,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function getArticleKeywords(Article $article): array
    {
        $keywords = [];

        if (!empty($article->keywords)) {
            $parts = preg_split('/[,，;；\s]+/u', (string) $article->keywords);
            foreach ($parts as $p) {
                $p = trim($p);
                if (mb_strlen($p) >= 2) {
                    $keywords[] = $p;
                }
            }
        }

        if (!empty($article->original_keyword)) {
            $keywords[] = (string) $article->original_keyword;
        }

        return array_values(array_unique($keywords));
    }

    private function noDataResult(string $description): array
    {
        return [
            'passed' => false,
            'score' => 0,
            'finding' => [
                'id' => $this->checkId(),
                'title' => 'AI 可见度数据不可用',
                'description' => $description,
                'severity' => 'info',
                'dimension' => $this->dimension(),
            ],
            'recommendation' => null,
        ];
    }
}
