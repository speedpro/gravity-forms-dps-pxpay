<?php
if (!defined('ABSPATH')) {
	exit;
}
?>

<div class="error notice is-dismissible">
	<p><?php esc_html_e('Gravity Forms DPS PxPay needs to update your data for the new version. Your forms will not process payments until updates have completed!', 'gravity-forms-dps-pxpay'); ?></p>

	<ul style="padding-left: 2em">

		<?php if ($this->update_feeds): ?>
		<li style="list-style-type:disc" id="gfdpspxpay-updatev1-feeds">
			<?php printf(_n('%s feed needs updating', '%s feeds need updating', $this->update_feeds, 'gravity-forms-dps-pxpay'), number_format_i18n($this->update_feeds, 0)); ?>
		</li>
		<?php endif; ?>

		<?php if ($this->update_transactions): ?>
		<li style="list-style-type:disc" id="gfdpspxpay-updatev1-txns">
			<?php printf(_n('%s transaction needs updating', '%s transactions need updating', $this->update_transactions, 'gravity-forms-dps-pxpay'), number_format_i18n($this->update_transactions, 0)); ?>
		</li>
		<?php endif; ?>

	</ul>

	<p><button id="gfdpspxpay-upgrade"><?php esc_html_e('Run updates', 'gravity-forms-dps-pxpay'); ?></button></p>

	<div id="gfdpspxpay-updatev1-progress"><div></div></div>
</div>
