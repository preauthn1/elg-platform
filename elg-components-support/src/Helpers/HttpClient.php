<?php


namespace target\support\Helpers;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use GuzzleHttp\Psr7;
use GuzzleHttp\Exception\RequestException;

/**
 * Class HttpClient
 *
 * @package target\support\Helpers
 * 使用方式
 * 1. json post
 * $client = new HttpClient();
 * $body = $client->send(
 *   "test",
 *   "POST",
 *   $url,
 *   [
 *     "json" => [
 *       'params' => $params,
 *       'key' => $key
 *     ],
 *     "headers" => [
 *       "Accept-Language" => "zh-tw",
 *       "Authorization" => "Bearer " . $this->token,
 *     ]
 *   ]
 * );
 * $response = json_decode($body, true);
 */

class HttpClient
{
    /**
     * Http Client
     *
     * @var Client
     */
    protected $client;

    /**
     * HttpClient constructor.
     *
     * @param array $config
     */

    public function __construct($config = [])
    {
        $this->client = new Client($config);
    }

    /**
     * @param $request_name     // message for log
     * @param $method           // method
     * @param $uri              // uri
     * @param array $options    // Guzzle Request options https://example.invalid/en/6.5/request-options.html
     * @return string
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function request($request_name, $method, $uri, $options = [])
    {
        try {
            $response = $this->client->request($method, $uri, $options);
            Log::info(
                $request_name,
                [
                    'request' => [
                        'uri' => $uri,
                        'option' => $options,
                    ],
                    'response' => [
                        'statusCode' => $response->getStatusCode(),
                        'content' => $this->getContent((string) $response->getBody()),
                    ]
                ]
            );
            return (string) $response->getBody();
        } catch (RequestException $e) {
            $context = [
                'request' => [
                    'uri' => $uri,
                    'option' => $options,
                ]
            ];
            if ($e->hasResponse()) {
                $context['response'] = Psr7\str($e->getResponse());
            }
            Log::error($request_name, $context);
            return "";
        }
    }

    /**
     * 取得 response content
     * @param $result
     * @return mixed|string
     */
    private function getContent($result)
    {
        $result_size = strlen($result);
        if ($result_size > 1024 * 1024 * 10) { //如果字超過 10M，拋出例外處理
            return "response content has large data size " . $result_size;
        } elseif (strlen($result) > 1024 * 10) { //如果字超過10240個，把它壓縮
            return base64_encode(gzcompress($result));
        } else {
            if (\target\support\Helpers\Utils\Utils::isJSON($result)) {
                return json_decode($result, true);
            } else {
                return $result;
            }
        }
    }
}
