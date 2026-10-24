<?php

namespace target\support\Test\Unit;

use target\support\Helpers\HttpClient;
use Tests\TestCase;

class HttpClientTest extends TestCase
{

    public function testRequest()
    {
        $client = new HttpClient();
        $response = $client->request("test", "GET", "https://example.invalid/");
        $this->assertNotEquals("", $response);
    }
}
