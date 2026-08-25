<?php
/**
 * VIP 会员管理类
 *
 * @package QilingShop
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QilingShop_VIP {

    /**
     * 单例实例
     *
     * @var QilingShop_VIP
     */
    private static $instance = null;

    /**
     * 数据库实例
     *
     * @var QilingShop_Database
     */
    private $db;

    /**
     * VIP 等级缓存
     *
     * @var array
     */
    private $levels_cache = null;

    /**
     * 获取单例实例
     *
     * @return QilingShop_VIP
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
        $this->db = QilingShop_Database::instance();
    }

    /**
     * 获取数据库级命名锁，串行化会员购买动作。
     *
     * @param string $lock_name
     * @param int    $timeout
     * @return bool
     */
    private function acquire_named_lock($lock_name, $timeout = 5) {
        global $wpdb;

        $lock_name = sanitize_key((string) $lock_name);
        if ($lock_name === '') {
            return false;
        }

        $result = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, (int) $timeout));
        return (int) $result === 1;
    }

    /**
     * 释放数据库级命名锁。
     *
     * @param string $lock_name
     * @return void
     */
    private function release_named_lock($lock_name) {
        global $wpdb;

        $lock_name = sanitize_key((string) $lock_name);
        if ($lock_name === '') {
            return;
        }

        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
    }

    /**
     * 构造用户级 VIP 购买锁名。
     *
     * @param int $user_id
     * @return string
     */
    private function build_vip_purchase_lock_name($user_id) {
        return 'qlsvip_' . md5((string) absint($user_id));
    }

    private function refund_failed_points_purchase($user_id, $points_price, $description) {
        $refunded = QilingShop_Points::instance()->add_points(
            (int) $user_id,
            (float) $points_price,
            'refund',
            (string) $description
        );

        if (!$refunded && function_exists('qilingshop_log')) {
            qilingshop_log('VIP points refund failed', 'error', [
                'user_id' => (int) $user_id,
                'points'  => (float) $points_price,
            ]);
        }

        return (bool) $refunded;
    }

    /**
     * 获取所有 VIP 等级
     *
     * @param bool $active_only 是否只获取启用的
     * @return array
     */
    public function get_levels($active_only = true) {
        if ($this->levels_cache !== null && $active_only) {
            return $this->levels_cache;
        }

        $where = [];
        if ($active_only) {
            $where['is_active'] = 1;
        }

        $levels = $this->db->get_results('vip_levels', [
            'where'   => $where,
            'orderby' => 'sort_order',
            'order'   => 'ASC',
            'limit'   => -1,
        ]);

        /**
         * 过滤 VIP 等级列表
         *
         * @param array $levels VIP 等级列表
         */
        $levels = apply_filters('qilingshop_vip_levels', $levels);

        if ($active_only) {
            $this->levels_cache = $levels;
        }

        return $levels;
    }

    /**
     * 根据 ID 获取 VIP 等级
     *
     * @param int $level_id 等级 ID
     * @return object|null
     */
    public function get_level_by_id($level_id) {
        return $this->db->get_by_id('vip_levels', $level_id);
    }

    /**
     * 根据 key 获取 VIP 等级
     *
     * @param string $level_key 等级 key
     * @return object|null
     */
    public function get_level_by_key($level_key) {
        return $this->db->get_row('vip_levels', ['level_key' => $level_key]);
    }

    /**
     * 根据 ID 获取 VIP 等级（别名方法）
     *
     * @param int $level_id 等级 ID
     * @return object|null
     */
    public function get_level($level_id) {
        return $this->get_level_by_id($level_id);
    }

    /**
     * 获取用户当前 VIP 等级 ID
     *
     * @param int $user_id 用户 ID
     * @return int
     */
    public function get_user_level($user_id = null) {
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }

        if (!$user_id) {
            return 0;
        }

        $user_info = QilingShop_Points::instance()->get_user_info($user_id);
        
        if (!$user_info) {
            return 0;
        }

        // 检查是否过期（过期即视为非VIP，不在此处重置数据库）
        $today = current_time('Y-m-d');
        if ($user_info->vip_level > 0 && $this->is_expired($user_info->vip_expires, $today)) {
            return 0;
        }

        return (int) $user_info->vip_level;
    }

    /**
     * 获取用户 VIP 等级名称
     *
     * @param int $user_id 用户 ID
     * @return string
     */
    public function get_user_level_name($user_id = null) {
        $level_id = $this->get_user_level($user_id);
        
        if ($level_id <= 0) {
            return __('普通用户', 'qilingshop');
        }

        $level = $this->get_level_by_id($level_id);
        
        return $level ? $level->level_name : __('VIP', 'qilingshop');
    }

    /**
     * 获取用户 VIP 过期时间
     *
     * @param int $user_id 用户 ID
     * @return string
     */
    public function get_user_expires($user_id = null) {
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }

        $user_info = QilingShop_Points::instance()->get_user_info($user_id);
        
        if (!$user_info || $user_info->vip_level <= 0) {
            return '';
        }

        return $this->format_expires_display($user_info->vip_expires);
    }

    /**
     * 获取用户 VIP 等级信息
     *
     * @param int $user_id 用户 ID
     * @return object|null
     */
    public function get_user_level_info($user_id = null) {
        $level_id = $this->get_user_level($user_id);
        
        if ($level_id <= 0) {
            return null;
        }

        return $this->get_level_by_id($level_id);
    }

    /**
     * 检查用户是否为 VIP
     *
     * @param int $user_id   用户 ID
     * @param int $min_level 最低等级要求（可选）
     * @return bool
     */
    public function is_vip($user_id = null, $min_level = 1) {
        $level = $this->get_user_level($user_id);
        
        if ($min_level <= 1) {
            return $level > 0;
        }

        // 根据排序比较等级
        if ($level <= 0) {
            return false;
        }

        $user_level_info = $this->get_level_by_id($level);
        $min_level_info = $this->get_level_by_id($min_level);

        if (!$user_level_info || !$min_level_info) {
            return $level >= $min_level;
        }

        return $user_level_info->sort_order >= $min_level_info->sort_order;
    }

    /**
     * 获取用户 VIP 状态（包含过期与宽限期信息）
     *
     * @param int $user_id
     * @return array
     */
    public function get_user_vip_status($user_id = null) {
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }

        $empty = [
            'level_id'     => 0,
            'level_info'   => null,
            'expires'      => '',
            'is_lifetime'  => false,
            'is_expired'   => false,
            'in_grace'     => false,
            'days_left'    => 0,
            'grace_days'   => $this->get_grace_days(),
            'grace_until'  => '',
        ];

        if (!$user_id) {
            return $empty;
        }

        $user_info = QilingShop_Points::instance()->get_user_info($user_id);
        if (!$user_info || $user_info->vip_level <= 0) {
            return $empty;
        }

        $expires = (string) $user_info->vip_expires;
        $today = current_time('Y-m-d');
        $is_lifetime = $this->is_lifetime_date($expires);
        $days_left = 0;
        $is_expired = false;
        $in_grace = false;
        $grace_days = $this->get_grace_days();
        $grace_until = '';

        if ($is_lifetime) {
            $days_left = 999999;
        } else {
            $days_left = (int) floor((strtotime($expires) - strtotime($today)) / 86400);
            if ($expires < $today) {
                $is_expired = true;
                if ($grace_days > 0) {
                    $grace_until = date('Y-m-d', strtotime($expires . ' + ' . $grace_days . ' days'));
                    if ($today <= $grace_until) {
                        $in_grace = true;
                    }
                }
            }
        }

        return [
            'level_id'     => (int) $user_info->vip_level,
            'level_info'   => $this->get_level_by_id((int) $user_info->vip_level),
            'expires'      => $expires,
            'is_lifetime'  => $is_lifetime,
            'is_expired'   => $is_expired,
            'in_grace'     => $in_grace,
            'days_left'    => $days_left,
            'grace_days'   => $grace_days,
            'grace_until'  => $grace_until,
        ];
    }

    /**
     * 获取 VIP 折扣率
     *
     * @param int $user_id 用户 ID
     * @return int 折扣率（0-100）
     */
    public function get_discount_rate($user_id = null) {
        $level_info = $this->get_user_level_info($user_id);
        
        if (!$level_info) {
            return 100; // 无折扣
        }

        return (int) $level_info->discount_rate;
    }

    /**
     * 检查 VIP 是否可免费下载
     *
     * @param int $user_id 用户 ID
     * @return bool
     */
    public function can_download_free($user_id = null) {
        $level_info = $this->get_user_level_info($user_id);
        
        if (!$level_info) {
            return false;
        }

        return (bool) $level_info->can_download_free;
    }

    /**
     * 获取 VIP 每日下载限制
     *
     * @param int $user_id 用户 ID
     * @return int -1 表示无限制
     */
    public function get_daily_download_limit($user_id = null) {
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }

        $level_info = $this->get_user_level_info($user_id);
        
        if (!$level_info) {
            return 0;
        }

        $limit = (int) $level_info->daily_download_limit;
        if ($limit >= 0 && class_exists('QilingShop_Growth_Benefits')) {
            $limit += QilingShop_Growth_Benefits::instance()->get_download_extra_quota($user_id);
        }

        return $limit;
    }

    /**
     * 升级 VIP
     *
     * @param int    $user_id      用户 ID
     * @param int    $level_id     VIP 等级 ID
     * @param string $payment_type 支付类型：points/rmb
     * @param float  $price        实际支付金额（积分或人民币）
     * @param string $order_no     订单号
     * @param int|null $duration_override 自定义有效天数（可选）
     * @param bool $skip_lock 是否跳过用户级升级锁（外层已持锁时使用）
     * @return array
     */
    public function upgrade($user_id, $level_id, $payment_type = 'points', $price = 0, $order_no = '', $duration_override = null, $skip_lock = false) {
        $lock_name = $this->build_vip_purchase_lock_name($user_id);
        if (!$skip_lock && !$this->acquire_named_lock($lock_name, 5)) {
            return [
                'success' => false,
                'message' => __('请求处理中，请勿重复提交', 'qilingshop'),
            ];
        }

        try {
            $level = $this->get_level_by_id($level_id);
        
            if (!$level) {
                return [
                    'success' => false,
                    'message' => __('VIP 等级不存在', 'qilingshop'),
                ];
            }

            if (!$level->is_active) {
                return [
                    'success' => false,
                    'message' => __('该 VIP 等级已停用', 'qilingshop'),
                ];
            }

            // 获取用户当前信息
            $user_info = QilingShop_Points::instance()->get_user_info($user_id);
            $old_level = $user_info ? $user_info->vip_level : 0;
            $old_expires = $user_info ? $user_info->vip_expires : '';

            // 计算新的过期时间（含宽限期续费叠加逻辑）
            $new_expires = $this->calculate_new_expires($user_id, $level_id, $duration_override);
            if ($new_expires === '') {
                $new_expires = current_time('Y-m-d');
            }

            /**
             * VIP 升级前
             *
             * @param int    $user_id   用户 ID
             * @param int    $level_id  新等级 ID
             * @param int    $old_level 旧等级 ID
             */
            do_action('qilingshop_before_vip_upgrade', $user_id, $level_id, $old_level);

            // 更新用户 VIP 状态
            $updated = $this->db->update('user_info', [
                'vip_level'   => $level_id,
                'vip_expires' => $new_expires,
                'updated_at'  => current_time('mysql'),
            ], ['user_id' => $user_id]);

            if ($updated === false) {
                return [
                    'success' => false,
                    'message' => __('升级失败，请重试', 'qilingshop'),
                ];
            }

            // 记录升级日志
            $this->db->insert('vip_log', [
                'user_id'        => $user_id,
                'vip_level'      => $level_id,
                'vip_level_name' => $level->level_name,
                'price'          => $price,
                'payment_type'   => $payment_type,
                'order_no'       => $order_no,
                'expires_at'     => $new_expires,
                'ip_address'     => qilingshop_security()->get_client_ip(),
                'created_at'     => current_time('mysql'),
            ]);

            // 清除缓存
            QilingShop_Points::instance()->clear_user_cache($user_id);

            /**
             * VIP 升级后
             *
             * @param int    $user_id     用户 ID
             * @param int    $level_id    新等级 ID
             * @param int    $old_level   旧等级 ID
             * @param string $new_expires 新过期时间
             */
            do_action('qilingshop_vip_upgraded', $user_id, $level_id, $old_level, $new_expires);

            return [
                'success'     => true,
                'message'     => sprintf(__('恭喜！成功升级为 %s', 'qilingshop'), $level->level_name),
                'level_id'    => $level_id,
                'level_name'  => $level->level_name,
                'expires'     => $new_expires,
            ];
        } finally {
            if (!$skip_lock) {
                $this->release_named_lock($lock_name);
            }
        }
    }

    /**
     * 用积分购买 VIP
     *
     * @param int $user_id  用户 ID
     * @param int $level_id VIP 等级 ID
     * @return array
     */
    public function purchase_with_points($user_id, $level_id) {
        $lock_name = $this->build_vip_purchase_lock_name($user_id);
        if (!$this->acquire_named_lock($lock_name, 5)) {
            return [
                'success' => false,
                'message' => __('请求处理中，请勿重复提交', 'qilingshop'),
            ];
        }

        try {
            $level = $this->get_level_by_id($level_id);
            
            if (!$level) {
                return [
                    'success' => false,
                    'message' => __('VIP 等级不存在', 'qilingshop'),
                ];
            }

            // 计算积分价格（支持差价升级）
            $price_rmb = (float) $this->calculate_upgrade_price($user_id, $level_id);
            if ($price_rmb < 0) {
                $price_rmb = 0;
            }
            $points_price = qilingshop_rmb_to_points($price_rmb);

            // 生成订单号
            $order_no = qilingshop_security()->generate_order_no('VIP');

            if ($points_price > 0) {
                // 检查余额
                $balance = QilingShop_Points::instance()->get_balance($user_id);
                if ($balance < $points_price) {
                    return [
                        'success' => false,
                        'message' => __('积分余额不足，请先充值', 'qilingshop'),
                    ];
                }

                // 扣除积分
                $deducted = QilingShop_Points::instance()->deduct_points(
                    $user_id,
                    $points_price,
                    'vip',
                    sprintf(__('购买 %s', 'qilingshop'), $level->level_name)
                );

                if (!$deducted) {
                    return [
                        'success' => false,
                        'message' => __('扣费失败，请重试', 'qilingshop'),
                    ];
                }
            }

            // 升级 VIP
            $result = $this->upgrade($user_id, $level_id, 'points', $points_price, $order_no, null, true);

            if (empty($result['success']) && $points_price > 0) {
                $refund_ok = $this->refund_failed_points_purchase(
                    $user_id,
                    $points_price,
                    sprintf(__('VIP开通失败退还积分：%s', 'qilingshop'), $level->level_name)
                );
                if (!$refund_ok) {
                    return [
                        'success' => false,
                        'message' => __('VIP开通失败，积分回退失败，请联系管理员处理', 'qilingshop'),
                    ];
                }
            }

            if ($result['success']) {
                // 处理推广提成
                if (get_option('qilingshop_affiliate_vip_enabled', true)) {
                    $commission_ok = QilingShop_Affiliate::instance()->process_commission(
                        $user_id,
                        $price_rmb,
                        'vip',
                        $order_no
                    );
                    if (!$commission_ok) {
                        $queued = QilingShop_Affiliate::instance()->queue_commission_retry(
                            (int) $user_id,
                            (float) $price_rmb,
                            'vip',
                            (string) $order_no,
                            'vip_points_purchase'
                        );
                        qilingshop_log(
                            $queued ? 'Affiliate commission queued for retry after vip purchase' : 'Affiliate commission retry queue failed after vip purchase',
                            'error',
                            [
                            'order_no' => (string) $order_no,
                            'user_id'  => (int) $user_id,
                            ]
                        );
                    }
                }
            }

            return $result;
        } finally {
            $this->release_named_lock($lock_name);
        }
    }

    /**
     * 计算升级差价
     *
     * @param int $user_id     用户 ID
     * @param int $new_level_id 目标等级 ID
     * @return float
     */
    public function calculate_upgrade_price($user_id, $new_level_id) {
        $current_level = $this->get_user_level_for_pricing($user_id);
        $new_level = $this->get_level_by_id($new_level_id);

        if (!$new_level) {
            return 0;
        }

        $new_price = (float) $new_level->price;

        // 如果没有当前等级或是不同等级，直接返回新等级价格
        if ($current_level <= 0) {
            return $new_price;
        }

        $current_level_info = $this->get_level_by_id($current_level);
        
        if (!$current_level_info) {
            return $new_price;
        }

        // 检查是否允许差价升级
        $allow_diff_upgrade = get_option('qilingshop_vip_diff_upgrade', true);
        
        if (!$allow_diff_upgrade) {
            return $new_price;
        }

        // 只有升级（新等级排序更高）才计算差价
        if ($new_level->sort_order <= $current_level_info->sort_order) {
            return $new_price;
        }

        // 计算差价
        $diff = $new_price - (float) $current_level_info->price;

        return max(0, $diff);
    }

    /**
     * 计算升级/续费后的到期日期（用于预览）
     *
     * @param int $user_id
     * @param int $level_id
     * @return string Y-m-d 或 3999-12-31
     */
    public function calculate_new_expires($user_id, $level_id, $duration_override = null) {
        $level = $this->get_level_by_id($level_id);
        if (!$level) {
            return '';
        }

        $today = current_time('Y-m-d');
        if ($duration_override !== null) {
            $duration_days = (int) $duration_override;
            if ($duration_days <= 0) {
                $duration_days = (int) $level->duration_days;
            }
        } else {
            $duration_days = (int) $level->duration_days;
        }

        if ($duration_days >= 999999) {
            return '3999-12-31';
        }

        $user_info = QilingShop_Points::instance()->get_user_info($user_id);
        $old_level = $user_info ? (int) $user_info->vip_level : 0;
        $old_expires = $user_info ? (string) $user_info->vip_expires : '';

        $base_date = $today;
        if ($old_level === (int) $level_id && $old_expires !== '') {
            if ($old_expires > $today || $this->is_within_grace($old_expires, $today)) {
                $base_date = $old_expires;
            }
        }

        return date('Y-m-d', strtotime($base_date . ' + ' . $duration_days . ' days'));
    }

    /**
     * 获取用于定价的VIP等级（宽限期内仍视为有效）
     *
     * @param int $user_id
     * @return int
     */
    public function get_user_level_for_pricing($user_id = null) {
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }

        $status = $this->get_user_vip_status($user_id);
        if ($status['level_id'] <= 0) {
            return 0;
        }

        if (!$status['is_expired']) {
            return $status['level_id'];
        }

        return $status['in_grace'] ? $status['level_id'] : 0;
    }

    /**
     * 格式化到期时间显示
     *
     * @param string $expires
     * @return string
     */
    public function format_expires_display($expires) {
        if ($expires === '' || $expires === '1000-01-01') {
            return '';
        }
        if ($this->is_lifetime_date($expires)) {
            return __('永久', 'qilingshop');
        }
        return $expires;
    }

    private function is_lifetime_date($expires) {
        if ($expires === '3999-12-31') {
            return true;
        }
        return strtotime($expires) > strtotime('+100 years');
    }

    /**
     * 是否为永久有效日期（公开方法）
     *
     * @param string $expires
     * @return bool
     */
    public function is_lifetime_expires($expires) {
        return $this->is_lifetime_date($expires);
    }

    private function is_expired($expires, $today) {
        if ($expires === '' || $expires === '1000-01-01') {
            return true;
        }
        if ($this->is_lifetime_date($expires)) {
            return false;
        }
        return $expires < $today;
    }

    private function is_within_grace($expires, $today) {
        if (!$this->is_expired($expires, $today)) {
            return false;
        }
        $grace_days = $this->get_grace_days();
        if ($grace_days <= 0) {
            return false;
        }
        $grace_until = date('Y-m-d', strtotime($expires . ' + ' . $grace_days . ' days'));
        return $today <= $grace_until;
    }

    private function get_grace_days() {
        return max(0, (int) get_option('qilingshop_vip_grace_days', 3));
    }

    /**
     * 获取 VIP 升级记录
     *
     * @param int   $user_id 用户 ID
     * @param array $args    查询参数
     * @return array
     */
    public function get_vip_log($user_id, $args = []) {
        $defaults = [
            'limit'  => 20,
            'offset' => 0,
        ];

        $args = wp_parse_args($args, $defaults);

        return $this->db->get_results('vip_log', [
            'where'   => ['user_id' => $user_id],
            'orderby' => 'id',
            'order'   => 'DESC',
            'limit'   => $args['limit'],
            'offset'  => $args['offset'],
        ]);
    }

    /**
     * 获取 VIP 升级记录数量
     *
     * @param int $user_id 用户 ID
     * @return int
     */
    public function get_vip_log_count($user_id) {
        return $this->db->count('vip_log', ['user_id' => $user_id]);
    }

    /**
     * 添加 VIP 等级（管理员）
     *
     * @param array $data 等级数据
     * @return int|false
     */
    public function add_level($data) {
        $defaults = [
            'level_key'           => '',
            'level_name'          => '',
            'price'               => 0,
            'original_price'      => null,
            'duration_days'       => 30,
            'discount_rate'       => 100,
            'can_download_free'   => 0,
            'daily_download_limit'=> -1,
            'description'         => '',
            'badge_color'         => '#ff6600',
            'sort_order'          => 0,
            'is_recommended'      => 0,
            'is_active'           => 1,
        ];

        $data = wp_parse_args($data, $defaults);
        $data['created_at'] = current_time('mysql');

        $this->levels_cache = null; // 清除缓存

        return $this->db->insert('vip_levels', $data);
    }

    /**
     * 更新 VIP 等级（管理员）
     *
     * @param int   $level_id 等级 ID
     * @param array $data     更新数据
     * @return bool
     */
    public function update_level($level_id, $data) {
        $data['updated_at'] = current_time('mysql');
        
        $this->levels_cache = null; // 清除缓存

        return $this->db->update('vip_levels', $data, ['id' => $level_id]) !== false;
    }

    /**
     * 删除 VIP 等级（管理员）
     *
     * @param int $level_id 等级 ID
     * @return bool
     */
    public function delete_level($level_id) {
        $this->levels_cache = null; // 清除缓存

        return $this->db->delete('vip_levels', ['id' => $level_id]) !== false;
    }
}
