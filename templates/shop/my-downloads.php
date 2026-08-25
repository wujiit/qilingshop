<?php
/**
 * 我的下载（虚拟商品）。
 */
if (!defined('ABSPATH')) exit;

$orders = isset($orders) && is_array($orders) ? $orders : [];
$total = isset($total) ? max(0, (int) $total) : 0;
$paged = isset($paged) ? max(1, (int) $paged) : 1;
$limit = isset($limit) ? max(1, (int) $limit) : 10;
$invoices = isset($invoices) && is_array($invoices) ? $invoices : [];
$orders_page_url = isset($orders_page_url) ? (string) $orders_page_url : '';
$invoice_available = function_exists('qls_invoice') && class_exists('QLS_Invoice');
$invoice_enabled = get_option('qls_shop_invoice_enabled', true) && $invoice_available;

qls_shop_public()->get_shop_header(__('我的下载', 'qilingshop'));
?>

<div class="qls-account-page qls-my-downloads-page">
    <?php include QILINGSHOP_PATH . 'templates/shop/partials/account-header.php'; ?>

    <div class="qls-account-body">
        <div class="qls-container">
            <div class="qls-account-layout">
                <?php include QILINGSHOP_PATH . 'templates/shop/partials/account-sidebar.php'; ?>

                <main class="qls-account-main">
                    <div class="qls-section-header">
                        <h3><?php _e('我的下载', 'qilingshop'); ?></h3>
                        <span class="qls-download-summary">
                            <?php
                            echo esc_html(sprintf(
                                __('共 %d 个可下载订单', 'qilingshop'),
                                (int) $total
                            ));
                            ?>
                        </span>
                    </div>

                    <?php if (empty($orders)): ?>
                    <div class="qls-orders-empty">
                        <span class="dashicons dashicons-download"></span>
                        <p><?php _e('暂无可下载的虚拟商品订单', 'qilingshop'); ?></p>
                        <a href="<?php echo esc_url(qls_shop_public()->get_shop_url()); ?>" class="qls-btn qls-btn-primary">
                            <?php _e('去逛逛', 'qilingshop'); ?>
                        </a>
                    </div>
                    <?php else: ?>
                    <div class="qls-order-list qls-download-list">
                        <?php foreach ($orders as $order): ?>
                        <?php
                        $invoice = $invoice_available ? ($invoices[$order->id] ?? null) : null;
                        $invoice_status_text = $invoice ? qls_invoice()->get_status_text($invoice) : '';
                        $invoice_status_class = $invoice ? qls_invoice()->get_status_badge_class($invoice) : '';
                        $invoice_type_text = $invoice ? qls_invoice()->get_invoice_type_text($invoice->invoice_type) : '';
                        $invoice_title_type_text = $invoice ? qls_invoice()->get_title_type_text($invoice->title_type) : '';
                        $invoice_url = $invoice ? trim((string) ($invoice->invoice_url ?? '')) : '';
                        ?>
                        <div class="qls-order-card qls-download-card">
                            <div class="order-header">
                                <span class="order-no"><?php _e('订单号:', 'qilingshop'); ?> <?php echo esc_html($order->order_no); ?></span>
                                <span class="order-date"><?php echo esc_html(date('Y-m-d H:i', strtotime($order->created_at))); ?></span>
                                <span class="order-status status-<?php echo esc_attr($order->status); ?>">
                                    <?php echo esc_html(qls_shop_order()->get_status_text((int) $order->status)); ?>
                                </span>
                            </div>

                            <?php if ($invoice || ($invoice_enabled && $orders_page_url !== '')): ?>
                            <div class="qls-download-meta-row">
                                <div class="qls-download-meta-main">
                                    <span class="qls-download-meta-label"><?php _e('发票', 'qilingshop'); ?></span>
                                    <?php if ($invoice): ?>
                                    <span class="qls-download-invoice-chip <?php echo esc_attr($invoice_status_class); ?>">
                                        <?php echo esc_html($invoice_status_text); ?>
                                    </span>
                                    <span class="qls-download-meta-text">
                                        <?php echo esc_html(trim($invoice_type_text . ' · ' . $invoice_title_type_text . '抬头')); ?>
                                    </span>
                                    <?php else: ?>
                                    <span class="qls-download-invoice-chip is-available"><?php _e('支持申请发票', 'qilingshop'); ?></span>
                                    <span class="qls-download-meta-text"><?php _e('如需发票，可前往我的订单提交申请。', 'qilingshop'); ?></span>
                                    <?php endif; ?>
                                </div>

                                <div class="qls-download-meta-actions">
                                    <?php if ($invoice_url !== ''): ?>
                                    <a href="<?php echo esc_url($invoice_url); ?>" class="qls-download-order-link" target="_blank" rel="noopener"><?php _e('查看发票', 'qilingshop'); ?></a>
                                    <?php elseif ($orders_page_url !== ''): ?>
                                    <a href="<?php echo esc_url($orders_page_url); ?>" class="qls-download-order-link"><?php _e('去我的订单', 'qilingshop'); ?></a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <div class="qls-download-items">
                                <?php foreach ((array) $order->items as $index => $item): ?>
                                <?php
                                $virtual = is_array($item->virtual_content ?? null) ? $item->virtual_content : [];
                                $virtual_type = isset($virtual['type']) ? sanitize_key((string) $virtual['type']) : '';
                                if ($virtual_type === '' && !empty($item->product_id) && function_exists('qls_product') && qls_product()->is_virtual((int) $item->product_id)) {
                                    $virtual_type = 'pending';
                                }
                                if ($virtual_type === '') {
                                    continue;
                                }

                                $copy_target_id = 'qls-download-copy-' . (int) $order->id . '-' . (int) $index;
                                $copy_text = '';

                                if ($virtual_type === 'download') {
                                    $download_url = isset($virtual['download_url']) ? esc_url_raw((string) $virtual['download_url']) : '';
                                    $download_code = isset($virtual['download_code']) ? sanitize_text_field((string) $virtual['download_code']) : '';
                                    $copy_text = trim(
                                        sprintf(__('下载链接：%s', 'qilingshop'), $download_url) . "\n" .
                                        sprintf(__('提取码：%s', 'qilingshop'), $download_code)
                                    );
                                } elseif ($virtual_type === 'card') {
                                    $cards = [];
                                    if (!empty($virtual['cards']) && is_array($virtual['cards'])) {
                                        foreach ($virtual['cards'] as $card) {
                                            $card_no = sanitize_text_field((string) ($card['card_no'] ?? ''));
                                            $card_secret = sanitize_text_field((string) ($card['card_secret'] ?? ''));
                                            if ($card_no === '' && $card_secret === '') {
                                                continue;
                                            }
                                            if ($card_secret !== '') {
                                                $cards[] = $card_no . '----' . $card_secret;
                                            } else {
                                                $cards[] = $card_no;
                                            }
                                        }
                                    }
                                    $copy_text = !empty($cards) ? implode("\n", $cards) : sanitize_text_field((string) ($virtual['error'] ?? __('暂无卡密信息', 'qilingshop')));
                                } elseif ($virtual_type === 'custom') {
                                    $copy_text = isset($virtual['content']) ? wp_strip_all_tags((string) $virtual['content']) : '';
                                } elseif ($virtual_type === 'pending') {
                                    $copy_text = '';
                                } else {
                                    $copy_text = wp_json_encode($virtual, JSON_UNESCAPED_UNICODE);
                                }
                                $can_copy = ($copy_text !== '');
                                $type_map = [
                                    'download' => __('下载链接', 'qilingshop'),
                                    'card' => __('卡密内容', 'qilingshop'),
                                    'custom' => __('图文内容', 'qilingshop'),
                                    'pending' => __('待发放', 'qilingshop'),
                                ];
                                $type_label = isset($type_map[$virtual_type]) ? $type_map[$virtual_type] : __('虚拟内容', 'qilingshop');
                                ?>
                                <section class="qls-download-item">
                                    <div class="qls-download-item__head">
                                        <div class="qls-download-item__title">
                                            <strong><?php echo esc_html($item->product_title); ?></strong>
                                            <span class="qls-download-type qls-download-type-<?php echo esc_attr($virtual_type); ?>"><?php echo esc_html($type_label); ?></span>
                                        </div>
                                        <button
                                            type="button"
                                            class="qls-btn qls-btn-sm qls-copy-btn"
                                            data-copy-target="<?php echo esc_attr($copy_target_id); ?>"
                                            data-default-label="<?php echo esc_attr(__('复制内容', 'qilingshop')); ?>"
                                            <?php echo $can_copy ? '' : 'disabled'; ?>
                                        ><?php echo esc_html($can_copy ? __('复制内容', 'qilingshop') : __('暂无可复制内容', 'qilingshop')); ?></button>
                                    </div>

                                    <textarea id="<?php echo esc_attr($copy_target_id); ?>" class="qls-copy-source" readonly><?php echo esc_textarea($copy_text); ?></textarea>

                                    <div class="qls-download-item__body">
                                        <?php if ($virtual_type === 'download'): ?>
                                            <p><strong><?php _e('下载链接：', 'qilingshop'); ?></strong>
                                                <?php if (!empty($download_url)): ?>
                                                    <a href="<?php echo esc_url($download_url); ?>" target="_blank" rel="noopener"><?php echo esc_html($download_url); ?></a>
                                                <?php else: ?>
                                                    <?php _e('未配置', 'qilingshop'); ?>
                                                <?php endif; ?>
                                            </p>
                                            <p><strong><?php _e('提取码：', 'qilingshop'); ?></strong><?php echo !empty($download_code) ? esc_html($download_code) : '-'; ?></p>
                                        <?php elseif ($virtual_type === 'card'): ?>
                                            <?php if (!empty($virtual['cards']) && is_array($virtual['cards'])): ?>
                                                <pre class="qls-download-pre"><?php
                                                    $card_lines = [];
                                                    foreach ($virtual['cards'] as $card) {
                                                        $card_no = sanitize_text_field((string) ($card['card_no'] ?? ''));
                                                        $card_secret = sanitize_text_field((string) ($card['card_secret'] ?? ''));
                                                        if ($card_no === '' && $card_secret === '') {
                                                            continue;
                                                        }
                                                        $card_lines[] = $card_secret !== '' ? ($card_no . '----' . $card_secret) : $card_no;
                                                    }
                                                    echo esc_html(!empty($card_lines) ? implode("\n", $card_lines) : __('暂无卡密信息', 'qilingshop'));
                                                ?></pre>
                                            <?php else: ?>
                                                <p><?php echo esc_html((string) ($virtual['error'] ?? __('暂无卡密信息', 'qilingshop'))); ?></p>
                                            <?php endif; ?>
                                        <?php elseif ($virtual_type === 'custom'): ?>
                                            <div class="qls-download-rich"><?php echo wp_kses_post((string) ($virtual['content'] ?? '')); ?></div>
                                        <?php elseif ($virtual_type === 'pending'): ?>
                                            <p><?php _e('虚拟内容发放中，请稍后刷新查看。', 'qilingshop'); ?></p>
                                        <?php else: ?>
                                            <p><?php _e('该虚拟内容暂不支持结构化展示，可直接复制内容。', 'qilingshop'); ?></p>
                                        <?php endif; ?>
                                    </div>
                                </section>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <?php
                    $total_pages = (int) ceil(max(1, $total) / $limit);
                    if ($total_pages > 1):
                    ?>
                    <div class="qls-pagination">
                        <?php
                        echo paginate_links([
                            'base'      => add_query_arg('paged', '%#%'),
                            'format'    => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total'     => $total_pages,
                            'current'   => $paged,
                        ]);
                        ?>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </main>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    function copyText(text) {
        if (!text) return Promise.reject(new Error('empty'));
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise(function(resolve, reject) {
            var area = document.createElement('textarea');
            area.value = text;
            area.setAttribute('readonly', 'readonly');
            area.style.position = 'fixed';
            area.style.left = '-9999px';
            document.body.appendChild(area);
            area.select();
            try {
                var ok = document.execCommand('copy');
                document.body.removeChild(area);
                if (ok) {
                    resolve();
                } else {
                    reject(new Error('copy_failed'));
                }
            } catch (e) {
                document.body.removeChild(area);
                reject(e);
            }
        });
    }

    document.addEventListener('click', function(e) {
        var button = e.target.closest('.qls-copy-btn');
        if (!button) return;

        var targetId = button.getAttribute('data-copy-target');
        var source = targetId ? document.getElementById(targetId) : null;
        var text = source ? source.value : '';

        copyText(text).then(function() {
            var original = button.getAttribute('data-default-label') || button.textContent;
            button.textContent = '<?php echo esc_js(__('已复制', 'qilingshop')); ?>';
            button.classList.add('is-copied');
            setTimeout(function() {
                button.textContent = original;
                button.classList.remove('is-copied');
            }, 1200);
        }).catch(function() {
            var original = button.getAttribute('data-default-label') || button.textContent;
            button.textContent = '<?php echo esc_js(__('复制失败', 'qilingshop')); ?>';
            button.classList.add('is-error');
            setTimeout(function() {
                button.textContent = original;
                button.classList.remove('is-error');
            }, 1200);
        });
    });
})();
</script>

<?php qls_shop_public()->get_shop_footer(); ?>
