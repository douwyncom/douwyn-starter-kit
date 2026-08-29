<?php

namespace App\Providers;

use App\Enums\ApiErrorCode;
use App\Models\PersonalAccessToken;
use App\Models\Setting;
use App\Observers\SettingObserver;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\Header as OpenApiHeader;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response as OpenApiResponse;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Douwyn\StarterKit\Api\ApiErrorCodeRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
        Scramble::ignoreDefaultRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(ApiErrorCodeRegistry $errorCodes): void
    {
        Setting::observe(SettingObserver::class);

        Scramble::afterOpenApiGenerated(function (OpenApi $openApi) use ($errorCodes): void {
            $cookieScheme = SecurityScheme::apiKey('cookie', (string) config('session.cookie'))
                ->as('sanctumCookie')
                ->setDescription('First-party Sanctum session cookie. Mutating requests also require CSRF protection.');

            $openApi->components->addSecurityScheme($cookieScheme->schemeName, $cookieScheme);
            $openApi->security ??= [];
            $openApi->security[] = new SecurityRequirement([$cookieScheme->schemeName => []]);

            $this->documentApiLifecycle($openApi, $errorCodes);
        });

        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute((int) config('api.rate_limit_per_minute'))
            ->by(match (true) {
                $request->user('web') !== null => 'user:'.$request->user('web')->getAuthIdentifier(),
                $request->bearerToken() !== null => 'token:'.hash('sha256', $request->bearerToken()),
                default => 'ip:'.$request->ip(),
            }));

        RateLimiter::for('api-login', function (Request $request): array {
            $credential = $this->authAttemptRateKey($request);
            $limits = [
                Limit::perMinute(30)->by('auth-attempt-ip:'.$request->ip()),
            ];

            if ($credential !== null) {
                $limits[] = Limit::perMinute(5)
                    ->by("auth-attempt-credential-ip:$credential|{$request->ip()}");
                $limits[] = Limit::perMinute(15)
                    ->by('auth-attempt-credential:'.$credential);
            }

            return $limits;
        });

        RateLimiter::for('api-register', fn (Request $request): Limit => Limit::perMinute(3)
            ->by($request->ip()));

        RateLimiter::for('api-refresh', fn (Request $request): array => [
            Limit::perMinute(30)->by('refresh-ip:'.$request->ip()),
            Limit::perMinute(10)->by('refresh-token:'.hash(
                'sha256',
                (string) $request->input('refresh_token'),
            )),
        ]);

        RateLimiter::for('api-password-forgot', fn (Request $request): array => [
            Limit::perMinute(10)->by('password-forgot-ip:'.$request->ip()),
            Limit::perMinute(3)->by('password-forgot-email:'.hash_hmac(
                'sha256',
                Str::lower(trim((string) $request->input('email'))),
                (string) config('app.key'),
            )),
        ]);

        RateLimiter::for('api-account-token', fn (Request $request): array => [
            Limit::perMinute(20)->by('account-token-ip:'.$request->ip()),
            Limit::perMinute(5)->by('account-token:'.hash(
                'sha256',
                (string) $request->input('token'),
            )),
        ]);

        RateLimiter::for('api-account-email', fn (Request $request): array => [
            Limit::perMinute(10)->by('account-email-ip:'.$request->ip()),
            Limit::perMinute(3)->by('account-email-credential:'.$this->credentialRateKey($request)),
        ]);

        RateLimiter::for('api-email-change', fn (Request $request): array => [
            Limit::perMinute(10)->by('email-change-ip:'.$request->ip()),
            Limit::perMinute(3)->by('email-change-credential:'.$this->credentialRateKey($request)),
        ]);
    }

    private function credentialRateKey(Request $request): string
    {
        if ($request->user()) {
            return 'user:'.$request->user()->getAuthIdentifier();
        }

        if ($request->bearerToken()) {
            return 'bearer:'.hash('sha256', $request->bearerToken());
        }

        if ($request->hasSession()) {
            return 'session:'.hash('sha256', $request->session()->getId());
        }

        return 'ip:'.$request->ip();
    }

    private function authAttemptRateKey(Request $request): ?string
    {
        $email = Str::lower(trim((string) $request->input('email')));

        if ($email !== '') {
            return 'identity:'.hash_hmac('sha256', $email, (string) config('app.key'));
        }

        $challenge = trim((string) $request->input('challenge_token'));

        if ($challenge !== '') {
            return 'challenge:'.hash_hmac('sha256', $challenge, (string) config('app.key'));
        }

        return null;
    }

    private function documentApiLifecycle(OpenApi $openApi, ApiErrorCodeRegistry $errorCodes): void
    {
        $errorCodeSchemaName = collect(array_keys($openApi->components->schemas))
            ->first(fn (string $name): bool => in_array($name, [
                ApiErrorCode::class,
                class_basename(ApiErrorCode::class),
            ], true));

        if (! is_string($errorCodeSchemaName)) {
            $openApi->components->addSchema(
                ApiErrorCode::class,
                Schema::fromType((new StringType)->enum($errorCodes->codes())),
            );
            $errorCodeSchemaName = ApiErrorCode::class;
        } else {
            $openApi->components->getSchema($errorCodeSchemaName)->type->enum($errorCodes->codes());
        }

        $this->documentErrorEnvelopes($openApi, $errorCodeSchemaName);

        foreach ($openApi->paths as $path) {
            foreach ($path->operations as $operation) {
                $hasRequestId = collect($operation->parameters)->contains(
                    fn ($parameter): bool => $parameter instanceof Parameter
                        && $parameter->in === 'header'
                        && strcasecmp($parameter->name, 'X-Request-ID') === 0,
                );

                if (! $hasRequestId) {
                    $operation->addParameters([
                        Parameter::make('X-Request-ID', 'header')
                            ->description('Optional UUID supplied by the client for end-to-end request correlation. The server generates one when it is absent or invalid.')
                            ->setSchema(Schema::fromType((new StringType)->format('uuid'))),
                    ]);
                }

                foreach ($operation->responses ?? [] as $response) {
                    if ($response instanceof Reference) {
                        $response = $response->resolve();
                    }

                    if (! $response instanceof OpenApiResponse) {
                        continue;
                    }

                    $response->addHeader('X-Request-ID', new OpenApiHeader(
                        description: 'UUID that correlates this response with server logs.',
                        schema: Schema::fromType((new StringType)->format('uuid')),
                    ));
                    $response->addHeader('X-API-Version', new OpenApiHeader(
                        description: 'Semantic version of the API contract that served the response.',
                        schema: Schema::fromType(new StringType),
                        example: (string) config('api.version'),
                    ));
                    $response->addHeader('Deprecation', new OpenApiHeader(
                        description: 'Optional RFC 9745 structured date, emitted only when this API version is deprecated.',
                        schema: Schema::fromType((new StringType)->pattern('^@[0-9]+$')),
                    ));
                    $response->addHeader('Sunset', new OpenApiHeader(
                        description: 'Optional RFC 8594 HTTP-date after which the deprecated API may become unavailable.',
                        schema: Schema::fromType(new StringType),
                    ));
                    $response->addHeader('Link', new OpenApiHeader(
                        description: 'Optional migration documentation link with rel="deprecation".',
                        schema: Schema::fromType(new StringType),
                    ));
                }
            }
        }
    }

    private function documentErrorEnvelopes(OpenApi $openApi, string $errorCodeSchemaName): void
    {
        foreach ($openApi->components->responses as $response) {
            $this->addErrorCodeToResponse($response, $openApi, $errorCodeSchemaName);
        }

        foreach ($openApi->paths as $path) {
            foreach ($path->operations as $operation) {
                foreach ($operation->responses ?? [] as $response) {
                    if ($response instanceof Reference) {
                        $response = $response->resolve();
                    }

                    if (! $response instanceof OpenApiResponse
                        || ! is_numeric($response->code)
                        || (int) $response->code < 400) {
                        continue;
                    }

                    $this->addErrorCodeToResponse($response, $openApi, $errorCodeSchemaName);
                }
            }
        }
    }

    private function addErrorCodeToResponse(
        OpenApiResponse $response,
        OpenApi $openApi,
        string $errorCodeSchemaName,
    ): void {
        $schema = $response->content['application/json'] ?? null;

        if ($schema instanceof Reference) {
            $schema = $schema->resolve();
        }

        if (! $schema instanceof Schema || ! $schema->type instanceof ObjectType) {
            return;
        }

        if (! $schema->type->hasProperty('code')) {
            $schema->type->addProperty(
                'code',
                $openApi->components->getSchemaReference($errorCodeSchemaName),
            );
        }

        $schema->type->addRequired(['code']);
    }
}
