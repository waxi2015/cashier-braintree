<?php

use Illuminate\Http\Request;
use Laravel\Cashier\Http\Controllers\WebhookController;

class WebhookControllerTest extends PHPUnit_Framework_TestCase
{
    public function testProperMethodsAreCalledBasedOnBraintreeEvent()
    {
        $_SERVER['__received'] = false;
        $request = Request::create('/', 'POST', [], [], [], [], json_encode(['kind' => 'charge_succeeded', 'id' => 'event-id']));
        $controller = new WebhookControllerTestStub;
        $controller->handleWebhook($request);

        $this->assertTrue($_SERVER['__received']);
    }

    public function testNormalResponseIsReturnedIfMethodIsMissing()
    {
        $request = Request::create('/', 'POST', [], [], [], [], json_encode(['kind' => 'foo_bar', 'id' => 'event-id']));
        $controller = new WebhookControllerTestStub;
        $response = $controller->handleWebhook($request);
        $this->assertEquals(200, $response->getStatusCode());
    }
}

class WebhookControllerTestStub extends WebhookController
{
    public function handleChargeSucceeded()
    {
        $_SERVER['__received'] = true;
    }

    /**
     * Parse the given Braintree webhook notification request.
     *
     * @param  Request  $request
     * @return WebhookNotification
     */
    protected function parseBraintreeNotification($request)
    {
        return json_decode($request->getContent());
    }
}
