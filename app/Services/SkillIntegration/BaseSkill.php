<?php

declare(strict_types=1);

namespace App\Services\SkillIntegration;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * 技能基类
 *
 * 提供技能加载、执行、沙箱隔离的基础能力
 * 遵循 yao-meta-skill 的 Skill OS 2.0 规范
 */
abstract class BaseSkill
{
    protected string $skillId;
    protected string $promptsPath;
    protected array $permissions = [];
    protected array $forbiddenOperations = [
        'db_write',
        'file_delete',
        'network_call',
    ];

    /**
     * 执行技能
     */
    abstract public function execute(mixed $input, array $options = []): SkillResult;

    /**
     * 加载提示词模板
     */
    protected function loadTemplate(string $templateKey): string
    {
        $path = $this->promptsPath . '/' . $templateKey . '.md';

        if (!file_exists($path)) {
            throw new \RuntimeException("Template not found: {$templateKey}");
        }

        return file_get_contents($path);
    }

    /**
     * 编译提示词（注入变量）
     */
    protected function compilePrompt(string $template, array $variables): string
    {
        $compiled = $template;

        foreach ($variables as $key => $value) {
            $compiled = str_replace('{{' . $key . '}}', (string)$value, $compiled);
        }

        return $compiled;
    }

    /**
     * 调用 AI 模型
     */
    protected function invokeModel(string $prompt, array $options = []): array
    {
        // 使用 GEOFlow 现有的 AI 模型调用机制
        $modelId = $options['model_id'] ?? null;

        if (!$modelId) {
            throw new \RuntimeException('Model ID is required');
        }

        // 这里应该调用 GEOFlow 的 AiModelInvocationLock 或相关服务
        // 暂时返回模拟结果
        Log::info('Skill invoking model', [
            'skill_id' => $this->skillId,
            'model_id' => $modelId,
            'prompt_length' => strlen($prompt),
        ]);

        return [
            'content' => '',
            'usage' => ['tokens' => 0],
        ];
    }

    /**
     * 检查权限
     */
    protected function checkPermission(string $operation): bool
    {
        if (in_array($operation, $this->forbiddenOperations)) {
            Log::warning('Forbidden operation attempted', [
                'skill_id' => $this->skillId,
                'operation' => $operation,
            ]);
            return false;
        }

        return in_array($operation, $this->permissions) || in_array('*', $this->permissions);
    }

    /**
     * 记录技能执行日志
     */
    protected function logExecution(SkillResult $result): void
    {
        Log::info('Skill executed', [
            'skill_id' => $this->skillId,
            'success' => $result->isSuccess(),
            'execution_time' => $result->getExecutionTime(),
            'metrics' => $result->getMetrics(),
        ]);
    }
}
