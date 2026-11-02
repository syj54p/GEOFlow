<?php

declare(strict_types=1);

namespace App\Services\SkillIntegration;

use App\Models\Article;
use Illuminate\Support\Facades\Log;

/**
 * GEO 优化技能
 *
 * 将 yao-open-prompts 的 25 个 GEO 模板封装为可调用技能
 * 用于优化文章内容以提升 AI 搜索可见度
 */
class GeoOptimizationSkill extends BaseSkill
{
    protected string $skillId = 'geo-opt-v1';
    protected string $promptsPath;

    // GEO 提示词模板映射
    protected array $templateMap = [
        'chatgpt' => 'geo-article-ai-friendly-transformation',
        'perplexity' => 'geo-article-ai-friendly-transformation',
        'google_ai' => 'geo-article-ai-friendly-transformation',
        'general' => 'geo-article-ai-friendly-transformation',
    ];

    public function __construct()
    {
        // 使用 yao-open-prompts 的 GEO 模板路径
        $this->promptsPath = base_path('../yao-open-prompts/prompts/08-ai-marketing');
        $this->permissions = ['ai_invoke', 'read_article', 'write_result'];
    }

    /**
     * 执行 GEO 优化
     *
     * @param Article|array $input 文章对象或文章数据
     * @param array $options 优化选项
     * @return SkillResult
     */
    public function execute(mixed $input, array $options = []): SkillResult
    {
        $startTime = microtime(true);

        try {
            // 1. 获取文章内容
            $article = $this->resolveArticle($input);
            $content = $article->content ?? $article['content'] ?? '';

            // 2. 确定目标引擎和模板
            $targetEngine = $options['target_engine'] ?? 'general';
            $templateKey = $this->templateMap[$targetEngine] ?? $this->templateMap['general'];

            // 3. 加载 GEO 优化提示词模板
            $template = $this->loadTemplate($templateKey);

            // 4. 编译提示词
            $prompt = $this->compilePrompt($template, [
                'article_title' => $article->title ?? $article['title'] ?? '',
                'article_content' => $content,
                'target_engine' => $targetEngine,
                'optimization_dimensions' => implode(', ', $options['dimensions'] ?? ['structure', 'semantics', 'evidence']),
            ]);

            // 5. 调用 AI 模型执行优化
            $modelResult = $this->invokeModel($prompt, [
                'model_id' => $options['model_id'] ?? null,
            ]);

            // 6. 生成优化结果
            $executionTime = microtime(true) - $startTime;

            $result = SkillResult::success([
                'optimized_content' => $modelResult['content'] ?? $content,
                'original_content' => $content,
                'target_engine' => $targetEngine,
                'template_used' => $templateKey,
                'changes' => $this->generateDiff($content, $modelResult['content'] ?? ''),
                'score_improvement' => $modelResult['score_delta'] ?? 0,
            ], $this->skillId, $executionTime, [
                'prompt_length' => strlen($prompt),
                'response_length' => strlen($modelResult['content'] ?? ''),
            ]);

            $this->logExecution($result);

            return $result;

        } catch (\Throwable $e) {
            $executionTime = microtime(true) - $startTime;

            Log::error('GEO optimization skill failed', [
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

    /**
     * 生成内容差异
     */
    protected function generateDiff(string $original, string $optimized): array
    {
        // 简化的差异生成，实际可以使用更复杂的 diff 算法
        return [
            'original_length' => strlen($original),
            'optimized_length' => strlen($optimized),
            'changed' => $original !== $optimized,
        ];
    }

    /**
     * 获取可用的优化模板列表
     */
    public function getAvailableTemplates(): array
    {
        $templates = [];

        if (is_dir($this->promptsPath)) {
            $files = scandir($this->promptsPath);
            foreach ($files as $file) {
                if (str_ends_with($file, '.md') && !str_starts_with($file, '.')) {
                    $templates[] = pathinfo($file, PATHINFO_FILENAME);
                }
            }
        }

        return $templates;
    }
}
