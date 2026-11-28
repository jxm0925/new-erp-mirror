<?php

namespace App\Services\Erp;

/** Builds category facts once; descendant lists are slices of the DFS interval. */
final class CategoryTreeEngine
{
    private array $rows = [];
    private array $parents = [];
    private array $children = [];
    private array $order = [];
    private array $ranges = [];

    public function __construct(iterable $categories)
    {
        foreach ($categories as $category) {
            $row = is_array($category) ? $category : $category->toArray();
            $this->rows[(int) $row['id']] = $row;
        }
        foreach ($this->rows as $id => $row) {
            $parentId = (int) ($row['parent_id'] ?? 0);
            $parent = $this->rows[$parentId] ?? null;
            $sameDomain = $parent && ($parent['category_type'] ?? null) === ($row['category_type'] ?? null);
            $sameScope = ($row['category_type'] ?? null) !== 'item'
                || ($parent['management_scope'] ?? 'factory') === ($row['management_scope'] ?? 'factory');
            $this->parents[$id] = $sameDomain && $sameScope && $parentId !== $id ? $parentId : 0;
        }
        // Damaged historical cycles must not hang a read. Writes reject cycles before saving.
        $visited = [];
        foreach (array_keys($this->rows) as $id) {
            $path = [];
            $cursor = $id;
            while ($cursor && !isset($visited[$cursor])) {
                $visited[$cursor] = 1;
                $path[] = $cursor;
                $parentId = $this->parents[$cursor];
                if ($parentId && ($visited[$parentId] ?? 0) === 1) {
                    $this->parents[$cursor] = 0;
                    break;
                }
                $cursor = $parentId;
            }
            foreach ($path as $nodeId) $visited[$nodeId] = 2;
        }
        foreach ($this->parents as $id => $parentId) $this->children[$parentId][] = $id;
        foreach ($this->children[0] ?? [] as $id) $this->visit($id, '/', '', 0);
        foreach (array_reverse($this->order) as $id) {
            $parentId = $this->parents[$id];
            if ($parentId) $this->rows[$parentId]['subtree_item_count'] += $this->rows[$id]['subtree_item_count'];
        }
    }

    private function visit(int $id, string $path, string $fullPath, int $depth): void
    {
        $row = &$this->rows[$id];
        $row['tree_path'] = $path.$id.'/';
        $row['depth'] = $depth;
        $row['full_path'] = $fullPath === '' ? (string) ($row['category_name'] ?? '') : $fullPath.' / '.($row['category_name'] ?? '');
        $row['is_leaf'] = empty($this->children[$id]);
        $row['selectable'] = ($row['status'] ?? null) === 'enabled' && $row['is_leaf'];
        $row['subtree_item_count'] = (int) ($row['direct_item_count'] ?? 0);
        $start = count($this->order);
        $this->order[] = $id;
        foreach ($this->children[$id] ?? [] as $childId) $this->visit($childId, $row['tree_path'], $row['full_path'], $depth + 1);
        $this->ranges[$id] = [$start + 1, count($this->order) - $start - 1];
    }

    public function descendantIds(int $id): array
    {
        return isset($this->ranges[$id]) ? array_slice($this->order, ...$this->ranges[$id]) : [];
    }

    public function containsDescendant(int $id, int $candidateId): bool
    {
        if (!isset($this->ranges[$id], $this->ranges[$candidateId])) return false;
        [$start, $count] = $this->ranges[$id];
        $candidatePosition = $this->ranges[$candidateId][0] - 1;
        return $candidatePosition >= $start && $candidatePosition < $start + $count;
    }

    public function node(int $id): ?array
    {
        return isset($this->rows[$id]) ? [...$this->rows[$id], 'descendant_ids' => $this->descendantIds($id)] : null;
    }

    public function tree(?array $visibleIds = null): array
    {
        $visible = array_fill_keys($visibleIds ?? array_keys($this->rows), true);
        $children = [];
        foreach ($this->order as $id) {
            if (isset($visible[$id])) $children[isset($visible[$this->parents[$id]]) ? $this->parents[$id] : 0][] = $id;
        }
        $build = function (int $id) use (&$build, $children): array {
            return [...$this->node($id), 'children' => array_map($build, $children[$id] ?? [])];
        };
        return array_map($build, $children[0] ?? []);
    }
}
