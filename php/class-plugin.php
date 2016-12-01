<?php
/**
 * Bootstraps the Customize REST Resources plugin.
 *
 * @package CustomizeRESTResources
 */

namespace CustomizeRESTResources;

/**
 * Main plugin bootstrap file.
 */
class Plugin extends Plugin_Base {

	/**
	 * Class constructor.
	 */
	public function __construct() {
		$this->plugin_file = dirname( __DIR__ ) . '/customize-rest-resources.php';

		parent::__construct();

		$priority = 9; // Because WP_Customize_Widgets::register_settings() happens at after_setup_theme priority 10.
		add_action( 'after_setup_theme', array( $this, 'init' ), $priority );
	}

	/**
	 * Initiate the plugin resources.
	 *
	 * @action after_setup_theme
	 */
	public function init() {
		if ( ! function_exists( 'get_rest_url' ) || ! apply_filters( 'rest_enabled', true ) ) {
			add_action( 'admin_notices', array( $this, 'show_missing_rest_api_admin_notice' ) );
			return;
		}

		$this->config = apply_filters( 'customize_rest_resources_plugin_config', $this->config, $this );

		add_action( 'wp_default_scripts', array( $this, 'register_scripts' ), 11 );
		add_action( 'wp_default_styles', array( $this, 'register_styles' ), 11 );

		add_action( 'customize_controls_enqueue_scripts', array( $this, 'enqueue_customize_controls_scripts' ) );
		add_action( 'customize_preview_init', array( $this, 'customize_preview_init' ) );

		add_action( 'customize_register', array( $this, 'customize_register' ), 20 );
		add_action( 'customize_dynamic_setting_args', array( $this, 'filter_dynamic_setting_args' ), 10, 2 );
		add_action( 'customize_dynamic_setting_class', array( $this, 'filter_dynamic_setting_class' ), 10, 3 );
		add_action( 'rest_api_init', array( $this, 'remove_customize_signature' ) );

		add_filter( 'rest_pre_dispatch', array( $this, 'use_edit_context_for_requests' ), 10, 3 );
		add_filter( 'rest_post_dispatch', array( $this, 'export_context_with_response' ), 10, 3 );

		add_action( 'customize_controls_print_footer_scripts', array( $this, 'print_templates' ) );
	}

	/**
	 * Attempt to upgrade all Customizer REST API requests to use the edit context.
	 *
	 * @param null|\WP_REST_Response $result  Response to replace the requested version with. Can be anything
	 *                                        a normal endpoint can return, or null to not hijack the request.
	 * @param \WP_REST_Server        $server  Server instance.
	 * @param \WP_REST_Request       $request Original request used to generate the response.
	 * @return null|\WP_REST_Response Dispatch result if successful, or null if the upgrade was not possible.
	 */
	public function use_edit_context_for_requests( $result, \WP_REST_Server $server, \WP_REST_Request $request ) {
		if ( null !== $result || 'edit' === $request['context'] || ! is_customize_preview() ) {
			return $result;
		}

		$edit_request = clone $request;
		$edit_request['context'] = 'edit';
		$edit_result = $server->dispatch( $edit_request );

		if ( $edit_result->is_error() ) {
			/*
			 * Return the original $result to prevent the short-circuiting of the
			 * request dispatching since it is found to result in an error, likely
			 * a rest_forbidden_context one.
			 */
			return $result;
		}

		/*
		 * Now set the context on the original request object to be edit so that
		 * it will match the context that was actually used, and so that the
		 * context will be available in the rest_post_dispatch filter.
		 */
		$request['context'] = 'edit';
		return $edit_result;
	}

	/**
	 * Make sure that the context for the request is made known so we know whether it can be customized.
	 *
	 * @param \WP_HTTP_Response $response Result to send to the client. Usually a WP_REST_Response.
	 * @param \WP_REST_Server   $server   Server instance.
	 * @param \WP_REST_Request  $request  Request used to generate the response.
	 * @return \WP_HTTP_Response Response.
	 */
	public function export_context_with_response( $response, $server, $request ) {
		unset( $server );
		if ( 'edit' === $request['context'] ) {
			$response->header( 'X-Customize-REST-Resources-Context', $request['context'] );
		}
		return $response;
	}

	/**
	 * Show error when REST API is not available.
	 *
	 * @action admin_notices
	 */
	public function show_missing_rest_api_admin_notice() {
		?>
		<div class="error">
			<p><?php esc_html_e( 'The Customize REST Resources plugin requires the WordPress REST API to be available and enabled, including WordPress 4.7 or the WP-API plugin.', 'customize-rest-resources' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Register scripts.
	 *
	 * @param \WP_Scripts $wp_scripts Instance of \WP_Scripts.
	 * @action wp_default_scripts
	 */
	public function register_scripts( \WP_Scripts $wp_scripts ) {
		$handle = 'customize-rest-resources-namespace';
		$src = $this->dir_url . 'js/namespace.js';
		$deps = array();
		$wp_scripts->add( $handle, $src, $deps, $this->version );

		$handle = 'customize-rest-resources-manager';
		$src = $this->dir_url . 'js/rest-resources-manager.js';
		$deps = array(
			'customize-rest-resources-namespace',
			'wp-api',
			'backbone',
		);
		$wp_scripts->add( $handle, $src, $deps, $this->version );

		$handle = 'customize-rest-resources-pane-manager';
		$src = $this->dir_url . 'js/rest-resources-pane-manager.js';
		$deps = array(
			'customize-rest-resources-namespace',
			'customize-rest-resources-manager',
			'customize-controls',
			'customize-rest-resources-section',
			'customize-rest-resource-control',
		);
		$wp_scripts->add( $handle, $src, $deps, $this->version );

		$handle = 'customize-rest-resources-preview-manager';
		$src = $this->dir_url . 'js/rest-resources-preview-manager.js';
		$deps = array(
			'customize-rest-resources-namespace',
			'customize-rest-resources-manager',
			'customize-preview',
		);
		$wp_scripts->add( $handle, $src, $deps, $this->version );

		$handle = 'customize-rest-resources-section';
		$src = $this->dir_url . 'js/rest-resources-section.js';
		$deps = array(
			'customize-rest-resources-namespace',
			'customize-controls',
		);
		$wp_scripts->add( $handle, $src, $deps, $this->version );

		$handle = 'customize-rest-resource-control';
		$src = $this->dir_url . 'js/rest-resource-control.js';
		$deps = array(
			'customize-rest-resources-namespace',
			'customize-controls',
		);
		$wp_scripts->add( $handle, $src, $deps, $this->version );
	}

	/**
	 * Register styles.
	 *
	 * @param \WP_Styles $wp_styles Instance of \WP_Styles.
	 * @action wp_default_styles
	 */
	public function register_styles( \WP_Styles $wp_styles ) {
		$handle = 'customize-rest-resources-pane';
		$src = $this->dir_url . 'css/customize-pane.css';
		$deps = array( 'customize-controls' );
		$wp_styles->add( $handle, $src, $deps, $this->version );
	}

	/**
	 * Enqueue scripts for Customizer pane.
	 *
	 * @action customize_controls_enqueue_scripts
	 */
	public function enqueue_customize_controls_scripts() {
		if ( ! wp_script_is( 'wp-api', 'registered' ) ) {
			return;
		}

		wp_enqueue_style( 'customize-rest-resources-pane' );
		wp_enqueue_script( 'customize-rest-resources-pane-manager' );
		add_action( 'customize_controls_print_footer_scripts', array( $this, 'boot_pane_script' ), 100 );
	}

	/**
	 * Boot script for Customizer pane.
	 *
	 * @throws Exception If the schema could not be obtained.
	 * @action customize_controls_print_footer_scripts
	 */
	public function boot_pane_script() {
		global $wp_customize;
		wp_print_scripts( array( 'customize-rest-resources-pane-manager' ) );

		$rest_server = \rest_get_server();
		$rest_request = new \WP_REST_Request( 'GET', '/' );
		$rest_response = $rest_server->dispatch( $rest_request );
		if ( $rest_response->is_error() ) {
			throw new Exception( $rest_response->as_error()->get_error_message() );
		}
		$schema = $rest_server->get_data_for_routes( $rest_server->get_routes(), 'help' );

		$args = array(
			'previewedTheme' => $wp_customize->get_stylesheet(),
			'previewNonce' => wp_create_nonce( 'preview-customize_' . $wp_customize->get_stylesheet() ),
			'restApiRoot' => get_rest_url(),
			'schema' => $schema,
			'timezoneOffsetString' => $this->get_timezone_offset_string(),
		);
		?>
		<script>
		/* global CustomizeRestResources */
		CustomizeRestResources.manager = new CustomizeRestResources.RestResourcesPaneManager( <?php echo wp_json_encode( $args ) ?> );
		</script>
		<?php
	}

	/**
	 * Get timezone offset string.
	 *
	 * @return string
	 */
	public function get_timezone_offset_string() {
		$tz_str = get_option( 'timezone_string' );
		$gmt_offset = get_option( 'gmt_offset' );
		$offset_str = null;
		if ( $tz_str ) {
			$tz = new \DateTimeZone( $tz_str );
			$date = new \DateTime( 'now', $tz );
			$offset_str = $date->format( 'P' );
		} elseif ( $gmt_offset ) {
			$gmt_offset *= 60;
			$hours = floor( abs( $gmt_offset ) / 60 );
			$minutes = ( abs( $gmt_offset ) % 60 );
			$offset_str = ( $gmt_offset < 0 ? '-' : '+' );
			$offset_str .= sprintf( '%02d:%02d', $hours, $minutes );
		} else {
			$offset_str = 'Z';
		}
		return $offset_str;
	}

	/**
	 * Setup Customizer preview.
	 *
	 * @action customize_preview_init
	 */
	public function customize_preview_init() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_customize_preview_scripts' ) );
	}

	/**
	 * Enqueue scripts for Customizer preview.
	 *
	 * @action wp_enqueue_scripts
	 */
	public function enqueue_customize_preview_scripts() {
		if ( ! wp_script_is( 'wp-api', 'registered' ) ) {
			return;
		}

		wp_enqueue_script( 'customize-rest-resources-preview-manager' );
		add_action( 'wp_head', array( $this, 'boot_preview_script' ), 1000 );
	}

	/**
	 * Boot script for Customizer preview.
	 *
	 * @action wp_head
	 */
	public function boot_preview_script() {
		global $wp_customize;
		wp_print_scripts( array( 'customize-rest-resources-preview-manager' ) );

		$dirty_setting_values = array();
		foreach ( array_keys( $wp_customize->unsanitized_post_values() ) as $setting_id ) {
			if ( ! preg_match( '#^rest_resource\[#', $setting_id ) ) {
				continue;
			}
			$setting = $wp_customize->get_setting( $setting_id );
			if ( $setting ) {
				$dirty_setting_values[ $setting_id ] = $setting->value();
			}
		}

		$args = array(
			'previewedTheme' => $wp_customize->get_stylesheet(),
			'previewNonce' => wp_create_nonce( 'preview-customize_' . $wp_customize->get_stylesheet() ),
			'restApiRoot' => get_rest_url(),
			'initialDirtySettingValues' => $dirty_setting_values,
		);
		?>
		<script>
		/* global CustomizeRestResources */
		CustomizeRestResources.manager = new CustomizeRestResources.RestResourcesPreviewManager( <?php echo wp_json_encode( $args ) ?> );
		</script>
		<?php
	}


	/**
	 * Register section and controls for REST resources.
	 *
	 * Note that this needs to happen at a priority greater than 11 for
	 * customize_register so that dynamic settings will have been registered via
	 * {@see \WP_Customize_Manager::register_dynamic_settings}.
	 *
	 * @param \WP_Customize_Manager $wp_customize Manager.
	 */
	public function customize_register( \WP_Customize_Manager $wp_customize ) {
		$wp_customize->register_control_type( __NAMESPACE__ . '\\WP_Customize_REST_Resource_Control' );
		$section_id = 'rest_resources';
		$section = new WP_Customize_REST_Resources_Section( $wp_customize, $section_id, array(
			'title' => __( 'REST Resources', 'customize-rest-resources' ),
		) );
		$wp_customize->add_section( $section );

		// @todo Create a panel with multiple sections correspondng to each endpoint.
		// @todo Mirror this in JS.
		$i = 0;
		foreach ( $wp_customize->settings() as $setting ) {
			$needs_rest_control = (
				$setting instanceof WP_Customize_REST_Resource_Setting
				&&
				! $wp_customize->get_control( $setting->id )
			);
			if ( $needs_rest_control ) {
				$control = new WP_Customize_REST_Resource_Control( $wp_customize, $setting->id, array(
					'section' => $section_id,
					'settings' => $setting->id,
					'priority' => $i,
				) );
				$wp_customize->add_control( $control );
				$i += 1;
			}
		}
	}

	/**
	 * Filter a dynamically-created rest_resource setting's args.
	 *
	 * For a dynamic setting to be registered, this filter must be employed
	 * to override the default false value with an array of args to pass to
	 * the WP_Customize_Setting constructor.
	 *
	 * @param false|array $setting_args The arguments to the WP_Customize_Setting constructor.
	 * @param string      $setting_id   ID for dynamic setting, usually coming from `$_POST['customized']`.
	 * @return array Setting args.
	 */
	public function filter_dynamic_setting_args( $setting_args, $setting_id ) {
		if ( preg_match( '#^rest_resource\[(?P<route>.*?)\]#', $setting_id ) ) {
			$setting_args['type'] = WP_Customize_REST_Resource_Setting::TYPE;
			$setting_args['transport'] = 'refresh';
			$setting_args['plugin'] = $this;
		}
		return $setting_args;
	}

	/**
	 * Filter a dynamically-created rest_resource setting's class.
	 *
	 * @param string $setting_class WP_Customize_Setting or a subclass.
	 * @param string $setting_id    ID for dynamic setting, usually coming from `$_POST['customized']`.
	 * @param array  $setting_args  WP_Customize_Setting or a subclass.
	 * @return string Setting class.
	 */
	public function filter_dynamic_setting_class( $setting_class, $setting_id, $setting_args ) {
		unset( $setting_id );
		if ( isset( $setting_args['type'] ) && WP_Customize_REST_Resource_Setting::TYPE === $setting_args['type'] ) {
			$setting_class = __NAMESPACE__ . '\\WP_Customize_REST_Resource_Setting';
		}
		return $setting_class;
	}

	/**
	 * Print templates for controls.
	 *
	 * @action customize_controls_print_footer_scripts
	 */
	public function print_templates() {
		?>
		<script id="tmpl-customize-rest-resources-section-notice" type="text/html">
			<div class="customize-rest-resources-section-notice">
				<em>{{ data.message }}</em>
			</div>
		</script>
		<?php
	}

	/**
	 * Remove the Customizer preview signature during REST API requests since it corrupts the JSON.
	 *
	 * @action rest_api_init
	 */
	public function remove_customize_signature() {
		global $wp_customize;
		if ( ! is_customize_preview() || empty( $wp_customize ) || ! defined( 'REST_REQUEST' ) ) {
			return;
		}
		$wp_customize->remove_preview_signature();
	}
}
