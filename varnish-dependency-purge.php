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
		$varnish_nodes    = array(),
		$vdp_post_ids     = array(),
		$edited_post_ids  = array(),
		$deleted_post_ids = array(),
		$posts_created    = False;

	public function __construct() {
		// Parse the varnish nodes
		if( ($nodes = self::parse_varnish_nodes()) !== False) {
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
		add_filter('shutdown', array($this, 'resolve_posts'));

		// Purge URLs when a post is updated
		add_action('deleted_post', array($this, 'post_deleted'));
		add_action('post_updated', array($this, 'post_edited'), 10, 3);
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
					$nodes[] = new VDPVarnishNode($node_info_parts[0], (int)$node_info_parts[1], self::$purge_timeout);
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
		if(!is_admin()) {
			global $wpdb;
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
		if(!is_admin()) {
			global $wpdb;

			// Don't record assets and other stuff, Only record URLs that end in a /
			if(substr($_SERVER['REQUEST_URI'], -1) === '/') {

				// Don't retrigger register_posts when making queries later in this
				// method
				$this->remove_query_filter();

				// Call this once more to catch anything that happened between the last
				// query filter and shutdown 
				$this->register_posts();

				// Delete the existing dependencies for this URL
				$wpdb->query($wpdb->prepare(
					'DELETE FROM '.self::get_db_table_name().' WHERE page_url = %s',
					$_SERVER['REQUEST_URI']
				));

				// Only record each post id once
				$this->vdp_post_ids = array_unique($this->vdp_post_ids);

				// Insert the new dependencies
				foreach($this->vdp_post_ids as $post_id) {
					$wpdb->insert(
						self::get_db_table_name(),
						array(
							'page_url' => $_SERVER['REQUEST_URI'],
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
		}
	}

	/**
	 * Deal with the posts that were modified in this execution.
	 *
	 **/
	public function resolve_posts() {
		global $wpdb;

		if($this->posts_created) {
			// Ban on all pages. Don't need to bother with the edited posts
			foreach($this->varnish_nodes as $node) {
				$node->ban('.*\/$');
			}
		} else if(count($this->edited_post_ids) > 0) {
			$this->remove_query_filter();

			$this->edited_post_ids = array_unique($this->edited_post_ids);

			// Get a list of all the  URLs dependent on any of the edited posts
			// TODO: Figure out a way to fit this into a `prepare`
			$purge_urls = $wpdb->get_results('
				SELECT DISTINCT page_url FROM '.$this->get_db_table_name().' WHERE post_id IN ('.implode(',', $this->edited_post_ids).')
			', ARRAY_A);

			// Flatten the results
			$purge_urls = array_map(create_function('$i', 'return $i[\'page_url\'];'), $purge_urls);

			// Always include this the post permalink
			foreach($this->edited_post_ids as $post_id) {
				if( ($permalink = get_permalink($post_id)) !== False && !in_array($permalink, $purge_urls)) {
					$purge_urls[] = $permalink;
				}
			}

			// Purge the URLs on each Varnish node
			foreach($purge_urls as $purge_url) {
				foreach($this->varnish_nodes as $node) {
					$node->purge($purge_url);
				}
				break;
			}

			$this->add_query_filter();
		}

		// Removed any dependencies for deleted posts
		if(count($this->deleted_post_ids) > 0) {
			$this->remove_query_filter();
			foreach($this->deleted_post_ids as $post_id) {
				if( ($parent_id = wp_is_post_revision($post_id)) !== False) {
					$post_id = $parent_id;
				}

				$wpdb->query($wpdb->prepare('DELETE FROM '.self::get_db_table_name().' post_id = %d', $post_id));
			}
			$this->add_query_filter();
		}

	}

	/**
	 * Purge associated URL. Deleted dependencies.
	 **/
	public function post_deleted($post_id) {
		$this->deleted_post_ids[] = $post_ids;
	}

	/**
	 * Purse associated URLs
	 **/
	public function post_edited($post_id, $post_after, $post_before) {
		if($post_after->post_status == 'publish' && $post_before->post_status != 'publish') {
			$this->posts_created = True;
		} else {
			$this->edited_post_ids[] = $post_id;
		}
	}

	/** Private Methods **/

	private static function get_db_table_name() {
		global $wpdb;
		return $wpdb->prefix.self::$db_table_name;
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
		$request = $this->setup_request('PURGE');
		curl_setopt($request, CURLOPT_HTTPHEADER, array('X-Purge-URL:'.$url, 'Host:'.$_SERVER['SERVER_NAME']));
		$success = $this->make_request($request);
		if(!$success) {
			trigger_error('Varnish Depedency Purger: Unable to PURGE URL '.$url.'. The following error occurred: '.curl_error($request), E_USER_WARNING);
		}
		curl_close($request);
		return $success;
	}

	/**
	 * Send a BAN request to this varnish node
	 **/
	public function ban($match) {
		$request = $this->setup_request('BAN');
		curl_setopt($request, CURLOPT_HTTPHEADER, array('X-Ban-URL:'.$match, 'Host:'.$_SERVER['SERVER_NAME']));
		$success = $this->make_request($request);
		if(!$success) {
			trigger_error('Varnish Depedency Purger: Unable to BAN match '.$match.'. The following error occurred: '.curl_error($request), E_USER_WARNING);
		}
		curl_close($request);
		return $success;
	}

	/**
	 * Setup a CURL request to this Varnish node
	 **/
	private function setup_request($method) {
		$request = curl_init($this->host);
		curl_setopt($request, CURLOPT_CUSTOMREQUEST,  $method);
		curl_setopt($request, CURLOPT_PORT,           $this->port);
		curl_setopt($request, CURLOPT_TIMEOUT,        $this->timeout);
		curl_setopt($request, CURLOPT_RETURNTRANSFER, True);
		return $request;
	}

	/**
	 * Complete the request created by setup_request
	 **/
	private function make_request($request) {
		$success = curl_exec($request);
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
			create_function('', 'include(plugin_dir_path(__FILE__).\''.$this->file_name.'\');')
		);
		add_action('admin_init', array($this, 'register_settings'));
	}

	public function register_settings() {
		register_setting('vdp-settings-group', 'varnish-nodes');
	}
}

$vdp = new VDP();
?>
