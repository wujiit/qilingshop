<?php
/**
 * 商城轻量售后工单服务。
 *
 * @package QilingShop
 */

if (!defined('ABSPATH')) {
    exit;
}

class QLS_Shop_Ticket {
    const STATUS_OPEN         = 0;
    const STATUS_PROCESSING   = 1;
    const STATUS_WAITING_USER = 2;
    const STATUS_RESOLVED     = 3;
    const STATUS_CLOSED       = 4;

    const MAX_ATTACHMENTS = 6;
    const MAX_ATTACHMENT_SIZE = 5242880;
    const DEFAULT_ATTACHMENT_MAX_COUNT = 3;
    const DEFAULT_ATTACHMENT_MAX_SIZE_MB = 5;

    /**
     * 单例实例。
     *
     * @var QLS_Shop_Ticket|null
     */
    private static $instance = null;

    /**
     * 商城数据库封装。
     *
     * @var QLS_Shop_Database
     */
    private $db;

    /**
     * 获取单例。
     *
     * @return QLS_Shop_Ticket
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * 构造函数。
     */
    private function __construct() {
        $this->db = QLS_Shop_Database::instance();
    }

    /**
     * 工单状态。
     *
     * @return array<int,string>
     */
    public function get_statuses() {
        return [
            self::STATUS_OPEN         => __('待处理', 'qilingshop'),
            self::STATUS_PROCESSING   => __('处理中', 'qilingshop'),
            self::STATUS_WAITING_USER => __('待用户回复', 'qilingshop'),
            self::STATUS_RESOLVED     => __('已解决', 'qilingshop'),
            self::STATUS_CLOSED       => __('已关闭', 'qilingshop'),
        ];
    }

    /**
     * 工单类型。
     *
     * @return array<string,string>
     */
    public function get_types() {
        return [
            'order_consult'    => __('订单咨询', 'qilingshop'),
            'resource_invalid' => __('资源失效', 'qilingshop'),
            'card_invalid'     => __('卡密不可用', 'qilingshop'),
            'logistics'        => __('物流问题', 'qilingshop'),
            'invoice'          => __('发票问题', 'qilingshop'),
            'other'            => __('其他问题', 'qilingshop'),
        ];
    }

    /**
     * 状态文案。
     *
     * @param int $status 状态。
     * @return string
     */
    public function get_status_text($status) {
        $statuses = $this->get_statuses();
        $status = (int) $status;

        return isset($statuses[$status]) ? $statuses[$status] : __('未知状态', 'qilingshop');
    }

    /**
     * 状态样式。
     *
     * @param int $status 状态。
     * @return string
     */
    public function get_status_badge_class($status) {
        $classes = [
            self::STATUS_OPEN         => 'is-open',
            self::STATUS_PROCESSING   => 'is-processing',
            self::STATUS_WAITING_USER => 'is-waiting',
            self::STATUS_RESOLVED     => 'is-resolved',
            self::STATUS_CLOSED       => 'is-closed',
        ];

        $status = (int) $status;
        return isset($classes[$status]) ? $classes[$status] : 'is-unknown';
    }

    /**
     * 类型文案。
     *
     * @param string $type 类型。
     * @return string
     */
    public function get_type_text($type) {
        $types = $this->get_types();
        $type = sanitize_key((string) $type);

        return isset($types[$type]) ? $types[$type] : __('其他问题', 'qilingshop');
    }

    /**
     * 附件类型选项。
     *
     * @return array<string,array>
     */
    public function get_attachment_type_options() {
        return [
            'jpg' => [
                'label' => __('JPG/JPEG 图片', 'qilingshop'),
                'mimes' => ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'],
            ],
            'png' => [
                'label' => __('PNG 图片', 'qilingshop'),
                'mimes' => ['png' => 'image/png'],
            ],
            'webp' => [
                'label' => __('WebP 图片', 'qilingshop'),
                'mimes' => ['webp' => 'image/webp'],
            ],
            'pdf' => [
                'label' => __('PDF 文件', 'qilingshop'),
                'mimes' => ['pdf' => 'application/pdf'],
            ],
        ];
    }

    /**
     * 允许上传的附件类型 key。
     *
     * @return string[]
     */
    public function get_allowed_attachment_type_keys() {
        $options = $this->get_attachment_type_options();
        $default = ['jpg', 'png', 'webp', 'pdf'];
        $saved = get_option('qilingshop_shop_ticket_attachment_types', $default);
        $saved = is_array($saved) ? $saved : [];
        $allowed = [];

        foreach ($saved as $type_key) {
            $type_key = sanitize_key((string) $type_key);
            if (isset($options[$type_key])) {
                $allowed[] = $type_key;
            }
        }

        return !empty($allowed) ? array_values(array_unique($allowed)) : $default;
    }

    /**
     * 最大附件数量。
     *
     * @return int
     */
    public function get_max_attachment_count() {
        $count = absint(get_option('qilingshop_shop_ticket_attachment_max_count', self::DEFAULT_ATTACHMENT_MAX_COUNT));
        return max(0, min(10, $count));
    }

    /**
     * 单个附件最大字节数。
     *
     * @return int
     */
    public function get_max_attachment_size() {
        $size_mb = (float) get_option('qilingshop_shop_ticket_attachment_max_size', self::DEFAULT_ATTACHMENT_MAX_SIZE_MB);
        $size_mb = max(1, min(20, $size_mb));
        $bytes_per_mb = defined('MB_IN_BYTES') ? MB_IN_BYTES : 1048576;
        return (int) round($size_mb * $bytes_per_mb);
    }

    /**
     * 前台 file accept 属性。
     *
     * @return string
     */
    public function get_attachment_accept_attribute() {
        $mimes = array_values(array_unique(array_values($this->get_allowed_attachment_mimes())));
        return implode(',', $mimes);
    }

    /**
     * 附件上传提示。
     *
     * @return string
     */
    public function get_attachment_help_text() {
        $max_count = $this->get_max_attachment_count();
        $max_size = $this->get_max_attachment_size();
        $labels = [];
        $type_options = $this->get_attachment_type_options();

        foreach ($this->get_allowed_attachment_type_keys() as $type_key) {
            if (isset($type_options[$type_key]['label'])) {
                $labels[] = wp_strip_all_tags((string) $type_options[$type_key]['label']);
            }
        }

        if ($max_count <= 0) {
            return __('当前未开放附件上传。', 'qilingshop');
        }

        return sprintf(
            __('最多 %1$d 个，单个不超过 %2$s，支持：%3$s。', 'qilingshop'),
            $max_count,
            size_format($max_size),
            implode('、', $labels)
        );
    }

    /**
     * 创建工单。
     *
     * @param int   $user_id 用户 ID。
     * @param array $data    工单数据。
     * @return int|WP_Error
     */
    public function create_ticket($user_id, $data) {
        $user_id = absint($user_id);
        if ($user_id <= 0) {
            return new WP_Error('qls_ticket_login_required', __('请先登录后提交工单', 'qilingshop'));
        }

        $data = wp_parse_args((array) $data, [
            'type'        => 'other',
            'title'       => '',
            'content'     => '',
            'order_id'    => 0,
            'product_id'  => 0,
            'resource_id' => 0,
            'card_id'     => 0,
            'source_type' => '',
            'source_id'   => 0,
            'priority'    => 'normal',
            'attachments'  => [],
        ]);

        $type = $this->sanitize_type($data['type']);
        $title = trim(sanitize_text_field((string) $data['title']));
        $content = trim(sanitize_textarea_field((string) $data['content']));

        if ($title === '') {
            return new WP_Error('qls_ticket_empty_title', __('请填写工单标题', 'qilingshop'));
        }

        if ($content === '') {
            return new WP_Error('qls_ticket_empty_content', __('请填写问题说明', 'qilingshop'));
        }

        $order_id = absint($data['order_id']);
        $product_id = absint($data['product_id']);
        $resource_id = absint($data['resource_id']);
        $card_id = absint($data['card_id']);
        $source_type = sanitize_key((string) $data['source_type']);
        $source_id = absint($data['source_id']);
        $priority = $this->sanitize_priority($data['priority']);
        $attachments = $this->sanitize_attachments($data['attachments']);
        $order = null;

        if ($order_id > 0) {
            $order = $this->get_order_for_user($order_id, $user_id);
            if (is_wp_error($order)) {
                return $order;
            }

            if ($product_id <= 0) {
                $product_id = $this->infer_product_id_from_order($order);
            }
        }

        if ($source_type === '' && $order_id > 0) {
            $source_type = 'order';
            $source_id = $order_id;
        }

        $now = current_time('mysql');
        $ticket_data = [
            'ticket_no'     => $this->generate_ticket_no(),
            'user_id'       => $user_id,
            'order_id'      => $order_id,
            'product_id'    => $product_id,
            'resource_id'   => $resource_id,
            'card_id'       => $card_id,
            'source_type'   => $source_type,
            'source_id'     => $source_id,
            'type'          => $type,
            'title'         => $title,
            'content'       => $content,
            'status'        => self::STATUS_OPEN,
            'priority'      => $priority,
            'last_reply_by' => 'user',
            'last_reply_at' => $now,
            'created_at'    => $now,
            'updated_at'    => $now,
        ];

        $this->db->begin_transaction();

        try {
            $ticket_id = $this->db->insert('tickets', $ticket_data);
            if (!$ticket_id) {
                throw new Exception(__('工单创建失败，请稍后重试', 'qilingshop'));
            }

            $message_id = $this->insert_message((int) $ticket_id, $user_id, 'user', $content, false, $attachments);
            if (!$message_id) {
                throw new Exception(__('工单消息写入失败，请稍后重试', 'qilingshop'));
            }

            $this->db->commit();

            do_action('qls_shop_ticket_created', (int) $ticket_id, $ticket_data);

            return (int) $ticket_id;
        } catch (Exception $e) {
            $this->db->rollback();
            return new WP_Error('qls_ticket_create_failed', $e->getMessage());
        }
    }

    /**
     * 用户回复工单。
     *
     * @param int    $ticket_id 工单 ID。
     * @param int    $user_id   用户 ID。
     * @param string $message   回复内容。
     * @param array  $attachments 附件。
     * @return bool|WP_Error
     */
    public function reply_ticket($ticket_id, $user_id, $message, $attachments = []) {
        $ticket_id = absint($ticket_id);
        $user_id = absint($user_id);
        $message = trim(sanitize_textarea_field((string) $message));
        $attachments = $this->sanitize_attachments($attachments);

        if ($message === '' && empty($attachments)) {
            return new WP_Error('qls_ticket_empty_reply', __('请填写回复内容', 'qilingshop'));
        }

        $ticket = $this->get_ticket($ticket_id);
        if (!$ticket) {
            return new WP_Error('qls_ticket_not_found', __('工单不存在', 'qilingshop'));
        }

        if ((int) $ticket->user_id !== $user_id) {
            return new WP_Error('qls_ticket_forbidden', __('无权回复该工单', 'qilingshop'));
        }

        if ((int) $ticket->status === self::STATUS_CLOSED) {
            return new WP_Error('qls_ticket_closed', __('工单已关闭，无法继续回复', 'qilingshop'));
        }

        $now = current_time('mysql');
        $this->db->begin_transaction();

        try {
            $message_id = $this->insert_message($ticket_id, $user_id, 'user', $message, false, $attachments);
            if (!$message_id) {
                throw new Exception(__('回复失败，请稍后重试', 'qilingshop'));
            }

            $update = [
                'status'        => self::STATUS_OPEN,
                'last_reply_by' => 'user',
                'last_reply_at' => $now,
                'updated_at'    => $now,
            ];

            if ((int) $ticket->status === self::STATUS_RESOLVED) {
                $update['resolved_at'] = null;
            }

            $updated = $this->db->update('tickets', $update, ['id' => $ticket_id]);
            if ($updated === false) {
                throw new Exception(__('工单状态更新失败，请稍后重试', 'qilingshop'));
            }

            $this->db->commit();

            do_action('qls_shop_ticket_user_replied', $ticket_id, $user_id);

            return true;
        } catch (Exception $e) {
            $this->db->rollback();
            return new WP_Error('qls_ticket_reply_failed', $e->getMessage());
        }
    }

    /**
     * 管理员回复/更新工单。
     *
     * @param int         $ticket_id     工单 ID。
     * @param int         $admin_id      管理员 ID。
     * @param string      $message       回复内容。
     * @param int|null    $status        目标状态。
     * @param string      $internal_note 内部备注。
     * @param array       $attachments   附件。
     * @return bool|WP_Error
     */
    public function admin_reply($ticket_id, $admin_id, $message = '', $status = null, $internal_note = '', $attachments = []) {
        $ticket_id = absint($ticket_id);
        $admin_id = absint($admin_id);
        $message = trim(sanitize_textarea_field((string) $message));
        $internal_note = trim(sanitize_textarea_field((string) $internal_note));
        $attachments = $this->sanitize_attachments($attachments);

        $ticket = $this->get_ticket($ticket_id);
        if (!$ticket) {
            return new WP_Error('qls_ticket_not_found', __('工单不存在', 'qilingshop'));
        }

        $target_status = is_null($status)
            ? ((int) $ticket->status)
            : $this->sanitize_status($status, (int) $ticket->status);

        if (($message !== '' || !empty($attachments)) && is_null($status)) {
            $target_status = self::STATUS_WAITING_USER;
        }

        if ($message === '' && empty($attachments) && $internal_note === '' && $target_status === (int) $ticket->status) {
            return new WP_Error('qls_ticket_empty_update', __('请填写回复、内部备注或调整状态', 'qilingshop'));
        }

        $now = current_time('mysql');
        $this->db->begin_transaction();

        try {
            if ($message !== '' || !empty($attachments)) {
                $message_id = $this->insert_message($ticket_id, $admin_id, 'admin', $message, false, $attachments);
                if (!$message_id) {
                    throw new Exception(__('回复写入失败，请稍后重试', 'qilingshop'));
                }
            }

            if ($internal_note !== '') {
                $note_id = $this->insert_message($ticket_id, $admin_id, 'admin', $internal_note, true);
                if (!$note_id) {
                    throw new Exception(__('内部备注写入失败，请稍后重试', 'qilingshop'));
                }
            }

            $update = [
                'status'     => $target_status,
                'updated_at' => $now,
            ];

            if ($message !== '' || !empty($attachments)) {
                $update['last_reply_by'] = 'admin';
                $update['last_reply_at'] = $now;
            }

            if ($target_status === self::STATUS_RESOLVED) {
                $update['resolved_at'] = $now;
                $update['closed_at'] = null;
            } elseif ($target_status === self::STATUS_CLOSED) {
                $update['closed_at'] = $now;
            } elseif ($target_status < self::STATUS_RESOLVED) {
                $update['resolved_at'] = null;
                $update['closed_at'] = null;
            }

            $updated = $this->db->update('tickets', $update, ['id' => $ticket_id]);
            if ($updated === false) {
                throw new Exception(__('工单更新失败，请稍后重试', 'qilingshop'));
            }

            $this->db->commit();

            $has_user_visible_update = $message !== '' || !empty($attachments) || $target_status !== (int) $ticket->status;
            do_action('qls_shop_ticket_admin_replied', $ticket_id, $admin_id, $target_status, $has_user_visible_update);

            return true;
        } catch (Exception $e) {
            $this->db->rollback();
            return new WP_Error('qls_ticket_admin_reply_failed', $e->getMessage());
        }
    }

    /**
     * 获取单个工单。
     *
     * @param int $ticket_id 工单 ID。
     * @return object|null
     */
    public function get_ticket($ticket_id) {
        $ticket_id = absint($ticket_id);
        if ($ticket_id <= 0) {
            return null;
        }

        return $this->db->get_by_id('tickets', $ticket_id);
    }

    /**
     * 获取用户自己的工单。
     *
     * @param int $ticket_id 工单 ID。
     * @param int $user_id   用户 ID。
     * @return object|null
     */
    public function get_user_ticket($ticket_id, $user_id) {
        $ticket_id = absint($ticket_id);
        $user_id = absint($user_id);
        if ($ticket_id <= 0 || $user_id <= 0) {
            return null;
        }

        return $this->db->get_row('tickets', [
            'id'      => $ticket_id,
            'user_id' => $user_id,
        ]);
    }

    /**
     * 获取工单消息。
     *
     * @param int  $ticket_id        工单 ID。
     * @param bool $include_internal 是否包含内部备注。
     * @return array
     */
    public function get_messages($ticket_id, $include_internal = false) {
        $ticket_id = absint($ticket_id);
        if ($ticket_id <= 0) {
            return [];
        }

        $where = ['ticket_id' => $ticket_id];
        if (!$include_internal) {
            $where['is_internal'] = 0;
        }

        return $this->db->get_results('ticket_messages', [
            'where'   => $where,
            'orderby' => 'id',
            'order'   => 'ASC',
        ]);
    }

    /**
     * 获取消息附件。
     *
     * @param object|array|string $message 消息对象、附件数组或 JSON 字符串。
     * @return array
     */
    public function get_message_attachments($message) {
        $raw = $message;
        if (is_object($message) && isset($message->attachments)) {
            $raw = $message->attachments;
        } elseif (is_array($message) && isset($message['attachments'])) {
            $raw = $message['attachments'];
        }

        return $this->sanitize_attachments($raw);
    }

    /**
     * 清洗附件列表。
     *
     * @param mixed $attachments 附件数据。
     * @return array
     */
    public function sanitize_attachments($attachments) {
        if (is_string($attachments)) {
            $attachments = trim($attachments);
            if ($attachments === '') {
                return [];
            }

            $decoded = json_decode($attachments, true);
            $attachments = is_array($decoded) ? $decoded : preg_split('/[\s,]+/', $attachments);
        }

        if (!is_array($attachments)) {
            return [];
        }

        $result = [];
        $display_limit = max(self::MAX_ATTACHMENTS, $this->get_max_attachment_count());
        foreach ($attachments as $attachment) {
            if (count($result) >= $display_limit) {
                break;
            }

            $formatted = null;
            if (is_numeric($attachment)) {
                $formatted = $this->format_attachment_by_id(absint($attachment));
            } elseif (is_string($attachment)) {
                $formatted = $this->format_attachment_from_url($attachment);
            } elseif (is_array($attachment)) {
                $attachment_id = isset($attachment['id'])
                    ? absint($attachment['id'])
                    : (isset($attachment['attachment_id']) ? absint($attachment['attachment_id']) : 0);

                if ($attachment_id > 0) {
                    $formatted = $this->format_attachment_by_id($attachment_id);
                } else {
                    $url = isset($attachment['url']) ? (string) $attachment['url'] : '';
                    $formatted = $this->format_attachment_from_url($url, $attachment);
                }
            }

            if (!empty($formatted)) {
                $result[] = $formatted;
            }
        }

        return $result;
    }

    /**
     * 收集表单上传附件。
     *
     * @param string $field_name 文件字段名。
     * @param int    $actor_id   上传用户。
     * @param array  $context    上下文。
     * @return array|WP_Error
     */
    public function collect_uploaded_attachments($field_name = 'ticket_attachments', $actor_id = 0, $context = []) {
        if (empty($_FILES[$field_name]) || empty($_FILES[$field_name]['name'])) {
            return [];
        }

        $files = $this->normalize_uploaded_files($_FILES[$field_name]);
        $files = array_values(array_filter($files, function ($file) {
            return isset($file['error']) && (int) $file['error'] !== UPLOAD_ERR_NO_FILE;
        }));

        if (empty($files)) {
            return [];
        }

        $max_count = $this->get_max_attachment_count();
        if ($max_count <= 0) {
            return new WP_Error(
                'qls_ticket_attachment_disabled',
                __('当前未开放附件上传', 'qilingshop')
            );
        }

        if (count($files) > $max_count) {
            return new WP_Error(
                'qls_ticket_attachment_too_many',
                sprintf(__('最多只能上传 %d 个附件', 'qilingshop'), $max_count)
            );
        }

        $attachments = [];
        foreach ($files as $file) {
            $uploaded = $this->upload_ticket_attachment($file, $actor_id, $context);
            if (is_wp_error($uploaded)) {
                return $uploaded;
            }

            if (!empty($uploaded)) {
                $attachments[] = $uploaded;
            }
        }

        return $attachments;
    }

    /**
     * 查询工单列表。
     *
     * @param array $args 查询参数。
     * @return array
     */
    public function get_list($args = []) {
        $args = wp_parse_args((array) $args, [
            'status'  => '',
            'type'    => '',
            'user_id' => 0,
            'order_id'=> 0,
            'keyword' => '',
            'limit'   => 20,
            'offset'  => 0,
            'orderby' => 'updated_at',
            'order'   => 'DESC',
        ]);

        $wpdb = $this->db->get_wpdb();
        $table = $this->db->get_table('tickets');
        list($where, $params) = $this->build_query_conditions($args);

        $orderby = $this->sanitize_orderby($args['orderby']);
        $order = strtoupper((string) $args['order']) === 'ASC' ? 'ASC' : 'DESC';
        $limit = max(0, (int) $args['limit']);
        $offset = max(0, (int) $args['offset']);

        $sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $where) . " ORDER BY {$orderby} {$order}";
        if ($limit > 0) {
            $sql .= ' LIMIT %d OFFSET %d';
            $params[] = $limit;
            $params[] = $offset;
        }

        return $wpdb->get_results($this->prepare_sql($sql, $params));
    }

    /**
     * 统计工单数。
     *
     * @param array $args 查询参数。
     * @return int
     */
    public function get_count($args = []) {
        $wpdb = $this->db->get_wpdb();
        $table = $this->db->get_table('tickets');
        list($where, $params) = $this->build_query_conditions((array) $args);

        $sql = "SELECT COUNT(*) FROM {$table} WHERE " . implode(' AND ', $where);
        return (int) $wpdb->get_var($this->prepare_sql($sql, $params));
    }

    /**
     * 按状态统计工单。
     *
     * @param array $args 查询参数。
     * @return array<int,int>
     */
    public function get_status_counts($args = []) {
        $args = (array) $args;
        unset($args['status']);

        $wpdb = $this->db->get_wpdb();
        $table = $this->db->get_table('tickets');
        list($where, $params) = $this->build_query_conditions($args);

        $sql = "SELECT status, COUNT(*) AS total FROM {$table} WHERE " . implode(' AND ', $where) . ' GROUP BY status';
        $rows = $wpdb->get_results($this->prepare_sql($sql, $params));

        $counts = [];
        foreach ($this->get_statuses() as $status => $label) {
            $counts[(int) $status] = 0;
        }

        foreach ((array) $rows as $row) {
            $counts[(int) $row->status] = (int) $row->total;
        }

        return $counts;
    }

    /**
     * 获取用户最近订单，用于工单表单选择。
     *
     * @param int $user_id 用户 ID。
     * @param int $limit   数量。
     * @return array
     */
    public function get_recent_orders_for_user($user_id, $limit = 20) {
        $user_id = absint($user_id);
        if ($user_id <= 0 || !function_exists('qls_shop_order')) {
            return [];
        }

        return qls_shop_order()->get_user_orders($user_id, [
            'limit'  => max(1, (int) $limit),
            'offset' => 0,
        ]);
    }

    /**
     * 获取订单上下文。
     *
     * @param int $order_id 订单 ID。
     * @param int $user_id  用户 ID，传入后校验归属。
     * @return object|null
     */
    public function get_order_context($order_id, $user_id = 0) {
        $order_id = absint($order_id);
        if ($order_id <= 0 || !function_exists('qls_shop_order')) {
            return null;
        }

        $order = qls_shop_order()->get($order_id, true);
        if (!$order) {
            return null;
        }

        $user_id = absint($user_id);
        if ($user_id > 0 && (int) $order->user_id !== $user_id) {
            return null;
        }

        return $order;
    }

    /**
     * 清洗工单类型。
     *
     * @param mixed $type 类型。
     * @return string
     */
    public function sanitize_type($type) {
        $type = sanitize_key((string) $type);
        $types = $this->get_types();

        return isset($types[$type]) ? $type : 'other';
    }

    /**
     * 清洗状态。
     *
     * @param mixed $status  状态。
     * @param int   $default 默认状态。
     * @return int
     */
    public function sanitize_status($status, $default = self::STATUS_OPEN) {
        $status = (int) $status;
        $statuses = $this->get_statuses();

        return isset($statuses[$status]) ? $status : (int) $default;
    }

    /**
     * 查询条件。
     *
     * @param array $args 查询参数。
     * @return array{0:array,1:array}
     */
    private function build_query_conditions($args) {
        $args = wp_parse_args((array) $args, [
            'status'  => '',
            'type'    => '',
            'user_id' => 0,
            'order_id'=> 0,
            'keyword' => '',
        ]);

        $where = ['1=1'];
        $params = [];

        if ($args['status'] !== '') {
            $statuses = is_array($args['status']) ? array_map('intval', $args['status']) : [(int) $args['status']];
            $statuses = array_values(array_intersect($statuses, array_keys($this->get_statuses())));
            if (!empty($statuses)) {
                $where[] = 'status IN (' . implode(', ', array_fill(0, count($statuses), '%d')) . ')';
                foreach ($statuses as $status) {
                    $params[] = (int) $status;
                }
            }
        }

        $type = $this->sanitize_type($args['type']);
        if ((string) $args['type'] !== '' && $type !== '') {
            $where[] = 'type = %s';
            $params[] = $type;
        }

        $user_id = absint($args['user_id']);
        if ($user_id > 0) {
            $where[] = 'user_id = %d';
            $params[] = $user_id;
        }

        $order_id = absint($args['order_id']);
        if ($order_id > 0) {
            $where[] = 'order_id = %d';
            $params[] = $order_id;
        }

        $keyword = trim(sanitize_text_field((string) $args['keyword']));
        if ($keyword !== '') {
            $wpdb = $this->db->get_wpdb();
            $like = '%' . $wpdb->esc_like($keyword) . '%';
            $where[] = '(ticket_no LIKE %s OR title LIKE %s OR content LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        return [$where, $params];
    }

    /**
     * 写入一条消息。
     *
     * @param int    $ticket_id    工单 ID。
     * @param int    $author_id    作者 ID。
     * @param string $sender_type  发送方。
     * @param string $message      内容。
     * @param bool   $is_internal  是否内部备注。
     * @param array  $attachments  附件。
     * @return int|false
     */
    private function insert_message($ticket_id, $author_id, $sender_type, $message, $is_internal = false, $attachments = []) {
        $sender_type = sanitize_key((string) $sender_type);
        if (!in_array($sender_type, ['user', 'admin', 'system'], true)) {
            $sender_type = 'system';
        }

        $attachments = $this->sanitize_attachments($attachments);
        $attachments_value = '';
        if (!empty($attachments)) {
            $attachments_value = wp_json_encode(array_values((array) $attachments), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $this->db->insert('ticket_messages', [
            'ticket_id'   => absint($ticket_id),
            'author_id'   => absint($author_id),
            'sender_type' => $sender_type,
            'message'     => (string) $message,
            'attachments' => $attachments_value,
            'is_internal' => $is_internal ? 1 : 0,
            'created_at'  => current_time('mysql'),
        ]);
    }

    /**
     * 规范化多文件上传数组。
     *
     * @param array $file_input $_FILES 中的文件字段。
     * @return array
     */
    private function normalize_uploaded_files($file_input) {
        if (!is_array($file_input) || !isset($file_input['name'])) {
            return [];
        }

        if (!is_array($file_input['name'])) {
            return [$file_input];
        }

        $files = [];
        foreach ($file_input['name'] as $index => $name) {
            $files[] = [
                'name'     => $name,
                'type'     => isset($file_input['type'][$index]) ? $file_input['type'][$index] : '',
                'tmp_name' => isset($file_input['tmp_name'][$index]) ? $file_input['tmp_name'][$index] : '',
                'error'    => isset($file_input['error'][$index]) ? $file_input['error'][$index] : UPLOAD_ERR_NO_FILE,
                'size'     => isset($file_input['size'][$index]) ? $file_input['size'][$index] : 0,
            ];
        }

        return $files;
    }

    /**
     * 上传单个工单附件。
     *
     * @param array $file     文件数据。
     * @param int   $actor_id 上传用户。
     * @param array $context  上下文。
     * @return array|WP_Error
     */
    private function upload_ticket_attachment($file, $actor_id = 0, $context = []) {
        $error = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
        if ($error !== UPLOAD_ERR_OK) {
            return new WP_Error('qls_ticket_attachment_upload_failed', $this->get_upload_error_message($error));
        }

        $size = isset($file['size']) ? (int) $file['size'] : 0;
        $max_size = $this->get_max_attachment_size();
        if ($size <= 0 || $size > $max_size) {
            return new WP_Error(
                'qls_ticket_attachment_size',
                sprintf(__('单个附件不能超过 %s', 'qilingshop'), size_format($max_size))
            );
        }

        $file['name'] = sanitize_file_name((string) ($file['name'] ?? ''));
        if ($file['name'] === '' || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return new WP_Error('qls_ticket_attachment_invalid', __('附件文件无效', 'qilingshop'));
        }

        $allowed_mimes = $this->get_allowed_attachment_mimes();
        $checked = wp_check_filetype_and_ext($file['tmp_name'], $file['name'], $allowed_mimes);
        $mime = isset($checked['type']) ? (string) $checked['type'] : '';
        $ext = isset($checked['ext']) ? (string) $checked['ext'] : '';

        if ($ext === '' || $mime === '' || !isset($allowed_mimes[$ext]) || $allowed_mimes[$ext] !== $mime) {
            return new WP_Error('qls_ticket_attachment_type', __('附件类型不符合后台设置', 'qilingshop'));
        }

        if (strpos($mime, 'image/') === 0) {
            $image_info = @getimagesize($file['tmp_name']);
            $real_mime = is_array($image_info) && !empty($image_info['mime'])
                ? sanitize_mime_type((string) $image_info['mime'])
                : '';
            if ($real_mime === '' || $real_mime !== $mime) {
                return new WP_Error('qls_ticket_attachment_image', __('图片附件真实性校验失败', 'qilingshop'));
            }
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachment_id = media_handle_sideload($file, 0, null, [
            'post_mime_type' => $mime,
        ]);

        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }

        update_post_meta((int) $attachment_id, '_qls_shop_ticket_attachment', 1);
        update_post_meta((int) $attachment_id, '_qls_shop_ticket_actor_id', absint($actor_id));
        if (!empty($context)) {
            update_post_meta((int) $attachment_id, '_qls_shop_ticket_context', wp_json_encode((array) $context));
        }

        $attachment = $this->format_attachment_by_id((int) $attachment_id);
        if (empty($attachment)) {
            return new WP_Error('qls_ticket_attachment_saved_invalid', __('附件保存失败，请稍后重试', 'qilingshop'));
        }

        return $attachment;
    }

    /**
     * 附件允许的 MIME。
     *
     * @return array<string,string>
     */
    private function get_allowed_attachment_mimes() {
        $type_options = $this->get_attachment_type_options();
        $mimes = [];

        foreach ($this->get_allowed_attachment_type_keys() as $type_key) {
            if (empty($type_options[$type_key]['mimes']) || !is_array($type_options[$type_key]['mimes'])) {
                continue;
            }

            foreach ($type_options[$type_key]['mimes'] as $ext => $mime) {
                $ext = sanitize_key((string) $ext);
                $mime = sanitize_mime_type((string) $mime);
                if ($ext !== '' && $mime !== '') {
                    $mimes[$ext] = $mime;
                }
            }
        }

        return !empty($mimes) ? $mimes : [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'webp' => 'image/webp',
        ];
    }

    /**
     * 根据媒体 ID 格式化附件。
     *
     * @param int $attachment_id 媒体 ID。
     * @return array|null
     */
    private function format_attachment_by_id($attachment_id) {
        $attachment_id = absint($attachment_id);
        if ($attachment_id <= 0) {
            return null;
        }

        $url = wp_get_attachment_url($attachment_id);
        if (!$url) {
            return null;
        }

        $mime = (string) get_post_mime_type($attachment_id);
        $name = get_the_title($attachment_id);
        if ($name === '') {
            $path = parse_url($url, PHP_URL_PATH);
            $name = $path ? basename($path) : sprintf(__('附件 #%d', 'qilingshop'), $attachment_id);
        }

        return [
            'id'   => $attachment_id,
            'url'  => esc_url_raw($url),
            'name' => sanitize_text_field($name),
            'type' => sanitize_mime_type($mime),
        ];
    }

    /**
     * 根据 URL 格式化附件。
     *
     * @param string $url      附件地址。
     * @param array  $metadata 附件元数据。
     * @return array|null
     */
    private function format_attachment_from_url($url, $metadata = []) {
        $url = esc_url_raw((string) $url);
        if ($url === '') {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);
        $ext = $path ? strtolower(pathinfo($path, PATHINFO_EXTENSION)) : '';
        $allowed_mimes = $this->get_allowed_attachment_mimes();
        if ($ext === '' || !isset($allowed_mimes[$ext])) {
            return null;
        }

        $name = isset($metadata['name']) ? sanitize_file_name((string) $metadata['name']) : '';
        if ($name === '') {
            $name = $path ? sanitize_file_name(basename($path)) : __('附件', 'qilingshop');
        }

        $type = isset($metadata['type']) ? sanitize_mime_type((string) $metadata['type']) : '';
        if ($type === '' || !in_array($type, $allowed_mimes, true)) {
            $type = $allowed_mimes[$ext];
        }

        return [
            'id'   => 0,
            'url'  => $url,
            'name' => $name,
            'type' => $type,
        ];
    }

    /**
     * 上传错误文案。
     *
     * @param int $error 上传错误码。
     * @return string
     */
    private function get_upload_error_message($error) {
        switch ((int) $error) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return __('附件超过服务器允许大小', 'qilingshop');
            case UPLOAD_ERR_PARTIAL:
                return __('附件上传不完整，请重试', 'qilingshop');
            case UPLOAD_ERR_NO_TMP_DIR:
            case UPLOAD_ERR_CANT_WRITE:
                return __('服务器暂时无法保存附件', 'qilingshop');
            case UPLOAD_ERR_EXTENSION:
                return __('附件上传被服务器拦截', 'qilingshop');
            default:
                return __('附件上传失败，请重试', 'qilingshop');
        }
    }

    /**
     * 校验订单归属。
     *
     * @param int $order_id 订单 ID。
     * @param int $user_id  用户 ID。
     * @return object|WP_Error
     */
    private function get_order_for_user($order_id, $user_id) {
        if (!function_exists('qls_shop_order')) {
            return new WP_Error('qls_ticket_order_unavailable', __('订单模块不可用', 'qilingshop'));
        }

        $order = qls_shop_order()->get(absint($order_id), true);
        if (!$order) {
            return new WP_Error('qls_ticket_order_not_found', __('关联订单不存在', 'qilingshop'));
        }

        if ((int) $order->user_id !== absint($user_id)) {
            return new WP_Error('qls_ticket_order_forbidden', __('不能关联不属于你的订单', 'qilingshop'));
        }

        return $order;
    }

    /**
     * 从订单中推断商品 ID。
     *
     * @param object $order 订单。
     * @return int
     */
    private function infer_product_id_from_order($order) {
        if (!is_object($order) || empty($order->items) || !is_array($order->items)) {
            return 0;
        }

        foreach ($order->items as $item) {
            $product_id = isset($item->product_id) ? absint($item->product_id) : 0;
            if ($product_id > 0) {
                return $product_id;
            }
        }

        return 0;
    }

    /**
     * 生成工单号。
     *
     * @return string
     */
    private function generate_ticket_no() {
        for ($i = 0; $i < 8; $i++) {
            $ticket_no = 'TK' . current_time('YmdHis') . strtoupper(wp_generate_password(4, false, false));
            $exists = $this->db->get_row('tickets', ['ticket_no' => $ticket_no]);
            if (!$exists) {
                return $ticket_no;
            }
        }

        return 'TK' . current_time('YmdHis') . wp_rand(100000, 999999);
    }

    /**
     * 清洗优先级。
     *
     * @param mixed $priority 优先级。
     * @return string
     */
    private function sanitize_priority($priority) {
        $priority = sanitize_key((string) $priority);
        return in_array($priority, ['low', 'normal', 'high'], true) ? $priority : 'normal';
    }

    /**
     * 排序字段白名单。
     *
     * @param mixed $orderby 排序字段。
     * @return string
     */
    private function sanitize_orderby($orderby) {
        $orderby = sanitize_key((string) $orderby);
        $allowed = [
            'id'            => '`id`',
            'created_at'    => '`created_at`',
            'updated_at'    => '`updated_at`',
            'last_reply_at' => '`last_reply_at`',
            'status'        => '`status`',
        ];

        return isset($allowed[$orderby]) ? $allowed[$orderby] : '`updated_at`';
    }

    /**
     * 安全 prepare。
     *
     * @param string $sql    SQL。
     * @param array  $params 参数。
     * @return string
     */
    private function prepare_sql($sql, $params) {
        if (empty($params)) {
            return $sql;
        }

        $wpdb = $this->db->get_wpdb();
        return call_user_func_array([$wpdb, 'prepare'], array_merge([$sql], $params));
    }
}

/**
 * 获取工单服务实例。
 *
 * @return QLS_Shop_Ticket
 */
function qls_shop_ticket() {
    return QLS_Shop_Ticket::instance();
}
