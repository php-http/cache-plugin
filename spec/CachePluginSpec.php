<?php

namespace spec\Http\Client\Common\Plugin;

use Http\Message\StreamFactory;
use Http\Promise\FulfilledPromise;
use PhpSpec\ObjectBehavior;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

class CachePluginSpec extends ObjectBehavior
{
    function let(CacheItemPoolInterface $pool, StreamFactory $streamFactory)
    {
        $this->beConstructedWith($pool, $streamFactory, ['default_ttl'=>60]);
    }

    function it_is_initializable(CacheItemPoolInterface $pool)
    {
        $this->shouldHaveType('Http\Client\Common\Plugin\CachePlugin');
    }

    function it_is_a_plugin()
    {
        $this->shouldImplement('Http\Client\Common\Plugin');
    }

    function it_caches_responses(CacheItemPoolInterface $pool, CacheItemInterface $item, RequestInterface $request, ResponseInterface $response, StreamInterface $stream)
    {
        $httpBody = 'body';
        $stream->__toString()->willReturn($httpBody);
        $stream->isSeekable()->willReturn(true);
        $stream->rewind()->shouldBeCalled();

        $request->getMethod()->willReturn('GET');
        $request->getUri()->willReturn('/');
        $response->getStatusCode()->willReturn(200);
        $response->getBody()->willReturn($stream);
        $response->getHeader('Cache-Control')->willReturn(array());
        $response->getHeader('Expires')->willReturn(array());

        $pool->getItem('d20f64acc6e70b6079845f2fe357732929550ae1')->shouldBeCalled()->willReturn($item);
        $item->isHit()->willReturn(false);
        $item->set(['response' => $response, 'body' => $httpBody])->willReturn($item)->shouldBeCalled();
        $item->expiresAfter(60)->willReturn($item)->shouldBeCalled();
        $pool->save($item)->shouldBeCalled();

        $next = function (RequestInterface $request) use ($response) {
            return new FulfilledPromise($response->getWrappedObject());
        };

        $this->handleRequest($request, $next, function () {});
    }

    function it_doesnt_store_failed_responses(CacheItemPoolInterface $pool, CacheItemInterface $item, RequestInterface $request, ResponseInterface $response)
    {
        $request->getMethod()->willReturn('GET');
        $request->getUri()->willReturn('/');
        $response->getStatusCode()->willReturn(400);
        $response->getHeader('Cache-Control')->willReturn(array());
        $response->getHeader('Expires')->willReturn(array());

        $pool->getItem('d20f64acc6e70b6079845f2fe357732929550ae1')->shouldBeCalled()->willReturn($item);
        $item->isHit()->willReturn(false);

        $next = function (RequestInterface $request) use ($response) {
            return new FulfilledPromise($response->getWrappedObject());
        };

        $this->handleRequest($request, $next, function () {});
    }

    function it_doesnt_store_post_requests(CacheItemPoolInterface $pool, CacheItemInterface $item, RequestInterface $request, ResponseInterface $response)
    {
        $request->getMethod()->willReturn('POST');
        $request->getUri()->willReturn('/');

        $next = function (RequestInterface $request) use ($response) {
            return new FulfilledPromise($response->getWrappedObject());
        };

        $this->handleRequest($request, $next, function () {});
    }


    function it_calculate_age_from_response(CacheItemPoolInterface $pool, CacheItemInterface $item, RequestInterface $request, ResponseInterface $response, StreamInterface $stream)
    {
        $httpBody = 'body';
        $stream->__toString()->willReturn($httpBody);
        $stream->isSeekable()->willReturn(true);
        $stream->rewind()->shouldBeCalled();

        $request->getMethod()->willReturn('GET');
        $request->getUri()->willReturn('/');
        $response->getStatusCode()->willReturn(200);
        $response->getBody()->willReturn($stream);
        $response->getHeader('Cache-Control')->willReturn(array('max-age=40'));
        $response->getHeader('Age')->willReturn(array('15'));
        $response->getHeader('Expires')->willReturn(array());

        $pool->getItem('d20f64acc6e70b6079845f2fe357732929550ae1')->shouldBeCalled()->willReturn($item);
        $item->isHit()->willReturn(false);

        // 40-15 should be 25
        $item->set(['response' => $response, 'body' => $httpBody])->willReturn($item)->shouldBeCalled();
        $item->expiresAfter(25)->willReturn($item)->shouldBeCalled();
        $pool->save($item)->shouldBeCalled();

        $next = function (RequestInterface $request) use ($response) {
            return new FulfilledPromise($response->getWrappedObject());
        };

        $this->handleRequest($request, $next, function () {});
    }
}
