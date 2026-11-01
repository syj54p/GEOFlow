<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\GeoDiagnosis\Checkers;

use App\Models\Article;
use App\Models\KnowledgeBase;
use App\Services\GeoFlow\GeoDiagnosis\Contracts\DiagnosisCheckerInterface;

/**
 * KA-03: 无知识库矛盾内容
 *
 * 检查文章内容是否与关联知识库中的事实存在矛盾。
 * 矛盾内容会降低 AI 引擎对内容的信任度。
 *
 * MVP 阶段使用简化策略：检查文章是否包含与知识库明确冲突的否定表述。
 */
final class KnowledgeContradictionChecker implements DiagnosisCheckerInterface
{
    public function checkId(): string
    {
        return 'KA-03';
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
        /** @var \Illuminate\Database\Eloquent\Collection<int, KnowledgeBase>|null $knowledgeBases */
        $knowledgeBases = $context['knowledge_bases'] ?? null;

        if ($knowledgeBases === null) {
            $knowledgeBases = $article->aiQualityKnowledgeBases ?? collect();
        }

        if ($knowledgeBases->isEmpty()) {
            // 无关联知识库时，默认通过
            return [
                'passed' => true,
                'score' => 100,
                'finding' => [
                    'id' => $this->checkId(),
                    'title' => '无知识库可对比',
                    'description' => '文章未关联知识库，跳过矛盾检测',
                    'severity' => 'info',
                    'dimension' => $this->dimension(),
                ],
                'recommendation' => null,
            ];
        }

        $articleContent = strip_tags((string) $article->content);
        $contradictions = [];

        foreach ($knowledgeBases as $kb) {
            $kbContent = (string) $kb->content;

            // 提取知识库中的关键断言（简化：提取含"是"、"不是"的判断句）
            $kbAssertions = $this->extractAssertions($kbContent);
            $articleAssertions = $this->extractAssertions($articleContent);

            // 检查是否存在明显矛盾（否定词 + 相同主语）
            foreach ($kbAssertions as $kbAssert) {
                foreach ($articleAssertions as $artAssert) {
                    if ($this->isContradictory($kbAssert, $artAssert)) {
                        $contradictions[] = [
                            'kb_assertion' => mb_substr($kbAssert, 0, 50),
                            'article_assertion' => mb_substr($artAssert, 0, 50),
                            'knowledge_base' => $kb->name,
                        ];
                    }
                }
            }
        }

        if (empty($contradictions)) {
            return [
                'passed' => true,
                'score' => 100,
                'finding' => [
                    'id' => $this->checkId(),
                    'title' => '未发现知识库矛盾',
                    'description' => '文章内容与 ' . $knowledgeBases->count() . ' 个关联知识库无矛盾',
                    'severity' => 'info',
                    'dimension' => $this->dimension(),
                ],
                'recommendation' => null,
            ];
        }

        $sample = $contradictions[0];

        return [
            'passed' => false,
            'score' => max(0, 100 - count($contradictions) * 25),
            'finding' => [
                'id' => $this->checkId(),
                'title' => '检测到知识库矛盾',
                'description' => "发现 " . count($contradictions) . " 处潜在矛盾。示例：知识库「{$sample['knowledge_base']}」表述与文章内容不一致",
                'severity' => $this->defaultSeverity(),
                'dimension' => $this->dimension(),
            ],
            'recommendation' => [
                'id' => $this->checkId(),
                'title' => '修正矛盾内容',
                'description' => '文章内容与知识库事实存在冲突，会降低 AI 引擎信任度',
                'impact_score' => 9,
                'fix_guide' => "对照知识库「{$sample['knowledge_base']}」的内容，修正文章中的矛盾表述",
                'auto_fixable' => false,
            ],
        ];
    }

    /**
     * 从文本中提取断言句（含判断词的句子）
     *
     * @return list<string>
     */
    private function extractAssertions(string $text): array
    {
        $text = strip_tags($text);
        // 按句号、问号、感叹号分割
        $sentences = preg_split('/[。！？\n]+/u', $text);
        $assertions = [];

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if (mb_strlen($sentence) < 5 || mb_strlen($sentence) > 200) {
                continue;
            }
            // 包含判断词的视为断言
            if (preg_match('/(是|不是|不属于|并非|不等于|称为|定义为)/u', $sentence)) {
                $assertions[] = $sentence;
            }
        }

        return array_slice($assertions, 0, 30);
    }

    /**
     * 判断两个断言是否矛盾（简化策略）
     */
    private function isContradictory(string $a, string $b): bool
    {
        $aLower = mb_strtolower($a);
        $bLower = mb_strtolower($b);

        // 提取主语（断言的前 4 个字作为主语近似）
        $aSubject = mb_substr($aLower, 0, 4);
        $bSubject = mb_substr($bLower, 0, 4);

        // 主语相同但一个含"不是/并非"另一个含"是"
        if ($aSubject === $bSubject) {
            $aNeg = preg_match('/(不是|并非|不属于|不等于)/u', $a);
            $bNeg = preg_match('/(不是|并非|不属于|不等于)/u', $b);
            $aPos = preg_match('/(?<!不)(?<!并非)是(?!不)/u', $a);
            $bPos = preg_match('/(?<!不)(?<!并非)是(?!不)/u', $b);

            return ($aNeg && $bPos) || ($aPos && $bNeg);
        }

        return false;
    }
}
