<?php

declare(strict_types=1);

namespace App\Services\SkillIntegration;

use Illuminate\Support\Facades\Log;

/**
 * 技能管理器
 *
 * 统一管理所有技能的注册、调用、权限控制
 */
class SkillManager
{
    protected array $skills = [];
    protected array $skillConfigs = [];

    public function __construct()
    {
        $this->registerBuiltinSkills();
    }

    /**
     * 注册内置技能
     */
    protected function registerBuiltinSkills(): void
    {
        $this->register('geo-opt', GeoOptimizationSkill::class, [
            'description' => 'GEO 内容优化技能',
            'version' => '1.0.0',
            'permissions' => ['ai_invoke', 'read_article', 'write_result'],
        ]);

        $this->register('qa-eval', QualityEvaluationSkill::class, [
            'description' => 'AI 质量评估技能',
            'version' => '1.0.0',
            'permissions' => ['ai_invoke', 'read_article', 'write_result'],
        ]);
    }

    /**
     * 注册技能
     */
    public function register(string $skillId, string $skillClass, array $config = []): void
    {
        $this->skills[$skillId] = $skillClass;
        $this->skillConfigs[$skillId] = $config;

        Log::info('Skill registered', [
            'skill_id' => $skillId,
            'class' => $skillClass,
        ]);
    }

    /**
     * 执行技能
     */
    public function execute(string $skillId, mixed $input, array $options = []): SkillResult
    {
        if (!isset($this->skills[$skillId])) {
            return SkillResult::failure("Skill not found: {$skillId}", $skillId, 0);
        }

        $skillClass = $this->skills[$skillId];
        $skill = new $skillClass();

        Log::info('Skill executing', [
            'skill_id' => $skillId,
            'input_type' => gettype($input),
        ]);

        return $skill->execute($input, $options);
    }

    /**
     * 获取技能列表
     */
    public function listSkills(): array
    {
        $result = [];

        foreach ($this->skills as $skillId => $skillClass) {
            $config = $this->skillConfigs[$skillId] ?? [];
            $result[$skillId] = [
                'class' => $skillClass,
                'description' => $config['description'] ?? '',
                'version' => $config['version'] ?? '1.0.0',
                'permissions' => $config['permissions'] ?? [],
            ];
        }

        return $result;
    }

    /**
     * 检查技能是否存在
     */
    public function hasSkill(string $skillId): bool
    {
        return isset($this->skills[$skillId]);
    }

    /**
     * 获取技能配置
     */
    public function getSkillConfig(string $skillId): array
    {
        return $this->skillConfigs[$skillId] ?? [];
    }
}
