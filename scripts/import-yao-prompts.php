<?php

/**
 * 批量导入 yao-open-prompts 模板到 GEOFlow prompts 表。
 *
 * 用法：D:/BtSoft/php/83/php.exe -d xdebug.mode=off scripts/import-yao-prompts.php
 *
 * 分类规则：
 * - type=content: 直接用于文章生成的提示词（geo-article-generator, geo-article-ai-friendly-transformation）
 * - type=strategy: 策略分析/诊断/规划类提示词（其余全部）
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$promptsDir = realpath(__DIR__ . '/../../yao-open-prompts/prompts/08-ai-marketing');
if (!$promptsDir || !is_dir($promptsDir)) {
    echo "ERROR: prompts directory not found: $promptsDir\n";
    exit(1);
}

if (!Schema::hasTable('prompts')) {
    echo "ERROR: prompts table does not exist\n";
    exit(1);
}

// 已存在的 prompt 名称（避免重复导入）
$existingNames = DB::table('prompts')->pluck('name')->toArray();

// 内容生成类模板（type=content）
$contentPrompts = [
    'geo-article-generator.md',
    'geo-article-ai-friendly-transformation.md',
];

// 收集所有 md 文件（排除 README 和子目录）
$files = glob($promptsDir . '/*.md');
$files = array_filter($files, fn($f) => basename($f) !== 'README.md');

$imported = 0;
$skipped = 0;
$errors = 0;

foreach ($files as $file) {
    $basename = basename($file);
    $content = file_get_contents($file);
    if ($content === false) {
        echo "  [ERROR] Cannot read: $basename\n";
        $errors++;
        continue;
    }

    // 解析 YAML frontmatter
    $title = '';
    if (preg_match('/^---\s*\n(.*?)\n---/su', $content, $fmMatch)) {
        if (preg_match('/^title:\s*["\']?(.+?)["\']?\s*$/mu', $fmMatch[1], $titleMatch)) {
            $title = trim($titleMatch[1]);
        }
    }

    if ($title === '') {
        echo "  [SKIP] No title found: $basename\n";
        $skipped++;
        continue;
    }

    // 去重检查
    if (in_array($title, $existingNames, true)) {
        echo "  [SKIP] Already exists: $title\n";
        $skipped++;
        continue;
    }

    // 提取 prompt 内容（```markdown 代码块内的内容）
    $promptContent = '';
    if (preg_match('/````markdown\s*\n(.*?)\n````/su', $content, $promptMatch)) {
        $promptContent = trim($promptMatch[1]);
    } elseif (preg_match('/```markdown\s*\n(.*?)\n```/su', $content, $promptMatch)) {
        $promptContent = trim($promptMatch[1]);
    } else {
        // 如果没有代码块，取 frontmatter 之后的全部内容
        $promptContent = trim(preg_replace('/^---.*?---/su', '', $content, 1));
    }

    if ($promptContent === '') {
        echo "  [SKIP] No prompt content: $title\n";
        $skipped++;
        continue;
    }

    // 确定类型
    $type = in_array($basename, $contentPrompts, true) ? 'content' : 'strategy';

    // 提取变量（从 {{variable}} 模式）
    $variables = [];
    preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/iu', $promptContent, $varMatches);
    $variables = array_unique($varMatches[1] ?? []);
    $variablesStr = !empty($variables) ? implode(',', $variables) : null;

    // 插入数据库
    try {
        DB::table('prompts')->insert([
            'name' => $title,
            'type' => $type,
            'content' => $promptContent,
            'variables' => $variablesStr,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $existingNames[] = $title;
        $imported++;
        echo "  [OK] [$type] $title (vars: " . ($variablesStr ?? 'none') . ")\n";
    } catch (\Throwable $e) {
        echo "  [ERROR] $title: " . $e->getMessage() . "\n";
        $errors++;
    }
}

echo "\n=== Summary ===\n";
echo "Imported: $imported\n";
echo "Skipped:  $skipped\n";
echo "Errors:   $errors\n";
echo "Total files processed: " . count($files) . "\n";
