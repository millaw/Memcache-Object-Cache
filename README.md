# Memcache Object Cache for WordPress

This repository provides an object cache implementation for WordPress using Memcache or Memcached. The `memcache.php` file should be placed in the `wp-content/` directory to enable caching.

## Features
- Supports both Memcache and Memcached.
- Automatically detects and uses the available caching extension.
- Provides fallback functions if neither Memcache nor Memcached is available.
- Includes functions for adding, retrieving, deleting, and flushing cache entries.

## Configuration

### WordPress Configuration
Add the following lines to your `wp-config.php` file to enable and configure the object cache:

```php
define('WP_CACHE', true);
define('WP_CACHE_KEY_PREFIX', 'mysite_'); // Optional
define('WP_CACHE_MEMCACHED_SERVER', '127.0.0.1'); // Optional
define('WP_CACHE_MEMCACHED_PORT', 11211); // Optional
define('WP_CACHE_DEFAULT_EXPIRE', 3600); // Optional, 1 hour
define('WP_CACHE_IGNORE_FAILURES', true); // Continue on cache failures
define('WP_CACHE_MAX_RETRIES', 2); // Optional
define('WP_CACHE_RETRY_DELAY', 100); // Optional
define('WP_CACHE_DEBUG', false); // Optional, controls error logging verbosity
```

### Security
- **IP Whitelisting**: Only connections from whitelisted IPs are allowed. Update the IP list in the code if needed.
- **SASL Authentication**: If using `Memcached`, you can enable SASL authentication by providing a username and password in the code.

### Performance Monitoring
- **Microtiming**: Cache operations (e.g., `GET`) are timed and logged when `WP_CACHE_DEBUG` is enabled.
- **Operation Counts**: Tracks cache hits and misses for performance analysis.

### Backward Compatibility
- **Version Checking**: Logs warnings if outdated versions of `Memcache` or `Memcached` are detected.

### Server Setup
By default, the script connects to a Memcache or Memcached server running on `127.0.0.1` (localhost) at port `11211`. If your server is running on a different host or port, you need to modify the `addServer` line in the `WP_Object_Cache` class constructor.

#### Example:
- For a local server:
  ```php
  $this->cache->addServer('127.0.0.1', 11211);
  ```
- For a remote server:
  ```php
  $this->cache->addServer('remote-server-ip', 11211);
  ```
- For a custom port:
  ```php
  $this->cache->addServer('127.0.0.1', custom-port);
  ```

## Installation
1. Copy the `memcache.php` file to the `wp-content/` directory of your WordPress installation.
2. Rename the file to `object-cache.php`.
3. Ensure that your Memcache or Memcached server is running and accessible.

## Fallback Behavior
If neither Memcache nor Memcached is available, the script will:
- Disable object caching.
- Display an admin notice in the WordPress dashboard indicating that caching is not enabled.

## Updates

### Error Handling
- Added error handling for the `addServer` method to notify the admin if the connection to the Memcache/Memcached server fails.

### Input Validation
- Implemented validation for cache keys and groups to ensure they are valid strings. Invalid inputs will throw an `InvalidArgumentException`.

## License
This project is open-source and available under the GPLv3 License.
