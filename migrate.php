<?php
/**
 * Tiny migrate script for PHP and MySQL.
 *
 * Copyright 2012 Alex Kennberg (https://github.com/kennberg/php-mysql-migrate)
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

require_once('config.php');

/*
 * HTTP ACCESS for migrate
 * with access token set in config
 */
if(isset($_GET['arg1'])){
  if(isset($_GET['token']) && $_GET['token'] == HTTP_TOKEN) {
    $argv[0] = 'migrate.php';
    $argv[1] = $_GET['arg1'];
  } else {
    exit("Invalid Access token");
  }
}

/**
 * Initialize your database parameters:
 *    cp config.php.sample config.php
 *    vim config.php
 *
 *  The rest is in the usage report.
 */
if (count($argv) <= 1) {
  echo "Usage:
     To add new migration:
         php php-mysql-migrate/migrate.php add [name-without-spaces]
     To migrate your database:
         php php-mysql-migrate/migrate.php migrate
     ";
  exit;
}

@define('MIGRATE_VERSION_FILE', dirname(__FILE__) . '/.version');
@define('MIGRATE_FILE_PREFIX', 'migrate-');
@define('MIGRATE_FILE_POSTFIX', '.php');
@define('DEBUG', false);

if (count($argv) <= 1) {
  echo "See readme file for usage.\n";
  exit;
}

// Connect to the database.
if (!@DEBUG) {
  $link = mysql_connect(DBADDRESS, DBUSERNAME, DBPASSWORD);
  if (!$link) {
    echo "Failed to connect to the database.\n";
    exit;
  }
  mysql_select_db(DBNAME, $link);
  mysql_query("SET NAMES 'utf8'", $link);
}

// Find the latest version or start at 0.
$version = 0;
$f = @fopen(MIGRATE_VERSION_FILE, 'r');
if ($f) {
  $version = intval(fgets($f));
  fclose($f);
}
echo "Current database version is: $version\n";

global $link;
function query($query) {
  global $link;

  if (@DEBUG)
    return true;

  $result = mysql_query($query, $link);
  if (!$result) {
    echo "Migration failed: " . mysql_error($link) . "\n";
    echo "Aborting.\n";
    mysql_query('ROLLBACK', $link);
    mysql_close($link);
    exit;
  }
  return $result;
}

function get_migrations() {
  // Find all the migration files in the directory and return the sorted.
  $files = array();
  $dir = opendir(MIGRATIONS_DIR);
  while ($file = readdir($dir)) {
    if (substr($file, 0, strlen(MIGRATE_FILE_PREFIX)) == MIGRATE_FILE_PREFIX)
      $files[] = $file;
  }
  asort($files);
  return $files;
}

function get_version_from_file($file) {
  return intval(substr($file, strlen(MIGRATE_FILE_PREFIX)));
}

if ($argv[1] == 'add') {
  $new_version = $version;

  // Check the new version against existing migrations.
  $last_file = end(get_migrations());
  if ($last_file !== false) {
    $file_version = get_version_from_file($last_file);
    if ($file_version > $new_version)
      $new_version = $file_version;
  }

  // Create migration file path.
  $new_version++;
  $path = MIGRATIONS_DIR . MIGRATE_FILE_PREFIX . sprintf('%04d', $new_version);
  if (@strlen($argv[2]))
    $path .= '-' . $argv[2];
  $path .= MIGRATE_FILE_POSTFIX;

  echo "Adding a new migration script: $path\n";

  $f = @fopen($path, 'w');
  if ($f) {
    fputs($f, "<?php\n\nquery(\$query);\n\n");
    fclose($f);
    echo "Done.\n";
  }
  else {
    echo "Failed.\n";
  }
}
else if ($argv[1] == 'migrate') {
  // Run all the new files.
  $files = get_migrations();
  $found_new = false;
  foreach ($files as $file) {
    $file_version = get_version_from_file($file);
    if ($file_version <= $version)
      continue;

    echo "Running: $file\n";
    query('BEGIN');
    include(MIGRATIONS_DIR . $file);
    query('COMMIT');
    echo "Done.\n";

    $version = $file_version;
    $found_new = true;

    // Output the new version number.
    $f = @fopen(MIGRATE_VERSION_FILE, 'w');
    if ($f) {
      fputs($f, $version);
      fclose($f);
    }
    else {
      echo "Failed to output new version to " . MIGRATION_VERSION_FILE . "\n";
    }
  }

  if ($found_new)
    echo "Migration complete.\n";
  else
    echo "Your database is up-to-date.\n";
}

if (!@DEBUG) {
  mysql_close($link);
}

