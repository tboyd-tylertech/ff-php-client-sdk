<?php

    namespace Harness;

    use Exception;
    use Monolog\Logger;
    use Monolog\Handler\StreamHandler;
    use OpenAPI\Client\Api\ClientApi;
    use OpenAPI\Client\Configuration;
    use OpenAPI\Client\Model\AuthenticationRequest;
    use OpenAPI\Client\ApiException;
    use OpenAPI\Client\Model\Target;
    use GuzzleHttp\Client as HttpClient;
    use Psr\Log\LogLevel;
    use GuzzleHttp\HandlerStack;
    use GuzzleLogMiddleware\LogMiddleware;

    use Symfony\Component\Cache\Adapter\FilesystemAdapter;
    use OpenAPI\Client\Api\MetricsApi;
    use OpenAPI\Client\Model\Evaluation;
    use OpenAPI\Client\Model\KeyValue;
    use OpenAPI\Client\Model\Metrics;
    use OpenAPI\Client\Model\MetricsData;
    use Psr\Cache\CacheItemPoolInterface;
    use Psr\Log\LoggerInterface;

    const METRICS_KEY = "metrics";
    const EVALUATIONS = "evaluations";
    const SEPARATOR = "__";

    class Client
    {
        /** @var string */
        const DEFAULT_BASE_URL = 'https://config.ff.harness.io/api/1.0';
        /** @var string */
        const DEFAULT_EVENTS_URL = 'https://events.ff.harness.io/api/1.0';
        /** @var string */
        const VERSION = '0.2.0';
        /** @var string */
        private const POOL_NAME_SPACE = "harness";

        protected string $_sdkKey;
        protected string $_baseUrl;
        protected string $_eventsUrl;
        protected ClientApi $_apiInstance;
        protected MetricsApi $_metricsApi;
        protected string $_environment;
        protected string $_environmentIdentifier;
        protected string $_accountID;
        protected string $_sdkInfo;
        protected int $_cluster = 1;

        protected Configuration $_baseConf;
        protected Configuration $_metricsConf;

        protected LoggerInterface $_logger;
        protected CacheItemPoolInterface $_cache;
        protected bool $_metricsEnabled = true;

        protected Target $_target;

        protected bool $_debug = false;
        protected int $_expireAfter = 60;

        public function __construct(string $sdkKey, Target $target, array $options = [])
        {
            $this->_sdkKey = $sdkKey;
            $this->_target = $target;
            if (!isset($options['base_url'])) {
                $this->_baseUrl = getenv("PROXY_BASE_URL") ?: self::DEFAULT_BASE_URL;
            } else {
                $this->_baseUrl = rtrim($options['base_url'], '/');
            }

            if (!isset($options['events_url'])) {
                $this->_eventsUrl = getenv("PROXY_EVENTS_URL") ?: self::DEFAULT_EVENTS_URL;
            } else {
                $this->_eventsUrl = rtrim($options['events_url'], '/');
            }

            if (!isset($options['logger'])) {
                $this->_logger = new Logger('FFClient');
                $this->_logger->pushHandler(new StreamHandler('php://stderr', LogLevel::DEBUG));
            } else {
                $this->_logger = $options['logger'];
            }

            $this->_logger->info("SDK version " . Client::VERSION);

            if (!isset($options['cache'])) {
                $tempdir           = sys_get_temp_dir();
                $filesystemAdapter = new FilesystemAdapter(self::POOL_NAME_SPACE, 0, $tempdir, null);

                $this->_logger->info("Cache location: " . $tempdir);

                $this->_cache = $filesystemAdapter;
            } else {
                $this->_cache = $options['cache'];
            }

            if (isset($options['metricsEnabled'])) {
                $this->_metricsEnabled = $options['metricsEnabled'];
            }

            if (isset($options['debug'])) {
                $this->_debug = $options['debug'] === "true";
            }

            if (isset($options['expireAfter'])) {
                $this->_expireAfter = $options['expireAfter'];
            }


            $this->_baseConf = new Configuration();
            $this->_baseConf->setHost($this->_baseUrl);
            $client = $this->makeClient();
            $this->_apiInstance = new ClientApi($client, $this->_baseConf);
            $this->_metricsConf = new Configuration();
            $this->_metricsConf->setHost($this->_eventsUrl);
            $this->_metricsApi = new MetricsApi($client, $this->_metricsConf);

            $item = $this->_cache->getItem("auth__{$target->getIdentifier()}");
            $data = $item->get();
            if (isset($data)) {
                $this->_logger->info("CF data loaded from the cache");
                $this->_environment = $data["environment"];
                $this->_environmentIdentifier = $data["environmentIdentifier"];
                $this->_sdkInfo = Client::VERSION;
                $this->_accountID = $data["accountID"];
                $this->_cluster = $data["clusterIdentifier"];
                $this->_baseConf->setAccessToken($data["JWT"]);
                $this->_metricsConf->setAccessToken($data["JWT"]);
            } else {
                $this->_logger->info("Authentication token not found in cache, authenticating...");
                $this->authenticate($item);
            }
        }

        protected function makeClient(): HttpClient {
            $stack = HandlerStack::create();
            if ($this->_debug) {
                $logMiddleware = new LogMiddleware($this->_logger);
                $stack->push($logMiddleware);
            }
            return new HttpClient([
                                      'handler' => $stack,
                                  ]);
        }

        protected function authenticate($item)
        {
            try {
                $request = new AuthenticationRequest(["api_key" => $this->_sdkKey, "target" => $this->_target]);
                $response = $this->_apiInstance->authenticate($request);
                $jwtToken = $response->getAuthToken();
                $parts = explode('.', $jwtToken);
                if (count($parts) !== 3) {
                    $this->_logger->error("JWT token not valid!");
                    return;
                }
                $decoded = base64_decode($parts[1]);
                $payload = json_decode($decoded, true);
                $this->_environment = $payload['environment'];
                if (array_key_exists('clusterIdentifier', $payload)) {
                    $this->_cluster = $payload["clusterIdentifier"];
                }
                if (array_key_exists('environmentIdentifier', $payload)) {
                    $this->_environmentIdentifier = $payload["environmentIdentifier"];
                }
                if (array_key_exists('accountID', $payload)) {
                    $this->_accountID = $payload["accountID"];
                }
                if (!isset($this->_environment)) {
                    $this->_logger->error("environment UUID not found in JWT claims");
                    return;
                }
                $this->_sdkInfo = Client::VERSION;
                $this->_baseConf->setAccessToken($jwtToken);
                $this->_metricsConf->setAccessToken($jwtToken);
                $this->_logger->info("successfully authenticated");
                $item->set(array("JWT" => $jwtToken,
                                 "environment" => $this->_environment,
                                 "clusterIdentifier" => $this->_cluster,
                                 "accountID" => $this->_accountID,
                                 "environmentIdentifier" => $this->_environmentIdentifier));
                $this->_cache->save($item);
            } catch (ApiException $e) {
                $this->_logger->error("Error while authenticating {$e->getMessage()}");
            } catch (Exception $e) {
                $this->_logger->error("Caught $e");
            }
        }

        public function getCacheKeyName(string $feature, string $target): string {
            return EVALUATIONS . SEPARATOR . $feature . SEPARATOR . $target;
        }

        public function fetchEvaluations()
        {
            try {
                $result = $this->_apiInstance->getEvaluations($this->_environment, $this->_target->getIdentifier(), $this->_cluster);
                foreach ($result as $evaluation) {
                    $feature = $evaluation->getFlag();
                    $target = $this->_target->getIdentifier();
                    $item = $this->_cache->getItem($this->getCacheKeyName($feature, $target));
                    $item->set($this->convertValue($evaluation));
                    $item->expiresAfter($this->_expireAfter);
                    $this->_cache->save($item);
                    if ($this->_metricsEnabled) {
                        $this->pushToMetricsCache($evaluation);
                    }
                }
                return $result;
            } catch (ApiException $e) {
                $this->_logger->error("Exception when calling ClientApi->getEvaluations: {$e->getMessage()}");
            } catch (Exception $e) {
                $this->_logger->error("Caught $e");
            }
        }

        public function evaluate(string $identifier, $defaultValue) {
            if (!isset($this->_environment)) {
                return $defaultValue;
            }
            $target = $this->_target->getIdentifier();
            $item = $this->_cache->getItem($this->getCacheKeyName($identifier, $target));
            $value = $item->get();
            if (isset($value)) {
                $this->_logger->debug("Loading '".$identifier."' from cache with value '".json_encode($value)."' of type ".gettype($value));
                return $value;
            }
            try {
                $response = $this->_apiInstance->getEvaluationByIdentifier($this->_environment, $identifier, $this->_target->getIdentifier(), $this->_cluster);
                $value = $this->convertValue($response);
                $this->_logger->debug("Loading '".$identifier."' from service with value '".json_encode($value)."' of type ".gettype($value));
                $item->set($value);
                $item->expiresAfter($this->_expireAfter);
                $this->_cache->save($item);
                $this->_logger->debug("Put {$identifier} in the cache");
                if ($this->_metricsEnabled) {
                    $this->pushToMetricsCache($response);
                }
                return $value;
            } catch (ApiException $e) {
                $this->_logger->error("Caught $e");
                return $defaultValue;
            }
        }

        public function sendMetrics() {
            if (!$this->_metricsEnabled) {
                return;
            }
            try {
                $item = $this->_cache->getItem(METRICS_KEY);
                $entries = $item->get();
                if (!isset($entries)) {
                    $this->_logger->info("No metrics data");
                    return;
                }
                $metricsData = [];
                /* @var $entry MetricItem */
                foreach ($entries as $entry) {
                    $data = new MetricsData();
                    $milliseconds = floor(microtime(true) * 1000);
                    $data->setTimestamp($milliseconds);
                    $data->setCount($entry->count);
                    $data->setMetricsType("FFMETRICS");
                    $data->setAttributes([
                                             new KeyValue(["key" => "featureIdentifier", "value" => $entry->featureIdentifier]),
                                             new KeyValue(["key" => "featureName", "value" => $entry->featureIdentifier]),
                                             new KeyValue(["key" => "variationIdentifier", "value" => $entry->variationIdentifier]),
                                             new KeyValue(["key" => "target", "value" => $entry->targetIdentifier]),
                                             new KeyValue(["key" => "SDK_NAME", "value" => "PHP"]),
                                             new KeyValue(["key" => "SDK_LANGUAGE", "value" => "PHP"]),
                                             new KeyValue(["key" => "SDK_TYPE", "value" => "Server"]),
                                             new KeyValue(["key" => "SDK_VERSION", "value" => Client::VERSION]),
                                         ]);
                    $metricsData[] = $data;
                }
                $metrics = new Metrics();
                $metrics->setMetricsData($metricsData);
                $this->_metricsApi->postMetrics($this->_environment, $this->_cluster, $metrics);
                $this->_cache->deleteItem(METRICS_KEY);
            } catch (ApiException $e) {
                $this->_logger->error("Exception when calling MetricsApi->postMetrics {$e->getMessage()}", [$e]);
            } catch (Exception $e) {
                $this->_logger->error("Caught $e in MetricsApi->postMetrics", [$e]);
            }
        }

        protected function pushToMetricsCache(Evaluation $evaluation) {
            if (!$this->_metricsEnabled) {
                return;
            }
            $item = $this->_cache->getItem(METRICS_KEY);
            $queue = $item->get();
            if (!isset($queue)) {
                $queue = [];
            }
            $queue[] = new MetricItem(
                $evaluation->getFlag(), $evaluation->getValue(), $evaluation->getIdentifier(), 1, time(),
                $this->_target->getIdentifier()
            );
            $item->set($queue);
            $this->_cache->save($item);
        }

        protected function convertValue(Evaluation $evaluation) {
            $value = $evaluation->getValue();
            try {
                switch ($evaluation->getKind()) {
                    case "int":
                    case "number":
                        $value = (float)$value;
                        break;
                    case "boolean":
                        $value = $value === "true";
                        break;
                    case "json":
                        $value = json_decode($value, true);
                        break;
                }
            } catch (Exception $e) {
                $this->_logger->error("Caught $e");
            }

            return $value;
        }
    }
