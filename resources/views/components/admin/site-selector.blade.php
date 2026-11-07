@props(['sites' => [], 'selectedSiteId' => null, 'baseUrl' => null])

@php
    $sites = is_array($sites) ? $sites : [];
    $currentUrl = $baseUrl ?? request()->fullUrl();
    // 移除已有的 site_id 参数
    $baseQueryString = request()->query();
    unset($baseQueryString['site_id']);
    $baseUrl = request()->url() . (empty($baseQueryString) ? '' : '?' . http_build_query($baseQueryString));
@endphp

<div class="gf-site-selector" x-data="{ open: false }">
    <button
        type="button"
        class="inline-flex items-center gap-2 px-3 py-1.5 text-sm font-medium rounded-lg border transition-colors"
        :class="open
            ? 'border-blue-500 bg-blue-50 text-blue-700'
            : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50'"
        @click="open = !open"
        @click.away="open = false"
    >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
        </svg>
        <span>
            @if ($selectedSiteId)
                站点 {{ $selectedSiteId }}
            @else
                全局视图
            @endif
        </span>
        <svg class="w-4 h-4 transition-transform" :class="{ 'rotate-180': open }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
        </svg>
    </button>

    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="transform opacity-0 scale-95"
        x-transition:enter-end="transform opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="transform opacity-100 scale-100"
        x-transition:leave-end="transform opacity-0 scale-95"
        class="absolute right-0 z-50 w-64 mt-2 bg-white rounded-lg shadow-lg border border-gray-200 overflow-hidden"
        style="display: none;"
    >
        <div class="py-1">
            {{-- 全局视图选项 --}}
            <a
                href="{{ $baseUrl }}"
                class="flex items-center justify-between px-4 py-2 text-sm transition-colors {{ !$selectedSiteId ? 'bg-blue-50 text-blue-700 font-medium' : 'text-gray-700 hover:bg-gray-100' }}"
            >
                <span class="flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 7.5h3a2 2 0 012 2v1.5" />
                    </svg>
                    全局视图
                </span>
                @if (!$selectedSiteId)
                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                    </svg>
                @endif
            </a>

            @if (count($sites) > 0)
                <div class="border-t border-gray-100 my-1"></div>
                <div class="px-4 py-1.5 text-xs font-semibold text-gray-500 uppercase tracking-wider">
                    按站点筛选
                </div>
                @foreach ($sites as $site)
                    <a
                        href="{{ $baseUrl }}{{ str_contains($baseUrl, '?') ? '&' : '?' }}site_id={{ $site['id'] }}"
                        class="flex items-center justify-between px-4 py-2 text-sm transition-colors {{ $selectedSiteId == $site['id'] ? 'bg-blue-50 text-blue-700 font-medium' : 'text-gray-700 hover:bg-gray-100' }}"
                    >
                        <span class="flex items-center gap-2">
                            <span class="w-6 h-6 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center text-white text-xs font-bold">
                                {{ substr((string) $site['name'], 0, 1) ?: 'S' }}
                            </span>
                            <span class="flex-1 truncate">
                                {{ $site['name'] }}
                                <span class="text-xs text-gray-500 ml-1">
                                    ({{ $site['run_count'] ?? 0 }} 诊断 / {{ $site['keyword_count'] ?? 0 }} 关键词)
                                </span>
                            </span>
                        </span>
                        @if ($selectedSiteId == $site['id'])
                            <svg class="w-4 h-4 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                            </svg>
                        @endif
                    </a>
                @endforeach
            @else
                <div class="px-4 py-3 text-sm text-gray-500 text-center">
                    暂无站点数据
                </div>
            @endif
        </div>
    </div>
</div>

<style>
    .gf-site-selector {
        position: relative;
    }
</style>
