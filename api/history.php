<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Lara\Api;
use Lara\Assistant\Pipeline;
use Lara\Db;

Api::guard('GET');
Api::run(static function (): void {
    $limit = max(1, min(200, (int)($_GET['limit'] ?? 60)));
    $before = (int)($_GET['before_id'] ?? 0);
    $filter = $_GET['filter'] ?? 'all';
    $where = [];
    $params = [];
    if ($before > 0) {
        $where[] = 'id < ?';
        $params[] = $before;
    }
    if ($filter === 'relevant') {
        $where[] = "relevance IN ('addressed', 'relevant')";
    } elseif ($filter !== 'all') {
        throw new InvalidArgumentException('filtro inválido');
    }
    $sql = 'SELECT id FROM messages' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY id DESC LIMIT $limit";
    $ids = array_column(Db::all($sql, $params), 'id');
    Api::ok(['messages' => array_map(static fn($id) => Pipeline::publicMessage((int)$id), array_reverse($ids))]);
});
