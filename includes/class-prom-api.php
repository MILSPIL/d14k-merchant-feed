<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Prom.ua REST API Client
 *
 * Wraps all HTTP calls to the Prom.ua Public API v1.
 * API base URL: https://my.prom.ua/api/v1/
 * Authentication: Authorization: Bearer {token}
 *
 * @since 4.0.0
 */
class D14K_Prom_API
{
    const API_BASE = 'https://my.prom.ua/api/v1/';
    const TIMEOUT  = 30;

    /** @var string */
    private $token;

    /** @var array Last raw response info */
    public $last_error = '';

    public function __construct($token = '')
    {
        $this->token = $token ?: $this->get_token_from_settings();
    }

    // -------------------------------------------------------------------------
    // Token helpers
    // -------------------------------------------------------------------------

    private function get_token_from_settings()
    {
        $settings = get_option('d14k_feed_settings', array());
        return isset($settings['prom_api_token']) ? trim($settings['prom_api_token']) : '';
    }

    public function has_token()
    {
        return !empty($this->token);
    }

    // -------------------------------------------------------------------------
    // Generic HTTP helpers
    // -------------------------------------------------------------------------

    /**
     * Make a GET request using cURL directly.
     * NOTE: wp_remote_get returns empty body on Hostinger for Prom.ua responses,
     * so we use raw cURL which works correctly.
     *
     * @param string $endpoint  e.g. "products/list"
     * @param array  $params    Query string params
     * @return array|false      Decoded JSON array or false on error
     */
    private function get($endpoint, $params = array())
    {
        $url = self::API_BASE . ltrim($endpoint, '/');
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        return $this->curl_request('GET', $url);
    }

    /**
     * Make a POST request with JSON body using cURL directly.
     *
     * @param string $endpoint
     * @param array  $body
     * @return array|false
     */
    private function post($endpoint, $body = array())
    {
        $url = self::API_BASE . ltrim($endpoint, '/');
        return $this->curl_request('POST', $url, $body);
    }

    /**
     * Make a PUT request with JSON body using cURL directly.
     */
    private function put($endpoint, $body = array())
    {
        $url = self::API_BASE . ltrim($endpoint, '/');
        return $this->curl_request('PUT', $url, $body);
    }

    /**
     * Raw cURL request — bypasses wp_remote_get which returns empty body
     * on Hostinger when Prom.ua sends gzip-encoded responses.
     *
     * @param string $method  GET | POST | PUT
     * @param string $url
     * @param array  $body    For POST/PUT requests
     * @return array|false
     */
    private function curl_request($method, $url, $body = array())
    {
        if (!function_exists('curl_init')) {
            // Fallback to wp_remote_* if cURL not available
            return $this->wp_request($method, $url, $body);
        }

        $ch = curl_init($url);

        $http_headers = array(
            'Authorization: Bearer ' . $this->token,
            'Accept: application/json',
            'Accept-Encoding: identity',
        );

        $opts = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        );

        if ($method === 'POST') {
            $json_body = wp_json_encode($body);
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = $json_body;
            $http_headers[]           = 'Content-Type: application/json';
            $http_headers[]           = 'Content-Length: ' . strlen($json_body);
        } elseif ($method === 'PUT') {
            $json_body = wp_json_encode($body);
            $opts[CURLOPT_CUSTOMREQUEST] = 'PUT';
            $opts[CURLOPT_POSTFIELDS]    = $json_body;
            $http_headers[]              = 'Content-Type: application/json';
            $http_headers[]              = 'Content-Length: ' . strlen($json_body);
        }

        $opts[CURLOPT_HTTPHEADER] = $http_headers;
        curl_setopt_array($ch, $opts);

        $response_body = curl_exec($ch);
        $http_code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error    = curl_error($ch);
        curl_close($ch);

        if ($curl_error) {
            $this->last_error = 'cURL error: ' . $curl_error;
            error_log("D14K Prom API [{$url}] cURL error: " . $curl_error);
            return false;
        }

        $data = json_decode($response_body, true);

        if ($http_code < 200 || $http_code >= 300) {
            $msg = isset($data['errors']) ? implode('; ', (array) $data['errors']) : substr($response_body, 0, 200);
            $this->last_error = "HTTP {$http_code}: {$msg}";
            error_log("D14K Prom API [{$url}] HTTP {$http_code}: {$msg}");
            return false;
        }

        if (!is_array($data)) {
            $this->last_error = 'Invalid JSON response (body length: ' . strlen($response_body) . ')';
            error_log("D14K Prom API [{$url}] Invalid JSON");
            return false;
        }

        $this->last_error = '';
        return $data;
    }

    /**
     * Fallback: use WordPress HTTP API (may not work on all hosts).
     */
    private function wp_request($method, $url, $body = array())
    {
        $args = array(
            'method'  => $method,
            'timeout' => self::TIMEOUT,
            'headers' => array(
                'Authorization'   => 'Bearer ' . $this->token,
                'Accept'          => 'application/json',
                'Content-Type'    => 'application/json',
                'Accept-Encoding' => 'identity',
            ),
        );
        if (in_array($method, array('POST', 'PUT'), true) && !empty($body)) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $this->last_error = $response->get_error_message();
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $rbody = wp_remote_retrieve_body($response);
        $data = json_decode($rbody, true);

        if ($code < 200 || $code >= 300) {
            $msg = isset($data['errors']) ? implode('; ', (array) $data['errors']) : $rbody;
            $this->last_error = "HTTP {$code}: {$msg}";
            return false;
        }

        if (!is_array($data)) {
            $this->last_error = 'Invalid JSON response';
            return false;
        }

        $this->last_error = '';
        return $data;
    }

    // -------------------------------------------------------------------------
    // Products
    // -------------------------------------------------------------------------

    /**
     * Get a paginated list of products.
     *
     * @param int    $page       1-based page number
     * @param int    $limit      Products per page (max 100)
     * @param int    $group_id   Filter by Prom group/category ID (0 = all)
     * @return array|false       ['products' => [...], 'group_id' => int]
     */
    /**
     * Get products from Prom.ua.
     * Prom API uses cursor-based pagination via last_id, NOT page numbers.
     *
     * @param int $last_id   ID of the last product from previous batch (0 = first batch)
     * @param int $limit     Products per request (max 100)
     * @param int $group_id  Filter by Prom group (0 = all)
     * @return array|false
     */
    public function get_products($last_id = 0, $limit = 100, $group_id = 0)
    {
        $params = array(
            'limit' => min(100, max(1, (int) $limit)),
        );
        if ($last_id > 0) {
            $params['last_id'] = (int) $last_id;
        }
        if ($group_id > 0) {
            $params['group_id'] = (int) $group_id;
        }
        return $this->get('products/list', $params);
    }

    /**
     * Get ALL products across all pages (use carefully on large catalogs).
     *
     * @param int $group_id  Filter by group (0 = all)
     * @return array         Flat array of product objects
     */
    public function get_all_products($group_id = 0)
    {
        $all     = array();
        $page    = 1;
        $limit   = 100;

        do {
            $result = $this->get_products($page, $limit, $group_id);
            if ($result === false) {
                break;
            }
            $batch = isset($result['products']) ? (array) $result['products'] : array();
            $all   = array_merge($all, $batch);
            $page++;
            // If we got fewer than the limit, we're done
        } while (count($batch) === $limit);

        return $all;
    }

    /**
     * Get a single product by its Prom ID.
     *
     * @param int $prom_id
     * @return array|false
     */
    public function get_product($prom_id)
    {
        return $this->get("products/{$prom_id}");
    }

    /**
     * Get a single product by external_id (= WooCommerce post ID).
     *
     * @param string|int $external_id
     * @return array|false
     */
    public function get_product_by_external_id($external_id)
    {
        return $this->get("products/by_external_id/{$external_id}");
    }

    /**
     * Update one product on Prom.ua.
     *
     * @param int   $prom_id   Required — the Prom product ID
     * @param array $fields    Fields to update (price, presence, status, etc.)
     * @return array|false
     */
    public function edit_product($prom_id, $fields = array())
    {
        $body         = $fields;
        $body['id']   = (int) $prom_id;
        return $this->post('products/edit', $body);
    }

    /**
     * Update a product on Prom by external_id (= WooCommerce post ID).
     *
     * @param string|int $external_id
     * @param array      $fields
     * @return array|false
     */
    public function edit_product_by_external_id($external_id, $fields = array())
    {
        $body                = $fields;
        $body['external_id'] = (string) $external_id;
        return $this->post('products/edit_by_external_id', $body);
    }

    /**
     * Trigger a product import on Prom.ua from a publicly accessible XML/YML URL.
     * This is the most efficient way to sync a large catalog from WooCommerce → Prom.
     *
     * @param string $feed_url   Public URL of the YML/XML feed
     * @param bool   $force      Force full reimport even if feed unchanged
     * @return array|false       ['import_id' => int] or false
     */
    public function import_from_url($feed_url, $force = false)
    {
        $body = array('url' => $feed_url);
        if ($force) {
            $body['force'] = true;
        }
        return $this->post('products/import_url', $body);
    }

    /**
     * Check the status of an import task.
     *
     * @param int $import_id   Returned by import_from_url()
     * @return array|false     Status object with 'status', 'added', 'updated', 'errors'
     */
    public function get_import_status($import_id)
    {
        return $this->get("products/import/status/{$import_id}");
    }

    // -------------------------------------------------------------------------
    // Groups (Categories)
    // -------------------------------------------------------------------------

    /**
     * Get the full category tree from Prom.ua.
     *
     * @return array|false   ['groups' => [...]]
     */
    public function get_groups()
    {
        return $this->get('groups/list');
    }

    // -------------------------------------------------------------------------
    // Orders (for future use)
    // -------------------------------------------------------------------------

    /**
     * Get orders from Prom.ua.
     *
     * @param array $params   Optional filters: status, date_from, date_to, limit, page
     * @return array|false
     */
    public function get_orders($params = array())
    {
        return $this->get('orders/list', $params);
    }

    /**
     * Change order status on Prom.ua.
     *
     * @param int    $order_id
     * @param string $status   e.g. 'payed', 'sent', 'delivered', 'cancelled'
     * @return array|false
     */
    public function set_order_status($order_id, $status)
    {
        return $this->post('orders/set_status', array(
            'ids'    => array((int) $order_id),
            'status' => $status,
        ));
    }

    // -------------------------------------------------------------------------
    // Connection test
    // -------------------------------------------------------------------------

    /**
     * Test API connection by fetching a single product page.
     * Returns array with 'success' (bool) and 'message' (string).
     *
     * @return array
     */
    public function test_connection()
    {
        if (!$this->has_token()) {
            return array('success' => false, 'message' => 'API-токен не вказано');
        }

        $result = $this->get_products(1, 1);
        if ($result === false) {
            return array('success' => false, 'message' => $this->last_error ?: 'Помилка з\'єднання');
        }

        $count = isset($result['products']) ? count($result['products']) : 0;
        return array(
            'success' => true,
            'message' => 'З\'єднання успішне. Знайдено товарів у першій сторінці: ' . $count,
        );
    }
}
