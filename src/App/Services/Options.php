<?php

namespace LaravelEnso\Select\App\Services;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use LaravelEnso\Filters\App\Services\Search;

class Options implements Responsable
{
    private const Limit = 100;

    private Builder $query;
    private string $trackBy;
    private Collection $queryAttributes;
    private Request $request;
    private Collection $selected;
    private array $value;
    private string $searchMode;
    private ?string $orderBy;
    private ?string $resource;
    private ?array $appends;

    public function __construct(Builder $query, string $trackBy, array $queryAttributes)
    {
        $this->query = $query;
        $this->trackBy = $trackBy;
        $this->queryAttributes = new Collection($queryAttributes);
    }

    public function toResponse($request)
    {
        $this->request = $request;

        return $this->resource
            ? $this->resource::collection($this->data())
            : $this->data();
    }

    public function searchMode(string $searchMode): self
    {
        $this->searchMode = $searchMode;

        return $this;
    }

    public function resource(?string $resource): self
    {
        $this->resource = $resource;

        return $this;
    }

    public function appends(?array $appends): self
    {
        $this->appends = $appends;

        return $this;
    }

    private function data(): Collection
    {
        return $this->init()
            ->applyParams()
            ->applyPivotParams()
            ->selected()
            ->search()
            ->order()
            ->limit()
            ->get();
    }

    private function init(): self
    {
        $this->value = $this->request->has('value')
            ? (array) $this->request->get('value')
            : [];

        $attribute = $this->queryAttributes->first();
        $this->orderBy = $this->isNested($attribute) ? null : $attribute;

        return $this;
    }

    private function applyParams(): self
    {
        $this->params()->each(fn ($value, $column) => $this->query
            ->when($value === null, fn ($query) => $query->whereNull($column))
            ->when($value !== null, fn ($query) => $query->whereIn($column, (array) $value)));

        return $this;
    }

    private function applyPivotParams(): self
    {
        $this->pivotParams()->each(fn ($param, $relation) => $this->query
            ->whereHas($relation, fn ($query) => (new Collection($param))
                ->each(fn ($value, $attribute) => $query
                    ->whereIn($attribute, (array) $value))));

        return $this;
    }

    private function selected(): self
    {
        $this->selected = (clone $this->query)
            ->whereIn($this->trackBy, $this->value)
            ->get();

        return $this;
    }

    private function search(): self
    {
        $search = $this->request->get('query');

        if (! $search) {
            return $this;
        }

        (new Search($this->query, $this->attributes(), $search))
            ->relations($this->relations())
            ->searchMode($this->searchMode)
            ->comparisonOperator(Config::get('enso.select.comparisonOperator'))
            ->handle();

        return $this;
    }

    private function attributes(): array
    {
        return $this->queryAttributes
            ->reject(fn ($attribute) => $this->isNested($attribute))
            ->toArray();
    }

    private function relations(): array
    {
        return $this->queryAttributes
            ->filter(fn ($attribute) => $this->isNested($attribute))
            ->toArray();
    }

    private function order(): self
    {
        $this->query->when($this->orderBy, fn ($query) => $query->orderBy($this->orderBy));

        return $this;
    }

    private function limit(): self
    {
        $limit = $this->request->get('paginate') ?? self::Limit;

        $this->query->limit($limit);

        return $this;
    }

    private function get(): Collection
    {
        return $this->query->whereNotIn($this->trackBy, $this->value)->get()
            ->merge($this->selected)
            ->when($this->orderBy !== null, fn ($results) => $results->sortBy($this->orderBy))
            ->values()
            ->when($this->appends, fn ($results) => $results->each->setAppends($this->appends));
    }

    private function params(): Collection
    {
        return new Collection(json_decode($this->request->get('params')));
    }

    private function pivotParams(): Collection
    {
        return new Collection(json_decode($this->request->get('pivotParams')));
    }

    private function isNested($attribute): bool
    {
        return Str::contains($attribute, '.');
    }
}