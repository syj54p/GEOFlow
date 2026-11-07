<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Http\ApiAuthContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 解析 API 请求的租户上下文（site_id）。
 *
 * 仅挂在 api.auth 之后的 API 路由组（诊断/品牌监测等租户强制边界）。
 *
 * 规则（fail-closed）：
 *   1. token 已绑定 site_id → 用绑定值；Header 缺失允许，Header 不匹配 → 403
 *   2. token 未绑定（过渡期）→ Header 必须为 >0 整数，否则 422
 *   3. 绝不降级为 null/全局
 */
class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $auth = $request->attributes->get('api_auth');
        if (! $auth instanceof ApiAuthContext) {
            throw new ApiException(
                'tenant_unauthorized',
                '缺少认证上下文：api.tenant 中间件必须挂在 api.auth 之后',
                401,
            );
        }

        $boundSiteId = $this->extractBoundSiteId($auth);
        $headerSiteId = $this->extractHeaderSiteId($request);

        $siteId = $this->resolve($boundSiteId, $headerSiteId);

        $request->attributes->set('api_auth', new ApiAuthContext(
            $auth->token,
            $auth->auditAdminId,
            $siteId,
        ));
        $request->attributes->set('tenant_site_id', $siteId);

        return $next($request);
    }

    private function extractBoundSiteId(ApiAuthContext $auth): ?int
    {
        $raw = $auth->token['site_id'] ?? null;

        return $raw !== null ? (int) $raw : null;
    }

    private function extractHeaderSiteId(Request $request): ?int
    {
        $value = $request->header('X-Site-Id');

        if (! is_string($value) || $value === '') {
            return null;
        }

        if (! ctype_digit($value)) {
            return null;
        }

        $parsed = (int) $value;

        return $parsed > 0 ? $parsed : null;
    }

    private function resolve(?int $boundSiteId, ?int $headerSiteId): int
    {
        if ($boundSiteId !== null) {
            if ($headerSiteId !== null && $headerSiteId !== $boundSiteId) {
                throw new ApiException(
                    'tenant_mismatch',
                    'X-Site-Id 与 Token 绑定站点不一致',
                    403,
                );
            }

            return $boundSiteId;
        }

        if ($headerSiteId === null) {
            throw new ApiException(
                'tenant_required',
                '缺少租户标识：请传入 X-Site-Id 或使用绑定站点的 Token',
                422,
            );
        }

        return $headerSiteId;
    }
}
