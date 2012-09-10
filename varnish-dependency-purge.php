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
		$db_table_name = 'vdp_dependencies',
		$purge_timeout = 3; // seconds

	private
		$varnish_nodes = array(),
		$vdp_post_ids  = array();

	public function __construct() {
		// Parse the varnish nodes
		if( ($node = self::parse_varnish_nodes()) !== False) {
			$this->varnish_nodes = $nodes;
		}

		// Initialize the settings page
		add_action('admin_menu', create_function('', 'new VDPSettingsPage();'));

		// Register the activation and deactivation hooks
		register_activation_hook(__FILE__,   array($this, 'activate'));
		register_deactivation_hook(__FILE__, array($this, 'deactivate'));

		// Record and write the dependent posts
		add_filter('query',    array($this, 'query_filter'));
		add_filter('shutdown', array($this, 'write_posts'));

		// Purge URLs when a post is updated
		add_action('publish_post', array($this, 'post_published'));
		add_action('edit_post',    array($this, 'post_edited'));
		add_action('deleted_post', array($this, 'post_deleted'));
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
					$nodes[] = new VDPVarnishNode($node_info_parts[0], $node_info_parts[1], self::$purge_timeout);
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
			post_id INT(11) NOT NULL,
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
		$this->remove_query_filter();

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
		$this->add_query_filter();
	}

	private function post_modified($post_id) {
		global $wpdb;

		$this->remove_query_filter();

		// Get all the URLs that depend on this post
		$purge_urls = $wpdb->get_results($wpdb->prepare('
			SELECT DISTINCT page_url FROM '.$this->get_db_table_name().' WHERE post_id = %d
		', $post_id), ARRAY_A);

		// Flatten the results
		$purge_urls = array_map(create_function('$i', 'return $i[\'page_url\'];'), $page_urls);

		// Always include this post's permalink
		if( ($permalink = get_permalink($post_id)) !== False && !in_array($permalink, $purge_urls)) {
			$purge_urls[] = $permalnk;
		}

		// Purge the URLs on each Varnish node
		$success = True;
		foreach($purge_urls as $purge_url) {
			foreach($this->varnish_nodes as $node) {
				if(!$node->purge($purge_url) && $success === True) {
					$success = False;
				}
			}
		}

		$this->add_query_filter();

		return $success;
	}


	/**
	 * Since we can't know before hand the dependencies of this post, ban
	 * the entire cache
	 **/
	public function post_published($post_id) {
		foreach($this->varnish_nodes as $node) {
			$node->ban('.');
		}
	}

	/**
	 * Purge associated URL. Deleted dependencies.
	 **/
	public function post_deleted($post_id) {
		global $wpdb;

		if($this->post_modified($post_id)) {
			$this->remove_query_filter();
			$wpdb->query($wpdb->prepare('DELETE FROM '.self::get_db_table_name().' post_id = %d', $post_id));
			$this->add_query_filter();
		}
		
	}

	/**
	 * Purse associated URLs
	 **/
	public function post_edited($post_id) {
		$this->post_modified($post_id);
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
	public static function current_page_url() {
		$page_url = 'http://'.$_SERVER['SERVER_NAME'];

		if($_SERVER['SERVER_PORT'] != '80') {
			$page_url .= ':'.$_SERVER['SERVER_PORT'];
		} 

		$page_url .= $_SERVER['REQUEST_URI'];

		return $page_url;
	}

	private function remove_query_filter() {
		remove_filter('query', array($this, 'query_filter'));
	}
	private function add_query_filter() {
		add_filter('query', array($this, 'query_filter'));
	}
	
}

class VDPVarnishNode {
	public
		$host,
		$port,
		$timeout;

	public function __construct($host, $port, $timeout) {
		$this->host    = $host;
		$this->port    = $port;
		$this->timeout = $timeout;
	}

	/**
	 * Send a PURGE request to this Varnish node. Replace whatever
	 * top level domain or ipaddress with the host and port of this node.
	 **/
	public function purge($url) {
		
		$request = setup_request($url, 'PURGE');
		$success = make_request($request);
		if(!$success) {
			trigger_error('Varnish Depedency Purger: Unable to PURGE URL '.$url.'. The following error occurred: '.curl_error($request), E_USER_WARNING);
			return False;
		} else {
			return True;
		}
	}

	/**
	 * Send a BAN request to this varnish node
	 **/
	public function ban($match) {
		$request = setup_request(VDP::current_page_url(), 'BAN');
		curl_setopt($request, CURLOPT_HTTPHEADER, array('X-Ban-Match: '.$match));
		$success = make_request($request);
		if(!$success) {
			trigger_error('Varnish Depedency Purger: Unable to BAN match '.$match.'. The following error occurred: '.curl_error($request), E_USER_WARNING);
			return False;
		} else {
			return True;
		}
	}

	/**
	 * Setup a CURL request to this Varnish node
	 **/
	private function setup_request($url, $method) {
		$request = curl_init($url);
		curl_setopt($request, CURLOPT_CUSTOMREQUEST, $method);
		curl_setopt($request, CURLOPT_PORT,          $this->port);
		curl_setopt($request, CURLOPT_TIMEOUT,       $this->timeout);
		return $request;
	}

	/**
	 * Complete the request created by setup_request
	 **/
	private function make_request($request) {
		$success = curl_exec($request);
		curl_close($request);
		return $success;
	}
}

class VDPSettingsPage {
	public
		$page_title = 'Varnish Depedency Purger',
		$menu_title = 'Varnish Depedency Purger',
		$capability = 'administrator',
		$menu_slug  = 'vdp-settings-page',
		$file_name  = 'options.php';

	public function __construct() {
		add_options_page(
			$this->page_title,
			$this->menu_title,
			$this->capability,
			$this->menu_slug,
			create_function('', 'include(plugin_dir_path(__FILE__).\''.$this->filename.'.php\');')
		);
		add_action('admin_init', array($this, 'register_settings'));
	}

	public function register_settings() {
		register_setting('vdp-settings-group', 'varnish-nodes');
	}
}

$vdp = new VDP();
?>
