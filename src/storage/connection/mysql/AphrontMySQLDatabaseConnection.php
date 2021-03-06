<?php

/*
 * Copyright 2011 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * @group storage
 */
class AphrontMySQLDatabaseConnection extends AphrontDatabaseConnection {

  private $config;
  private $connection;

  private static $connectionCache = array();

  public function __construct(array $configuration) {
    $this->configuration  = $configuration;
  }

  public function escapeString($string) {
    $this->requireConnection();
    return mysql_real_escape_string($string, $this->connection);
  }

  public function escapeColumnName($name) {
    return '`'.str_replace('`', '\\`', $name).'`';
  }

  public function escapeMultilineComment($comment) {
    // These can either terminate a comment, confuse the hell out of the parser,
    // make MySQL execute the comment as a query, or, in the case of semicolon,
    // are quasi-dangerous because the semicolon could turn a broken query into
    // a working query plus an ignored query.

    static $map = array(
      '--'  => '(DOUBLEDASH)',
      '*/'  => '(STARSLASH)',
      '//'  => '(SLASHSLASH)',
      '#'   => '(HASH)',
      '!'   => '(BANG)',
      ';'   => '(SEMICOLON)',
    );

    $comment = str_replace(
      array_keys($map),
      array_values($map),
      $comment);

    // For good measure, kill anything else that isn't a nice printable
    // character.
    $comment = preg_replace('/[^\x20-\x7F]+/', ' ', $comment);

    return '/* '.$comment.' */';
  }

  public function escapeStringForLikeClause($value) {
    $value = $this->escapeString($value);
    // Ideally the query shouldn't be modified after safely escaping it,
    // but we need to escape _ and % within LIKE terms.
    $value = str_replace(
      // Even though we've already escaped, we need to replace \ with \\
      // because MYSQL unescapes twice inside a LIKE clause. See note
      // at mysql.com. However, if the \ is being used to escape a single
      // quote ('), then the \ should not be escaped. Thus, after all \
      // are replaced with \\, we need to revert instances of \\' back to
      // \'.
      array('\\',   '\\\\\'', '_',  '%'),
      array('\\\\', '\\\'',   '\_', '\%'),
      $value);
    return $value;
  }

  private function getConfiguration($key, $default = null) {
    return idx($this->configuration, $key, $default);
  }

  private function closeConnection() {
    if ($this->connection) {
      $this->connection = null;
      $key = $this->getConnectionCacheKey();
      unset(self::$connectionCache[$key]);
    }
  }

  private function getConnectionCacheKey() {
    $user = $this->getConfiguration('user');
    $host = $this->getConfiguration('host');
    $database = $this->getConfiguration('database');

    return "{$user}:{$host}:{$database}";
  }


  private function establishConnection() {
    $this->closeConnection();

    $user = $this->getConfiguration('user');
    $host = $this->getConfiguration('host');
    $database = $this->getConfiguration('database');

    $key = $this->getConnectionCacheKey();
    if (isset(self::$connectionCache[$key])) {
      $this->connection = self::$connectionCache[$key];
      return;
    }

    $start = microtime(true);

    if (!function_exists('mysql_connect')) {
      // We have to '@' the actual call since it can spew all sorts of silly
      // noise, but it will also silence fatals caused by not having MySQL
      // installed, which has bitten me on three separate occasions. Make sure
      // such failures are explicit and loud.
      throw new Exception(
        "About to call mysql_connect(), but the PHP MySQL extension is not ".
        "available!");
    }

    $conn = @mysql_connect(
      $host,
      $user,
      $this->getConfiguration('pass'),
      $new_link = true,
      $flags = 0);

    if (!$conn) {
      $errno = mysql_errno();
      $error = mysql_error();
      throw new AphrontQueryConnectionException(
        "Attempt to connect to {$user}@{$host} failed with error #{$errno}: ".
        "{$error}.");
    }

    $ret = @mysql_select_db($database, $conn);
    if (!$ret) {
      $this->throwQueryException($conn);
    }

    $end = microtime(true);

    DarkConsoleServicesPluginAPI::addEvent(
      array(
        'event'     => DarkConsoleServicesPluginAPI::EVENT_CONNECT,
        'host'      => $host,
        'database'  => $database,
        'start'     => $start,
        'end'       => $end,
      ));

    self::$connectionCache[$key] = $conn;
    $this->connection = $conn;
  }

  public function getInsertID() {
    return mysql_insert_id($this->requireConnection());
  }

  public function getAffectedRows() {
    return mysql_affected_rows($this->requireConnection());
  }

  public function getTransactionKey() {
    return (int)$this->requireConnection();
  }

  private function requireConnection() {
    if (!$this->connection) {
      $this->establishConnection();
    }
    return $this->connection;
  }

  public function selectAllResults() {
    $result = array();
    $res = $this->lastResult;
    if ($res == null) {
      throw new Exception('No query result to fetch from!');
    }
    while (($row = mysql_fetch_assoc($res)) !== false) {
      $result[] = $row;
    }
    return $result;
  }

  public function executeRawQuery($raw_query) {
    $this->lastResult = null;
    $retries = 3;
    while ($retries--) {
      try {
        $this->requireConnection();

        $start = microtime(true);
        $result = @mysql_query($raw_query, $this->connection);
        $end = microtime(true);

        DarkConsoleServicesPluginAPI::addEvent(
          array(
            'event' => DarkConsoleServicesPluginAPI::EVENT_QUERY,
            'query' => $raw_query,
            'start' => $start,
            'end'   => $end,
          ));

        if ($result) {
          $this->lastResult = $result;
          break;
        }

        $this->throwQueryException($this->connection);
      } catch (AphrontQueryConnectionLostException $ex) {
        if (!$retries) {
          throw $ex;
        }
        if ($this->isInsideTransaction()) {
          throw $ex;
        }
        $this->closeConnection();
      }
    }
  }

  private function throwQueryException($connection) {
    $errno = mysql_errno($connection);
    $error = mysql_error($connection);

    switch ($errno) {
      case 2013: // Connection Dropped
      case 2006: // Gone Away
        throw new AphrontQueryConnectionLostException("#{$errno}: {$error}");
      case 1213: // Deadlock
      case 1205: // Lock wait timeout exceeded
        throw new AphrontQueryRecoverableException("#{$errno}: {$error}");
      case 1062: // Duplicate Key
        // NOTE: In some versions of MySQL we get a key name back here, but
        // older versions just give us a key index ("key 2") so it's not
        // portable to parse the key out of the error and attach it to the
        // exception.
        throw new AphrontQueryDuplicateKeyException("{$errno}: {$error}");
      default:
        // TODO: 1064 is syntax error, and quite terrible in production.
        throw new AphrontQueryException("#{$errno}: {$error}");
    }
  }

}
