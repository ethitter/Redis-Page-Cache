## Wp Redis Cache

Cache WordPress using Redis, the fastest way to date to cache WordPress.

### Requirements
------
* [WordPress](http://wordpress.org) - CMS framework/blogging system
* [Redis](http://redis.io/) - Key Value in memory caching
* [Predis](https://github.com/nrk/predis) - PHP api for Redis

### Installation
------
Install Redis, must have root access to your machine. On debian it's as simple as:
```bash
sudo apt-get install redis-server
```
On other systems please refer to the [Redis website](http://redis.io/).

You can install the pecl extension (faster)
```
apt-get install php5-redis
```
If you don't have the pecl extension installed it will default to use [Predis](https://github.com/nrk/predis)

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

We do this because WordPress is no longer in charge of displaying our posts.  Redis will now serve the post if it is in the cache.  If the post is not in the Redis cache it will then call WordPress to serve the page and then cache it for the next pageload


### Benchmark
------
I welcome you to compare the page load times of this caching system with other popular Caching plugins such as [Wp Super Cache](http://wordpress.org/plugins/wp-super-cache/) and [W3 Total Cache](http://wordpress.org/plugins/w3-total-cache/)

With a fresh WordPress install:

Wp Super Cache
```
Page generated in 0.318 seconds.
```

W3 Total Cache
```
Page generated in 0.30484 seconds.
```

Wp Redis Cache
```
Page generated in 0.00902 seconds.
```

