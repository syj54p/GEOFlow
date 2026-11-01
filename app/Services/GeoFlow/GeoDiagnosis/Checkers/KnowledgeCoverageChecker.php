<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\GeoDiagnosis\Checkers;

use App\Models\Article;
use App\Models\KnowledgeBase;
use App\Services\GeoFlow\GeoDiagnosis\Contracts\DiagnosisCheckerInterface;

/**
 * KA-01: 知识库关键事实覆盖率 ≥ 60%
 *
 * 检查文章内容是否覆盖了关联知识库中的关键事实。
 * 高覆盖率意味着内容与权威知识源对齐，AI 引擎更信任。
 */
final class KnowledgeCoverageChecker implements DiagnosisCheckerInterface
{
    public function checkId(): string
    {
        return 'KA-01';
    }

    public function dimension(): string
    {
        return 'knowledge_alignment';
    }

    public function defaultSeverity(): string
    {
        return 'high';
    }

    public function check(Article $article, array $context = []): array
    {
        /** @var array<int, KnowledgeBase>|null $knowledgeBases */
        $knowledgeBases = $context['knowledge_bases'] ?? null;

        if ($knowledgeBases === null) {
            // 从文章关联的知识库加载
            $knowledgeBases = $article->aiQualityKnowledgeBases ?? collect();
        }

        if ($knowledgeBases->isEmpty()) {
            return [
                'passed' => false,
                'score' => 0,
                'finding' => [
                    'id' => $this->checkId(),
                    'title' => '未关联知识库',
                    'description' => '文章未关联任何知识库，无法评估知识覆盖率',
                    'severity' => $this->defaultSeverity(),
                    'dimension' => $this->dimension(),
                ],
                'recommendation' => [
                    'id' => $this->checkId(),
                    'title' => '关联知识库',
                    'description' => '为文章关联相关领域的知识库，提升内容权威性',
                    'impact_score' => 8,
                    'fix_guide' => '在文章编辑页的"知识库关联"面板中选择相关知识库',
                    'auto_fixable' => false,
                ],
            ];
        }

        $articleContent = mb_strtolower(strip_tags((string) $article->content));
        $totalFacts = 0;
        $coveredFacts = 0;

        foreach ($knowledgeBases as $kb) {
            // 从知识库内容中提取关键术语/事实
            $kbContent = mb_strtolower((string) $kb->content);
            $keyTerms = $this->extractKeyTerms($kbContent);
            $totalFacts += count($keyTerms);

            foreach ($keyTerms as $term) {
                if (mb_strpos($articleContent, $term) !== false) {
                    $coveredFacts++;
                }
            }
        }

        $coveragePct = $totalFacts > 0 ? (int) round(($coveredFacts / $totalFacts) * 100) : 0;
        $passed = $coveragePct >= 60;

        if ($passed) {
            return [
                'passed' => true,
                'score' => min(100, $coveragePct + 10),
                'finding' => [
                    'id' => $this->checkId(),
                    'title' => '知识库覆盖率达标',
                    'description' => "覆盖 {$coveredFacts}/{$totalFacts} 个关键事实（{$coveragePct}%）",
                    'severity' => 'info',
                    'dimension' => $this->dimension(),
                ],
                'recommendation' => null,
            ];
        }

        return [
            'passed' => false,
            'score' => $coveragePct,
            'finding' => [
                'id' => $this->checkId(),
                'title' => '知识库覆盖率不足',
                'description' => "仅覆盖 {$coveredFacts}/{$totalFacts} 个关键事实（{$coveragePct}%），低于 60% 阈值",
                'severity' => $this->defaultSeverity(),
                'dimension' => $this->dimension(),
            ],
            'recommendation' => [
                'id' => $this->checkId(),
                'title' => '补充知识库关键内容',
                'description' => '在文章中补充知识库中的关键事实和术语',
                'impact_score' => 8,
                'fix_guide' => '参考关联知识库的核心内容，在文章中补充未覆盖的关键术语和事实描述',
                'auto_fixable' => false,
            ],
        ];
    }

    /**
     * 从知识库内容中提取关键术语（简化版：提取高频名词短语）
     *
     * @return list<string>
     */
    private function extractKeyTerms(string $content): array
    {
        // 移除 HTML 标签和多余空白
        $text = strip_tags($content);
        $text = preg_replace('/\s+/u', ' ', $text);

        // 提取 2-8 字的中文词组作为关键术语候选
        preg_match_all('/[\x{4e00}-\x{9fff}]{2,8}/u', $text ?? '', $matches);
        $candidates = $matches[0] ?? [];

        // 去重并取前 20 个（简化策略）
        $unique = array_unique($candidates);

        return array_slice(array_values($unique), 0, 20);
    }
}
