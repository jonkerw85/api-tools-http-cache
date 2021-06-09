<?php

namespace LaminasTest\ApiTools\HttpCache;

use DateTime;
use DateTimeZone;
use Laminas\ApiTools\HttpCache\ETagGeneratorInterface;
use Laminas\ApiTools\HttpCache\HttpCacheListener;
use Laminas\Http\Request as HttpRequest;
use Laminas\Http\Response as HttpResponse;
use Laminas\Mvc\MvcEvent;
use Laminas\Router\RouteMatch;
use PHPUnit\Framework\TestCase;

use function call_user_func_array;
use function md5;

class HttpCacheListenerTest extends TestCase
{
    /** @var HttpCacheListener */
    protected $instance;

    public function setUp(): void
    {
        $this->instance = new HttpCacheListener();
    }

    /** @return V2RouteMatch|RouteMatch */
    protected function createRouteMatch(array $matches)
    {
        $class = RouteMatch::class;

        return new $class($matches);
    }

    protected function calculateDate(int $seconds): string
    {
        $seconds += $_SERVER['REQUEST_TIME'];
        $date     = new DateTime("@{$seconds}", new DateTimeZone('GMT'));

        return $date->format('D, d M Y H:i:s \G\M\T');
    }

    /**
     * @see checkStatusCode
     *
     * @return array
     */
    public function checkStatusCodeDataProvider()
    {
        return [
            [[], 200, true],
            [[], 404, false],
            [['http_codes_black_list' => [200]], 404, true],
            [['http_codes_black_list' => [404]], 404, false],
        ];
    }

    /**
     * @see testOnRoute
     *
     * @return array
     */
    public function configDataProvider()
    {
        return [
            [
                ['enable' => false],
                'get',
                'my.route.name',
                ['action' => 'bar', 'controller' => 'foo'],
                [],
            ],
            [
                ['enable' => true],
                'get',
                'my.route.name',
                ['action' => 'bar', 'controller' => 'foo'],
                [],
            ],
            [
                [
                    'enable'      => true,
                    'controllers' => [
                        'foo' => [
                            'get' => [
                                'expires' => [
                                    'override' => true,
                                    'value'    => '+1 day',
                                ],
                            ],
                        ],
                    ],
                ],
                'get',
                'my.route.name',
                ['action' => 'bar', 'controller' => 'foo'],
                [
                    'expires' => [
                        'override' => true,
                        'value'    => '+1 day',
                    ],
                ],
            ],
            [
                [
                    'enable'      => true,
                    'controllers' => [
                        'foo'           => [
                            'get' => [
                                'expires' => [
                                    'override' => true,
                                    'value'    => '+2 day',
                                ],
                            ],
                        ],
                        'foo::bar'      => [
                            'get' => [
                                'expires' => [
                                    'override' => true,
                                    'value'    => '+2 day',
                                ],
                            ],
                        ],
                        'my.route.name' => [
                            'get' => [
                                'expires' => [
                                    'override' => true,
                                    'value'    => '+1 day',
                                ],
                            ],
                        ],
                    ],
                ],
                'get',
                'my.route.name',
                ['action' => 'bar', 'controller' => 'foo'],
                [
                    'expires' => [
                        'override' => true,
                        'value'    => '+1 day',
                    ],
                ],
            ],
            [
                [
                    'enable'      => true,
                    'controllers' => [
                        'foo' => [
                            '*' => [
                                'expires' => [
                                    'override' => true,
                                    'value'    => '+1 day',
                                ],
                            ],
                        ],
                    ],
                ],
                'head',
                'my.route.name',
                ['action' => 'bar', 'controller' => 'foo'],
                [
                    'expires' => [
                        'override' => true,
                        'value'    => '+1 day',
                    ],
                ],
            ],
            [
                [
                    'enable'      => true,
                    'controllers' => [
                        '*' => [
                            'get' => [
                                'expires' => [
                                    'override' => true,
                                    'value'    => '+1 day',
                                ],
                            ],
                        ],
                    ],
                ],
                'get',
                'my.route.name',
                ['action' => 'baz', 'controller' => 'bar'],
                [
                    'expires' => [
                        'override' => true,
                        'value'    => '+1 day',
                    ],
                ],
            ],
            [
                [
                    'enable'      => true,
                    'controllers' => [
                        '*' => [
                            '*' => [
                                'expires' => [
                                    'override' => true,
                                    'value'    => '+1 day',
                                ],
                            ],
                        ],
                    ],
                ],
                'head',
                'my.route.name',
                ['action' => 'bar', 'controller' => 'foo'],
                [
                    'expires' => [
                        'override' => true,
                        'value'    => '+1 day',
                    ],
                ],
            ],
            [
                [
                    'enable'      => true,
                    'controllers' => [
                        '*'   => [
                            '*' => [
                                'cache-control' => [
                                    'override' => false,
                                    'value'    => 'private',
                                ],
                            ],
                        ],
                        'baz' => [
                            'get' => [
                                'cache-control' => [
                                    'override' => true,
                                    'value'    => 'public',
                                ],
                            ],
                        ],
                    ],
                ],
                'head',
                'my.route.name',
                ['action' => 'bar', 'controller' => 'baz'],
                [
                    'cache-control' => [
                        'override' => false,
                        'value'    => 'private',
                    ],
                ],
            ],
            [
                [
                    'enable'          => true,
                    'controllers'     => [
                        '~my\.[a-z.]{10}~' => [
                            '*' => [
                                'cache-control' => [
                                    'override' => false,
                                    'value'    => 'private',
                                ],
                            ],
                        ],
                        '*'                => [
                            'get' => [
                                'cache-control' => [
                                    'override' => true,
                                    'value'    => 'public',
                                ],
                            ],
                        ],
                    ],
                    'regex_delimiter' => '~',
                ],
                'head',
                'my.route.name',
                ['action' => 'bar', 'controller' => 'baz'],
                [
                    'cache-control' => [
                        'override' => false,
                        'value'    => 'private',
                    ],
                ],
            ],
            [
                [
                    'enable'          => true,
                    'controllers'     => [
                        '~[a-z]{3}::[a-z]{3}~' => [
                            '*' => [
                                'cache-control' => [
                                    'override' => false,
                                    'value'    => 'private',
                                ],
                            ],
                        ],
                    ],
                    'regex_delimiter' => '~',
                ],
                'head',
                'my.route.name',
                ['action' => 'bar', 'controller' => 'baz'],
                [
                    'cache-control' => [
                        'override' => false,
                        'value'    => 'private',
                    ],
                ],
            ],
            [
                [
                    'enable'          => true,
                    'controllers'     => [
                        '~[a-z]{3}~' => [
                            '*' => [
                                'cache-control' => [
                                    'override' => false,
                                    'value'    => 'private',
                                ],
                            ],
                        ],
                        '*'          => [
                            'get' => [
                                'cache-control' => [
                                    'override' => true,
                                    'value'    => 'public',
                                ],
                            ],
                        ],
                    ],
                    'regex_delimiter' => '~',
                ],
                'head',
                'my.route.name',
                ['action' => 'bar', 'controller' => 'baz'],
                [
                    'cache-control' => [
                        'override' => false,
                        'value'    => 'private',
                    ],
                ],
            ],
            [
                [
                    'enable'          => true,
                    'controllers'     => [
                        '~[a-z]{3}~' => [
                            '*' => [
                                'cache-control' => [
                                    'override' => false,
                                    'value'    => 'private',
                                ],
                            ],
                        ],
                    ],
                    'regex_delimiter' => '~',
                ],
                'head',
                'my.route.name',
                ['action' => 'bar', 'controller' => 'baz'],
                [
                    'cache-control' => [
                        'override' => false,
                        'value'    => 'private',
                    ],
                ],
            ],
        ];
    }

    /**
     * @see testMethodsReturnSelf
     *
     * @return array
     */
    public function methodsReturnSelfDataProvider()
    {
        $response = new HttpResponse();
        $headers  = $response->getHeaders();

        return [
            ['setCacheControl', [$headers]],
            ['setCacheConfig', [[]]],
            ['setConfig', [[]]],
            ['setExpires', [$headers]],
            ['setPragma', [$headers]],
            ['setVary', [$headers]],
        ];
    }

    /**
     * @see testOnResponse
     *
     * @return array
     */
    public function onResponseDataProvider()
    {
        return [
            [
                [
                    'enable'      => true,
                    'controllers' => [
                        'foo' => [
                            'get' => [
                                'cache-control' => [
                                    'override' => true,
                                    'value'    => 'max-age=86400, must-revalidate, public',
                                ],
                                'expires'       => [
                                    'override' => true,
                                    'value'    => 'Fri, 10 Oct 2014 20:44:35 GMT',
                                ],
                                'pragma'        => [
                                    'override' => true,
                                    'value'    => 'token',
                                ],
                                'vary'          => [
                                    'override' => true,
                                    'value'    => 'accept-encoding, x-requested-with',
                                ],
                            ],
                        ],
                    ],
                ],
                'get',
                ['controller' => 'foo'],
                [
                    'Expires'       => 'Fri, 10 Oct 2014 20:44:35 GMT',
                    'Cache-Control' => 'max-age=86400, must-revalidate, public',
                    'Pragma'        => 'token',
                    'Vary'          => 'accept-encoding, x-requested-with',
                ],
            ],
        ];
    }

    /**
     * @see http://www.w3.org/Protocols/rfc2616/rfc2616-sec14.html#sec14.9
     * @see testSetCacheControl
     *
     * @return array
     */
    public function setCacheControlDataProvider()
    {
        return [
            [
                [
                    'cache-control' => [
                        'override' => true,
                        'value'    => 'max-age=86400, must-revalidate, public',
                    ],
                ],
                [],
                ['Cache-Control' => 'max-age=86400, must-revalidate, public'],
            ],
            [
                [
                    'cache-control' => [
                        'override' => true,
                        'value'    => 'max-age=86400, must-revalidate, public',
                    ],
                ],
                ['Cache-Control' => 'max-age=86400, must-revalidate, no-cache, public'],
                ['Cache-Control' => 'max-age=86400, must-revalidate, public'],
            ],
            [
                [
                    'cache-control' => [
                        'override' => false,
                        'value'    => 'max-age=86400, must-revalidate, public',
                    ],
                ],
                ['Cache-Control' => 'max-age=86400, must-revalidate, no-cache, public'],
                ['Cache-Control' => 'max-age=86400, must-revalidate, no-cache, public'],
            ],
        ];
    }

    /**
     * @see http://www.w3.org/Protocols/rfc2616/rfc2616-sec14.html#sec14.21
     * @see testSetExpires
     *
     * @return array
     */
    public function setExpiresDataProvider()
    {
        return [
            [
                [
                    'expires' => [
                        'override' => true,
                        'value'    => '+1 day',
                    ],
                ],
                [],
                ['Expires' => $this->calculateDate(86400)],
            ],
            [
                [
                    'expires' => [
                        'override' => true,
                        'value'    => '+1 day',
                    ],
                ],
                ['Expires' => $this->calculateDate(0)],
                ['Expires' => $this->calculateDate(86400)],
            ],
            [
                [
                    'expires' => [
                        'override' => false,
                        'value'    => '+1 day',
                    ],
                ],
                ['Expires' => $this->calculateDate(0)],
                ['Expires' => $this->calculateDate(0)],
            ],

            /*
             * HTTP/1.1 clients and caches MUST treat other invalid date formats,
             * especially including the value "0", as in the past
             * (i.e., "already expired").
             */
            [
                [
                    'expires' => [
                        'override' => true,
                        'value'    => 'junk-date',
                    ],
                ],
                [],
                ['Expires' => $this->calculateDate(0)],
            ],
            [
                [
                    'expires' => [
                        'override' => true,
                        'value'    => 'junk-date',
                    ],
                ],
                ['Date' => $this->calculateDate(10)],
                [
                    'Date'    => $this->calculateDate(10),
                    'Expires' => $this->calculateDate(10),
                ],
            ],
        ];
    }

    /**
     * @see http://www.w3.org/Protocols/rfc2616/rfc2616-sec14.html#sec14.32
     * @see testSetPragma
     *
     * @return array
     */
    public function setPragmaDataProvider()
    {
        return [
            [
                [
                    'pragma' => [
                        'override' => true,
                        'value'    => 'no-cache',
                    ],
                ],
                [],
                ['Pragma' => 'no-cache'],
            ],
            [
                [
                    'pragma' => [
                        'override' => true,
                        'value'    => 'no-cache',
                    ],
                ],
                ['Pragma' => 'token'],
                ['Pragma' => 'no-cache'],
            ],
            [
                [
                    'pragma' => [
                        'override' => false,
                        'value'    => 'no-cache',
                    ],
                ],
                ['Pragma' => 'token'],
                ['Pragma' => 'token'],
            ],
        ];
    }

    /**
     * @see http://www.w3.org/Protocols/rfc2616/rfc2616-sec14.html#sec14.44
     * @see testSetVary
     *
     * @return array
     */
    public function setVaryDataProvider()
    {
        return [
            [
                [
                    'vary' => [
                        'override' => true,
                        'value'    => 'accept-encoding, x-requested-with',
                    ],
                ],
                [],
                ['Vary' => 'accept-encoding, x-requested-with'],
            ],
            [
                [
                    'vary' => [
                        'override' => true,
                        'value'    => 'accept-encoding, x-requested-with',
                    ],
                ],
                ['Vary' => 'accept-encoding'],
                ['Vary' => 'accept-encoding, x-requested-with'],
            ],
            [
                [
                    'vary' => [
                        'override' => false,
                        'value'    => 'accept-encoding, x-requested-with',
                    ],
                ],
                ['Vary' => 'accept-encoding'],
                ['Vary' => 'accept-encoding'],
            ],
        ];
    }

    /**
     * @see http://www.w3.org/Protocols/rfc2616/rfc2616-sec14.html#sec14.19
     * @see testSetETag
     *
     * @return array
     */
    public function setETagDataProvider()
    {
        return [
            [
                [
                    'etag' => [
                        'override' => true,
                    ],
                ],
                [],
                ['Etag' => md5('')],
            ],
            [
                ['vary' => []],
                ['Etag' => '1234'],
                ['Etag' => '1234'],
            ],
            [
                [
                    'vary' => [
                        'override' => false,
                    ],
                ],
                ['Etag' => '1234'],
                ['Etag' => '1234'],
            ],
            [
                [
                    'etag' => [
                        'override' => true,
                    ],
                ],
                ['Etag' => '1234'],
                ['Etag' => md5('')],
            ],
        ];
    }

    /**
     * @see testSetNotModified
     *
     * @return array
     */
    public function setNotModifiedDataProvider()
    {
        return [
            [
                [],
                ['Etag' => '123'],
                200,
            ],
            [
                ['If-None-Match' => '1234'],
                ['Etag' => '1234'],
                304,
            ],
            [
                ['If-None-Match' => '1234'],
                ['Etag' => 'something-else'],
                200,
            ],
        ];
    }

    /**
     * @covers \Laminas\ApiTools\HttpCache\HttpCacheListener::checkStatusCode
     * @dataProvider checkStatusCodeDataProvider
     * @param array   $config
     * @param integer $code
     * @param boolean $exResult
     */
    public function testCheckStatusCode(array $config, $code, $exResult)
    {
        $this->instance->setConfig($config);

        $response = new HttpResponse();
        $response->setStatusCode($code);

        $ret = $this->instance->checkStatusCode($response);

        $this->assertSame($exResult, $ret);
    }

    /**
     * @coversNothing
     * @dataProvider methodsReturnSelfDataProvider
     * @param string $method
     * @param array  $args
     */
    public function testMethodsReturnsSelf($method, $args)
    {
        $ret = call_user_func_array([$this->instance, $method], $args);

        $this->assertInstanceOf(HttpCacheListener::class, $ret);
    }

    /**
     * @covers \Laminas\ApiTools\HttpCache\HttpCacheListener::onResponse
     * @dataProvider onResponseDataProvider
     * @param array  $config
     * @param string $method
     * @param array  $routeMatch
     * @param array  $exHeaders
     */
    public function testOnResponse(array $config, $method, array $routeMatch, array $exHeaders)
    {
        $request = new HttpRequest();
        $request->setMethod($method);

        $event = new MvcEvent();
        $event->setRequest($request);
        $event->setRouteMatch($this->createRouteMatch($routeMatch));

        $response = new HttpResponse();
        $event->setResponse($response);

        $this->instance->setConfig($config);
        $this->instance->onRoute($event);
        $this->instance->onResponse($event);

        $headers = $event->getResponse()
            ->getHeaders();

        $this->assertSame($exHeaders, $headers->toArray());
    }

    /**
     * @covers \Laminas\ApiTools\HttpCache\HttpCacheListener::onRoute
     * @dataProvider configDataProvider
     * @param array  $config
     * @param string $method
     * @param string $routeName
     * @param array  $routeMatch
     * @param array  $exCacheConfig
     */
    public function testOnRoute(array $config, $method, $routeName, array $routeMatch, array $exCacheConfig)
    {
        $request = new HttpRequest();
        $request->setMethod($method);

        $routeMatch = $this->createRouteMatch($routeMatch);
        if ($routeName) {
            $routeMatch->setMatchedRouteName($routeName);
        }

        $event = new MvcEvent();
        $event->setRequest($request);
        $event->setRouteMatch($routeMatch);

        $this->instance->setConfig($config)
            ->onRoute($event);

        $cacheConfig = $this->instance->getCacheConfig();

        $this->assertSame(
            ! empty($cacheConfig),
            $this->instance->hasCacheConfig()
        );
        $this->assertSame($exCacheConfig, $cacheConfig);
    }

    /**
     * @covers \Laminas\ApiTools\HttpCache\HttpCacheListener::setCacheControl
     * @dataProvider setCacheControlDataProvider
     * @param array $cacheConfig
     * @param array $headers
     * @param array $exHeaders
     */
    public function testSetCacheControl(array $cacheConfig, array $headers, array $exHeaders)
    {
        $this->instance->setCacheConfig($cacheConfig);

        $response = new HttpResponse();
        $headers  = $response->getHeaders()
            ->addHeaders($headers);

        $this->instance->setCacheControl($headers);

        $this->assertSame($exHeaders, $headers->toArray());
    }

    /**
     * @covers \Laminas\ApiTools\HttpCache\HttpCacheListener::setExpires
     * @dataProvider setExpiresDataProvider
     * @param array $cacheConfig
     * @param array $headers
     * @param array $exHeaders
     */
    public function testSetExpires(array $cacheConfig, array $headers, array $exHeaders)
    {
        $this->instance->setCacheConfig($cacheConfig);

        $response = new HttpResponse();
        $headers  = $response->getHeaders()
            ->addHeaders($headers);

        $this->instance->setExpires($headers);

        $headers = $headers->toArray();

        $this->assertArrayHasKey('Expires', $headers);

        $date   = new DateTime($headers['Expires']);
        $exDate = new DateTime($exHeaders['Expires']);

        $this->assertEqualsWithDelta($exDate, $date, 3);
    }

    /**
     * @covers \Laminas\ApiTools\HttpCache\HttpCacheListener::setPragma
     * @dataProvider setPragmaDataProvider
     * @param array $cacheConfig
     * @param array $headers
     * @param array $exHeaders
     */
    public function testSetPragma(array $cacheConfig, array $headers, array $exHeaders)
    {
        $this->instance->setCacheConfig($cacheConfig);

        $response = new HttpResponse();
        $headers  = $response->getHeaders()
            ->addHeaders($headers);

        $this->instance->setPragma($headers);

        $this->assertSame($exHeaders, $headers->toArray());
    }

    /**
     * @covers \Laminas\ApiTools\HttpCache\HttpCacheListener::setvary
     * @dataProvider setVaryDataProvider
     * @param array $cacheConfig
     * @param array $headers
     * @param array $exHeaders
     */
    public function testSetVary(array $cacheConfig, array $headers, array $exHeaders)
    {
        $this->instance->setCacheConfig($cacheConfig);

        $response = new HttpResponse();
        $headers  = $response->getHeaders()
            ->addHeaders($headers);

        $this->instance->setVary($headers);

        $this->assertSame($exHeaders, $headers->toArray());
    }

    /**
     * @covers \Laminas\ApiTools\HttpCache\HttpCacheListener::setETag
     * @dataProvider setEtagDataProvider
     * @param array $cacheConfig
     * @param array $headers
     * @param array $exHeaders
     */
    public function testSetETag(array $cacheConfig, array $headers, array $exHeaders)
    {
        $this->instance->setCacheConfig($cacheConfig);

        $response = new HttpResponse();
        $headers  = $response->getHeaders()
            ->addHeaders($headers);

        $this->instance->setETag(new HttpRequest(), $response);

        $this->assertSame($exHeaders, $headers->toArray());
    }

    public function testSetETagGenerator()
    {
        $testGenerator = $this->createMock(ETagGeneratorInterface::class);
        $testGenerator
            ->expects($this->once())
            ->method('generate')
            ->willReturn('generated');

        $httpCacheListener = new HttpCacheListener($testGenerator);
        $httpCacheListener->setCacheConfig([
            'etag' => [
                'override'  => true,
                'generator' => 'test-etag-generator',
            ],
        ]);

        $response = new HttpResponse();
        $headers  = $response->getHeaders();

        $httpCacheListener->setETag(new HttpRequest(), $response);

        $this->assertSame(['Etag' => 'generated'], $headers->toArray());
    }

    /**
     * @internal param array $cacheConfig
     *
     * @covers       \Laminas\ApiTools\HttpCache\HttpCacheListener::setvary
     * @dataProvider setNotModifiedDataProvider
     * @param array $requestHeaders
     * @param array $responseHeaders
     * @param array $exStatusCode
     */
    public function testSetNotModified(array $requestHeaders, array $responseHeaders, $exStatusCode)
    {
        $request = new HttpRequest();
        $request->getHeaders()->addHeaders($requestHeaders);

        $response = new HttpResponse();
        $response->getHeaders()->addHeaders($responseHeaders);

        $this->instance->setNotModified($request, $response);

        $this->assertSame($exStatusCode, $response->getStatusCode());
    }
}
