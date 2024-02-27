<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Wordpress;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use Throwable;

class WordpressInstrumentation
{
    public const NAME = 'wordpress';

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation('io.opentelemetry.contrib.php.wordpress');
        $httpInstrument = new CachedInstrumentation('io.opentelemetry.contrib.php.wordpress_http');

        self::_hook($instrumentation, 'WP', 'main', 'WP.main');
        self::_hook($instrumentation, 'WP', 'init', 'WP.init');
        self::_hook($instrumentation, 'WP', 'parse_request', 'WP.parse_request');
        self::_hook($instrumentation, 'WP', 'send_headers', 'WP.send_headers');
        self::_hook($instrumentation, 'WP', 'query_posts', 'WP.query_posts');
        self::_hook($instrumentation, 'WP', 'handle_404', 'WP.handle_404');
        self::_hook($instrumentation, 'WP', 'register_globals', 'WP.register_globals');
        self::_hook($instrumentation, null, 'get_single_template', 'get_single_template');
        self::_hook($instrumentation, 'wpdb', 'db_connect', 'wpdb.db_connect', SpanKind::KIND_CLIENT);
        self::_hook($instrumentation, 'wpdb', 'close', 'wpdb.close', SpanKind::KIND_CLIENT);

        /**
         * Database class constructor
         */
        hook(
            class: 'wpdb',
            function: '__construct',
            pre: static function ($object, ?array $params, ?string $class, ?string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $span = self::builder($instrumentation, 'wpdb.__connect', $function, $class, $filename, $lineno)
                    ->setAttribute(TraceAttributes::DB_USER, $params[0] ?? 'unknown')
                    ->setAttribute(TraceAttributes::DB_NAME, $params[1] ?? 'unknown')
                    ->setAttribute(TraceAttributes::DB_SYSTEM, 'mysql')
                    ->startSpan();
                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function ($object, ?array $params, mixed $return, ?Throwable $exception) {
                self::end($exception);
            }
        );

        /**
         * Create a span for every db query. This can get noisy, so could be turned off via config?
         */
        hook(
            class: 'wpdb',
            function: 'query',
            pre: static function ($object, ?array $params, ?string $class, ?string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                $span = self::builder($instrumentation, 'wpdb.query', $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(TraceAttributes::DB_STATEMENT, $params[0] ?? 'undefined')
                    ->startSpan();
                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function ($object, ?array $params, mixed $return, ?Throwable $exception) {
                self::end($exception);
            }
        );

        //wp_initial_constants is earliest hookable WordPress function that is run once. Here we use it to create the root span
        hook(
            class: null,
            function: 'wp_initial_constants',
            pre: static function () use ($instrumentation) {
                $factory = new Psr17Factory();
                $request = (new ServerRequestCreator($factory, $factory, $factory, $factory))->fromGlobals();
                $parent = Globals::propagator()->extract($request->getHeaders());

                $span = $instrumentation
                    ->tracer()
                    ->spanBuilder(sprintf('%s', $request->getMethod()))
                    ->setParent($parent)
                    ->setSpanKind(SpanKind::KIND_SERVER)
                    ->setAttribute(TraceAttributes::URL_FULL, (string) $request->getUri())
                    ->setAttribute(TraceAttributes::URL_SCHEME, $request->getUri()->getScheme())
                    ->setAttribute(TraceAttributes::URL_PATH, $request->getUri()->getPath())
                    ->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, $request->getMethod())
                    ->setAttribute(TraceAttributes::NETWORK_PROTOCOL_VERSION, $request->getProtocolVersion())
                    ->setAttribute(TraceAttributes::USER_AGENT_ORIGINAL, $request->getHeaderLine('User-Agent'))
                    ->setAttribute(TraceAttributes::HTTP_REQUEST_BODY_SIZE, $request->getHeaderLine('Content-Length'))
                    ->setAttribute(TraceAttributes::CLIENT_ADDRESS, $request->getUri()->getHost())
                    ->setAttribute(TraceAttributes::CLIENT_PORT, $request->getUri()->getPort())
                    ->startSpan();
                Context::storage()->attach($span->storeInContext(Context::getCurrent()));

                //register a shutdown function to end root span (@todo, ensure it runs _before_ tracer shuts down)
                register_shutdown_function(function () use ($span) {
                    //@todo there could be other interesting settings from wordpress...
                    function_exists('is_admin') && $span->setAttribute('wp.is_admin', is_admin());

                    if (function_exists('is_404') && is_404()) {
                        $span->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, 404);
                        $span->setStatus(StatusCode::STATUS_ERROR);
                    }
                    //@todo check for other errors?

                    $span->end();
                    $scope = Context::storage()->scope();
                    if (!$scope) {
                        return;
                    }
                    $scope->detach();
                });
            }
        );



        /**
         * Taken from Auto Guzzle instrumentation
         */

         hook(
            class: \WpOrg\Requests\Transport::class,
            function: 'request',
            pre: static function (\WpOrg\Requests\Transport\Curl $request, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($httpInstrument): \WpOrg\Requests\Transport\Curl {
                
                $urlParts = parse_url($params[0]);

                $method = $params[3]['type'];
                $uri = $params[0];
                $protocolVersion = $params[3]['protocol_version'];
                $userAgent = $params[3]['useragent'];
                $host = $urlParts['host'];
                $port = $urlParts['port'];
                $path = $urlParts['path'];

                $spanBuilder = self::builder($httpInstrument, $method, $function, $class, $filename, $lineno)
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(TraceAttributes::URL_FULL, $uri)
                    ->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, $method)
                    ->setAttribute(TraceAttributes::NETWORK_PROTOCOL_VERSION, $protocolVersion)
                    ->setAttribute(TraceAttributes::USER_AGENT_ORIGINAL, $userAgent)
                    ->setAttribute(TraceAttributes::SERVER_ADDRESS, $host)
                    ->setAttribute(TraceAttributes::SERVER_PORT, $port)
                    ->setAttribute(TraceAttributes::URL_PATH, $path);

                foreach ((array) $params[1] as $header => $value) {
                    $spanBuilder->setAttribute(
                        sprintf('http.request.header.%s', strtolower($header)), 
                        $value
                    );
                }
                $span = $spanBuilder->startSpan();
                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
                return $request;
            },
            post: static function ($client, array $params, $promise, ?Throwable $exception): void {
                
                self::end($exception);
               // echo "<pre>------------------------------------\n"; var_dump($client, $params, $promise, $exception);die;
                // $scope = Context::storage()->scope();
                // $scope?->detach();

                // if (!$scope || $scope->context() === Context::getCurrent()) {
                //     return;
                // }

                // $span = Span::fromContext($scope->context());
                // if ($exception) {
                //     $span->recordException($exception, [TraceAttributes::EXCEPTION_ESCAPED => true]);
                //     $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                //     $span->end();
                // }

                // $promise->then(
                //     onFulfilled: function (ResponseInterface $response) use ($span) {
                //         $span->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $response->getStatusCode());
                //         $span->setAttribute(TraceAttributes::NETWORK_PROTOCOL_VERSION, $response->getProtocolVersion());
                //         $span->setAttribute(TraceAttributes::HTTP_RESPONSE_BODY_SIZE, $response->getHeaderLine('Content-Length'));
                //         if ($response->getStatusCode() >= 400 && $response->getStatusCode() < 600) {
                //             $span->setStatus(StatusCode::STATUS_ERROR);
                //         }
                //         $span->end();

                //         return $response;
                //     },
                //     onRejected: function (\Throwable $t) use ($span) {
                //         $span->recordException($t, [TraceAttributes::EXCEPTION_ESCAPED => true]);
                //         $span->setStatus(StatusCode::STATUS_ERROR, $t->getMessage());
                //         $span->end();

                //         throw $t;
                //     }
                // );
            }
        );

        // hook(
        //     class: \WpOrg\Requests\Transport::class,
        //     function: 'process_response',
        //     pre: static function (\WpOrg\Requests\Transport\Curl $request, array $response, string $class, string $function, ?string $filename, ?int $lineno) use ($httpInstrument): void {
                
        //         echo "<pre>------------------------------------\n"; var_dump($request, $response,  $class,  $function, $filename, $lineno);die;
        //         die();
        //     },
        //     post: static function ($client, array $params, $promise, ?Throwable $exception): void {
                
        //         echo "<pre>------------------------------------\n"; var_dump($client, $params, $promise, $exception);die;
        //         self::end($exception);
        //     }
        // );


    }

    /**
     * Simple generic hook function which starts and ends a minimal span
     * @psalm-param SpanKind::KIND_* $spanKind
     */
    private static function _hook(CachedInstrumentation $instrumentation, ?string $class, string $function, string $name, int $spanKind = SpanKind::KIND_SERVER): void
    {
        hook(
            class: $class,
            function: $function,
            pre: static function ($object, ?array $params, ?string $class, ?string $function, ?string $filename, ?int $lineno) use ($instrumentation, $name, $spanKind) {
                $span = self::builder($instrumentation, $name, $function, $class, $filename, $lineno)
                    ->setSpanKind($spanKind)
                    ->startSpan();
                Context::storage()->attach($span->storeInContext(Context::getCurrent()));
            },
            post: static function ($object, ?array $params, mixed $return, ?Throwable $exception) {
                self::end($exception);
            }
        );
    }

    private static function builder(
        CachedInstrumentation $instrumentation,
        string $name,
        ?string $function,
        ?string $class,
        ?string $filename,
        ?int $lineno,
    ): SpanBuilderInterface {
        /** @psalm-suppress ArgumentTypeCoercion */
        return $instrumentation->tracer()
            ->spanBuilder($name)
            ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
            ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
            ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
            ->setAttribute(TraceAttributes::CODE_LINENO, $lineno);
    }

    private static function end(?Throwable $exception): void
    {
        $scope = Context::storage()->scope();
        if (!$scope) {
            return;
        }
        $scope->detach();
        $span = Span::fromContext($scope->context());
        if ($exception) {
            $span->recordException($exception, [TraceAttributes::EXCEPTION_ESCAPED => true]);
            $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
        }

        $span->end();
    }
}
