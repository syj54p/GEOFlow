<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Services\SkillIntegration\SkillManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 技能 API 控制器
 *
 * 提供技能调用的 REST API
 */
class SkillController extends Controller
{
    protected SkillManager $skillManager;

    public function __construct(SkillManager $skillManager)
    {
        $this->skillManager = $skillManager;
    }

    /**
     * 获取技能列表
     */
    public function index(): JsonResponse
    {
        $skills = $this->skillManager->listSkills();

        return response()->json([
            'success' => true,
            'data' => $skills,
        ]);
    }

    /**
     * 执行 GEO 优化技能
     */
    public function optimize(Request $request): JsonResponse
    {
        $request->validate([
            'article_id' => 'required|integer|exists:articles,id',
            'target_engine' => 'sometimes|string|in:chatgpt,perplexity,google_ai,general',
            'model_id' => 'required|integer|exists:ai_models,id',
            'dimensions' => 'sometimes|array',
            'dimensions.*' => 'string',
        ]);

        $article = Article::findOrFail($request->input('article_id'));

        $result = $this->skillManager->execute('geo-opt', $article, [
            'target_engine' => $request->input('target_engine', 'general'),
            'model_id' => $request->input('model_id'),
            'dimensions' => $request->input('dimensions', ['structure', 'semantics', 'evidence']),
        ]);

        return response()->json([
            'success' => $result->isSuccess(),
            'data' => $result->getData(),
            'message' => $result->getMessage(),
            'metrics' => $result->getMetrics(),
            'execution_time' => $result->getExecutionTime(),
        ]);
    }

    /**
     * 执行质量评估技能
     */
    public function evaluate(Request $request): JsonResponse
    {
        $request->validate([
            'article_id' => 'required|integer|exists:articles,id',
            'dimensions' => 'sometimes|array',
            'dimensions.*' => 'string|in:factual,readability,seo,geo',
        ]);

        $article = Article::findOrFail($request->input('article_id'));

        $result = $this->skillManager->execute('qa-eval', $article, [
            'dimensions' => $request->input('dimensions', ['factual', 'readability', 'seo', 'geo']),
        ]);

        return response()->json([
            'success' => $result->isSuccess(),
            'data' => $result->getData(),
            'message' => $result->getMessage(),
            'metrics' => $result->getMetrics(),
            'execution_time' => $result->getExecutionTime(),
        ]);
    }
}
