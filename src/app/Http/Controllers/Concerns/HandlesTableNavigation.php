<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

trait HandlesTableNavigation
{
    protected function tableRequest(Request $request): Request
    {
        foreach (['search', 'sort', 'direction', 'per_page', 'page'] as $key) {
            if ($request->filled("table_{$key}")) {
                $request->merge([$key => $request->input("table_{$key}")]);
            }
        }

        return $request;
    }

    protected function tablePage(Builder $query, Request $request, int|string|null $recordId = null): int
    {
        $perPage = max(1, (int) $request->input('per_page', 10));
        $ids = (clone $query)->get()->pluck('id')->map(fn ($id) => (string) $id)->values();

        if ($recordId === null) {
            return max(1, min((int) $request->input('page', 1), (int) ceil($ids->count() / $perPage)));
        }

        $position = $ids->search((string) $recordId);

        return $position === false ? 1 : (int) floor($position / $perPage) + 1;
    }

    protected function tableNavigation(Builder $query, Request $request, int|string|null $recordId = null): array
    {
        return [
            'redirect_to_page' => $this->tablePage($query, $request, $recordId),
            'highlight_id' => $recordId,
        ];
    }
}
