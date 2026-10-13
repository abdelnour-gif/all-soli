<?php
/**
 * Test bootstrap — runs the real engine against a real database, no web server.
 *   QP_TEST_DRIVER=sqlite (default, temp file)  |  mysql (QP_DB_HOST/USER/PASS/NAME)
 */
define('QP_APP', true);
define('QP_TESTING', true);
require __DIR__ . '/../app/autoload.php';

use QueuePro\Container;
use QueuePro\Database\Connection;
use QueuePro\Support\Security;

Security::setSecret('test-secret');

function qp_test_config(): array
{
    if ((getenv('QP_TEST_DRIVER') ?: 'sqlite') === 'mysql') {
        return ['driver' => 'mysql', 'host' => getenv('QP_DB_HOST') ?: '127.0.0.1', 'user' => getenv('QP_DB_USER') ?: 'qp',
                'pass' => getenv('QP_DB_PASS') ?: 'qp', 'name' => getenv('QP_DB_NAME') ?: 'qp_test'];
    }
    return ['driver' => 'sqlite', 'path' => getenv('QP_TEST_SQLITE') ?: sys_get_temp_dir() . '/qp_acceptance.sqlite'];
}

function qp_test_container(): Container
{
    return new Container(new Connection(qp_test_config()));
}
