<?php

namespace App\Http\Controllers;

use App\Jobs\AnswerAssistantQuestion;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class AssistantController extends Controller
{
    public function ask(Request $request): JsonResponse
    {
        abort_unless(Setting::flag('ai_module_enabled'), 403, 'The AI assistant is currently disabled.');

        $data = $request->validate([
            'question' => ['required', 'string', 'max:2000'],
        ]);

        $queryId = (string) Str::uuid();

        Cache::put("assistant_query:{$queryId}", ['status' => 'pending'], now()->addMinutes(15));

        AnswerAssistantQuestion::dispatch($queryId, $request->user()->id, $data['question']);

        return response()->json(['id' => $queryId]);
    }

    public function status(string $id): JsonResponse
    {
        $result = Cache::get("assistant_query:{$id}");

        abort_unless($result, 404);

        return response()->json($result);
    }
}
