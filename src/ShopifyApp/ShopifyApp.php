<?php

namespace HulkApps\ShopifyApp;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use HulkApps\ShopifyApp\Models\Shop;
use HulkApps\ShopifyApp\Services\ShopSession;

/**
 * The base "helper" class for this package.
 */
class ShopifyApp
{
    /**
     * Laravel application.
     *
     * @var \Illuminate\Foundation\Application
     */
    public $app;

    /**
     * The current shop.
     *
     * @var \HulkApps\ShopifyApp\Models\Shop
     */
    public $shop;

    /**
     * Create a new confide instance.
     *
     * @param \Illuminate\Foundation\Application $app
     *
     * @return void
     */
    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * Gets/sets the current shop.
     *
     * @param string|null $shopDomain
     *
     * @return \HulkApps\ShopifyApp\Models\Shop
     */
    public function shop(string $shopDomain = null)
    {
        $shopifyDomain = $shopDomain ? $this->sanitizeShopDomain($shopDomain) : (new ShopSession())->getDomain();
        if (!$this->shop && $shopifyDomain) {
            // Grab shop from database here
            $shopModel = Config::get('shopify-app.shop_model');
            $shop = $shopModel::withTrashed()->firstOrCreate(['shopify_domain' => $shopifyDomain]);

            // Update shop instance
            $this->shop = $shop;
        }

        return $this->shop;
    }

    /**
     * Gets an API instance.
     *
     * @return \HulkApps\BasicShopifyAPI
     */
    public function api()
    {
        $apiClass = Config::get('shopify-app.api_class');
        $api = new $apiClass();
        $api->setApiKey(Config::get('shopify-app.api_key'));
        $api->setApiSecret(Config::get('shopify-app.api_secret'));

        // Add versioning?
        $version = Config::get('shopify-app.api_version');
        if ($version !== null) {
            $api->setVersion($version);
        }

        // Enable basic rate limiting?
        if (Config::get('shopify-app.api_rate_limiting_enabled') === true) {
            $api->enableRateLimiting(
                Config::get('shopify-app.api_rate_limit_cycle'),
                Config::get('shopify-app.api_rate_limit_cycle_buffer')
            );
        }

        return $api;
    }


    /**
     * Do GraphQL call
     *
     * @param string $query
     * @param array|null $payload
     * @return mixed
     */
    public function doRequestGraphQL(string $query, array $payload = null)
    {
        $response = json_decode(json_encode($this->shop->api()->graph($query, $payload)),true);
        if ($response['errors'] !== false) {
            $message = is_array($response['errors'])
                ? $response['errors'][0]['message'] : $response['errors'];

            // Request error somewhere, throw the exception
            throw new Exception($message);
        }

        return $response;
    }

    /**
     * Ensures shop domain meets the specs.
     *
     * @param string $domain The shopify domain
     *
     * @return string
     */
    public function sanitizeShopDomain($domain)
    {
        if (empty($domain)) {
            return;
        }

        $configEndDomain = Config::get('shopify-app.myshopify_domain');
        $domain = strtolower(preg_replace('/https?:\/\//i', '', trim($domain)));

        if (strpos($domain, $configEndDomain) === false && strpos($domain, '.') === false) {
            // No myshopify.com ($configEndDomain) in shop's name
            $domain .= ".{$configEndDomain}";
        }

        // Return the host after cleaned up
        return parse_url("https://{$domain}", PHP_URL_HOST);
    }

    /**
     * HMAC creation helper.
     *
     * @param array $opts
     *
     * @return string
     */
    public function createHmac(array $opts)
    {
        // Setup defaults
        $data = $opts['data'];
        $raw = $opts['raw'] ?? false;
        $buildQuery = $opts['buildQuery'] ?? false;
        $buildQueryWithJoin = $opts['buildQueryWithJoin'] ?? false;
        $encode = $opts['encode'] ?? false;
        $secret = $opts['secret'] ?? Config::get('shopify-app.api_secret');

        if ($buildQuery) {
            //Query params must be sorted and compiled
            ksort($data);
            $queryCompiled = [];
            foreach ($data as $key => $value) {
                $queryCompiled[] = "{$key}=".(is_array($value) ? implode($value, ',') : $value);
            }
            $data = implode($queryCompiled, ($buildQueryWithJoin ? '&' : ''));
        }

        // Create the hmac all based on the secret
        $hmac = hash_hmac('sha256', $data, $secret, $raw);

        // Return based on options
        return $encode ? base64_encode($hmac) : $hmac;
    }

    /**
     * Allows for sending a message to the logger for debugging.
     *
     * @param string $message The message to send.
     *
     * @return bool
     */
    public function debug(string $message)
    {
        if (!Config::get('shopify-app.debug')) {
            return false;
        }

        Log::debug($message);

        return true;
    }
}
