<?php
/*
Plugin Name: Redis Page Cache
Plugin URI: http://eth.pw/rpc
Version: 1.0
Description: Manage settings for full-page caching powered by Redis.
Author: Erick Hitter
Author URI: https://ethitter.com/

This software is based on WP Redis Cache by Benjamin Adams, copyright 2013.

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License, version 2, as
published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

// Version cache keys to support plugin upgrades that modify data structures
if ( ! defined( 'REDIS_PAGE_CACHE_CACHE_VERSION' ) ) {
	define( 'REDIS_PAGE_CACHE_CACHE_VERSION', 0 );
}

class Redis_Page_Cache {
	// Hold singleton instance
	private static $__instance = null;

	// Maintain a single instance of the Redis library, on demand via redis()
	private static $__redis = null;

	// Regular class variables
	private $ns = 'redis-page-cache';

	/**
	 * Singleton instantiation
	 */
	public static function get_instance() {
		if ( ! is_a( self::$__instance, __CLASS__ ) ) {
			self::$__instance = new self;
		}

		return self::$__instance;
	}

	/**
	 * Register necessary actions
	 *
	 * @return null
	 */
	private function __construct() {
		// UI
		add_action( 'admin_init', array( $this, 'register_options' ) );
		add_action( 'admin_menu', array( $this, 'register_ui' ) );

		// Automated invalidations
		add_action( 'transition_post_status', array( $this, 'flush_cache' ), 10, 3 );

		// Manual invalidations
		add_action( 'admin_bar_menu', array( $this, 'admin_bar_menu' ), 999 );
		add_action( 'post_row_actions', array( $this, 'quick_edit' ), 999, 2 );
		add_action( 'page_row_actions', array( $this, 'quick_edit' ), 999, 2 );
		add_action( 'wp_ajax_redis_page_cache_purge', array( $this, 'ajax_purge' ) );
	}

	/**
	 * Create an instance of the Predis library as needed
	 *
	 * @return object
	 */
	private function redis() {
		if ( is_null( self::$__redis ) ) {
			// Default connection settings
			$redis_settings = array(
				'host'     => '127.0.0.1',
				'port'     => 6379,
			);

			// Override default connection settings with global values, when present
			if ( defined( 'REDIS_PAGE_CACHE_REDIS_HOST' ) && REDIS_PAGE_CACHE_REDIS_HOST ) {
				$redis_settings['host'] = REDIS_PAGE_CACHE_REDIS_HOST;
			}
			if ( defined( 'REDIS_PAGE_CACHE_REDIS_PORT' ) && REDIS_PAGE_CACHE_REDIS_PORT ) {
				$redis_settings['port'] = REDIS_PAGE_CACHE_REDIS_PORT;
			}
			if ( defined( 'REDIS_PAGE_CACHE_REDIS_DB' ) && REDIS_PAGE_CACHE_REDIS_DB ) {
				$redis_settings['database'] = REDIS_PAGE_CACHE_REDIS_DB;
			}

			// Connect to Redis using either the PHP PECL extension of the bundled Predis library
			if ( class_exists( 'Redis' ) ) {
				self::$redis = new Redis();

				self::$redis->connect( $redis_settings['host'], $redis_settings['port'] );

				// Default DB is 0, so only need to SELECT if other
				if ( isset( $redis_settings['database'] ) ) {
					self::$redis->select( $redis_settings['database'] );
				}
			} else {
				// Load the Predis library and return an instance of it
				include_once dirname( __FILE__ ) . '/predis5.2.php';
				self::$__redis = new Predis_Client( $redis_settings );
			}
		}

		return self::$__redis;
	}

	/**
	 * Turn URL into corresponding Redis cache key
	 *
	 * @param string $url
	 * @return string
	 */
	private function build_key( $url ) {
		return md5( 'v' . REDIS_PAGE_CACHE_CACHE_VERSION . '-' . $url );
	}

	/**
	 * Remove a specific URL from the Redis cache
	 *
	 * @param string $url
	 * @return null
	 */
	private function purge( $url ) {
		$redis = $this->redis();

		$redis_key = $this->build_key( $url );
		foreach ( array( '', 'M-', 'T-', ) as $prefix ) {
			$redis->del( $prefix . $redis_key );
		}
	}

	/**
	 * Register plugin's settings for proper sanitization
	 *
	 * @return null
	 */
	public function register_options() {
		register_setting( $this->ns, $this->ns . '-seconds', 'absint' );
		register_setting( $this->ns, $this->ns . '-unlimited', 'absint' );
	}

	/**
	 * Register plugin options page
	 *
	 * @action admin_menu
	 * @return null
	 */
	public function register_ui() {
		// Don't show UI
		if ( defined( 'REDIS_PAGE_CACHE_HIDE_UI' ) && REDIS_PAGE_CACHE_HIDE_UI ) {
			return;
		}

		add_options_page( 'Redis Page Cache', 'Redis Page Cache', 'manage_options', $this->ns, array( $this, 'render_ui' ) );
	}

	/**
	 * Render plugin settings screen
	 *
	 * @return string
	 */
	public function render_ui() {
		?>
		<div class="wrap">
		<h2>Redis Page Cache Options</h2>
		<form method="post" action="options.php">
			<?php settings_fields( $this->ns ); ?>

			<p><?php printf( __( 'This plugin does not work out of the box and requires additional steps.<br />Please follow these install instructions: %s.', 'redis-page-cache' ), '<a target="_blank" href="https://github.com/BenjaminAdams/redis-page-cache">https://github.com/BenjaminAdams/redis-page-cache</a>' ); ?></p>

			<p><?php _e( 'If you do not have Redis installed on your machine this will NOT work!', 'redis-page-cache' ); ?></p>

			<table class="form-table">
				<tr>
					<th scope="row"><label for="duration-seconds"><?php _e( 'Duration of Caching in Seconds:', 'redis-page-cache' ); ?></label></td>
					<td>
						<input type="text" name="<?php echo esc_attr( $this->ns ); ?>-seconds" id="duration-seconds" size="15" value="<?php echo (int) get_option( $this->ns . '-seconds', 43200 ); ?>" />

						<p class="description"><?php _e( 'How many seconds would you like to cache individual pages? <strong>Recommended 12 hours or 43200 seconds</strong>.', 'redis-page-cache' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="unlimited-cache"><?php _e( 'Cache Without Expiration?', 'redis-page-cache' ); ?></label></th>
					<td>
						<input type="checkbox" name="<?php echo esc_attr( $this->ns ); ?>-unlimited" id="unlimited-cache" value="1" <?php checked( true, (bool) get_option( $this->ns . '-unlimited', false ) ); ?>/>

						<p class="description"><?php _e( 'If this option is set, the cache never expire. This option overides the setting <em>Duration of Caching in Seconds</em>.', 'redis-page-cache' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
		</div>
		<?php
	}

	/**
	 * On publish, purge cache for individual entry and the homepage
	 *
	 * @param string $new_status
	 * @param string $old_status
	 * @param object $post
	 * @action transition_post_status
	 * @return null
	 */
	public function flush_cache( $new_status, $old_status, $post ) {
		if ( in_array( 'publish', array( $new_status, $old_status ) ) ) {
			$this->purge( get_permalink( $post->ID ) );
			$this->purge( trailingslashit( get_home_url() ) );
		}
	}

	/**
	 * Add a single-page purge option to the admin bar for those with proper capabilities
	 *
	 * @action admin_bar_menu
	 * @return null
	 */
	public function admin_bar_menu() {
		global $wp_admin_bar;

		// In the admin, only show on the post editor
		if ( is_admin() && 'post' !== get_current_screen()->base ) {
			return;
		}

		// Only for Super Admins on multisite or Administrators on single site.
		if ( ( is_multisite() && ! is_super_admin() ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// What are we trying to clear?
		$url = set_url_scheme( esc_url( $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] ) );

		$wp_admin_bar->add_menu( array(
			'id'     => $this->ns,
			'parent' => false,
			'title'  => __( 'Clear Page Cache', 'redis-page-cache' ),
			'href'   => $this->get_ajax_purge_url( $url ),
		) );
	}

	/**
	 * Add a purge link to the Quick Edit actions in post tables
	 *
	 * @param array $actions
	 * @param object $post
	 * @action post_row_actions
	 * @action page_row_actions
	 * @return array
	 */
	public function quick_edit( $actions, $post ) {
		$actions[ $this->ns ] = '<a href="' . esc_url( $this->get_ajax_purge_url( get_permalink( $post->ID ) ) ) . '">' . __( 'Clear cache', 'redis-page-cache' ) . '</a>';

		return $actions;
	}

	/**
	 * Build URL for Ajax purge requests
	 *
	 * @param string $url
	 * @return string
	 */
	private function get_ajax_purge_url( $url ) {
		$url = remove_query_arg( $this->ns . '-purge', $url );

		$url = add_query_arg( array(
			'action' => 'redis_page_cache_purge',
			'nonce'  => wp_create_nonce( $url ),
			'url'    => urlencode( $url ),
		), admin_url( 'admin-ajax.php' ) );

		return $url;
	}

	/**
	 * Purge a page from cache via an Ajax request
	 *
	 * @action wp_ajax_redis_page_cache_purge
	 * @return null
	 */
	public function ajax_purge() {
		$url = esc_url_raw( urldecode( $_GET['url'] ) );

		if ( isset( $_SERVER['HTTP_REFERER'] ) ) {
			$redirect = $_SERVER['HTTP_REFERER'];
		} else {
			$redirect = $url;
		}
		$redirect = add_query_arg( $this->ns . '-purge', 'failed', $redirect );

		// Check nonce and referrer
		if ( ! check_ajax_referer( $url, 'nonce', false ) ) {
			wp_safe_redirect( $redirect, 302 );
		}

		// Only for Super Admins on multisite or Administrators on single site.
		if ( ( is_multisite() && ! is_super_admin() ) || ! current_user_can( 'manage_options' ) ) {
			wp_safe_redirect( $redirect, 302 );
		}

		// Checks passed, so we purge and redirect with success noted in the query string
		$this->purge( $url );

		$redirect = add_query_arg( $this->ns . '-purge', 'success', $redirect );
		wp_safe_redirect( $redirect, 302 );
	}
}

Redis_Page_Cache::get_instance();
