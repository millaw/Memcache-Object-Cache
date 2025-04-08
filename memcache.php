<?php
/**
 * Memcache Object Cache
 *
 * This file provides an object cache implementation using Memcache or Memcached.
 * Place this file in wp-content/object-cache.php to enable caching.
 */

// Detect Memcache(d) to use
if ( class_exists('Memcached') || class_exists('Memcache') ) {
    // Add error handling for addServer and validate inputs
    class WP_Object_Cache {
        private $cache;

        public function __construct() {
            $this->cache = class_exists('Memcached') ? new Memcached() : new Memcache();
            $connected = $this->cache->addServer('127.0.0.1', 11211);

            if (!$connected) {
                add_action('admin_notices', function() {
                    echo '<div class="error"><p>Failed to connect to Memcache/Memcached server at 127.0.0.1:11211. Object caching is disabled.</p></div>';
                });
            }
        }

        public function add($key, $data, $group = 'default', $expire = 0) {
            $key = $this->validateKey($key);
            $group = $this->validateGroup($group);
            $key = $this->buildKey($key, $group);
            return $this->cache->add($key, $data, $expire);
        }

        public function get($key, $group = 'default') {
            $key = $this->validateKey($key);
            $group = $this->validateGroup($group);
            $key = $this->buildKey($key, $group);
            return $this->cache->get($key);
        }

        public function delete($key, $group = 'default') {
            $key = $this->validateKey($key);
            $group = $this->validateGroup($group);
            $key = $this->buildKey($key, $group);
            return $this->cache->delete($key);
        }

        public function flush() {
            return $this->cache->flush();
        }

        private function buildKey($key, $group) {
            return $group . ':' . $key;
        }

        private function validateKey($key) {
            if (!is_string($key) || empty($key)) {
                throw new InvalidArgumentException('Cache key must be a non-empty string.');
            }
            return $key;
        }

        private function validateGroup($group) {
            if (!is_string($group)) {
                throw new InvalidArgumentException('Cache group must be a string.');
            }
            return $group;
        }
    }
} else {
    // Neither Memcached nor Memcache is available
    function wp_cache_add() {
        return false;
    }

    function wp_cache_get() {
        return false;
    }

    function wp_cache_delete() {
        return false;
    }

    function wp_cache_flush() {
        return false;
    }

    add_action('admin_notices', function() {
        echo '<div class="error"><p>Memcache or Memcached is not available. Object caching is disabled.</p></div>';
    });
}

// Initialize the object cache
global $wp_object_cache;
$wp_object_cache = new WP_Object_Cache();
?>
