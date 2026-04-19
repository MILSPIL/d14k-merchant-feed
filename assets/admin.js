(function () {
    'use strict';

    var ruleFields = [
        { value: 'title', label: 'Назва товару', type: 'text' },
        { value: 'sku', label: 'SKU', type: 'text' },
        { value: 'regular_price', label: 'Ціна (звичайна)', type: 'number' },
        { value: 'sale_price', label: 'Ціна зі знижкою', type: 'number' },
        { value: 'stock_status', label: 'Статус наявності', type: 'status' },
        { value: 'tag', label: 'Тег товару', type: 'text' },
        { value: 'custom_meta', label: 'Власне поле (meta)', type: 'meta' },
    ];
    var ruleConditions = {
        text: {
            contains: 'Містить',
            not_contains: 'Не містить',
            equals: 'Дорівнює',
            not_equals: 'Не дорівнює',
            starts_with: 'Починається з',
            ends_with: 'Закінчується на',
            is_empty: 'Порожнє',
            not_empty: 'Не порожнє',
        },
        number: {
            equals: '= Дорівнює',
            not_equals: '≠ Не дорівнює',
            gt: '> Більше ніж',
            lt: '< Менше ніж',
            gte: '≥ Більше або рівне',
            lte: '≤ Менше або рівне',
            is_empty: 'Не вказана',
            not_empty: 'Вказана',
        },
        status: {
            equals: 'Дорівнює',
            not_equals: 'Не дорівнює',
        },
        meta: {
            contains: 'Містить',
            not_contains: 'Не містить',
            equals: 'Дорівнює',
            not_equals: 'Не дорівнює',
            is_empty: 'Порожнє',
            not_empty: 'Не порожнє',
        },
    };
    var statusValues = [
        { value: 'instock', label: 'В наявності' },
        { value: 'outofstock', label: 'Немає в наявності' },
        { value: 'onbackorder', label: 'Під замовлення' },
    ];
    var noValueConditions = ['is_empty', 'not_empty'];
    var supplierUpdateFieldLabels = {
        price: 'Ціна та знижка',
        stock: 'Наявність і кількість',
        image: 'Картинка',
        category: 'Категорія',
        title: 'Назва',
        description: 'Опис',
        params: 'Характеристики',
        sku: 'Артикул',
        vendor: 'Бренд',
    };
    var supplierScheduleIntervalOptions = {
        hourly: 'Кожну годину',
        twicedaily: 'Двічі на добу',
        daily: 'Раз на добу',
        d14k_weekly: 'Раз на тиждень',
        d14k_monthly: 'Раз на місяць',
    };
    var supplierScheduleWeekdayOptions = {
        mon: 'Понеділок',
        tue: 'Вівторок',
        wed: 'Середа',
        thu: 'Четвер',
        fri: 'П’ятниця',
        sat: 'Субота',
        sun: 'Неділя',
    };

    function qs(selector, root) {
        return (root || document).querySelector(selector);
    }

    function qsa(selector, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(selector));
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function copyToClipboard(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }

        var textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.left = '-9999px';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();

        try {
            document.execCommand('copy');
        } catch (e) {
            // no-op
        }

        document.body.removeChild(textarea);
        return Promise.resolve();
    }

    function postAdminAction(payload) {
        var formData = new FormData();

        Object.keys(payload).forEach(function (key) {
            formData.append(key, payload[key]);
        });
        formData.append('nonce', d14kFeed.nonce);

        return postAdminFormData(formData);
    }

    function postAdminFormData(formData) {
        if (!formData.has('nonce')) {
            formData.append('nonce', d14kFeed.nonce);
        }

        return fetch(d14kFeed.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData,
        }).then(function (response) {
            return response.json().catch(function () {
                return Promise.reject({ status: response.status });
            });
        });
    }

    function setButtonState(button, disabled, label) {
        if (!button) {
            return;
        }
        button.disabled = !!disabled;
        if (typeof label === 'string') {
            button.textContent = label;
        }
    }

    function setSyncMessage(element, type, message, useHtml) {
        if (!element) {
            return;
        }
        element.classList.remove('is-success', 'is-error', 'is-pending');
        if (type) {
            element.classList.add('is-' + type);
        }
        if (useHtml) {
            element.innerHTML = message;
        } else {
            element.textContent = message;
        }
    }

    function setSupplierRowMessage(row, type, message) {
        var element = qs('.d14k-supplier-feed-row__result', row);
        if (!element) {
            return;
        }

        element.classList.remove('is-success', 'is-error', 'is-pending');
        if (type) {
            element.classList.add('is-' + type);
        }
        element.textContent = message || '';
    }

    function setSupplierRowAnalysis(row, type, html) {
        var element = qs('.d14k-supplier-feed-row__analysis', row);
        if (!element) {
            return;
        }

        element.classList.remove('is-success', 'is-error', 'is-pending');
        if (type) {
            element.classList.add('is-' + type);
        }
        element.innerHTML = html || '';
    }

    function resetSupplierRowSaveButton(row) {
        var button = qs('.d14k-save-feed-row', row);
        if (!button) {
            return;
        }

        setButtonState(button, false, 'Зберегти feed');
    }

    function getSupplierRowIndex(row) {
        var index = parseInt(row.getAttribute('data-supplier-index') || '-1', 10);
        return index >= 0 ? index : getNextSupplierIndex();
    }

    function collectSupplierRowPayload(row) {
        var payload = {
            url: (qs('input[name$="[url]"]', row) || {}).value || '',
            label: (qs('input[name$="[label]"]', row) || {}).value || '',
            category_root: (qs('input[name$="[category_root]"]', row) || {}).value || '',
            markup: (qs('input[name$="[markup]"]', row) || {}).value || '0',
            enabled: (qs('input[name$="[enabled]"]', row) || {}).checked ? '1' : '',
            schedule_interval: (qs('select[name$="[schedule_interval]"]', row) || {}).value || 'daily',
            schedule_time: (qs('input[name$="[schedule_time]"]', row) || {}).value || '01:00',
            schedule_weekday: (qs('select[name$="[schedule_weekday]"]', row) || {}).value || 'mon',
            schedule_monthday: (qs('input[name$="[schedule_monthday]"]', row) || {}).value || '1',
            update_fields: {}
        };

        qsa('input[type="checkbox"][name*="[update_fields]"]', row).forEach(function (input) {
            if (!input.checked) {
                return;
            }

            var match = input.name.match(/\[update_fields\]\[([^\]]+)\]/);
            if (match) {
                payload.update_fields[match[1]] = '1';
            }
        });

        return payload;
    }

    function appendSupplierFeedPayload(formData, payload) {
        if (!payload) {
            return;
        }

        formData.append('feed[url]', payload.url || '');
        formData.append('feed[label]', payload.label || '');
        formData.append('feed[category_root]', payload.category_root || '');
        formData.append('feed[markup]', payload.markup || '0');
        formData.append('feed[schedule_interval]', payload.schedule_interval || 'daily');
        formData.append('feed[schedule_time]', payload.schedule_time || '01:00');
        formData.append('feed[schedule_weekday]', payload.schedule_weekday || 'mon');
        formData.append('feed[schedule_monthday]', payload.schedule_monthday || '1');

        if (payload.enabled) {
            formData.append('feed[enabled]', payload.enabled);
        }

        Object.keys(payload.update_fields || {}).forEach(function (fieldKey) {
            formData.append('feed[update_fields][' + fieldKey + ']', payload.update_fields[fieldKey]);
        });
    }

    function getSupplierHostFromUrl(url) {
        var value = String(url || '').trim();
        var match;

        if (!value) {
            return '';
        }

        try {
            return new URL(value).host || '';
        } catch (e) {
            match = value.match(/^(?:https?:\/\/)?([^\/?#]+)/i);
            return match ? match[1] : '';
        }
    }

    function buildSupplierScheduleSummary(row) {
        var interval = (qs('select[name$="[schedule_interval]"]', row) || {}).value || 'daily';
        var time = (qs('input[name$="[schedule_time]"]', row) || {}).value || '01:00';
        var weekday = (qs('select[name$="[schedule_weekday]"]', row) || {}).value || 'mon';
        var monthday = (qs('input[name$="[schedule_monthday]"]', row) || {}).value || '1';
        var summary = (supplierScheduleIntervalOptions[interval] || 'Раз на добу') + ' • ' + time;

        if (interval === 'd14k_weekly') {
            summary += ' • ' + (supplierScheduleWeekdayOptions[weekday] || supplierScheduleWeekdayOptions.mon);
        } else if (interval === 'd14k_monthly') {
            summary += ' • ' + monthday + ' число';
        }

        return summary;
    }

    function syncSupplierRowPreview(row) {
        var titleElement;
        var scheduleBadge;
        var urlValue;
        var labelValue;
        var titleValue;
        var enabledInput;
        var isEnabled;

        if (!row) {
            return;
        }

        titleElement = qs('.d14k-supplier-feed-row__title', row);
        scheduleBadge = qs('[data-role="schedule-badge"]', row);
        urlValue = (qs('input[name$="[url]"]', row) || {}).value || '';
        labelValue = (qs('input[name$="[label]"]', row) || {}).value || '';
        titleValue = labelValue.trim() || (getSupplierHostFromUrl(urlValue) || 'Новий постачальник');
        enabledInput = qs('input[name$="[enabled]"]', row);
        isEnabled = !!(enabledInput && enabledInput.checked);

        if (titleElement) {
            titleElement.textContent = titleValue;
        }

        if (scheduleBadge) {
            scheduleBadge.textContent = buildSupplierScheduleSummary(row);
        }

        row.classList.toggle('is-disabled', !isEnabled);
    }

    function syncSupplierScheduleFields(row) {
        if (!row) {
            return;
        }

        var interval = (qs('select[name$="[schedule_interval]"]', row) || {}).value || 'daily';
        var weekdayWrap = qs('.d14k-supplier-feed-row__schedule-item--weekday', row);
        var monthdayWrap = qs('.d14k-supplier-feed-row__schedule-item--monthday', row);
        var weekdayInput = weekdayWrap ? qs('select', weekdayWrap) : null;
        var monthdayInput = monthdayWrap ? qs('input', monthdayWrap) : null;

        if (weekdayWrap) {
            weekdayWrap.classList.remove('d14k-supplier-feed-row__schedule-item--hidden', 'is-inactive', 'is-placeholder');
        }

        if (monthdayWrap) {
            monthdayWrap.classList.remove('d14k-supplier-feed-row__schedule-item--hidden', 'is-inactive', 'is-placeholder');
        }

        if (weekdayInput) {
            weekdayInput.disabled = false;
        }

        if (monthdayInput) {
            monthdayInput.disabled = false;
        }

        if (interval === 'd14k_weekly') {
            if (monthdayWrap) {
                monthdayWrap.classList.add('d14k-supplier-feed-row__schedule-item--hidden');
            }
            if (monthdayInput) {
                monthdayInput.disabled = true;
            }
        } else if (interval === 'd14k_monthly') {
            if (weekdayWrap) {
                weekdayWrap.classList.add('d14k-supplier-feed-row__schedule-item--hidden');
            }
            if (weekdayInput) {
                weekdayInput.disabled = true;
            }
        } else {
            if (weekdayWrap) {
                weekdayWrap.classList.add('is-inactive', 'is-placeholder');
            }
            if (weekdayInput) {
                weekdayInput.disabled = true;
            }
            if (monthdayWrap) {
                monthdayWrap.classList.add('d14k-supplier-feed-row__schedule-item--hidden');
            }
            if (monthdayInput) {
                monthdayInput.disabled = true;
            }
        }

        syncSupplierRowPreview(row);
    }

    function getSupplierStateScopeUrls(state) {
        if (!state) {
            return [];
        }

        if (Array.isArray(state.scope_feed_urls)) {
            return state.scope_feed_urls.filter(Boolean);
        }

        if (Array.isArray(state.feeds)) {
            return state.feeds.map(function (feed) {
                return feed && feed.url ? feed.url : '';
            }).filter(Boolean);
        }

        return [];
    }

    function doesSupplierRowMatchState(row, state) {
        if (!row || !state) {
            return false;
        }

        if ((state.scope_mode || 'all') !== 'single') {
            return false;
        }

        var payload = collectSupplierRowPayload(row);
        var rowUrl = (payload.url || '').trim();
        var scopeUrls = getSupplierStateScopeUrls(state);

        return scopeUrls.length === 1 && rowUrl !== '' && scopeUrls[0] === rowUrl;
    }

    function saveSupplierFeedRow(row) {
        var button = qs('.d14k-save-feed-row', row);
        var payload = collectSupplierRowPayload(row);
        var formData = new FormData();

        if (!payload.url.trim()) {
            setSupplierRowMessage(row, 'error', 'Вкажіть URL фіду перед збереженням.');
            return;
        }

        setButtonState(button, true, 'Зберігаємо...');
        setSupplierRowMessage(row, 'pending', 'Зберігаємо лише цього постачальника...');

        formData.append('action', 'd14k_supplier_feed_save_row');
        formData.append('index', String(getSupplierRowIndex(row)));
        formData.append('feed[url]', payload.url);
        formData.append('feed[label]', payload.label);
        formData.append('feed[category_root]', payload.category_root);
        formData.append('feed[markup]', payload.markup);
        if (payload.enabled) {
            formData.append('feed[enabled]', payload.enabled);
        }

        Object.keys(payload.update_fields).forEach(function (fieldKey) {
            formData.append('feed[update_fields][' + fieldKey + ']', payload.update_fields[fieldKey]);
        });

        postAdminFormData(formData).then(function (response) {
            if (!response.success) {
                setSupplierRowMessage(row, 'error', response.data || 'Не вдалося зберегти feed.');
                return;
            }

            row.setAttribute('data-supplier-index', String(response.data && typeof response.data.index !== 'undefined' ? response.data.index : getSupplierRowIndex(row)));
            row.classList.remove('is-dirty');
            setSupplierRowMessage(row, 'success', (response.data && response.data.message) || 'Feed збережено.');
        }).catch(function (error) {
            var status = error && error.status ? error.status : '0';
            setSupplierRowMessage(row, 'error', 'Не вдалося зберегти feed. HTTP ' + status + '.');
        }).finally(function () {
            setButtonState(button, false, 'Зберегти feed');
        });
    }

    function analyzeSupplierFeedRow(row) {
        var button = qs('.d14k-analyze-feed-row', row);
        var payload = collectSupplierRowPayload(row);
        var formData = new FormData();

        if (!payload.url.trim()) {
            setSupplierRowMessage(row, 'error', 'Вкажіть URL фіду перед аналізом.');
            setSupplierRowAnalysis(row, 'error', '');
            return;
        }

        setButtonState(button, true, 'Аналізуємо...');
        setSupplierRowMessage(row, 'pending', 'Аналізуємо лише цей feed без запису товарів...');
        setSupplierRowAnalysis(row, 'pending', '');

        formData.append('action', 'd14k_supplier_feed_analyze_row');
        formData.append('feed[url]', payload.url);
        formData.append('feed[label]', payload.label);
        formData.append('feed[category_root]', payload.category_root);
        formData.append('feed[markup]', payload.markup);
        if (payload.enabled) {
            formData.append('feed[enabled]', payload.enabled);
        }

        Object.keys(payload.update_fields).forEach(function (fieldKey) {
            formData.append('feed[update_fields][' + fieldKey + ']', payload.update_fields[fieldKey]);
        });

        postAdminFormData(formData).then(function (response) {
            if (!response.success) {
                setSupplierRowMessage(row, 'error', response.data || 'Не вдалося проаналізувати feed.');
                setSupplierRowAnalysis(row, 'error', '');
                return;
            }

            var data = response.data || {};
            setSupplierRowMessage(row, 'success', data.message || 'Аналіз завершено.');
            setSupplierRowAnalysis(row, 'success', data.html || '');
        }).catch(function (error) {
            var status = error && error.status ? error.status : '0';
            setSupplierRowMessage(row, 'error', 'Не вдалося проаналізувати feed. HTTP ' + status + '.');
            setSupplierRowAnalysis(row, 'error', '');
        }).finally(function () {
            setButtonState(button, false, 'Аналізувати цей feed');
        });
    }

    function renderProgressCard(element, config) {
        if (!element) {
            return;
        }

        element.classList.remove(
            'is-visible',
            'd14k-progress-card--summary',
            'd14k-progress-card--inline',
            'd14k-progress-card--detail',
            'd14k-progress-card--success',
            'd14k-progress-card--error',
            'd14k-progress-card--pending'
        );

        if (!config) {
            element.innerHTML = '';
            return;
        }

        var percent = Math.max(0, Math.min(100, Number(config.percent || 0)));
        var stats = Array.isArray(config.stats) ? config.stats : [];
        var variant = config.variant || '';
        var tone = config.tone || '';
        var statsHtml = stats.map(function (item) {
            return '<span>' + escapeHtml(item) + '</span>';
        }).join('');

        element.classList.add('is-visible');
        if (variant) {
            element.classList.add('d14k-progress-card--' + variant);
        }
        if (tone) {
            element.classList.add('d14k-progress-card--' + tone);
        }
        element.innerHTML =
            '<div class="d14k-progress-card__head">' +
                '<div class="d14k-progress-card__title">' + escapeHtml(config.title || '') + '</div>' +
                '<div class="d14k-progress-card__meta">' + escapeHtml(config.meta || '') + '</div>' +
            '</div>' +
            '<div class="d14k-progress-card__bar">' +
                '<div class="d14k-progress-card__fill" style="width:' + percent + '%"></div>' +
            '</div>' +
            '<div class="d14k-progress-card__stats">' + statsHtml + '</div>' +
            '<div class="d14k-progress-card__batch"><strong>' + escapeHtml(config.batchLabel || 'Останній батч:') + '</strong> ' + escapeHtml(config.batchMessage || 'Очікування старту...') + '</div>';
    }

    function getSupplierOfferTotals(results) {
        return Object.keys(results || {}).reduce(function (sum, key) {
            return sum + Number((results[key] || {}).offers_total || 0);
        }, 0);
    }

    function getSupplierProcessedTotals(cumulative) {
        cumulative = cumulative || {};
        return Number(cumulative.created || 0) + Number(cumulative.updated || 0) + Number(cumulative.skipped || 0);
    }

    function getSupplierStageLabel(stage) {
        var labels = {
            queued: 'У черзі',
            preparing: 'Підготовка фіду',
            sync_categories: 'Синхронізація категорій',
            importing: 'Імпорт товарів',
            completed: 'Завершено',
            failed: 'Помилка',
            cancelled: 'Скасовано',
        };

        return labels[stage] || 'Обробка';
    }

    function getSupplierBackgroundTone(state) {
        if (!state) {
            return '';
        }

        if (state.status === 'completed') {
            return 'success';
        }

        if (state.status === 'failed' || state.status === 'cancelled') {
            return 'error';
        }

        return 'pending';
    }

    function getSupplierFeedScopeCount(state) {
        if (state && Array.isArray(state.feeds) && state.feeds.length) {
            return state.feeds.length;
        }

        return getSupplierStateScopeUrls(state).length;
    }

    function getSupplierCurrentFeedPosition(state, totalFeeds) {
        var total = Math.max(0, Number(totalFeeds || 0));
        if (total <= 0) {
            return 0;
        }

        var index = Math.max(0, Number(state && state.current_feed ? state.current_feed : 0));
        return Math.min(total, index + 1);
    }

    function buildSupplierBackgroundViewModel(state) {
        var cumulative = state.cumulative || {};
        var results = state.results || {};
        var lastBatch = state.last_batch || {};
        var totalOffers = getSupplierOfferTotals(results);
        var processedOffers = getSupplierProcessedTotals(cumulative);
        var batchSize = Math.max(1, Number(state.batch_size || 25));
        var completedBatches = batchSize > 0 ? Math.ceil(processedOffers / batchSize) : 0;
        var totalBatches = batchSize > 0 && totalOffers > 0 ? Math.ceil(totalOffers / batchSize) : completedBatches;
        var percent = totalOffers > 0 ? (processedOffers / totalOffers) * 100 : 0;
        var status = state.status || 'idle';
        var scopeMode = state.scope_mode || 'all';
        var currentStage = state.current_stage || 'queued';
        var scopeLabel = state.scope_label || (scopeMode === 'single' ? 'Цей feed' : 'Усі увімкнені фіди');
        var feedLabel = state.current_feed_label || scopeLabel || 'Supplier feed';
        var totalFeeds = getSupplierFeedScopeCount(state);
        var currentFeedPosition = getSupplierCurrentFeedPosition(state, totalFeeds);
        var stageLabel = getSupplierStageLabel(currentStage);
        var isTerminal = status === 'completed' || status === 'failed' || status === 'cancelled';
        var isPreparing = totalOffers <= 0 && !isTerminal;
        var preparationPercent = 6;
        var preparationStats = [
            'Стадія: ' + stageLabel,
            'Очікуємо реальну кількість оферів після підготовки feed'
        ];
        var preparationMessage = 'Очікуємо перші дані від сервера...';

        if (status === 'completed') {
            percent = 100;
        }

        if (currentStage === 'preparing') {
            preparationPercent = 12;
            preparationStats = [
                'Стадія: Підготовка',
                'Завантажуємо feed постачальника',
                'Розбираємо XML та рахуємо офери'
            ];
            preparationMessage = 'Підготовка feed ще триває';
        } else if (currentStage === 'sync_categories') {
            preparationPercent = 20;
            preparationStats = [
                'Стадія: Синхронізація категорій',
                'Створюємо або оновлюємо дерево категорій',
                'Після цього з\'явиться реальний прогрес по оферах'
            ];
            preparationMessage = 'Готуємо каталог перед імпортом товарів';
        }

        return {
            status: status,
            scopeMode: scopeMode,
            scopeLabel: scopeLabel,
            feedLabel: feedLabel,
            totalFeeds: totalFeeds,
            currentFeedPosition: currentFeedPosition,
            currentStage: currentStage,
            stageLabel: stageLabel,
            totalOffers: totalOffers,
            processedOffers: processedOffers,
            created: Number(cumulative.created || 0),
            updated: Number(cumulative.updated || 0),
            skipped: Number(cumulative.skipped || 0),
            completedBatches: completedBatches,
            totalBatches: totalBatches,
            percent: percent,
            batchMessage: lastBatch.message || ('+' + (lastBatch.created || 0) + ' створ., +' + (lastBatch.updated || 0) + ' оновл., +' + (lastBatch.skipped || 0) + ' пропущ.'),
            tone: getSupplierBackgroundTone(state),
            isPreparing: isPreparing,
            preparationPercent: preparationPercent,
            preparationStats: preparationStats,
            preparationMessage: preparationMessage
        };
    }

    function updateSupplierBackgroundControls(runButton, freshButton, cancelButton, state) {
        var status = state && state.status ? state.status : 'idle';
        var isActive = status === 'queued' || status === 'running';
        var canResume = !!(state && state.can_resume);

        if (freshButton) {
            setButtonState(freshButton, !canResume || isActive, 'Почати спочатку');
        }

        if (cancelButton) {
            setButtonState(cancelButton, !isActive, 'Скасувати імпорт');
        }

        if (!runButton) {
            return;
        }

        if (status === 'queued') {
            setButtonState(runButton, true, 'У черзі...');
            updateSupplierRowBackgroundControls(state);
            return;
        }

        if (status === 'running') {
            setButtonState(runButton, true, 'Працює у фоні...');
            updateSupplierRowBackgroundControls(state);
            return;
        }

        setButtonState(runButton, false, canResume ? 'Продовжити у фоні' : 'Оновити у фоні');
        updateSupplierRowBackgroundControls(state);
    }

    function updateSupplierRowBackgroundControls(state) {
        qsa('.d14k-supplier-feed-row').forEach(function (row) {
            var button = qs('.d14k-run-feed-row', row);
            if (!button) {
                return;
            }

            var status = state && state.status ? state.status : 'idle';
            var isActive = status === 'queued' || status === 'running';
            var matchesState = doesSupplierRowMatchState(row, state);
            var canResume = !!(state && state.can_resume && matchesState);

            if (isActive) {
                if (matchesState) {
                    setButtonState(button, true, status === 'queued' ? 'У черзі...' : 'Працює у фоні...');
                } else {
                    setButtonState(button, true, 'Черга зайнята');
                }
                return;
            }

            setButtonState(button, false, canResume ? 'Продовжити цей feed' : 'Оновити цей feed у фоні');
        });
    }

    function renderSupplierImportStatus(statusElement, data, currentBatch) {
        var results = data.results || {};
        var cumulative = data.cumulative || {};
        var batch = data.batch || {};
        var totalOffers = getSupplierOfferTotals(results);
        var processedOffers = getSupplierProcessedTotals(cumulative);
        var totalBatches = totalOffers > 0 ? Math.ceil(totalOffers / 25) : currentBatch;
        var percent = totalOffers > 0 ? (processedOffers / totalOffers) * 100 : 0;

        renderProgressCard(statusElement, {
            title: data.done ? 'Імпорт завершено' : 'Імпорт постачальника виконується',
            meta: 'Батч ' + currentBatch + ' з ' + Math.max(totalBatches, currentBatch),
            percent: data.done ? 100 : percent,
            stats: [
                'Оферів: ' + totalOffers,
                'Оброблено: ' + processedOffers,
                'Створено: ' + (cumulative.created || 0),
                'Оновлено: ' + (cumulative.updated || 0),
                'Пропущено: ' + (cumulative.skipped || 0)
            ],
            batchMessage: '+' + (batch.created || 0) + ' створ., +' + (batch.updated || 0) + ' оновл., +' + (batch.skipped || 0) + ' пропущ.'
        });
    }

    function renderSupplierBackgroundStatus(statusElement, state) {
        if (!state || !state.status || state.status === 'idle') {
            renderProgressCard(statusElement, null);
            return;
        }

        var view = buildSupplierBackgroundViewModel(state);
        var detailTitle = 'Детальний статус фонового імпорту';

        if (state.status === 'completed') {
            detailTitle = 'Фоновий імпорт завершено';
        } else if (state.status === 'failed') {
            detailTitle = 'Фоновий імпорт зупинився з помилкою';
        } else if (state.status === 'cancelled') {
            detailTitle = 'Фоновий імпорт скасовано';
        } else if (state.status === 'queued') {
            detailTitle = 'Фоновий імпорт у черзі';
        }

        if (view.isPreparing) {
            renderProgressCard(statusElement, {
                title: detailTitle,
                meta: view.scopeLabel + ' • ' + view.stageLabel,
                percent: view.preparationPercent,
                stats: view.preparationStats,
                batchMessage: view.preparationMessage,
                variant: 'detail',
                tone: view.tone
            });
            return;
        }

        var detailStats = [
            'Стадія: ' + view.stageLabel,
            'Оферів: ' + view.totalOffers,
            'Оброблено: ' + view.processedOffers,
            'Створено: ' + view.created,
            'Оновлено: ' + view.updated,
            'Пропущено: ' + view.skipped
        ];

        if (view.totalFeeds > 1) {
            detailStats.splice(1, 0, 'Feed: ' + view.currentFeedPosition + ' з ' + view.totalFeeds);
        }

        renderProgressCard(statusElement, {
            title: detailTitle,
            meta: view.scopeLabel + ' • ' + view.feedLabel + ' • Батч ' + Math.max(view.completedBatches, 0) + ' з ' + Math.max(view.totalBatches, view.completedBatches, 1),
            percent: view.percent,
            stats: detailStats,
            batchMessage: view.batchMessage,
            variant: 'detail',
            tone: view.tone
        });
    }

    function renderSupplierSummaryStatus(statusElement, state) {
        if (!statusElement || !state || !state.status || state.status === 'idle') {
            renderProgressCard(statusElement, null);
            return;
        }

        var view = buildSupplierBackgroundViewModel(state);
        var title = 'Оновлюємо всі увімкнені feed';

        if (state.status === 'completed') {
            title = 'Оновлення всіх feed завершено';
        } else if (state.status === 'failed') {
            title = 'Оновлення всіх feed зупинилося';
        } else if (state.status === 'cancelled') {
            title = 'Оновлення всіх feed скасовано';
        } else if (state.status === 'queued') {
            title = 'Усі увімкнені feed у черзі';
        }

        var summaryStats = view.isPreparing
            ? [
                'Feed: ' + Math.max(view.currentFeedPosition, 1) + ' з ' + Math.max(view.totalFeeds, 1),
                'Стадія: ' + view.stageLabel
            ]
            : [
                'Feed: ' + Math.max(view.currentFeedPosition, 1) + ' з ' + Math.max(view.totalFeeds, 1),
                'Оброблено: ' + view.processedOffers + ' з ' + view.totalOffers,
                'Створено: ' + view.created,
                'Оновлено: ' + view.updated,
                'Пропущено: ' + view.skipped
            ];

        renderProgressCard(statusElement, {
            title: title,
            meta: 'Поточний feed: ' + view.feedLabel + ' • ' + view.stageLabel,
            percent: view.isPreparing ? view.preparationPercent : view.percent,
            stats: summaryStats,
            batchMessage: view.isPreparing ? view.preparationMessage : view.batchMessage,
            batchLabel: 'Зараз:',
            variant: 'summary',
            tone: view.tone
        });
    }

    function renderSupplierRowLiveStatus(row, state) {
        var statusElement = qs('.d14k-supplier-feed-row__live-status', row);
        if (!statusElement) {
            return;
        }

        if (!state || !state.status || state.status === 'idle') {
            renderProgressCard(statusElement, null);
            return;
        }

        var view = buildSupplierBackgroundViewModel(state);
        var title = 'Цей feed оновлюється';

        if (state.status === 'completed') {
            title = 'Статус feed';
        } else if (state.status === 'failed') {
            title = 'Цей feed зупинився з помилкою';
        } else if (state.status === 'cancelled') {
            title = 'Цей feed скасовано';
        } else if (state.status === 'queued') {
            title = 'Цей feed у черзі';
        }

        var rowMeta = view.stageLabel;
        if (view.totalOffers > 0) {
            rowMeta += ' • Батч ' + Math.max(view.completedBatches, 0) + ' з ' + Math.max(view.totalBatches, view.completedBatches, 1);
        }

        renderProgressCard(statusElement, {
            title: title,
            meta: rowMeta,
            percent: view.isPreparing ? view.preparationPercent : view.percent,
            stats: view.isPreparing
                ? ['Стадія: ' + view.stageLabel, 'Очікуємо підрахунок оферів']
                : [
                    'Оброблено: ' + view.processedOffers + ' з ' + view.totalOffers,
                    'Створено: ' + view.created,
                    'Оновлено: ' + view.updated,
                    'Пропущено: ' + view.skipped
                ],
            batchMessage: view.isPreparing ? view.preparationMessage : view.batchMessage,
            batchLabel: 'Зараз:',
            variant: 'inline',
            tone: view.tone
        });
    }

    function clearSupplierRowLiveStatuses() {
        qsa('.d14k-supplier-feed-row__live-status').forEach(function (element) {
            renderProgressCard(element, null);
        });
    }

    function findSupplierRowForState(state) {
        var rows = qsa('.d14k-supplier-feed-row');
        var i;

        for (i = 0; i < rows.length; i += 1) {
            if (doesSupplierRowMatchState(rows[i], state)) {
                return rows[i];
            }
        }

        return null;
    }

    function renderSupplierBackgroundUi(state) {
        var detailCard = qs('#d14k-supplier-import-status');
        var summaryCard = qs('#d14k-supplier-import-summary');
        var matchingRow = findSupplierRowForState(state);

        renderSupplierBackgroundStatus(detailCard, state);
        renderProgressCard(summaryCard, null);
        clearSupplierRowLiveStatuses();

        if (!state || !state.status || state.status === 'idle') {
            return;
        }

        if ((state.scope_mode || 'all') === 'single' && matchingRow) {
            renderSupplierRowLiveStatus(matchingRow, state);
            return;
        }

        renderSupplierSummaryStatus(summaryCard, state);
    }

    function pollSupplierBackgroundStatus(button, freshButton, cancelButton, result, statusCard) {
        postAdminAction({
            action: 'd14k_supplier_feeds_background_status',
        }).then(function (response) {
            var data = response.data || {};
            updateSupplierBackgroundControls(button, freshButton, cancelButton, data);
            renderSupplierBackgroundUi(data);

            if (data.status === 'idle') {
                setSyncMessage(result, '', '', false);
                return;
            }

            if (data.status === 'completed') {
                setSyncMessage(result, 'success', 'Фоновий імпорт завершено.', false);
                return;
            }

            if (data.status === 'failed') {
                setSyncMessage(result, 'error', 'Фоновий імпорт завершився з помилкою.', false);
                return;
            }

            if (data.status === 'cancelled') {
                setSyncMessage(result, 'error', 'Фоновий імпорт скасовано.', false);
                return;
            }

            setSyncMessage(result, 'pending', 'Імпорт працює у фоні. Сторінку можна оновити або закрити.', false);

            setTimeout(function () {
                pollSupplierBackgroundStatus(button, freshButton, cancelButton, result, statusCard);
            }, 2000);
        }).catch(function (error) {
            var status = error && error.status ? error.status : '0';
            updateSupplierBackgroundControls(button, freshButton, cancelButton, { status: 'idle' });
            renderSupplierBackgroundUi({ status: 'idle' });
            setSyncMessage(result, 'error', 'Не вдалося отримати статус фонового імпорту. HTTP ' + status + '.', false);
        });
    }

    function startSupplierBackgroundImport(button, freshButton, cancelButton, result, options) {
        var statusCard = qs('#d14k-supplier-import-status');
        var config = options || {};
        var isFreshStart = !!config.freshStart;
        var maxItemsPerFeed = Math.max(0, Number(config.maxItemsPerFeed || 0));
        var selectionMode = (config.selectionMode || '').trim();
        var isTestMode = !!config.testMode || maxItemsPerFeed > 0;
        var isResume = !isFreshStart && (
            typeof config.isResume === 'boolean'
                ? config.isResume
                : (button && button.textContent.indexOf('Продовжити') !== -1)
        );
        var row = config.row || null;
        var rowPayload = config.feed || null;
        var provisionalState = {
            status: 'queued',
            scope_mode: rowPayload ? 'single' : 'all',
            scope_label: rowPayload
                ? ((rowPayload.label || '').trim() || 'Цей feed')
                : 'Усі увімкнені фіди',
            current_stage: 'queued',
            current_feed: 0,
            current_feed_label: rowPayload
                ? ((rowPayload.label || '').trim() || 'Цей feed')
                : 'Черга',
            feeds: rowPayload ? [rowPayload] : [],
            max_items_per_feed: maxItemsPerFeed,
            selection_mode: selectionMode
        };
        if (isTestMode) {
            provisionalState.scope_label += ' • тест ' + maxItemsPerFeed + ' товар(и)';
            if (selectionMode === 'nonempty_params') {
                provisionalState.scope_label += ' з характеристиками';
            }
        }
        var formData = new FormData();

        formData.append('action', 'd14k_supplier_feeds_background_start');
        formData.append('batch_size', '25');
        if (maxItemsPerFeed > 0) {
            formData.append('max_items_per_feed', String(maxItemsPerFeed));
        }
        if (selectionMode) {
            formData.append('selection_mode', selectionMode);
        }
        if (isFreshStart) {
            formData.append('fresh_start', '1');
        }
        appendSupplierFeedPayload(formData, rowPayload);

        updateSupplierBackgroundControls(button, freshButton, cancelButton, { status: 'queued' });
        setButtonState(button, true, 'Ставимо в чергу...');
        setSyncMessage(
            result,
            'pending',
            isTestMode
                ? (
                    selectionMode === 'nonempty_params'
                        ? 'Запускаємо тестовий supplier import на ' + maxItemsPerFeed + ' товар(и) з непорожніми характеристиками...'
                        : 'Запускаємо тестовий supplier import на ' + maxItemsPerFeed + ' товар(и)...'
                )
                : (isFreshStart
                    ? 'Запускаємо supplier import з нуля у фоновій черзі...'
                    : (isResume
                        ? 'Відновлюємо supplier import у фоновій черзі...'
                        : 'Ставимо supplier import у фонову чергу...')),
            false
        );
        if (row) {
            setSupplierRowMessage(
                row,
                'pending',
                rowPayload
                    ? (isTestMode
                        ? 'Запускаємо тестовий прогін цього feed на ' + maxItemsPerFeed + ' товар(и)...'
                        : (isResume
                            ? 'Відновлюємо цей feed у фоновій черзі...'
                            : 'Ставимо цей feed у фонову чергу...'))
                    : 'Ставимо feed у фонову чергу...'
            );
        }
        renderSupplierBackgroundUi(provisionalState);
        renderProgressCard(statusCard, {
            title: isTestMode
                ? 'Тестовий імпорт готується'
                : (isFreshStart
                    ? 'Фоновий імпорт запускається з нуля'
                    : (isResume ? 'Фоновий імпорт відновлюється' : 'Фоновий імпорт готується')),
            meta: rowPayload && rowPayload.label
                ? rowPayload.label + ' • Черга'
                : 'Черга',
            percent: (isFreshStart || isTestMode) ? 2 : 0,
            stats: [isTestMode
                ? 'Обмежуємо прогін до ' + maxItemsPerFeed + ' товар(и) для безпечної перевірки.'
                : (isFreshStart ? 'Очищаємо попередній state і готуємо новий прохід...' : (isResume ? 'Піднімаємо імпорт із збереженого offset...' : 'Створюємо job...'))],
            batchMessage: isTestMode ? 'Готуємо тестовий запуск' : (isFreshStart ? 'Готуємо чистий запуск' : (isResume ? 'Готуємо продовження' : 'Очікування відповіді')),
            variant: 'detail',
            tone: 'pending'
        });

        postAdminFormData(formData).then(function (response) {
            if (!response.success) {
                updateSupplierBackgroundControls(button, freshButton, cancelButton, { status: 'idle' });
                renderSupplierBackgroundUi({ status: 'idle' });
                setSyncMessage(result, 'error', response.data || 'Помилка', false);
                if (row) {
                    setSupplierRowMessage(row, 'error', response.data || 'Не вдалося поставити feed у чергу.');
                }
                return;
            }

            if (row) {
                setSupplierRowMessage(
                    row,
                    'success',
                    isTestMode
                        ? 'Цей feed поставлено у тестову фонову чергу.'
                        : (isResume ? 'Цей feed поставлено на продовження у фоні.' : 'Цей feed поставлено у фонову чергу.')
                );
            }
            renderSupplierBackgroundUi(response.data || {});
            pollSupplierBackgroundStatus(button, freshButton, cancelButton, result, statusCard);
        }).catch(function (error) {
            var status = error && error.status ? error.status : '0';
            updateSupplierBackgroundControls(button, freshButton, cancelButton, { status: 'idle' });
            renderSupplierBackgroundUi({ status: 'idle' });
            setSyncMessage(result, 'error', 'Не вдалося поставити імпорт у чергу. HTTP ' + status + '.', false);
            if (row) {
                setSupplierRowMessage(row, 'error', 'Не вдалося поставити цей feed у чергу. HTTP ' + status + '.');
            }
        });
    }

    function cancelSupplierBackgroundImport(runButton, freshButton, cancelButton, result) {
        setButtonState(cancelButton, true, 'Скасовуємо...');
        setSyncMessage(result, 'pending', 'Скасовуємо фоновий імпорт...', false);

        postAdminAction({
            action: 'd14k_supplier_feeds_background_cancel',
        }).then(function (response) {
            if (!response.success) {
                setButtonState(cancelButton, false, 'Скасувати імпорт');
                setSyncMessage(result, 'error', response.data || 'Не вдалося скасувати імпорт.', false);
                return;
            }

            renderSupplierBackgroundUi(response.data || {});
            updateSupplierBackgroundControls(runButton, freshButton, cancelButton, response.data || { status: 'cancelled' });
            setSyncMessage(result, 'success', 'Фоновий імпорт скасовано.', false);
        }).catch(function (error) {
            var status = error && error.status ? error.status : '0';
            setButtonState(cancelButton, false, 'Скасувати імпорт');
            setSyncMessage(result, 'error', 'Не вдалося скасувати імпорт. HTTP ' + status + '.', false);
        });
    }

    function startSupplierRowBackgroundImport(row) {
        var runButton = qs('#d14k-supplier-feeds-run-now');
        var freshButton = qs('#d14k-supplier-feeds-fresh-start');
        var cancelButton = qs('#d14k-supplier-feeds-cancel');
        var result = qs('#d14k-supplier-feeds-result');
        var rowButton = qs('.d14k-run-feed-row', row);
        var payload = collectSupplierRowPayload(row);

        if (!rowButton || !runButton || !result) {
            return;
        }

        if (!payload.url.trim()) {
            setSupplierRowMessage(row, 'error', 'Вкажіть URL фіду перед запуском.');
            return;
        }

        startSupplierBackgroundImport(runButton, freshButton, cancelButton, result, {
            row: row,
            feed: payload,
            isResume: rowButton.textContent.indexOf('Продовжити') !== -1,
        });
    }

    function startSupplierRowTestImport(row) {
        var runButton = qs('#d14k-supplier-feeds-run-now');
        var freshButton = qs('#d14k-supplier-feeds-fresh-start');
        var cancelButton = qs('#d14k-supplier-feeds-cancel');
        var result = qs('#d14k-supplier-feeds-result');
        var rowButton = qs('.d14k-run-feed-row-test', row);
        var payload = collectSupplierRowPayload(row);

        if (!rowButton || !runButton || !result) {
            return;
        }

        if (!payload.url.trim()) {
            setSupplierRowMessage(row, 'error', 'Вкажіть URL фіду перед тестовим запуском.');
            return;
        }

        startSupplierBackgroundImport(runButton, freshButton, cancelButton, result, {
            row: row,
            feed: payload,
            freshStart: true,
            isResume: false,
            maxItemsPerFeed: 2,
            selectionMode: 'nonempty_params',
            testMode: true,
        });
    }

    function renderSupplierCleanupStatus(statusElement, data, currentBatch) {
        var cumulative = data.cumulative || {};
        var batch = data.batch || {};

        renderProgressCard(statusElement, {
            title: data.done ? 'Cleanup завершено' : 'Cleanup постачальника виконується',
            meta: 'Батч ' + currentBatch,
            percent: data.done ? 100 : 12,
            stats: [
                'Перевірено: ' + (cumulative.scanned || 0),
                'В кошик: ' + (cumulative.trashed || 0),
                'Залишено: ' + (cumulative.kept || 0),
                'Категорій прибрано: ' + (cumulative.categories_deleted || 0)
            ],
            batchMessage: '+' + (batch.trashed || 0) + ' в кошик, +' + (batch.kept || 0) + ' залишено.'
        });
    }

    function renderSupplierRollbackStatus(statusElement, data) {
        renderProgressCard(statusElement, {
            title: 'Rollback останнього імпорту завершено',
            meta: data.scope_label || 'Supplier import',
            percent: 100,
            stats: [
                'Видалено товарів: ' + (data.deleted_products || 0),
                'Видалено категорій: ' + (data.deleted_categories || 0),
                'Пропущено товарів: ' + (data.skipped_products || 0),
                'Пропущено категорій: ' + (data.skipped_categories || 0)
            ],
            batchMessage: data.snapshot_status
                ? 'Стан snapshot: ' + data.snapshot_status
                : 'Rollback виконано'
        });
    }

    function bindCopyButtons() {
        qsa('.d14k-copy').forEach(function (button) {
            button.addEventListener('click', function () {
                var targetId = button.getAttribute('data-target');
                var target = targetId ? document.getElementById(targetId) : null;
                if (!target) {
                    return;
                }

                var originalLabel = button.textContent;
                copyToClipboard(target.textContent.trim()).then(function () {
                    button.textContent = '✓ Скопійовано';
                    button.classList.add('d14k-copy--success');
                    setTimeout(function () {
                        button.textContent = originalLabel;
                        button.classList.remove('d14k-copy--success');
                    }, 1500);
                });
            });
        });
    }

    function bindGenerateForms() {
        var generateAction = qs('form[action*="admin-post.php"] input[name="action"][value="d14k_generate_now"]');
        if (generateAction) {
            var generateForm = generateAction.closest('form');
            generateForm.addEventListener('submit', function () {
                var button = generateForm.querySelector('button[type="submit"]');
                if (button) {
                    button.disabled = true;
                    button.innerHTML = '<span class="spinner"></span>Генерація фідів…';
                    button.classList.add('d14k-generating');
                }
            });
        }

        var validationAction = qs('form[action*="admin-post.php"] input[name="action"][value="d14k_test_validation"]');
        if (validationAction) {
            var validationForm = validationAction.closest('form');
            validationForm.addEventListener('submit', function () {
                var button = validationForm.querySelector('button[type="submit"]');
                if (button) {
                    button.disabled = true;
                    button.innerHTML = '<span class="spinner"></span>Перевірка…';
                }
            });
        }
    }

    function bindChannelToggles() {
        qsa('.d14k-channel-toggle').forEach(function (toggle) {
            toggle.addEventListener('change', function () {
                var channel = toggle.getAttribute('data-channel');
                var enabled = toggle.checked;

                toggle.disabled = true;

                postAdminAction({
                    action: 'd14k_toggle_channel',
                    channel: channel,
                    enabled: enabled ? '1' : '0',
                }).then(function (response) {
                    toggle.disabled = false;
                    if (response.success) {
                        window.location.reload();
                        return;
                    }
                    toggle.checked = !enabled;
                    alert('Помилка: ' + (response.data || 'Невідома помилка'));
                }).catch(function () {
                    toggle.disabled = false;
                    toggle.checked = !enabled;
                    alert('Помилка мережі');
                });
            });
        });
    }

    function bindChannelGeneration() {
        qsa('.d14k-generate-channel').forEach(function (button) {
            button.addEventListener('click', function () {
                var channel = button.getAttribute('data-channel');
                if (button.disabled) {
                    return;
                }

                var originalLabel = button.textContent;
                button.disabled = true;
                button.innerHTML = '<span class="spinner"></span>Генерація…';

                postAdminAction({
                    action: 'd14k_generate_channel',
                    channel: channel,
                }).then(function (response) {
                    button.disabled = false;
                    if (!response.success) {
                        button.textContent = originalLabel;
                        alert('Помилка генерації: ' + (response.data || 'Перевірте логи'));
                        return;
                    }

                    button.textContent = '✓ Готово!';
                    button.classList.add('d14k-generate--success');

                    var row = button.closest('tr');
                    if (row && response.data && response.data.generated) {
                        var cells = row.querySelectorAll('td');
                        if (cells[3]) {
                            cells[3].textContent = response.data.generated;
                        }
                        if (cells[4] && response.data.stats) {
                            cells[4].textContent = response.data.stats.valid || '—';
                        }
                    }

                    setTimeout(function () {
                        button.textContent = originalLabel;
                        button.classList.remove('d14k-generate--success');
                    }, 2000);
                }).catch(function () {
                    button.disabled = false;
                    button.textContent = originalLabel;
                    alert('Помилка мережі');
                });
            });
        });
    }

    function bindSimpleAction(config) {
        var button = qs(config.button);
        var result = qs(config.result);
        if (!button || !result) {
            return;
        }

        button.addEventListener('click', function () {
            setButtonState(button, true, config.pendingLabel);
            setSyncMessage(result, config.pendingType || 'pending', config.pendingMessage || '', false);

            postAdminAction(config.payload()).then(function (response) {
                setButtonState(button, false, config.idleLabel);
                if (response.success) {
                    var successText = config.successMessage ? config.successMessage(response) : (((response.data || {}).message) || '');
                    setSyncMessage(result, 'success', successText, !!config.successMessageUsesHtml);
                    return;
                }
                setSyncMessage(result, 'error', response.data || 'Помилка', false);
            }).catch(function () {
                setButtonState(button, false, config.idleLabel);
                setSyncMessage(result, 'error', config.errorMessage || 'Помилка запиту', false);
            });
        });
    }

    function runPromImportBatches(button, result, lastId, batchNumber) {
        var currentBatch = batchNumber + 1;

        setButtonState(button, true, 'Батч ' + currentBatch + '...');
        setSyncMessage(result, 'pending', 'Імпорт батч ' + currentBatch + ' (last_id=' + lastId + ')...', true);

        postAdminAction({
            action: 'd14k_prom_import_now',
            last_id: lastId,
        }).then(function (response) {
            if (!response.success) {
                setButtonState(button, false, 'Імпортувати зараз');
                setSyncMessage(result, 'error', response.data || 'Помилка', false);
                return;
            }

            var data = response.data || {};
            var cumulative = data.cumulative || {};
            var summary = 'Створено: ' + (cumulative.created || 0) +
                ', оновлено: ' + (cumulative.updated || 0) +
                ', пропущено: ' + (cumulative.skipped || 0);

            if (data.done) {
                setButtonState(button, false, 'Імпортувати зараз');
                setSyncMessage(result, 'success', 'Імпорт завершено. ' + summary, true);
                return;
            }

            setSyncMessage(result, 'pending', 'Батч ' + currentBatch + ' готовий. ' + summary + '. Продовжуємо...', true);

            setTimeout(function () {
                runPromImportBatches(button, result, data.next_last_id, currentBatch);
            }, 500);
        }).catch(function (error) {
            var status = error && error.status ? error.status : '0';
            setButtonState(button, false, 'Імпортувати зараз');
            setSyncMessage(result, 'error', 'Помилка HTTP ' + status + ' (батч ' + currentBatch + '). Натисніть ще раз для продовження.', false);
        });
    }

    function runSupplierImportBatches(button, result, batchNumber, reset) {
        var currentBatch = batchNumber + 1;
        var statusCard = qs('#d14k-supplier-import-status');

        setButtonState(button, true, 'Батч ' + currentBatch + '...');
        setSyncMessage(result, 'pending', 'Імпорт supplier batch ' + currentBatch + '...', false);
        renderProgressCard(statusCard, {
            title: 'Імпорт постачальника запускається',
            meta: 'Батч ' + currentBatch,
            percent: 0,
            stats: ['Очікуємо відповідь сервера...'],
            batchMessage: 'Старт запиту'
        });

        postAdminAction({
            action: 'd14k_supplier_feeds_run',
            batch_size: 25,
            reset: reset ? '1' : '0',
        }).then(function (response) {
            if (!response.success) {
                setButtonState(button, false, 'Оновити зараз');
                setSyncMessage(result, 'error', response.data || 'Помилка', false);
                return;
            }

            var data = response.data || {};
            var cumulative = data.cumulative || {};
            var batch = data.batch || {};
            var summary = 'Створено: ' + (cumulative.created || 0) +
                ', оновлено: ' + (cumulative.updated || 0) +
                ', пропущено: ' + (cumulative.skipped || 0);

            renderSupplierImportStatus(statusCard, data, currentBatch);

            if (data.done) {
                setButtonState(button, false, 'Оновити зараз');
                setSyncMessage(result, 'success', 'Імпорт завершено. ' + summary, false);
                return;
            }

            setSyncMessage(
                result,
                'pending',
                'Готовий batch ' + currentBatch + '. Поточний batch: +' + (batch.created || 0) +
                    ' створ., +' + (batch.updated || 0) +
                    ' оновл., +' + (batch.skipped || 0) + ' пропущ. ' + summary,
                false
            );

            setTimeout(function () {
                runSupplierImportBatches(button, result, currentBatch, false);
            }, 350);
        }).catch(function (error) {
            var status = error && error.status ? error.status : '0';
            setButtonState(button, false, 'Оновити зараз');
            setSyncMessage(result, 'error', 'Помилка HTTP ' + status + ' на supplier batch ' + currentBatch + '.', false);
            renderProgressCard(statusCard, {
                title: 'Імпорт зупинився з помилкою',
                meta: 'Батч ' + currentBatch,
                percent: 0,
                stats: ['HTTP ' + status],
                batchMessage: 'Запит завершився помилкою'
            });
        });
    }

    function runSupplierCleanupBatches(button, result, batchNumber, reset) {
        var currentBatch = batchNumber + 1;
        var statusCard = qs('#d14k-supplier-cleanup-status');

        setButtonState(button, true, 'Чистимо batch ' + currentBatch + '...');
        setSyncMessage(result, 'pending', 'Перевіряємо supplier товари batch ' + currentBatch + '...', false);
        renderProgressCard(statusCard, {
            title: 'Cleanup постачальника запускається',
            meta: 'Батч ' + currentBatch,
            percent: 0,
            stats: ['Очікуємо відповідь сервера...'],
            batchMessage: 'Старт запиту'
        });

        postAdminAction({
            action: 'd14k_supplier_feeds_cleanup',
            batch_size: 25,
            reset: reset ? '1' : '0',
        }).then(function (response) {
            if (!response.success) {
                setButtonState(button, false, 'Прибрати некоректні');
                setSyncMessage(result, 'error', response.data || 'Помилка', false);
                return;
            }

            var data = response.data || {};
            var cumulative = data.cumulative || {};
            var batch = data.batch || {};
            var summary = 'Перевірено: ' + (cumulative.scanned || 0) +
                ', в кошик: ' + (cumulative.trashed || 0) +
                ', залишено: ' + (cumulative.kept || 0) +
                ', категорій прибрано: ' + (cumulative.categories_deleted || 0);

            renderSupplierCleanupStatus(statusCard, data, currentBatch);

            if (data.done) {
                setButtonState(button, false, 'Прибрати некоректні');
                setSyncMessage(result, 'success', 'Cleanup завершено. ' + summary, false);
                return;
            }

            setSyncMessage(
                result,
                'pending',
                'Готовий cleanup batch ' + currentBatch + '. Поточний batch: +' + (batch.trashed || 0) +
                    ' в кошик, +' + (batch.kept || 0) + ' залишено. ' + summary,
                false
            );

            setTimeout(function () {
                runSupplierCleanupBatches(button, result, currentBatch, false);
            }, 350);
        }).catch(function (error) {
            var status = error && error.status ? error.status : '0';
            setButtonState(button, false, 'Прибрати некоректні');
            setSyncMessage(result, 'error', 'Помилка HTTP ' + status + ' на cleanup batch ' + currentBatch + '.', false);
            renderProgressCard(statusCard, {
                title: 'Cleanup зупинився з помилкою',
                meta: 'Батч ' + currentBatch,
                percent: 0,
                stats: ['HTTP ' + status],
                batchMessage: 'Запит завершився помилкою'
            });
        });
    }

    function bindPromActions() {
        bindSimpleAction({
            button: '#d14k-prom-test-api',
            result: '#d14k-prom-test-result',
            idleLabel: 'Перевірити з\'єднання',
            pendingLabel: 'Перевіряємо...',
            pendingType: '',
            pendingMessage: '',
            payload: function () {
                var tokenInput = qs('input[name="prom_api_token"]');
                return {
                    action: 'd14k_prom_test_api',
                    token: tokenInput ? tokenInput.value : '',
                };
            },
            errorMessage: 'Помилка мережі',
        });

        bindSimpleAction({
            button: '#d14k-prom-export-now',
            result: '#d14k-prom-export-result',
            idleLabel: 'Відправити на Prom зараз',
            pendingLabel: 'Надсилаємо...',
            pendingMessage: 'Відправляємо фід на Prom.ua...',
            payload: function () {
                return { action: 'd14k_prom_export_now' };
            },
        });

        bindSimpleAction({
            button: '#d14k-supplier-feeds-analyze',
            result: '#d14k-supplier-feeds-analysis-result',
            idleLabel: 'Аналізувати всі фіди',
            pendingLabel: 'Аналізуємо...',
            pendingMessage: 'Завантажуємо feed і рахуємо, що буде створено або оновлено без запису товарів...',
            payload: function () {
                return { action: 'd14k_supplier_feeds_analyze' };
            },
            successMessage: function (response) {
                var data = response.data || {};
                return data.html || data.message || 'Аналіз завершено';
            },
            successMessageUsesHtml: true,
        });

        var supplierRunButton = qs('#d14k-supplier-feeds-run-now');
        var supplierFreshButton = qs('#d14k-supplier-feeds-fresh-start');
        var supplierCancelButton = qs('#d14k-supplier-feeds-cancel');
        var supplierRunResult = qs('#d14k-supplier-feeds-result');
        if (supplierRunButton && supplierRunResult) {
            supplierRunButton.addEventListener('click', function () {
                startSupplierBackgroundImport(supplierRunButton, supplierFreshButton, supplierCancelButton, supplierRunResult);
            });

            if (supplierFreshButton) {
                supplierFreshButton.addEventListener('click', function () {
                    startSupplierBackgroundImport(supplierRunButton, supplierFreshButton, supplierCancelButton, supplierRunResult, {
                        freshStart: true,
                    });
                });
            }

            if (supplierCancelButton) {
                supplierCancelButton.addEventListener('click', function () {
                    cancelSupplierBackgroundImport(supplierRunButton, supplierFreshButton, supplierCancelButton, supplierRunResult);
                });
            }

            postAdminAction({
                action: 'd14k_supplier_feeds_background_status',
            }).then(function (response) {
                var data = response.data || {};
                updateSupplierBackgroundControls(supplierRunButton, supplierFreshButton, supplierCancelButton, data);
                if (data.status === 'queued' || data.status === 'running') {
                    pollSupplierBackgroundStatus(supplierRunButton, supplierFreshButton, supplierCancelButton, supplierRunResult, qs('#d14k-supplier-import-status'));
                } else if (data.status === 'failed' || data.status === 'cancelled') {
                    renderSupplierBackgroundUi(data);
                    setSyncMessage(
                        supplierRunResult,
                        'error',
                        data.can_resume
                            ? (data.status === 'cancelled'
                                ? 'Останній фоновий імпорт було скасовано. Можна продовжити з цього місця або почати спочатку.'
                                : 'Останній фоновий імпорт завершився з помилкою. Можна продовжити з цього місця або почати спочатку.')
                            : (data.status === 'cancelled'
                                ? 'Останній фоновий імпорт було скасовано.'
                                : 'Останній фоновий імпорт завершився з помилкою.'),
                        false
                    );
                } else if (data.status === 'completed') {
                    renderSupplierBackgroundUi(data);
                    setSyncMessage(supplierRunResult, '', '', false);
                }
            }).catch(function () {
                // no-op
            });
        }

        var supplierCleanupButton = qs('#d14k-supplier-feeds-cleanup');
        var supplierCleanupResult = qs('#d14k-supplier-feeds-cleanup-result');
        if (supplierCleanupButton && supplierCleanupResult) {
            supplierCleanupButton.addEventListener('click', function () {
                runSupplierCleanupBatches(supplierCleanupButton, supplierCleanupResult, 0, true);
            });
        }

        var supplierRollbackButton = qs('#d14k-supplier-feeds-rollback');
        var supplierRollbackResult = qs('#d14k-supplier-feeds-rollback-result');
        if (supplierRollbackButton && supplierRollbackResult) {
            supplierRollbackButton.addEventListener('click', function () {
                var rollbackStatus = qs('#d14k-supplier-rollback-status');

                setButtonState(supplierRollbackButton, true, 'Відкочуємо...');
                setSyncMessage(supplierRollbackResult, 'pending', 'Видаляємо сутності з останнього supplier-імпорту...', false);

                if (rollbackStatus) {
                    renderProgressCard(rollbackStatus, {
                        title: 'Rollback останнього імпорту запускається',
                        meta: 'Очікуємо відповідь сервера',
                        percent: 0,
                        stats: ['Підготовка до видалення...'],
                        batchMessage: 'Старт rollback'
                    });
                }

                postAdminAction({
                    action: 'd14k_supplier_feeds_rollback_last_import'
                }).then(function (response) {
                    if (!response.success) {
                        setButtonState(supplierRollbackButton, false, 'Відкотити останній імпорт');
                        setSyncMessage(supplierRollbackResult, 'error', response.data || 'Помилка rollback', false);
                        return;
                    }

                    var data = response.data || {};
                    setButtonState(supplierRollbackButton, false, 'Відкотити останній імпорт');
                    setSyncMessage(
                        supplierRollbackResult,
                        'success',
                        'Rollback завершено. Видалено товарів: ' + (data.deleted_products || 0) +
                            ', категорій: ' + (data.deleted_categories || 0) + '.',
                        false
                    );

                    if (rollbackStatus) {
                        renderSupplierRollbackStatus(rollbackStatus, data);
                    }
                }).catch(function (error) {
                    var status = error && error.status ? error.status : '0';
                    setButtonState(supplierRollbackButton, false, 'Відкотити останній імпорт');
                    setSyncMessage(supplierRollbackResult, 'error', 'Помилка HTTP ' + status + ' під час rollback.', false);

                    if (rollbackStatus) {
                        renderProgressCard(rollbackStatus, {
                            title: 'Rollback зупинився з помилкою',
                            meta: 'HTTP ' + status,
                            percent: 0,
                            stats: ['Спробуйте повторити ще раз'],
                            batchMessage: 'Запит завершився помилкою'
                        });
                    }
                });
            });
        }

        var importButton = qs('#d14k-prom-import-now');
        var importResult = qs('#d14k-prom-import-result');
        if (importButton && importResult) {
            importButton.addEventListener('click', function () {
                runPromImportBatches(importButton, importResult, 0, 0);
            });
        }

        var statusButton = qs('#d14k-prom-check-status');
        if (statusButton) {
            statusButton.addEventListener('click', function () {
                setButtonState(statusButton, true, '...');

                postAdminAction({
                    action: 'd14k_prom_import_status',
                }).then(function (response) {
                    setButtonState(statusButton, false, 'Оновити статус');
                    if (response.success && response.data && response.data.status) {
                        alert('Статус імпорту на Prom.ua: ' + response.data.status);
                    }
                }).catch(function () {
                    setButtonState(statusButton, false, 'Оновити статус');
                });
            });
        }
    }

    function bindCodeToggles() {
        document.addEventListener('click', function (event) {
            var toggle = event.target.closest('.d14k-url-toggle[data-target]');
            if (!toggle) {
                return;
            }

            var targetId = toggle.getAttribute('data-target');
            var target = targetId ? document.getElementById(targetId) : null;
            if (!target) {
                return;
            }

            target.style.display = target.style.display === 'none' ? 'block' : 'none';
        });
    }

    function getNextSupplierIndex() {
        var names = qsa('#d14k-supplier-feeds-list input[name^="supplier_feeds["]').map(function (input) {
            var match = input.name.match(/^supplier_feeds\[(\d+)\]/);
            return match ? parseInt(match[1], 10) : -1;
        }).filter(function (value) {
            return value >= 0;
        });

        if (!names.length) {
            return 0;
        }

        return Math.max.apply(Math, names) + 1;
    }

    function createSupplierEmptyState() {
        var wrapper = document.createElement('div');

        wrapper.className = 'd14k-supplier-empty-state';
        wrapper.innerHTML =
            '<strong>Ще немає жодного feed постачальника.</strong>' +
            '<p>Додайте перший feed, щоб налаштувати аналіз, розклад і фоновий імпорт без зайвого шуму в інтерфейсі.</p>';

        return wrapper;
    }

    function ensureSupplierEmptyState(list) {
        if (!list) {
            return;
        }

        if (!qsa('.d14k-supplier-feed-row', list).length && !qs('.d14k-supplier-empty-state', list)) {
            list.appendChild(createSupplierEmptyState());
        }
    }

    function createSupplierFeedRow(index) {
        var wrapper = document.createElement('div');

        wrapper.className = 'd14k-supplier-feed-row';
        wrapper.setAttribute('data-supplier-index', String(index));
        wrapper.innerHTML =
            '<div class="d14k-supplier-feed-row__top">' +
                '<div class="d14k-supplier-feed-row__identity">' +
                    '<div class="d14k-supplier-feed-row__title-row">' +
                        '<h3 class="d14k-supplier-feed-row__title">Новий постачальник</h3>' +
                        '<div class="d14k-supplier-feed-row__badges">' +
                            '<span class="d14k-supplier-state-badge d14k-supplier-state-badge--schedule" data-role="schedule-badge">Раз на добу • 01:00</span>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
                '<div class="d14k-supplier-feed-row__side-actions">' +
                    '<label class="d14k-supplier-feed-flag d14k-supplier-feed-flag--toggle"><input type="checkbox" name="supplier_feeds[' + index + '][enabled]" value="1" checked><span>Feed увімкнений</span></label>' +
                '</div>' +
            '</div>' +
            '<div class="d14k-supplier-feed-row__grid">' +
                '<section class="d14k-supplier-feed-panel d14k-supplier-feed-panel--source">' +
                    '<div class="d14k-supplier-feed-panel__head">' +
                        '<h4>Джерело</h4>' +
                        '<p>Базові дані постачальника й коренева категорія для цього feed.</p>' +
                    '</div>' +
                    '<div class="d14k-supplier-feed-panel__fields d14k-supplier-feed-panel__fields--source">' +
                        '<label class="d14k-supplier-feed-row__field d14k-supplier-feed-row__field--url">' +
                            '<span class="d14k-supplier-feed-row__field-label">URL фіду</span>' +
                            '<input type="url" name="supplier_feeds[' + index + '][url]" placeholder="https://supplier.com/feed.xml" class="regular-text d14k-supplier-feed-row__url">' +
                        '</label>' +
                        '<label class="d14k-supplier-feed-row__field d14k-supplier-feed-row__field--compact d14k-supplier-feed-row__field--markup">' +
                            '<span class="d14k-supplier-feed-row__field-label">Націнка</span>' +
                            '<span class="d14k-supplier-feed-row__markup-shell">' +
                                '<input type="number" name="supplier_feeds[' + index + '][markup]" placeholder="0" min="0" max="1000" step="0.1" class="small-text d14k-supplier-feed-row__markup" value="0">' +
                                '<span class="d14k-supplier-feed-row__markup-unit">відсотків</span>' +
                            '</span>' +
                        '</label>' +
                        '<label class="d14k-supplier-feed-row__field d14k-supplier-feed-row__field--label">' +
                            '<span class="d14k-supplier-feed-row__field-label">Назва постачальника</span>' +
                            '<input type="text" name="supplier_feeds[' + index + '][label]" placeholder="Назва постачальника" class="regular-text d14k-supplier-feed-row__label">' +
                        '</label>' +
                        '<label class="d14k-supplier-feed-row__field d14k-supplier-feed-row__field--category">' +
                            '<span class="d14k-supplier-feed-row__field-label">Корінь категорій</span>' +
                            '<input type="text" name="supplier_feeds[' + index + '][category_root]" placeholder="Окрема папка для цього постачальника" class="regular-text">' +
                        '</label>' +
                    '</div>' +
                '</section>' +
                '<section class="d14k-supplier-feed-panel d14k-supplier-feed-panel--updates">' +
                    '<div class="d14k-supplier-feed-panel__head">' +
                        '<h4>Що оновлювати</h4>' +
                        '<p>Для частих запусків залишайте тільки потрібні поля, щоб прогін був легшим і швидшим.</p>' +
                    '</div>' +
                    '<div class="d14k-supplier-feed-row__chips">' +
                        Object.keys(supplierUpdateFieldLabels).map(function (fieldKey) {
                            var checked = fieldKey === 'price' || fieldKey === 'stock' ? ' checked' : '';
                            return '<label class="d14k-supplier-feed-chip">' +
                                '<input type="checkbox" name="supplier_feeds[' + index + '][update_fields][' + fieldKey + ']" value="1"' + checked + '>' +
                                '<span>' + supplierUpdateFieldLabels[fieldKey] + '</span>' +
                            '</label>';
                        }).join('') +
                    '</div>' +
                '</section>' +
                '<section class="d14k-supplier-feed-panel d14k-supplier-feed-panel--schedule">' +
                    '<div class="d14k-supplier-feed-panel__head">' +
                        '<h4>Розклад цього feed</h4>' +
                    '</div>' +
                    '<div class="d14k-supplier-feed-row__schedule">' +
                        '<label class="d14k-supplier-feed-row__schedule-item">' +
                            '<span class="d14k-supplier-feed-row__schedule-label">Частота</span>' +
                            '<select name="supplier_feeds[' + index + '][schedule_interval]">' +
                                Object.keys(supplierScheduleIntervalOptions).map(function (key) {
                                    var selected = key === 'daily' ? ' selected' : '';
                                    return '<option value="' + key + '"' + selected + '>' + supplierScheduleIntervalOptions[key] + '</option>';
                                }).join('') +
                            '</select>' +
                        '</label>' +
                        '<label class="d14k-supplier-feed-row__schedule-item">' +
                            '<span class="d14k-supplier-feed-row__schedule-label">Час</span>' +
                            '<input type="time" name="supplier_feeds[' + index + '][schedule_time]" value="01:00">' +
                        '</label>' +
                        '<label class="d14k-supplier-feed-row__schedule-item d14k-supplier-feed-row__schedule-item--weekday d14k-supplier-feed-row__schedule-item--hidden">' +
                            '<span class="d14k-supplier-feed-row__schedule-label">День тижня</span>' +
                            '<select name="supplier_feeds[' + index + '][schedule_weekday]">' +
                                Object.keys(supplierScheduleWeekdayOptions).map(function (key) {
                                    var selected = key === 'mon' ? ' selected' : '';
                                    return '<option value="' + key + '"' + selected + '>' + supplierScheduleWeekdayOptions[key] + '</option>';
                                }).join('') +
                            '</select>' +
                        '</label>' +
                        '<label class="d14k-supplier-feed-row__schedule-item d14k-supplier-feed-row__schedule-item--monthday d14k-supplier-feed-row__schedule-item--hidden">' +
                            '<span class="d14k-supplier-feed-row__schedule-label">День місяця</span>' +
                            '<input type="number" name="supplier_feeds[' + index + '][schedule_monthday]" value="1" min="1" max="28" class="small-text">' +
                        '</label>' +
                    '</div>' +
                '</section>' +
                '<section class="d14k-supplier-feed-panel d14k-supplier-feed-panel--actions">' +
                    '<div class="d14k-supplier-feed-panel__head d14k-supplier-feed-panel__head--actions">' +
                        '<h4>Дії з feed</h4>' +
                        '<span class="d14k-supplier-feed-row__result" aria-live="polite"></span>' +
                    '</div>' +
                    '<div class="d14k-supplier-feed-row__action-grid">' +
                        '<button type="button" class="button button-secondary d14k-remove-feed-row">Видалити</button>' +
                        '<button type="button" class="button button-secondary d14k-save-feed-row">Зберегти feed</button>' +
                        '<button type="button" class="button button-secondary d14k-analyze-feed-row">Аналізувати цей feed</button>' +
                        '<button type="button" class="button button-secondary d14k-run-feed-row">Оновити цей feed у фоні</button>' +
                    '</div>' +
                '</section>' +
            '</div>' +
            '<div class="d14k-supplier-feed-row__live-status d14k-progress-card" aria-live="polite"></div>' +
            '<div class="d14k-supplier-feed-row__analysis d14k-analysis-report" aria-live="polite"></div>' +
            '<details class="d14k-supplier-feed-row__test-tools">' +
                '<summary>Тестові інструменти</summary>' +
                '<div class="d14k-supplier-feed-row__test-tools-body">' +
                    '<p>Тимчасова зона для локальної перевірки імпорту без повного прогону всього feed.</p>' +
                    '<button type="button" class="button button-secondary d14k-run-feed-row-test">Тест 2 товари</button>' +
                '</div>' +
            '</details>';
        syncSupplierScheduleFields(wrapper);
        return wrapper;
    }

    function bindSupplierFeedRows() {
        var addButton = qs('#d14k-add-supplier-feed');
        var list = qs('#d14k-supplier-feeds-list');
        if (!addButton || !list) {
            return;
        }

        qsa('.d14k-supplier-feed-row', list).forEach(syncSupplierScheduleFields);

        addButton.addEventListener('click', function () {
            var emptyState = list.querySelector('.d14k-supplier-empty-state');
            if (emptyState) {
                emptyState.remove();
            }
            list.appendChild(createSupplierFeedRow(getNextSupplierIndex()));
        });

        list.addEventListener('click', function (event) {
            var saveButton = event.target.closest('.d14k-save-feed-row');
            if (saveButton) {
                var saveRow = saveButton.closest('.d14k-supplier-feed-row');
                if (saveRow) {
                    saveSupplierFeedRow(saveRow);
                }
                return;
            }

            var analyzeButton = event.target.closest('.d14k-analyze-feed-row');
            if (analyzeButton) {
                var analyzeRow = analyzeButton.closest('.d14k-supplier-feed-row');
                if (analyzeRow) {
                    analyzeSupplierFeedRow(analyzeRow);
                }
                return;
            }

            var runButton = event.target.closest('.d14k-run-feed-row');
            if (runButton) {
                var runRow = runButton.closest('.d14k-supplier-feed-row');
                if (runRow) {
                    startSupplierRowBackgroundImport(runRow);
                }
                return;
            }

            var testButton = event.target.closest('.d14k-run-feed-row-test');
            if (testButton) {
                var testRow = testButton.closest('.d14k-supplier-feed-row');
                if (testRow) {
                    startSupplierRowTestImport(testRow);
                }
                return;
            }

            var removeButton = event.target.closest('.d14k-remove-feed-row');
            if (!removeButton) {
                return;
            }

            var row = removeButton.closest('.d14k-supplier-feed-row');
            if (row) {
                row.remove();
                ensureSupplierEmptyState(list);
            }
        });

        list.addEventListener('input', function (event) {
            var row = event.target.closest('.d14k-supplier-feed-row');
            if (!row) {
                return;
            }

            resetSupplierRowSaveButton(row);
            row.classList.add('is-dirty');
            setSupplierRowMessage(row, 'pending', 'Є незбережені зміни в цьому feed.');
            setSupplierRowAnalysis(row, '', '');
        });

        list.addEventListener('change', function (event) {
            var row = event.target.closest('.d14k-supplier-feed-row');
            if (!row) {
                return;
            }

            if (event.target.matches('select[name$="[schedule_interval]"]')) {
                syncSupplierScheduleFields(row);
            }

            resetSupplierRowSaveButton(row);
            row.classList.add('is-dirty');
            setSupplierRowMessage(row, 'pending', 'Є незбережені зміни в цьому feed.');
            setSupplierRowAnalysis(row, '', '');
        });

        list.addEventListener('click', function (event) {
            var focusWrap = event.target.closest('.d14k-supplier-feed-row__field, .d14k-supplier-feed-panel');
            if (!focusWrap) {
                return;
            }

            if (event.target.closest('input, textarea, select, button, label')) {
                return;
            }

            var input = qs('input:not([type="checkbox"]):not([type="button"]), textarea, select', focusWrap);
            if (!input) {
                return;
            }

            input.focus();
            if (typeof input.select === 'function' && (input.tagName === 'INPUT' || input.tagName === 'TEXTAREA')) {
                input.select();
            }
        });
    }

    function bindBrandModeToggle() {
        var customWrap = qs('#d14k-brand-custom-wrap');
        var attrWrap = qs('#d14k-brand-attr-wrap');
        var customRow = customWrap ? customWrap.closest('.d14k-brand-option-row') : null;
        var attrRow = attrWrap ? attrWrap.closest('.d14k-brand-option-row') : null;

        if (!customWrap || !attrWrap || !customRow || !attrRow) {
            return;
        }

        function setMode(mode) {
            customRow.classList.toggle('is-active', mode === 'custom');
            customRow.classList.toggle('is-inactive', mode !== 'custom');
            attrRow.classList.toggle('is-active', mode === 'attribute');
            attrRow.classList.toggle('is-inactive', mode !== 'attribute');
        }

        qsa('.d14k-brand-mode').forEach(function (radio) {
            if (radio.checked) {
                setMode(radio.value);
            }
            radio.addEventListener('change', function () {
                setMode(radio.value);
            });
        });
    }

    function getCategoryDescendants(checkboxes, parentId) {
        var result = [];

        checkboxes.forEach(function (checkbox) {
            if (parseInt(checkbox.dataset.parent || '-1', 10) === parentId) {
                result.push(checkbox);
                getCategoryDescendants(checkboxes, parseInt(checkbox.dataset.id || '0', 10)).forEach(function (child) {
                    result.push(child);
                });
            }
        });

        return result;
    }

    function bindSimpleCategoryCascade() {
        var checkboxes = qsa('.d14k-cat-cb');
        if (!checkboxes.length) {
            return;
        }

        checkboxes.forEach(function (checkbox) {
            checkbox.addEventListener('change', function () {
                getCategoryDescendants(checkboxes, parseInt(checkbox.dataset.id || '0', 10)).forEach(function (child) {
                    child.checked = checkbox.checked;
                });
            });
        });
    }

    function bindAttributeFilterToggles() {
        qsa('.d14k-attr-enable').forEach(function (checkbox) {
            checkbox.addEventListener('change', function () {
                var block = checkbox.closest('.d14k-attr-filter-block');
                var body = block ? qs('.d14k-attr-filter-body', block) : null;
                if (body) {
                    body.style.display = checkbox.checked ? '' : 'none';
                }
            });
        });
    }

    function getRuleFieldType(fieldValue) {
        var match = ruleFields.find(function (field) {
            return field.value === fieldValue;
        });
        return match ? match.type : 'text';
    }

    function buildRuleConditionSelect(index, fieldType, selectedCondition) {
        var conditions = ruleConditions[fieldType] || ruleConditions.text;
        var html = '<select name="custom_rules[' + index + '][condition]" class="d14k-rule-condition" style="width:auto;">';

        Object.keys(conditions).forEach(function (conditionKey) {
            html += '<option value="' + conditionKey + '"' + (conditionKey === selectedCondition ? ' selected' : '') + '>' + conditions[conditionKey] + '</option>';
        });

        return html + '</select>';
    }

    function buildRuleValueInput(index, fieldType, condition, value) {
        var hiddenStyle = noValueConditions.indexOf(condition) !== -1 ? 'display:none;' : '';

        if (fieldType === 'status') {
            var selectHtml = '<select name="custom_rules[' + index + '][value]" class="d14k-rule-value" style="width:auto;' + hiddenStyle + '">';
            statusValues.forEach(function (status) {
                selectHtml += '<option value="' + status.value + '"' + (status.value === value ? ' selected' : '') + '>' + status.label + '</option>';
            });
            return selectHtml + '</select>';
        }

        var inputType = fieldType === 'number' ? 'number' : 'text';
        return '<input type="' + inputType + '" name="custom_rules[' + index + '][value]" class="d14k-rule-value" value="' + escapeHtml(value) + '" placeholder="Значення" style="width:130px;' + hiddenStyle + '">';
    }

    function rebuildRuleConditionSelect(row, fieldValue, keepCondition) {
        var fieldType = getRuleFieldType(fieldValue);
        var conditions = ruleConditions[fieldType] || ruleConditions.text;
        var select = qs('.d14k-rule-condition', row);
        var previous = keepCondition || (select ? select.value : '');
        if (!select) {
            return;
        }

        select.innerHTML = '';
        Object.keys(conditions).forEach(function (conditionKey) {
            var option = document.createElement('option');
            option.value = conditionKey;
            option.textContent = conditions[conditionKey];
            if (conditionKey === previous) {
                option.selected = true;
            }
            select.appendChild(option);
        });
    }

    function rebuildRuleValueInput(row, fieldType, condition) {
        var valueInput = qs('.d14k-rule-value', row);
        if (!valueInput) {
            return;
        }

        var valueCell = valueInput.closest('td');
        var oldValue = valueInput.value || '';
        var fieldInput = qs('[name*="[field]"]', row);
        var match = fieldInput ? fieldInput.name.match(/\[(\d+)\]/) : null;
        var index = match ? match[1] : '0';

        valueCell.innerHTML = buildRuleValueInput(index, fieldType, condition, oldValue);
    }

    function initRuleRow(row) {
        var fieldSelect = qs('.d14k-rule-field', row);
        var conditionSelect = qs('.d14k-rule-condition', row);
        var metaKeyInput = qs('.d14k-rule-meta-key', row);
        var removeButton = qs('.d14k-remove-rule', row);

        if (fieldSelect) {
            fieldSelect.addEventListener('change', function () {
                var fieldType = getRuleFieldType(fieldSelect.value);
                if (metaKeyInput) {
                    metaKeyInput.style.display = fieldType === 'meta' ? '' : 'none';
                }
                rebuildRuleConditionSelect(row, fieldSelect.value, null);
                rebuildRuleValueInput(row, fieldType, conditionSelect ? conditionSelect.value : 'contains');
            });
        }

        if (conditionSelect) {
            conditionSelect.addEventListener('change', function () {
                rebuildRuleValueInput(row, getRuleFieldType(fieldSelect ? fieldSelect.value : 'title'), conditionSelect.value);
            });
        }

        if (removeButton) {
            removeButton.addEventListener('click', function () {
                row.remove();
            });
        }
    }

    function createRuleRow(index, saved) {
        saved = saved || {};

        var fieldValue = saved.field || 'title';
        var metaKey = saved.meta_key || '';
        var condition = saved.condition || 'contains';
        var value = saved.value || '';
        var action = saved.action || 'exclude';
        var fieldType = getRuleFieldType(fieldValue);
        var fieldSelectHtml = '<select name="custom_rules[' + index + '][field]" class="d14k-rule-field" style="width:auto;">';

        ruleFields.forEach(function (field) {
            fieldSelectHtml += '<option value="' + field.value + '"' + (field.value === fieldValue ? ' selected' : '') + '>' + field.label + '</option>';
        });
        fieldSelectHtml += '</select>';

        var row = document.createElement('tr');
        row.className = 'd14k-rule-row';
        row.innerHTML =
            '<td>' + fieldSelectHtml + '</td>' +
            '<td><input type="text" name="custom_rules[' + index + '][meta_key]" class="d14k-rule-meta-key" value="' + escapeHtml(metaKey) + '" placeholder="напр. _weight" style="width:110px;' + (fieldType !== 'meta' ? 'display:none;' : '') + '"></td>' +
            '<td>' + buildRuleConditionSelect(index, fieldType, condition) + '</td>' +
            '<td>' + buildRuleValueInput(index, fieldType, condition, value) + '</td>' +
            '<td><select name="custom_rules[' + index + '][action]" style="width:auto;"><option value="exclude"' + (action === 'exclude' ? ' selected' : '') + '>Виключити</option><option value="include"' + (action === 'include' ? ' selected' : '') + '>Включити тільки</option></select></td>' +
            '<td><button type="button" class="button d14k-remove-rule">✕</button></td>';

        initRuleRow(row);
        return row;
    }

    function bindAdvancedRules() {
        var addButton = qs('#d14k-add-rule');
        var rulesBody = qs('#d14k-rules-body');
        if (!addButton || !rulesBody) {
            return;
        }

        var ruleIndex = qsa('.d14k-rule-row', rulesBody).length;
        qsa('.d14k-rule-row', rulesBody).forEach(initRuleRow);

        addButton.addEventListener('click', function () {
            rulesBody.appendChild(createRuleRow(ruleIndex++));
        });
    }

    function getGroupChildren(group) {
        return qsa('.d14k-cat-child', group);
    }

    function updateCategoryCounter(tree) {
        var counter = qs('#d14kExCount');
        if (!counter || !tree) {
            return;
        }

        var checked = qsa('.d14k-cat-parent:checked, .d14k-cat-child:checked', tree).length;
        counter.textContent = String(checked);
    }

    function updateCategoryBadge(group) {
        var badge = qs('.d14k-cg-badge', group);
        if (!badge) {
            return;
        }

        var parentChecked = !!qs('.d14k-cat-parent:checked', group);
        var childCount = qsa('.d14k-cat-child:checked', group).length;
        var total = (parentChecked ? 1 : 0) + childCount;

        badge.textContent = String(total);
        badge.style.display = total > 0 ? 'inline-block' : 'none';
        group.classList.toggle('d14k-has-ex', total > 0);
    }

    function syncParentState(group) {
        var parent = qs('.d14k-cat-parent', group);
        var children = getGroupChildren(group);
        if (!parent || !children.length) {
            updateCategoryBadge(group);
            return;
        }

        var checkedCount = children.filter(function (child) {
            return child.checked;
        }).length;

        parent.indeterminate = checkedCount > 0 && checkedCount < children.length;
        updateCategoryBadge(group);
    }

    function bindCategoryAccordion() {
        var tree = qs('#d14kCatTree');
        if (!tree) {
            return;
        }

        qsa('.d14k-cg', tree).forEach(function (group) {
            updateCategoryBadge(group);
            syncParentState(group);
        });
        updateCategoryCounter(tree);

        tree.addEventListener('click', function (event) {
            var toggle = event.target.closest('.d14k-cg-toggle');
            if (!toggle || !tree.contains(toggle)) {
                return;
            }
            if (event.target.closest('input[type="checkbox"]')) {
                return;
            }

            toggle.parentElement.classList.toggle('d14k-open');
        });

        tree.addEventListener('change', function (event) {
            var checkbox = event.target.closest('.d14k-cat-parent, .d14k-cat-child');
            if (!checkbox) {
                return;
            }

            var group = checkbox.closest('.d14k-cg');
            if (!group) {
                return;
            }

            if (checkbox.classList.contains('d14k-cat-parent')) {
                getGroupChildren(group).forEach(function (child) {
                    child.checked = checkbox.checked;
                });
                checkbox.indeterminate = false;
            } else {
                syncParentState(group);
            }

            updateCategoryBadge(group);
            updateCategoryCounter(tree);
        });

        var searchInput = qs('#d14kCatSearch');
        var emptyState = qs('#d14kCatEmpty');
        if (searchInput && emptyState) {
            searchInput.addEventListener('input', function () {
                var query = searchInput.value.trim().toLowerCase();
                var visibleCount = 0;

                qsa('.d14k-cg', tree).forEach(function (group) {
                    var haystack = (group.getAttribute('data-search') || '').toLowerCase();
                    var visible = !query || haystack.indexOf(query) !== -1;
                    group.style.display = visible ? '' : 'none';
                    if (visible) {
                        visibleCount += 1;
                    }
                });

                emptyState.style.display = visibleCount ? 'none' : 'block';
            });
        }

        var selectAllButton = qs('.d14k-cat-select-all');
        if (selectAllButton) {
            selectAllButton.addEventListener('click', function () {
                qsa('.d14k-cat-parent, .d14k-cat-child', tree).forEach(function (checkbox) {
                    checkbox.checked = true;
                    checkbox.indeterminate = false;
                });
                qsa('.d14k-cg', tree).forEach(function (group) {
                    updateCategoryBadge(group);
                });
                updateCategoryCounter(tree);
            });
        }

        var clearAllButton = qs('.d14k-cat-clear-all');
        if (clearAllButton) {
            clearAllButton.addEventListener('click', function () {
                qsa('.d14k-cat-parent, .d14k-cat-child', tree).forEach(function (checkbox) {
                    checkbox.checked = false;
                    checkbox.indeterminate = false;
                });
                qsa('.d14k-cg', tree).forEach(function (group) {
                    updateCategoryBadge(group);
                });
                updateCategoryCounter(tree);
            });
        }
    }

    function autoDismissNotices() {
        qsa('.d14k-notice').forEach(function (notice) {
            setTimeout(function () {
                notice.style.transition = 'opacity .3s ease';
                notice.style.opacity = '0';
                setTimeout(function () {
                    notice.remove();
                }, 300);
            }, 5000);
        });
    }

    autoDismissNotices();
    bindCopyButtons();
    bindGenerateForms();
    bindChannelToggles();
    bindChannelGeneration();
    bindPromActions();
    bindCodeToggles();
    bindSupplierFeedRows();
    bindBrandModeToggle();
    bindSimpleCategoryCascade();
    bindAttributeFilterToggles();
    bindAdvancedRules();
    bindCategoryAccordion();
})();
