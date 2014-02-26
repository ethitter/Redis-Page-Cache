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

// Do not edit these values!
$GLOBALS['wp_redis_cache_config']['current_url'] = get_clean_url( $GLOBALS['wp_redis_cache_config']['secret_string'] );
$GLOBALS['wp_redis_cache_config']['redis_key']   = md5( $GLOBALS['wp_redis_cache_config']['current_url'] );

/**
 * DO NOT EDIT BELOW THIS LINE!
 */

// Start the timer so we can track the page load time
$start = microtime();

function get_micro_time( $time ) {
	list( $usec, $sec ) = explode( " ", $time );
	return ( (float) $usec + (float) $sec );
}

function refresh_has_secret( $secret ) {
	return isset( $_GET['refresh'] ) && $secret == $_GET['refresh'];
}

function request_has_secret( $secret ) {
	return false !== strpos( $_SERVER['REQUEST_URI'], "refresh=${secret}" );
}

function is_remote_page_load( $current_url, $server_ip ) {
	return ( isset( $_SERVER['HTTP_REFERER'] )
			&& $_SERVER['HTTP_REFERER'] == $current_url
			&& $_SERVER['REQUEST_URI'] != '/'
			&& $_SERVER['REMOTE_ADDR'] != $server_ip );
}

function handle_cdn_remote_addressing() {
	// so we don't confuse the cloudflare server
	if ( isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
		$_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_CF_CONNECTING_IP'];
	}
}

function get_clean_url( $secret ) {
	$replace_keys = array( "?refresh=${secret}","&refresh=${secret}" );
	$url = "http://${_SERVER['HTTP_HOST']}${_SERVER['REQUEST_URI']}";
	$current_url = str_replace( $replace_keys, '', $url );
	return $current_url;
}

handle_cdn_remote_addressing();

if ( ! defined( 'WP_USE_THEMES' ) ) {
	define( 'WP_USE_THEMES', true );
}

try {
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

	//Either manual refresh cache by adding ?refresh=secret_string after the URL or somebody posting a comment
	if ( refresh_has_secret( $GLOBALS['wp_redis_cache_config']['secret_string'] ) || request_has_secret( $GLOBALS['wp_redis_cache_config']['secret_string'] ) || is_remote_page_load( $GLOBALS['wp_redis_cache_config']['current_url'], $GLOBALS['wp_redis_cache_config']['server_ip'] ) ) {
		if ( $GLOBALS['wp_redis_cache_config']['debug'] ) {
			echo "<!-- manual refresh was required -->\n";
		}

		$redis->del( $GLOBALS['wp_redis_cache_config']['redis_key'] );

		require dirname( __FILE__ ) . '/wp-blog-header.php';

		// $unlimited           = get_option( 'wp-redis-cache-debug',   false );
		$unlimited           = false;
		// $seconds_cache_redis = get_option( 'wp-redis-cache-seconds', 43200 );
		$seconds_cache_redis = 300;
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

			if ( ! is_numeric( $seconds_cache_redis ) ) {
				$seconds_cache_redis = 43200;
			}

			// When a page displays after an "HTTP 404: Not Found" error occurs, do not cache
			// When the search was used, do not cache
			if ( ! is_404() && ! is_search() ) {
				if ( $unlimited ) {
					$redis->set( $GLOBALS['wp_redis_cache_config']['redis_key'], $html_of_page );
				} else {
					$redis->setex( $GLOBALS['wp_redis_cache_config']['redis_key'], $seconds_cache_redis, $html_of_page );
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

		$GLOBALS['wp_redis_cache_config']['cache']  = true;

		$html_of_page = trim( $redis->get( $GLOBALS['wp_redis_cache_config']['redis_key'] ) );
		echo $html_of_page;

	} else {
		require dirname( __FILE__ ) . '/wp-blog-header.php';
	}
} catch ( Exception $e ) {
	require dirname( __FILE__ ) . '/wp-blog-header.php';
}

$end  = microtime();
$time = @get_micro_time( $end ) - @get_micro_time( $start );
if ( $GLOBALS['wp_redis_cache_config']['debug'] ) {
	echo "<!-- Cache system by Benjamin Adams. Page generated in " . round($time, 5) . " seconds. -->\n";
	echo "<!-- Site was cached  = " . $GLOBALS['wp_redis_cache_config']['cache'] . " -->\n";
	if ( isset( $seconds_cache_redis ) ) {
		echo "<!-- wp-redis-cache-seconds  = " . $seconds_cache_redis . " -->\n";
	}
	echo "<!-- wp-redis-cache-ip  = " . $GLOBALS['wp_redis_cache_config']['server_ip'] . "-->\n";
	if ( isset( $unlimited ) ) {
		echo "<!-- wp-redis-cache-unlimited = " . $unlimited . "-->\n";
	}
	echo "<!-- wp-redis-cache-debug  = " . $GLOBALS['wp_redis_cache_config']['debug'] . "-->\n";
}
