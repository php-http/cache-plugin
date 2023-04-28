<?php

namespace spec\Http\Client\Common\Plugin\Cache\Generator;

use PhpSpec\ObjectBehavior;
use Psr\Http\Message\RequestInterface;
use Http\Client\Common\Plugin\Cache\Generator\CacheKeyGenerator;
use Http\Client\Common\Plugin\Cache\Generator\SimpleGenerator;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

class SimpleGeneratorSpec extends ObjectBehavior
{
    public function it_is_initializable()
    {
        $this->shouldHaveType(SimpleGenerator::class);
    }

    public function it_is_a_key_generator()
    {
        $this->shouldImplement(CacheKeyGenerator::class);
    }

    public function it_generates_cache_from_request(RequestInterface $request, UriInterface $uri, StreamInterface $body)
    {
        $uri->__toString()->shouldBeCalled()->willReturn('http://example.com/foo');
        $body->__toString()->shouldBeCalled()->willReturn('bar');
        $request->getMethod()->shouldBeCalled()->willReturn('GET');
        $request->getUri()->shouldBeCalled()->willReturn($uri);
        $request->getBody()->shouldBeCalled()->willReturn($body);

        $this->generate($request)->shouldReturn('GET http://example.com/foo bar');
    }

    public function it_generates_cache_from_request_with_no_body(RequestInterface $request, UriInterface $uri, StreamInterface $body)
    {
        $uri->__toString()->shouldBeCalled()->willReturn('http://example.com/foo');
        $body->__toString()->shouldBeCalled()->willReturn('');
        $request->getMethod()->shouldBeCalled()->willReturn('GET');
        $request->getUri()->shouldBeCalled()->willReturn($uri);
        $request->getBody()->shouldBeCalled()->willReturn($body);

        // No extra space after uri
        $this->generate($request)->shouldReturn('GET http://example.com/foo');
    }
}
