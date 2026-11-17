<?php

declare(strict_types=1);

namespace App\Store;

use App\Core\Request;

/**
 * Parses DataTables server-side request params.
 */
final class DataTableRequest
{
    public int $draw;
    public int $start;
    public int $length;
    public string $search;
    public int $orderColumn;
    public string $orderDir;
    /** @var array<string, string> */
    public array $filters;

    public function __construct(Request $request)
    {
        $this->draw = max(0, (int) $request->get('draw', 1));
        $this->start = max(0, (int) $request->get('start', 0));
        $length = (int) $request->get('length', 25);
        $this->length = $length === -1 ? 500 : max(1, min(200, $length));
        $search = $request->get('search', []);
        $this->search = is_array($search)
            ? trim((string) ($search['value'] ?? ''))
            : trim((string) $request->get('q', ''));

        $order = $request->get('order', []);
        if (is_array($order) && isset($order[0]) && is_array($order[0])) {
            $this->orderColumn = (int) ($order[0]['column'] ?? 0);
            $dir = strtolower((string) ($order[0]['dir'] ?? 'desc'));
            $this->orderDir = $dir === 'asc' ? 'ASC' : 'DESC';
        } else {
            $this->orderColumn = 0;
            $this->orderDir = 'DESC';
        }

        $this->filters = [
            'view'         => (string) $request->get('view', 'all'),
            'status'       => (string) $request->get('status', 'open'),
            'type'         => (string) $request->get('type', ''),
            'select'       => (string) $request->get('select', ''),
            'fulfillment'  => (string) $request->get('fulfillment', ''),
            'layout'       => (string) $request->get('layout', ''),
        ];
    }
}
