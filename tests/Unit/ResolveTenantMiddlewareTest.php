<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\ApiException;
use App\Http\ApiAuthContext;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ResolveTenant 中间件单元测试：fail-closed 租户解析逻辑。
 *
 * 覆盖矩阵（v1.2 §2.3）：
 *   1. 无 api_auth 上下文 → 401
 *   2. Token 已绑定 + Header 匹配 → 通过，siteId = 绑定值
 *   3. Token 已绑定 + Header 缺失 → 通过，siteId = 绑定值
 *   4. Token 已绑定 + Header 不匹配 → 403
 *   5. Token 未绑定 + Header 有效 → 通过，siteId = Header 值
 *   6. Token 未绑定 + Header 缺失 → 422
 *   7. Token 未绑定 + Header 非正整数 → 422
 */
class ResolveTenantMiddlewareTest extends TestCase
{
    private ResolveTenant $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new ResolveTenant();
    }

    public function test_rejects_request_without_auth_context(): void
    {
        $request = Request::create('/api/v1/test', 'GET');

        $this->expectException(ApiException::class);
        $this->expectExceptionCode(401);

        $this->middleware->handle($request, fn () => response('ok'));
    }

    public function test_bound_token_with_matching_header_uses_bound_value(): void
    {
        $request = $this->makeRequest(tokenSiteId: 42, headerSiteId: '42');

        $response = $this->middleware->handle($request, fn () => response('ok'));

        self::assertSame(200, $response->getStatusCode());
        /** @var ApiAuthContext $auth */
        $auth = $request->attributes->get('api_auth');
        self::assertSame(42, $auth->siteId);
        self::assertSame(42, $request->attributes->get('tenant_site_id'));
    }

    public function test_bound_token_without_header_uses_bound_value(): void
    {
        $request = $this->makeRequest(tokenSiteId: 7, headerSiteId: null);

        $response = $this->middleware->handle($request, fn () => response('ok'));

        self::assertSame(200, $response->getStatusCode());
        /** @var ApiAuthContext $auth */
        $auth = $request->attributes->get('api_auth');
        self::assertSame(7, $auth->siteId);
    }

    public function test_bound_token_with_mismatched_header_returns_403(): void
    {
        $request = $this->makeRequest(tokenSiteId: 42, headerSiteId: '99');

        $exception = $this->catchApiException(fn () => $this->middleware->handle($request, fn () => response('ok')));

        self::assertNotNull($exception);
        self::assertSame(403, $exception->getHttpStatus());
        self::assertSame('tenant_mismatch', $exception->getErrorCode());
    }

    public function test_unbound_token_with_valid_header_uses_header_value(): void
    {
        $request = $this->makeRequest(tokenSiteId: null, headerSiteId: '15');

        $response = $this->middleware->handle($request, fn () => response('ok'));

        self::assertSame(200, $response->getStatusCode());
        /** @var ApiAuthContext $auth */
        $auth = $request->attributes->get('api_auth');
        self::assertSame(15, $auth->siteId);
        self::assertSame(15, $request->attributes->get('tenant_site_id'));
    }

    public function test_unbound_token_without_header_returns_422(): void
    {
        $request = $this->makeRequest(tokenSiteId: null, headerSiteId: null);

        $exception = $this->catchApiException(fn () => $this->middleware->handle($request, fn () => response('ok')));

        self::assertNotNull($exception);
        self::assertSame(422, $exception->getHttpStatus());
        self::assertSame('tenant_required', $exception->getErrorCode());
    }

    #[DataProvider('invalidHeaderProvider')]
    public function test_unbound_token_with_invalid_header_returns_422(mixed $headerValue): void
    {
        $request = $this->makeRequest(tokenSiteId: null, headerSiteId: $headerValue);

        $exception = $this->catchApiException(fn () => $this->middleware->handle($request, fn () => response('ok')));

        self::assertNotNull($exception);
        self::assertSame(422, $exception->getHttpStatus());
        self::assertSame('tenant_required', $exception->getErrorCode());
    }

    /** @return array<string, array{mixed}> */
    public static function invalidHeaderProvider(): array
    {
        return [
            'empty string' => [''],
            'zero' => ['0'],
            'negative' => ['-1'],
            'non-numeric' => ['abc'],
            'alphanumeric' => ['12abc'],
        ];
    }

    public function test_never_degrades_to_null(): void
    {
        // 无论哪种路径，解析成功时 siteId 恒非 null
        $request = $this->makeRequest(tokenSiteId: 1, headerSiteId: null);
        $this->middleware->handle($request, fn () => response('ok'));
        self::assertNotNull($request->attributes->get('tenant_site_id'));
        self::assertIsInt($request->attributes->get('tenant_site_id'));
    }

    /**
     * @param int|null $tokenSiteId  Token 绑定的 site_id（null = 未绑定）
     * @param string|null $headerSiteId  X-Site-Id 请求头值（null = 不传）
     */
    private function makeRequest(?int $tokenSiteId, ?string $headerSiteId): Request
    {
        $request = Request::create('/api/v1/test', 'GET');

        $tokenData = [
            'id' => 1,
            'name' => 'test-token',
            'scopes' => ['geo-diagnosis:read'],
            'status' => 'active',
            'site_id' => $tokenSiteId,
        ];

        $request->attributes->set('api_auth', new ApiAuthContext($tokenData, 1));

        if ($headerSiteId !== null) {
            $request->headers->set('X-Site-Id', $headerSiteId);
        }

        return $request;
    }

    private function catchApiException(callable $fn): ?ApiException
    {
        try {
            $fn();
            return null;
        } catch (ApiException $e) {
            return $e;
        }
    }
}
