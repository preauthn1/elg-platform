<?php

namespace target\resource\Clients;

class InternalClient
{
    protected $client;

    /** @var array $arPath api path */
    protected $arPath;

    public function __construct(Client $client)
    {
        $this->client = $client;

        $this->arPath = [
            'ReportSR07'  => '/api/v1/report/sr07',
            'AgentMoneyChangeReport'  => '/api/v1/report/getAgentMoneyChangeLogs',

//            'login'     => '/player/login',
//            'getNews'   => '/api/getNews',
//            'playerTagAll'   => '/playerTag/playerTagAll',
//            'players'   => '/players'
        ];
    }

    /**
     * 傳送資料至其他服務
     *
     * @param string $name
     * @param string $method GET/POST/PUT/DELETE
     * @param array $headers Header
     * @paarm array $body    Body
     *
     * @return object
     */
    public function send($name, $method = 'GET', $headers = [], $body = null)
    {
        if (function_exists('GetInitHeaders')) {
            $initHeaders = GetInitHeaders();
            $headers = array_merge($headers, $initHeaders);
        }

        /** @var object $result */
        $result;

        $path = $this->arPath[$name];
        switch (strtoupper($method)) {
            case 'POST':
                $result = $this->post($path, $body, $headers);
                break;
            case 'PUT':
                $result = $this->put($path, $body, $headers);
                break;
            case 'DELETE':
                $result = $this->delete($path, null, $headers);
                break;
            default:
                $path = $this->client->buildUriWithQuery($path, $body);
                $result = $this->get($path, null, $headers);
                break;
        }
        return $result;
    }

    /**
     * get
     *
     * @param string $path   Path
     * @paarm array $body    Body
     * @param array $headers Header
     *
     * @return object
     */
    public function get($path, $body = null, $headers = [])
    {
        return $this->client->getRequest('GET', $path, $body, $headers);
    }

    /**
     * post
     *
     * @param string $path   Path
     * @paarm array $body    Body
     * @param array $headers Header
     *
     * @return object
     */
    public function post($path, $body, $headers = [])
    {
        return $this->client->getRequest('POST', $path, $body, $headers);
    }

    /**
     * put
     *
     * @param string $path   Path
     * @paarm array $body    Body
     * @param array $headers Header
     *
     * @return object
     */
    public function put($path, $body, $headers = [])
    {
        return $this->client->getRequest('PUT', $path, $body, $headers);
    }

    /**
     * delete
     *
     * @param string $path   Path
     * @paarm array $body    Body
     * @param array $headers Header
     *
     * @return object
     */
    public function delete($path, $body = null, $headers = [])
    {
        return $this->client->getRequest('DELETE', $path, $body, $headers);
    }
}