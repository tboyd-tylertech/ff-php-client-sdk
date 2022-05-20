<?php

namespace Harness;

use Exception;
use OpenAPI\Client\Api\ClientApi;
use OpenAPI\Client\Configuration;
use OpenAPI\Client\Model\AuthenticationRequest;
use GuzzleHttp\Client;

class CFClient
{
    /** @var string */
    const DEFAULT_BASE_URL = 'http://ff-proxy:7000';
    /** @var string */
    const DEFAULT_EVENTS_URL = 'http://ff-proxy:7000';
    /** @var string */
    const VERSION = '1.0.0';

    /** @var string */
    protected $_sdkKey;
    /** @var string */
    protected $_baseUrl;
    /** @var string */
    protected $_eventsUrl;
    /** @var ClientApi */
    protected $_apiInstance;

    /** @var string */
    protected $_environment;

    /** @var string */
    protected $_cluster;

    protected $_configuration;

    public function __construct(string $sdkKey, array $options = [])
    {
        $this->_sdkKey = $sdkKey;
        if (!isset($options['base_url'])) {
            $this->_baseUrl = $_ENV["PROXY_BASE_URL"] ?: self::DEFAULT_BASE_URL;
        } else {
            $this->_baseUrl = rtrim($options['base_url'], '/');
        }
        if (!isset($options['events_url'])) {
            $this->_eventsUrl = $_ENV["PROXY_EVENTS_URL"] ?: self::DEFAULT_EVENTS_URL;
        } else {
            $this->_eventsUrl = rtrim($options['events_url'], '/');
        }

        $this->_configuration = new Configuration();
        $this->_configuration->setHost($this->_baseUrl);
        $this->_apiInstance = new ClientApi(new Client(), $this->_configuration);

        try {
            $this->authenticate();
            $this->fetchEvaluations();
        } catch (Exception $e) {
            error_log("Error while authenticating {$e->getMessage()}");
        }
    }

    public function authenticate()
    {
        $request = new AuthenticationRequest(array("api_key" => $this->_sdkKey));
        $response = $this->_apiInstance->authenticate($request);
        $jwtToken = $response->getAuthToken();
        $parts = explode('.', $jwtToken);
        $decoded = base64_decode($parts[1]);
        $payload = json_decode($decoded, true);
        $this->_environment = $payload["environment"];
        $this->_cluster = $payload["clusterIdentifier"];
        $this->_configuration->setAccessToken($jwtToken);
    }

    public function fetchEvaluations()
    {
        $response = $this->_apiInstance->getFeatureConfig($this->_environment);
        foreach ($response as $key => $value) {
            echo "Key: $key; Value: $value\n";
        }
    }
}
