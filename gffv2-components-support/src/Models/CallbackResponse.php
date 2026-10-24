<?php


namespace target\support\Models;

class CallbackResponse
{
    private $status;
    private $response;
    private $order;

    public function __construct(int $status, string $response, ?Order $order)
    {
        $this->status = $status;
        $this->response = $response;
        $this->order = $order;
    }

    /**
     * @return int $status
     */
    public function getStatus()
    {
        return $this->status;
    }
    /**
     * @return string $response
     */
    public function getResponse()
    {
        return $this->response;
    }
    /**
     * @return Order $status
     */
    public function getOrder()
    {
        return $this->order;
    }
}
