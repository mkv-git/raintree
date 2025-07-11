<?php
declare(strict_types=1);

namespace DBH;

use \PDO;

function getDSN(): string {
  return sprintf('mysql:haost=%s;dbname=%s', getenv('RAINTREE_DB_HOST'), getenv('RAINTREE_DB_NAME'));
}

$options = array(
    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
    \PDO::ATTR_EMULATE_PREPARES => false,
    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
);

$db_connection = new \PDO(getDSN(), getenv('RAINTREE_DB_USERNAME'), getenv('RAINTREE_DB_PASSWORD'), $options);
