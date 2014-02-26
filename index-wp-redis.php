<?php

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

$debug         = true;
$cache         = true;
$server_ip     = '127.0.0.1';
$reddis_server = '127.0.0.1';
$secret_string = 'changeme';
$current_url   = get_clean_url( $secret_string );
$redis_key     = md5( $current_url );

handle_cdn_remote_addressing();

if ( ! defined( 'WP_USE_THEMES' ) ) {
	define( 'WP_USE_THEMES', true );
}

try {
	// check if PECL Extension is available
	if ( class_exists( 'Redis' ) ) {
		if ( $debug ) {
			echo "<!-- Redis PECL module found -->\n";
		}

		$redis = new Redis();

		// Sockets can be used as well. Documentation @ https://github.com/nicolasff/phpredis/#connection
		$redis->connect( $reddis_server );

	} else { // Fallback to predis5.2.php
		if ( $debug ) {
			echo "<!-- using predis as a backup -->\n";
		}

		include_once( "wp-content/plugins/wp-redis-cache/predis5.2.php" ); //we need this to use Redis inside of PHP
		$redis = new Predis_Client();
	}

	//Either manual refresh cache by adding ?refresh=secret_string after the URL or somebody posting a comment
	if ( refresh_has_secret( $secret_string ) || request_has_secret( $secret_string ) || is_remote_page_load( $current_url, $server_ip ) ) {
		if ( $debug ) {
			echo "<!-- manual refresh was required -->\n";
		}

		$redis->del( $redis_key );

		require('./wp-blog-header.php');

		$unlimited           = get_option( 'wp-redis-cache-debug',   false );
		$seconds_cache_redis = get_option( 'wp-redis-cache-seconds', 43200 );

	// This page is cached, lets display it
	} elseif ( $redis->exists( $redis_key ) ) {
		if ( $debug ) {
			echo "<!-- serving page from cache: key: $redis_key -->\n";
		}

		$cache  = true;

		$html_of_page = $redis->get( $redis_key );
		echo $html_of_page;

	// If the cache does not exist lets display the user the normal page without cache, and then fetch a new cache page
	} elseif ( $_SERVER['REMOTE_ADDR'] != $server_ip && false === strstr( $current_url, 'preview=true' ) ) {
		if ( $debug ) {
			echo "<!-- displaying page without cache -->\n";
		}

		$is_post  = (int) 'POST' === $_SERVER['REQUEST_METHOD'];
		$logged_in = preg_match( "/wordpress_logged_in/", var_export( $_COOKIE, true ) );

		if ( ! $is_post && ! $logged_in ) {
			ob_start();
			require('./wp-blog-header.php');
			$html_of_page = ob_get_contents();
			ob_end_clean();
			echo $html_of_page;

			if ( ! is_numeric( $seconds_cache_redis ) ) {
				$seconds_cache_redis = 43200;
			}

			// When a page displays after an "HTTP 404: Not Found" error occurs, do not cache
			// When the search was used, do not cache
			if ( ( ! is_404() ) and ( ! is_search() ) )  {
				if ( $unlimited ) {
					$redis->set( $redis_key, $html_of_page );
				} else {
					$redis->setex( $redis_key, $seconds_cache_redis, $html_of_page );
				}
			}
		} else { //either the user is logged in, or is posting a comment, show them uncached
			require('./wp-blog-header.php');
		}
	} elseif ( $_SERVER['REMOTE_ADDR'] != $server_ip && true === strstr( $current_url, 'preview=true' ) ) {
		require('./wp-blog-header.php');
	}
	 // else {   // This is what your server should get if no cache exists  //deprecated, as the ob_start() is cleaner
		// require('./wp-blog-header.php');
	// }
} catch ( Exception $e ) {
	//require('./wp-blog-header.php');
	echo "something went wrong";
}

$end  = microtime();
$time = @get_micro_time( $end ) - @get_micro_time( $start );
if ( $debug ) {
	echo "<!-- Cache system by Benjamin Adams. Page generated in " . round($time, 5) . " seconds. -->\n";
	echo "<!-- Site was cached  = " . $cache . " -->\n";
	if ( isset( $seconds_cache_redis ) ) {
		echo "<!-- wp-redis-cache-seconds  = " . $seconds_cache_redis . " -->\n";
	}
	echo "<!-- wp-redis-cache-secret  = " . $secret_string . "-->\n";
	echo "<!-- wp-redis-cache-ip  = " . $server_ip . "-->\n";
	if ( isset( $unlimited ) ) {
		echo "<!-- wp-redis-cache-unlimited = " . $unlimited . "-->\n";
	}
	echo "<!-- wp-redis-cache-debug  = " . $debug . "-->\n";
}
