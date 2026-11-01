<?php

declare(strict_types=1);

namespace App\Services\GeoFlow;

use App\Models\AiModel;
use App\Models\Article;
use App\Models\Prompt;
use App\Models\Task;
use Illuminate\Support\Facades\Log;

/**
 * Schema.org JSON-LD 结构化数据生成器。
 *
 * 基于文章元数据（标题、关键词、品牌信息）调用 AI 模型生成
 * 符合 Schema.org 规范的 JSON-LD 标记，用于 GEO 优化。
 */
final class SchemaOrgJsonLdGenerator
{
    private const DEFAULT_PROMPT_NAME = 'Schema.org结构化数据·JSON-LD生成';

    public function __construct(
        private readonly ArticleContentPromptRenderer $promptRenderer,
        private readonly ArticleContentGenerationService $generationService,
    ) {}

    /**
     * 为文章生成 Schema.org JSON-LD 数据。
     *
     * @return array<string, mixed>|null 成功时返回 JSON-LD 数组，失败返回 null
     */
    public function generateForArticle(Article $article, ?int $promptId = null): ?array
    {
        $prompt = $this->resolvePrompt($promptId);
        if ($prompt === null) {
            Log::warning('Schema.org generator: prompt template not found.', [
                'prompt_id' => $promptId,
                'article_id' => (int) $article->id,
            ]);

            return null;
        }

        $model = $this->resolveModel($article);
        if ($model === null) {
            Log::warning('Schema.org generator: AI model not available.', [
                'article_id' => (int) $article->id,
            ]);

            return null;
        }

        $brandInfo = $this->extractBrandInfo($article);
        $keyword = $this->extractKeyword($article);

        $renderedPrompt = $this->promptRenderer->renderForEditor(
            title: (string) $article->title,
            keyword: $keyword,
            promptContent: (string) $prompt->content,
            knowledgeContext: '',
            imageContext: '',
            originalContent: mb_substr((string) ($article->content ?? ''), 0, 2000),
            brandInfo: $brandInfo,
        );

        try {
            $response = $this->generationService->generate(
                aiModel: $model,
                prompt: $renderedPrompt,
                beforeProvider: null,
                instructions: '只输出 JSON-LD 代码块，不输出任何解释文字。确保 JSON 语法合法。',
                providerTimeout: 120,
            );

            $rawText = $response->text ?? '';

            return $this->parseJsonLd($rawText);
        } catch (\Throwable $exception) {
            Log::warning('Schema.org generation AI call failed.', [
                'article_id' => (int) $article->id,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * 从 AI 返回的文本中提取 JSON-LD 数据。
     *
     * @return array<string, mixed>|null
     */
    private function parseJsonLd(string $rawText): ?array
    {
        // 尝试从 <script> 标签中提取
        if (preg_match('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/siu', $rawText, $matches)) {
            $decoded = json_decode(trim($matches[1]), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // 尝试从 ```json 代码块中提取
        if (preg_match('/```(?:json|html)?\s*\n?(.*?)\n?```/siu', $rawText, $matches)) {
            $inner = trim($matches[1]);
            // 如果代码块内包含 <script> 标签，递归提取
            if (str_contains($inner, '<script')) {
                return $this->parseJsonLd($inner);
            }
            $decoded = json_decode($inner, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // 尝试直接解析整个文本
        $decoded = json_decode(trim($rawText), true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // 尝试找到第一个 { 到最后一个 } 之间的内容
        $start = strpos($rawText, '{');
        $end = strrpos($rawText, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $jsonSubstring = substr($rawText, $start, $end - $start + 1);
            $decoded = json_decode($jsonSubstring, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private function resolvePrompt(?int $promptId): ?Prompt
    {
        if ($promptId !== null && $promptId > 0) {
            return Prompt::query()->find($promptId);
        }

        return Prompt::query()
            ->where('name', self::DEFAULT_PROMPT_NAME)
            ->where('type', 'content')
            ->first();
    }

    private function resolveModel(Article $article): ?AiModel
    {
        // 优先使用文章关联 Task 的 AI 模型
        $article->loadMissing('task.aiModel');
        if ($article->task instanceof Task && $article->task->aiModel instanceof AiModel) {
            return $article->task->aiModel;
        }

        // 回退到系统默认模型
        return AiModel::query()
            ->where('status', 'active')
            ->whereIn('model_type', ['', 'chat'])
            ->orderByDesc('is_default')
            ->first();
    }

    private function extractBrandInfo(Article $article): string
    {
        $article->loadMissing('task');
        if (! $article->task instanceof Task) {
            return '';
        }

        // 从 Task 关联的知识库或作者信息中提取品牌信息
        $author = $article->task->author;
        if ($author !== null) {
            $parts = array_filter([
                isset($author->name) ? "品牌名称：{$author->name}" : null,
                isset($author->description) ? "品牌简介：{$author->description}" : null,
            ]);

            return implode('；', $parts);
        }

        return '';
    }

    private function extractKeyword(Article $article): string
    {
        // 优先使用文章的 keywords 字段
        if (! empty($article->keywords)) {
            return (string) $article->keywords;
        }

        // 回退到 Task 的 auto_keywords
        $article->loadMissing('task');
        if ($article->task instanceof Task && ! empty($article->task->auto_keywords)) {
            return (string) $article->task->auto_keywords;
        }

        return '';
    }
}
