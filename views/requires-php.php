<?php
if (!defined('ABSPATH')) {
	exit;
}
?>

<div class="error">
	<p>Gravity Forms DPS PxPay requires PHP <?php echo esc_html($php_min); ?> or higher; your website has PHP <?php echo esc_html(PHP_VERSION); ?>
		which is <a target="_blank" href="http://php.net/eol.php">old, obsolete, and unsupported</a>.</p>
	<p>Please upgrade your website hosting. At least PHP 5.6 is recommended.</p>
</div>
