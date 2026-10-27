<?php
// CLI fixture bridge: never serve this directory, and never use a live database for tests.
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('COMPANY_TEST_DATABASE') !== 'YES') {
    exit(1);
}
define('COMPANY_SETUP_CONTEXT', true);
require __DIR__ . '/../config/connection.php';
$name = $conn->query('SELECT DATABASE()')->fetch_row()[0];
if (!is_string($name) || !str_ends_with($name, '_test')) {
    throw new RuntimeException('The fixture database name must end in _test.');
}
$request = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
function execute_fixture(mysqli $conn, array $request): array
{
    // MySQL does not support CREATE TRIGGER through the prepared-statement protocol.
    // This bridge accepts only trusted CLI fixture definitions, never browser input.
    if (empty($request['params'])) {
        $result = $conn->query($request['sql']);
        $response = ['rows' => $result instanceof mysqli_result ? $result->fetch_all(MYSQLI_ASSOC) : [],
                     'affected' => $conn->affected_rows, 'id' => $conn->insert_id];
        while ($conn->more_results() && $conn->next_result()) {
            $extra = $conn->store_result();
            if ($extra instanceof mysqli_result) $extra->free();
        }
        return $response;
    }
    $stmt = $conn->prepare($request['sql']);
    if (!empty($request['params'])) {
        $params = $request['params'];
        $types = implode('', array_map(static fn($value) => is_int($value) ? 'i' : 's', $params));
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $response = ['rows' => $result ? $result->fetch_all(MYSQLI_ASSOC) : [], 'affected' => $stmt->affected_rows, 'id' => $conn->insert_id];
    while ($stmt->more_results()) {
        $stmt->next_result();
        $extra = $stmt->get_result();
        if ($extra) $extra->free();
    }
    $stmt->close();
    return $response;
}
if (isset($request['hash'])) {
    echo json_encode(['hash' => password_hash($request['hash'], PASSWORD_DEFAULT)], JSON_THROW_ON_ERROR);
} elseif (isset($request['transaction'])) {
    $conn->begin_transaction();
    try {
        $results = [];
        foreach ($request['transaction'] as $operation) $results[] = execute_fixture($conn, $operation);
        $conn->rollback(); // Fixture assertions here must never commit a destructive database-only probe.
        echo json_encode($results, JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
} else {
    echo json_encode(execute_fixture($conn, $request), JSON_THROW_ON_ERROR);
}
