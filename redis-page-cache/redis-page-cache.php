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
		add_action( 'admin_init', array( $this, 'register_options' ) );
		add_action( 'admin_menu', array( $this, 'register_ui' ) );
		add_action( 'transition_post_status', array( $this, 'flush_cache' ), 10, 3 );

		$this->redis();
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
	 * Register plugin's settings for proper sanitization
	 *
	 * @return null
	 */
	public function register_options() {
		register_setting( $this->ns, 'redis-page-cache-seconds', 'absint' );
		register_setting( $this->ns, 'redis-page-cache-unlimited', 'absint' );
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
						<input type="text" name="redis-page-cache-seconds" id="duration-seconds" size="15" value="<?php echo (int) get_option( 'redis-page-cache-seconds', 43200 ); ?>" />

						<p class="description"><?php _e( 'How many seconds would you like to cache individual pages? <strong>Recommended 12 hours or 43200 seconds</strong>.', 'redis-page-cache' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="unlimited-cache"><?php _e( 'Cache Without Expiration?', 'redis-page-cache' ); ?></label></th>
					<td>
						<input type="checkbox" name="redis-page-cache-unlimited" id="unlimited-cache" value="1" <?php checked( true, (bool) get_option( 'redis-page-cache-unlimited', false ) ); ?>/>

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
			$redis = $this->redis();

			$redis_key = md5( get_permalink( $post->ID ) );
			foreach ( array( '', 'M-', 'T-', ) as $prefix ) {
				$redis->del( $prefix . $redis_key );
			}

			//refresh the front page
			$front_page = get_home_url( '/' );
			$redis_key = md5( $front_page );
			foreach ( array( '', 'M-', 'T-', ) as $prefix ) {
				$redis->del( $prefix . $redis_key );
			}
		}
	}
}

Redis_Page_Cache::get_instance();
