<?php
/**
 * Express Logistics Handler
 *
 * Handles API integration with Lingjian Express Query Interface
 * 
 * @package QilingShop
 */

if (!defined('ABSPATH')) {
    exit;
}

class QLS_Shop_Logistics {

    /**
     * Instance
     */
    private static $instance = null;
    
    /**
     * Get instance
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private $api_endpoint = 'https://api.jingxialai.com/api/express';

    /**
     * Get logistics trace
     *
     * @param string $company_name e.g. "顺丰速运"
     * @param string $tracking_no  e.g. "SF123456"
     * @param string $phone        Required for SF, YTO, etc.
     * @return array|WP_Error
     */
    public function get_trace($company_name, $tracking_no, $phone = '') {
        if (empty($tracking_no)) {
            return new WP_Error('no_tracking_no', __('物流单号为空', 'qilingshop'));
        }

        $tracking_no = sanitize_text_field((string) $tracking_no);
        $phone = sanitize_text_field((string) $phone);
        $mobile = preg_replace('/\D+/', '', $phone);
        if ($mobile !== '' && strlen($mobile) > 4) {
            $mobile = substr($mobile, -4);
        }
        $express_code = $this->get_company_code($company_name);

        // 1. Check Cache
        $cache_key = 'qls_logistics_v2_' . md5($tracking_no . '|' . $mobile . '|' . $express_code);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        // 2. Get API Key
        $api_key = trim((string) get_option('qls_shop_express_api_key', ''));
        if ($api_key === '' || !preg_match('/^ip_live_[A-Za-z0-9]{32}$/', $api_key)) {
            return new WP_Error('no_api_key', __('未配置有效的灵简 API Key', 'qilingshop'));
        }

        // 3. Prepare request according to the Lingjian API contract.
        $headers = [
            'X-API-Key' => $api_key,
            'Accept'    => 'application/json',
        ];

        $query = [
            'number' => $tracking_no,
            'sort'   => 'desc',
        ];
        if ($mobile !== '') {
            $query['mobile'] = $mobile;
        }
        if ($express_code !== '') {
            $query['expressCode'] = $express_code;
        }

        // 4. Execute Request
        $response = wp_remote_get(add_query_arg($query, $this->api_endpoint), [
            'headers' => $headers,
            'timeout' => 15,
            'sslverify' => true,
        ]);

        if (is_wp_error($response)) {
            error_log('QilingShop Lingjian logistics request failed: ' . $response->get_error_message());
            return new WP_Error('logistics_request_failed', __('物流服务连接失败，请稍后重试', 'qilingshop'));
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $result = json_decode($response_body, true);

        // 5. Parse Result
        if ($response_code === 200 && is_array($result) && (int) ($result['code'] ?? 0) === 200 && isset($result['data']) && is_array($result['data'])) {
            $data = $this->sanitize_trace_data($result['data'], $tracking_no, $company_name);
            
            // Determine cache time
            // If "logisticsStatus" is "SIGN" (Signed), cache for longer (e.g. 7 days) as it won't change.
            // Otherwise cache for 1-3 hours.
            $cache_time = 3 * HOUR_IN_SECONDS;
            if (isset($data['logisticsStatus']) && $data['logisticsStatus'] === 'SIGN') {
                $cache_time = 7 * DAY_IN_SECONDS;
            }

            set_transient($cache_key, $data, $cache_time);
            return $data;
        } else {
            $msg = is_array($result) ? ($result['message'] ?? $result['msg'] ?? '') : '';
            $msg = sanitize_text_field((string) $msg);
            if ($msg === '') {
                $msg = __('物流服务暂不可用，请稍后重试', 'qilingshop');
            }
            error_log('QilingShop Lingjian logistics API error, HTTP ' . (int) $response_code);
            return new WP_Error('api_error', $msg);
        }
    }

    /**
     * Sanitize the remote response while preserving the existing storefront contract.
     *
     * @param array  $data
     * @param string $tracking_no
     * @param string $company_name
     * @return array
     */
    private function sanitize_trace_data($data, $tracking_no, $company_name) {
        $data = is_array($data) ? $data : [];
        $clean = [];
        $scalar_fields = [
            'expressCode', 'expressCompanyName', 'number', 'logisticsStatus',
            'logisticsStatusDesc', 'theLastMessage', 'theLastTime', 'takeTime',
            'courier', 'courierPhone',
        ];
        foreach ($scalar_fields as $field) {
            $clean[$field] = sanitize_text_field((string) ($data[$field] ?? ''));
        }

        $clean['number'] = $clean['number'] !== '' ? $clean['number'] : sanitize_text_field((string) $tracking_no);
        $clean['expressCompanyName'] = $clean['expressCompanyName'] !== '' ? $clean['expressCompanyName'] : sanitize_text_field((string) $company_name);
        $clean['logisticsTraceDetails'] = [];

        foreach ((array) ($data['logisticsTraceDetails'] ?? []) as $trace) {
            if (!is_array($trace)) {
                continue;
            }
            $clean['logisticsTraceDetails'][] = [
                'time'               => sanitize_text_field((string) ($trace['time'] ?? '')),
                'desc'               => sanitize_text_field((string) ($trace['desc'] ?? '')),
                'logisticsStatus'    => sanitize_text_field((string) ($trace['logisticsStatus'] ?? '')),
                'subLogisticsStatus' => sanitize_text_field((string) ($trace['subLogisticsStatus'] ?? '')),
            ];
        }

        return $clean;
    }

    /**
     * Resolve the optional Lingjian expressCode from the configured carrier.
     *
     * @param string $company_name
     * @return string
     */
    private function get_company_code($company_name) {
        if (!function_exists('qls_shipping_company')) {
            return '';
        }

        $company = qls_shipping_company()->find(sanitize_text_field((string) $company_name));
        if (!$company || empty($company->code)) {
            return '';
        }

        return sanitize_text_field((string) $company->code);
    }

}

/**
 * Global accessor
 */
function qls_shop_logistics() {
    return QLS_Shop_Logistics::instance();
}
