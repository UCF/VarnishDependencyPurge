<?php
/*
Plugin Name: Varnish Dependency Purger
Description: Purge Varnish caches from WordPress
Version: 1.0
Author: Chris Conover
License: GPL3
*/

class VDP {
	private static
		$db_table_name = 'vdp_dependencies';

	private
		$vdp_post_ids  = array();

	public function __construct() {
		// Initialize the settings page
		add_action('admin_menu', create_function('', 'new VDPSettingsPage();'));

		// Register the activation and deactivation hooks
		register_activation_hook(__FILE__,   array($this, 'activate'));
		register_deactivation_hook(__FILE__, array($this, 'deactivate'));

		// Record and write the dependent posts
		add_filter('query',    array($this, 'query_filter'));
		add_filter('shutdown', array($this, 'write_posts'));
	}

	/** Public Methods **/

	/**
	 * Parse the raw Varnish node option into VDPVarnishNode objects.
	 * Option value format: <ip address or domain name>:port
	 * Multiple nodes are separated by semicolons.
	 * @return array/false
	 **/
	public static function parse_varnish_nodes() {
		$nodes        = array();
		$option_value = get_option('varnish-nodes');
		if($option_value != '') {
			foreach(explode(';', $option_value) as $node_info) {
				$node_info_parts = explode(':', $node_info);
				if(count($node_info_parts) == 2 && is_string($node_info_parts[0]) && 
					$node_info_parts[0] != '' && is_numeric($node_info_parts[1])) {
					$nodes[] = new VDPVarnishNode($node_info_parts[0], $node_info_parts[1]);
				} else {
					return False;
				}
			}
		}
		return $nodes;
	}

	/**
	 * Attached to the `query` action. Named function instead of anonymous
	 * so that it can be unregistered before and re-registered after
	 * writing the posts. Otherwise writing the posts would cause an infinite
	 * loop because the `query` action would be triggered on each DB call. 
	 **/
	public function query_filter($query) {
		$this->register_posts();
		return $query;
	}

	/**
	 * Actions to execute when the plugin is activated
	 **/
	public function activate() {
		global $wpdb;

		// Create dependency table
		$definition = sprintf('CREATE TABLE %s (
			page_url VARCHAR(2083) NOT NULL,
			post_Id INT(11) NOT NULL,
			KEY (post_id)
		);', VDP::get_db_table_name());
		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($definition);
	}

	/**
	 * Actions to execute when the plugin is deactivated
	 **/
	public function deactivate() {
		global $wpdb;

		// Drop the dependency table
		$wpdb->query(sprintf('DROP TABLE IF EXISTS %s', VDP::get_db_table_name()));
	}

	/**
	 * Look at the last result from wpdb. If it contain's a post ID, assume
	 * this URL relies on it and add it to the global vdp_post_ids list.
	 **/
	public function register_posts() {
		global $wpdb;

		if(!is_admin()) {
			foreach($wpdb->last_result as $result) {
				if(isset($result->post_id)) {
					$this->vdp_post_ids[] = $result->post_id;
				}
			}
		}
	}

	/**
	 *	Write the posts collected by register_posts to the database.
	 **/
	public function write_posts() {
		global $wpdb;

		$current_page_url = self::current_page_url();

		// Don't retrigger register_posts when making queries later in this
		// method
		remove_filter('query', array($this, 'query_filter'));

		// Call this once more to catch anything that happened between the last
		// query filter and shutdown 
		$this->register_posts();

		// Delete the existing dependencies for this URL
		$wpdb->query($wpdb->prepare(
			'DELETE FROM '.self::get_db_table_name().' WHERE page_url = %s',
			$current_page_url
		));

		// Only record each post id once
		$this->vdp_post_ids = array_unique($this->vdp_post_ids);

		// Insert the new dependencies
		foreach($this->vdp_post_ids as $post_id) {
			$wpdb->insert(
				self::get_db_table_name(),
				array(
					'page_url' => $current_page_url,
					'post_id'  => $post_id
				),
				array(
					'%s',
					'%d'
				)
			);
		}

		add_filter('query', array($this, 'query_filter'));
	}

	/** Private Methods **/

	private static function get_db_table_name() {
		global $wpdb;
		return $wpdb->prefix.self::$db_table_name;
	}

	/**
	 * Construct the current page's URL. Varnish doesn't support SSL so don't
	 * bother detecting it
	 **/
	private static function current_page_url() {
		$page_url = 'http://'.$_SERVER['SERVER_NAME'];

		if($_SERVER['SERVER_PORT'] != '80') {
			$page_url .= ':'.$_SERVER['SERVER_PORT'];
		} 

		$page_url .= $_SERVER['REQUEST_URI'];

		return $page_url;
	}

	
}

class VDPVarnishNode {
	public
		$host,
		$port;

	public function __construct($host, $port) {
		$this->host = $host;
		$this->port = $port;
	}
}

class VDPSettingsPage {
	public
		$page_title = 'Varnish Depedency Purger',
		$menu_title = 'Varnish Depedency Purger',
		$capability = 'administrator',
		$menu_slug  = 'vdp-settings-page',
		$functions  = 'options.php';

	public function __construct() {
		add_options_page(
			$this->page_title,
			$this->menu_title,
			$this->capability,
			$this->menu_slug,
			create_function('', 'include(plugin_dir_path(__FILE__).\'options.php\');')
		);
		add_action('admin_init', array($this, 'register_settings'));
	}

	public function register_settings() {
		register_setting('vdp-settings-group', 'varnish-nodes');
	}
}

$vdp = new VDP();
?>
