<?php
/**
 * 钩子加载器
 * 
 * 管理所有 action 和 filter 的注册
 *
 * @package QilingShop
 * @since   1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class QilingShop_Loader {

    /**
     * 单例实例
     *
     * @var QilingShop_Loader
     */
    private static $instance = null;

    /**
     * 要注册的 actions 数组
     *
     * @var array
     */
    protected $actions = [];

    /**
     * 要注册的 filters 数组
     *
     * @var array
     */
    protected $filters = [];

    /**
     * 获取单例实例
     *
     * @return QilingShop_Loader
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
        // 私有构造函数，防止外部实例化
    }

    /**
     * 添加一个 action 到注册队列
     *
     * @param string $hook          钩子名称
     * @param object $component     回调所属的对象实例
     * @param string $callback      回调方法名
     * @param int    $priority      优先级
     * @param int    $accepted_args 接受的参数数量
     */
    public function add_action($hook, $component, $callback, $priority = 10, $accepted_args = 1) {
        $this->actions = $this->add($this->actions, $hook, $component, $callback, $priority, $accepted_args);
    }

    /**
     * 添加一个 filter 到注册队列
     *
     * @param string $hook          钩子名称
     * @param object $component     回调所属的对象实例
     * @param string $callback      回调方法名
     * @param int    $priority      优先级
     * @param int    $accepted_args 接受的参数数量
     */
    public function add_filter($hook, $component, $callback, $priority = 10, $accepted_args = 1) {
        $this->filters = $this->add($this->filters, $hook, $component, $callback, $priority, $accepted_args);
    }

    /**
     * 添加钩子到数组
     *
     * @param array  $hooks         现有钩子数组
     * @param string $hook          钩子名称
     * @param object $component     回调所属的对象实例
     * @param string $callback      回调方法名
     * @param int    $priority      优先级
     * @param int    $accepted_args 接受的参数数量
     * @return array 更新后的钩子数组
     */
    private function add($hooks, $hook, $component, $callback, $priority, $accepted_args) {
        $hooks[] = [
            'hook'          => $hook,
            'component'     => $component,
            'callback'      => $callback,
            'priority'      => $priority,
            'accepted_args' => $accepted_args
        ];
        return $hooks;
    }

    /**
     * 注册所有的 actions 和 filters
     */
    public function run() {
        foreach ($this->filters as $hook) {
            add_filter(
                $hook['hook'],
                [$hook['component'], $hook['callback']],
                $hook['priority'],
                $hook['accepted_args']
            );
        }

        foreach ($this->actions as $hook) {
            add_action(
                $hook['hook'],
                [$hook['component'], $hook['callback']],
                $hook['priority'],
                $hook['accepted_args']
            );
        }
    }

    /**
     * 移除一个 action
     *
     * @param string $hook     钩子名称
     * @param object $component 回调所属的对象实例
     * @param string $callback 回调方法名
     * @param int    $priority 优先级
     */
    public function remove_action($hook, $component, $callback, $priority = 10) {
        remove_action($hook, [$component, $callback], $priority);
    }

    /**
     * 移除一个 filter
     *
     * @param string $hook     钩子名称
     * @param object $component 回调所属的对象实例
     * @param string $callback 回调方法名
     * @param int    $priority 优先级
     */
    public function remove_filter($hook, $component, $callback, $priority = 10) {
        remove_filter($hook, [$component, $callback], $priority);
    }
}
