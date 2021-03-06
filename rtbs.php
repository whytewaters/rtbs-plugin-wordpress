<?php

use Rtbs\ApiHelper\Exceptions\PromoNotFoundException;

require_once("vendor/autoload.php");

/*
Plugin Name: RTBS Booking Plugin
Description: Tour Booking Plugin
Version: 4.3.1
*/
global $wpdb;
new rtbs_plugin($wpdb);

class rtbs_plugin {

    const HOST_DEBUG = 'http://rtbstraining.local';
    const HOST_TEST = 'https://dev.rtbstraining.com';
    const HOST_LIVE = 'https://rtbslive.com';

    const STEP_AVAILABILITY = 1;
    const STEP_DETAILS = 2;
    const STEP_CONFIRM = 3;
    const STEP_PAYMENT = 4;

    private $rtbslive_plugin_version = '4.3.1'; // need to update description in comments above, as that is where wp looks for info
    private $wpdb;

    private $booking_service_instance;

    private $supplier_instance;

    /** @var rtbslive_settings $settings */
    private $settings;


    public function __construct($wpdb)
    {
        date_default_timezone_set('Pacific/Auckland');

        $this->wpdb = $wpdb;

        register_activation_hook(__FILE__, array($this, 'plugin_activate'));
        register_deactivation_hook(__FILE__, array($this, 'plugin_deactivate'));

        add_action('admin_menu', array($this, 'build_admin_menu'));
        add_action('wp_enqueue_scripts', array($this, 'plugin_enqueue_scripts'));


        // ajax actions
        add_action('wp_ajax_rtbs_availability',  array($this, 'ajax_frontend_availability'));
	    add_action('wp_ajax_nopriv_rtbs_availability',  array($this, 'ajax_frontend_availability'));
	    add_action('wp_ajax_rtbs_applypromo',  array($this, 'ajax_frontend_applypromo'));
	    add_action('wp_ajax_nopriv_rtbs_applypromo',  array($this, 'ajax_frontend_applypromo'));

        add_shortcode('rtbs_plugin', array($this, 'rtbs_plugin_main'));
        add_shortcode('rtbs_show_ticket', array($this, 'rtbs_show_ticket'));

        require( plugin_dir_path( __FILE__ ) . 'rtbslive_settings.php');
        $this->settings = rtbslive_settings::load();
    }


    public function plugin_activate()
    {
        $current_version = get_option("rtbslive_plugin_version");

        if ($current_version != $this->rtbslive_plugin_version) {

            // convert table to options as of version 1.2.0
            $row = $this->wpdb->get_row("SELECT * FROM rtbs_settings");

            if ($row) {
                $this->settings->api_key = $row->api_key;
                $this->settings->supplier_key = $row->supplier_key;
                $this->settings->is_test_mode = ($row->rtbs_domain == self::HOST_TEST);
                $this->settings->url_success = $row->success_url;
                $this->settings->html_terms = $row->terms_cond;
                $this->settings->tour_keys = $row->tour_keys;
                $this->settings->text_page_titles = $row->page_title;
                $this->settings->text_section_titles = $row->section_title;
                $this->settings->text_first_page_title = $row->title_first_page;
                $this->settings->html_first_page_content = $row->content_first_page;
                $this->settings->is_show_remaining = $row->is_show_remaining;
                $this->settings->css_style = $row->css_style;
                $this->settings->save();

                $this->wpdb->query('DROP TABLE rtbs_settings');
            }

            update_option('rtbslive_plugin_version', $this->rtbslive_plugin_version);
        }
    }


    public function plugin_enqueue_scripts()
    {
        wp_enqueue_script('jquery');
        wp_enqueue_script('jquery-ui-datepicker');
        wp_enqueue_script('rtbs-plugin-script', plugins_url('rtbs.plugin.js', __FILE__), array('jquery'), $this->rtbslive_plugin_version);
        wp_localize_script('rtbs-plugin-script', 'myRtbsObject', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'loaderImgUrl' => plugins_url('img/ajax-loader-0F5592.gif', __FILE__)
        ));

        if ($this->settings->is_include_bootstrap) {
            wp_enqueue_style('rtbs-bootstrap-css', plugins_url('/bootstrap-3.3.7-dist/css/bootstrap.min.css', __FILE__));
        }

        wp_enqueue_style('jquery-ui-css', '//code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css');
        wp_enqueue_style('rtbs-plugin-base-css', plugins_url('/base.css', __FILE__ ));
    }


    public function build_admin_menu()
    {
        add_menu_page('RTBS', 'RTBS', '', __FILE__, 'moveing_company', plugins_url('img/settings.png', __FILE__));
        add_submenu_page(__FILE__, 'Settings', 'Settings', 'administrator', 'adminSettings', array($this, 'rtbs_admin_settings'));
        add_submenu_page(__FILE__, 'CSS Style', 'CSS Style', 'administrator', 'css-style-rtbs-booking', array($this, 'rtbs_admin_css_style'));
        add_submenu_page(__FILE__, 'Shortcodes', 'Shortcodes', 'administrator', 'shortcode-rtbs-booking', array($this, 'rtbs_admin_shortcodes'));
    }


    private function host()
    {
        if (stripos($_SERVER['HTTP_HOST'], '.local') !== false) {
            $host = self::HOST_DEBUG;
        } else {
            $host = ($this->settings->is_test_mode) ? self::HOST_TEST : self::HOST_LIVE;
        }

        return $host;
    }


    /**
     * @return \Rtbs\ApiHelper\BookingServiceImpl
     * @throws Exception
     */
    private function get_booking_service_instance()
    {
        if (!$this->settings->api_key) {
            throw new Exception('API Key is required');
        }

        if (!$this->booking_service_instance && $this->settings->api_key) {
            $credentials = array(
                'host' => $this->host(),
                'key' => $this->settings->api_key,
            );

            $this->booking_service_instance = new Rtbs\ApiHelper\BookingServiceImpl($credentials);
        }

        return $this->booking_service_instance;
    }


    /**
     * @return \Rtbs\ApiHelper\Models\Supplier
     */
    private function get_supplier_instance()
    {
        if (!$this->supplier_instance) {
            $booking_service = $this->get_booking_service_instance();
            $this->supplier_instance = $booking_service->get_supplier($this->settings->supplier_key);
        }

        return $this->supplier_instance;
    }


    /**
     * @param string $shortcode_tour_keys
     * @return array
     */
    private function get_tour_keys($shortcode_tour_keys)
    {
        if (!empty($shortcode_tour_keys)) {
            $tour_keys = explode(',', $shortcode_tour_keys);
        } else {
            $supplier = $this->get_supplier_instance();
            $tour_keys = array();

            foreach ($supplier->get_tours() as $tour) {
                $tour_keys[] = $tour->get_tour_key();
            }
        }

        return $tour_keys;
    }


    /**
     * @param array $tour_keys
     * @return \Rtbs\ApiHelper\Models\Tour[]
     */
    private function get_tours($tour_keys)
    {
        $booking_service = $this->get_booking_service_instance();
        $tours = $booking_service->get_tours($tour_keys);

        if (empty($tours)) {
            die('Error: No Tours Found');
        }

        return $tours;
    }


    public function rtbs_admin_settings()
    {
        $is_saved = false;

        if (isset($_POST['save_set'])) {
            $this->settings->fill($_POST);
            $this->settings->save();
            $is_saved = true;
        }

        ?>

        <div class='wrap'>

            <h2>RTBS Settings</h2>

	        <?php if (ini_get('allow_url_fopen') == 0): ?>
                <div class="error settings-error notice">
                    <p><strong>allow_url_fopen is disabled</strong></p>
                    Please make sure the following setting is in your php.ini file <pre>allow_url_fopen=1</pre>

                    <p>See the official php docs for further info on this setting.
                        <a href="http://php.net/manual/en/filesystem.configuration.php">http://php.net/manual/en/filesystem.configuration.php</a>
                    </p>
                </div>
	        <?php endif; ?>

            <?php if ($is_saved): ?>
                <div id="setting-error-settings_updated" class="updated settings-error notice is-dismissible">
                    <p><strong>Settings saved.</strong></p>
                    <button type="button" class="notice-dismiss"><span
                            class="screen-reader-text">Dismiss this notice.</span></button>
                </div>
            <?php endif; ?>

            <form action="" method="post">

                <table class="form-table">
                    <tbody>

                    <tr>
                        <th scope="row"><label for="api_key">API Key</label></th>
                        <td><input name="api_key" type="text" id="api_key" value="<?= $this->settings->api_key; ?>" class="regular-text"></td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="supplier_key">Supplier Key</label></th>
                        <td><input name="supplier_key" type="text" id="supplier_key" value="<?= $this->settings->supplier_key; ?>" class="regular-text"></td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="is_test_mode">Test Mode</label></th>
                        <td>
                            <input type="hidden" name="is_test_mode" value="0">
                            <input name="is_test_mode" type="checkbox" id="is_test_mode" <?= ($this->settings->is_test_mode) ? 'checked' : '' ?> class="regular-checkbox" value="1">
                            <p class="description">Use testing server <?= self::HOST_TEST; ?> no live bookings will be created</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="is_include_bootstrap">Include Bootstrap CSS</label></th>
                        <td>
                            <input type="hidden" name="is_include_bootstrap" value="0">
                            <input name="is_include_bootstrap" type="checkbox" id="is_include_bootstrap" <?= ($this->settings->is_include_bootstrap) ? 'checked' : '' ?> class="regular-checkbox" value="1">
                            <p class="description">Only include bootstrap if your theme does not have bootstrap already</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="is_show_remaining">Show Remaining</label></th>
                        <td>
                            <input type="hidden" name="is_show_remaining" value="0">
                            <input name="is_show_remaining" type="checkbox" id="is_show_remaining" class="regular-checkbox" <?= ($this->settings->is_show_remaining) ? 'checked' : '' ?>  value="1">
                            <p class="description">Show number of seats remaining next to each tour</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="is_show_comments">Show Comments</label></th>
                        <td>
                            <input type="hidden" name="is_show_comments" value="0">
                            <input name="is_show_comments" type="checkbox" id="is_show_comments" class="regular-checkbox" <?= ($this->settings->is_show_comments) ? 'checked' : '' ?>  value="1">
                            <p class="description">Show Comments field for customer.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="url_success">Success URI</label></th>
                        <td><input name="url_success" type="text" id="url_success" value="<?= $this->settings->url_success; ?>" class="regular-text">
                            <p class="description">Return or Success url ( For display ticket you must put [rtbs_show_ticket] shortcode to your return url page ).</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="text_page_titles">Page Titles</label></th>
                        <td><input name="text_page_titles" type="text" id="text_page_titles" value="<?= $this->settings->text_page_titles; ?>" class="regular-text">
                            <p class="description">Custom progress bar page titles (comma separated)</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="text_section_titles">Section Titles</label></th>
                        <td><input name="text_section_titles" type="text" id="text_section_titles" value="<?= $this->settings->text_section_titles; ?>" class="regular-text">
                            <p class="description">Custom progress bar section title (comma separated).</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="text_first_page_title">Title First Page</label></th>
                        <td><input name="text_first_page_title" type="text" id="text_first_page_title" value="<?= $this->settings->text_first_page_title; ?>" class="regular-text">
                            <p class="description">Your first page title.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="html_first_page_content">First Page Content</label></th>
                        <td><?php wp_editor($this->settings->html_first_page_content, 'html_first_page_content', $options = array('media_buttons' => false)); ?></td>
                    </tr>


                    <tr>
                        <th scope="row"><label for="html_terms">Terms &amp; Conditions</label></th>
                        <td>
                            <?php wp_editor($this->settings->html_terms, 'html_terms', $options = array('media_buttons' => false)); ?>
                        </td>
                    </tr>

                    </tbody>
                </table>

                <p>
                    <button type="submit" name="save_set" class="button-primary">Save Changes</button>
                </p>
            </form>

        </div>

        <?php
    }


    public function rtbs_admin_shortcodes()
    {
        $tours = array();
        $error_msg = null;

        try {
            $booking_service = $this->get_booking_service_instance();
            if ($booking_service) {
                $supplier = $booking_service->get_supplier($this->settings->supplier_key);
                $tours = $supplier->get_tours();
            }
        } catch (Exception $ex) {
            $error_msg = $ex->getMessage();
        }

        ?>
        <div>
            <h1>RTBS Booking Shortcodes</h1>

            <div class="card pressthis">
                <h2>Shortcode for All Tours</h2>
                <code>[rtbs_plugin]</code>
        <?php
            foreach ($tours as $tour) {
                echo '<h2>Shortcode for ' . htmlentities($tour->get_name()) . '</h2>';
                echo '<code>[rtbs_plugin tour_key="' . htmlentities($tour->get_tour_key()) . '"]</code>';
            }
        ?>

                <h2>Shortcode for Multiple Tours</h2>
                <code>[rtbs_plugin tour_key="tour_key1,tour_key2"]</code>
                <p class="description">Replace tour_key1,tour_key2 with tour keys (comma separated).</p>

                <h2>Shortcode for display ticket to your return url page</h2>
                <code>[rtbs_show_ticket]</code>

                <?php
                    if ($error_msg) {
                        $this->display_error($error_msg);
                    };
                ?>

            </div>



        </div>
        <?php
    }


    public function rtbs_admin_css_style()
    {
        $num_rows_updated = 0;

        if (isset($_POST['save_set'])) {
            $this->settings->fill($_POST);
            $this->settings->save();
        }

        ?>
        <div class="wrap">
            <h1>RTBS Booking CSS Style</h1>

            <?php if ($num_rows_updated === 1): ?>
                <div id="setting-error-settings_updated" class="updated settings-error notice is-dismissible">
                    <p><strong>Settings saved.</strong></p>
                    <button type="button" class="notice-dismiss"><span
                            class="screen-reader-text">Dismiss this notice.</span></button>
                </div>
            <?php endif; ?>

            <form action="" method="post">

                <table class="form-table">
                    <tbody>
                    <tr>
                        <th scope="row"><label for="css_style">CSS Style</label></th>
                        <td>
                            <textarea name="css_style" rows="30" cols="50" id="css_style" class="large-text code"><?= $this->settings->css_style; ?></textarea>
                            <p class="description">Override the default css styles. (Optional).</p>
                        </td>
                    </tr>
                    </tbody>
                </table>

                <p>
                    <button type="submit" name="save_set" class="button-primary">Save Changes</button>
                </p>
            </form>
        </div>

        <?php
    }


    public function display_error($message)
    {
        echo '<div style="background: color: yellow; border: 2px solid red; padding: 10px;"><h2>RTBS Booking Plugin Error</h2><span>' . htmlentities($message) . '</span></div>';
    }


    public function rtbs_plugin_main($atts)
    {
        // wrap output in api exception handler
        try {
            $this->render_plugin_frontend($atts);
        } catch (Exception $ex) {
            $this->display_error($ex->getMessage());
        }
    }

    /**
     * Render availability step on ajax request
     */
    public function ajax_frontend_availability()
    {
        $date = (isset($_REQUEST['date'])) ? $_REQUEST['date'] : date('Y-m-d');

        // can't search in the past
        $ts = strtotime($date);
        if ($ts !== false && $ts < time()) {
            $date = date('Y-m-d');
        }

        $shortcode_tour_keys = (isset($_REQUEST['tour_key'])) ? $_REQUEST['tour_key'] : '';

        $this->step_availability($shortcode_tour_keys, $date);

        wp_die();
    }


	/**
	 * apply promo
	 */
	public function ajax_frontend_applypromo()
	{
        $booking = new Rtbs\ApiHelper\Models\Booking();
        $booking->set_tour_key($_REQUEST['tour_key']);
        $booking->set_datetime($_REQUEST['datetime']);

        $price_qtys = $_REQUEST['price_qtys'];
        foreach ($price_qtys as $price_qty) {
	        $booking->add_price_selection_keys($price_qty['price_key'], $price_qty['qty']);
        }

        $booking_service = $this->get_booking_service_instance();

		$promo = null;
		$err_msg = null;

        try {
	        $promo = $booking_service->apply_promo( $_REQUEST['promo_code'], $booking );
        } catch (PromoNotFoundException $ex) {
            $err_msg = $ex->getMessage();
        }

        $data = [
            'success' => ($promo) ? true : false,
            'err_msg' => $err_msg,
            'promo_code' => ($promo) ? $promo->get_promo_code() : null,
            'discount_amount' => ($promo) ? $promo->get_discount_amount() : 0,
        ];

		wp_send_json($data);
	}



	private function render_plugin_frontend($atts)
    {

        // shortcode with attribute or parameter
        if (isset($atts['tour_key'])) {
            $shortcode_tour_keys = $atts['tour_key'];

            if ($shortcode_tour_keys  == 'tour_key1,tour_key2') {
                $this->display_error('Please replace "tour_key1,tour_key2" in the shortcode, with actual tour keys');
                return;
            }
        } else {
            $shortcode_tour_keys = '';
        }

        if (empty($this->settings->supplier_key)) {
            die('Error: Supplier Key Not Set');
        }

        $hdStep = (isset($_POST['hd_step'])) ? $_POST['hd_step'] : self::STEP_AVAILABILITY;
        $date = (isset($_REQUEST['tdate'])) ? $_REQUEST['tdate'] : date('Y-m-d');

        // payment redirects, so do that first
        if ($hdStep == self::STEP_PAYMENT) {
            $this->step_payment();
            return;
        }

        $hd_tour_key = isset($_POST['hd_tour_key']) ? $_POST['hd_tour_key'] : null;
        if ($hd_tour_key) {
            $booking_service = $this->get_booking_service_instance();
            $tours = $booking_service->get_tours(array($_POST['hd_tour_key']));
        }

        ?>

        <div class="rtbs-plugin">
            <a name="rtbs-booking"></a>
            <!-- CSS Styles Custom --->
            <style>
                <?= $this->settings->css_style; ?>
            </style>

            <div class="rtbs-container">

                <?php $this->render_navbar($hdStep); ?>

                <div class="rtbs-plugin-content">

                    <div class="row rtbs-tours-step rtbs-tours-step-<?= $hdStep; ?>">
                        <?php

                            switch ($hdStep) {
                                case self::STEP_AVAILABILITY:
                                    $this->step_availability($shortcode_tour_keys, $date, $is_search_next_available = true);
                                    break;

                                case self::STEP_DETAILS:
                                    $this->step_details();
                                    break;

                                case self::STEP_CONFIRM:
                                    $this->step_confirm();
                                    break;
                            }

                        ?>
                    </div>
                </div>
            </div>

            <script type="text/javascript">
                jQuery(document).ready(function() {
                    RTBSplugin.init();
                });
            </script>

        </div>
        <?php
    }


    public function rtbs_show_ticket() {
        $ticket_url = $this->host() . "/api/ticket?token=" . $_REQUEST['token'];
        return '<p><iframe src="' . $ticket_url . '" frameborder="0" style="overflow:hidden;height:1000px;width:100%" height="100%" width="100%"></iframe></p>';
    }


    private function step_confirm()
    {
        $price_rates = $_POST['price_rate'];
        $price_names = $_POST['hd_price_name'];
	    $fields = array_key_exists('fields', $_POST) ? $_POST['fields'] : array();

        $total = 0;
        $price_qtys = array();

        foreach ($_POST['price_qty'] as $idx => $qty) {
            if ($qty > 0) {
                $price_qtys[$idx] = $qty;

                $total += ($qty * $price_rates[$idx]);
            }
        }

        $tours = $this->get_tours(array($_POST['hd_tour_key']));

	    // only expecting 1 tour
	    $tour = $tours[0];

        ?>
            <h3 class="tour_name"><?= htmlentities($_POST['hd_tour_name']); ?></h3>
            <h5>Selected Tour: <?= date('l jS M Y g:i a', strtotime($_POST['hd_tour_date_time'])); ?></h5>

            <form action="#rtbs-booking" method="post">

                <table class="table">
                    <tr>
                        <td colspan="2">
                            <div class="rtbs-plugin-section-header">Confirm Your Booking </div>
                        </td>
                    </tr>
                    <tr>
                        <td>Tour Date Time</td>
                        <td><?= date('l dS F Y h:i A', strtotime($_POST['hd_tour_date_time'])); ?></td>
                    </tr>

                    <?php foreach ($price_qtys as $idx => $qty): ?>
                        <tr>
                            <td><?= htmlentities($price_names[$idx] . ' x ' . $qty); ?></td>
                            <td><span class="rtbs-plugin-price-amount" data-amount="<?php printf('%0.2f', $price_rates[$idx] * $qty); ?>"><?= '$' . number_format($price_rates[$idx] * $qty, 2); ?></span></td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if ($tour->get_has_promo_codes()): ?>
                        <tr class="rtbs-plugin-promo-code-row">
                            <td>
                                Promo Code:
                            </td>
                            <td>
                                <div class=form-group">
                                    <div class="input-group">
                                        <input class="rtbs-plugin-promo-code form-control" type="text" name="" value="" placeholder="Promo Code">
                                        <a href="#" class="btn btn-default input-group-addon action-apply-promo">Apply</a>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <tr class="rtbs-plugin-promo-discount-row" style="display: none;">
                            <td>less Discount <span class="rtbs-plugin-promo-code-readonly"></span> </td>
                            <td><span class="rtbs-plugin-promo-discount-amount"></span></td>
                        </tr>
                    <?php endif; ?>

                    <tr>
                        <td>Total Price:</td>
                        <td><span class="rtbs-plugin-total-amount"><?= '$' . number_format($total, 2); ?></span></td>
                    </tr>

                    <tr>
                        <td colspan="2">
                            <div class="rtbs-plugin-section-header">Your Details</div>
                        </td>
                    </tr>

                    <tr>
                        <td>
                            Name
                        </td>
                        <td>
                            <?= htmlentities($_POST['fname']) . ' ' . htmlentities($_POST['lname']); ?>
                            <input type="hidden" name="fname" value="<?= htmlentities($_POST['fname']); ?>">
                            <input type="hidden" name="lname" value="<?= htmlentities($_POST['lname']); ?>">
                        </td>
                    </tr>

                    <tr>
                        <td>
                            Email
                        </td>
                        <td>
                            <?= htmlentities($_POST['email']); ?>
                            <input type="hidden" name="email" value="<?= htmlentities($_POST['email']); ?>">
                        </td>
                    </tr>

                    <tr>
                        <td>
                            Phone
                        </td>
                        <td>
                            <?= htmlentities($_POST['phone']); ?>
                            <input type="hidden" name="phone" value="<?= htmlentities($_POST['phone']); ?>">
                        </td>
                    </tr>

                    <tr>
                        <td colspan="2">
                            <div class="rtbs-plugin-section-header">Terms &amp; Conditions</div>
                        </td>
                    </tr>

                    <tr>
                        <td colspan="2">
                            <div class="rtbs-plugin-tandc-error" style="display: none;">Please accept the terms and conditions</div>
                            <p class="tandc"><?= nl2br($this->settings->html_terms); ?></p>
                            <input type="checkbox" name="tandc" id="rtbs-checkbox-tandc" value="0"> I have read and accept the Terms and Conditions.<br><br>
                        </td>
                    </tr>

                </table>

                <div class="hidden_hd">
                    <input type="hidden" name="hd_step" value="4">
                    <input type="hidden" id="rtbs-plugin-hd-promo-code" name="hd_promo_code" value="">
                    <input type="hidden" class="rtbs-plugin-tour-key" name="hd_tour_key" value="<?= htmlentities($_POST['hd_tour_key']); ?>">
                    <input type="hidden" name="hd_date" value="<?= htmlentities($_POST['hd_date']); ?>">
                    <input type="hidden" name="hd_tour_name" value="<?= htmlentities($_POST['hd_tour_name']); ?>">
                    <input type="hidden" class="rtbs-plugin-trip-datetime" name="hd_tour_date_time" value="<?= htmlentities($_POST['hd_tour_date_time']); ?>">

                    <?php foreach ($price_qtys as $idx => $qty): ?>
                        <input type="hidden" class="rtbs-plugin-price-qty" data-price-key="<?= $idx; ?>" name="price_qty[<?= $idx; ?>]" value="<?= $qty; ?>">
                    <?php endforeach; ?>

	                <?php foreach ($fields as $name => $value): ?>
                        <input type="hidden" name="fields[<?= htmlentities($name); ?>]" value="<?= htmlentities($value); ?>">
	                <?php endforeach; ?>

                    <input type="hidden" name="pickup_key" value="<?= isset($_POST['pickup_key']) ? $_POST['pickup_key'] : ''; ?>">
                    <input type="hidden" name="comments" value="<?= isset($_POST['comments']) ? $_POST['comments'] : ''; ?>">

                </div>

                <button id="confirm_pay" type="submit" class="btn btn-primary pull-right" name="confirm_payment">Confirm &amp; Make Payment</button>

            </form>

        <?php
    }


    private function step_details() {

	    $section_titles = explode(",", $this->settings->text_section_titles);

        $booking_service = $this->get_booking_service_instance();
	    $pickups = $booking_service->get_pickups($_POST['hd_tour_key']);
	    $tours = $booking_service->get_tours(array($_POST['hd_tour_key']));

	    // only expecting 1 tour
	    $tour = $tours[0];

	    $sessions_and_advance_dates = $booking_service->get_sessions_and_advance_dates($this->settings->supplier_key, array($_POST['hd_tour_key']), $_POST['hd_date']);

	    $prices = array();

	    $min_pax = 0;
	    $max_pax = 999;

	    foreach ($sessions_and_advance_dates->get_sessions() as $session) {
		    if ($session->get_datetime() == $_POST['hd_tour_date_time'] && $session->get_tour_key() == $_POST['hd_tour_key']) {
			    $prices = $session->get_prices();
                $min_pax = $session->get_min_pax();
                $max_pax = $session->get_max_pax();
			    break;
		    }
	    }

	    $qty_range = range(0, $_POST['hd_remaining']);
    ?>
        <h3 class="tour_name"><?= htmlentities($_POST['hd_tour_name']); ?></h3>
        <h5>Selected Tour: <?= date('l jS M Y g:i a', strtotime($_POST['hd_tour_date_time'])); ?></h5>

        <div class="col-md-12 rtbs-plugin-box">
            <div class="col-md-2"></div>
            <div class="col-md-8">

                <center>

                    <form class="form-horizontal" action="#rtbs-booking" method="post" id="details-form" data-min-pax="<?= $min_pax; ?>" data-max-pax="<?= $max_pax; ?>">
                        <fieldset>
                            <p class="rtbs-plugin-section-header"><?php echo(!empty($section_titles[0]) ? $section_titles[0] : 'Number of People'); ?> </p>
                            <?php foreach ($prices as $price): ?>

                                <div class="form-group">
                                    <label for="select" class="col-md-6 text-left"><?= $price->get_name(); ?></label>
                                    <div class="col-md-6">
                                        <div class="col-md-6">
                                            <input type="hidden" name="price_rate[<?= $price->get_price_key(); ?>]" value="<?= $price->get_rate(); ?>">
                                            <select class="form-control nPeople" name="price_qty[<?= $price->get_price_key(); ?>]" data-rate="<?= $price->get_rate(); ?>" data-pax="<?= $price->get_passenger_count(); ?>">
                                                <?php foreach ($qty_range as $qty): ?>
                                                    <option value="<?= $qty; ?>"><?= $qty; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="text-right"><?= '$' . $price->get_rate(); ?></div>
                                        </div>

                                        <input type="hidden" name="hd_price_name[<?= $price->get_price_key(); ?>]" value="<?= $price->get_name(); ?>">
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <p class="rtbs-plugin-total" id="totalPrice">Total: $0.00</p>


                            <p class="rtbs-plugin-section-header"><?php echo(!empty($section_titles[1]) ? $section_titles[1] : 'Your Details'); ?> </p>


                            <div class="form-group">
                                <label for="rtbsFname" class="col-lg-3">First Name</label>
                                <div class="col-lg-9">
                                    <input id="rtbsFname" class="form-control" type="text" name="fname" value="">

                                </div>
                            </div>

                            <div class="form-group">
                                <label for="rtbsLname" class="col-lg-3">Last Name</label>
                                <div class="col-lg-9">
                                    <input id="rtbsLname" class="form-control" type="text" name="lname" value="">
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="rtbsEmail" class="col-lg-3">Email</label>
                                <div class="col-lg-9">
                                    <input id="rtbsEmail" class="form-control" type="email" name="email" value="">
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="rtbsPhone" class="col-lg-3">Phone</label>
                                <div class="col-lg-9">
                                    <input id="rtbsPhone" class="form-control" type="tel" name="phone" value="">
                                </div>
                            </div>


                            <?php if (count($pickups) > 0 || count($tour->get_fields()) > 0 || $this->settings->is_show_comments): ?>
                                <p class="rtbs-plugin-section-header">Additional Info </p>

                                <?php if (count($pickups) > 0): ?>
                                    <div class="form-group">
                                        <label for="pickup_key" class="col-lg-4">Pickup from</label>
                                        <div class="col-lg-8">
                                            <select class="form-control" name="pickup_key" id="pickup_key">
                                                <?php foreach ($pickups as $pickup): ?>
                                                    <option value="<?= $pickup->get_pickup_key(); ?>"><?= ($pickup->get_name() == '' ? 'No Pickup available' : $pickup->get_name() . ' - ' . $pickup->get_place() . ' - ' . date('h:i a', strtotime($_POST['hd_tour_date_time'] . ' -' . $pickup->get_minutes() . ' minutes'))); ?></option>
                                                <?php endforeach; ?>
                                            </select>

                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if (count($tour->get_fields()) > 0): ?>

                                    <?php foreach ($tour->get_fields() as $idx => $field): ?>

                                        <div class="form-group">
                                            <label for="rtbsField<?= $idx; ?>" class="col-lg-4"><?= htmlentities($field->get_name()); ?></label>
                                            <div class="col-lg-8">
                                                <input id="rtbsField<?= $idx; ?>" class="form-control" type="text" name="fields[<?= htmlentities($field->get_name()); ?>]" value="">
                                                <div class="help-block text-left small"><?= htmlentities($field->get_description()); ?></div>
                                            </div>
                                        </div>

                                    <?php endforeach; ?>

                                <?php endif; ?>

                                <?php if ($this->settings->is_show_comments): ?>
                                    <div class="form-group">
                                        <label for="comments" class="col-lg-4">Comments</label>
                                        <div class="col-lg-8">
                                            <textarea class="form-control" id="comments" name="comments" rows="3"></textarea>
                                        </div>
                                    </div>
                                <?php endif; ?>

                            <?php endif; ?>

                            <div class="hidden_hd">
                                <input type="hidden" name="hd_step" value="3">
                                <input type="hidden" name="hd_remaining" id="hd-remaining" value="<?= htmlentities($_POST['hd_remaining']); ?>"/>
                                <input type="hidden" name="hd_tour_key" value="<?= htmlentities($_POST['hd_tour_key']); ?>">
                                <input type="hidden" name="hd_date" value="<?= htmlentities($_POST['hd_date']); ?>">
                                <input type="hidden" name="hd_tour_name" value="<?= htmlentities($_POST['hd_tour_name']); ?>">
                                <input type="hidden" name="hd_tour_date_time" value="<?= htmlentities($_POST['hd_tour_date_time']); ?>">
                            </div>

                            <div class="alert alert-danger" style="display: none;"></div>

                            <div class="form-group">
                                <div class="col-lg-10">
                                    <button type="submit" class="btn btn-primary pull-right" name="button">NEXT &gt;</button>
                                </div>
                            </div>


                        </fieldset>
                    </form>

                </center>
            </div>
        </div>

        <div class="col-md-2"></div>

        <?php
    }


    /**
     * @param string $shortcode_tour_keys
     * @param string $date
     * @param bool $is_search_next_available
     */
    private function step_availability($shortcode_tour_keys, $date, $is_search_next_available = false)
    {
        $is_open_available = false;

        $tour_keys = $this->get_tour_keys($shortcode_tour_keys);
        $tours = $this->get_tours($tour_keys);

        $booking_service = $this->get_booking_service_instance();
        $sessions_and_advanced_dates = $booking_service->get_sessions_and_advance_dates($this->settings->supplier_key, $tour_keys, $date, $is_search_next_available);

        $first_open_date = $sessions_and_advanced_dates->get_first_available_session_datetime(null, $open_only = true);

        if ($is_search_next_available) {

            // if no open dates, then search advance date
            if (!$first_open_date) {
                $first_advance_date = $sessions_and_advanced_dates->get_first_advance_date();
                $sessions_and_advanced_dates = $booking_service->get_sessions_and_advance_dates($this->settings->supplier_key, $tour_keys, $first_advance_date, $is_search_next_available = true);

                $first_open_date = $sessions_and_advanced_dates->get_first_available_session_datetime(null, $open_only = true);
            }

            if (!empty($first_open_date)) {
                $first_open_date = date('Y-m-d', strtotime($first_open_date));

                if ($first_open_date != $date) {
                    $is_open_available = true;
                    $date = $first_open_date;
                }
            }
        }

        $sessions_by_tour = array();

        foreach ($sessions_and_advanced_dates->get_sessions() as $session) {
            $sessions_by_tour[$session->get_tour_key()][] = $session;
        }

        $date_prefix_text = ($is_open_available) ? 'The next date you can book online is: ' : 'Showing: ';

        echo '<h2 class="title-first-page">' . htmlentities($this->settings->text_first_page_title) . '</h2>';
        echo '<p>' . nl2br($this->settings->html_first_page_content) . '</p>';
        echo '<p>Date: <input type="text" placeholder="Change Date" class="rtbs-plugin-datepicker" value="' . $date . '" data-tour-key="' . $shortcode_tour_keys . '"></p>';


        if (count($sessions_by_tour) == 0) {
            echo '<div class="alert alert-info" style="margin-bottom: 20px;">No tours are available on this date</div>';
            return;
        }

        // list all open tours
        echo '<h5>' . $date_prefix_text . ' ' . date('l, jS M Y', strtotime($date)) . '</h5>';

        foreach ($tours as $tour) {
            if (!empty($sessions_by_tour[$tour->get_tour_key()])) {
                /** @var \Rtbs\ApiHelper\Models\Session[] $sessions */
                $sessions = $sessions_by_tour[$tour->get_tour_key()];
                $is_no_tours = false;
                ?>
                <div class="col-md-12">
                    <div class="panel panel-default">
                        <div class="panel-heading">
                            <h4><?= htmlentities($tour->get_name()); ?> <?php if ($this->settings->is_test_mode): ?>
                                    <span style="background: red; padding: 2px; color: yellow;">TEST MODE IS ON</span><?php endif; ?>
                            </h4></div>
                        <div class="panel-body">

                            <?php foreach ($sessions as $session): ?>

                                <form action="#rtbs-booking" method="post">
                                    <p>
                                        <?= date('g:ia', strtotime($session->get_datetime())) . ($this->settings->is_show_remaining ? ', ' . $session->get_remaining() . ' remaining' : ''); ?>
                                        <input type="hidden" name="hd_step" value="2">
                                        <input type="hidden" name="hd_remaining" value="<?= $session->get_remaining(); ?>">
                                        <input type="hidden" name="hd_tour_key" value="<?= $tour->get_tour_key(); ?>">
                                        <input type="hidden" name="hd_date" value="<?= $date; ?>">
                                        <input type="hidden" name="hd_tour_name" value="<?= $tour->get_name(); ?>">
                                        <input type="hidden" name="hd_tour_date_time" value="<?= $session->get_datetime(); ?>">
                                        <button <?= ($session->is_open()) ? '' : 'disabled' ?> class="btn btn-primary" type="submit" name="button"><?= $session->get_state(); ?></button>
                                    </p>
                                </form>
                            <?php endforeach; ?>

                        </div>
                    </div>
                </div>
                <?php
            }
        }

    }


    private function step_payment()
    {

        $booking = new Rtbs\ApiHelper\Models\Booking();
        $booking->set_tour_key($_POST['hd_tour_key']);
        $booking->set_datetime($_POST['hd_tour_date_time']);
        $booking->set_first_name($_POST['fname']);
        $booking->set_last_name($_POST['lname']);
        $booking->set_email($_POST['email']);
        $booking->set_phone($_POST['phone']);

        if (!empty($_POST['hd_promo_code'])) {
            $booking->set_promo_code($_POST['hd_promo_code']);
        }

        if (!empty($this->settings->url_success)) {
            $booking->set_return_url($this->settings->url_success);
        }

        if (!empty($_POST['pickup_key'])) {
            $booking->set_pickup_key($_POST['pickup_key']);
        }

        if (!empty($_POST['comments'])) {
            $booking->set_comment($_POST['comments']);
        }

	    if (!empty($_POST['fields'])) {
		    foreach ($_POST['fields'] as $name => $value) {
			    $booking->add_field_data($name, $value);
		    }
	    }

        $price_qtys = $_POST['price_qty'];
        foreach ($price_qtys as $key => $qty) {
            $booking->add_price_selection_keys($key, $qty);
        }

        $booking_service = $this->get_booking_service_instance();
        $return_url = $booking_service->make_booking($booking);

        if ($return_url == '') {
            die('<p class="rtbs_error_msg">Error. insufficient capacity or session closed. </p>');
        } else {
            echo '<script>window.location.href="' . $return_url . '"</script>';
            exit;
        }
    }


    private function render_navbar($hdStep) {
        $page_titles = explode(",", $this->settings->text_page_titles);

        ?>
        <div class="row hidden-xs hidden-sm rtbs-plugin-navbar">
            <center>

                <div class="col-md-3 col-sm-3 col-xs-3 <?= ($hdStep == self::STEP_AVAILABILITY) ? 'rtbs-plugin-navbar-active' : '' ?>">
                    <div class="col-md-2 rtbs-plugin-navbar-number">1</div>
                    <div class="col-md-10 rtbs-plugin-navbar-text">
                        <p><?php echo(!empty($page_titles[0]) ? $page_titles[0] : 'AVAILABILITY'); ?></p>
                    </div>
                </div>

                <div class="col-md-3 col-sm-3 col-xs-3 <?php echo($hdStep == self::STEP_DETAILS ? 'rtbs-plugin-navbar-active' : ''); ?>">
                    <div class="col-md-2 rtbs-plugin-navbar-number">2</div>
                    <div class="col-md-10 rtbs-plugin-navbar-text">
                        <p><?php echo(!empty($page_titles[1]) ? $page_titles[1] : 'DETAILS'); ?></p>

                    </div>
                </div>

                <div class="col-md-3 col-sm-3 col-xs-3 <?php echo($hdStep == self::STEP_CONFIRM ? 'rtbs-plugin-navbar-active' : ''); ?>">
                    <div class="col-md-2 rtbs-plugin-navbar-number">3</div>
                    <div class="col-md-10 rtbs-plugin-navbar-text">
                        <p><?php echo(!empty($page_titles[2]) ? $page_titles[2] : 'CONFIRM'); ?></p>
                    </div>
                </div>

                <div class="col-md-3 col-sm-3 col-xs-3 <?php echo($hdStep == self::STEP_PAYMENT ? 'rtbs-plugin-navbar-active' : ''); ?>">
                    <div class="col-md-2 rtbs-plugin-navbar-number">4</div>
                    <div class="col-md-10 rtbs-plugin-navbar-text">
                        <p><?php echo(!empty($page_titles[3]) ? $page_titles[3] : 'PAYMENT'); ?></p>

                    </div>
                </div>

            </center>
        </div>
        <?php

    }

}