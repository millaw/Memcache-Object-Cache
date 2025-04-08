# Memcache Object Cache for WordPress

This repository provides an object cache implementation for WordPress using Memcache or Memcached. The `memcache.php` file should be placed in the `wp-content/` directory to enable caching.

## Features
- Supports both Memcache and Memcached.
- Automatically detects and uses the available caching extension.
- Provides fallback functions if neither Memcache nor Memcached is available.
- Includes functions for adding, retrieving, deleting, and flushing cache entries.

## Configuration

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
