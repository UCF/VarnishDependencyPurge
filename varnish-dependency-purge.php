<?php
/*
Plugin Name: Varnish Dependency Purger
Description: Purge Varnish caches from WordPress
Version: 1.0
Author: Chris Conover
License: GPL3
*/

add_action('admin_init', create_function('', 'new VDP();'));
add_action('admin_menu', create_function('', 'new VDPSettingsPage();'));
register_activation_hook(__FILE__,   create_function('', 'VDP::activate();'));
register_deactivation_hook(__FILE__, create_function('', 'VDP::deactivate();'));
add_filter('query',    'vdp_query_filter');
add_filter('shutdown', create_function('', 'VDP::write_posts();'));

$vdp_post_ids = array();

/**
 * Name this function so it can be removed and added again when VDP::write_posts
 * is called. As opposed to using create_function
 **/
function vdp_query_filter($query) {
	VDP::register_posts();
	return $query;
}

class VDP {
	public static
		$db_table_name = 'vdp_dependencies';

	public static function get_db_table_name() {
		global $wpdb;
		return $wpdb->prefix.VDP::$db_table_name;
	}


	/**
	 * Construct the current page's URL. Varnish doesn't support SSL so don't
	 * bother detecting it
	 **/
	public static function current_page_url() {
		$page_url = 'http://'.$_SERVER['SERVER_NAME'];

		if($_SERVER['SERVER_PORT'] != '80') {
			$page_url .= ':'.$_SERVER['SERVER_PORT'];	
		} 

		$page_url .= $_SERVER['REQUEST_URI'];

		return $page_url;
	}

	/**
	 * Actions to execute when the plugin is activated
	 **/
	public static function activate() {
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
	public static function deactivate() {
		global $wpdb;

		// Drop the dependency table
		$wpdb->query(sprintf('DROP TABLE IF EXISTS %s', VDP::get_db_table_name()));
	}

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
	 * Look at the last result from wpdb. If it contain's a post ID, assume
	 * this URL relies on it and add it to the global vdp_post_ids list.
	 **/
	public static function register_posts() {
		global $wpdb, $vdp_post_ids;

		if(!is_admin()) {
			foreach($wpdb->last_result as $result) {
				if(isset($result->post_id)) {
					$vdp_post_ids[] = $result->post_id;
				}
			}
		}
	}

	/**
	 *	Write the posts collected by register_posts to the database.
	 **/
	public static function write_posts() {
		global $wpdb, $vdp_post_ids;
		
		$current_page_url = VDP::current_page_url();

		// Don't retrigger vdp_query_filter when makes queries later in this
		// function
		remove_filter('query', 'vdp_query_filter');

		// Call this once more to catch anything that happened between the last
		// query filter and shutdown 
		register_posts();

		// Delete the existing dependencies for this URL
		$wpdb->query(sprintf(
			'DELETE FROM %s WHERE page_url = %s',
			VDP::get_db_table_name(),
			$current_page_url
		));

		// Only record each post id once
		$vdp_post_ids = array_unique($vdp_post_ids);

		// Insert the new dependencies
		foreach($vdp_post_ids as $post_id) {
			$wpdb->insert(
				VDP::get_db_table_name(),
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

		add_filter('query', 'vdp_query_filter');
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
?>
