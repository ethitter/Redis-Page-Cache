<?php
/**
 * Redis Page Cache
 */

/**
 * GLOBAL CONFIGURATION
 */
global $redis_page_cache_config;

$redis_page_cache_config = array(
	'debug'          => false,
	'debug_messages' => '',
	'stats'          => false,
	'cache'          => false,
	'server_ip'      => '127.0.0.1',
	'redis_server'   => '127.0.0.1',
	'redis_port'     => 6379,
	'redis_db'       => 0,
	'secret_string'  => 'changeme',
);

// Uncomment either option below to fix the values here and disable the admin UI
// $redis_page_cache_config['cache_duration'] = 43200;
// $redis_page_cache_config['unlimited']      = false;

// Modify this function to introduce custom handling when exceptions occur
function redis_page_cache_exception_handler( $exception ) {
	return;
}

/**
 * END GLOBAL CONFIGURATION
 *
 * DO NOT EDIT BELOW THIS LINE!
 */
$redis_page_cache_config['current_url'] = redis_page_cache_get_clean_url();
$redis_page_cache_config['redis_key']   = md5( $redis_page_cache_config['current_url'] );

// Start the timer so we can track the page load time
if ( $redis_page_cache_config['debug'] || $redis_page_cache_config['stats'] ) {
	$start = microtime();
}

/**
 * SET SEPARATE CACHES FOR BROAD DEVICE TYPES
 */
$redis_page_cache_config['redis_key'] = redis_page_cache_set_device_key( $redis_page_cache_config['redis_key'] );

/**
 * UTILITY FUNCTIONS
 */

/**
 * Compute microtime from a timestamp
 *
 * @return float
 */
function redis_page_cache_get_micro_time( $time ) {
	list( $usec, $sec ) = explode( " ", $time );
	return ( (float) $usec + (float) $sec );
}

/**
 * Count seconds elapsed between two microtime() timestampes
 *
 * @param string $start
 * @param string $end
 * @param int $precision
 * @return float
 */
function redis_page_cache_time_elapsed( $start, $end ) {
	return round( @redis_page_cache_get_micro_time( $end ) - @redis_page_cache_get_micro_time( $start ), 5 );
}

/**
 * Is the current request a refresh request with the correct secret key?
 *
 * @return bool
 */
function redis_page_cache_refresh_has_secret( $secret ) {
	return isset( $_GET['refresh'] ) && $secret == $_GET['refresh'];
}

/**
 * Does current request include a refresh request?
 *
 * @return bool
 */
function redis_page_cache_request_has_secret( $secret ) {
	return false !== strpos( $_SERVER['REQUEST_URI'], "refresh=${secret}" );
}

/**
 * Set proper IP address for proxied requests
 *
 * @return null
 */
function redis_page_cache_handle_cdn_remote_addressing() {
	// so we don't confuse the cloudflare server
	if ( isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
		$_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_CF_CONNECTING_IP'];
	}
}

/**
 * Prepare a URL for use as a cache key
 *
 * If the URL is too malformed to parse, a one-time cache is set using microtime().
 *
 * @return string
 */
function redis_page_cache_get_clean_url() {
	$proto = 'http';
	if ( isset( $_SERVER['HTTPS'] ) && ( 'on' === strtolower( $_SERVER['HTTPS'] ) || '1' === $_SERVER['HTTPS'] ) ) {
		$proto .= 's';
	} elseif ( isset( $_SERVER['SERVER_PORT'] ) && ( '443' == $_SERVER['SERVER_PORT'] ) ) {
		$proto .= 's';
	}

	$url = parse_url( $proto . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] );
	if ( $url ) {
		$url = $url['scheme'] . '://' . $url['host'] . $url['path'];
	} else {
		$url = microtime();
	}

	return $url;
}

/**
 * Prefix cache key if device calls for separate caching
 *
 * @param string $key
 * @return $string
 */
function redis_page_cache_set_device_key( $key ) {
	switch ( redis_page_cache_get_device_type() ) {
		case 'tablet' :
			$prefix = 'T-';
			break;
		case 'mobile' :
			$prefix = 'M-';
			break;
		default :
		case 'desktop' :
			$prefix = '';
			break;
	}

	return $prefix . $key;
}

/**
 * Determine the current device type from its user agent
 * Allows for separate caches for tablet, mobile, and desktop visitors
 *
 * @return string
 */
function redis_page_cache_get_device_type() {
	$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '';

	if ( empty( $ua ) ) {
		return 'desktop';
	}

	// Tablet user agents
	if (
		false !== stripos( $ua, 'ipad'       ) ||
		( false !== stripos( $ua, 'Android'  ) && false === stripos( $ua, 'mobile' ) ) ||
		false !== stripos( $ua, 'tablet '    ) ||
		false !== stripos( $ua, 'Silk/'      ) ||
		false !== stripos( $ua, 'Kindle'     ) ||
		false !== stripos( $ua, 'PlayBook'   ) ||
		false !== stripos( $ua, 'RIM Tablet' )
	) {
		return 'tablet';
	}

	// Mobile user agents
	if (
		false !== stripos( $ua, 'Mobile'     ) || // many mobile devices (all iPhone, iPad, etc.)
		false !== stripos( $ua, 'Android'    ) ||
		false !== stripos( $ua, 'BlackBerry' ) ||
		false !== stripos( $ua, 'Opera Mini' ) ||
		false !== stripos( $ua, 'Opera Mobi' )
	) {
		return 'mobile';
	}

	return 'desktop';
}

/**
 * Establish a connection to the Redis server
 *
 * Will try the PECL module first, then fall back to PRedis
 *
 * @return object
 */
function redis_page_cache_connect_redis() {
	global $redis_page_cache_config;

	// check if PECL Extension is available
	if ( class_exists( 'Redis' ) ) {
		if ( $redis_page_cache_config['debug'] ) {
			$redis_page_cache_config['debug_messages'] .= "<!-- Redis PECL module found -->\n";
		}

		$redis = new Redis();
		$redis->connect( $redis_page_cache_config['redis_server'], $redis_page_cache_config['redis_port'] );

		// Default DB is 0, so only need to SELECT if other
		if ( $redis_page_cache_config['redis_db'] ) {
			$redis->select( $redis_page_cache_config['redis_db'] );
		}
	// Fallback to predis5.2.php
	} else {
		if ( $redis_page_cache_config['debug'] ) {
			$redis_page_cache_config['debug_messages'] .= "<!-- using predis as a backup -->\n";
		}

		include_once dirname( __FILE__ ) . '/wp-content/plugins/wp-redis-cache/predis5.2.php'; //we need this to use Redis inside of PHP
		$redis = array(
			'host' => $redis_page_cache_config['redis_server'],
			'port' => $redis_page_cache_config['redis_port'],
		);

		// Default DB is 0, so only need to SELECT if other
		if ( $redis_page_cache_config['redis_db'] ) {
			$redis['database'] = $redis_page_cache_config['redis_db'];
		}

		$redis = new Predis_Client( $redis );
	}

	return $redis;
}

/**
 * BEGIN CACHING LOGIC
 */

// Set proper IP for proxied requests
redis_page_cache_handle_cdn_remote_addressing();

// Ensure WP uses a theme (this is normally set in index.php)
if ( ! defined( 'WP_USE_THEMES' ) ) {
	define( 'WP_USE_THEMES', true );
}

try {
	// Establish connection with Redis server
	$redis = redis_page_cache_connect_redis();

	// Whether we need to load WP
	$load_wp = true;

	// Relevant details on the current request
	$is_post   = (bool) 'POST' === $_SERVER['REQUEST_METHOD'];
	$logged_in = (bool) preg_match( "#(wordpress_(logged|sec)|comment_author)#", var_export( $_COOKIE, true ) );

	if ( $redis_page_cache_config['debug'] ) {
		$redis_page_cache_config['debug_messages'] .= "<!-- POST request: " . ( $is_post ? 'yes' : 'no' ) . "-->\n";
		$redis_page_cache_config['debug_messages'] .= "<!-- Logged in: " . ( $logged_in ? 'yes' : 'no' ) . "-->\n";
	}

	// Refresh request, deletes cache: either manual refresh cache by adding ?refresh=secret_string after the URL or somebody posting a comment
	if ( redis_page_cache_refresh_has_secret( $redis_page_cache_config['secret_string'] ) || redis_page_cache_request_has_secret( $redis_page_cache_config['secret_string'] ) ) {
		if ( $redis_page_cache_config['debug'] ) {
			$redis_page_cache_config['debug_messages'] .= "<!-- manual refresh was required -->\n";
		}

		$redis->del( $redis_page_cache_config['redis_key'] );
	// This page is cached, the user isn't logged in, and it isn't a POST request, so let's use the cache
	} elseif ( ! $is_post && ! $logged_in && $redis->exists( $redis_page_cache_config['redis_key'] ) ) {
		if ( $redis_page_cache_config['debug'] ) {
			$redis_page_cache_config['debug_messages'] .= "<!-- serving page from cache: key: " . $redis_page_cache_config['redis_key'] . " -->\n";
		}

		// Page is served from cache, so we don't need WP
		$load_wp = false;
		$redis_page_cache_config['cached'] = true;

		echo trim( $redis->get( $redis_page_cache_config['redis_key'] ) );

		// Display generation stats if requested
		if ( $redis_page_cache_config['stats'] ) {
			echo "\n<!-- Page cached via Redis using the Redis Page Cache plugin. -->";
			echo "\n<!-- Retrieved from cache in " . redis_page_cache_time_elapsed( $start, microtime() ) . " seconds. -->";
		}
	// If the cache does not exist lets display the user the normal page without cache, and then fetch a new cache page
	} elseif ( $_SERVER['REMOTE_ADDR'] != $redis_page_cache_config['server_ip'] ) {
		if ( false === strstr( $redis_page_cache_config['current_url'], 'preview=true' ) ) {
			if ( $redis_page_cache_config['debug'] ) {
				$redis_page_cache_config['debug_messages'] .= "<!-- displaying page without cache -->\n";
			}

			// If user isn't logged in and this isn't a post request, render the requested page and cache if appropriate.
			if ( ! $is_post && ! $logged_in ) {
				// We load WP to generate the cached output, so no need to load again
				$load_wp = false;

				// Render page into an output buffer and display
				ob_start();
				require_once dirname( __FILE__ ) . '/wp-blog-header.php';
				$markup_to_cache = trim( ob_get_clean() );
				echo $markup_to_cache;

				// Display generation stats if requested
				if ( $redis_page_cache_config['stats'] ) {
					echo "\n<!-- Page NOT cached via Redis using the Redis Page Cache plugin. -->";
					echo "\n<!-- Generated and cached in " . redis_page_cache_time_elapsed( $start, microtime() ) . " seconds. -->";
				}

				// Cache rendered page if appropriate
				if ( ! is_404() && ! is_search() ) {
					// Is unlimited cache life requested?
					if ( isset( $redis_page_cache_config['unlimited'] ) ) {
						$unlimited = $redis_page_cache_config['unlimited'];
					} else {
						$unlimited = (bool) get_option( 'wp-redis-cache-debug', false );
						$redis_page_cache_config['unlimited'] = $unlimited;
					}

					// Cache the page for the chosen duration
					if ( $unlimited ) {
						$redis->set( $redis_page_cache_config['redis_key'], $markup_to_cache );
					} else {
						if ( isset( $redis_page_cache_config['cache_duration'] ) ) {
							$cache_duration = $redis_page_cache_config['cache_duration'];
						} else {
							$cache_duration = (int) get_option( 'wp-redis-cache-seconds', 43200 );
							$redis_page_cache_config['cache_duration'] = $cache_duration;
						}

						if ( ! is_numeric( $cache_duration ) ) {
							$cache_duration = $redis_page_cache_config['cache_duration'] = 43200;
						}

						$redis->setex( $redis_page_cache_config['redis_key'], $cache_duration, $markup_to_cache );
					}
				}
			}
		}
	}

	// The current request wasn't served from cache or isn't cacheable, so we pass off to WP
	if ( $load_wp ) {
		require_once dirname( __FILE__ ) . '/wp-blog-header.php';
	}
} catch ( Exception $e ) {
	require_once dirname( __FILE__ ) . '/wp-blog-header.php';
	redis_page_cache_exception_handler( $e );
}

/**
 * DEBUGGING OUTPUT
 */
if ( $redis_page_cache_config['debug'] ) {
	$end  = microtime();
	$time = redis_page_cache_time_elapsed( $start, $end );
	$redis_page_cache_config['debug_messages'] .= "<!-- Redis Page Cache by Erick Hitter. Page generated in " . $time . " seconds. -->\n";
	$redis_page_cache_config['debug_messages'] .= "<!-- Site was cached = " . $redis_page_cache_config['cached'] . " -->\n";
	$redis_page_cache_config['debug_messages'] .= "<!-- wp-redis-cache-key = " . $redis_page_cache_config['redis_key'] . "-->\n";
	if ( isset( $redis_page_cache_config['cache_duration'] ) ) {
		$redis_page_cache_config['debug_messages'] .= "<!-- wp-redis-cache-seconds = " . $redis_page_cache_config['cache_duration'] . " -->\n";
	}
	$redis_page_cache_config['debug_messages'] .= "<!-- wp-redis-cache-ip = " . $redis_page_cache_config['server_ip'] . "-->\n";
	if ( isset( $redis_page_cache_config['unlimited'] ) ) {
		$redis_page_cache_config['debug_messages'] .= "<!-- wp-redis-cache-unlimited = " . $redis_page_cache_config['unlimited'] . "-->\n";
	}
	$redis_page_cache_config['debug_messages'] .= "<!-- wp-redis-cache-debug = " . $redis_page_cache_config['debug'] . "-->\n";

	echo $redis_page_cache_config['debug_messages'];
}
