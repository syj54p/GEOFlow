<?php

/**
 * Skill 集成测试脚本
 * 
 * 用法：php test-skill-integration.php
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Services\SkillIntegration\SkillManager;

echo "=== GEOFlow Skill 集成测试 ===\n\n";

// 获取 SkillManager 实例
$skillManager = app(SkillManager::class);

echo "1. 测试列出可用 Skills\n";
echo "------------------------\n";
$skills = $skillManager->listSkills();
foreach ($skills as $skillId => $skillInfo) {
    echo "✓ Skill ID: {$skillId}\n";
    echo "  类名: {$skillInfo['class']}\n";
    echo "  描述: {$skillInfo['description']}\n";
    echo "  版本: {$skillInfo['version']}\n";
}
echo "\n";

echo "2. 测试 GEO 优化 Skill\n";
echo "------------------------\n";
$testContent = <<<EOT
人工智能（AI）是计算机科学的一个分支，它试图理解和构建智能实体。AI 的研究可以追溯到古代，但现代 AI 研究始于 20 世纪 50 年代。
机器学习是 AI 的一个子集，它使系统能够从数据中学习并改进，而无需明确编程。深度学习是机器学习的一个子集，它使用多层神经网络。
EOT;

try {
    $result = $skillManager->execute('geo-opt', $testContent, [
        'target_engine' => 'chatgpt',
        'optimization_type' => 'full',
    ]);
    
    echo "✓ 执行成功\n";
    echo "  Skill ID: {$result->getSkillId()}\n";
    echo "  状态: " . ($result->isSuccess() ? '成功' : '失败') . "\n";
    echo "  执行时间: " . number_format($result->getExecutionTime(), 3) . " 秒\n";
    
    $data = $result->getData();
    echo "  优化类型: " . ($data['optimization_type'] ?? 'N/A') . "\n";
    echo "  目标引擎: " . ($data['target_engine'] ?? 'N/A') . "\n";
    
    if (isset($data['optimized_content'])) {
        $preview = substr($data['optimized_content'], 0, 200);
        echo "  优化内容预览: {$preview}...\n";
    }
    
    $metrics = $result->getMetrics();
    if (!empty($metrics)) {
        echo "  指标:\n";
        foreach ($metrics as $key => $value) {
            echo "    - {$key}: {$value}\n";
        }
    }
} catch (\Throwable $e) {
    echo "✗ 执行失败: {$e->getMessage()}\n";
}
echo "\n";

echo "3. 测试质量评估 Skill\n";
echo "------------------------\n";
try {
    $result = $skillManager->execute('qa-eval', $testContent, [
        'dimensions' => ['factual', 'readability', 'geo'],
    ]);
    
    echo "✓ 执行成功\n";
    echo "  Skill ID: {$result->getSkillId()}\n";
    echo "  状态: " . ($result->isSuccess() ? '成功' : '失败') . "\n";
    echo "  执行时间: " . number_format($result->getExecutionTime(), 3) . " 秒\n";
    
    $data = $result->getData();
    if (isset($data['overall_score'])) {
        echo "  综合评分: " . number_format($data['overall_score'], 2) . "\n";
    }
    
    if (isset($data['dimension_scores'])) {
        echo "  维度评分:\n";
        foreach ($data['dimension_scores'] as $dimension => $score) {
            echo "    - {$dimension}: " . number_format($score, 2) . "\n";
        }
    }
    
    if (isset($data['recommendations'])) {
        echo "  建议数量: " . count($data['recommendations']) . "\n";
    }
} catch (\Throwable $e) {
    echo "✗ 执行失败: {$e->getMessage()}\n";
}
echo "\n";

echo "4. 测试不存在的 Skill\n";
echo "------------------------\n";
try {
    $result = $skillManager->execute('non-existent-skill', 'test');
    if (!$result->isSuccess()) {
        echo "✓ 正确返回失败结果: {$result->getMessage()}\n";
    } else {
        echo "✗ 应该返回失败但没有\n";
    }
} catch (\Throwable $e) {
    echo "✗ 抛出意外异常: {$e->getMessage()}\n";
}
echo "\n";

echo "=== 测试完成 ===\n";
