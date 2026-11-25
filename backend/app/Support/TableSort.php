<?php

namespace App\Support;

class TableSort
{
    /** Only trusted columns/callbacks in this allowlist can reach ORDER BY. */
    public static function apply($query, array $columns, string $default, string $direction = 'asc', string $prefix = '')
    {
        $requested = request()->query($prefix.'sort');
        $key = is_string($requested) && array_key_exists($requested, $columns) ? $requested : $default;
        $requestedDirection = request()->query($prefix.'direction');
        $direction = in_array($requestedDirection, ['asc', 'desc'], true) ? $requestedDirection : $direction;
        $column = $columns[$key];
        $query->reorder();
        if (is_callable($column)) {
            $column($query, $direction);
        } else {
            $query->orderBy($column, $direction);
        }

        return $query;
    }
}
