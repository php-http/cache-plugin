<?php

namespace spec\Http\Client\Common\Plugin;

use Prophecy\Argument;
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
    public function let(CacheItemPoolInterface $pool, StreamFactory $streamFactory)
    {
        $this->beConstructedWith($pool, $streamFactory, [
            'default_ttl' => 60,
            'cache_lifetime' => 1000
        ]);
    }

    public function it_is_initializable(CacheItemPoolInterface $pool)
    {
        $this->shouldHaveType('Http\Client\Common\Plugin\CachePlugin');
    }

    public function it_is_a_plugin()
    {
        $this->shouldImplement('Http\Client\Common\Plugin');
    }

    public function it_caches_responses(
        CacheItemPoolInterface $pool,
        CacheItemInterface $item,
        RequestInterface $request,
        ResponseInterface $response,
        StreamInterface $stream
    ) {
        $httpBody = 'body';
        $stream->__toString()->willReturn($httpBody);
        $stream->isSeekable()->willReturn(true);
        $stream->rewind()->shouldBeCalled();

        $request->getMethod()->willReturn('GET');
        $request->getUri()->willReturn('/');
        $response->getStatusCode()->willReturn(200);
        $response->getBody()->willReturn($stream);
        $response->getHeader('Cache-Control')->willReturn(array())->shouldBeCalled();
        $response->getHeader('Expires')->willReturn(array())->shouldBeCalled();
        $response->getHeader('ETag')->willReturn(array())->shouldBeCalled();

        $pool->getItem('d20f64acc6e70b6079845f2fe357732929550ae1')->shouldBeCalled()->willReturn($item);
        $item->isHit()->willReturn(false);
        $item->expiresAfter(1060)->willReturn($item)->shouldBeCalled();

        $item->set($this->getCacheItemMatcher([
            'response' => $response->getWrappedObject(),
            'body' => $httpBody,
            'expiresAt' => 0,
            'createdAt' => 0,
            'etag' => []
        ]))->willReturn($item)->shouldBeCalled();
        $pool->save(Argument::any())->shouldBeCalled();

        $next = function (RequestInterface $request) use ($response) {
            return new FulfilledPromise($response->getWrappedObject());
        };

        $this->handleRequest($request, $next, function () {
        });
    }

    public function it_doesnt_store_failed_responses(
        CacheItemPoolInterface $pool,
        CacheItemInterface $item,
        RequestInterface $request,
        ResponseInterface $response
    ) {
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

        $this->handleRequest($request, $next, function () {
        });
    }

    public function it_doesnt_store_post_requests(
        CacheItemPoolInterface $pool,
        CacheItemInterface $item,
        RequestInterface $request,
        ResponseInterface $response
    ) {
        $request->getMethod()->willReturn('POST');
        $request->getUri()->willReturn('/');

        $next = function (RequestInterface $request) use ($response) {
            return new FulfilledPromise($response->getWrappedObject());
        };

        $this->handleRequest($request, $next, function () {
        });
    }

    public function it_calculate_age_from_response(
        CacheItemPoolInterface $pool,
        CacheItemInterface $item,
        RequestInterface $request,
        ResponseInterface $response,
        StreamInterface $stream
    ) {
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
        $response->getHeader('ETag')->willReturn(array());

        $pool->getItem('d20f64acc6e70b6079845f2fe357732929550ae1')->shouldBeCalled()->willReturn($item);
        $item->isHit()->willReturn(false);

        $item->set($this->getCacheItemMatcher([
                'response' => $response->getWrappedObject(),
                'body' => $httpBody,
                'expiresAt' => 0,
                'createdAt' => 0,
                'etag' => []
            ]))->willReturn($item)->shouldBeCalled();
        // 40-15 should be 25 + the default 1000
        $item->expiresAfter(1025)->willReturn($item)->shouldBeCalled();
        $pool->save($item)->shouldBeCalled();

        $next = function (RequestInterface $request) use ($response) {
            return new FulfilledPromise($response->getWrappedObject());
        };

        $this->handleRequest($request, $next, function () {
        });
    }

    public function it_saves_etag(
        CacheItemPoolInterface $pool,
        CacheItemInterface $item,
        RequestInterface $request,
        ResponseInterface $response,
        StreamInterface $stream
    ) {
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
        $response->getHeader('ETag')->willReturn(array('foo_etag'));

        $pool->getItem('d20f64acc6e70b6079845f2fe357732929550ae1')->shouldBeCalled()->willReturn($item);
        $item->isHit()->willReturn(false);
        $item->expiresAfter(1060)->willReturn($item);

        $item->set($this->getCacheItemMatcher([
            'response' => $response->getWrappedObject(),
            'body' => $httpBody,
            'expiresAt' => 0,
            'createdAt' => 0,
            'etag' => ['foo_etag']
        ]))->willReturn($item)->shouldBeCalled();
        $pool->save(Argument::any())->shouldBeCalled();

        $next = function (RequestInterface $request) use ($response) {
            return new FulfilledPromise($response->getWrappedObject());
        };

        $this->handleRequest($request, $next, function () {
        });
    }

    public function it_adds_etag_and_modfied_since_to_request(
        CacheItemPoolInterface $pool,
        CacheItemInterface $item,
        RequestInterface $request,
        ResponseInterface $response,
        StreamInterface $stream
    ) {
        $httpBody = 'body';

        $request->getMethod()->willReturn('GET');
        $request->getUri()->willReturn('/');

        $request
            ->withHeader('If-Modified-Since', 'Thursday, 01-Jan-70 01:18:31 GMT')
            ->shouldBeCalled()
            ->willReturn($request);
        $request->withHeader('If-None-Match', 'foo_etag')->shouldBeCalled()->willReturn($request);

        $response->getStatusCode()->willReturn(304);

        $pool->getItem('d20f64acc6e70b6079845f2fe357732929550ae1')->shouldBeCalled()->willReturn($item);
        $item->isHit()->willReturn(true, false);
        $item->get()->willReturn([
            'response' => $response,
            'body' => $httpBody,
            'expiresAt' => 0,
            'createdAt' => 4711,
            'etag' => ['foo_etag']
        ])->shouldBeCalled();

        $next = function (RequestInterface $request) use ($response) {
            return new FulfilledPromise($response->getWrappedObject());
        };

        $this->handleRequest($request, $next, function () {
        });
    }

    public function it_servces_a_cached_response(
        CacheItemPoolInterface $pool,
        CacheItemInterface $item,
        RequestInterface $request,
        ResponseInterface $response,
        StreamInterface $stream,
        StreamFactory $streamFactory
    ) {
        $httpBody = 'body';

        $request->getMethod()->willReturn('GET');
        $request->getUri()->willReturn('/');

        $pool->getItem('d20f64acc6e70b6079845f2fe357732929550ae1')->shouldBeCalled()->willReturn($item);
        $item->isHit()->willReturn(true);
        $item->get()->willReturn([
            'response' => $response,
            'body' => $httpBody,
            'expiresAt' => time()+1000000, //It is in the future
            'createdAt' => 4711,
            'etag' => []
        ])->shouldBeCalled();

        // Make sure we add back the body
        $response->withBody($stream)->willReturn($response)->shouldBeCalled();
        $streamFactory->createStream($httpBody)->shouldBeCalled()->willReturn($stream);

        $next = function (RequestInterface $request) use ($response) {
            return new FulfilledPromise($response->getWrappedObject());
        };

        $this->handleRequest($request, $next, function () {
        });
    }

    public function it_serves_and_resaved_expired_response(
        CacheItemPoolInterface $pool,
        CacheItemInterface $item,
        RequestInterface $request,
        ResponseInterface $response,
        StreamInterface $stream,
        StreamFactory $streamFactory
    ) {
        $httpBody = 'body';

        $request->getMethod()->willReturn('GET');
        $request->getUri()->willReturn('/');

        $request->withHeader(Argument::any(), Argument::any())->willReturn($request);
        $request->withHeader(Argument::any(), Argument::any())->willReturn($request);

        $response->getStatusCode()->willReturn(304);
        $response->getHeader('Cache-Control')->willReturn(array());
        $response->getHeader('Expires')->willReturn(array())->shouldBeCalled();

        // Make sure we add back the body
        $response->withBody($stream)->willReturn($response)->shouldBeCalled();

        $pool->getItem('d20f64acc6e70b6079845f2fe357732929550ae1')->shouldBeCalled()->willReturn($item);
        $item->isHit()->willReturn(true, true);
        $item->expiresAfter(1060)->willReturn($item)->shouldBeCalled();
        $item->get()->willReturn([
            'response' => $response,
            'body' => $httpBody,
            'expiresAt' => 0,
            'createdAt' => 4711,
            'etag' => ['foo_etag']
        ])->shouldBeCalled();

        $item->set($this->getCacheItemMatcher([
            'response' => $response->getWrappedObject(),
            'body' => $httpBody,
            'expiresAt' => 0,
            'createdAt' => 0,
            'etag' => ['foo_etag']
        ]))->willReturn($item)->shouldBeCalled();
        $pool->save(Argument::any())->shouldBeCalled();

        $streamFactory->createStream($httpBody)->shouldBeCalled()->willReturn($stream);

        $next = function (RequestInterface $request) use ($response) {
            return new FulfilledPromise($response->getWrappedObject());
        };

        $this->handleRequest($request, $next, function () {
        });
    }


    /**
     * Private function to match cache item data.
     *
     * @param array $expectedData
     *
     * @return \Closure
     */
    private function getCacheItemMatcher(array $expectedData)
    {
        return Argument::that(function (array $actualData) use ($expectedData) {
            foreach ($expectedData as $key => $value) {
                if (!isset($actualData[$key])) {
                    return false;
                }

                if ($key === 'expiresAt' || $key === 'createdAt') {
                    // We do not need to validate the value of these fields.
                    continue;
                }

                if ($actualData[$key] !== $value) {
                    return false;
                }
            }
            return true;
        });
    }
}
