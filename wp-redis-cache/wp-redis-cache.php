<?php
/*
Plugin Name: WP Redis Cache
Plugin URI: https://github.com/BenjaminAdams/wp-redis-cache
Version: 1.0
Author: Benjamin Adams
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


//Custom Theme Settings
add_action( 'admin_menu', 'add_redis_interface' );

function add_redis_interface() {
	add_options_page( 'WP Redis Cache', 'WP Redis Cache', 'manage_options', 'wp-redis-cache', 'edit_redis_options' );
}

function edit_redis_options() {
	?>
	<div class='wrap'>
	<h2>WP-Redis Options</h2>
	<form method="post" action="options.php">
	<?php wp_nonce_field('update-options') ?>

	<p>This plugin does not work out of the box and requires additional steps.<br />
	Please follow these install instructions: <a target='_blank' href='https://github.com/BenjaminAdams/wp-redis-cache'>https://github.com/BenjaminAdams/wp-redis-cache</a></p>

	<p>If you do not have Redis installed on your machine this will NOT work! </p>

	<p><strong>Seconds of Caching:</strong><br />
	How many seconds would you like to cache?  *Recommended 12 hours or 43200 seconds <br />
	<input type="text" name="wp-redis-cache-seconds" size="45" value="<?php echo get_option('wp-redis-cache-seconds'); ?>" /></p>

	<p><strong>Cache unlimeted:</strong><br />
		If this options set the cache never expire. This option overiedes the setting "Seconds of Caching"<br />
	<input type="checkbox" name="wp-redis-cache-unlimited" size="45" value="true" <?php checked('true', get_option('wp-redis-cache-unlimited')); ?>/></p>

	<p><input type="submit" name="Submit" value="Update Options" /></p>
	<p><input type="button" id="WPRedisClearCache" name="WPRedisClearCache" value="Clear Cache"></p>
	<input type="hidden" name="action" value="update" />
	<input type="hidden" name="page_options" value="wp-redis-cache-seconds,wp-redis-cache-unlimited" />

	</form>
	</div>
	<?php
}

add_action('transition_post_status', 'refresh_wp_redis_cache',10,3);
add_action('wp_ajax_clear_wp_redis_cache', 'clear_wp_redis_cache');
add_action( 'admin_footer', 'clear_wp_redis_cache_javascript' );
//clears the cache after you update a post
function refresh_wp_redis_cache( $new, $old, $post )
{

	if($new == "publish")
	{
		$permalink = get_permalink( $post->ID );

		// aaronstpierre: this needs to be include_once so as not to cauase a redeclaration error
		include_once("predis5.2.php");  //we need this to use Redis inside of PHP
		$redis = new Predis_Client();

		$redis_key = md5($permalink);
		$redis->del($redis_key);

		//refresh the front page
		$frontPage = get_home_url() . "/";
		$redis_key = md5($frontPage);
		$redis->del($redis_key);
	}
}

// clears the whole cache
function clear_wp_redis_cache()
{
	include_once("predis5.2.php"); //we need this to use Redis inside of PHP
	$args = array( 'post_type' => 'any', 'posts_per_page' => -1);
	$wp_query = new WP_Query( $args); // to get all Posts
	$redis = new Predis_Client();
	// Loop all posts and clear the cache
	$i = 0;
	while ( $wp_query->have_posts() ) : $wp_query->the_post();
		$permalink = get_permalink();

		$redis_key = md5($permalink);
		if (($redis->exists($redis_key)) == true ) {
			$redis->del($redis_key);
			$i++;
		}


	endwhile;

	echo $i++." of " . $wp_query  -> found_posts . " posts was cleared in cache";
	die();
}

function clear_wp_redis_cache_javascript() {
?>
<script type="text/javascript" >
jQuery(document).ready(function($) {

	jQuery('#WPRedisClearCache').click(function(){
		var data = {
			action: 'clear_wp_redis_cache',
		};

		// since 2.8 ajaxurl is always defined in the admin header and points to admin-ajax.php
		$.post(ajaxurl, data, function(response) {
			alert(response);
		});
	});
});
</script>
<?php
}
