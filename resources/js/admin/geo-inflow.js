/**
 * GEOInflow 闭环仪表盘交互模块。
 *
 * 功能：
 * 1. 加载已发布文章列表到下拉框
 * 2. 单文章评估（调用 API 并展示结果）
 * 3. 执行闭环动作（调用 API 并刷新页面）
 */

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

const json = async (url, options = {}) => {
    const response = await fetch(url, {
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        ...options,
    });
    if (!response.ok) {
        const error = await response.json().catch(() => ({ error: { message: response.statusText } }));
        throw new Error(error?.error?.message ?? response.statusText);
    }
    return response.json();
};

/**
 * 加载已发布文章到下拉框。
 */
const loadArticles = async (select) => {
    try {
        // 复用文章列表 API，只取已发布的
        const data = await json('/geo_admin/articles?status=published&per_page=100&sort=-id');
        const articles = data?.data?.items ?? data?.data ?? [];

        select.innerHTML = '<option value="">' + (select.querySelector('option')?.textContent ?? '请选择文章') + '</option>';
        for (const article of articles) {
            const option = document.createElement('option');
            option.value = article.id;
            option.textContent = article.title;
            select.appendChild(option);
        }
    } catch (error) {
        console.warn('GEOInflow: 加载文章列表失败', error);
    }
};

/**
 * 评估单篇文章。
 */
const evaluateArticle = async (articleId, resultContainer) => {
    resultContainer.classList.remove('hidden');
    resultContainer.innerHTML = '<div class="flex items-center gap-2 text-sm text-gray-500"><i data-lucide="loader-2" class="h-4 w-4 animate-spin"></i> 评估中…</div>';
    // 重新初始化 lucide 图标
    if (window.lucide) window.lucide.createIcons();

    try {
        const { data } = await json(`/geo_admin/geo-inflow/article/${articleId}/evaluate`);

        if (!data.needs_action) {
            resultContainer.innerHTML = `
                <div class="rounded-lg bg-emerald-50 border border-emerald-200 p-4">
                    <div class="flex items-center gap-2">
                        <i data-lucide="circle-check" class="h-5 w-5 text-emerald-600"></i>
                        <span class="text-sm font-medium text-emerald-800">文章状态良好，暂无需优化动作</span>
                    </div>
                </div>`;
            if (window.lucide) window.lucide.createIcons();
            return;
        }

        const reasonsHtml = data.reasons.map(r => `<li class="text-sm text-gray-700">${r}</li>`).join('');
        const actionLabels = {
            rewrite_heavy: '重改写', rewrite_medium: '中改写', rewrite_light: '轻改写',
            redistribute: '重新分发', pause_channel: '暂停渠道', optimize_metadata: '优化元数据',
        };
        const recommendedLabel = actionLabels[data.recommended_action] ?? data.recommended_action;

        resultContainer.innerHTML = `
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-4">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2">
                            <i data-lucide="alert-triangle" class="h-5 w-5 text-amber-600"></i>
                            <span class="text-sm font-semibold text-amber-800">需要优化（严重度 ${data.severity_score}）</span>
                        </div>
                        <ul class="mt-2 list-disc pl-5 space-y-1">${reasonsHtml}</ul>
                        <p class="mt-2 text-sm text-amber-700">推荐动作：<strong>${recommendedLabel}</strong></p>
                    </div>
                    <button type="button"
                        data-execute-action
                        data-article-id="${articleId}"
                        data-action-type="${data.recommended_action}"
                        class="shrink-0 inline-flex min-h-9 items-center rounded-md bg-amber-600 px-3 text-sm font-semibold text-white hover:bg-amber-700 active:scale-[.98]">
                        <i data-lucide="play" class="mr-1.5 h-3.5 w-3.5"></i>
                        执行
                    </button>
                </div>
            </div>`;
        if (window.lucide) window.lucide.createIcons();
    } catch (error) {
        resultContainer.innerHTML = `
            <div class="rounded-lg border border-red-200 bg-red-50 p-4">
                <p class="text-sm text-red-700">评估失败：${error.message}</p>
            </div>`;
    }
};

/**
 * 执行闭环动作。
 */
const executeAction = async (articleId, actionType, button) => {
    const originalHtml = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<i data-lucide="loader-2" class="mr-1.5 h-3.5 w-3.5 animate-spin"></i> 执行中…';
    if (window.lucide) window.lucide.createIcons();

    try {
        const { data } = await json(`/geo_admin/geo-inflow/article/${articleId}/action`, {
            method: 'POST',
            body: JSON.stringify({ action: actionType }),
        });

        if (data.success) {
            button.innerHTML = '<i data-lucide="check" class="mr-1.5 h-3.5 w-3.5"></i> 完成';
            if (window.lucide) window.lucide.createIcons();
            // 2 秒后刷新页面以更新列表
            setTimeout(() => location.reload(), 1500);
        } else {
            throw new Error(data.message ?? '执行失败');
        }
    } catch (error) {
        button.disabled = false;
        button.innerHTML = originalHtml;
        if (window.lucide) window.lucide.createIcons();
        alert('执行失败：' + error.message);
    }
};

// -- 初始化 --
document.addEventListener('DOMContentLoaded', () => {
    const articleSelect = document.getElementById('geo-inflow-article-select');
    const evaluateBtn = document.getElementById('geo-inflow-evaluate-btn');
    const resultContainer = document.getElementById('geo-inflow-evaluate-result');

    // 加载文章列表
    if (articleSelect) {
        loadArticles(articleSelect);

        articleSelect.addEventListener('change', () => {
            evaluateBtn.disabled = !articleSelect.value;
        });
    }

    // 评估按钮
    if (evaluateBtn && articleSelect && resultContainer) {
        evaluateBtn.addEventListener('click', () => {
            const articleId = articleSelect.value;
            if (articleId) {
                evaluateArticle(articleId, resultContainer);
            }
        });
    }

    // 事件委托：执行动作按钮（包括待处理列表和评估结果中的按钮）
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-execute-action]');
        if (!button) return;

        const articleId = button.dataset.articleId;
        const actionType = button.dataset.actionType;
        if (articleId && actionType) {
            executeAction(articleId, actionType, button);
        }
    });
});
