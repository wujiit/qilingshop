<?php
/**
 * Product Grid Template Part
 * 
 * Used by shortcodes and modules to respond to grid layouts
 */
if (!defined('ABSPATH')) exit;

$columns = isset($columns) ? $columns : 4;
?>
<div class="qls-product-grid columns-<?php echo intval($columns); ?>">
    <?php foreach ($products as $product): 
        // Load product-card partial for each item
        qls_shop_public()->load_template('partials/product-card', ['product' => $product]);
    endforeach; ?>
</div>
