<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

abstract class Controller
{
    protected function sorting(Request $request, array $allowed, string $default, string $defaultDirection = 'asc'): array
    {
        $values = validator($request->only(['sort', 'direction']), [
            'sort' => ['nullable', Rule::in(array_keys($allowed))],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ])->validate();

        $sort = $values['sort'] ?? $default;
        return [$sort, $values['direction'] ?? $defaultDirection, $allowed[$sort]];
    }
}
