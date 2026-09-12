<?php

namespace App\Support;

use App\Enums\ApiErrorCode;
use Douwyn\StarterKit\Contracts\LocaleResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Lang;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ApiExceptionResponse
{
    public function __construct(
        private readonly ApiLifecycle $lifecycle,
        private readonly LocaleResolver $locales,
    ) {}

    public function prepare(Response $response, Throwable $exception, Request $request): Response
    {
        if (! $request->is('api/*')) {
            return $response;
        }

        try {
            App::setLocale($this->locales->resolveForApi($request));
        } catch (Throwable) {
            // Authentication or profile storage may be the source of the
            // original exception. Keep rendering with the current locale.
        }

        if ($response instanceof JsonResponse && $response->getStatusCode() >= 400) {
            $payload = $response->getData(true);

            if (is_array($payload) && ! array_key_exists('code', $payload)) {
                $errorCode = ApiErrorCode::forHttpStatus($response->getStatusCode());
                $payload['code'] = $errorCode->value;

                if ($errorCode !== ApiErrorCode::VALIDATION_FAILED) {
                    $translationKey = 'api.errors.'.$errorCode->value;

                    if (Lang::has($translationKey, app()->getLocale(), false)) {
                        $payload['message'] = __($translationKey);
                    }
                }

                $response->setData($payload);
            }
        }

        return $this->lifecycle->apply($response, $request);
    }
}
