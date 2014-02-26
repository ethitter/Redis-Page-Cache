<?php
/*
Plugin Name: WP Redis Cache
Plugin URI: https://github.com/BenjaminAdams/wp-redis-cache
Version: 1.1
Description: Manage settings for full-page caching powered by Redis.
Author: Benjamin Adams & Erick Hitter
Author URI: http://dudelol.com

Cache WordPress using Redis, the fastest way to date to cache WordPress.

== Description ==
## WP Redis Cache
------
Cache WordPress using Redis, the fastest way to date to cache WordPress.

Please see [https://github.com/BenjaminAdams/wp-redis-cache](https://github.com/BenjaminAdams/wp-redis-cache) for the latest information and other needed setup files.

### Requirements
------
* [WordPress](http://wordpress.org) - CMS framework/blogging system
* [Redis](http://redis.io/) - Key Value in memory caching
* [Predis](https://github.com/nrk/predis) - PHP api for Redis

== Installation ==
Install Redis, must have root access to your machine. On debian it's as simple as:
```bash
sudo apt-get install redis-server
```
On other systems please refer to the [Redis website](http://redis.io/).

Move the folder wp-redis-cache to the plugin directory and activate the plugin.  In the admin section you can set how long you will cache the post for.  By default it will cache the post for 12 hours.
Note: This plugin is optional and is used to refresh the cache after you update a post/page

Move the `index-wp-redis.php` to the root/base WordPress directory.

Move the `index.php` to the root/base WordPress directory.  Or manually change the `index.php` to:

```php
<?php
require 'index-wp-redis.php';
?>
```
In `index-wp-redis.php` change `$server_ip` to the IP of your server

*Note: Sometimes when you upgrade WordPress it will replace over your `index.php` file and you will have to redo this step.  This is the reason we don't just replace the contents of `index-wp-redis.php` with `index.php`.

We do this because WordPress is no longer in charge of displaying our posts.  Redis will now server the post if it is in the cache.  If the post is not in the Redis cache it will then call WordPress to serve the page and then cache it for the next pageload


### Benchmark
------
I welcome you to compare the page load times of this caching system with other popular Caching plugins such as [WP Super Cache](http://wordpress.org/plugins/wp-super-cache/) and [W3 Total Cache](http://wordpress.org/plugins/w3-total-cache/)

With a fresh WordPress install:

WP Super Cache
```
Page generated in 0.318 seconds.
```

W3 Total Cache
```
Page generated in 0.30484 seconds.
```

WP Redis Cache
```
Page generated in 0.00902 seconds.
```


== Installation ==

== Installation ==
------
Install Redis, must have root access to your machine. On debian it's as simple as:
```bash
sudo apt-get install redis-server
```
On other systems please refer to the [Redis website](http://redis.io/).

Move the folder wp-redis-cache to the plugin directory and activate the plugin.  In the admin section you can set how long you will cache the post for.  By default it will cache the post for 12 hours.
Note: This plugin is optional and is used to refresh the cache after you update a post/page

Move the `index-wp-redis.php` to the root/base WordPress directory.

Move the `index.php` to the root/base WordPress directory.  Or manually change the `index.php` to:

```php
<?php
require('index-wp-redis.php');
?>
```
In `index-wp-redis.php` change `$ip_of_your_website` to the IP of your server

*Note: Sometimes when you upgrade WordPress it will replace over your `index.php` file and you will have to redo this step.  This is the reason we don't just replace the contents of `index-wp-redis.php` with `index.php`.

We do this because WordPress is no longer in charge of displaying our posts.  Redis will now server the post if it is in the cache.  If the post is not in the Redis cache it will then call WordPress to serve the page and then cache it for the next pageload

/*  Copyright 2013  Benjamin Adams  (email : ben@dudelol.com)

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

class WP_Redis_Cache {
	// Hold singleton instance
	private static $__instance = null;

	// Regular class variables
	private $ns = 'wp-redis-cache';

	/**
	 *
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
	}

	/**
	 * Register plugin's settings for proper sanitization
	 *
	 * @return null
	 */
	public function register_options() {
		register_setting( $this->ns, 'wp-redis-cache-seconds', 'absint' );
		register_setting( $this->ns, 'wp-redis-cache-unlimited', 'absint' );
	}

	/**
	 * Register plugin options page
	 *
	 * @action admin_menu
	 * @return null
	 */
	public function register_ui() {
		// If cache life is set globally, don't show the UI
		global $wp_redis_cache_config;
		if ( is_array( $wp_redis_cache_config ) &&
			( isset( $wp_redis_cache_config['cache_duration' ] ) || isset( $wp_redis_cache_config['unlimited' ] ) )
		) {
			return;
		}

		add_options_page( 'WP Redis Cache', 'WP Redis Cache', 'manage_options', $this->ns, array( $this, 'render_ui' ) );
	}

	/**
	 * Render plugin settings screen
	 *
	 * @return string
	 */
	public function render_ui() {
		?>
		<div class="wrap">
		<h2>WP Redis Options</h2>
		<form method="post" action="options.php">
			<?php settings_fields( $this->ns ); ?>

			<p><?php printf( __( 'This plugin does not work out of the box and requires additional steps.<br />Please follow these install instructions: %s.', 'wp-redis-cache' ), '<a target="_blank" href="https://github.com/BenjaminAdams/wp-redis-cache">https://github.com/BenjaminAdams/wp-redis-cache</a>' ); ?></p>

			<p><?php _e( 'If you do not have Redis installed on your machine this will NOT work!', 'wp-redis-cache' ); ?></p>

			<table class="form-table">
				<tr>
					<th scope="row"><label for="duration-seconds"><?php _e( 'Duration of Caching in Seconds:', 'wp-redis-cache' ); ?></label></td>
					<td>
						<input type="text" name="wp-redis-cache-seconds" id="duration-seconds" size="15" value="<?php echo (int) get_option( 'wp-redis-cache-seconds', 43200 ); ?>" />

						<p class="description"><?php _e( 'How many seconds would you like to cache individual pages? <strong>Recommended 12 hours or 43200 seconds</strong>.', 'wp-redis-cache' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="unlimited-cache"><?php _e( 'Cache Without Expiration?', 'wp-redis-cache' ); ?></label></th>
					<td>
						<input type="checkbox" name="wp-redis-cache-unlimited" id="unlimited-cache" value="1" <?php checked( true, (bool) get_option( 'wp-redis-cache-unlimited', false ) ); ?>/>

						<p class="description"><?php _e( 'If this option is set, the cache never expire. This option overides the setting <em>Duration of Caching in Seconds</em>.', 'wp-redis-cache' ); ?></p>
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
			// Default connection settings
			$redis_settings = array(
				'host'     => '127.0.0.1',
				'port'     => 6379,
				'database' => 0,
			);

			// Override default connection settings with global values, when present
			global $wp_redis_cache_config;
			if ( is_array( $wp_redis_cache_config ) ) {
				$_redis_settings = array_intersect( $wp_redis_cache_config, $redis_settings );
				$redis_settings = wp_parse_args( $_redis_settings, $redis_settings );
			}

			$permalink = get_permalink( $post->ID );

			include_once dirname( __FILE__ ) . '/predis5.2.php'; // we need this to use Redis inside of PHP
			$redis = new Predis_Client( $redis_settings );

			$redis_key = md5( $permalink );
			$redis->del( $redis_key );

			//refresh the front page
			$front_page = get_home_url( '/' );
			$redis_key = md5( $front_page );
			$redis->del( $redis_key );
		}
	}
}

WP_Redis_Cache::get_instance();
