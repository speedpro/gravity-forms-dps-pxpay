<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
* implement a Gravity Forms Payment Add-on instance
*/
class GFDpsPxPayAddOn extends GFPaymentAddOn {

	protected $validationMessages;						// any validation messages picked up for the form as a whole
	protected $urlPaymentForm;							// URL for payment form where purchaser will enter credit card details
	protected $feed = null;								// current feed mapping form fields to payment fields
	protected $feedDefaultFieldMap;						// map of default fields for feed

	const META_UNIQUE_ID					= 'gfdpspxpay_unique_id';	// unique form submission
	const META_TRANSACTION_ID				= 'gfdpspxpay_txn_id';		// merchant's transaction ID (invoice number, etc.)
	const META_GATEWAY_TXN_ID				= 'gateway_txn_id';			// duplicate of transaction_id to enable passing to integrations (e.g. Zapier)
	const META_FEED_ID						= 'gfdpspxpay_feed_id';		// link to feed under which the transaction was processed
	const META_AUTHCODE						= 'authcode';				// bank authorisation code

	// end points for return to website
	const ENDPOINT_RETURN					= '__gfpxpayreturn';
	const ENDPOINT_CONFIRMATION				= '__gfpxpayconfirm';
	const ENDPOINT_HASH						= '__gfpxpayhash';

	/**
	* static method for getting the instance of this singleton object
	* @return self
	*/
	public static function get_instance() {
		static $instance = null;

		if (is_null($instance)) {
			$instance = new self();
		}

		return $instance;
	}

	/**
	* declare detail to GF Add-On framework
	*/
	public function __construct() {
		$this->_version						= GFDPSPXPAY_PLUGIN_VERSION;
		$this->_min_gravityforms_version	= GFDpsPxPayPlugin::MIN_VERSION_GF;
		$this->_slug						= 'gravity-forms-dps-pxpay';
		$this->_path						= GFDPSPXPAY_PLUGIN_NAME;
		$this->_full_path					= GFDPSPXPAY_PLUGIN_FILE;
		$this->_title						= 'DPS PxPay';					// NB: no localisation yet
		$this->_short_title					= 'DPS PxPay';					// NB: no localisation yet
		$this->_supports_callbacks			= true;

// TODO: remove once upgrade routines are working well
//~ delete_option("gravityformsaddon_{$this->_slug}_version");

		parent::__construct();

		add_action('init', array($this, 'lateLocalise'), 50);
		add_filter('gform_validation_message', array($this, 'gformValidationMessage'), 10, 2);
		add_filter('gform_custom_merge_tags', array($this, 'gformCustomMergeTags'), 10, 4);
		add_filter('gform_replace_merge_tags', array($this, 'gformReplaceMergeTags'), 10, 7);
		add_action('wp', array($this, 'processFormConfirmation'), 5);		// process redirect to GF confirmation

		// handle the new Payment Details box
		add_action('gform_payment_details', array($this, 'gformPaymentDetails'), 10, 2);

		// handle deferrals
		add_filter('gform_is_delayed_pre_process_feed', array($this, 'gformIsDelayed'), 10, 4);
		add_filter('gform_disable_post_creation', array($this, 'gformDelayPost'), 10, 3);
	}

	/**
	* late localisation of strings, after load_plugin_textdomain() has been called
	*/
	public function lateLocalise() {
		$this->_title			= esc_html_x('DPS PxPay', 'add-on full title', 'gravity-forms-dps-pxpay');
		$this->_short_title		= esc_html_x('DPS PxPay', 'add-on short title', 'gravity-forms-dps-pxpay');
	}

	/**
	* add our admin initialisation
	*/
	public function init_admin() {
		parent::init_admin();

		add_action('gform_payment_status', array($this, 'gformPaymentStatus' ), 10, 3);
		add_action('gform_after_update_entry', array($this, 'gformAfterUpdateEntry' ), 10, 2);
	}

	/**
	* plugin version has changed, run upgrade procedues
	*/
	public function upgrade($previous_version) {
		if (empty($previous_version)) {
			// might be v1.x add-on, needs upgrade of everything
			$old_settings = get_option('gfdpspxpay_plugin');
			if (is_array($old_settings)) {
				require GFDPSPXPAY_PLUGIN_ROOT . 'includes/class.GFDpsPxPayUpdateV1.php';
				new GFDpsPxPayUpdateV1($this->_slug);
			}
		}
	}

	/**
	* enqueue required styles
	*/
	public function styles() {
		$ver = SCRIPT_DEBUG ? time() : GFDPSPXPAY_PLUGIN_VERSION;

		$styles = array(

			array(
				'handle'		=> 'gfdpspxpay_admin',
				'src'			=> plugins_url('css/admin.css', GFDPSPXPAY_PLUGIN_FILE),
				'version'		=> $ver,
				'enqueue'		=> array(
										array(
											'admin_page'	=> array('plugin_settings', 'form_settings'),
											'tab'			=> array($this->_slug),
										),
									),
			),

		);

		return array_merge(parent::styles(), $styles);
	}

	/**
	* enqueue required scripts
	*/
	public function scripts() {
		$min = SCRIPT_DEBUG ? '' : '.min';
		$ver = SCRIPT_DEBUG ? time() : GFDPSPXPAY_PLUGIN_VERSION;

		$scripts = array(

			//~ array(
				//~ 'handle'		=> 'gfdpspxpay_feed_admin',
				//~ 'src'			=> plugins_url("js/feed-admin$min.js", GFDPSPXPAY_PLUGIN_FILE),
				//~ 'version'		=> $ver,
				//~ 'deps'			=> array('jquery'),
				//~ 'in_footer'		=> true,
				//~ 'enqueue'		=> array( array(
										//~ 'admin_page'	=> array('form_settings'),
										//~ 'tab'			=> array($this->_slug),
									//~ )),
			//~ ),

		);

		return array_merge(parent::scripts(), $scripts);
	}

	/**
	* set full title of add-on as settings page title
	* @return string
	*/
	public function plugin_settings_title() {
		return esc_html__('DPS Payment Express PxPay settings', 'gravity-forms-dps-pxpay');
	}

	/**
	* set icon for settings page
	* @return string
	*/
	public function plugin_settings_icon() {
		return '<i class="fa fa-credit-card" aria-hidden="true"></i>';
	}

	/**
	* specify the settings fields to be rendered on the plugin settings page
	* @return array
	*/
	public function plugin_settings_fields() {
		$settings = array (
			array (
				'title'					=> esc_html__('Live gateway settings', 'gravity-forms-dps-pxpay'),
				'fields'				=> array (

					array (
						'name'			=> 'userID',
						'label'			=> esc_html_x('User ID', 'feed field name', 'gravity-forms-dps-pxpay'),
						'type'			=> 'text',
						'class'			=> 'medium',
					),

					array (
						'name'			=> 'userKey',
						'label'			=> esc_html_x('User Key', 'feed field name', 'gravity-forms-dps-pxpay'),
						'type'			=> 'text',
						'class'			=> 'large',
					),

				),
			),

			array (
				'title'					=> esc_html__('Sandbox gateway settings', 'gravity-forms-dps-pxpay'),
				'description'			=> esc_html__('When your feed is configured for Test (Sandbox), it will send transactions to this gateway instead of the live gateway.', 'gravity-forms-dps-pxpay'),
				'fields'				=> array (

					array (
						'name'			=> 'testEnv',
						'label'			=> esc_html_x('Sandbox Environment', 'feed field name', 'gravity-forms-dps-pxpay'),
						'type'			=> 'radio',
						'tooltip'		=> esc_html__('When DPS Payment Express sent you your user ID and password, they will have told you to use either SEC or UAT for your sandbox.', 'gravity-forms-dps-pxpay'),
						'choices'		=> array(
							array('value' => 'SEC', 'label' => 'SEC'),
							array('value' => 'UAT', 'label' => 'UAT'),
						),
						'default_value'	=> 'UAT',
					),

					array (
						'name'			=> 'testID',
						'label'			=> esc_html_x('Sandbox User ID', 'feed field name', 'gravity-forms-dps-pxpay'),
						'type'			=> 'text',
						'class'			=> 'medium',
					),

					array (
						'name'			=> 'testKey',
						'label'			=> esc_html_x('Sandbox User Key', 'feed field name', 'gravity-forms-dps-pxpay'),
						'type'			=> 'text',
						'class'			=> 'large',
					),

					array(
						'type'			=> 'save',
						'messages'		=> array('success' => esc_html__('Settings updated', 'gravity-forms-dps-pxpay')),
					)

				),
			),
		);

		return $settings;
	}

	/**
	* title of feed settings
	* @return string
	*/
	public function feed_settings_title() {
		return esc_html__('DPS Payment Express PxPay transaction settings', 'gravity-forms-dps-pxpay');
	}

	/**
	* columns to display in list of feeds
	* @return array
	*/
	public function feed_list_columns() {
		$columns = array(
			'feedName'				=> esc_html_x('Feed name', 'feed field name', 'gravity-forms-dps-pxpay'),
			'feedItem_useTest'		=> esc_html_x('Mode', 'payment transaction mode', 'gravity-forms-dps-pxpay'),
		);

		return $columns;
	}

	/**
	* feed list value for payment mode
	* @param array $item
	* @return string
	*/
	protected function get_column_value_feedItem_useTest($item) {
		switch (rgars($item, 'meta/useTest')) {

			case '0':
				$value = esc_html_x('Live', 'payment transaction mode', 'gravity-forms-dps-pxpay');
				break;

			case '1':
				$value = esc_html_x('Test', 'payment transaction mode', 'gravity-forms-dps-pxpay');
				break;

			default:
				$value = '';
				break;

		}

		return $value;
	}

	/**
	* configure the fields in a feed
	* @return array
	*/
	public function feed_settings_fields() {
		$this->setFeedDefaultFieldMap();

		$fields = array(

			#region "core settings"

			array(
				'fields' => array(

					array(
						'name'   		=> 'feedName',
						'label'  		=> esc_html_x('Feed name', 'feed field name', 'gravity-forms-dps-pxpay'),
						'type'   		=> 'text',
						'class'			=> 'medium',
						'tooltip'		=> esc_html__('Give this feed a name, to differentiate it from other feeds.', 'gravity-forms-dps-pxpay'),
						'required'		=> '1',
					),

					array(
						'name'   		=> 'useTest',
						'label'  		=> esc_html_x('Mode', 'payment transaction mode', 'gravity-forms-dps-pxpay'),
						'type'   		=> 'radio',
						'tooltip'		=> esc_html__('Credit cards will not be processed in Test mode. Special card numbers must be used.', 'gravity-forms-dps-pxpay'),
						'choices'		=> array(
							array('value' => '0', 'label' => esc_html_x('Live', 'payment transaction mode', 'gravity-forms-dps-pxpay')),
							array('value' => '1', 'label' => esc_html_x('Test', 'payment transaction mode', 'gravity-forms-dps-pxpay')),
						),
						'default_value'	=> '1',
					),

					array(
						'name'   		=> 'transactionType',
						'type'   		=> 'hidden',
						'default_value'	=> 'product',
					),

				),
			),

			#endregion "core settings"

			#region "mapped fields"

			array(
				'title'					=> esc_html__('Mapped Field Settings', 'gravity-forms-dps-pxpay'),
				'fields'				=> array(

					array(
						'name'			=> 'billingInformation',
						'type'			=> 'field_map',
						'field_map'		=> $this->billing_info_fields(),
					),

				),
			),

			#endregion "mapped fields"

			#region "hosted page settings"

			array(
				'title'					=> esc_html__('Hosted Page Settings', 'gravity-forms-dps-pxpay'),
				'id'					=> 'gfdpspxpay-settings-shared',
				'fields'				=> array(

					array(
						'name'			=> 'cancelURL',
						'label'			=> esc_html_x('Cancel URL', 'feed field name', 'gravity-forms-dps-pxpay'),
						'type'			=> 'text',
						'class'  		=> 'large',
						'placeholder'	=> esc_html_x('Leave empty to use default Gravity Forms confirmation handler', 'field placeholder', 'gravity-forms-dps-pxpay'),
						'tooltip'		=> __('Redirect to this URL if the transaction is canceled.', 'gravity-forms-dps-pxpay')
										.  '<br/><br/>'
										.  __('Please note: standard Gravity Forms submission logic applies if the transaction is successful.', 'gravity-forms-dps-pxpay'),
					),

					array(
						'name'			=> 'delayPost',
						'label'			=> esc_html_x('Create Post', 'feed field name', 'gravity-forms-dps-pxpay'),
						'type'			=> 'checkbox',
						'choices'		=> array(
							array('name' => 'delayPost', 'label' => esc_html__('Create post only when transaction completes', 'gravity-forms-dps-pxpay')),
						),
					),

					array(
						'name'			=> 'delayMailchimp',
						'label'			=> esc_html_x('MailChimp Subscription', 'feed field name', 'gravity-forms-dps-pxpay'),
						'type'			=> 'checkbox',
						'choices'		=> array(
							array('name' => 'delayMailchimp', 'label' => esc_html__('Subscribe user to MailChimp only when transaction completes', 'gravity-forms-dps-pxpay')),
						),
					),

					array(
						'name'			=> 'delayUserrego',
						'label'			=> esc_html_x('User Registration', 'feed field name', 'gravity-forms-dps-pxpay'),
						'type'			=> 'checkbox',
						'choices'		=> array(
							array('name' => 'delayUserrego', 'label' => esc_html__('Register user only when transaction completes', 'gravity-forms-dps-pxpay')),
						),
					),

					array(
						'name'			=> 'execDelayed',
						'label'			=> esc_html_x('Delayed Execute', 'feed field name', 'gravity-forms-dps-pxpay'),
						'type'   		=> 'radio',
						'tooltip'		=> __('The delayed actions above will be processed according to these options.', 'gravity-forms-dps-pxpay'),
						'choices'		=> array(
							array('value' => 'success',       'label' => esc_html_x('Execute delayed actions for successful payment, or if no payment is required', 'delayed execute mode', 'gravity-forms-dps-pxpay')),
							array('value' => 'always',        'label' => esc_html_x('Always execute delayed actions, regardless of payment status', 'delayed execute mode', 'gravity-forms-dps-pxpay')),
							array('value' => 'ignore_nofeed', 'label' => esc_html_x("Don't execute delayed actions if no payment is required", 'delayed execute mode', 'gravity-forms-dps-pxpay')),
						),
						'default_value'	=> 'success',
					),

				),
			),

			#endregion "hosted page settings"

			#region "conditional processing settings"

			array(
				'title'					=> esc_html__('Feed Conditions', 'gravity-forms-dps-pxpay'),
				'fields'				=> array(

					array(
						'name'			=> 'condition',
						'label'			=> esc_html_x('Payment Express condition', 'feed field name', 'gravity-forms-dps-pxpay'),
						'type'			=> 'feed_condition',
						'checkbox_label' => 'Enable',
						'instructions'	=> esc_html_x('Send to Payment Express if', 'feed conditions', 'gravity-forms-dps-pxpay'),
						'tooltip'		=> esc_html__('When the Payment Express condition is enabled, form submissions will only be sent to Payment Express when the condition is met. When disabled, all form submissions will be sent to Payment Express.', 'gravity-forms-dps-pxpay'),
					),

				),
			),

			#endregion "conditional processing settings"

		);

		return $fields;
	}

	/**
	* title of fields column for mapped fields
	* @return string
	*/
	public function field_map_title() {
		return esc_html_x('PxPay field', 'mapped fields title', 'gravity-forms-dps-pxpay');
	}

	/**
	* build map of field types to fields, for default field mappings
	*/
	protected function setFeedDefaultFieldMap() {
		$this->feedDefaultFieldMap = array();

		$form_id = rgget( 'id' );
		$form = RGFormsModel::get_form_meta( $form_id );

		if (!isset($this->feedDefaultFieldMap['billingInformation_description'])) {
			$this->feedDefaultFieldMap['billingInformation_description']			= 'form_title';
		}

		if (is_array($form['fields'])) {
			foreach ($form['fields'] as $field) {

				switch ($field->type) {

					case 'email':
						if (!isset($this->feedDefaultFieldMap['billingInformation_email'])) {
							$this->feedDefaultFieldMap['billingInformation_email']			= $field->id;
						}
						break;

				}
			}
		}
	}

	/**
	 * Prepend the name fields to the default billing_info_fields added by the framework.
	 *
	 * @return array
	 */
	public function billing_info_fields() {
		$fields = array(
			array(
				'name' => 'description',
				'label' => esc_html_x('Invoice Description', 'mapped field name', 'gravity-forms-dps-pxpay'),
				'required' => false,
			),
			array(
				'name' => 'txn_data1',
				'label' => esc_html_x('TxnData1', 'mapped field name', 'gravity-forms-dps-pxpay'),
				'required' => false,
			),
			array(
				'name' => 'txn_data2',
				'label' => esc_html_x('TxnData2', 'mapped field name', 'gravity-forms-dps-pxpay'),
				'required' => false,
			),
			array(
				'name' => 'txn_data3',
				'label' => esc_html_x('TxnData3', 'mapped field name', 'gravity-forms-dps-pxpay'),
				'required' => false,
			),
			array(
				'name' => 'email',
				'label' => esc_html_x('Email Address', 'mapped field name', 'gravity-forms-dps-pxpay'),
				'required' => false,
			),
		);

		return $fields;
	}

	/**
	* override to set default mapped field selections from first occurring field of type
	* @param  array $field
	* @return string|null
	*/
	public function get_default_field_select_field($field) {
		if (!empty($this->feedDefaultFieldMap[$field['name']])) {
			return $this->feedDefaultFieldMap[$field['name']];
		}

		return parent::get_default_field_select_field($field);
	}

	/**
	* test form for product fields
	* @param array $form
	* @return bool
	*/
	protected static function hasProductFields($form) {
		foreach ($form['fields'] as $field) {
			if ($field->type === 'shipping' || $field->type === 'product') {
				return true;
			}
		}

		return false;
	}

	/**
	* process form validation
	* @param array $data an array with elements is_valid (boolean) and form (array of form elements)
	* @return array
	*/
	public function validation($data) {
		try {
			$data = parent::validation($data);

			if ($data['is_valid'] && $this->is_payment_gateway) {
				$form  = $data['form'];

				// make sure form hasn't already been submitted / processed
				if ($this->hasFormBeenProcessed($form)) {
					throw new GFDpsPxPayException(__('Payment already submitted and processed - please close your browser window.', 'gravity-forms-dps-pxpay'));
				}

				// set hook to request redirect URL
				add_filter('gform_entry_post_save', array( $this, 'requestRedirectUrl' ), 9, 2);
			}
		}
		catch (GFDpsPxPayException $e) {
			$data['is_valid'] = false;
			$this->validationMessages[] = nl2br(esc_html($e->getMessage()));
		}

		return $data;
	}

	/**
	* pre-process feeds to ensure that they have necessary gateway credentials
	* @param array $feeds
	* @param array $entry
	* @param array $form
	* @return array
	* @throws GFDpsPxPayException
	*/
	public function pre_process_feeds($feeds, $entry, $form) {
		foreach ($feeds as $feed) {
			// feed must be active and meet feed conditions, if any
			if (!$feed['is_active'] || !$this->is_feed_condition_met($feed, $form, array())) {
				continue;
			}

			// make sure that gateway credentials have been set for feed, or globally
			$creds = new GFDpsPxPayCredentials($this, $feed);
			if ($creds->isIncomplete()) {
				throw new GFDpsPxPayException(__('Incomplete credentials for Payment Express PxPay payment; please tell the web master.', 'gravity-forms-dps-pxpay'));
			}
		}

		return $feeds;
	}

	/**
	* attempt to get shared page URL for transaction
	* @param array $entry the form entry
	* @param array $form the form submission data
	* @return array
	*/
	public function requestRedirectUrl($entry, $form) {
		$feed				= $this->current_feed;
		$submission_data	= $this->current_submission_data;

		$this->log_debug('========= initiating transaction request');
		$this->log_debug(sprintf('%s: feed #%d - %s', __FUNCTION__, $feed['id'], $feed['meta']['feedName']));

//~ error_log(__METHOD__ . ": submission_data =\n" . print_r($submission_data,1));
//~ error_log(__METHOD__ . ": feed =\n" . print_r($feed,1));
//~ error_log(__METHOD__ . ": entry =\n" . print_r($entry,1));

		try {
			$paymentReq = $this->getPaymentRequest($submission_data, $feed, $form, $entry);

			$returnURL						= $this->getReturnURL($paymentReq->transactionNumber);
			$paymentReq->redirectURL		= $returnURL;
			$paymentReq->cancelUrl			= $returnURL;

//~ error_log(__METHOD__ . ": paymentReq =\n" . print_r($paymentReq,1));

			// record some payment meta
			gform_update_meta($entry['id'], self::META_TRANSACTION_ID, $paymentReq->transactionNumber);
			gform_update_meta($entry['id'], self::META_FEED_ID, $feed['id']);

			$response = $paymentReq->requestSharedPage();

//~ error_log(__METHOD__ . ": response =\n" . print_r($response,1));

			if ($response && $response->POST_VALIDATION === 'ACK') {
				$this->urlPaymentForm = $response->FRONTEND_REDIRECT_URL;
				GFFormsModel::update_lead_property($entry['id'], 'payment_status', 'Processing');
				$entry['payment_status']	= 'Processing';
			}
			else {
				$entry['payment_status']	= 'Failed';
				$entry['payment_date']		= date('Y-m-d H:i:s');

				$paymentMethod = rgar($feed['meta'], 'paymentMethod', 'debit');

				$this->log_debug(sprintf('%s: failed', __FUNCTION__));

				$error_msg = esc_html__('Transaction request failed', 'gravity-forms-dps-pxpay');

				$note = $this->getFailureNote($paymentMethod, array($error_msg));
				$this->add_note($entry['id'], $note, 'error');

				// record payment failure, and set hook for displaying error message
				$this->error_msg = $error_msg;
				add_filter('gform_confirmation', array($this, 'displayPaymentFailure'), 1000, 4);
			}
		}
		catch (GFDpsPxPayException $e) {

//~ error_log(__METHOD__ . ": exception =\n" . $e->getMessage());
			$this->log_error(__FUNCTION__ . ': exception = ' . $e->getMessage());

			// record payment failure, and set hook for displaying error message
			GFFormsModel::update_lead_property($entry['id'], 'payment_status', 'Failed');
			$this->error_msg = $e->getMessage();
			add_filter('gform_confirmation', array($this, 'displayPaymentFailure'), 1000, 4);
		}

		return $entry;
	}

	/**
	* display a payment request failure message
	* @param mixed $confirmation text or redirect for form submission
	* @param array $form the form submission data
	* @param array $entry the form entry
	* @param bool $ajax form submission via AJAX
	* @return mixed
	*/
	public function displayPaymentFailure($confirmation, $form, $entry, $ajax) {
		// record entry's unique ID in database, to signify that it has been processed so don't attempt another payment!
		gform_update_meta($entry['id'], self::META_UNIQUE_ID, GFFormsModel::get_form_unique_id($form['id']));

		// create a "confirmation message" in which to display the error
		$default_anchor = count(GFCommon::get_fields_by_type($form, array('page'))) > 0 ? 1 : 0;
		$default_anchor = apply_filters('gform_confirmation_anchor_'.$form['id'], apply_filters('gform_confirmation_anchor', $default_anchor));
		$anchor = $default_anchor ? "<a id='gf_{$form["id"]}' name='gf_{$form["id"]}' class='gform_anchor' ></a>" : '';
		$cssClass = rgar($form, 'cssClass');
		$error_msg = wpautop($this->error_msg);

		ob_start();
		include GFDPSPXPAY_PLUGIN_ROOT . 'views/error-payment-failure.php';
		return ob_get_clean();
	}

	/**
	* check whether this form entry's unique ID has already been used; if so, we've already done/doing a payment attempt.
	* @param array $form
	* @return boolean
	*/
	protected function hasFormBeenProcessed($form) {
		global $wpdb;

		$unique_id = GFFormsModel::get_form_unique_id($form['id']);

		$sql = "select lead_id from {$wpdb->prefix}rg_lead_meta where meta_key = %s and meta_value = %s limit 1";
		$lead_id = $wpdb->get_var($wpdb->prepare($sql, self::META_UNIQUE_ID, $unique_id));

		return !empty($lead_id);
	}

	/**
	* create and populate a Payment Request object
	* @param GFHeidelpayFormData $formData
	* @param array $feed
	* @param array $form
	* @param array|false $entry
	* @return GFHeidelpayPayment
	*/
	protected function getPaymentRequest($formData, $feed, $form, $entry = false) {
		// build a payment request and execute on API
		$creds		= new GFDpsPxPayCredentials($this, $feed);
		$paymentReq	= new GFDpsPxPayAPI($creds);

		// generate a unique transaction ID to avoid collisions, e.g. between different installations using the same gateway account
		// use last three characters of entry ID as prefix, to avoid collisions with entries created at same microsecond
		// uniqid() generates 13-character string, plus 3 characters from entry ID = 16 characters which is max for field
		$transactionID = uniqid(substr($entry['id'], -3));

		// allow plugins/themes to modify transaction ID; NB: must remain unique for gateway account!
		$transactionID = apply_filters('gfdpspxpay_invoice_trans_number', $transactionID, $form);

		$paymentReq->amount					= $formData['payment_amount'];
		$paymentReq->currency				= GFCommon::get_currency();
		$paymentReq->transactionNumber		= $transactionID;
		$paymentReq->invoiceReference		= $formData['description'];
		$paymentReq->txnType				= GFDpsPxPayAPI::TXN_TYPE_CAPTURE;
		$paymentReq->urlSuccess				= home_url(self::ENDPOINT_RETURN);
		$paymentReq->urlFail				= home_url(self::ENDPOINT_RETURN);		// NB: redirection will happen after transaction status is updated

		// billing details
		$paymentReq->txn_data1				= $formData['txn_data1'];
		$paymentReq->txn_data2				= $formData['txn_data2'];
		$paymentReq->txn_data3				= $formData['txn_data3'];
		$paymentReq->emailAddress			= $formData['email'];

		// allow plugins/themes to modify invoice description and reference, and set option fields
		$paymentReq->invoiceReference	= apply_filters('gfdpspxpay_invoice_ref', $paymentReq->invoiceReference, $form);
		$paymentReq->txn_data1			= apply_filters('gfdpspxpay_invoice_txndata1', $paymentReq->txn_data1, $form);
		$paymentReq->txn_data2			= apply_filters('gfdpspxpay_invoice_txndata2', $paymentReq->txn_data2, $form);
		$paymentReq->txn_data3			= apply_filters('gfdpspxpay_invoice_txndata3', $paymentReq->txn_data3, $form);

		return $paymentReq;
	}

	/**
	* generate an entry-based return URL for passing information back from gateway
	* @param string $transactionNumber
	* @return string
	*/
	protected function getReturnURL($transactionNumber) {
		$args = array(
			'callback'		=> $this->_slug,
			'txid'			=> $transactionNumber,
		);
		$hash = wp_hash(wp_json_encode($args));
		$args['hash'] = $hash;

		$url = home_url('/');

		$url = add_query_arg($args, $url);

		return $url;
	}

	/**
	* alter the validation message
	* @param string $msg
	* @param array $form
	* @return string
	*/
	public function gformValidationMessage($msg, $form) {
		if (!empty($this->validationMessages)) {
			$msg = sprintf('<div class="validation_error">%s</div>', implode('<br />', $this->validationMessages));
		}

		return $msg;
	}

	/**
	* return redirect URL for the payment gateway
	* @param array $feed
	* @param array $submission_data
	* @param array $form
	* @param array $entry
	* @return string
	*/
	public function redirect_url($feed, $submission_data, $form, $entry) {
		if ($this->urlPaymentForm) {
			// record entry's unique ID in database, to signify that it has been processed so don't attempt another payment!
			gform_update_meta($entry['id'], 'heidelpay_unique_id', GFFormsModel::get_form_unique_id($form['id']));
		}

		return $this->urlPaymentForm;
	}

	/**
	* test for valid callback from gateway
	*/
	public function is_callback_valid() {
		if (rgget('callback') != $this->_slug) {
			return false;
		}

		$hash = rgget('hash');
		if (empty($hash)) {
			return false;
		}

		$args = array(
			'callback'	=> rgget('callback'),
			'txid'		=> rgget('txid'),
		);
		if ($hash !== wp_hash(wp_json_encode($args))) {
			return false;
		}

		return true;
	}

	/**
	* process the gateway callback
	*/
	public function callback() {
		self::log_debug('========= processing transaction result');

		$transactionNumber = rgget('txid');

		try {
			global $wpdb;
			$sql = "select lead_id from {$wpdb->prefix}rg_lead_meta where meta_key=%s and meta_value = %s";
			$lead_id = $wpdb->get_var($wpdb->prepare($sql, self::META_TRANSACTION_ID, $transactionNumber));

			// must have a lead ID, or nothing to do
			if (empty($lead_id)) {
				throw new GFDpsPxPayException(sprintf(__('Invalid entry ID: %s', 'gravity-forms-dps-pxpay'), $lead_id));
			}

			$response = new GFHeidelpayResponseCallback();
			$response->loadResponse($_POST);

			$entry = GFFormsModel::get_lead($lead_id);
			$form = GFFormsModel::get_form_meta($entry['form_id']);
			$feed = $this->getFeed($lead_id);

			// capture current state of lead
			$initial_status = $entry['payment_status'];

			$capture = (rgar($feed['meta'], 'paymentMethod', 'debit') !== 'authorize');

			if (rgar($entry, 'payment_status') === 'Processing') {
				// update lead entry, with success/fail details
				if ($response->PROCESSING_RESULT === 'ACK') {
					$action = array(
						'type'						=> 'complete_payment',
						'payment_status'			=> $capture ? 'Paid' : 'Pending',
						'payment_date'				=> $response->CLEARING_DATE,
						'amount'					=> $response->CLEARING_AMOUNT,
						'currency'					=> $response->CLEARING_CURRENCY,
						'transaction_id'			=> $response->IDENTIFICATION_UNIQUEID,
					);
					$action['note']					=  $this->getPaymentNote($capture, $action, $response->getProcessingMessages());
					$entry[self::META_SHORT_ID]		=  $response->IDENTIFICATION_SHORTID;
					$entry[self::META_RETURN_CODE]	=  $response->PROCESSING_RETURN_CODE;
					$entry['currency']				=  $response->CLEARING_CURRENCY;
					$this->complete_payment($entry, $action);

					$this->log_debug(sprintf('%s: success, date = %s, id = %s, status = %s, amount = %s',
						__FUNCTION__, $entry['payment_date'], $entry['transaction_id'], $entry['payment_status'], $entry['payment_amount']));
					$this->log_debug(sprintf('%s: %s', __FUNCTION__, $response->PROCESSING_RETURN));
					$this->log_debug(sprintf('%s: %s', __FUNCTION__, $response->CLEARING_DESCRIPTOR));
				}
				else {
					$entry['payment_status']		=  'Failed';
					$entry['payment_date']			=  $response->CLEARING_DATE;
					$entry['currency']				=  $response->CLEARING_CURRENCY;
					$entry[self::META_RETURN_CODE]	=  $response->PROCESSING_RETURN_CODE;

					// fail_payment() below doesn't update whole entry, so we need to do it here
					GFAPI::update_entry($entry);

					if ($response->FRONTEND_REQUEST_CANCELLED) {
						$note = esc_html('Transaction canceled by customer', 'gravity-forms-dps-pxpay');
					}
					else {
						$note = $this->getFailureNote($capture, $response->getProcessingMessages());
					}

					$action = array(
						'type'						=> 'fail_payment',
						'payment_status'			=> 'Failed',
						'note'						=> $note,
					);
					$this->fail_payment($entry, $action);

					$this->log_debug(sprintf('%s: failed; %s', __FUNCTION__, $this->getErrorsForLog($response->getProcessingMessages())));
				}

				// if order hasn't been fulfilled, process any deferred actions
				if ($initial_status === 'Processing') {
					$this->log_debug('processing deferred actions');

					$this->processDelayed($feed, $entry, $form);

					// allow hookers to trigger their own actions
					$hook_status = $response->PROCESSING_RESULT === 'ACK' ? 'approved' : 'failed';
					do_action("gfheidelpay_process_{$hook_status}", $entry, $form, $feed);
				}
			}

			if ($entry['payment_status'] === 'Failed' && $feed['meta']['cancelURL']) {
				// on failure, redirect to failure page if set
				$redirect_url = esc_url_raw($feed['meta']['cancelURL']);
			}
			else {
				// otherwise, redirect to Gravity Forms page, passing form and lead IDs, encoded to deter simple attacks
				$query = array(
					'form_id'	=> $entry['form_id'],
					'lead_id'	=> $entry['id'],
				);
				$hash = wp_hash(wp_json_encode($query));
				$query['hash']	=  $hash;
				$query = base64_encode(wp_json_encode($query));
				$redirect_url = esc_url_raw(add_query_arg(self::ENDPOINT_CONFIRMATION, $query, $entry['source_url']));
			}
			echo $redirect_url;
			exit;
		}
		catch (GFDpsPxPayException $e) {
			// TODO: what now?
			echo nl2br(esc_html($e->getMessage()));
			self::log_error(__FUNCTION__ . ': ' . $e->getMessage());
			exit;
		}
	}

	/**
	* filter whether post creation from form is enabled (yet)
	* @param bool $is_delayed
	* @param array $form
	* @param array $entry
	* @return bool
	*/
	public function gformDelayPost($is_delayed, $form, $entry) {
		$feed = $this->getFeed($entry['id']);

		if ($entry['payment_status'] === 'Processing' && !empty($feed['meta']['delayPost'])) {
			$is_delayed = true;
			$this->log_debug(sprintf('delay post creation: form id %s, lead id %s', $form['id'], $entry['id']));
		}

		return $is_delayed;
	}

	/**
	* filter whether form delays some actions (e.g. MailChimp)
	* @param bool $is_delayed
	* @param array $form
	* @param array $entry
	* @param string $addon_slug
	* @return bool
	*/
	public function gformIsDelayed($is_delayed, $form, $entry, $addon_slug) {
		if ($entry['payment_status'] === 'Processing') {
			$feed = $this->getFeed($entry['id']);

			if ($feed) {

				switch ($addon_slug) {

					case 'gravityformsmailchimp':
						if (!empty($feed['meta']['delayMailchimp'])) {
							$is_delayed = true;
							$this->log_debug(sprintf('delay MailChimp registration: form id %s, lead id %s', $form['id'], $entry['id']));
						}
						break;

					case 'gravityformsuserregistration':
						if (!empty($feed['meta']['delayUserrego'])) {
							$is_delayed = true;
							$this->log_debug(sprintf('delay user registration: form id %s, lead id %s', $form['id'], $entry['id']));
						}
						break;

				}

			}

		}

		return $is_delayed;
	}

	/**
	* process any delayed actions
	* @param array $feed
	* @param array $entry
	* @param array $form
	*/
	protected function processDelayed($feed, $entry, $form) {
		// default to only performing delayed actions if payment was successful, unless feed opts to always execute
		// can filter each delayed action to permit / deny execution
		$execute_delayed = in_array($entry['payment_status'], array('Paid', 'Pending')) || $feed['meta']['execDelayedAlways'];

		if ($feed['meta']['delayPost']) {
			if (apply_filters('gfheidelpay_delayed_post_create', $execute_delayed, $entry, $form, $feed)) {
				$this->log_debug(sprintf('executing delayed post creation; form id %s, lead id %s', $form['id'], $entry['id']));
				GFFormsModel::create_post($form, $entry);
			}
		}

		if ($execute_delayed) {
			do_action('gform_paypal_fulfillment', $entry, $feed, $entry['transaction_id'], $entry['payment_amount']);
		}
	}

	/**
	* allow edits to payment status
	* @param string $content
	* @param array $form
	* @param array $entry
	* @return string
	*/
	public function gformPaymentStatus($content, $form, $entry) {
		// make sure that we're editing the entry and are allowed to change it
		if ($this->canEditPaymentDetails($entry, 'edit')) {
			// create drop down for payment status
			ob_start();
			include GFDPSPXPAY_PLUGIN_ROOT . 'views/admin-entry-payment-status.php';
			$content = ob_get_clean();
		}

		return $content;
	}

	/**
	* update payment status if it has changed
	* @param array $form
	* @param int $entry_id
	*/
	public function gformAfterUpdateEntry($form, $entry_id) {
		// make sure we have permission
		check_admin_referer('gforms_save_entry', 'gforms_save_entry');

		$entry = GFFormsModel::get_lead($entry_id);

		// make sure that we're editing the entry and are allowed to change it
		if (!$this->canEditPaymentDetails($entry, 'update')) {
			return;
		}

		// make sure we have new values
		$payment_status = rgpost('payment_status');

		if (empty($payment_status)) {
			return;
		}

		$note = __('Payment information was manually updated.', 'gravity-forms-dps-pxpay');

		if ($entry['payment_status'] !== $payment_status) {
			// translators: 1: old payment status; 2: new payment status
			$note .= "\n" . sprintf(__('Payment status changed from %1$s to %2$s.', 'gravity-forms-dps-pxpay'), $entry['payment_status'], $payment_status);
			$entry['payment_status'] = $payment_status;
		}


		GFAPI::update_entry($entry);

		$user = wp_get_current_user();
		GFFormsModel::add_note($entry['id'], $user->ID, $user->display_name, esc_html($note));
	}

	/**
	* payment processed and recorded, show confirmation message / page
	*/
	public function processFormConfirmation() {
		// check for redirect to Gravity Forms page with our encoded parameters
		if (isset($_GET[self::ENDPOINT_CONFIRMATION])) {
			do_action('gfheidelpay_process_confirmation');

			// decode the encoded form and lead parameters
			$query = json_decode(base64_decode($_GET[self::ENDPOINT_CONFIRMATION]), true);
			$check = array(
				'form_id'	=> rgar($query, 'form_id'),
				'lead_id'	=> rgar($query, 'lead_id'),
			);

			// make sure we have a match
			if ($query && wp_hash(wp_json_encode($check)) === rgar($query, 'hash')) {

				// stop WordPress SEO from stripping off our query parameters and redirecting the page
				global $wpseo_front;
				if (isset($wpseo_front)) {
					remove_action('template_redirect', array($wpseo_front, 'clean_permalink'), 1);
				}

				// load form and lead data
				$form = GFFormsModel::get_form_meta($query['form_id']);
				$lead = GFFormsModel::get_lead($query['lead_id']);

				do_action('gfheidelpay_process_confirmation_parsed', $lead, $form);

				// get confirmation page
				if (!class_exists('GFFormDisplay', false)) {
					require_once(GFCommon::get_base_path() . '/form_display.php');
				}
				$confirmation = GFFormDisplay::handle_confirmation($form, $lead, false);

				// preload the GF submission, ready for processing the confirmation message
				GFFormDisplay::$submission[$form['id']] = array(
					'is_confirmation'		=> true,
					'confirmation_message'	=> $confirmation,
					'form'					=> $form,
					'lead'					=> $lead,
				);

				// if it's a redirection (page or other URL) then do the redirect now
				if (is_array($confirmation) && isset($confirmation['redirect'])) {
					wp_safe_redirect($confirmation['redirect']);
					exit;
				}
			}
		}
	}

	/**
	* supported notification events
	* @param array $form
	* @return array
	*/
	public function supported_notification_events( $form ) {
		if (!$this->has_feed($form['id'])) {
			return false;
		}

		return array(
			'complete_payment'		=> esc_html_x('Payment Completed', 'notification event', 'gravity-forms-dps-pxpay'),
			'fail_payment'			=> esc_html_x('Payment Failed', 'notification event', 'gravity-forms-dps-pxpay'),
		);
	}

	/**
	* activate and configure custom entry meta
	* @param array $entry_meta
	* @param int $form_id
	* @return array
	*/
	public function get_entry_meta($entry_meta, $form_id) {
		// not on feed admin screen
		if (is_admin()) {
			global $hook_suffix;
			$subview = isset($_GET['subview']) ? $_GET['subview'] : '';

			if ($hook_suffix === 'toplevel_page_gf_edit_forms' && $subview === $this->_slug) {
				return $entry_meta;
			}
		}

		// duplicate of transaction_id as meta, so that it can be passed to other integrations (like Zapier)
		$entry_meta['gateway_txn_id'] = array(
			'label'					=> esc_html_x('Transaction ID', 'entry meta label', 'gravity-forms-dps-pxpay'),
			'is_numeric'			=> false,
			'is_default_column'		=> false,
			'filter'				=> array(
											'operators' => array('is', 'isnot')
										),
		);

		$entry_meta['payment_gateway'] = array(
			'label'					=> esc_html_x('Payment Gateway', 'entry meta label', 'gravity-forms-dps-pxpay'),
			'is_numeric'			=> false,
			'is_default_column'		=> false,
			'filter'				=> array(
											'operators' => array('is', 'isnot')
										),
		);

		$entry_meta[self::META_AUTHCODE] = array(
			'label'					=> esc_html_x('AuthCode', 'entry meta label', 'gravity-forms-dps-pxpay'),
			'is_numeric'			=> false,
			'is_default_column'		=> false,
			'filter'				=> array(
											'operators' => array('is', 'isnot')
										),
		);

		return $entry_meta;
	}

	/**
	* add custom merge tags
	* @param array $merge_tags
	* @param int $form_id
	* @param array $fields
	* @param int $element_id
	* @return array
	*/
	public function gformCustomMergeTags($merge_tags, $form_id, $fields, $element_id) {
		if ($form_id) {
			$feeds = $this->get_feeds($form_id);
			if (!empty($feeds)) {
				// at least one feed for this add-on, so add our merge tags
				$merge_tags[] = array('label' => esc_html_x('Transaction ID', 'merge tag label', 'gravity-forms-dps-pxpay'), 'tag' => '{transaction_id}');
				$merge_tags[] = array('label' => esc_html_x('Auth Code',      'merge tag label', 'gravity-forms-dps-pxpay'), 'tag' => '{authcode}');
				$merge_tags[] = array('label' => esc_html_x('Payment Amount', 'merge tag label', 'gravity-forms-dps-pxpay'), 'tag' => '{payment_amount}');
				$merge_tags[] = array('label' => esc_html_x('Payment Status', 'merge tag label', 'gravity-forms-dps-pxpay'), 'tag' => '{payment_status}');
			}
		}

		return $merge_tags;
	}

	/**
	* replace custom merge tags
	* @param string $text
	* @param array $form
	* @param array $entry
	* @param bool $url_encode
	* @param bool $esc_html
	* @param bool $nl2br
	* @param string $format
	* @return string
	*/
	public function gformReplaceMergeTags($text, $form, $entry, $url_encode, $esc_html, $nl2br, $format) {
		// check for invalid calls, e.g. Gravity Forms User Registration login form widget
		if (empty($form) || empty($lead)) {
			return $text;
		}

		$gateway = gform_get_meta($entry['id'], 'payment_gateway');

		if ($gateway === $this->_slug) {
			$authCode = gform_get_meta($entry['id'], 'authcode');

			// format payment amount as currency
			if (isset($entry['payment_amount'])) {
				$payment_amount = GFCommon::format_number($entry['payment_amount'], 'currency', rgar($entry, 'currency', ''));
			}
			else {
				$payment_amount = '';
			}

			$tags = array (
				'{transaction_id}',
				'{payment_status}',
				'{payment_amount}',
				'{authcode}',
			);
			$values = array (
				rgar($entry, 'transaction_id', ''),
				rgar($entry, 'payment_status', ''),
				$payment_amount,
				!empty($authCode) ? $authCode : '',
			);

			$text = str_replace($tags, $values, $text);
		}

		return $text;
	}

	/**
	* get feed for lead/entry
	* @param int $lead_id the submitted entry's ID
	* @return array
	*/
	protected function getFeed($lead_id) {
		if ($this->feed !== false && (empty($this->feed['lead_id']) || $this->feed['lead_id'] != $lead_id)) {
			$this->feed = $this->get_feed(gform_get_meta($lead_id, self::META_FEED_ID));
			if ($this->feed) {
				$this->feed['lead_id'] = $lead_id;
			}
		}

		return $this->feed;
	}

	/**
	* action hook for building the entry details view
	* @param int $form_id
	* @param array $entry
	*/
	public function gformPaymentDetails($form_id, $entry) {
		$payment_gateway = gform_get_meta($entry['id'], 'payment_gateway');
		if ($payment_gateway === $this->_slug) {
			$authCode		= gform_get_meta($entry['id'], self::META_AUTHCODE);
			//~ $txn_id			= gform_get_meta($entry['id'], self::META_TRANSACTION_ID);

			require GFDPSPXPAY_PLUGIN_ROOT . 'views/admin-entry-payment-details.php';
		}
	}

	/**
	* test whether we can edit payment details
	* @param array $entry
	* @param string $action
	* @return bool
	*/
	protected function canEditPaymentDetails($entry, $action) {
		// make sure payment is not Paid already (can't go backwards!)
		// no Paid, and no Active recurring payments
		$payment_status = rgar($entry, 'payment_status');
		if ($payment_status === 'Paid' || $payment_status === 'Active') {
			return false;
		}

		// check that we're editing the lead
		if (strcasecmp(rgpost('save'), $action) !== 0) {
			return false;
		}

		// make sure payment is one of ours
		if (gform_get_meta($entry['id'], 'payment_gateway') !== $this->_slug) {
			return false;
		}

		return true;
	}

	/**
	* get payment note based on payment method, with details, and gateway response messages
	* @param bool $capture
	* @param array $results
	* @param array $messages
	* @return string
	*/
	protected function getPaymentNote($capture, $results, $messages) {
		if ($capture) {
			$message = esc_html__('Payment has been captured successfully. Amount: %1$s. Transaction ID: %2$s.', 'gravity-forms-dps-pxpay');
		}
		else {
			$message = esc_html__('Payment has been authorized successfully. Amount: %1$s. Transaction ID: %2$s.', 'gravity-forms-dps-pxpay');
		}

		$amount = GFCommon::to_money($results['amount'], $results['currency']);

		$note = sprintf($message, $amount, $results['transaction_id']);
		if (!empty($messages)) {
			$note .= "\n" . esc_html(implode("\n", $messages));
		}

		return $note;
	}

	/**
	* get failure note based on payment method, with gateway response messages
	* @param string $paymentMethod
	* @param array $messages
	* @return string
	*/
	protected function getFailureNote($paymentMethod, $messages) {
		switch ($paymentMethod) {

			case 'authorize':
				$note = esc_html__('Payment authorization failed.', 'gravity-forms-dps-pxpay');
				break;

			default:
				$note = esc_html__('Failed to capture payment.', 'gravity-forms-dps-pxpay');
				break;

		}

		if (!empty($messages)) {
			$note .= "\n" . esc_html(implode("\n", $messages));
		}

		return $note;
	}

	/**
	* get formatted error message for front end, with gateway errors appended
	* @param string $error_msg
	* @param array $errors
	* @return string
	*/
	protected function getErrorMessage($error_msg, $errors) {
		if (!empty($errors)) {
			// add detailed error messages
			$error_msg .= "\n" . implode("\n", $errors);
		}

		return $error_msg;
	}

	/**
	* get errors and response messages as a string, for logging
	* @param array $errors
	* @return string
	*/
	protected function getErrorsForLog($errors) {
		return implode('; ', (array) $errors);
	}

}