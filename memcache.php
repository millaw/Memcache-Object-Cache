<?php
/**
 * Memcached Object Cache for WordPress
 * 
 * @package WordPress
 * @subpackage Cache
 * @version 2.1
 * @license GPL-2.0+
 */

// ========================
// Configuration Constants
// ========================

if (!defined('WP_CACHE_KEY_PREFIX')) {
    define('WP_CACHE_KEY_PREFIX', 'wp_' . md5(ABSPATH));
}

if (!defined('WP_CACHE_MEMCACHED_SERVER')) {
    define('WP_CACHE_MEMCACHED_SERVER', '127.0.0.1');
}

if (!defined('WP_CACHE_MEMCACHED_PORT')) {
    define('WP_CACHE_MEMCACHED_PORT', 11211);
}

if (!defined('WP_CACHE_DEFAULT_EXPIRE')) {
    define('WP_CACHE_DEFAULT_EXPIRE', 3600); // 1 hour default
}

if (!defined('WP_CACHE_POOL_NAME')) {
    define('WP_CACHE_POOL_NAME', 'wp_' . substr(md5(ABSPATH), 0, 8));
}

if (!defined('WP_CACHE_MAX_RETRIES')) {
    define('WP_CACHE_MAX_RETRIES', 2);
}

if (!defined('WP_CACHE_RETRY_DELAY')) {
    define('WP_CACHE_RETRY_DELAY', 100); // milliseconds
}

if (!defined('WP_CACHE_IGNORE_FAILURES')) {
    define('WP_CACHE_IGNORE_FAILURES', false);
}

// Add a WP_CACHE_DEBUG constant to control error logging verbosity
if (!defined('WP_CACHE_DEBUG')) {
    define('WP_CACHE_DEBUG', false);
}

// ========================
// Cache Implementation
// ========================

if (class_exists('Memcached') || class_exists('Memcache')) {
    class WP_Object_Cache {
        private $cache;
        private $is_connected = false;
        private $key_prefix;
        private $max_key_length = 250;
        private $global_groups = array();
        private $non_persistent_groups = array();
        private $cache_hits = 0;
        private $cache_misses = 0;
        private $default_expire;
        private $retry_count = 0;
        private $last_error = null;
        private $ignored_groups = array('counts', 'plugins', 'theme_json');

        public function __construct() {
            $this->key_prefix = WP_CACHE_KEY_PREFIX;
            $this->default_expire = WP_CACHE_DEFAULT_EXPIRE;
            $this->connect();
        }

        // Add IP whitelisting for Memcached server
        private function is_ip_whitelisted($ip) {
            $whitelisted_ips = ['127.0.0.1']; // Add your IPs here
            return in_array($ip, $whitelisted_ips);
        }

        private function connect() {
            if (!$this->is_ip_whitelisted(WP_CACHE_MEMCACHED_SERVER)) {
                $this->log_error('Connection denied: IP not whitelisted');
                return;
            }
            try {
                if (class_exists('Memcached')) {
                    $this->init_memcached();
                } elseif (class_exists('Memcache')) {
                    $this->init_memcache();
                }

                $this->verify_connection();
                $this->check_extension_versions();
            } catch (Exception $e) {
                $this->handle_connection_error($e->getMessage());
            }

            if (!$this->is_connected && !WP_CACHE_IGNORE_FAILURES) {
                $this->show_admin_notice();
            }
        }

        private function init_memcached() {
            $this->cache = new Memcached(WP_CACHE_POOL_NAME);
            $this->cache->setOption(Memcached::OPT_COMPRESSION, true);
            $this->cache->setOption(Memcached::OPT_LIBKETAMA_COMPATIBLE, true);
            $this->cache->setOption(Memcached::OPT_CONNECT_TIMEOUT, 1000);
            $this->cache->setOption(Memcached::OPT_RETRY_TIMEOUT, 1);
            $this->cache->setOption(Memcached::OPT_SERVER_FAILURE_LIMIT, 2);
            
            if (empty($this->cache->getServerList())) {
                $this->is_connected = $this->cache->addServer(
                    WP_CACHE_MEMCACHED_SERVER, 
                    WP_CACHE_MEMCACHED_PORT
                );
            } else {
                $this->is_connected = true;
            }
        }

        private function init_memcache() {
            $this->cache = new Memcache();
            $this->cache->setOption(Memcached::OPT_CONNECT_TIMEOUT, 1000);
            $this->is_connected = $this->cache->addServer(
                WP_CACHE_MEMCACHED_SERVER,
                WP_CACHE_MEMCACHED_PORT,
                true,
                1,
                1,
                15,
                true,
                array($this, 'failureCallback')
            );
        }

        private function verify_connection() {
            if ($this->is_connected) {
                try {
                    $test_key = $this->buildKey('connection_test', 'wp_cache');
                    $this->cache->set($test_key, 1, 1);
                    $test_value = $this->cache->get($test_key);
                    $this->is_connected = ($test_value === 1);
                    $this->cache->delete($test_key);
                } catch (Exception $e) {
                    $this->is_connected = false;
                    $this->last_error = 'Connection test failed: ' . $e->getMessage();
                }
            }
        }

        // Add version checking for Memcached/Memcache extensions
        private function check_extension_versions() {
            if (class_exists('Memcached')) {
                $version = phpversion('memcached');
                if (version_compare($version, '3.0.0', '<')) {
                    $this->log_error('Memcached extension version is outdated: ' . $version);
                }
            } elseif (class_exists('Memcache')) {
                $version = phpversion('memcache');
                if (version_compare($version, '3.0.0', '<')) {
                    $this->log_error('Memcache extension version is outdated: ' . $version);
                }
            }
        }

        // Add SASL authentication support for Memcached
        private function enable_sasl_authentication($username, $password) {
            if (class_exists('Memcached')) {
                $this->cache->setOption(Memcached::OPT_BINARY_PROTOCOL, true);
                $this->cache->setSaslAuthData($username, $password);
            }
        }

        // Deferred connection verification until the first cache operation
        public function ensure_connection() {
            if (!$this->is_connected) {
                $this->connect();
            }
        }

        public function failureCallback($host, $port) {
            $this->last_error = "Connection failure to $host:$port";
            $this->is_connected = false;
            $this->log_error($this->last_error);
            
            if ($this->retry_count < WP_CACHE_MAX_RETRIES) {
                usleep(WP_CACHE_RETRY_DELAY * 1000);
                $this->retry_count++;
                $this->connect();
            }
        }

        private function handle_connection_error($message) {
            $this->last_error = $message;
            $this->is_connected = false;
            $this->log_error($message);
        }

        private function log_error($message) {
            if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log('[WP Object Cache] ' . $message);
                if (WP_CACHE_DEBUG && $this->last_error) {
                    error_log('[WP Object Cache Debug] Last error: ' . $this->last_error);
                }
            }
        }

        public function add($key, $data, $group = 'default', $expire = null) {
            if (!$this->should_cache($group) || !$this->is_connected) {
                return false;
            }
    
            if (!$this->validateGroup($group)) {
                return false;
            }
            
            $key = $this->buildKey($key, $group);
            if (!$this->validateKey($key)) {
                return false;
            }
            
            $expire = $this->parse_expire($expire);
            $data = $this->maybe_serialize($data);
            
            return $this->cache->add($key, $data, $expire);
        }

        public function set($key, $data, $group = 'default', $expire = null) {
            if (!$this->should_cache($group) || !$this->is_connected) {
                return false;
            }
            
            $key = $this->buildKey($key, $group);
            if (!$this->validateKey($key)) {
                return false;
            }
            
            $expire = $this->parse_expire($expire);
            $data = $this->maybe_serialize($data);
            
            return $this->cache->set($key, $data, $expire);
        }

        public function replace($key, $data, $group = 'default', $expire = null) {
            if (!$this->should_cache($group) || !$this->is_connected) {
                return false;
            }
            
            $key = $this->buildKey($key, $group);
            if (!$this->validateKey($key)) {
                return false;
            }
            
            $expire = $this->parse_expire($expire);
            $data = $this->maybe_serialize($data);
            
            return $this->cache->replace($key, $data, $expire);
        }

        // Add microtiming for cache operations when debugging
        private function microtime_diff($start, $end) {
            return ($end - $start) * 1000; // Return time in milliseconds
        }

        public function get($key, $group = 'default', $force = false, &$found = null) {
            $start_time = microtime(true);
            $found = false;
            
            if (!$this->should_cache($group)) {
                return false;
            }
            
            if (!$this->is_connected) {
                $this->cache_misses++;
                return false;
            }
            
            $key = $this->buildKey($key, $group);
            if (!$this->validateKey($key)) {
                $this->cache_misses++;
                return false;
            }
            
            $data = $this->cache->get($key);
            
            if ($data === false) {
                $this->cache_misses++;
                return false;
            }
            
            $found = true;
            $this->cache_hits++;
            $result = $this->maybe_unserialize($data);

            if (WP_CACHE_DEBUG) {
                $end_time = microtime(true);
                $this->log_error('GET operation took ' . $this->microtime_diff($start_time, $end_time) . ' ms');
            }

            return $result;
        }

        public function delete($key, $group = 'default') {
            if (!$this->should_cache($group) || !$this->is_connected) {
                return false;
            }
            
            $key = $this->buildKey($key, $group);
            if (!$this->validateKey($key)) {
                return false;
            }
            
            return $this->cache->delete($key);
        }

        public function increment($key, $offset = 1, $group = 'default') {
            if (!$this->should_cache($group) || !$this->is_connected) {
                return false;
            }
            
            $key = $this->buildKey($key, $group);
            if (!$this->validateKey($key)) {
                return false;
            }
            
            return $this->cache->increment($key, $offset);
        }

        public function decrement($key, $offset = 1, $group = 'default') {
            if (!$this->should_cache($group) || !$this->is_connected) {
                return false;
            }
            
            $key = $this->buildKey($key, $group);
            if (!$this->validateKey($key)) {
                return false;
            }
            
            return $this->cache->decrement($key, $offset);
        }

        public function flush() {
            if (!$this->is_connected) {
                return false;
            }
            return $this->cache->flush();
        }

        public function add_global_groups($groups) {
            $groups = (array)$groups;
            $this->global_groups = array_merge($this->global_groups, $groups);
            $this->global_groups = array_unique($this->global_groups);
        }

        public function add_non_persistent_groups($groups) {
            $groups = (array)$groups;
            $this->non_persistent_groups = array_merge($this->non_persistent_groups, $groups);
            $this->non_persistent_groups = array_unique($this->non_persistent_groups);
        }

        public function stats() {
            return array(
                'hits' => $this->cache_hits,
                'misses' => $this->cache_misses,
                'global_groups' => $this->global_groups,
                'non_persistent_groups' => $this->non_persistent_groups,
                'ignored_groups' => $this->ignored_groups,
                'connected' => $this->is_connected,
                'servers' => array(WP_CACHE_MEMCACHED_SERVER . ':' . WP_CACHE_MEMCACHED_PORT),
                'stats' => $this->is_connected ? $this->cache->getStats() : array(),
                'last_error' => $this->last_error
            );
        }

        public function close() {
            if ($this->is_connected && method_exists($this->cache, 'quit')) {
                return $this->cache->quit();
            }
            return true;
        }

        private function should_cache($group) {
            if (in_array($group, $this->ignored_groups)) {
                return false;
            }
            return true;
        }

        private function buildKey($key, $group) {
            if (is_array($group)) {
                $group = implode(':', array_map(array($this, 'sanitize_cache_key_part'), $group));
            } else {
                $group = $this->sanitize_cache_key_part($group);
            }
            
            $key = $this->sanitize_cache_key_part($key);
            
            $prefix = in_array($group, $this->global_groups) ? '' : $this->key_prefix;
            return $prefix . $group . ':' . $key;
        }

        private function sanitize_cache_key_part($part) {
            $part = (string)$part;
            $part = preg_replace('/[^a-zA-Z0-9_\-:]/', '', $part); // Stricter regex to allow only alphanumeric, underscore, hyphen, and colon
            return substr($part, 0, $this->max_key_length);
        }

        private function validateKey($key) {
            if (!is_string($key) || empty($key)) {
                $this->log_error('Invalid cache key: must be non-empty string');
                return false;
            }
            
            if (strlen($key) > $this->max_key_length) {
                $this->log_error('Cache key exceeds maximum length');
                return false;
            }
            
            return true;
        }

        private function validateGroup($group) {
            if (!is_string($group)) {
                $this->log_error('Cache group must be a string');
                return false;
            }
            return true;
        }

        private function parse_expire($expire) {
            if ($expire === null) {
                return $this->default_expire;
            }
            return (int)$expire;
        }

        private function maybe_serialize($data) {
            if (is_object($data)) {
                $data = clone $data;
            }
            
            // Don't serialize resources or closures
            if (is_resource($data) || $data instanceof Closure) {
                $this->log_error('Attempted to cache invalid data type');
                return false;
            }
            
            return is_scalar($data) ? $data : serialize($data);
        }

        private function maybe_unserialize($data) {
            if (!is_string($data)) {
                return $data;
            }
            
            if ($this->is_serialized($data)) {
                try {
                    return unserialize($data, ['allowed_classes' => false]);
                } catch (Exception $e) {
                    $this->log_error('Unserialize error: ' . $e->getMessage());
                    return false;
                }
            }
            
            return $data;
        }

        private function is_serialized($data) {
            if (!is_string($data)) {
                return false;
            }
            
            $data = trim($data);
            if ('N;' === $data) {
                return true;
            }
            
            if (!preg_match('/^([adObis]):/', $data, $matches)) {
                return false;
            }
            
            switch ($matches[1]) {
                case 'a':
                case 'O':
                case 's':
                    if (preg_match("/^{$matches[1]}:[0-9]+:.*[;}]\$/s", $data)) {
                        return true;
                    }
                    break;
                case 'b':
                case 'i':
                case 'd':
                    if (preg_match("/^{$matches[1]}:[0-9.E-]+;\$/", $data)) {
                        return true;
                    }
                    break;
            }
            
            return false;
        }

        public function show_admin_notice() {
            add_action('admin_notices', function() {
                $screen = get_current_screen();
                if ($screen && (strpos($screen->base, 'plugins') !== false || 
                               strpos($screen->base, 'dashboard') !== false ||
                               strpos($screen->base, 'settings') !== false)) {
                    $class = WP_CACHE_IGNORE_FAILURES ? 'notice-warning' : 'notice-error';
                    echo '<div class="notice '.$class.' is-dismissible">';
                    echo '<p><strong>WP Object Cache:</strong> ';
                    echo WP_CACHE_IGNORE_FAILURES 
                        ? 'Running in degraded mode with caching disabled due to connection failures.'
                        : 'Failed to connect to Memcache/Memcached server. Caching disabled.';
                    echo '</p>';
                    
                    if ($this->last_error) {
                        echo '<p><small>Error: ' . esc_html($this->last_error) . '</small></p>';
                    }
                    
                    if (current_user_can('manage_options')) {
                        echo '<p><small>Server: '.WP_CACHE_MEMCACHED_SERVER.':'.WP_CACHE_MEMCACHED_PORT.'</small></p>';
                    }
                    echo '</div>';
                }
            });
        }
    }

    // ========================
    // WordPress Cache API
    // ========================

    global $wp_object_cache;

    function wp_cache_add($key, $data, $group = '', $expire = 0) {
        global $wp_object_cache;
        return $wp_object_cache->add($key, $data, $group, $expire);
    }

    function wp_cache_set($key, $data, $group = '', $expire = 0) {
        global $wp_object_cache;
        return $wp_object_cache->set($key, $data, $group, $expire);
    }

    function wp_cache_replace($key, $data, $group = '', $expire = 0) {
        global $wp_object_cache;
        return $wp_object_cache->replace($key, $data, $group, $expire);
    }

    function wp_cache_get($key, $group = '', $force = false, &$found = null) {
        global $wp_object_cache;
        return $wp_object_cache->get($key, $group, $force, $found);
    }

    function wp_cache_delete($key, $group = '') {
        global $wp_object_cache;
        return $wp_object_cache->delete($key, $group);
    }

    function wp_cache_flush() {
        global $wp_object_cache;
        return $wp_object_cache->flush();
    }

    function wp_cache_add_global_groups($groups) {
        global $wp_object_cache;
        $wp_object_cache->add_global_groups($groups);
    }

    function wp_cache_add_non_persistent_groups($groups) {
        global $wp_object_cache;
        $wp_object_cache->add_non_persistent_groups($groups);
    }

    function wp_cache_close() {
        global $wp_object_cache;
        return $wp_object_cache->close();
    }

    function wp_cache_incr($key, $offset = 1, $group = '') {
        global $wp_object_cache;
        return $wp_object_cache->increment($key, $offset, $group);
    }

    function wp_cache_decr($key, $offset = 1, $group = '') {
        global $wp_object_cache;
        return $wp_object_cache->decrement($key, $offset, $group);
    }

    function wp_cache_init() {
        global $wp_object_cache;
        $wp_object_cache = new WP_Object_Cache();
    }

    function wp_cache_stats() {
        global $wp_object_cache;
        return $wp_object_cache->stats();
    }

    // Initialize the cache
    $wp_object_cache = new WP_Object_Cache();

} else {
    // ========================
    // Fallback Implementation
    // ========================

    function wp_cache_add($key, $data, $group = '', $expire = 0) { 
        trigger_error('Memcache(d) not available', E_USER_NOTICE);
        return false; 
    }

    function wp_cache_set($key, $data, $group = '', $expire = 0) { 
        return false; 
    }

    function wp_cache_replace($key, $data, $group = '', $expire = 0) { 
        return false; 
    }

    function wp_cache_get($key, $group = '', $force = false, &$found = null) { 
        $found = false;
        return false; 
    }

    function wp_cache_delete($key, $group = '') { 
        return false; 
    }

    function wp_cache_flush() { 
        return false; 
    }

    function wp_cache_add_global_groups($groups) { 
        return false; 
    }

    function wp_cache_add_non_persistent_groups($groups) { 
        return false; 
    }

    function wp_cache_close() { 
        return false; 
    }

    function wp_cache_incr($key, $offset = 1, $group = '') { 
        return false; 
    }

    function wp_cache_decr($key, $offset = 1, $group = '') { 
        return false; 
    }

    function wp_cache_init() { 
        return false; 
    }

    function wp_cache_stats() { 
        return array('status' => 'Not available');
    }

    add_action('admin_notices', function() {
        $screen = get_current_screen();
        if ($screen && (strpos($screen->base, 'plugins') !== false || strpos($screen->base, 'dashboard') !== false)) {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p>Memcache or Memcached is not available. Object caching is disabled.</p>';
            echo '</div>';
        }
    });
}
