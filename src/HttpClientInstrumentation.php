<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Symfony;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\ArrayAccessGetterSetter;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class HttpClientInstrumentation
{
    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation('io.opentelemetry.contrib.php.wordpress_http');

        hook(
            HttpClientInterface::class,
            'request',
            pre: static function (
                HttpClientInterface $client,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) use ($instrumentation): array {
                
            },
            post: static function (
                HttpClientInterface $client,
                array $params,
                ?ResponseInterface $response,
                ?\Throwable $exception
            ): void {
                
            },
        );
    }
}