<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\OneployAiGatewayException;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Services\OnePloy\AiGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

class OneployAiGatewayController extends Controller
{
    private const ALLOWED_FIELDS = [
        'model',
        'messages',
        'temperature',
        'max_tokens',
        'top_p',
        'stop',
        'presence_penalty',
        'frequency_penalty',
        'seed',
        'stream',
    ];

    public function chatCompletions(Request $request, AiGatewayService $gateway): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if ($teamId === null) {
            return invalidTokenResponse();
        }

        $body = $request->json()->all();
        $models = config('oneploy.ai_gateway.models', []);
        $modelNames = is_array($models) ? array_keys($models) : [];
        $validator = customApiValidator($body, [
            'model' => ['required', 'string', 'max:128', Rule::in($modelNames)],
            'messages' => ['required', 'array', 'min:1', 'max:100'],
            'messages.*' => ['required', 'array'],
            'messages.*.role' => ['required', 'string', Rule::in(['system', 'user', 'assistant'])],
            'messages.*.content' => ['required', 'string', 'max:50000'],
            'temperature' => ['sometimes', 'numeric', 'between:0,2'],
            'max_tokens' => ['sometimes', 'integer', 'between:1,32768'],
            'top_p' => ['sometimes', 'numeric', 'between:0,1'],
            'stop' => ['sometimes', 'nullable', 'string', 'max:200'],
            'presence_penalty' => ['sometimes', 'numeric', 'between:-2,2'],
            'frequency_penalty' => ['sometimes', 'numeric', 'between:-2,2'],
            'seed' => ['sometimes', 'integer'],
            'stream' => ['sometimes', 'boolean'],
        ]);

        $errors = $validator->errors();
        foreach (array_diff(array_keys($body), self::ALLOWED_FIELDS) as $field) {
            $errors->add($field, 'This field is not allowed.');
        }

        foreach (($body['messages'] ?? []) as $index => $message) {
            if (! is_array($message)) {
                continue;
            }

            foreach (array_diff(array_keys($message), ['role', 'content']) as $field) {
                $errors->add("messages.{$index}.{$field}", 'This field is not allowed.');
            }
        }

        if (($body['stream'] ?? false) === true) {
            $errors->add('stream', 'Streaming is not supported by this endpoint.');
        }

        $idempotencyKey = $this->idempotencyKey($request);
        if ($idempotencyKey === false) {
            $errors->add(
                'idempotency_key',
                'The Idempotency-Key header must be 8-128 URL-safe characters.',
            );
        }

        if ($validator->fails() || $errors->isNotEmpty()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }

        $payload = $validator->validated();
        $contentLength = collect($payload['messages'])
            ->sum(fn (array $message): int => mb_strlen($message['content']));
        if ($contentLength > 200000) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => [
                    'messages' => ['The combined message content may not exceed 200000 characters.'],
                ],
            ], 422);
        }

        $team = Team::query()->whereKey($teamId)->first();
        if (! $team) {
            return invalidTokenResponse();
        }

        try {
            $result = $gateway->complete(
                $team,
                (int) $request->user()->id,
                $payload,
                is_string($idempotencyKey) ? $idempotencyKey : null,
            );
        } catch (OneployAiGatewayException $exception) {
            return response()->json([
                'error' => [
                    'message' => $exception->getMessage(),
                    'type' => 'gateway_error',
                    'code' => $exception->errorCode,
                ],
            ], $exception->httpStatus);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'error' => [
                    'message' => 'The AI Gateway could not complete the request.',
                    'type' => 'gateway_error',
                    'code' => 'gateway_internal_error',
                ],
            ], 500);
        }

        $response = response()->json($result['payload'], $result['status']);
        if ($result['replay']) {
            $response->header('X-OnePloy-Idempotent-Replay', 'true');
        }

        return $response;
    }

    private function idempotencyKey(Request $request): string|false|null
    {
        $value = $request->header('Idempotency-Key');
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return preg_match('/\A[A-Za-z0-9._:-]{8,128}\z/', $value) === 1
            ? $value
            : false;
    }
}
