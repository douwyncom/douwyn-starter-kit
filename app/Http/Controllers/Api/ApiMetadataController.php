<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApiErrorCode;
use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Response;
use Douwyn\StarterKit\Api\ApiErrorCodeRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Lang;

class ApiMetadataController extends Controller
{
    /**
     * List stable API error codes.
     *
     * @return JsonResponse<array{
     *     data: list<array{code: string, http_status: int, description: string, retryable: bool}>,
     *     meta: array{api_version: string}
     * }>
     */
    #[Response(
        status: 200,
        description: 'The stable, machine-readable API error-code catalogue.',
        type: 'array{data: list<array{code: '.ApiErrorCode::class.', http_status: int, description: string, retryable: bool}>, meta: array{api_version: string}}',
    )]
    public function errorCodes(ApiErrorCodeRegistry $errorCodes): JsonResponse
    {
        $definitions = array_map(static function (array $definition): array {
            $translationKey = 'api.error_codes.'.$definition['code'];

            if (! Lang::has($translationKey, app()->getLocale(), false)) {
                return $definition;
            }

            return [
                ...$definition,
                'description' => __($translationKey),
            ];
        }, $errorCodes->catalogue());

        return response()->json([
            'data' => $definitions,
            'meta' => [
                'api_version' => (string) config('api.version'),
            ],
        ]);
    }
}
