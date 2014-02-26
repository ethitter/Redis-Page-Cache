<?php
/**
 * WP REDIS CACHE
 */

/**
 * GLOBAL CONFIGURATION
 */
$GLOBALS['wp_redis_cache_config'] = array(
	'debug'         => false,
	'cache'         => true,
	'server_ip'     => '127.0.0.1',
	'redis_server'  => '127.0.0.1',
	'redis_port'    => 6379,
	'redis_db'      => 0,
	'secret_string' => 'changeme',
);

// Uncomment either option below to fix the values here and disable the admin UI
// $GLOBALS['wp_redis_cache_config']['cache_duration'] = 43200;
// $GLOBALS['wp_redis_cache_config']['unlimited']      = false;

// Modify this function to introduce custom handling when exceptions occur
function wp_redis_cache_exception_handler( $exception ) {
	return;
}

/**
 * END GLOBAL CONFIGURATION
 *
 * DO NOT EDIT BELOW THIS LINE!
 */
$GLOBALS['wp_redis_cache_config']['current_url'] = wp_redis_cache_get_clean_url( $GLOBALS['wp_redis_cache_config']['secret_string'] );
$GLOBALS['wp_redis_cache_config']['redis_key']   = md5( $GLOBALS['wp_redis_cache_config']['current_url'] );

// Start the timer so we can track the page load time
$start = microtime();

/**
 * UTILITY FUNCTIONS
 */

/**
 * Compute microtime from a timestamp
 *
 * @return float
 */
function wp_redis_cache_get_micro_time( $time ) {
	list( $usec, $sec ) = explode( " ", $time );
	return ( (float) $usec + (float) $sec );
}

/**
 * Is the current request a refresh request with the correct secret key?
 *
 * @return bool
 */
function wp_redis_cache_refresh_has_secret( $secret ) {
	return isset( $_GET['refresh'] ) && $secret == $_GET['refresh'];
}

/**
 * Does current request include a refresh request?
 *
 * @return bool
 */
function wp_redis_cache_request_has_secret( $secret ) {
	return false !== strpos( $_SERVER['REQUEST_URI'], "refresh=${secret}" );
}

/**
 * Determine if request is from a server other than the one running this code
 *
 * @return bool
 */
function wp_redis_cache_is_remote_page_load( $current_url, $server_ip ) {
	return ( isset( $_SERVER['HTTP_REFERER'] )
			&& $_SERVER['HTTP_REFERER'] == $current_url
			&& $_SERVER['REQUEST_URI'] != '/'
			&& $_SERVER['REMOTE_ADDR'] != $server_ip );
}

/**
 * Set proper IP address for proxied requests
 *
 * @return null
 */
function wp_redis_cache_handle_cdn_remote_addressing() {
	// so we don't confuse the cloudflare server
	if ( isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
		$_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_CF_CONNECTING_IP'];
	}
}

/**
 * Prepare a URL for use as a cache key
 *
 * Strips secret key from URL
 *
 * @param string
 * @return string
 */
function wp_redis_cache_get_clean_url( $secret ) {
	$replace_keys = array( "?refresh=${secret}","&refresh=${secret}" );
	$url          = 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
	return str_replace( $replace_keys, '', $url );
}

/**
 * Establish a connection to the Redis server
 *
 * Will try the PECL module first, then fall back to PRedis
 *
 * @return object
 */
function wp_redis_cache_connect_redis() {
	// check if PECL Extension is available
	if ( class_exists( 'Redis' ) ) {
		if ( $GLOBALS['wp_redis_cache_config']['debug'] ) {
			echo "<!-- Redis PECL module found -->\n";
		}

		$redis = new Redis();

		// Sockets can be used as well. Documentation @ https://github.com/nicolasff/phpredis/#connection
		$redis->connect( $GLOBALS['wp_redis_cache_config']['redis_server'], $GLOBALS['wp_redis_cache_config']['redis_port'] );
		$redis->select( $GLOBALS['wp_redis_cache_config']['redis_db'] );
	} else { // Fallback to predis5.2.php
		if ( $GLOBALS['wp_redis_cache_config']['debug'] ) {
			echo "<!-- using predis as a backup -->\n";
		}

		include_once dirname( __FILE__ ) . '/wp-content/plugins/wp-redis-cache/predis5.2.php'; //we need this to use Redis inside of PHP
		$redis = new Predis_Client( array(
			'host'     => $GLOBALS['wp_redis_cache_config']['redis_server'],
			'port'     => $GLOBALS['wp_redis_cache_config']['redis_port'],
			'database' => $GLOBALS['wp_redis_cache_config']['redis_db'],
		) );
	}

	return $redis;
}

/**
 * BEGIN CACHING LOGIC
 */

// Set proper IP for proxied requests
wp_redis_cache_handle_cdn_remote_addressing();

// Ensure WP uses a theme (this is normally set in index.php)
if ( ! defined( 'WP_USE_THEMES' ) ) {
	define( 'WP_USE_THEMES', true );
}

try {
	// Establish connection with Redis server
	$redis = wp_redis_cache_connect_redis();

	//Either manual refresh cache by adding ?refresh=secret_string after the URL or somebody posting a comment
	if ( wp_redis_cache_refresh_has_secret( $GLOBALS['wp_redis_cache_config']['secret_string'] ) || wp_redis_cache_request_has_secret( $GLOBALS['wp_redis_cache_config']['secret_string'] ) || wp_redis_cache_is_remote_page_load( $GLOBALS['wp_redis_cache_config']['current_url'], $GLOBALS['wp_redis_cache_config']['server_ip'] ) ) {
		if ( $GLOBALS['wp_redis_cache_config']['debug'] ) {
			echo "<!-- manual refresh was required -->\n";
		}

		$redis->del( $GLOBALS['wp_redis_cache_config']['redis_key'] );

		require dirname( __FILE__ ) . '/wp-blog-header.php';
	// If the cache does not exist lets display the user the normal page without cache, and then fetch a new cache page
	} elseif ( $_SERVER['REMOTE_ADDR'] != $GLOBALS['wp_redis_cache_config']['server_ip'] && false === strstr( $GLOBALS['wp_redis_cache_config']['current_url'], 'preview=true' ) ) {
		if ( $GLOBALS['wp_redis_cache_config']['debug'] ) {
			echo "<!-- displaying page without cache -->\n";
		}

		$is_post   = (bool) 'POST' === $_SERVER['REQUEST_METHOD'];
		$logged_in = (bool) preg_match( "#(wordpress_(logged|sec)|comment_author)#", var_export( $_COOKIE, true ) );

		if ( $GLOBALS['wp_redis_cache_config']['debug'] ) {
			echo "<!-- POST request: . " . ( $is_post ? 'yes' : 'no' ) . "-->\n";
			echo "<!-- Logged in: . " . ( $logged_in ? 'yes' : 'no' ) . "-->\n";
		}

		if ( ! $is_post && ! $logged_in ) {
			ob_start();
			require dirname( __FILE__ ) . '/wp-blog-header.php';
			$html_of_page = trim( ob_get_clean() );
			echo $html_of_page;

			// When a page displays after an "HTTP 404: Not Found" error occurs, do not cache
			// When the search was used, do not cache
			if ( ! is_404() && ! is_search() ) {
				if ( isset( $GLOBALS['wp_redis_cache_config']['unlimited'] ) ) {
					$unlimited = $GLOBALS['wp_redis_cache_config']['unlimited'];
				} else {
					$unlimited = (bool) get_option( 'wp-redis-cache-debug', false );
					$GLOBALS['wp_redis_cache_config']['unlimited'] = $unlimited;
				}

				// Determine how long to cache the page for and set the cache
				if ( $unlimited ) {
					$redis->set( $GLOBALS['wp_redis_cache_config']['redis_key'], $html_of_page );
				} else {
					if ( isset( $GLOBALS['wp_redis_cache_config']['cache_duration'] ) ) {
						$cache_duration = $GLOBALS['wp_redis_cache_config']['cache_duration'];
					} else {
						$cache_duration = (int) get_option( 'wp-redis-cache-seconds', 43200 );
						$GLOBALS['wp_redis_cache_config']['cache_duration'] = $cache_duration;
					}

					if ( ! is_numeric( $cache_duration ) ) {
						$cache_duration = $GLOBALS['wp_redis_cache_config']['cache_duration'] = 43200;
					}

					$redis->setex( $GLOBALS['wp_redis_cache_config']['redis_key'], $cache_duration, $html_of_page );
				}
			}
		} else { //either the user is logged in, or is posting a comment, show them uncached
			require dirname( __FILE__ ) . '/wp-blog-header.php';
		}
	} elseif ( $_SERVER['REMOTE_ADDR'] != $GLOBALS['wp_redis_cache_config']['server_ip'] && true === strstr( $GLOBALS['wp_redis_cache_config']['current_url'], 'preview=true' ) ) {
		require dirname( __FILE__ ) . '/wp-blog-header.php';
	// This page is cached, lets display it
	} elseif ( $redis->exists( $GLOBALS['wp_redis_cache_config']['redis_key'] ) ) {
		if ( $GLOBALS['wp_redis_cache_config']['debug'] ) {
			echo "<!-- serving page from cache: key: " . $GLOBALS['wp_redis_cache_config']['redis_key'] . " -->\n";
		}

		$GLOBALS['wp_redis_cache_config']['cache'] = true;

		$html_of_page = trim( $redis->get( $GLOBALS['wp_redis_cache_config']['redis_key'] ) );
		echo $html_of_page;

	} else {
		require dirname( __FILE__ ) . '/wp-blog-header.php';
	}
} catch ( Exception $e ) {
	require dirname( __FILE__ ) . '/wp-blog-header.php';
	wp_redis_cache_exception_handler( $e );
}

$end  = microtime();
$time = @wp_redis_cache_get_micro_time( $end ) - @wp_redis_cache_get_micro_time( $start );
if ( $GLOBALS['wp_redis_cache_config']['debug'] ) {
	echo "<!-- Cache system by Benjamin Adams. Page generated in " . round($time, 5) . " seconds. -->\n";
	echo "<!-- Site was cached = " . $GLOBALS['wp_redis_cache_config']['cache'] . " -->\n";
	if ( isset( $GLOBALS['wp_redis_cache_config']['cache_duration'] ) ) {
		echo "<!-- wp-redis-cache-seconds = " . $GLOBALS['wp_redis_cache_config']['cache_duration'] . " -->\n";
	}
	echo "<!-- wp-redis-cache-ip = " . $GLOBALS['wp_redis_cache_config']['server_ip'] . "-->\n";
	if ( isset( $GLOBALS['wp_redis_cache_config']['unlimited'] ) ) {
		echo "<!-- wp-redis-cache-unlimited = " . $GLOBALS['wp_redis_cache_config']['unlimited'] . "-->\n";
	}
	echo "<!-- wp-redis-cache-debug = " . $GLOBALS['wp_redis_cache_config']['debug'] . "-->\n";
}
