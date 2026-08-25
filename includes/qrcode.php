<?php
/**
 * 二维码生成器
 *
 * @package QilingShop
 */

if (!isset($_GET['data']) || $_GET['data'] === '') {
    header('HTTP/1.1 400 Bad Request');
    exit;
}

$data = trim((string) $_GET['data']);
if ($data === '' || strlen($data) > 4096) {
    header('HTTP/1.1 400 Bad Request');
    exit;
}

$size = isset($_GET['size']) ? intval($_GET['size']) : 200;
$size = max(120, min(600, $size));

$margin = isset($_GET['margin']) ? intval($_GET['margin']) : 2;
$margin = max(0, min(8, $margin));

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$qrcode_lib = __DIR__ . '/phpqrcode/qrlib.php';
if (file_exists($qrcode_lib)) {
    require_once $qrcode_lib;

    $scale = max(4, min(12, (int) floor($size / 25)));
    header('Content-Type: image/png');
    QRcode::png($data, false, QR_ECLEVEL_L, $scale, $margin);
    exit;
}

http_response_code(503);
header('Content-Type: image/svg+xml; charset=UTF-8');
$missing_title = function_exists('esc_html__') ? esc_html__('二维码组件未安装', 'qilingshop') : htmlspecialchars('二维码组件未安装', ENT_QUOTES, 'UTF-8');
$missing_hint = function_exists('esc_html__') ? esc_html__('请部署 includes/phpqrcode/qrlib.php', 'qilingshop') : htmlspecialchars('请部署 includes/phpqrcode/qrlib.php', ENT_QUOTES, 'UTF-8');
echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<svg xmlns="http://www.w3.org/2000/svg" width="<?php echo (int) $size; ?>" height="<?php echo (int) $size; ?>" viewBox="0 0 <?php echo (int) $size; ?> <?php echo (int) $size; ?>">
    <rect width="100%" height="100%" fill="#f7f8fa"/>
    <rect x="12%" y="12%" width="76%" height="76%" rx="14" fill="#ffffff" stroke="#e5e7eb" stroke-width="2"/>
    <text x="50%" y="45%" text-anchor="middle" font-size="14" fill="#1f2937"><?php echo $missing_title; ?></text>
    <text x="50%" y="58%" text-anchor="middle" font-size="11" fill="#6b7280"><?php echo $missing_hint; ?></text>
</svg>
