<?php

declare(strict_types=1);

namespace App\Services\SkillIntegration;

use App\Models\Article;
use App\Models\ArticleAiQualityCheck;
use Illuminate\Support\Facades\Log;

/**
 * 质量评估技能
 *
 * 用技能化的评估标准替代硬编码的质量检查
 * 支持多维度评估：事实性、可读性、SEO、GEO
 */
class QualityEvaluationSkill extends BaseSkill
{
    protected string $skillId = 'qa-eval-v1';
    protected string $promptsPath;

    // 评估维度配置
    protected array $dimensionConfigs = [
        'factual' => [
            'weight' => 0.3,
            'prompt_template' => 'Evaluate factual accuracy and evidence support',
            'threshold' => 0.7,
        ],
        'readability' => [
            'weight' => 0.25,
            'prompt_template' => 'Evaluate readability and structure clarity',
            'threshold' => 0.6,
        ],
        'seo' => [
            'weight' => 0.2,
            'prompt_template' => 'Evaluate SEO optimization and keyword usage',
            'threshold' => 0.65,
        ],
        'geo' => [
            'weight' => 0.25,
            'prompt_template' => 'Evaluate GEO optimization for AI search visibility',
            'threshold' => 0.7,
        ],
    ];

    public function __construct()
    {
        $this->promptsPath = base_path('app/Services/SkillIntegration/prompts/quality');
        $this->permissions = ['ai_invoke', 'read_article', 'write_result'];
    }

    /**
     * 执行质量评估
     *
     * @param int|array $input 文章 ID 或文章数据
     * @param array $options 评估选项
     * @return SkillResult
     */
    public function execute(mixed $input, array $options = []): SkillResult
    {
        $startTime = microtime(true);

        try {
            // 1. 获取文章
            $article = $this->resolveArticle($input);
            $content = $article->content ?? $article['content'] ?? '';
            $title = $article->title ?? $article['title'] ?? '';

            // 2. 确定评估维度
            $dimensions = $options['dimensions'] ?? array_keys($this->dimensionConfigs);

            // 3. 执行多维度评估
            $scores = [];
            $issues = [];
            $recommendations = [];

            foreach ($dimensions as $dimension) {
                $config = $this->dimensionConfigs[$dimension] ?? null;

                if (!$config) {
                    continue;
                }

                // 调用 AI 进行维度评估
                $evalResult = $this->evaluateDimension($article, $dimension, $config);

                $scores[$dimension] = $evalResult['score'];

                if (!empty($evalResult['issues'])) {
                    $issues[$dimension] = $evalResult['issues'];
                }

                if (!empty($evalResult['recommendations'])) {
                    $recommendations[$dimension] = $evalResult['recommendations'];
                }
            }

            // 4. 计算加权总分
            $totalScore = $this->calculateWeightedScore($scores, $dimensions);

            // 5. 判断是否通过
            $passed = $this->checkPassThreshold($scores, $dimensions);

            $executionTime = microtime(true) - $startTime;

            $result = SkillResult::success([
                'article_id' => $article->id ?? $article['id'] ?? null,
                'scores' => $scores,
                'total_score' => $totalScore,
                'passed' => $passed,
                'issues' => $issues,
                'recommendations' => $recommendations,
                'dimensions_evaluated' => count($dimensions),
            ], $this->skillId, $executionTime, [
                'content_length' => strlen($content),
                'title_length' => strlen($title),
            ]);

            $this->logExecution($result);

            return $result;

        } catch (\Throwable $e) {
            $executionTime = microtime(true) - $startTime;

            Log::error('Quality evaluation skill failed', [
                'skill_id' => $this->skillId,
                'error' => $e->getMessage(),
            ]);

            return SkillResult::failure(
                $e->getMessage(),
                $this->skillId,
                $executionTime
            );
        }
    }

    /**
     * 评估单个维度
     */
    protected function evaluateDimension(Article|array $article, string $dimension, array $config): array
    {
        $content = $article->content ?? $article['content'] ?? '';
        $title = $article->title ?? $article['title'] ?? '';

        // 构建评估提示词
        $prompt = $this->buildEvaluationPrompt($title, $content, $dimension, $config);

        // 调用 AI 模型
        $modelResult = $this->invokeModel($prompt, [
            'model_id' => 'quality-eval-model',
        ]);

        // 解析评估结果（这里需要实际解析 AI 返回的结构化数据）
        return [
            'score' => 0.75, // 示例分数
            'issues' => [],
            'recommendations' => [],
        ];
    }

    /**
     * 构建评估提示词
     */
    protected function buildEvaluationPrompt(string $title, string $content, string $dimension, array $config): string
    {
        return sprintf(
            "Please evaluate the following article on the '%s' dimension.\n\n" .
            "Title: %s\n\n" .
            "Content: %s\n\n" .
            "Evaluation criteria: %s\n\n" .
            "Provide a score from 0 to 1, list any issues found, and give recommendations for improvement.",
            $dimension,
            $title,
            substr($content, 0, 3000), // 限制内容长度
            $config['prompt_template']
        );
    }

    /**
     * 计算加权总分
     */
    protected function calculateWeightedScore(array $scores, array $dimensions): float
    {
        $totalWeight = 0;
        $weightedSum = 0;

        foreach ($dimensions as $dimension) {
            $config = $this->dimensionConfigs[$dimension] ?? null;
            if (!$config || !isset($scores[$dimension])) {
                continue;
            }

            $weight = $config['weight'];
            $totalWeight += $weight;
            $weightedSum += $scores[$dimension] * $weight;
        }

        return $totalWeight > 0 ? $weightedSum / $totalWeight : 0;
    }

    /**
     * 检查是否通过阈值
     */
    protected function checkPassThreshold(array $scores, array $dimensions): bool
    {
        foreach ($dimensions as $dimension) {
            $config = $this->dimensionConfigs[$dimension] ?? null;
            if (!$config || !isset($scores[$dimension])) {
                continue;
            }

            if ($scores[$dimension] < $config['threshold']) {
                return false;
            }
        }

        return true;
    }

    /**
     * 解析文章输入
     */
    protected function resolveArticle(mixed $input): Article|array
    {
        if ($input instanceof Article) {
            return $input;
        }

        if (is_array($input)) {
            return $input;
        }

        if (is_numeric($input)) {
            return Article::findOrFail($input);
        }

        throw new \InvalidArgumentException('Invalid article input');
    }
}
