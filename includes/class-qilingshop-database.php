<?php
/**
 * 数据库操作封装类
 * 
 * 提供安全的数据库操作方法，防止 SQL 注入
 *
 * @package QilingShop
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QilingShop_Database {

    /**
     * 单例实例
     *
     * @var QilingShop_Database
     */
    private static $instance = null;

    /**
     * WordPress 数据库对象
     *
     * @var wpdb
     */
    private $wpdb;

    /**
     * 虚拟资源/积分/VIP系统允许访问的数据表（不含 WordPress 前缀和插件表前缀）。
     *
     * @var array<string,bool>
     */
    private $allowed_tables = [
        'user_info'                  => true,
        'points_log'                 => true,
        'points_assets'              => true,
        'task_reward_claims'         => true,
        'orders'                     => true,
        'recharge'                   => true,
        'vip_levels'                 => true,
        'vip_log'                    => true,
        'affiliate'                  => true,
        'invites'                    => true,
        'invite_tier_rules'          => true,
        'invite_tier_logs'           => true,
        'downloads'                  => true,
        'guest_orders'               => true,
        'checkins'                   => true,
        'recharge_bonus'             => true,
        'order_rebate_rules'         => true,
        'growth_levels'              => true,
        'user_growth'                => true,
        'growth_log'                 => true,
        'growth_benefits'            => true,
        'growth_rules'               => true,
        'withdrawals'                => true,
        'author_commissions'         => true,
        'registration_codes'         => true,
        'registration_code_logs'     => true,
        'vip_codes'                  => true,
        'vip_code_logs'              => true,
    ];

    /**
     * 获取单例实例
     *
     * @return QilingShop_Database
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
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    /**
     * 获取 wpdb 实例
     *
     * @return wpdb
     */
    public function get_wpdb() {
        return $this->wpdb;
    }

    /**
     * 获取带前缀的表名
     *
     * @param string $table 表名（不含前缀）
     * @return string
     */
    public function get_table($table) {
        $table = $this->normalize_table_name($table);
        return $this->wpdb->prefix . QILINGSHOP_TABLE_PREFIX . $table;
    }

    /**
     * 获取允许访问的数据表白名单。
     *
     * @return array<string,bool>
     */
    private function get_allowed_tables() {
        $tables = array_keys($this->allowed_tables);
        $extra_tables = apply_filters('qilingshop_database_extra_allowed_tables', []);
        if (is_array($extra_tables)) {
            $tables = array_merge($tables, $extra_tables);
        }

        $allowed = [];
        foreach ($tables as $table) {
            $table = sanitize_key((string) $table);
            if ($table !== '') {
                $allowed[$table] = true;
            }
        }

        return $allowed;
    }

    /**
     * 校验并规范化表名。
     *
     * @param mixed $table 表名（不含前缀）
     * @return string
     * @throws InvalidArgumentException 当表名不在白名单内。
     */
    private function normalize_table_name($table) {
        $table = sanitize_key((string) $table);
        $allowed_tables = $this->get_allowed_tables();

        if ($table === '' || empty($allowed_tables[$table])) {
            $this->log_error('get_table', $table !== '' ? $table : '(empty)', 'Table name is not in the QilingShop whitelist');
            throw new InvalidArgumentException(sprintf('Invalid QilingShop table name: %s', $table !== '' ? $table : '(empty)'));
        }

        return $table;
    }

    /**
     * 插入一条记录
     *
     * @param string $table  表名（不含前缀）
     * @param array  $data   要插入的数据
     * @param array  $format 数据格式（可选）
     * @return int|false 插入的 ID 或 false
     */
    public function insert($table, $data, $format = null) {
        $table_name = $this->get_table($table);
        
        $result = $this->wpdb->insert($table_name, $data, $format);
        
        if ($result === false) {
            $this->log_error('insert', $table, $this->wpdb->last_error);
            return false;
        }
        
        return $this->wpdb->insert_id;
    }

    /**
     * 更新记录
     *
     * @param string $table        表名（不含前缀）
     * @param array  $data         要更新的数据
     * @param array  $where        WHERE 条件
     * @param array  $format       数据格式（可选）
     * @param array  $where_format WHERE 格式（可选）
     * @return int|false 影响的行数或 false
     */
    public function update($table, $data, $where, $format = null, $where_format = null) {
        $table_name = $this->get_table($table);
        
        $result = $this->wpdb->update($table_name, $data, $where, $format, $where_format);
        
        if ($result === false) {
            $this->log_error('update', $table, $this->wpdb->last_error);
            return false;
        }
        
        return $result;
    }

    /**
     * 删除记录
     *
     * @param string $table        表名（不含前缀）
     * @param array  $where        WHERE 条件
     * @param array  $where_format WHERE 格式（可选）
     * @return int|false 影响的行数或 false
     */
    public function delete($table, $where, $where_format = null) {
        $table_name = $this->get_table($table);
        
        $result = $this->wpdb->delete($table_name, $where, $where_format);
        
        if ($result === false) {
            $this->log_error('delete', $table, $this->wpdb->last_error);
            return false;
        }
        
        return $result;
    }

    /**
     * 获取单条记录
     *
     * @param string $table   表名（不含前缀）
     * @param array  $where   WHERE 条件
     * @param string $output  输出类型
     * @return object|array|null
     */
    public function get_row($table, $where = [], $output = OBJECT) {
        $table_name = $this->get_table($table);
        
        $sql = "SELECT * FROM {$table_name}";
        
        if (!empty($where)) {
            $sql .= " WHERE " . $this->build_where_clause($where);
        }
        
        $sql .= " LIMIT 1";
        
        return $this->wpdb->get_row($sql, $output);
    }

    /**
     * 根据 ID 获取记录
     *
     * @param string $table  表名（不含前缀）
     * @param int    $id     记录 ID
     * @param string $output 输出类型
     * @return object|array|null
     */
    public function get_by_id($table, $id, $output = OBJECT) {
        $table_name = $this->get_table($table);
        
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d LIMIT 1",
            $id
        );
        
        return $this->wpdb->get_row($sql, $output);
    }

    /**
     * 获取多条记录
     *
     * @param string $table   表名（不含前缀）
     * @param array  $args    查询参数
     * @param string $output  输出类型
     * @return array
     */
    public function get_results($table, $args = [], $output = OBJECT) {
        $table_name = $this->get_table($table);
        
        $defaults = [
            'where'    => [],
            'orderby'  => 'id',
            'order'    => 'DESC',
            'limit'    => -1,
            'offset'   => 0,
            'fields'   => '*',
        ];
        
        $args = wp_parse_args($args, $defaults);
        $fields = $this->sanitize_sql_fields($args['fields']);
        $orderby = $this->sanitize_sql_identifier($args['orderby'], 'id');
        $order = strtoupper((string) $args['order']) === 'ASC' ? 'ASC' : 'DESC';
        $limit = (int) $args['limit'];
        $offset = max(0, (int) $args['offset']);

        $sql = "SELECT {$fields} FROM {$table_name}";
        
        if (!empty($args['where'])) {
            $sql .= " WHERE " . $this->build_where_clause($args['where']);
        }
        
        $sql .= " ORDER BY `{$orderby}` {$order}";
        
        if ($limit > 0) {
            $sql .= $this->wpdb->prepare(" LIMIT %d OFFSET %d", $limit, $offset);
        }
        
        return $this->wpdb->get_results($sql, $output);
    }

    /**
     * 获取记录数量
     *
     * @param string $table 表名（不含前缀）
     * @param array  $where WHERE 条件
     * @return int
     */
    public function count($table, $where = []) {
        $table_name = $this->get_table($table);
        
        $sql = "SELECT COUNT(*) FROM {$table_name}";
        
        if (!empty($where)) {
            $sql .= " WHERE " . $this->build_where_clause($where);
        }
        
        return (int) $this->wpdb->get_var($sql);
    }

    /**
     * 获取某列的和
     *
     * @param string $table  表名（不含前缀）
     * @param string $column 列名
     * @param array  $where  WHERE 条件
     * @return float
     */
    public function sum($table, $column, $where = []) {
        $table_name = $this->get_table($table);
        $column = $this->sanitize_sql_identifier($column, 'id');
        $sql = "SELECT COALESCE(SUM(`{$column}`), 0) FROM {$table_name}";
        
        if (!empty($where)) {
            $sql .= " WHERE " . $this->build_where_clause($where);
        }
        
        return (float) $this->wpdb->get_var($sql);
    }

    /**
     * 执行原生 SQL 查询
     *
     * @param string $sql 预处理后的 SQL 语句
     * @return mixed
     */
    public function query($sql) {
        return $this->wpdb->query($sql);
    }

    /**
     * 准备 SQL 语句
     *
     * @param string $sql  SQL 模板
     * @param mixed  $args 参数
     * @return string
     */
    public function prepare($sql, ...$args) {
        return $this->wpdb->prepare($sql, ...$args);
    }

    /**
     * 构建 WHERE 子句
     *
     * @param array $where WHERE 条件
     * @return string
     */
    private function build_where_clause($where) {
        $conditions = [];
        $params = [];

        foreach ($where as $key => $value) {
            $key = $this->sanitize_sql_identifier($key, '');
            if ($key === '') {
                continue;
            }

            if (is_null($value)) {
                $conditions[] = "`{$key}` IS NULL";
            } elseif (is_array($value)) {
                if (isset($value['operator']) && array_key_exists('value', $value)) {
                    $operator = strtoupper(trim((string) $value['operator']));
                    $val = $value['value'];
                    
                    switch ($operator) {
                        case 'IN':
                        case 'NOT IN':
                            $items = array_values((array) $val);
                            if (empty($items)) {
                                $conditions[] = $operator === 'IN' ? '0=1' : '1=1';
                                break;
                            }

                            $conditions[] = "`{$key}` {$operator} (" . implode(',', $this->build_prepare_placeholders($items)) . ")";
                            foreach ($items as $item) {
                                $params[] = $this->normalize_prepare_value($item);
                            }
                            break;
                        case 'BETWEEN':
                            $range = array_values((array) $val);
                            if (count($range) < 2) {
                                break;
                            }

                            $conditions[] = "`{$key}` BETWEEN {$this->get_prepare_placeholder($range[0])} AND {$this->get_prepare_placeholder($range[1])}";
                            $params[] = $this->normalize_prepare_value($range[0]);
                            $params[] = $this->normalize_prepare_value($range[1]);
                            break;
                        case 'LIKE':
                        case 'NOT LIKE':
                            $conditions[] = "`{$key}` {$operator} %s";
                            $params[] = '%' . $this->wpdb->esc_like((string) $val) . '%';
                            break;
                        case '=':
                        case '>':
                        case '<':
                        case '>=':
                        case '<=':
                        case '!=':
                        case '<>':
                            if (is_null($val)) {
                                $conditions[] = in_array($operator, ['!=', '<>'], true) ? "`{$key}` IS NOT NULL" : "`{$key}` IS NULL";
                            } else {
                                $conditions[] = "`{$key}` {$operator} {$this->get_prepare_placeholder($val)}";
                                $params[] = $this->normalize_prepare_value($val);
                            }
                            break;
                        default:
                            $conditions[] = "`{$key}` = {$this->get_prepare_placeholder($val)}";
                            $params[] = $this->normalize_prepare_value($val);
                    }
                } else {
                    // 默认 IN 查询
                    $items = array_values($value);
                    if (empty($items)) {
                        $conditions[] = '0=1';
                        continue;
                    }

                    $conditions[] = "`{$key}` IN (" . implode(',', $this->build_prepare_placeholders($items)) . ")";
                    foreach ($items as $item) {
                        $params[] = $this->normalize_prepare_value($item);
                    }
                }
            } else {
                $conditions[] = "`{$key}` = {$this->get_prepare_placeholder($value)}";
                $params[] = $this->normalize_prepare_value($value);
            }
        }

        if (empty($conditions)) {
            return '1=1';
        }

        $sql = implode(' AND ', $conditions);
        if (empty($params)) {
            return $sql;
        }

        return call_user_func_array([$this->wpdb, 'prepare'], array_merge([$sql], $params));
    }

    /**
     * 根据值类型生成 prepare 占位符。
     *
     * @param mixed $value 条件值。
     * @return string
     */
    private function get_prepare_placeholder($value) {
        if (is_int($value) || is_bool($value)) {
            return '%d';
        }

        if (is_float($value)) {
            return '%f';
        }

        return '%s';
    }

    /**
     * 为 wpdb::prepare 规范化参数值。
     *
     * @param mixed $value 条件值。
     * @return mixed
     */
    private function normalize_prepare_value($value) {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_scalar($value) || is_null($value)) {
            return $value;
        }

        $encoded = wp_json_encode($value);
        return $encoded !== false ? $encoded : '';
    }

    /**
     * 批量生成 prepare 占位符。
     *
     * @param array $values 条件值列表。
     * @return array
     */
    private function build_prepare_placeholders($values) {
        return array_map([$this, 'get_prepare_placeholder'], $values);
    }

    /**
     * 清理 SQL 标识符（列名）。
     *
     * @param string $identifier 列名
     * @param string $fallback 兜底列名
     * @return string
     */
    private function sanitize_sql_identifier($identifier, $fallback = 'id') {
        $identifier = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $identifier);
        if ($identifier === '') {
            return $fallback;
        }
        return $identifier;
    }

    /**
     * 清理 SELECT 字段列表，仅允许 * 或逗号分隔的字段名。
     *
     * @param mixed $fields 字段配置
     * @return string
     */
    private function sanitize_sql_fields($fields) {
        if ($fields === '*') {
            return '*';
        }

        $fields = is_string($fields) ? $fields : '';
        $parts = explode(',', $fields);
        $safe_fields = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $part = preg_replace('/[^a-zA-Z0-9_]/', '', $part);
            if ($part === '') {
                continue;
            }
            $safe_fields[] = "`{$part}`";
        }

        if (empty($safe_fields)) {
            return '*';
        }

        return implode(', ', $safe_fields);
    }

    /**
     * 记录数据库错误
     *
     * @param string $operation 操作类型
     * @param string $table     表名
     * @param string $error     错误信息
     */
    private function log_error($operation, $table, $error) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[QilingShop DB Error] %s on %s: %s',
                $operation,
                $table,
                $error
            ));
        }
    }

    /**
     * 开始事务
     */
    public function begin_transaction() {
        $this->wpdb->query('START TRANSACTION');
    }

    /**
     * 提交事务
     */
    public function commit() {
        $this->wpdb->query('COMMIT');
    }

    /**
     * 回滚事务
     */
    public function rollback() {
        $this->wpdb->query('ROLLBACK');
    }

    /**
     * 获取最后插入的 ID
     *
     * @return int
     */
    public function get_insert_id() {
        return $this->wpdb->insert_id;
    }

    /**
     * 获取最后一次错误
     *
     * @return string
     */
    public function get_last_error() {
        return $this->wpdb->last_error;
    }
}

/**
 * 获取数据库实例的快捷函数
 *
 * @return QilingShop_Database
 */
function qilingshop_db() {
    return QilingShop_Database::instance();
}
