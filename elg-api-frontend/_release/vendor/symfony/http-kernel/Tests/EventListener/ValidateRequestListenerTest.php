<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <user@example.invalid>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpKernel\Tests\EventListener;

use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\EventListener\ValidateRequestListener;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

class ValidateRequestListenerTest extends TestCase
{
    protected function tearDown()
    {
        Request::setTrustedProxies([], -1);
    }

    /**
     * @expectedException \Symfony\Component\HttpFoundation\Exception\ConflictingHeadersException
     */
    public function testListenerThrowsWhenMasterRequestHasInconsistentClientIps()
    {
        $dispatcher = new EventDispatcher();
        $kernel = $this->getMockBuilder('Symfony\Component\HttpKernel\HttpKernelInterface')->getMock();

        $request = new Request();
        $request->setTrustedProxies(['0.0.0.0'], Request::HEADER_X_FORWARDED_FOR | Request::HEADER_FORWARDED);
        $request->server->set('REMOTE_ADDR', '0.0.0.0');
        $request->headers->set('FORWARDED', 'for=0.0.0.0');
        $request->headers->set('X_FORWARDED_FOR', '0.0.0.0');

        $dispatcher->addListener(KernelEvents::REQUEST, [new ValidateRequestListener(), 'onKernelRequest']);
        $event = new GetResponseEvent($kernel, $request, HttpKernelInterface::MASTER_REQUEST);

        $dispatcher->dispatch(KernelEvents::REQUEST, $event);
    }
}
