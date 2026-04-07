<?php

/** @var yii\web\View $this */

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
                        <div class="mb-3">
                            <label for="address-input" class="form-label">Начните вводить адрес</label>
                            <input type="text" id="address-input" class="form-control" autocomplete="off" placeholder="Например, Пермский край, г Пермь, ул...">
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
            </div>
        </div>
    </div>

<?php
// Подключаем jQuery с CDN (если не загружен в AppAsset)
$this->registerJsFile('https://code.jquery.com/jquery-3.7.1.min.js', ['position' => \yii\web\View::POS_HEAD]);

$autocompleteUrl = Url::to(['/address/autocomplete']);
$script = <<<JS
    let currentRequest = null;
    const \$input = $('#address-input');
    const \$suggestions = $('#suggestions');
    const \$selected = $('#selected-address');

    let debounceTimer;
    \$input.on('keyup', function() {
        clearTimeout(debounceTimer);
        const query = \$(this).val().trim();
        if (query.length === 0) {
            \$suggestions.empty().hide();
            return;
        }
        debounceTimer = setTimeout(() => {
            if (currentRequest && currentRequest.abort) {
                currentRequest.abort();
            }
            currentRequest = $.ajax({
                url: '$autocompleteUrl',
                data: { q: query, limit: 10 },
                dataType: 'json',
                success: function(data) {
                    renderSuggestions(data);
                },
                error: function(xhr) {
                    if (xhr.statusText !== 'abort') {
                        console.error('Ошибка загрузки подсказок');
                    }
                },
                complete: function() {
                    currentRequest = null;
                }
            });
        }, 300);
    });

    function renderSuggestions(items) {
        \$suggestions.empty();
        if (!items.length) {
            \$suggestions.hide();
            return;
        }
        for (let i = 0; i < items.length; i++) {
            const item = items[i];
            const \$btn = $('<button type="button" class="list-group-item list-group-item-action"></button>')
                .text(item.full_address)
                .on('click', function() {
                    selectAddress(item.full_address);
                });
            \$suggestions.append(\$btn);
        }
        \$suggestions.show();
    }

    function selectAddress(fullAddress) {
        \$selected.html('<span>' + escapeHtml(fullAddress) + '</span>');
        \$input.val(fullAddress);
        \$suggestions.empty().hide();
    }

    function escapeHtml(str) {
        return str.replace(/[&<>]/g, function(m) {
            if (m === '&') return '&amp;';
            if (m === '<') return '&lt;';
            if (m === '>') return '&gt;';
            return m;
        });
    }

    $(document).on('click', function(e) {
        if (!\$(e.target).closest('#address-input, #suggestions').length) {
            \$suggestions.hide();
        }
    });
JS;
$this->registerJs($script, \yii\web\View::POS_READY);