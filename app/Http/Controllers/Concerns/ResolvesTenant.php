<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * 控制器辅助 trait：从请求属性中读取经 ResolveTenant 中间件解析的租户 site_id。
 *
 * 用法：在需要感知租户上下文的控制器中 `use ResolvesTenant;`，然后调用
 * `$this->tenantId($request)` 获取当前请求的 niucloud site_id。
 *
 * API 面（经过 api.tenant 中间件）：返回值恒非 null。
 * Admin web 面（超管全局视图）：返回 null 表示不按租户过滤。
 */
trait ResolvesTenant
{
    protected function tenantId(Request $request): ?int
    {
        $v = $request->attributes->get('tenant_site_id');

        return $v !== null ? (int) $v : null;
    }
}
