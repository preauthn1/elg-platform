<?php

namespace target\resource\Clients;

use function GuzzleHttp\Psr7\build_query;
use function GuzzleHttp\Psr7\uri_for;
use BadMethodCallException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Client as GuzzleHttpClient;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\Request;
use http\Client\Response;
use Psr\Http\Message\ResponseInterface;
use target\resource\Exceptions\BadResponseException;

class Client
{
    /**
     * Http Client
     *
     * @var GuzzleHttpClient
     */
    protected $httpClient;

    /**
     * Endpoint
     *
     * @var string
     */
    protected $endpoint;

    /**
     * Client
     *
     * @var object
     */
    protected $client;

    /**
     * Constructor
     *
     * @param string $service Service
     */
    public function __construct($service)
    {
        $this->setHttpClient();
        $this->setEndpoint($service);
        $this->setClient($service);
    }

    /**
     * Method Call
     *
     * @param string $method    Method
     * @param array  $arguments Arguments
     *
     * @return object
     */
    public function __call($method, $arguments)
    {
        if (! method_exists($this->client, $method)) {
            throw new BadMethodCallException(sprintf('Method "%s" not found', $method));
        }

        return $this->getClient()->$method(...$arguments);
    }

    /**
     * HttpClient Setter
     *
     * @param ClientInterface $httpClient HttpClient
     *
     * @return void
     */
    public function setHttpClient(ClientInterface $httpClient = null)
    {
        $this->httpClient = $httpClient ?: new GuzzleHttpClient();

        return;
    }

    /**
     * HttpClient Getter
     *
     * @return ClientInterface
     */
    public function getHttpClient()
    {
        return $this->httpClient;
    }

    /**
     * Endpoint Getter
     *
     * @return string
     */
    public function getEndpoint()
    {
        return $this->endpoint;
    }

    /**
     * Endpoint Setter
     *
     * @param string
     *
     * @return void
     */
    public function setEndpoint($service)
    {
        $config = include __DIR__ . '/../../config/endpoints.php';
        $this->endpoint = new Uri($config[$service]);
        //$this->endpoint = new Uri(config(sprintf('endpoints.%s', $service)));

        return;
    }

    /**
     * Client Getter
     *
     * @return object
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * Client Setter
     *
     * @param string $service Service
     *
     * @return void
     */
    public function setClient($service)
    {
        switch ($service) {
            case 'internal':
                $this->client = new InternalClient($this);
                break;
        }

        return;
    }

    /**
     * Send Request
     *
     * @param Request $request Request
     *
     * @return object
     */
    private function sendRequest(Request $request)
    {
        try {
            $response = $this->httpClient->send($request);
        } catch (\Exception $exception) {
            /**
             * @todo exception type limit
             */

            $response = $exception->getResponse();
            if (!$response) {
                return (object)[
                    'statusCode' => 500,
                    'data' => $exception->getMessage()
                ];
            }
//            dd($exception);
//            if ($exception instanceof ClientException) {
//                $response = $exception->getResponse();
//            } else {
//                throw $exception;
//            }
        }
        $this->checkErrorResponse($response);
        $result = json_decode($response->getBody()->getContents());
        return (object)[
            'statusCode' => $response->getStatusCode(),
            'data' => $result
        ];
    }

    /**
     * request
     *
     * @param string $method method
     * @param string $path   Path
     * @param array $body    Body
     * @param array $headers Headers
     *
     * @return object
     */
    public function getRequest($method, $path, $body, $headers = [])
    {
        $request = $this->newRequest(
            $headers,
            $method,
            $path,
            $body
        );

        return $this->sendRequest($request);
    }

    /**
     * New Request
     * @param array        $headers Headers
     * @param string       $method  Method
     * @param UriInterface $uri     URI
     * @param array        $body    Body
     *
     * @return Request
     */
    public function newRequest($headers, $method, $uri, $body = null)
    {
        $type = [
            'Content-Type' => 'application/json'
        ];

        $headers = array_merge($headers, $type);

        if (!is_null($body)) {
            $body = json_encode($body);
        }

        return new Request(
            $method,
            $uri,
            $headers,
            $body
        );
    }

    /**
     * Return the uri with authentication parameters and query string
     *
     * @param string|UriInterface $uri
     * @param array $params Query string parameters
     *
     * @return UriInterface
     */
    public function buildUriWithQuery($uri, array $params = [])
    {
        $uri = uri_for($uri);
        if (!Uri::isAbsolute($uri)) {
            $uri = $uri->withScheme($this->endpoint->getScheme())
                ->withHost($this->endpoint->getHost())
                ->withPort($this->endpoint->getPort());
        }

        $defualt = [];

        return $uri->withQuery(build_query(array_merge($defualt, $params)));
    }

    /**
     * Check the API response for errors
     *
     * @param ResponseInterface $response Response
     *
     * @return void
     */
    protected function checkErrorResponse(ResponseInterface $response)
    {
        $statusCode = $response->getStatusCode();

        if ($statusCode < 200 || $statusCode > 499) {
            throw new BadResponseException(sprintf('未知的狀態碼：%d', $statusCode));
        }

        $responseBody = $response->getBody();
//不限制空回应
//        if (!$responseBody->getSize()) {
//            throw new BadResponseException('非預期的空回應');
//        }

        return;
    }
}