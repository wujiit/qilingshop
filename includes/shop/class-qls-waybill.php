<?php
/**
 * 商城电子面单服务。
 *
 * @package QilingShop
 * @since   2.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QLS_Waybill {

    const PROVIDER_KDNIAO = 'kdniao';

    const LOG_PENDING = 0;
    const LOG_SUCCESS = 1;
    const LOG_FAILED  = 2;

    /**
     * 单例实例。
     *
     * @var QLS_Waybill|null
     */
    private static $instance = null;

    /**
     * 数据库实例。
     *
     * @var QLS_Shop_Database
     */
    private $db;

    /**
     * 表名。
     *
     * @var string
     */
    private $templates_table;
    private $logs_table;

    /**
     * 获取单例实例。
     *
     * @return QLS_Waybill
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        $this->db = QLS_Shop_Database::instance();
        $this->templates_table = $this->db->get_table('waybill_templates');
        $this->logs_table = $this->db->get_table('waybill_logs');
        $this->migrate_legacy_local_templates();
    }

    /**
     * 保存电子面单模板。
     *
     * @param array $data
     * @return int|WP_Error
     */
    public function save_template($data) {
        $data = is_array($data) ? $data : [];
        if (!$this->table_exists($this->templates_table)) {
            return new WP_Error('missing_table', __('电子面单模板表不存在', 'qilingshop'));
        }

        $clean = $this->sanitize_template_data($data);
        if (is_wp_error($clean)) {
            return $clean;
        }

        if (!empty($clean['is_default'])) {
            $this->db->update('waybill_templates', ['is_default' => 0], [
                'provider'   => $clean['provider'],
                'is_default' => 1,
            ]);
        }

        $clean['updated_at'] = current_time('mysql');
        if (!empty($data['id'])) {
            $template_id = (int) $data['id'];
            $updated = $this->db->update('waybill_templates', $clean, ['id' => $template_id]);
            return $updated !== false ? $template_id : new WP_Error('template_update_failed', __('电子面单模板保存失败', 'qilingshop'));
        }

        $clean['created_at'] = current_time('mysql');
        $template_id = $this->db->insert('waybill_templates', $clean);
        return $template_id ? (int) $template_id : new WP_Error('template_create_failed', __('电子面单模板保存失败', 'qilingshop'));
    }

    /**
     * 获取模板列表。
     *
     * @param array $args
     * @return array
     */
    public function get_templates($args = []) {
        if (!$this->table_exists($this->templates_table)) {
            return [];
        }

        $defaults = [
            'provider' => '',
            'status'   => null,
            'limit'    => -1,
            'offset'   => 0,
        ];
        $args = wp_parse_args($args, $defaults);

        $where = [];
        if ($args['provider'] !== '') {
            $where['provider'] = sanitize_key($args['provider']);
        }
        if ($args['status'] !== null) {
            $where['status'] = (int) $args['status'];
        }

        $rows = $this->db->get_results('waybill_templates', [
            'where'   => $where,
            'orderby' => 'is_default',
            'order'   => 'DESC',
            'limit'   => (int) $args['limit'],
            'offset'  => (int) $args['offset'],
        ]);

        return array_map([$this, 'parse_template'], $rows);
    }

    /**
     * 获取默认模板。
     *
     * @param string $provider
     * @param int    $company_id
     * @return object|null
     */
    public function get_default_template($provider = '', $company_id = 0) {
        $templates = $this->get_templates([
            'provider' => $provider,
            'status'   => 1,
            'limit'    => -1,
        ]);

        if (empty($templates)) {
            return null;
        }

        $company_id = (int) $company_id;
        if ($company_id > 0) {
            foreach ($templates as $template) {
                if ((int) ($template->company_id ?? 0) === $company_id && !empty($template->is_default)) {
                    return $template;
                }
            }
            foreach ($templates as $template) {
                if ((int) ($template->company_id ?? 0) === $company_id) {
                    return $template;
                }
            }
        }

        foreach ($templates as $template) {
            if (!empty($template->is_default)) {
                return $template;
            }
        }

        return $templates[0];
    }

    /**
     * 获取模板。
     *
     * @param int $template_id
     * @return object|null
     */
    public function get_template($template_id) {
        $template_id = (int) $template_id;
        if ($template_id <= 0 || !$this->table_exists($this->templates_table)) {
            return null;
        }

        $template = $this->db->get_by_id('waybill_templates', $template_id);
        return $template ? $this->parse_template($template) : null;
    }

    /**
     * 删除模板。
     *
     * @param int $template_id
     * @return bool
     */
    public function delete_template($template_id) {
        $template_id = (int) $template_id;
        if ($template_id <= 0 || !$this->table_exists($this->templates_table)) {
            return false;
        }

        return $this->db->delete('waybill_templates', ['id' => $template_id]) !== false;
    }

    /**
     * 获取电子面单服务商。
     *
     * @return array
     */
    public function get_providers() {
        $providers = [
            self::PROVIDER_KDNIAO => [
                'label'       => __('快递鸟电子面单', 'qilingshop'),
                'description' => __('通过阿里云市场快递鸟接口创建真实电子面单。', 'qilingshop'),
            ],
        ];

        /**
         * 允许后续接入快递鸟、快递100等真实电子面单服务。
         *
         * @param array $providers
         */
        return apply_filters('qls_shop_waybill_providers', $providers);
    }

    /**
     * 生成电子面单。
     *
     * 默认调用快递鸟服务，扩展代码仍可通过
     * qls_shop_waybill_create_response filter 接管请求。
     *
     * @param int   $shipment_id
     * @param array $args
     * @return array|WP_Error
     */
    public function create_for_shipment($shipment_id, $args = []) {
        $shipment_id = (int) $shipment_id;
        $args = is_array($args) ? $args : [];

        if ($shipment_id <= 0) {
            return new WP_Error('invalid_shipment', __('发货单不存在', 'qilingshop'));
        }

        if (!$this->table_exists($this->logs_table)) {
            return new WP_Error('missing_table', __('电子面单日志表不存在', 'qilingshop'));
        }

        if (!function_exists('qls_shipment')) {
            return new WP_Error('shipment_service_missing', __('发货单服务不可用', 'qilingshop'));
        }

        $shipment = qls_shipment()->get($shipment_id, true);
        if (!$shipment) {
            return new WP_Error('shipment_missing', __('发货单不存在', 'qilingshop'));
        }

        $template_id = isset($args['template_id']) ? (int) $args['template_id'] : 0;
        $template = $template_id > 0 ? $this->get_template($template_id) : null;
        if (!$template) {
            $template = $this->get_default_template(self::PROVIDER_KDNIAO, (int) ($shipment->shipping_company_id ?? 0));
        }

        if (!$template || (int) ($template->status ?? 0) !== 1) {
            return new WP_Error('missing_template', __('请选择可用的电子面单模板', 'qilingshop'));
        }

        $provider = self::PROVIDER_KDNIAO;
        $request_data = $this->build_request_data($shipment, $template, $args);

        // 第三方下单接口通常以 OrderCode 幂等，已有成功记录时直接复用，避免重复下单。
        $existing_log = $this->get_latest_success_log($shipment_id);
        if ($existing_log && $existing_log->provider === self::PROVIDER_KDNIAO && !empty($existing_log->waybill_no)) {
            return [
                'log_id'      => (int) $existing_log->id,
                'shipment_id' => $shipment_id,
                'waybill_no'  => (string) $existing_log->waybill_no,
                'print_data'  => $existing_log->response_data['print_data'] ?? [],
                'provider_print_template' => (string) ($existing_log->response_data['provider_print_template'] ?? ''),
            ];
        }

        $response = apply_filters('qls_shop_waybill_create_response', null, $request_data, $shipment, $template, $this);
        if ($response === null) {
            $response = $this->create_kdniao_response($request_data, $shipment, $template);
        }

        if (is_wp_error($response)) {
            $this->add_log([
                'shipment_id'   => $shipment_id,
                'order_id'      => (int) $shipment->order_id,
                'provider'      => $provider,
                'company_code'  => (string) ($shipment->shipping_code ?? ''),
                'request_data'  => $request_data,
                'response_data' => [],
                'status'        => self::LOG_FAILED,
                'error_message' => $response->get_error_message(),
            ]);

            return $response;
        }

        $response = is_array($response) ? $response : [];
        $waybill_no = sanitize_text_field($response['waybill_no'] ?? '');
        if ($waybill_no === '') {
            $error = new WP_Error('waybill_number_missing', __('快递鸟未返回有效的电子面单号', 'qilingshop'));
            $this->add_log([
                'shipment_id'   => $shipment_id,
                'order_id'      => (int) $shipment->order_id,
                'provider'      => $provider,
                'company_code'  => (string) ($shipment->shipping_code ?? ''),
                'request_data'  => $request_data,
                'response_data' => $response,
                'status'        => self::LOG_FAILED,
                'error_message' => $error->get_error_message(),
            ]);
            return $error;
        }

        $print_data = $response['print_data'] ?? $this->build_print_data($shipment, $template, $request_data, $waybill_no);
        $response['waybill_no'] = $waybill_no;
        $response['print_data'] = $print_data;

        $log_id = $this->add_log([
            'shipment_id'   => $shipment_id,
            'order_id'      => (int) $shipment->order_id,
            'provider'      => $provider,
            'company_code'  => (string) ($shipment->shipping_code ?? ''),
            'waybill_no'    => $waybill_no,
            'request_data'  => $request_data,
            'response_data' => $response,
            'status'        => self::LOG_SUCCESS,
        ]);

        if (is_wp_error($log_id)) {
            return $log_id;
        }

        $shipment_update = [
            'waybill_no'      => $waybill_no,
            'sender_snapshot' => wp_json_encode($request_data['sender'] ?? []),
            'updated_at'      => current_time('mysql'),
        ];
        if (empty($shipment->tracking_no)) {
            $shipment_update['tracking_no'] = $waybill_no;
        }

        $this->db->update('shipments', $shipment_update, ['id' => $shipment_id]);

        if (function_exists('qls_shipment')) {
            qls_shipment()->sync_order_shipment_state((int) $shipment->order_id);
        }

        do_action('qls_shop_waybill_created', (int) $log_id, $shipment_id, $waybill_no);

        return [
            'log_id'     => (int) $log_id,
            'shipment_id'=> $shipment_id,
            'waybill_no' => $waybill_no,
            'print_data' => $print_data,
        ];
    }

    /**
     * 新增电子面单日志。
     *
     * @param array $data
     * @return int|WP_Error
     */
    public function add_log($data) {
        if (!$this->table_exists($this->logs_table)) {
            return new WP_Error('missing_table', __('电子面单日志表不存在', 'qilingshop'));
        }

        $payload = [
            'shipment_id'   => isset($data['shipment_id']) ? (int) $data['shipment_id'] : 0,
            'order_id'      => isset($data['order_id']) ? (int) $data['order_id'] : 0,
            'provider'      => sanitize_key($data['provider'] ?? self::PROVIDER_KDNIAO),
            'company_code'  => sanitize_text_field($data['company_code'] ?? ''),
            'waybill_no'    => sanitize_text_field($data['waybill_no'] ?? ''),
            'request_data'  => isset($data['request_data']) ? (is_array($data['request_data']) ? wp_json_encode($data['request_data']) : (string) $data['request_data']) : null,
            'response_data' => isset($data['response_data']) ? (is_array($data['response_data']) ? wp_json_encode($data['response_data']) : (string) $data['response_data']) : null,
            'status'        => isset($data['status']) ? (int) $data['status'] : self::LOG_PENDING,
            'error_message' => isset($data['error_message']) ? sanitize_textarea_field($data['error_message']) : null,
            'created_at'    => current_time('mysql'),
        ];

        $log_id = $this->db->insert('waybill_logs', $payload);
        return $log_id ? (int) $log_id : new WP_Error('log_create_failed', __('电子面单日志保存失败', 'qilingshop'));
    }

    /**
     * 获取发货单的面单日志。
     *
     * @param int $shipment_id
     * @return array
     */
    public function get_logs_by_shipment($shipment_id) {
        $shipment_id = (int) $shipment_id;
        if ($shipment_id <= 0 || !$this->table_exists($this->logs_table)) {
            return [];
        }

        $rows = $this->db->get_results('waybill_logs', [
            'where'   => ['shipment_id' => $shipment_id],
            'orderby' => 'id',
            'order'   => 'DESC',
        ]);

        return array_map([$this, 'parse_log'], $rows);
    }

    /**
     * 获取面单日志。
     *
     * @param int $log_id
     * @return object|null
     */
    public function get_log($log_id) {
        $log_id = (int) $log_id;
        if ($log_id <= 0 || !$this->table_exists($this->logs_table)) {
            return null;
        }

        $log = $this->db->get_by_id('waybill_logs', $log_id);
        return $log ? $this->parse_log($log) : null;
    }

    /**
     * 获取最新成功面单日志。
     *
     * @param int $shipment_id
     * @return object|null
     */
    public function get_latest_success_log($shipment_id) {
        $shipment_id = (int) $shipment_id;
        if ($shipment_id <= 0 || !$this->table_exists($this->logs_table)) {
            return null;
        }

        $rows = $this->db->get_results('waybill_logs', [
            'where'   => [
                'shipment_id' => $shipment_id,
                'status'      => self::LOG_SUCCESS,
            ],
            'orderby' => 'id',
            'order'   => 'DESC',
            'limit'   => 1,
        ]);

        return !empty($rows) ? $this->parse_log($rows[0]) : null;
    }

    /**
     * 获取打印载荷。
     *
     * @param int $shipment_id
     * @param int $log_id
     * @return array|WP_Error
     */
    public function get_print_payload($shipment_id, $log_id = 0) {
        $shipment_id = (int) $shipment_id;
        $log_id = (int) $log_id;

        if (!function_exists('qls_shipment')) {
            return new WP_Error('shipment_service_missing', __('发货单服务不可用', 'qilingshop'));
        }

        $shipment = qls_shipment()->get($shipment_id, true);
        if (!$shipment) {
            return new WP_Error('shipment_missing', __('发货单不存在', 'qilingshop'));
        }

        $log = $log_id > 0 ? $this->get_log($log_id) : $this->get_latest_success_log($shipment_id);
        if ($log && !empty($log->response_data['print_data']) && is_array($log->response_data['print_data'])) {
            return [
                'shipment'               => $shipment,
                'log'                    => $log,
                'print_data'             => $log->response_data['print_data'],
                'provider_print_template'=> (string) ($log->response_data['provider_print_template'] ?? ''),
            ];
        }

        $waybill_no = sanitize_text_field($shipment->waybill_no ?? $shipment->tracking_no ?? '');
        if ($waybill_no === '') {
            return new WP_Error('missing_waybill', __('该发货单还没有可打印的电子面单', 'qilingshop'));
        }

        $template = $this->get_default_template(self::PROVIDER_KDNIAO, (int) ($shipment->shipping_company_id ?? 0));
        if (!$template) {
            return new WP_Error('missing_template', __('请选择可用的电子面单模板', 'qilingshop'));
        }

        $request_data = $this->build_request_data($shipment, $template, []);
        return [
            'shipment'   => $shipment,
            'log'        => $log,
            'print_data' => $this->build_print_data($shipment, $template, $request_data, $waybill_no),
        ];
    }

    /**
     * 清洗模板数据。
     *
     * @param array $data
     * @return array|WP_Error
     */
    private function sanitize_template_data($data) {
        $data = is_array($data) ? $data : [];
        $name = sanitize_text_field($data['name'] ?? '');
        if ($name === '') {
            return new WP_Error('missing_name', __('请填写模板名称', 'qilingshop'));
        }

        return [
            'name'             => $name,
            'provider'         => self::PROVIDER_KDNIAO,
            'company_id'       => isset($data['company_id']) ? (int) $data['company_id'] : 0,
            'sender_name'      => sanitize_text_field($data['sender_name'] ?? ''),
            'sender_phone'     => sanitize_text_field($data['sender_phone'] ?? ''),
            'sender_province'  => sanitize_text_field($data['sender_province'] ?? ''),
            'sender_city'      => sanitize_text_field($data['sender_city'] ?? ''),
            'sender_district'  => sanitize_text_field($data['sender_district'] ?? ''),
            'sender_address'   => sanitize_textarea_field($data['sender_address'] ?? ''),
            'template_config'  => isset($data['template_config']) ? (is_array($data['template_config']) ? wp_json_encode($this->sanitize_template_config($data['template_config'])) : (string) $data['template_config']) : null,
            'is_default'       => empty($data['is_default']) ? 0 : 1,
            'status'           => isset($data['status']) ? (int) $data['status'] : 1,
        ];
    }

    /**
     * 清洗模板配置。
     *
     * @param array $config
     * @return array
     */
    private function sanitize_template_config($config) {
        $config = is_array($config) ? $config : [];

        return [
            'sheet_size' => sanitize_text_field($config['sheet_size'] ?? '100x150'),
            'printer_no' => sanitize_text_field($config['printer_no'] ?? ''),
            'weight'     => max(0, (float) ($config['weight'] ?? 1)),
            'print_note' => sanitize_textarea_field($config['print_note'] ?? ''),
            'sender_company' => sanitize_text_field($config['sender_company'] ?? ''),
            'pay_type'   => max(1, (int) ($config['pay_type'] ?? 1)),
            'month_code' => sanitize_text_field($config['month_code'] ?? ''),
            'exp_type'   => max(1, (int) ($config['exp_type'] ?? 1)),
            'cost'       => max(0, (float) ($config['cost'] ?? 0)),
            'other_cost' => max(0, (float) ($config['other_cost'] ?? 0)),
            'volume'     => max(0, (float) ($config['volume'] ?? 0)),
        ];
    }

    /**
     * 调用阿里云市场快递鸟电子面单接口。
     *
     * @param array  $request_data
     * @param object $shipment
     * @param object $template
     * @return array|WP_Error
     */
    private function create_kdniao_response($request_data, $shipment, $template) {
        $appcode = trim((string) get_option('qls_shop_waybill_appcode', ''));
        if ($appcode === '') {
            return new WP_Error('kdniao_appcode_missing', __('请先配置快递鸟电子面单 AppCode', 'qilingshop'));
        }

        $config = is_array($template->template_config ?? null) ? $template->template_config : [];
        $sender = $request_data['sender'] ?? [];
        $receiver = $request_data['receiver'] ?? [];
        $company = $request_data['company'] ?? [];
        $items = !empty($shipment->items) && is_array($shipment->items) ? $shipment->items : [];
        $required_address_fields = ['name', 'phone', 'province', 'city', 'district', 'address'];
        $address_incomplete = false;
        foreach ($required_address_fields as $field) {
            if (empty($sender[$field]) || empty($receiver[$field])) {
                $address_incomplete = true;
                break;
            }
        }
        if (empty($company['code']) || $address_incomplete) {
            return new WP_Error('kdniao_required_data_missing', __('快递公司编码或收寄件信息不完整，请检查订单和电子面单模板', 'qilingshop'));
        }

        $commodity = [];
        foreach ($items as $item) {
            $commodity[] = [
                'GoodsName'     => sanitize_text_field((string) ($item->product_title ?? __('商品', 'qilingshop'))),
                'Goodsquantity' => max(1, (int) ($item->quantity ?? 1)),
                'GoodsWeight'   => (float) ($request_data['weight'] ?? 1),
            ];
        }
        if (empty($commodity) || empty($request_data['shipment']['shipment_no'])) {
            return new WP_Error('kdniao_order_data_missing', __('发货单号或商品信息不完整，无法创建电子面单', 'qilingshop'));
        }

        $body = [
            'OrderCode'   => (string) ($request_data['shipment']['shipment_no'] ?? $request_data['shipment']['order_no'] ?? ''),
            'ShipperCode' => sanitize_text_field((string) ($company['code'] ?? '')),
            'PayType'     => max(1, (int) ($config['pay_type'] ?? 1)),
            'MonthCode'   => sanitize_text_field((string) ($config['month_code'] ?? '')),
            'ExpType'     => max(1, (int) ($config['exp_type'] ?? 1)),
            'Cost'        => (float) ($config['cost'] ?? 0),
            'OtherCost'   => (float) ($config['other_cost'] ?? 0),
            'Sender'      => [
                'Company'     => sanitize_text_field((string) ($config['sender_company'] ?? '')),
                'Name'        => sanitize_text_field((string) ($sender['name'] ?? '')),
                'Mobile'      => sanitize_text_field((string) ($sender['phone'] ?? '')),
                'ProvinceName'=> sanitize_text_field((string) ($sender['province'] ?? '')),
                'CityName'    => sanitize_text_field((string) ($sender['city'] ?? '')),
                'ExpAreaName' => sanitize_text_field((string) ($sender['district'] ?? '')),
                'Address'     => sanitize_textarea_field((string) ($sender['address'] ?? '')),
            ],
            'Receiver' => [
                'Company'     => '',
                'Name'        => sanitize_text_field((string) ($receiver['name'] ?? '')),
                'Mobile'      => sanitize_text_field((string) ($receiver['phone'] ?? '')),
                'ProvinceName'=> sanitize_text_field((string) ($receiver['province'] ?? '')),
                'CityName'    => sanitize_text_field((string) ($receiver['city'] ?? '')),
                'ExpAreaName' => sanitize_text_field((string) ($receiver['district'] ?? '')),
                'Address'     => sanitize_textarea_field((string) ($receiver['address'] ?? '')),
            ],
            'Commodity' => $commodity,
            'Weight'    => (float) ($request_data['weight'] ?? 1),
            'Quantity'  => max(1, (int) ($request_data['count'] ?? 1)),
            'Volume'    => (float) ($config['volume'] ?? 0),
            'Remark'    => sanitize_textarea_field((string) ($request_data['remark'] ?? '')),
        ];

        $response = wp_remote_post('https://alicloudmarket1007.kdniao.com/api/order/create', [
            'headers' => [
                'Authorization' => 'APPCODE ' . $appcode,
                'Content-Type'  => 'application/json; charset=UTF-8',
            ],
            'body'    => wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'timeout' => 10,
        ]);
        if (is_wp_error($response)) {
            error_log('QilingShop Kdniao waybill request failed: ' . $response->get_error_message());
            return new WP_Error('kdniao_request_failed', __('电子面单服务连接失败，请稍后重试', 'qilingshop'));
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $payload = json_decode(wp_remote_retrieve_body($response), true);
        if ($status_code < 200 || $status_code >= 300 || !is_array($payload)) {
            error_log('QilingShop Kdniao waybill invalid response, HTTP ' . $status_code);
            return new WP_Error('kdniao_invalid_response', __('电子面单服务返回异常，请稍后重试', 'qilingshop'));
        }

        $success = !empty($payload['Success']) && (string) ($payload['ResultCode'] ?? '') === '100';
        $waybill_no = sanitize_text_field((string) ($payload['Order']['LogisticCode'] ?? ''));
        if (!$success || $waybill_no === '') {
            $reason = sanitize_text_field((string) ($payload['Reason'] ?? __('电子面单创建失败', 'qilingshop')));
            return new WP_Error('kdniao_create_failed', $reason);
        }

        $logged_response = $payload;
        unset($logged_response['PrintTemplate']);

        return [
            'waybill_no'             => $waybill_no,
            'provider_print_template'=> isset($payload['PrintTemplate']) ? (string) $payload['PrintTemplate'] : '',
            'raw_response'           => $logged_response,
            'print_data'             => $this->build_print_data($shipment, $template, $request_data, $waybill_no),
        ];
    }

    /**
     * 构建电子面单请求数据。
     *
     * @param object $shipment
     * @param object $template
     * @param array  $args
     * @return array
     */
    private function build_request_data($shipment, $template, $args = []) {
        $order = function_exists('qls_shop_order') ? qls_shop_order()->get((int) $shipment->order_id, true) : null;
        $items = !empty($shipment->items) && is_array($shipment->items) ? $shipment->items : [];
        $cargo = $this->build_cargo_summary($items);
        $count = 0;
        foreach ($items as $item) {
            $count += (int) ($item->quantity ?? 0);
        }

        $sender = [
            'name'     => (string) ($template->sender_name ?? ''),
            'phone'    => (string) ($template->sender_phone ?? ''),
            'province' => (string) ($template->sender_province ?? ''),
            'city'     => (string) ($template->sender_city ?? ''),
            'district' => (string) ($template->sender_district ?? ''),
            'address'  => (string) ($template->sender_address ?? ''),
        ];

        $receiver = [
            'name'     => (string) ($shipment->receiver_name ?? $order->receiver_name ?? ''),
            'phone'    => (string) ($shipment->receiver_phone ?? $order->receiver_phone ?? ''),
            'province' => (string) ($shipment->receiver_province ?? $order->receiver_province ?? ''),
            'city'     => (string) ($shipment->receiver_city ?? $order->receiver_city ?? ''),
            'district' => (string) ($shipment->receiver_district ?? $order->receiver_district ?? ''),
            'address'  => (string) ($shipment->receiver_address ?? $order->receiver_address ?? ''),
        ];

        return [
            'provider'    => self::PROVIDER_KDNIAO,
            'template_id' => (int) ($template->id ?? 0),
            'company'     => [
                'id'   => (int) ($shipment->shipping_company_id ?? $template->company_id ?? 0),
                'name' => (string) ($shipment->shipping_company ?? ''),
                'code' => (string) ($shipment->shipping_code ?? ''),
            ],
            'sender'      => $sender,
            'receiver'    => $receiver,
            'shipment'    => [
                'id'          => (int) ($shipment->id ?? 0),
                'shipment_no' => (string) ($shipment->shipment_no ?? ''),
                'order_id'    => (int) ($shipment->order_id ?? 0),
                'order_no'    => (string) ($shipment->order_no ?? $order->order_no ?? ''),
            ],
            'cargo'       => $cargo,
            'count'       => $count,
            'weight'      => max(0, (float) ($template->template_config['weight'] ?? 1)),
            'printer_no'  => (string) ($template->template_config['printer_no'] ?? ''),
            'remark'      => sanitize_textarea_field($args['remark'] ?? ($order->buyer_remark ?? '')),
        ];
    }

    /**
     * 构建打印数据。
     *
     * @param object $shipment
     * @param object $template
     * @param array  $request_data
     * @param string $waybill_no
     * @return array
     */
    private function build_print_data($shipment, $template, $request_data, $waybill_no) {
        $items = !empty($shipment->items) && is_array($shipment->items) ? $shipment->items : [];
        $print_items = [];
        foreach ($items as $item) {
            $attrs = $item->sku_attrs ?? '';
            if (is_array($attrs)) {
                $attrs = implode(' / ', array_filter(array_map('strval', $attrs)));
            }
            $print_items[] = [
                'title'    => (string) ($item->product_title ?? ''),
                'sku'      => (string) $attrs,
                'quantity' => (int) ($item->quantity ?? 0),
            ];
        }

        return [
            'waybill_no' => (string) $waybill_no,
            'provider'   => self::PROVIDER_KDNIAO,
            'template'   => [
                'id'         => (int) ($template->id ?? 0),
                'name'       => (string) ($template->name ?? ''),
                'sheet_size' => (string) ($template->template_config['sheet_size'] ?? '100x150'),
                'print_note' => (string) ($template->template_config['print_note'] ?? ''),
            ],
            'company'    => $request_data['company'] ?? [],
            'sender'     => $request_data['sender'] ?? [],
            'receiver'   => $request_data['receiver'] ?? [],
            'shipment'   => [
                'id'          => (int) ($shipment->id ?? 0),
                'shipment_no' => (string) ($shipment->shipment_no ?? ''),
                'order_no'    => (string) ($shipment->order_no ?? ''),
                'shipped_at'  => (string) ($shipment->shipped_at ?? ''),
            ],
            'cargo'      => (string) ($request_data['cargo'] ?? ''),
            'count'      => (int) ($request_data['count'] ?? 0),
            'weight'     => (float) ($request_data['weight'] ?? 0),
            'remark'     => (string) ($request_data['remark'] ?? ''),
            'items'      => $print_items,
            'created_at' => current_time('mysql'),
        ];
    }

    /**
     * 构建货品摘要。
     *
     * @param array $items
     * @return string
     */
    private function build_cargo_summary($items) {
        $lines = [];
        foreach ((array) $items as $item) {
            $title = trim((string) ($item->product_title ?? ''));
            if ($title === '') {
                $title = sprintf(__('商品 #%d', 'qilingshop'), (int) ($item->product_id ?? 0));
            }
            $lines[] = $title . '×' . max(1, (int) ($item->quantity ?? 1));
        }

        return implode('；', $lines);
    }

    /**
     * 解析模板。
     *
     * @param object $template
     * @return object
     */
    private function parse_template($template) {
        if (!empty($template->template_config) && is_string($template->template_config)) {
            $decoded = json_decode($template->template_config, true);
            $template->template_config = is_array($decoded) ? $decoded : [];
        } else {
            $template->template_config = [];
        }

        foreach (['id', 'company_id', 'is_default', 'status'] as $field) {
            if (isset($template->{$field})) {
                $template->{$field} = (int) $template->{$field};
            }
        }

        return $template;
    }

    /**
     * 解析日志。
     *
     * @param object $log
     * @return object
     */
    private function parse_log($log) {
        foreach (['request_data', 'response_data'] as $field) {
            if (!empty($log->{$field}) && is_string($log->{$field})) {
                $decoded = json_decode($log->{$field}, true);
                $log->{$field} = is_array($decoded) ? $decoded : [];
            } else {
                $log->{$field} = [];
            }
        }

        foreach (['id', 'shipment_id', 'order_id', 'status'] as $field) {
            if (isset($log->{$field})) {
                $log->{$field} = (int) $log->{$field};
            }
        }

        return $log;
    }

    /**
     * 判断表是否存在。
     *
     * @param string $table
     * @return bool
     */
    private function table_exists($table) {
        $wpdb = $this->db->get_wpdb();
        return (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    /**
     * 将旧版本地模板切换为快递鸟模板，保留寄件人与模板配置。
     */
    private function migrate_legacy_local_templates() {
        if ((string) get_option('qls_shop_waybill_provider', '') !== self::PROVIDER_KDNIAO) {
            update_option('qls_shop_waybill_provider', self::PROVIDER_KDNIAO);
        }

        if ((int) get_option('qls_shop_waybill_kdniao_migrated', 0) !== 1 && $this->table_exists($this->templates_table)) {
            $this->db->update('waybill_templates', ['provider' => self::PROVIDER_KDNIAO], ['provider' => 'manual']);
            update_option('qls_shop_waybill_kdniao_migrated', 1, false);
        }
    }
}

/**
 * 获取电子面单服务实例。
 *
 * @return QLS_Waybill
 */
function qls_waybill() {
    return QLS_Waybill::instance();
}
