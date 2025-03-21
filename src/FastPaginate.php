<?php
/**
 * @author Aaron Francis <aarondfrancis@gmail.com|https://twitter.com/aarondfrancis>
 */

namespace Hammerstone\FastPaginate;

use Closure;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FastPaginate
{
    public function fastPaginate()
    {
        return $this->paginate('paginate', function (array $items, $paginator) {
            return $this->paginator(
                $this->model->newCollection($items),
                $paginator->total(),
                $paginator->perPage(),
                $paginator->currentPage(),
                $paginator->getOptions()
            );
        });
    }

    public function simpleFastPaginate()
    {
        return $this->paginate('simplePaginate', function (array $items, $paginator) {
            return $this->simplePaginator(
                $this->model->newCollection($items),
                $paginator->perPage(),
                $paginator->currentPage(),
                $paginator->getOptions()
            )->hasMorePagesWhen($paginator->hasMorePages());
        });
    }

    protected function paginate(string $paginationMethod, Closure $paginatorOutput)
    {
        return function (
            $perPage = null,
            $columns = ['*'],
            $pageName = 'page',
            $page = null,
            $countQueryCallback = null,
        ) use (
            $paginationMethod,
            $paginatorOutput
        ) {
            /** @var \Illuminate\Database\Query\Builder $this */
            $base = $this->getQuery();
            // Havings, groups, and unions don't work well with this paradigm, because
            // we are counting on each row of the inner query to return a primary key
            // that we can use. When grouping, that's not always the case.
//            if (filled($base->havings) || filled($base->groups) || filled($base->unions)) {
//                return $this->{$paginationMethod}($perPage, $columns, $pageName, $page);
//            }

            $model = $this->newModelInstance();
            $key = $model->getKeyName();
            $table = $model->getTable();

            // Apparently some databases allow for offset 0 with no limit and some people
            // use it as a hack to get all records. Since that defeats the purpose of
            // fast pagination, we'll just return the normal paginator in that case.
            // https://github.com/hammerstonedev/fast-paginate/issues/39
            if ($perPage === -1) {
                return $this->{$paginationMethod}($perPage, $columns, $pageName, $page);
            }

            try {
                $innerSelectColumns = FastPaginate::getInnerSelectColumns($this);
            } catch (QueryIncompatibleWithFastPagination $e) {
                return $this->{$paginationMethod}($perPage, $columns, $pageName, $page);
            }

            /** @var bool $canReplaceGroupByWithDistinct */
            // This method calls out to one of our own internal builder macros to determine whether
            // group by clauses can be replaced with distinct to further improve query performance.
            $canReplaceGroupByWithDistinct = $this->canReplaceGroupByWithDistinct();

            // This is the copy of the query that becomes
            // the inner query that selects keys only.
            $paginator = $this->clone()
                // Only select the primary key, we'll get the full
                // records in a second query below.
                ->select($innerSelectColumns)
                // We don't need eager loads for this cloned query, they'll
                // remain on the query that actually gets the records.
                // (withoutEagerLoads not available on Laravel 8.)
                ->setEagerLoads([])
                // Where possible, we want to use "select DISTINCT id" .. rather than "group by id"
                // Simply because it outperforms it significantly when dealing with the inner ids select
                ->when($canReplaceGroupByWithDistinct, fn ($builder) => $builder->withoutGroupBy())
                // Here we are using our (nutickets) custom paginate method, because we need the count to be performed
                // as a subquery "select count() from (our query)" rather than "select count from our query" to report
                // the correct counts according to our "select distinct" / "group by" changes made above.
                ->toLengthAwarePaginator(
                    perPage: $perPage,
                    pageName: $pageName,
                    page: $page,
                    countQueryCallback: $countQueryCallback
                );

            // Get the key values from the records on the current page without mutating them.
            $ids = $paginator->getCollection()->map->getRawOriginal($key)->toArray();

            if (count($ids) <= 0) {
                return $paginator;
            }

            if (in_array($model->getKeyType(), ['int', 'integer'])) {
                $this->query->whereIntegerInRaw("$table.$key", $ids);
            } else {
                $this->query->whereIn("$table.$key", $ids);
            }

            // The $paginator is full of records that are primary keys only. Here,
            // we create a new paginator with all of the *stats* from the index-
            // only paginator, but the *items* from the outer query.
            $items = $this->simplePaginate($perPage, $columns, $pageName, 1)->items();

            return Closure::fromCallable($paginatorOutput)->call($this, $items, $paginator);
        };
    }

    /**
     * @return array
     *
     * @throws QueryIncompatibleWithFastPagination
     */
    public static function getInnerSelectColumns($builder)
    {
        $base = $builder->getQuery();
        $model = $builder->newModelInstance();
        $key = $model->getKeyName();
        $table = $model->getTable();

        // Collect all of the `orders` off of the base query and pluck
        // the column out. Based on what orders are present, we may
        // have to include certain columns in the inner query.
        $orders = collect($base->orders)
            ->pluck('column')
            ->filter()
            ->map(function ($column) use ($base) {
                // Not everyone quotes their custom selects, which
                // is totally reasonable. We'll look for both
                // quoted and unquoted, as a kindness.
                // See https://github.com/hammerstonedev/fast-paginate/pull/57
                $column = $column instanceof Expression ? $column->getValue($base->grammar) : $column;

                return [
                    $column,
                    $base->grammar->wrap($column),
                ];
            })
            ->flatten(1);

        $havings = collect($base->havings)
            ->pluck('column')
            ->map(function ($column) use ($base) {
                $column = $column instanceof Expression ? $column->getValue($base->grammar) : $column;

                // Use the grammar to wrap them, so that our `str_contains`
                // (further down) doesn't return any false positives.
                return [
                    $column,
                    $base->grammar->wrap($column),
                ];
            })
            ->flatten(1);

        // IF we are grouping by $table.$key exclusively, then remove the group by and use DISTINCT instead.
        // This yields us better performance for the same results on the inner select that plucks the ids.
        if ($builder->canReplaceGroupByWithDistinct()) {
            $primaryKeySelect = DB::raw("DISTINCT $table.$key");
        } else {
            $primaryKeySelect = "$table.$key";
        }

        return collect($base->columns)
            ->filter(function ($column) use ($orders, $base, $havings) {
                $column = $column instanceof Expression ? $column->getValue($base->grammar) : $base->grammar->wrap($column);
                foreach ($orders as $order) {
                    // If we're ordering by this column, then we need to
                    // keep it in the inner query.
                    if (str_contains($column, "as $order")) {
                        return true;
                    }
                }

                foreach ($havings as $having) {
                    // If we're using this column in the having, then
                    // we need to keep it in the inner query.
                    if (str_contains($column, "as $having")) {
                        return true;
                    }
                }

                // Otherwise we don't.
                return false;
            })
            ->each(function ($column) use ($base) {
                if ($column instanceof Expression) {
                    $column = $column->getValue($base->grammar);
                }

                if (str_contains($column, '?')) {
                    throw new QueryIncompatibleWithFastPagination;
                }
            })
            ->prepend($primaryKeySelect)
            ->unique()
            ->values()
            ->toArray();
    }
}
