<?php

use yii\helpers\Url;

$this->title = 'Поиск адресов';
?>
    <div class="site-index">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Поиск адреса (ФИАС / КЛАДР)</h3>
                    </div>
                    <div class="card-body">
                        <div class="mb-3 position-relative">
                            <label for="address-input" class="form-label">Начните вводить адрес</label>
                            <input type="text" id="address-input" class="form-control" autocomplete="off"
                                   placeholder="Например, Пермский край, г Пермь, ул...">
                            <div id="loading-spinner" class="position-absolute" style="display: none;">
                                <div class="spinner-border spinner-border-sm text-primary" role="status">
                                    <span class="visually-hidden">Загрузка...</span>
                                </div>
                            </div>
                            <div id="address-error" class="invalid-feedback" style="display: none;"></div>
                            <div id="suggestions" class="list-group mt-2" style="display: none;"></div>
                        </div>
                        <div class="mt-4">
                            <label class="form-label">Выбранный адрес:</label>
                            <div id="selected-address" class="alert alert-secondary" role="alert">
                                <span class="text-muted">— не выбран —</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row justify-content-center mt-4">
                    <div class="col-md-8">
                        <div class="card bg-light">
                            <div class="card-body text-center">
                                <div id="stats-placeholder">
                                    <span class="spinner-border spinner-border-sm text-secondary"></span> Загрузка статистики...
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php
$this->registerJsFile('https://code.jquery.com/jquery-3.7.1.min.js', ['position' => \yii\web\View::POS_HEAD]);

$fastUrl = Url::to(['/address/autocomplete']);
$slowUrl = Url::to(['/address/autocomplete-slow']);

$script = <<<JS
    let fastRequest = null;
    let slowRequest = null;
    let debounceTimer;
    let currentFastItems = [];

    const \$input = $('#address-input');
    const \$suggestions = $('#suggestions');
    const \$selected = $('#selected-address');
    const \$errorDiv = $('#address-error');
    const \$spinner = $('#loading-spinner');
    
    function showSpinner() { \$spinner.show(); }
    function hideSpinner() { \$spinner.hide(); }

    function validateInput(value) {
        const trimmed = value.trim();
        if (trimmed.length === 0) {
            showError('Пожалуйста, введите адрес.');
            return false;
        }
        if (trimmed.length < 2) {
            showError('Введите минимум 2 символа.');
            return false;
        }
        if (trimmed.length > 200) {
            showError('Адрес не может быть длиннее 200 символов.');
            return false;
        }
        clearError();
        return true;
    }

    function showError(msg) {
        \$input.addClass('is-invalid');
        \$errorDiv.text(msg).show();
    }
    function clearError() {
        \$input.removeClass('is-invalid');
        \$errorDiv.hide();
    }

    function mergeUnique(fast, slow) {
        const map = new Map();
        fast.forEach(item => map.set(item.full_address, item));
        slow.forEach(item => {
            if (!map.has(item.full_address)) map.set(item.full_address, item);
        });
        return Array.from(map.values());
    }

    function renderSuggestions(items, isSlowIncluded = false) {
        \$suggestions.empty();
        if (!items.length) {
            \$suggestions.hide();
            return;
        }
        for (let item of items) {
            const \$btn = $('<button type="button" class="list-group-item list-group-item-action"></button>')
                .text(item.full_address)
                .on('click', function() {
                    selectAddress(item.full_address);
                });
            \$suggestions.append(\$btn);
        }
        if (!isSlowIncluded && items.length > 0) {
            // Показываем индикатор, что ещё загружаются похожие адреса
            \$suggestions.append('<div class="list-group-item text-muted small" id="slow-loader">Загружаем похожие адреса...</div>');
        }
        \$suggestions.show();
    }

    function selectAddress(fullAddress) {
        \$selected.html('<span>' + escapeHtml(fullAddress) + '</span>');
        \$input.val(fullAddress);
        \$suggestions.empty().hide();
        clearError();
        if (slowRequest) slowRequest.abort();
        if (fastRequest) fastRequest.abort();
        hideSpinner();
    }

    function escapeHtml(str) {
        return str.replace(/[&<>]/g, function(m) {
            if (m === '&') return '&amp;';
            if (m === '<') return '&lt;';
            if (m === '>') return '&gt;';
            return m;
        });
    }

    \$input.on('keyup', function() {
        clearTimeout(debounceTimer);
        const raw = \$(this).val();
        const isValid = validateInput(raw);
        if (!isValid) {
            \$suggestions.empty().hide();
            if (fastRequest) fastRequest.abort();
            if (slowRequest) slowRequest.abort();
            hideSpinner();
            return;
        }
        const query = raw.trim();
        if (query.length < 2) {
            \$suggestions.empty().hide();
            return;
        }

        debounceTimer = setTimeout(() => {
            // Отменяем предыдущие запросы
            if (fastRequest) fastRequest.abort();
            if (slowRequest) slowRequest.abort();

            showSpinner();

            // 1. Быстрый префиксный запрос
            fastRequest = $.ajax({
                url: '$fastUrl',
                data: { q: query, limit: 10 },
                dataType: 'json',
                success: function(fastItems) {
                    currentFastItems = fastItems;
                    renderSuggestions(fastItems, false);
                    hideSpinner();

                    // 2. Медленный similarity-запрос
                    slowRequest = $.ajax({
                        url: '$slowUrl',
                        data: { q: query, limit: 10 },
                        dataType: 'json',
                        success: function(slowItems) {
                            const merged = mergeUnique(currentFastItems, slowItems);
                            renderSuggestions(merged, true);
                            // Удаляем индикатор "Загружаем похожие адреса" если был
                            $('#slow-loader').remove();
                        },
                        error: function() {
                            // Если медленный запрос упал, просто оставляем быстрые результаты
                            $('#slow-loader').remove();
                        }
                    });
                },
                error: function() {
                    hideSpinner();
                    showError('Не удалось загрузить подсказки');
                }
            });
        }, 300);
    });

    $(document).on('click', function(e) {
        if (!\$(e.target).closest('#address-input, #suggestions').length) {
            \$suggestions.hide();
        }
    });
    $(document).ready(function() {
    $.ajax({
        url: '/address/stats',
        dataType: 'json',
        success: function(data) {
            $('#stats-placeholder').html(`
                <i class="bi bi-database"></i> 
                Всего адресов в базе: <strong>\${data.total.toLocaleString()}</strong>
                <span class="text-muted small ms-2">(обновлено \${data.updated_at})</span>
            `);
        },
        error: function() {
            $('#stats-placeholder').html('<span class="text-danger">Не удалось загрузить статистику</span>');
        }
    });
});
JS;
$this->registerJs($script, \yii\web\View::POS_READY);