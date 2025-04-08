<?php
/**
 * Memcache Object Cache
 *
 * This file provides an object cache implementation using Memcache or Memcached.
 * Place this file in wp-content/object-cache.php to enable caching.
 */

// Detect Memcache(d) to use
if ( class_exists('Memcached') ) {
    // Use Memcached if available
    class WP_Object_Cache {
        private $cache;

        public function __construct() {
            $this->cache = new Memcached();
            $this->cache->addServer('127.0.0.1', 11211);
        }

        public function add($key, $data, $group = 'default', $expire = 0) {
            $key = $this->buildKey($key, $group);
            return $this->cache->add($key, $data, $expire);
        }

        public function get($key, $group = 'default') {
            $key = $this->buildKey($key, $group);
            return $this->cache->get($key);
        }

        public function delete($key, $group = 'default') {
            $key = $this->buildKey($key, $group);
            return $this->cache->delete($key);
        }

        public function flush() {
            return $this->cache->flush();
        }

        private function buildKey($key, $group) {
            return $group . ':' . $key;
        }
    }
} elseif ( class_exists('Memcache') ) {
    // Use Memcache if available
    class WP_Object_Cache {
        private $cache;

        public function __construct() {
            $this->cache = new Memcache();
            $this->cache->addServer('127.0.0.1', 11211);
        }

        public function add($key, $data, $group = 'default', $expire = 0) {
            $key = $this->buildKey($key, $group);
            return $this->cache->add($key, $data, false, $expire);
        }

        public function get($key, $group = 'default') {
            $key = $this->buildKey($key, $group);
            return $this->cache->get($key);
        }

        public function delete($key, $group = 'default') {
            $key = $this->buildKey($key, $group);
            return $this->cache->delete($key);
        }

        public function flush() {
            return $this->cache->flush();
        }

        private function buildKey($key, $group) {
            return $group . ':' . $key;
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