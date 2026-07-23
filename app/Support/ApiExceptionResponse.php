<?php

namespace App\Support;

use App\Enums\ApiErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ApiExceptionResponse
{
    public function __construct(private readonly ApiLifecycle $lifecycle) {}

    public function prepare(Response $response, Throwable $exception, Request $request): Response
    {
        if (! $request->is('api/*')) {
            return $response;
        }

        if ($response instanceof JsonResponse && $response->getStatusCode() >= 400) {
            $payload = $response->getData(true);

            if (is_array($payload) && ! array_key_exists('code', $payload)) {
                $payload['code'] = ApiErrorCode::forHttpStatus($response->getStatusCode())->value;
                $response->setData($payload);
            }
        }

        return $this->lifecycle->apply($response, $request);
    }
}
