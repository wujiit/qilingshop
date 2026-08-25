<?php
/**
 * Gutenberg Blocks Integration
 * 
 * @package QilingShop
 */

if (!defined('ABSPATH')) exit;

class QLS_Blocks {

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', [$this, 'register_blocks']);
        add_action('block_categories_all', [$this, 'register_category'], 10, 2);
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_editor_assets']);
    }

    /**
     * Register Block Category
     */
    public function register_category($categories, $post) {
        return array_merge(
            $categories,
            [
                [
                    'slug'  => 'qiling-shop',
                    'title' => __('启灵商城', 'qilingshop'),
                    'icon'  => 'cart',
                ],
            ]
        );
    }

    /**
     * Register Blocks
     */
    public function register_blocks() {
        // Product List Block
        register_block_type('qls-shop/product-list', [
            'api_version' => 2,
            'editor_script' => 'qls-shop-blocks',
            'render_callback' => [$this, 'render_product_list'],
            'attributes' => [
                'title' => [
                    'type' => 'string',
                    'default' => __('热门商品', 'qilingshop')
                ],
                'source' => [
                    'type' => 'string',
                    'default' => 'latest'
                ],
                'limit' => [
                    'type' => 'number',
                    'default' => 8
                ],
                'columns' => [
                    'type' => 'number',
                    'default' => 4
                ]
            ]
        ]);
    }

    /**
     * Enqueue Editor Assets
     */
    public function enqueue_editor_assets() {
        $asset_file = include(QILINGSHOP_PATH . 'static/shop/js/blocks/index.asset.php');

        wp_enqueue_script(
            'qls-shop-blocks',
            QILINGSHOP_URL . 'static/shop/js/blocks/index.js',
            $asset_file['dependencies'],
            $asset_file['version']
        );

        wp_localize_script(
            'qls-shop-blocks',
            'qlsShopBlocks',
            [
                'i18n' => [
                    'productList'       => __('商品列表', 'qilingshop'),
                    'hotProducts'       => __('热门商品', 'qilingshop'),
                    'listSettings'      => __('列表设置', 'qilingshop'),
                    'title'             => __('标题', 'qilingshop'),
                    'productSource'     => __('商品来源', 'qilingshop'),
                    'latestProducts'    => __('最新上架', 'qilingshop'),
                    'salesRanking'      => __('销量排行', 'qilingshop'),
                    'hotRecommendation' => __('热门推荐', 'qilingshop'),
                    'pointsProducts'    => __('支持积分价商品', 'qilingshop'),
                    'displayCount'      => __('显示数量', 'qilingshop'),
                    'columns'           => __('列数', 'qilingshop'),
                ],
            ]
        );
    }

    /**
     * Render Product List (Server Side)
     */
    public function render_product_list($attributes) {
        $source = $attributes['source'] ?? 'latest';
        $limit = intval($attributes['limit'] ?? 8);
        $title = $attributes['title'] ?? '';

        // Query Products
        $args = [
            'status' => 1,
            'limit'  => $limit
        ];
        
        if ($source === 'hot') {
            $args['is_hot'] = 1;
        } elseif ($source === 'sales') {
            $args['orderby'] = 'sales_count';
        } elseif ($source === 'points') {
            $args['points_payable'] = 1;
            $args['orderby'] = 'points_price';
            $args['order'] = 'ASC';
        }

        $products = qls_product()->get_list($args);

        ob_start();
        ?>
        <div class="qls-block-product-list">
            <?php if ($title): ?>
                <h3 class="qls-block-title"><?php echo esc_html($title); ?></h3>
            <?php endif; ?>
            
            <div class="qls-product-grid" style="display: grid; grid-template-columns: repeat(<?php echo intval($attributes['columns'] ?? 4); ?>, 1fr); gap: 20px;">
                <?php if (!empty($products)): foreach ($products as $product): 
                    $price = qls_product()->get_price_range($product->id);
                ?>
                    <div class="qls-product-card">
                        <div class="qls-product-thumb">
                            <img src="<?php echo esc_url($product->main_image['url'] ?? ''); ?>" alt="<?php echo esc_attr($product->title); ?>" style="width:100%; aspect-ratio:1; object-fit:cover;">
                        </div>
                        <div class="qls-product-info" style="padding:10px;">
                            <h4 style="margin:0 0 5px; font-size:14px;"><?php echo esc_html($product->title); ?></h4>
                            <div class="price" style="color:#f5222d; font-weight:bold;">
                                <?php echo (!is_user_logged_in() && get_option('qls_shop_price_login_required', false)) ? esc_html__('登录后查看价格', 'qilingshop') : '¥' . esc_html($price['min']); ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; else: ?>
                    <p><?php esc_html_e('暂无商品', 'qilingshop'); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
