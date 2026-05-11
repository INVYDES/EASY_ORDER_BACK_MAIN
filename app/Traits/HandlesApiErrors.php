<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

trait HandlesApiErrors
{
    /**
     * Standardized error response that hides technical details in production.
     */
    protected function errorResponse(string $message, \Exception $e, int $status = 500): JsonResponse
    {
        Log::error($message . ': ' . $e->getMessage(), [
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => collect($e->getTrace())->take(5)->toArray()
        ]);

        $payload = [
            'success' => false,
            'message' => $message
        ];

        if (config('app.debug')) {
            $payload['error'] = $e->getMessage();
            $payload['debug'] = [
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ];
        }

        return response()->json($payload, $status);
    }
}
