<?php
/**
 * 运费计算类
 * 
 * 计算订单运费
 *
 * @package QilingShop
 * @since   2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QLS_Shipping {

    /**
     * 单例实例
     */
    private static $instance = null;

    /**
     * 数据库实例
     */
    private $db;

    /**
     * 运费类型常量
     */
    const TYPE_FREE = 0;       // 包邮
    const TYPE_FIXED = 1;      // 固定运费
    const TYPE_WEIGHT = 2;     // 按重量

    /**
     * 获取单例实例
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 构造函数
     */
    private function __construct() {
        $this->db = QLS_Shop_Database::instance();
    }

    /**
     * 获取所有运费规则
     *
     * @param bool $active_only
     * @return array
     */
    public function get_rules($active_only = true) {
        $where = [];
        if ($active_only) {
            $where['status'] = 1;
        }

        return $this->db->get_results('shipping_rules', [
            'where'   => $where,
            'orderby' => 'is_default',
            'order'   => 'DESC',
        ]);
    }

    /**
     * 获取单个运费规则
     *
     * @param int $id
     * @return object|null
     */
    public function get_rule($id) {
        $rule = $this->db->get_by_id('shipping_rules', $id);
        return $rule && (int) ($rule->status ?? 0) === 1 ? $rule : null;
    }

    /**
     * 获取默认运费规则
     *
     * @return object|null
     */
    public function get_default_rule() {
        return $this->db->get_row('shipping_rules', [
            'is_default' => 1,
            'status'     => 1,
        ]);
    }

    /**
     * 保存运费规则
     *
     * @param array $data
     * @return int|false
     */
    public function save_rule($data) {
        $defaults = [
            'name'           => '',
            'type'           => self::TYPE_FIXED,
            'base_fee'       => 0,
            'free_threshold' => null,
            'first_weight'   => null,
            'weight_step'    => null,
            'step_fee'       => null,
            'is_default'     => 0,
            'status'         => 1,
        ];

        $data = wp_parse_args($data, $defaults);

        // 如果设为默认，取消其他默认
        if ($data['is_default']) {
            $this->db->update('shipping_rules', ['is_default' => 0], ['is_default' => 1]);
        }

        if (isset($data['id']) && $data['id'] > 0) {
            $id = $data['id'];
            unset($data['id']);
            $this->db->update('shipping_rules', $data, ['id' => $id]);
            return $id;
        }

        unset($data['id']);
        return $this->db->insert('shipping_rules', $data);
    }

    /**
     * 删除运费规则
     *
     * @param int $id
     * @return bool
     */
    public function delete_rule($id) {
        return $this->db->delete('shipping_rules', ['id' => $id]) !== false;
    }

    /**
     * 计算运费
     *
     * @param array $cart_items   购物车商品
     * @param array $address_data 地址数据
     * @return float
     */
    public function calculate($cart_items, $address_data = []) {
        if (empty($cart_items)) {
            return 0;
        }

        $default_rule = $this->get_default_rule();
        $groups = [];

        foreach ($cart_items as $item) {
            if (isset($item->is_invalid) && $item->is_invalid) {
                continue;
            }

            $product = $item->product ?? null;
            if ($product && function_exists('qls_product') && qls_product()->is_virtual($product)) {
                continue;
            }

            $rule_id = (int) ($product->shipping_rule_id ?? 0);
            $rule = $rule_id > 0 ? $this->get_rule($rule_id) : $default_rule;
            if (!$rule && $rule_id > 0) {
                $rule = $default_rule;
            }
            if (!$rule) {
                // 保持未配置运费模板的既有免运费行为。
                continue;
            }

            $group_key = (int) $rule->id;
            if (!isset($groups[$group_key])) {
                $groups[$group_key] = [
                    'rule' => $rule,
                    'amount' => 0.0,
                    'weight' => 0.0,
                ];
            }

            $quantity = max(0, (int) ($item->quantity ?? 0));
            $groups[$group_key]['amount'] += (float) ($item->subtotal ?? (($item->price ?? 0) * $quantity));
            $groups[$group_key]['weight'] += (float) ($item->sku->weight ?? 0) * $quantity;
        }

        $shipping_fee = 0.0;
        foreach ($groups as $group) {
            $shipping_fee += $this->calculate_by_rule($group['rule'], $group['amount'], $group['weight']);
        }

        return round(max(0, $shipping_fee), 2);
    }

    /**
     * 根据规则计算运费
     *
     * @param object $rule
     * @param float  $total_amount
     * @param float  $total_weight
     * @return float
     */
    public function calculate_by_rule($rule, $total_amount, $total_weight) {
        // 检查是否满足免邮条件
        if ($rule->free_threshold > 0 && $total_amount >= $rule->free_threshold) {
            return 0;
        }

        switch ($rule->type) {
            case self::TYPE_FREE:
                return 0;

            case self::TYPE_FIXED:
                return (float) $rule->base_fee;

            case self::TYPE_WEIGHT:
                $fee = (float) $rule->base_fee;
                
                // 获取首重，如果没有设置，默认为重量步长，如果还没设置，默认为0
                $first_weight = !empty($rule->first_weight) ? $rule->first_weight : ($rule->weight_step > 0 ? $rule->weight_step : 0);

                if ($total_weight > $first_weight && $rule->weight_step > 0 && $rule->step_fee > 0) {
                    // 超出首重部分
                    $extra_weight = $total_weight - $first_weight;
                    $extra_steps = ceil($extra_weight / $rule->weight_step);
                    $fee += $extra_steps * $rule->step_fee;
                }
                
                return $fee;

            default:
                return 0;
        }
    }

    /**
     * 获取运费类型文本
     *
     * @param int $type
     * @return string
     */
    public function get_type_text($type) {
        $types = [
            self::TYPE_FREE   => __('包邮', 'qilingshop'),
            self::TYPE_FIXED  => __('固定运费', 'qilingshop'),
            self::TYPE_WEIGHT => __('按重量计费', 'qilingshop'),
        ];

        return isset($types[$type]) ? $types[$type] : __('未知', 'qilingshop');
    }

    /**
     * 获取运费规则描述
     *
     * @param object $rule
     * @return string
     */
    public function get_rule_description($rule) {
        $desc = [];

        switch ($rule->type) {
            case self::TYPE_FREE:
                $desc[] = __('全场包邮', 'qilingshop');
                break;

            case self::TYPE_FIXED:
                $desc[] = sprintf(__('运费 ¥%s', 'qilingshop'), number_format($rule->base_fee, 2));
                break;

            case self::TYPE_WEIGHT:
                $first_weight = !empty($rule->first_weight) ? $rule->first_weight : ($rule->weight_step > 0 ? $rule->weight_step : 0);
                $desc[] = sprintf(
                    __('首重%sg ¥%s，续重每%sg ¥%s', 'qilingshop'),
                    $first_weight,
                    number_format($rule->base_fee, 2),
                    $rule->weight_step,
                    number_format($rule->step_fee, 2)
                );
                break;
        }

        if ($rule->free_threshold > 0) {
            $desc[] = sprintf(__('满¥%s包邮', 'qilingshop'), number_format($rule->free_threshold, 2));
        }

        return implode('，', $desc);
    }
}

/**
 * 获取运费类实例的快捷函数
 */
function qls_shipping() {
    return QLS_Shipping::instance();
}
