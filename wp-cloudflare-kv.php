<?php

/**
 * Cloudflare KV (Key-Value) Store API Class for WordPress.
 *
 * This class provides a simple interface for interacting with a
 * Cloudflare KV namespace, using WordPress's built-in HTTP functions.
 * It handles GET, PUT, and DELETE operations for keys.
 *
 * @version 1.0.0
 * @link https://developers.cloudflare.com/api/operations/workers-kv-namespace-read-key-value-pair
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class Cloudflare_KV {
  
    // --- Connection & API Credentials ---
    private string $api_token;
    private string $api_base_url;

    // --- Public Properties ---
    public bool $show_errors = false;
    public bool $suppress_errors = false;
    public string $last_error = '';
    public ?int $last_response_code = null;

    /**
     * Sets up the KV object with connection details.
     *
     * @param string $account_id   Your Cloudflare Account ID.
     * @param string $api_token    Your Cloudflare API Token with KV access.
     * @param string $namespace_id Your Cloudflare KV Namespace ID.
     */
    public function __construct(string $account_id, string $api_token, string $namespace_id) {
        $this->api_base_url = "https://api.cloudflare.com/client/v4/accounts/{$account_id}/storage/kv/namespaces/{$namespace_id}";
        $this->api_token = $api_token;

        if (defined('WP_DEBUG') && WP_DEBUG) {
            $this->show_errors();
        }
    }

    /**
     * Reads a value from the KV store.
     *
     * @param string $key The key to retrieve.
     * @return mixed The value (string or array/object if JSON) or null if not found/error.
     */
    public function get(string $key) {
        $this->flush_errors();
        $key = urlencode($key);
        $endpoint = "/values/{$key}";
        
        $response = $this->_request($endpoint, ['method' => 'GET']);

        if (is_wp_error($response)) {
            return null;
        }

        // 404 is a valid "not found" response, not an error.
        if ($this->last_response_code === 404) {
            return null;
        }

        $value = wp_remote_retrieve_body($response);

        // Attempt to auto-decode JSON
        $maybe_json = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $maybe_json;
        }

        return $value;
    }

    /**
     * Writes a value to the KV store.
     *
     * @param string $key   The key to store the value under.
     * @param mixed  $value The value to store (string, array, object).
     * @param ?int   $expiration_ttl Optional. Time to live (in seconds) for the key. Must be >= 60.
     * @return bool True on success, false on failure.
     */
    public function put(string $key, $value, ?int $expiration_ttl = null): bool {
        $this->flush_errors();
        $key = urlencode($key);
        $endpoint = "/values/{$key}";

        $query_params = [];
        if ($expiration_ttl) {
            $query_params['expiration_ttl'] = $expiration_ttl;
        }

        if (!empty($query_params)) {
            $endpoint .= '?' . http_build_query($query_params);
        }

        // Auto-encode JSON if value is an array or object
        if (is_array($value) || is_object($value)) {
            $body = wp_json_encode($value);
            $content_type = 'application/json';
        } else {
            $body = (string) $value;
            $content_type = 'text/plain';
        }

        $args = [
            'method'  => 'PUT',
            'body'    => $body,
            'headers' => ['Content-Type' => $content_type],
        ];

        $response = $this->_request($endpoint, $args);
        
        return !is_wp_error($response);
    }

    /**
     * Deletes a key from the KV store.
     *
     * @param string $key The key to delete.
     * @return bool True on success, false on failure.
     */
    public function delete(string $key): bool {
        $this->flush_errors();
        $key = urlencode($key);
        $endpoint = "/values/{$key}";

        $args = ['method' => 'DELETE'];
        
        $response = $this->_request($endpoint, $args);

        return !is_wp_error($response);
    }

    /**
     * Lists keys in the namespace.
     *
     * @param string  $prefix Optional. The prefix to filter keys by.
     * @param int     $limit  Optional. The number of keys to return (max 1000).
     * @param ?string $cursor Optional. The cursor for pagination.
     * @return ?array An array containing 'keys', 'list_complete', and 'cursor', or null on error.
     */
    public function list_keys(string $prefix = '', int $limit = 100, ?string $cursor = null): ?array {
        $this->flush_errors();
        $endpoint = "/keys";
        
        $query_params = [];
        if (!empty($prefix)) {
            $query_params['prefix'] = $prefix;
        }
        if ($limit) {
            $query_params['limit'] = max(1, min(1000, $limit));
        }
        if (!empty($cursor)) {
            $query_params['cursor'] = $cursor;
        }

        if (!empty($query_params)) {
            $endpoint .= '?' . http_build_query($query_params);
        }

        $response = $this->_request($endpoint, ['method' => 'GET']);

        if (is_wp_error($response)) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!$data || !($data['success'] ?? false) || !isset($data['result'])) {
            $this->last_error = 'Failed to parse list_keys response.';
            $this->print_error();
            return null;
        }

        // Return the 'result' block which contains 'keys', 'cursor', etc.
        return $data['result'];
    }

    /**
     * Internal helper to make requests to the Cloudflare API.
     *
     * @param string $endpoint_path The API endpoint path (e.g., "/values/my_key").
     * @param array  $args          The args for wp_remote_request (method, body, etc.).
     * @return array|WP_Error The full WP_Error object on failure, or the response array on success.
     */
    private function _request(string $endpoint_path, array $args)
    {
        $url = $this->api_base_url . $endpoint_path;
        
        $default_headers = [
            'Authorization' => 'Bearer ' . $this->api_token,
            'Content-Type'  => 'application/json' // Default, can be overridden
        ];

        $args['headers'] = array_merge($default_headers, $args['headers'] ?? []);
        $args['timeout'] = 15; // 15 second timeout

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $this->last_error = 'WP_Error: ' . $response->get_error_message();
            $this->print_error();
            return $response;
        }

        $this->last_response_code = wp_remote_retrieve_response_code($response);

        // Check for Cloudflare API errors (non-2xx responses)
        if ($this->last_response_code < 200 || $this->last_response_code >= 300) {
            $body = wp_remote_retrieve_body($response);
            $error_data = json_decode($body, true);

            $this->last_error = 'KV API Error: ';
            if ($error_data && !empty($error_data['errors'])) {
                $this->last_error .= $error_data['errors'][0]['message'] ?? 'Unknown API error.';
            } else {
                $this->last_error .= 'Received HTTP ' . $this->last_response_code;
            }
            
            $this->print_error();
            return new WP_Error('kv_api_error', $this->last_error, ['status' => $this->last_response_code]);
        }
        
        return $response;
    }

    // #################################################################
    // ## ERROR & DEBUG HANDLING
    // #################################################################

    public function print_error($str = ''): void {
        if (!$this->show_errors || $this->suppress_errors) return;
        $error_str = esc_html($str ?: $this->last_error);
        printf('<div class="notice notice-error is-dismissible" style="padding:1em;margin:1em 0;"><p><strong>Cloudflare KV Error:</strong> %s</p></div>', $error_str);
    }
    public function show_errors($show = true): bool { $old = $this->show_errors; $this->show_errors = $show; return $old; }
    public function hide_errors(): bool { return $this->show_errors(false); }
    private function flush_errors(): void { $this->last_error = ''; $this->last_response_code = null; }
}
