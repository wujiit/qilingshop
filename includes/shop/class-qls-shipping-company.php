<?php
/**
 * 商城物流公司服务。
 *
 * @package QilingShop
 * @since   2.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QLS_Shipping_Company {

    const STATUS_DISABLED = 0;
    const STATUS_ENABLED  = 1;

    /**
     * 单例实例。
     *
     * @var QLS_Shipping_Company|null
     */
    private static $instance = null;

    /**
     * 数据库实例。
     *
     * @var QLS_Shop_Database
     */
    private $db;

    /**
     * 物流公司表。
     *
     * @var string
     */
    private $table;

    /**
     * 获取单例实例。
     *
     * @return QLS_Shipping_Company
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        $this->db = QLS_Shop_Database::instance();
        $this->table = $this->db->get_table('shipping_companies');
    }

    /**
     * 获取物流公司列表。
     *
     * @param bool $include_disabled 是否包含已停用公司。
     * @return array
     */
    public function all($include_disabled = false) {
        if (!$this->table_exists()) {
            return $this->legacy_companies($include_disabled);
        }

        $wpdb = $this->db->get_wpdb();
        $where = $include_disabled ? '' : 'WHERE status = 1';
        $rows = $wpdb->get_results(
            "SELECT * FROM {$this->table} {$where} ORDER BY is_default DESC, sort_order ASC, id ASC"
        );

        if (empty($rows)) {
            return $this->legacy_companies($include_disabled);
        }

        return array_map([$this, 'parse_company'], $rows);
    }

    /**
     * 获取启用的物流公司。
     *
     * @return array
     */
    public function enabled() {
        return $this->all(false);
    }

    /**
     * 获取单个物流公司。
     *
     * @param int $id
     * @return object|null
     */
    public function get($id) {
        $id = (int) $id;
        if ($id <= 0 || !$this->table_exists()) {
            return null;
        }

        $company = $this->db->get_by_id('shipping_companies', $id);
        return $company ? $this->parse_company($company) : null;
    }

    /**
     * 根据编码获取物流公司。
     *
     * @param string $code
     * @return object|null
     */
    public function get_by_code($code) {
        $code = strtoupper(trim((string) $code));
        if ($code === '' || !$this->table_exists()) {
            return null;
        }

        $wpdb = $this->db->get_wpdb();
        $company = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE code = %s LIMIT 1",
            $code
        ));

        return $company ? $this->parse_company($company) : null;
    }

    /**
     * 根据名称、别名或编码匹配物流公司。
     *
     * @param string $identifier
     * @return object|null
     */
    public function find($identifier) {
        $identifier = trim((string) $identifier);
        if ($identifier === '') {
            return null;
        }

        $code_match = $this->get_by_code($identifier);
        if ($code_match) {
            return $code_match;
        }

        foreach ($this->all(true) as $company) {
            if ((string) $company->name === $identifier || (string) $company->code === $identifier) {
                return $company;
            }

            foreach ((array) $company->aliases as $alias) {
                if ((string) $alias === $identifier) {
                    return $company;
                }
            }
        }

        return null;
    }

    /**
     * 获取默认物流公司。
     *
     * @return object|null
     */
    public function get_default() {
        foreach ($this->enabled() as $company) {
            if (!empty($company->is_default)) {
                return $company;
            }
        }

        $companies = $this->enabled();
        return !empty($companies) ? $companies[0] : null;
    }

    /**
     * 保存物流公司。
     *
     * @param array $data
     * @return int|WP_Error
     */
    public function create($data) {
        if (!$this->table_exists()) {
            return new WP_Error('missing_table', __('物流公司数据表不存在', 'qilingshop'));
        }

        $data = $this->sanitize_data($data);
        if (is_wp_error($data)) {
            return $data;
        }

        if (!empty($data['is_default'])) {
            $this->db->update('shipping_companies', ['is_default' => 0], ['is_default' => 1]);
        }

        $insert_id = $this->db->insert('shipping_companies', $data);
        if (!$insert_id) {
            return new WP_Error('insert_failed', __('物流公司保存失败', 'qilingshop'));
        }

        $this->ensure_default_enabled_company();
        $this->sync_legacy_option();
        return (int) $insert_id;
    }

    /**
     * 更新物流公司。
     *
     * @param int   $id
     * @param array $data
     * @return bool|WP_Error
     */
    public function update($id, $data) {
        $id = (int) $id;
        if ($id <= 0 || !$this->table_exists()) {
            return new WP_Error('invalid_company', __('物流公司不存在', 'qilingshop'));
        }

        $data = $this->sanitize_data($data, true);
        if (is_wp_error($data)) {
            return $data;
        }

        if (empty($data)) {
            return true;
        }

        if (!empty($data['is_default'])) {
            $this->db->update('shipping_companies', ['is_default' => 0], ['is_default' => 1]);
        }

        $updated = $this->db->update('shipping_companies', $data, ['id' => $id]);
        if ($updated === false) {
            return false;
        }

        $this->ensure_default_enabled_company();
        $this->sync_legacy_option();
        return true;
    }

    /**
     * 删除物流公司。
     *
     * @param int $id
     * @return bool
     */
    public function delete($id) {
        $id = (int) $id;
        if ($id <= 0 || !$this->table_exists()) {
            return false;
        }

        $deleted = $this->db->delete('shipping_companies', ['id' => $id]) !== false;
        if ($deleted) {
            $this->ensure_default_enabled_company();
            $this->sync_legacy_option();
        }

        return $deleted;
    }

    /**
     * 判断数据表是否存在。
     *
     * @return bool
     */
    private function table_exists() {
        $wpdb = $this->db->get_wpdb();
        return (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $this->table)) === $this->table;
    }

    /**
     * 兼容旧 option 里的物流公司列表。
     *
     * @param bool $include_disabled
     * @return array
     */
    private function legacy_companies($include_disabled = false) {
        $defaults = [
            ['name' => '顺丰速运', 'code' => 'SF', 'sort_order' => 1, 'phone_required' => 1],
            ['name' => '圆通速递', 'code' => 'YTO', 'sort_order' => 2],
            ['name' => '中通快递', 'code' => 'ZTO', 'sort_order' => 3, 'phone_required' => 1],
            ['name' => '韵达快递', 'code' => 'YD', 'sort_order' => 4],
            ['name' => '申通快递', 'code' => 'STO', 'sort_order' => 5],
            ['name' => '邮政EMS', 'code' => 'EMS', 'sort_order' => 6],
            ['name' => '京东快递', 'code' => 'JD', 'sort_order' => 7],
            ['name' => '极兔速递', 'code' => 'J&T', 'sort_order' => 8],
            ['name' => '其他', 'code' => 'OTHER', 'sort_order' => 99],
        ];

        $companies = get_option('qls_shop_shipping_companies', $defaults);
        if (!is_array($companies)) {
            $companies = $defaults;
        }

        $parsed = [];
        foreach ($companies as $index => $company) {
            $row = (object) [
                'id'             => $index + 1,
                'name'           => sanitize_text_field($company['name'] ?? ''),
                'code'           => sanitize_text_field($company['code'] ?? ''),
                'aliases'        => $company['aliases'] ?? [],
                'phone_required' => !empty($company['phone_required']) ? 1 : 0,
                'is_default'     => !empty($company['is_default']) ? 1 : ($index === 0 ? 1 : 0),
                'status'         => isset($company['status']) ? (int) $company['status'] : 1,
                'sort_order'     => isset($company['sort_order']) ? (int) $company['sort_order'] : ($index + 1),
            ];

            if (!$include_disabled && (int) $row->status !== self::STATUS_ENABLED) {
                continue;
            }

            $parsed[] = $this->parse_company($row);
        }

        usort($parsed, function($a, $b) {
            $default_compare = (int) $b->is_default <=> (int) $a->is_default;
            if ($default_compare !== 0) {
                return $default_compare;
            }

            return (int) $a->sort_order <=> (int) $b->sort_order;
        });

        return $parsed;
    }

    /**
     * 清洗物流公司数据。
     *
     * @param array $data
     * @param bool  $partial
     * @return array|WP_Error
     */
    private function sanitize_data($data, $partial = false) {
        $data = is_array($data) ? $data : [];
        $clean = [];

        if (!$partial || array_key_exists('name', $data)) {
            $clean['name'] = sanitize_text_field($data['name'] ?? '');
            if ($clean['name'] === '') {
                return new WP_Error('missing_name', __('请填写物流公司名称', 'qilingshop'));
            }
        }

        if (!$partial || array_key_exists('code', $data)) {
            $clean['code'] = strtoupper(sanitize_text_field($data['code'] ?? ''));
            if ($clean['code'] === '') {
                return new WP_Error('missing_code', __('请填写物流公司编码', 'qilingshop'));
            }
        }

        if (array_key_exists('aliases', $data)) {
            $aliases = array_filter(array_map('sanitize_text_field', (array) $data['aliases']));
            $clean['aliases'] = !empty($aliases) ? wp_json_encode(array_values($aliases)) : null;
        }

        foreach (['phone_required', 'is_default', 'status'] as $flag) {
            if (!$partial || array_key_exists($flag, $data)) {
                $clean[$flag] = empty($data[$flag]) ? 0 : 1;
            }
        }

        if (isset($clean['status']) && (int) $clean['status'] === self::STATUS_DISABLED) {
            $clean['is_default'] = 0;
        }

        if (!$partial || array_key_exists('sort_order', $data)) {
            $clean['sort_order'] = isset($data['sort_order']) ? (int) $data['sort_order'] : 0;
        }

        return $clean;
    }

    /**
     * 保证至少有一个启用物流公司作为默认项。
     *
     * @return void
     */
    private function ensure_default_enabled_company() {
        if (!$this->table_exists()) {
            return;
        }

        $wpdb = $this->db->get_wpdb();
        $default_id = (int) $wpdb->get_var("SELECT id FROM {$this->table} WHERE status = 1 AND is_default = 1 ORDER BY sort_order ASC, id ASC LIMIT 1");
        if ($default_id > 0) {
            $wpdb->query($wpdb->prepare("UPDATE {$this->table} SET is_default = CASE WHEN id = %d THEN 1 ELSE 0 END WHERE status = 1", $default_id));
            return;
        }

        $first_id = (int) $wpdb->get_var("SELECT id FROM {$this->table} WHERE status = 1 ORDER BY sort_order ASC, id ASC LIMIT 1");
        if ($first_id > 0) {
            $wpdb->update($this->table, ['is_default' => 1], ['id' => $first_id]);
        }
    }

    /**
     * 同步旧 option，兼容外部历史代码读取。
     *
     * @return void
     */
    private function sync_legacy_option() {
        if (!$this->table_exists()) {
            return;
        }

        $wpdb = $this->db->get_wpdb();
        $rows = $wpdb->get_results("SELECT * FROM {$this->table} ORDER BY is_default DESC, sort_order ASC, id ASC");
        if (!is_array($rows)) {
            return;
        }

        $companies = [];
        foreach ($rows as $row) {
            $company = $this->parse_company($row);
            $companies[] = [
                'id'             => (int) $company->id,
                'name'           => (string) $company->name,
                'code'           => (string) $company->code,
                'aliases'        => (array) $company->aliases,
                'phone_required' => (int) $company->phone_required,
                'is_default'     => (int) $company->is_default,
                'status'         => (int) $company->status,
                'sort_order'     => (int) $company->sort_order,
            ];
        }

        update_option('qls_shop_shipping_companies', $companies, false);
    }

    /**
     * 解析物流公司记录。
     *
     * @param object $company
     * @return object
     */
    private function parse_company($company) {
        if (!empty($company->aliases) && is_string($company->aliases)) {
            $decoded = json_decode($company->aliases, true);
            $company->aliases = is_array($decoded) ? $decoded : [];
        } elseif (empty($company->aliases)) {
            $company->aliases = [];
        }

        $company->id = (int) ($company->id ?? 0);
        $company->phone_required = (int) ($company->phone_required ?? 0);
        $company->is_default = (int) ($company->is_default ?? 0);
        $company->status = (int) ($company->status ?? 1);
        $company->sort_order = (int) ($company->sort_order ?? 0);

        return $company;
    }
}

/**
 * 获取物流公司服务实例。
 *
 * @return QLS_Shipping_Company
 */
function qls_shipping_company() {
    return QLS_Shipping_Company::instance();
}
