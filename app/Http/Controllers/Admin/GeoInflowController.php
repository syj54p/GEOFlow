<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\GeoInflowAction;
use App\Models\Task;
use App\Services\GeoFlow\GeoInflowOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GeoInflowController extends Controller
{
    public function __construct(
        private readonly GeoInflowOrchestrator $orchestrator,
    ) {}

    /**
     * GEOInflow 仪表盘页面（返回视图）。
     */
    public function index(): View
    {
        $dashboard = $this->orchestrator->dashboard();

        return view('admin.geo-inflow.index', [
            'pageTitle' => __('admin.geo_inflow.page_title'),
            'activeMenu' => 'analytics',
            'analyticsPage' => 'geo_inflow',
            'summary' => $dashboard['summary'],
            'recentActions' => $dashboard['recent_actions'],
            'pendingActions' => $dashboard['pending_actions'],
        ]);
    }

    /**
     * GEOInflow 仪表盘 JSON API。
     */
    public function dashboard(): JsonResponse
    {
        $data = $this->orchestrator->dashboard();

        return response()->json(['data' => $data]);
    }

    /**
     * 评估文章的闭环状态。
     */
    public function evaluateArticle(int $articleId): JsonResponse
    {
        $article = Article::query()->whereKey($articleId)->firstOrFail();
        $evaluation = $this->orchestrator->evaluateArticle($article);

        return response()->json(['data' => $evaluation]);
    }

    /**
     * 评估 Task 下所有文章的闭环状态。
     */
    public function evaluateTask(int $taskId): JsonResponse
    {
        $task = Task::query()->whereKey($taskId)->firstOrFail();
        $evaluation = $this->orchestrator->evaluateTask($task);

        return response()->json(['data' => $evaluation]);
    }

    /**
     * 执行闭环优化动作。
     */
    public function executeAction(Request $request, int $articleId): JsonResponse
    {
        $article = Article::query()->whereKey($articleId)->firstOrFail();
        $action = (string) $request->input('action');

        if (! in_array($action, [
            GeoInflowAction::ACTION_REWRITE_HEAVY,
            GeoInflowAction::ACTION_REWRITE_MEDIUM,
            GeoInflowAction::ACTION_REWRITE_LIGHT,
            GeoInflowAction::ACTION_REDISTRIBUTE,
            GeoInflowAction::ACTION_PAUSE_CHANNEL,
            GeoInflowAction::ACTION_OPTIMIZE_METADATA,
        ], true)) {
            return response()->json([
                'error' => ['code' => 'invalid_action', 'message' => '无效的闭环动作'],
            ], 422);
        }

        $result = $this->orchestrator->executeAction($article, $action);

        // 记录动作历史
        GeoInflowAction::create([
            'article_id' => (int) $article->id,
            'action_type' => $action,
            'trigger_source' => GeoInflowAction::TRIGGER_MANUAL,
            'severity_score' => 0,
            'status' => $result['success'] ? GeoInflowAction::STATUS_COMPLETED : GeoInflowAction::STATUS_FAILED,
            'result_message' => $result['message'],
            'executed_at' => $result['success'] ? now() : null,
        ]);

        return response()->json(['data' => $result]);
    }

    /**
     * 获取闭环动作历史。
     */
    public function actionHistory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'article_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'in:pending,executing,completed,failed,cancelled'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = GeoInflowAction::query()->with('article:id,title,status');

        if (isset($validated['article_id'])) {
            $query->where('article_id', (int) $validated['article_id']);
        }
        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $actions = $query->orderByDesc('created_at')->paginate(
            (int) ($validated['per_page'] ?? 20),
            ['*'],
            'page',
            (int) ($validated['page'] ?? 1),
        );

        return response()->json([
            'data' => [
                'items' => $actions->items(),
                'pagination' => [
                    'page' => $actions->currentPage(),
                    'last_page' => $actions->lastPage(),
                    'total' => $actions->total(),
                ],
            ],
        ]);
    }
}
