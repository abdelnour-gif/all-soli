<?php
/** One "phone": waits for a shared start instant, then joins. Used by the concurrency test. */
require __DIR__ . '/bootstrap.php';

use QueuePro\Support\Actor;
use QueuePro\Support\QueueException;

[$_, $providerId, $phone, $startAt] = $argv;
$c = qp_test_container();
while (microtime(true) < (float) $startAt) {
    usleep(500);
}
try {
    $r = $c->queue->join((int) $providerId, ['phone' => $phone], Actor::customer(null));
    echo json_encode(['ok' => true, 'number' => $r['ticket']['number'], 'token' => $r['token']]);
} catch (QueueException $e) {
    echo json_encode(['ok' => false, 'error' => $e->errorCode()]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'EXCEPTION: ' . $e->getMessage()]);
}
