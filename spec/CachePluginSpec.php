<?php

namespace spec\Http\Client\Common\Plugin;

use Http\Client\Common\Plugin\Cache\Generator\SimpleGenerator;
use PhpSpec\Wrapper\Collaborator;
use Prophecy\Argument;
use Http\Message\StreamFactory;
use Http\Promise\FulfilledPromise;
use PhpSpec\ObjectBehavior;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Http\Client\Common\Plugin\CachePlugin;
use Http\Client\Common\Plugin;

class CachePluginSpec extends ObjectBehavior
{
    /**
     * @var StreamFactory&Collaborator
     */
    private $streamFactory;

    function let(CacheItemPoolInterface $pool, StreamFactory $streamFactory)
    {
        $this->streamFactory = $streamFactory;
        $this->beConstructedWith($pool, $streamFactory, [
            'default_ttl' => 60,
            'cache_lifetime' => 1000
        ]);
    }

    function it_is_initializable(CacheItemPoolInterface $pool)
    {
        $this->shouldHaveType(CachePlugin::class);
    }

    function it_is_a_plugin()
    {
        $this->shouldImplement(Plugin::class);
    }

    function it_caches_responses(CacheItemPoolInterface $pool, CacheItemInterface $item, RequestInterface $request, UriInterface $uri, ResponseInterface $response, StreamInterface $stream)
    {
        $httpBody = 'body';
        $stream->__toString()->willReturn($httpBody);
        $stream->isSeekable()->willReturn(true);
        $stream->rewind()->shouldBeCalled();
        $stream->detach()->shouldBeCalled();

        $request->getMethod()->willReturn('GET');
        $request->getUri()->willReturn($uri);
        $uri->__toString()->willReturn('https://example.com/');
        $request->getBody()->shouldBeCalled()->willReturn($stream);

        $response->getStatusCode()->willReturn(200);
        $response->getBody()->willReturn($stream);
        $response->getHeader('Cache-Control')->willReturn([])->shouldBeCalled();
        $response->getHeader('Expires')->willReturn([])->shouldBeCalled();
        $response->getHeader('ETag')->willReturn([])->shouldBeCalled();
        $response->withBody($stream)->shouldBeCalled()->willReturn($response);

        $this->streamFactory->createStream($httpBody)->shouldBeCalled()->willReturn($stream);

        $pool->getItem(Argument::any())->shouldBeCalled()->willReturn($item);
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

        $this->handleRequest($request, $next, function () {});
    }

    function it_doesnt_store_failed_responses(CacheItemPoolInterface $pool, CacheItemInterface $item, RequestInterface $request, UriInterface $uri, StreamInterface $requestBody, ResponseInterface $response)
    {
        $request->getMethod()->willReturn('GET');
        $request->getUri()->willReturn($uri);
        $uri->__toString()->willReturn('https://example.com/');
        $request->getBody()->shouldBeCalled()->willReturn($requestBody);
        $requestBody->__toString()->shouldBeCalled()->willReturn('body');

        $response->getStatusCode()->willReturn(400);
        $response->getHeader('Cache-Control')->willReturn([]);
        $response->getHeader('Expires')->willReturn([]);

        $pool->getItem(Argument::any())->shouldBeCalled()->willReturn($item);
        $item->isHit()->willReturn(false);

        $next = function (RequestInterface $request) use ($response) {
            return new FulfilledPromise($response->getWrappedObject());
        };

        $this->handleRequest($request, $next, function () {});
    }

    function it_doesnt_store_post_requests_by_default(CacheItemPoolInterface $pool, CacheItemInterface $item, RequestInterface $request, UriInterface $uri, ResponseInterface $response)
    {
        $request->getMethod()->willReturn('POST');
        $request->getUri()->willReturn($uri);
        $uri->__toString()->willReturn('https://example.com/');

        $next = function (RequestInterface $request) use ($response) {
            return new FulfilledPromise($response->getWrappedObject());
        };

        $this->handleRequest($request, $next, function () {});
    }

    function it_stores_post_requests_when_allowed(
        CacheItemPoolInterface $pool,
        CacheItemInterface $item,
        RequestInterface $request,
        UriInterface $uri,
        ResponseInterface $response,
        StreamFactory $streamFactory,
        StreamInterface $stream
    ) {
        $this->beConstructedWith($pool, $streamFactory, [
            'default_ttl' => 60,
            'cache_lifetime' => 1000,
            'methods' => ['GET', 'HEAD', 'POST']
        ]);

        $httpBody = 'hello=world';
        $stream->__toString()->willReturn($httpBody);
        $stream->isSeekable()->willReturn(true);
        $stream->rewind()->shouldBeCalled();
        $stream->detach()->shouldBeCalled();

        $request->getMethod()->willReturn('POST');
        $request->getUri()->willReturn($uri);
        $uri->__toString()->willReturn('https://example.com/');
        $request->getBody()->willReturn($stream);

        $response->getStatusCode()->willReturn(200);
        $response->getBody()->willReturn($stream);
        $response->getHeader('Cache-Control')->willReturn([])->shouldBeCalled();
        $response->getHeader('Expires')->willReturn([])->shouldBeCalled();
        $response->getHeader('ETag')->willReturn([])->shouldBeCalled();
        $response->withBody($stream)->shouldBeCalled()->willReturn($response);

        $this->streamFactory->createStream($httpBody)->shouldBeCalled()->willReturn($stream);

        $pool->getItem(Argument::any())->shouldBeCalled()->willReturn($item);
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

        $this->handleRequest($request, $next, function () {});
    }

    function it_does_not_allow_invalid_request_methods(
        CacheItemPoolInterface $pool,
        CacheItemInterface $item,
        RequestInterface $request,
        ResponseInterface $response,
        StreamFactory $streamFactory,
        StreamInterface $stream
    ) {
        $this
            ->shouldThrow("Symfony\Component\OptionsResolver\Exception\InvalidOptionsException")
            ->during('__construct', [$pool, $streamFactory, ['methods' => ['GET', 'HEAD', 'POST ']]]);
        $this
            ->shouldThrow("Symfony\Component\OptionsResolver\Exception\InvalidOptionsException")
            ->during('__construct', [$pool, $streamFactory, ['methods' => ['GET', 'HEAD"', 'POST']]]);
        $this
            ->shouldThrow("Symfony\Component\OptionsResolver\Exception\InvalidOptionsException")
            ->during('__construct', [$pool, $streamFactory, ['methods' => ['GET', 'head', 'POST']]]);
    }

    function it_calculate_age_from_response(CacheItemPoolInterface $pool, CacheItemInterface $item, RequestInterface $request, UriInterface $uri, ResponseInterface $response, StreamInterface $stream)
    {
        $httpBody = 'body';
        $stream->__toString()->willReturn($httpBody);
        $stream->isSeekable()->willReturn(true);
        $stream->rewind()->shouldBeCalled();
        $stream->detach()->shouldBeCalled();

        $request->getMethod()->willReturn('GET');
        $request->getUri()->willReturn($uri);
        $uri->__toString()->willReturn('https://example.com/');
        $request->getBody()->shouldBeCalled()->willReturn($stream);

        $response->getStatusCode()->willReturn(200);
        $response->getBody()->willReturn($stream);
        $response->getHeader('Cache-Control')->willReturn(['max-age=40']);
        $response->getHeader('Age')->willReturn(['15']);
        $response->getHeader('Expires')->willReturn([]);
        $response->getHeader('ETag')->willReturn([]);
        $response->withBody($stream)->shouldBeCalled()->willReturn($response);

        $this->streamFactory->createStream($httpBody)->shouldBeCalled()->willReturn($stream);

        $pool->getItem(Argument::any())->shouldBeCalled()->willReturn($item);
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

        $this->handleRequest($request, $next, function () {});
    }

    function it_saves_etag(CacheItemPoolInterface $pool, CacheItemInterface $item, RequestInterface $request, UriInterface $uri, ResponseInterface $response, StreamInterface $stream)
    {
        $httpBody = 'body';
        $stream->__toString()->willReturn($httpBody);
        $stream->isSeekable()->willReturn(true);
        $stream->rewind()->shouldBeCalled();
        $stream->detach()->shouldBeCalled();
        $request->getBody()->shouldBeCalled()->willReturn($stream);

        $request->getMethod()->willReturn('GET');
        $request->getUri()->willReturn($uri);
        $uri->__toString()->willReturn('https://example.com/');
        $response->getStatusCode()->willReturn(200);
        $response->getBody()->willReturn($stream);
        $response->getHeader('Cache-Control')->willReturn([]);
        $response->getHeader('Expires')->willReturn([]);
        $response->getHeader('ETag')->willReturn(['foo_etag']);
        $response->withBody($stream)->shouldBeCalled()->willReturn($response);

        $this->streamFactory->createStream($httpBody)->shouldBeCalled()->willReturn($stream);

        $pool->getItem(Argument::any())->shouldBeCalled()->willReturn($item);
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

        $this->handleRequest($request, $next, function () {});
    }

    function it_adds_etag_and_modfied_since_to_request(CacheItemPoolInterface $pool, CacheItemInterface $item, RequestInterface $request, UriInterface $uri, ResponseInterface $response, StreamInterface $stream)
    {
        $httpBody = 'body';

        $request->getMethod()->willReturn('GET');
        $request->getUri()->willReturn($uri);
        $uri->__toString()->willReturn('https://example.com/');
        $request->getBody()->shouldBeCalled()->willReturn($stream);
        $stream->__toString()->shouldBeCalled()->willReturn('');

        $request->withHeader('If-Modified-Since', 'Thursday, 01-Jan-70 01:18:31 GMT')->shouldBeCalled()->willReturn($request);
        $request->withHeader('If-None-Match', 'foo_etag')->shouldBeCalled()->willReturn($request);

        $response->getStatusCode()->willReturn(304);

        $pool->getItem(Argument::any())->shouldBeCalled()->willReturn($item);
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

        $this->handleRequest($request, $next, function () {});
    }

    function it_serves_a_cached_response(CacheItemPoolInterface $pool, CacheItemInterface $item, RequestInterface $request, UriInterface $uri, StreamInterface $requestBody, ResponseInterface $response, StreamInterface $stream, StreamFactory $streamFactory)
    {
        $httpBody = 'body';

        $request->getMethod()->willReturn('GET');
        $request->getUri()->willReturn($uri);
        $uri->__toString()->willReturn('https://example.com/');
        $request->getBody()->shouldBeCalled()->willReturn($requestBody);
        $requestBody->__toString()->shouldBeCalled()->willReturn('');

        $pool->getItem(Argument::any())->shouldBeCalled()->willReturn($item);
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

        $this->handleRequest($request, $next, function () {});
    }

    function it_serves_and_resaved_expired_response(CacheItemPoolInterface $pool, CacheItemInterface $item, RequestInterface $request, UriInterface $uri, StreamInterface $requestStream, ResponseInterface $response, StreamInterface $stream, StreamFactory $streamFactory)
    {
        $httpBody = 'body';

        $request->getMethod()->willReturn('GET');
        $request->getUri()->willReturn($uri);
        $uri->__toString()->willReturn('https://example.com/');
        $request->getBody()->shouldBeCalled()->willReturn($requestStream);
        $requestStream->__toString()->willReturn('');

        $request->withHeader(Argument::any(), Argument::any())->willReturn($request);
        $request->withHeader(Argument::any(), Argument::any())->willReturn($request);

        $response->getStatusCode()->willReturn(304);
        $response->getHeader('Cache-Control')->willReturn([]);
        $response->getHeader('Expires')->willReturn([])->shouldBeCalled();

        // Make sure we add back the body
        $response->withBody($stream)->willReturn($response)->shouldBeCalled();

        $pool->getItem(Argument::any())->shouldBeCalled()->willReturn($item);
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

        $this->handleRequest($request, $next, function () {});
    }

    function it_caches_private_responses_when_allowed(
        CacheItemPoolInterface $pool,
        CacheItemInterface $item,
        RequestInterface $request,
        UriInterface $uri,
        ResponseInterface $response,
        StreamFactory $streamFactory,
        StreamInterface $stream
    ) {
        $this->beConstructedThrough('clientCache', [$pool, $streamFactory, [
            'default_ttl' => 60,
            'cache_lifetime' => 1000,
        ]]);

        $httpBody = 'body';
        $stream->__toString()->willReturn($httpBody);
        $stream->isSeekable()->willReturn(true);
        $stream->rewind()->shouldBeCalled();
        $stream->detach()->shouldBeCalled();

        $request->getMethod()->willReturn('GET');
        $request->getUri()->willReturn($uri);
        $uri->__toString()->willReturn('https://example.com/');
        $request->getBody()->shouldBeCalled()->willReturn($stream);

        $response->getStatusCode()->willReturn(200);
        $response->getBody()->willReturn($stream);
        $response->getHeader('Cache-Control')->willReturn(['private'])->shouldBeCalled();
        $response->getHeader('Expires')->willReturn([])->shouldBeCalled();
        $response->getHeader('ETag')->willReturn([])->shouldBeCalled();
        $response->withBody($stream)->shouldBeCalled()->willReturn($response);

        $this->streamFactory->createStream($httpBody)->shouldBeCalled()->willReturn($stream);

        $pool->getItem(Argument::any())->shouldBeCalled()->willReturn($item);
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

        $this->handleRequest($request, $next, function () {});
    }

    function it_does_not_store_responses_of_requests_to_blacklisted_paths(
        CacheItemPoolInterface $pool,
        CacheItemInterface $item,
        RequestInterface $request,
        UriInterface $uri,
        ResponseInterface $response,
        StreamFactory $streamFactory,
        StreamInterface $stream
    ) {
        $this->beConstructedThrough('clientCache', [$pool, $streamFactory, [
            'default_ttl' => 60,
            'cache_lifetime' => 1000,
            'blacklisted_paths' => ['@/foo@']
        ]]);

        $httpBody = 'body';
        $stream->__toString()->willReturn($httpBody);
        $stream->isSeekable()->willReturn(true);

        $request->getMethod()->willReturn('GET');
        $request->getUri()->willReturn($uri);
        $uri->__toString()->willReturn('https://example.com/foo');
        $request->getBody()->shouldBeCalled()->willReturn($stream);

        $response->getStatusCode()->willReturn(200);
        $response->getBody()->willReturn($stream);
        $response->getHeader('Cache-Control')->willReturn([])->shouldBeCalled();

        $pool->getItem(Argument::any())->shouldBeCalled()->willReturn($item);
        $item->isHit()->willReturn(false);

        $item->set($this->getCacheItemMatcher([
            'response' => $response->getWrappedObject(),
            'body' => $httpBody,
            'expiresAt' => 0,
            'createdAt' => 0
        ]))->willReturn($item)->shouldNotBeCalled();
        $pool->save(Argument::any())->shouldNotBeCalled();

        $next = function (RequestInterface $request) use ($response) {
            return new FulfilledPromise($response->getWrappedObject());
        };

        $this->handleRequest($request, $next, function () {});
    }

    function it_stores_responses_of_requests_not_in_blacklisted_paths(
        CacheItemPoolInterface $pool,
        CacheItemInterface $item,
        RequestInterface $request,
        UriInterface $uri,
        ResponseInterface $response,
        StreamFactory $streamFactory,
        StreamInterface $stream
    ) {
        $this->beConstructedThrough('clientCache', [$pool, $streamFactory, [
            'default_ttl' => 60,
            'cache_lifetime' => 1000,
            'blacklisted_paths' => ['@/foo@']
        ]]);

        $httpBody = 'body';
        $stream->__toString()->willReturn($httpBody);
        $stream->isSeekable()->willReturn(true);
        $stream->rewind()->shouldBeCalled();
        $stream->detach()->shouldBeCalled();

        $request->getMethod()->willReturn('GET');
        $request->getUri()->willReturn($uri);
        $uri->__toString()->willReturn('https://example.com/');
        $request->getBody()->shouldBeCalled()->willReturn($stream);

        $response->getStatusCode()->willReturn(200);
        $response->getBody()->willReturn($stream);
        $response->getHeader('Cache-Control')->willReturn([])->shouldBeCalled();
        $response->getHeader('Expires')->willReturn([])->shouldBeCalled();
        $response->getHeader('ETag')->willReturn([])->shouldBeCalled();
        $response->withBody($stream)->shouldBeCalled()->willReturn($response);

        $this->streamFactory->createStream($httpBody)->shouldBeCalled()->willReturn($stream);

        $pool->getItem(Argument::any())->shouldBeCalled()->willReturn($item);
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

        $this->handleRequest($request, $next, function () {});
    }

    function it_can_be_initialized_with_custom_cache_key_generator(
        CacheItemPoolInterface $pool,
        CacheItemInterface $item,
        StreamFactory $streamFactory,
        RequestInterface $request,
        UriInterface $uri,
        ResponseInterface $response,
        StreamInterface $stream,
        SimpleGenerator $generator
    ) {
        $this->beConstructedThrough('clientCache', [$pool, $streamFactory, [
            'cache_key_generator' => $generator,
        ]]);

        $generator->generate($request)->shouldBeCalled()->willReturn('foo');

        $stream->isSeekable()->willReturn(true);
        $stream->rewind()->shouldBeCalled();
        $streamFactory->createStream(Argument::any())->willReturn($stream);

        $request->getMethod()->willReturn('GET');
        $request->getUri()->willReturn($uri);
        $uri->__toString()->willReturn('https://example.com/');
        $response->withBody(Argument::any())->willReturn($response);

        $pool->getItem(Argument::any())->shouldBeCalled()->willReturn($item);
        $item->isHit()->willReturn(true);
        $item->get()->willReturn([
            'response' => $response->getWrappedObject(),
            'body' => 'body',
            'expiresAt' => null,
            'createdAt' => 0,
            'etag' => []
        ]);

        $next = function (RequestInterface $request) use ($response) {
            return new FulfilledPromise($response->getWrappedObject());
        };

        $this->handleRequest($request, $next, function () {});
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
        return Argument::that(function(array $actualData) use ($expectedData) {
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
