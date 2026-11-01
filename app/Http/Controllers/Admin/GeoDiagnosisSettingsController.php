<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GeoDiagnosisSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * GEO 诊断设置控制器
 *
 * 管理诊断维度权重和检查项开关。
 */
class GeoDiagnosisSettingsController extends Controller
{
    /**
     * 诊断设置页面
     */
    public function index(): View
    {
        $settings = GeoDiagnosisSetting::getForSite();

        return view('admin.geo-diagnosis.settings', [
            'pageTitle' => '诊断设置',
            'activeMenu' => 'geo-diagnosis',
            'settings' => $settings,
            'allChecks' => $this->getAllChecksDefinition(),
        ]);
    }

    /**
     * 获取诊断设置（API）
     */
    public function show(): JsonResponse
    {
        $settings = GeoDiagnosisSetting::getForSite();

        return response()->json([
            'data' => [
                'dimension_weights' => $settings->dimension_weights,
                'enabled_checks' => $settings->enabled_checks,
                'all_checks' => $this->getAllChecksDefinition(),
            ],
        ]);
    }

    /**
     * 更新诊断设置
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'dimension_weights' => ['required', 'array'],
            'dimension_weights.content_structure' => ['required', 'numeric', 'min:0', 'max:1'],
            'dimension_weights.knowledge_alignment' => ['required', 'numeric', 'min:0', 'max:1'],
            'dimension_weights.technical_geo' => ['required', 'numeric', 'min:0', 'max:1'],
            'dimension_weights.ai_visibility' => ['required', 'numeric', 'min:0', 'max:1'],
            'enabled_checks' => ['required', 'array'],
            'enabled_checks.*' => ['string', 'in:CS-01,CS-02,CS-03,CS-04,KA-01,KA-02,KA-03,TG-01,TG-02,TG-03,AV-01,AV-02'],
        ]);

        // 验证权重总和为 1.0（允许浮点误差）
        $weightSum = array_sum($validated['dimension_weights']);
        if (abs($weightSum - 1.0) > 0.01) {
            return response()->json([
                'error' => [
                    'code' => 'invalid_weights',
                    'message' => '维度权重总和必须为 100%（当前：' . round($weightSum * 100) . '%）',
                ],
            ], 422);
        }

        $settings = GeoDiagnosisSetting::getForSite();
        $settings->update([
            'dimension_weights' => $validated['dimension_weights'],
            'enabled_checks' => $validated['enabled_checks'],
        ]);

        return response()->json([
            'data' => $settings->refresh(),
            'message' => '设置已保存',
        ]);
    }

    /**
     * 获取所有检查项的定义
     *
     * @return list<array{id: string, title: string, dimension: string, severity: string, auto_fixable: bool}>
     */
    private function getAllChecksDefinition(): array
    {
        return [
            ['id' => 'CS-01', 'title' => 'H1 标题存在且唯一', 'dimension' => 'content_structure', 'severity' => 'high', 'auto_fixable' => false],
            ['id' => 'CS-02', 'title' => '标题层级无跳跃', 'dimension' => 'content_structure', 'severity' => 'medium', 'auto_fixable' => false],
            ['id' => 'CS-03', 'title' => '段落平均长度 ≤ 200 字', 'dimension' => 'content_structure', 'severity' => 'low', 'auto_fixable' => false],
            ['id' => 'CS-04', 'title' => '包含 FAQ 或定义性语句', 'dimension' => 'content_structure', 'severity' => 'medium', 'auto_fixable' => false],
            ['id' => 'KA-01', 'title' => '知识库事实覆盖率 ≥ 60%', 'dimension' => 'knowledge_alignment', 'severity' => 'high', 'auto_fixable' => false],
            ['id' => 'KA-02', 'title' => '核心术语频次 ≥ 3', 'dimension' => 'knowledge_alignment', 'severity' => 'medium', 'auto_fixable' => false],
            ['id' => 'KA-03', 'title' => '无知识库矛盾内容', 'dimension' => 'knowledge_alignment', 'severity' => 'high', 'auto_fixable' => false],
            ['id' => 'TG-01', 'title' => 'llms.txt 可达', 'dimension' => 'technical_geo', 'severity' => 'high', 'auto_fixable' => true],
            ['id' => 'TG-02', 'title' => 'Schema.org JSON-LD 完整', 'dimension' => 'technical_geo', 'severity' => 'medium', 'auto_fixable' => true],
            ['id' => 'TG-03', 'title' => '规范化链接正确', 'dimension' => 'technical_geo', 'severity' => 'medium', 'auto_fixable' => true],
            ['id' => 'AV-01', 'title' => '关键词 AI 搜索前 10', 'dimension' => 'ai_visibility', 'severity' => 'high', 'auto_fixable' => false],
            ['id' => 'AV-02', 'title' => '竞品引用占比 ≤ 50%', 'dimension' => 'ai_visibility', 'severity' => 'medium', 'auto_fixable' => false],
        ];
    }
}
