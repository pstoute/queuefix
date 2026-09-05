<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\CannedResponse;
use App\Models\User;
use App\Services\CannedResponseRenderer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CannedResponseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $response = $this->route('cannedResponse');
        $user = $this->user();

        if (! $response instanceof CannedResponse) {
            return $user instanceof User;
        }

        return $user instanceof User
            && ($user->role === UserRole::Admin || $response->created_by === $user->id);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
            'is_active' => ['sometimes', 'boolean'],
            'visibility' => ['sometimes', 'string', Rule::in(CannedResponse::visibilities())],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $unknown = app(CannedResponseRenderer::class)->unknownPlaceholders((string) $this->input('body'));
            if ($unknown !== []) {
                $formatted = collect($unknown)->map(fn (string $name): string => "{{{$name}}}")->implode(', ');
                $validator->errors()->add('body', "Unknown placeholder(s): {$formatted}.");
            }
        }];
    }
}
