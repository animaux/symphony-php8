<?php

/**
 * @package toolkit
 */

/**
 * The DatabaseException class extends a normal Exception to add in
 * debugging information when a SQL query fails such as the internal
 * database error code and message in additional to the usual
 * Exception information. It allows a DatabaseException to contain a human
 * readable error, as well more technical information for debugging.
 */
class DatabaseException extends Exception
{
    /**
     * An associative array with three keys, 'query', 'msg' and 'num'
     * @var array
     */
    private $_error = array();

    /**
     * Constructor takes a message and an associative array to set to
     * `$_error`. The message is passed to the default Exception constructor
     */
    public function __construct($message, array $error = null)
    {
        parent::__construct($message);
        $this->_error = $error;
    }

    /**
     * Accessor function for the original query that caused this Exception
     *
     * @return string
     */
    public function getQuery()
    {
        return $this->_error['query'];
    }

    /**
     * Accessor function for the Database error code for this type of error
     *
     * @return integer
     */
    public function getDatabaseErrorCode()
    {
        return $this->_error['num'];
    }

    /**
     * Accessor function for the Database message from this Exception
     *
     * @return string
     */
    public function getDatabaseErrorMessage()
    {
        return $this->_error['msg'];
    }
}

/**
 * The MySQL class acts as a wrapper for connecting to the Database
 * in Symphony. It utilises mysqli_* functions in PHP to complete the usual
 * querying. As well as the normal set of insert, update, delete and query
 * functions, some convenience functions are provided to return results
 * in different ways. Symphony uses a prefix to namespace it's tables in a
 * database, allowing it play nice with other applications installed on the
 * database. An errors that occur during a query throw a `DatabaseException`.
 * By default, Symphony logs all queries to be used for Profiling and Debug
 * devkit extensions when a Developer is logged in. When a developer is not
 * logged in, all queries and errors are made available with delegates.
 */
class MySQL
{
    /**
     * Constant to indicate whether the query is a write operation.
     *
     * @var integer
     */
    const __WRITE_OPERATION__ = 0;

    /**
     * Constant to indicate whether the query is a write operation
     *
     * @var integer
     */
    const __READ_OPERATION__ = 1;

    /**
     * Sets the current `$_log` to be an empty array
     *
     * @var array
     */
    private static $_log = array();

    /**
     * The number of queries this class has executed, defaults to 0.
     *
     * @var integer
     */
    private static $_query_count = 0;

    /**
     * Whether query caching is enabled or not. By default this is set
     * to true which will use SQL_CACHE to cache the results of queries
     *
     * @var boolean
     */
    private static $_cache = true;

    /**
     * Whether query logging is enabled or not. By default this is set
     * to true, which allows profiling of queries
     *
     * @var boolean
     */
    private static $_logging = true;

    /**
     * An associative array of connection properties for this MySQL
     * database including the host, port, username, password and
     * selected database.
     *
     * @var array
     */
    private static $_connection = array();

    /**
     * The resource of the last result returned from mysqli_query
     *
     * @var resource
     */
    private $_result = null;

    /**
     * The last query that was executed by the class
     */
    private $_lastQuery = null;

    /**
     * The hash value of the last query that was executed by the class
     */
    private $_lastQueryHash = null;

    /**
     * The auto increment value returned by the last query that was executed
     * by the class
     */
    private $_lastInsertID = null;

    /**
     * By default, an array of arrays or objects representing the result set
     * from the `$this->_lastQuery`
     */
    private $_lastResult = array();

    /**
     * Magic function that will flush the MySQL log and close the MySQL
     * connection when the MySQL class is removed or destroyed.
     *
     * @link http://php.net/manual/en/language.oop5.decon.php
     */
    public function __destruct()
    {
        $this->flush();
        $this->close();
    }

    /**
     * Resets the result, `$this->_lastResult` and `$this->_lastQuery` to their empty
     * values. Called on each query and when the class is destroyed.
     */
    public function flush()
    {
        $this->_result = null;
        $this->_lastResult = array();
        $this->_lastQuery = null;
        $this->_lastQueryHash = null;
    }

    /**
     * Sets the current `$_log` to be an empty array
     */
    public static function flushLog()
    {
        self::$_log = array();
    }

    /**
     * Returns the number of queries that has been executed
     *
     * @return integer
     */
    public static function queryCount()
    {
        return self::$_query_count;
    }

    /**
     * Sets query caching to true, this will prepend all READ_OPERATION
     * queries with SQL_CACHE. Symphony by default enables caching. It
     * can be turned off by setting the `query_cache` parameter to `off` in the
     * Symphony config file.
     *
     * @link http://dev.mysql.com/doc/refman/5.1/en/query-cache.html
     */
    public static function enableCaching()
    {
        self::$_cache = true;
    }

    /**
     * Sets query caching to false, this will prepend all READ_OPERATION
     * queries will SQL_NO_CACHE.
     */
    public static function disableCaching()
    {
        self::$_cache = false;
    }

    /**
     * Returns boolean if query caching is enabled or not
     *
     * @return boolean
     */
    public static function isCachingEnabled()
    {
        return self::$_cache;
    }

    /**
     * Enables query logging and profiling.
     *
     * @since Symphony 2.6.2
     */
    public static function enableLogging()
    {
        self::$_logging = true;
    }

    /**
     * Disables query logging and profiling. Use this in low memory environments
     * to reduce memory usage.
     *
     * @since Symphony 2.6.2
     * @link https://github.com/symphonycms/symphony-2/issues/2398
     */
    public static function disableLogging()
    {
        self::$_logging = false;
    }

    /**
     * Returns boolean if logging is enabled or not
     *
     * @since Symphony 2.6.2
     * @return boolean
     */
    public static function isLoggingEnabled()
    {
        return self::$_logging;
    }

    /**
     * Symphony uses a prefix for all it's database tables so it can live peacefully
     * on the same database as other applications. By default this is `sym_`, but it
     * can be changed when Symphony is installed.
     *
     * @param string $prefix
     *  The table prefix for Symphony, by default this is `sym_`
     */
    public function setPrefix($prefix)
    {
        self::$_connection['tbl_prefix'] = $prefix;
    }

    /**
     * Returns the prefix used by Symphony for this Database instance.
     *
     * @since Symphony 2.4
     * @return string
     */
    public function getPrefix()
    {
        return self::$_connection['tbl_prefix'];
    }

    /**
     * Determines if a connection has been made to the MySQL server
     *
     * @return boolean
     */
    public static function isConnected()
    {
        try {
            $connected = (
                isset(self::$_connection['id'])
                && !is_null(self::$_connection['id'])
            );
        } catch (Exception $ex) {
            return false;
        }

        return $connected;
    }

    /**
     * Called when the script has finished executing, this closes the MySQL
     * connection
     *
     * @return boolean
     */
    public function close()
    {
        if ($this->isConnected()) {
            return mysqli_close(self::$_connection['id']);
        }
    }

    /**
     * Creates a connect to the database server given the credentials. If an
     * error occurs, a `DatabaseException` is thrown, otherwise true is returned
     *
     * @param string $host
     *  Defaults to null, which MySQL assumes as localhost.
     * @param string $user
     *  Defaults to null
     * @param string $password
     *  Defaults to null
     * @param string $port
     *  Defaults to 3306.
     * @param null $database
     * @throws DatabaseException
     * @return boolean
     */
    public function connect($host = null, $user = null, $password = null, $port = '3306', $database = null)
    {
        self::$_connection = array(
            'host' => $host,
            'user' => $user,
            'pass' => $password,
            'port' => $port,
            'database' => $database
        );

        try {
            self::$_connection['id'] = mysqli_connect(
                self::$_connection['host'],
                self::$_connection['user'],
                self::$_connection['pass'],
                self::$_connection['database'],
                self::$_connection['port']
            );

            if (!$this->isConnected()) {
                $this->__error('connect');
            }
        } catch (Exception $ex) {
            $this->__error('connect');
        }

        return true;
    }

    /**
     * Accessor for the current MySQL resource from PHP. May be
     * useful for developers who want complete control over their
     * database queries and don't want anything abstract by the MySQL
     * class.
     *
     * @return resource
     */
    public static function getConnectionResource()
    {
        return self::$_connection['id'];
    }

    /**
     * This will set the character encoding of the connection for sending and
     * receiving data. This function will run every time the database class
     * is being initialized. If no character encoding is provided, UTF-8
     * is assumed.
     *
     * @link http://au2.php.net/manual/en/function.mysql-set-charset.php
     * @param string $set
     *  The character encoding to use, by default this 'utf8'
     */
    public function setCharacterEncoding($set = 'utf8')
    {
        mysqli_set_charset(self::$_connection['id'], $set);
    }

    /**
     * This function will set the character encoding of the database so that any
     * new tables that are created by Symphony use this character encoding
     *
     * @link http://dev.mysql.com/doc/refman/5.0/en/charset-connection.html
     * @param string $set
     *  The character encoding to use, by default this 'utf8'
     * @throws DatabaseException
     */
    public function setCharacterSet($set = 'utf8')
    {
        $this->query("SET character_set_connection = '$set', character_set_server = '$set'");
        $this->query("SET CHARACTER SET '$set'");
    }

    /**
     * This function will set the SQL mode of the database so that Symphony
     * is able to run on never versions of MySQL which have set
     * ONLY_FULL_GROUP_BY by default
     *
     * @link https://dev.mysql.com/doc/refman/8.0/en/sql-mode.html
     * @throws DatabaseException
     */
    public function setSQLMode()
    {
        $this->query("SET SESSION sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''));");
    }

    /**
     * Sets the MySQL connection to use this timezone instead of the default
     * MySQL server timezone.
     *
     * @throws DatabaseException
     * @link https://dev.mysql.com/doc/refman/5.6/en/time-zone-support.html
     * @link https://github.com/symphonycms/symphony-2/issues/1726
     * @since Symphony 2.3.3
     * @param string $timezone
     *  Timezone will human readable, such as Australia/Brisbane.
     */
    public function setTimeZone($timezone = null)
    {
        if (is_null($timezone)) {
            return;
        }

        // What is the time now in the install timezone
        $symphony_date = new DateTime('now', new DateTimeZone($timezone));

        // MySQL wants the offset to be in the format +/-H:I, getOffset returns offset in seconds
        $utc = new DateTime('now ' . $symphony_date->getOffset() . ' seconds', new DateTimeZone("UTC"));

        // Get the difference between the symphony install timezone and UTC
        $offset = $symphony_date->diff($utc)->format('%R%H:%I');

        $this->query("SET time_zone = '$offset'");
    }

    /**
     * This function will clean a string using the `mysqli_real_escape_string` function
     * taking into account the current database character encoding. Note that this
     * function does not encode _ or %. If `mysqli_real_escape_string` doesn't exist,
     * `addslashes` will be used as a backup option
     *
     * @param string $value
     *  The string to be encoded into an escaped SQL string
     * @return string
     *  The escaped SQL string
     */
    public static function cleanValue($value)
    {
        if (function_exists('mysqli_real_escape_string') && self::isConnected()) {
            return mysqli_real_escape_string(self::$_connection['id'], $value);
        } else {
            return addslashes($value);
        }
    }

    /**
     * This function will apply the `cleanValue` function to an associative
     * array of data, encoding only the value, not the key. This function
     * can handle recursive arrays. This function manipulates the given
     * parameter by reference.
     *
     * @see cleanValue
     * @param array $array
     *  The associative array of data to encode, this parameter is manipulated
     *  by reference.
     */
    public static function cleanFields(array &$array)
    {
        foreach ($array as $key => $val) {

            // Handle arrays with more than 1 level
            if (is_array($val)) {
                self::cleanFields($val);
                continue;
            } elseif (strlen($val) == 0) {
                $array[$key] = 'null';
            } else {
                $array[$key] = "'" . self::cleanValue($val) . "'";
            }
        }
    }

    /**
     * Determines whether this query is a read operation, or if it is a write operation.
     * A write operation is determined as any query that starts with CREATE, INSERT,
     * REPLACE, ALTER, DELETE, UPDATE, OPTIMIZE, TRUNCATE or DROP. Anything else is
     * considered to be a read operation which are subject to query caching.
     *
     * @param string $query
     * @return integer
     *  `self::__WRITE_OPERATION__` or `self::__READ_OPERATION__`
     */
    public function determineQueryType($query)
    {
        return (preg_match('/^(create|insert|replace|alter|delete|update|optimize|truncate|drop)/i', $query) ? self::__WRITE_OPERATION__ : self::__READ_OPERATION__);
    }

    /**
     * Takes an SQL string and executes it. This function will apply query
     * caching if it is a read operation and if query caching is set. Symphony
     * will convert the `tbl_` prefix of tables to be the one set during installation.
     * A type parameter is provided to specify whether `$this->_lastResult` will be an array
     * of objects or an array of associative arrays. The default is objects. This
     * function will return boolean, but set `$this->_lastResult` to the result.
     *
     * @uses PostQueryExecution
     * @param string $query
     *  The full SQL query to execute.
     * @param string $type
     *  Whether to return the result as objects or associative array. Defaults
     *  to OBJECT which will return objects. The other option is ASSOC. If $type
     *  is not either of these, it will return objects.
     * @throws DatabaseException
     * @return boolean
     *  true if the query executed without errors, false otherwise
     */
    public function query($query, $type = "OBJECT")
    {
        if (empty($query) || self::isConnected() === false) {
            return false;
        }

        $start = precision_timer();
        $query = trim($query);
        $query_type = $this->determineQueryType($query);
        $query_hash = md5($query.$start);
        self::$_connection['tbl_prefix'] = self::$_connection['tbl_prefix'] ?? null;
        
        if (self::$_connection['tbl_prefix'] !== 'tbl_') {
            $query = preg_replace('/tbl_(\S+?)([\s\.,]|$)/', self::$_connection['tbl_prefix'].'\\1\\2', $query);
        }

        // TYPE is deprecated since MySQL 4.0.18, ENGINE is preferred
        if ($query_type == self::__WRITE_OPERATION__) {
            $query = preg_replace('/TYPE=(MyISAM|InnoDB)/i', 'ENGINE=$1', $query);
        } elseif ($query_type == self::__READ_OPERATION__ && !preg_match('/^SELECT\s+SQL(_NO)?_CACHE/i', $query)) {
            if ($this->isCachingEnabled()) {
                //$query = preg_replace('/^SELECT\s+/i', 'SELECT SQL_CACHE ', $query);
                $query = preg_replace('/^SELECT\s+/i', 'SELECT ', $query);
            } else {
                $query = preg_replace('/^SELECT\s+/i', 'SELECT SQL_NO_CACHE ', $query);
            }
        }

        $this->flush();
        $this->_lastQuery = $query;
        $this->_lastQueryHash = $query_hash;
        $this->_result = mysqli_query(self::$_connection['id'], $query);
        $this->_lastInsertID = mysqli_insert_id(self::$_connection['id']);
        self::$_query_count++;

        if (mysqli_error(self::$_connection['id'])) {
            $this->__error();
        } elseif (($this->_result instanceof mysqli_result)) {
            if ($type == "ASSOC") {
                while ($row = mysqli_fetch_assoc($this->_result)) {
                    $this->_lastResult[] = $row;
                }
            } else {
                while ($row = mysqli_fetch_object($this->_result)) {
                    $this->_lastResult[] = $row;
                }
            }

            mysqli_free_result($this->_result);
        }

        $stop = precision_timer('stop', $start);

        /**
         * After a query has successfully executed, that is it was considered
         * valid SQL, this delegate will provide the query, the query_hash and
         * the execution time of the query.
         *
         * Note that this function only starts logging once the ExtensionManager
         * is available, which means it will not fire for the first couple of
         * queries that set the character set.
         *
         * @since Symphony 2.3
         * @delegate PostQueryExecution
         * @param string $context
         * '/frontend/' or '/backend/'
         * @param string $query
         *  The query that has just been executed
         * @param string $query_hash
         *  The hash used by Symphony to uniquely identify this query
         * @param float $execution_time
         *  The time that it took to run `$query`
         */
        if (self::$_logging === true) {
            if (Symphony::ExtensionManager() instanceof ExtensionManager) {
                Symphony::ExtensionManager()->notifyMembers('PostQueryExecution', class_exists('Administration', false) ? '/backend/' : '/frontend/', array(
                    'query' => $query,
                    'query_hash' => $query_hash,
                    'execution_time' => $stop
                ));

                // If the ExceptionHandler is enabled, then the user is authenticated
                // or we have a serious issue, so log the query.
                if (GenericExceptionHandler::$enabled) {
                    self::$_log[$query_hash] = array(
                        'query' => $query,
                        'query_hash' => $query_hash,
                        'execution_time' => $stop
                    );
                }

                // Symphony isn't ready yet. Log internally
            } else {
                self::$_log[$query_hash] = array(
                    'query' => $query,
                    'query_hash' => $query_hash,
                    'execution_time' => $stop
                );
            }
        }

        return true;
    }

    /**
     * Returns the last insert ID from the previous query. This is
     * the value from an auto_increment field.
     *
     * @return integer
     *  The last interested row's ID
     */
    public function getInsertID()
    {
        return $this->_lastInsertID;
    }

    /**
     * A convenience method to insert data into the Database. This function
     * takes an associative array of data to input, with the keys being the column
     * names and the table. An optional parameter exposes MySQL's ON DUPLICATE
     * KEY UPDATE functionality, which will update the values if a duplicate key
     * is found.
     *
     * @param array $fields
     *  An associative array of data to input, with the key's mapping to the
     *  column names. Alternatively, an array of associative array's can be
     *  provided, which will perform multiple inserts
     * @param string $table
     *  The table name, including the tbl prefix which will be changed
     *  to this Symphony's table prefix in the query function
     * @param boolean $updateOnDuplicate
     *  If set to true, data will updated if any key constraints are found that cause
     *  conflicts. By default this is set to false, which will not update the data and
     *  would return an SQL error
     * @throws DatabaseException
     * @return boolean
     */
    public function insert(array $fields, $table, $updateOnDuplicate = false)
    {
        // Multiple Insert
        if (is_array(current($fields))) {
            $sql  = "INSERT INTO `$table` (`".implode('`, `', array_keys(current($fields))).'`) VALUES ';
            $rows = array();

            foreach ($fields as $key => $array) {
                // Sanity check: Make sure we dont end up with ',()' in the SQL.
                if (!is_array($array)) {
                    continue;
                }

                self::cleanFields($array);
                $rows[] = '('.implode(', ', $array).')';
            }

            $sql .= implode(", ", $rows);

            // Single Insert
        } else {
            self::cleanFields($fields);
            $sql  = "INSERT INTO `$table` (`".implode('`, `', array_keys($fields)).'`) VALUES ('.implode(', ', $fields).')';

            if ($updateOnDuplicate) {
                $sql .= ' ON DUPLICATE KEY UPDATE ';

                foreach ($fields as $key => $value) {
                    $sql .= " `$key` = $value,";
                }

                $sql = trim($sql, ',');
            }
        }

        return $this->query($sql);
    }

    /**
     * A convenience method to update data that exists in the Database. This function
     * takes an associative array of data to input, with the keys being the column
     * names and the table. A WHERE statement can be provided to select the rows
     * to update
     *
     * @param array $fields
     *  An associative array of data to input, with the key's mapping to the
     *  column names.
     * @param string $table
     *  The table name, including the tbl prefix which will be changed
     *  to this Symphony's table prefix in the query function
     * @param string $where
     *  A WHERE statement for this UPDATE statement, defaults to null
     *  which will update all rows in the $table
     * @throws DatabaseException
     * @return boolean
     */
    public function update($fields, $table, $where = null)
    {
        self::cleanFields($fields);
        $sql = "UPDATE $table SET ";
        $rows = array();

        foreach ($fields as $key => $val) {
            $rows[] = " `$key` = $val";
        }

        $sql .= implode(', ', $rows) . (!is_null($where) ? ' WHERE ' . $where : null);

        return $this->query($sql);
    }

    /**
     * Given a table name and a WHERE statement, delete rows from the
     * Database.
     *
     * @param string $table
     *  The table name, including the tbl prefix which will be changed
     *  to this Symphony's table prefix in the query function
     * @param string $where
     *  A WHERE statement for this DELETE statement, defaults to null,
     *  which will delete all rows in the $table
     * @throws DatabaseException
     * @return boolean
     */
    public function delete($table, $where = null)
    {
        $sql = "DELETE FROM `$table`";

        if (!is_null($where)) {
            $sql .= " WHERE $where";
        }

        return $this->query($sql);
    }

    /**
     * Returns an associative array that contains the results of the
     * given `$query`. Optionally, the resulting array can be indexed
     * by a particular column.
     *
     * @param string $query
     *  The full SQL query to execute. Defaults to null, which will
     *  use the _lastResult
     * @param string $index_by_column
     *  The name of a column in the table to use it's value to index
     *  the result by. If this is omitted (and it is by default), an
     *  array of associative arrays is returned, with the key being the
     *  column names
     * @throws DatabaseException
     * @return array
     *  An associative array with the column names as the keys
     */
    public function fetch($query = null, $index_by_column = null)
    {
        if (!is_null($query)) {
            $this->query($query, "ASSOC");
        } elseif (is_null($this->_lastResult)) {
            return array();
        }

        $result = $this->_lastResult;

        if (!is_null($index_by_column) && isset($result[0][$index_by_column])) {
            $n = array();

            foreach ($result as $ii) {
                $n[$ii[$index_by_column]] = $ii;
            }

            $result = $n;
        }

        return $result;
    }

    /**
     * Returns the row at the specified index from the given query. If no
     * query is given, it will use the `$this->_lastResult`. If no offset is provided,
     * the function will return the first row. This function does not imply any
     * LIMIT to the given `$query`, so for the more efficient use, it is recommended
     * that the `$query` have a LIMIT set.
     *
     * @param integer $offset
     *  The row to return from the SQL query. For instance, if the second
     *  row from the result was required, the offset would be 1, because it
     *  is zero based.
     * @param string $query
     *  The full SQL query to execute. Defaults to null, which will
     *  use the `$this->_lastResult`
     * @throws DatabaseException
     * @return array
     *  If there is no row at the specified `$offset`, an empty array will be returned
     *  otherwise an associative array of that row will be returned.
     */
    public function fetchRow($offset = 0, $query = null)
    {
        $result = $this->fetch($query);
        return (empty($result) ? array() : $result[$offset]);
    }

    /**
     * Returns an array of values for a specified column in a given query.
     * If no query is given, it will use the `$this->_lastResult`.
     *
     * @param string $column
     *  The column name in the query to return the values for
     * @param string $query
     *  The full SQL query to execute. Defaults to null, which will
     *  use the `$this->_lastResult`
     * @throws DatabaseException
     * @return array
     *  If there is no results for the `$query`, an empty array will be returned
     *  otherwise an array of values for that given `$column` will be returned
     */
    public function fetchCol($column, $query = null)
    {
        $result = $this->fetch($query);

        if (empty($result)) {
            return array();
        }

        $rows = array();
        foreach ($result as $row) {
            if (isset($row[$column])) {
                $rows[] = $row[$column];
            }
        }

        return $rows;
    }

    /**
     * Returns the value for a specified column at a specified offset. If no
     * offset is provided, it will return the value for column of the first row.
     * If no query is given, it will use the `$this->_lastResult`.
     *
     * @param string $column
     *  The column name in the query to return the values for
     * @param integer $offset
     *  The row to use to return the value for the given `$column` from the SQL
     *  query. For instance, if `$column` form the second row was required, the
     *  offset would be 1, because it is zero based.
     * @param string $query
     *  The full SQL query to execute. Defaults to null, which will
     *  use the `$this->_lastResult`
     * @throws DatabaseException
     * @return string|null
     *  Returns the value of the given column, if it doesn't exist, null will be
     *  returned
     */
    public function fetchVar($column, $offset = 0, $query = null)
    {
        $result = $this->fetch($query);
        return (empty($result) ? null : $result[$offset][$column]);
    }

    /**
     * This function takes `$table` and `$field` names and returns boolean
     * if the `$table` contains the `$field`.
     *
     * @since Symphony 2.3
     * @link  https://dev.mysql.com/doc/refman/en/describe.html
     * @param string $table
     *  The table name
     * @param string $field
     *  The field name
     * @throws DatabaseException
     * @return boolean
     *  true if `$table` contains `$field`, false otherwise
     */
    public function tableContainsField($table, $field)
    {
        $table = MySQL::cleanValue($table);
        $field = MySQL::cleanValue($field);
        $results = $this->fetch("DESC `{$table}` `{$field}`");
        return (is_array($results) && !empty($results));
    }

    /**
     * This function takes `$table` and returns boolean
     * if it exists or not.
     *
     * @since Symphony 2.3.4
     * @link  https://dev.mysql.com/doc/refman/en/show-tables.html
     * @param string $table
     *  The table name
     * @throws DatabaseException
     * @return boolean
     *  true if `$table` exists, false otherwise
     */
    public function tableExists($table)
    {
        $table = MySQL::cleanValue($table);
        $results = $this->fetch(sprintf("SHOW TABLES LIKE '%s'", $table));
        return (is_array($results) && !empty($results));
    }

    /**
     * If an error occurs in a query, this function is called which logs
     * the last query and the error number and error message from MySQL
     * before throwing a `DatabaseException`
     *
     * @uses QueryExecutionError
     * @throws DatabaseException
     * @param string $type
     *  Accepts one parameter, 'connect', which will return the correct
     *  error codes when the connection sequence fails
     */
    private function __error($type = null)
    {
        if ($type == 'connect') {
            $msg = mysqli_connect_error();
            $errornum = mysqli_connect_errno();
        } else {
            $msg = mysqli_error(self::$_connection['id']);
            $errornum = mysqli_errno(self::$_connection['id']);
        }

        /**
         * After a query execution has failed this delegate will provide the query,
         * query hash, error message and the error number.
         *
         * Note that this function only starts logging once the `ExtensionManager`
         * is available, which means it will not fire for the first couple of
         * queries that set the character set.
         *
         * @since Symphony 2.3
         * @delegate QueryExecutionError
         * @param string $context
         * '/frontend/' or '/backend/'
         * @param string $query
         *  The query that has just been executed
         * @param string $query_hash
         *  The hash used by Symphony to uniquely identify this query
         * @param string $msg
         *  The error message provided by MySQL which includes information on why the execution failed
         * @param integer $num
         *  The error number that corresponds with the MySQL error message
         */
        if (self::$_logging === true) {
            if (Symphony::ExtensionManager() instanceof ExtensionManager) {
                Symphony::ExtensionManager()->notifyMembers('QueryExecutionError', class_exists('Administration', false) ? '/backend/' : '/frontend/', array(
                    'query' => $this->_lastQuery,
                    'query_hash' => $this->_lastQueryHash,
                    'msg' => $msg,
                    'num' => $errornum
                ));
            }
        }

        throw new DatabaseException(__('MySQL Error (%1$s): %2$s in query: %3$s', array($errornum, $msg, $this->_lastQuery)), array(
            'msg' => $msg,
            'num' => $errornum,
            'query' => $this->_lastQuery
        ));
    }

    /**
     * Returns all the log entries by type. There are two valid types,
     * error and debug. If no type is given, the entire log is returned,
     * otherwise only log messages for that type are returned
     *
     * @param null|string $type
     * @return array
     *  An array of associative array's. Log entries of the error type
     *  return the query the error occurred on and the error number and
     *  message from MySQL. Log entries of the debug type return the
     *  the query and the start/stop time to indicate how long it took
     *  to run
     */
    public function debug($type = null)
    {
        if (!$type) {
            return self::$_log;
        }

        return ($type == 'error' ? self::$_log['error'] : self::$_log['query']);
    }

    /**
     * Returns some basic statistics from the MySQL class about the
     * number of queries, the time it took to query and any slow queries.
     * A slow query is defined as one that took longer than 0.0999 seconds
     * This function is used by the Profile devkit
     *
     * @return array
     *  An associative array with the number of queries, an array of slow
     *  queries and the total query time.
     */
    public function getStatistics()
    {
        $query_timer = 0.0;
        $slow_queries = array();

        foreach (self::$_log as $key => $val) {
            $query_timer += $val['execution_time'];
            if ($val['execution_time'] > 0.0999) {
                $slow_queries[] = $val;
            }
        }

        return array(
            'queries' => self::queryCount(),
            'slow-queries' => $slow_queries,
            'total-query-time' => number_format($query_timer, 4, '.', '')
        );
    }

    /**
     * Convenience function to allow you to execute multiple SQL queries at once
     * by providing a string with the queries delimited with a `;`
     *
     * @throws DatabaseException
     * @throws Exception
     * @param string $sql
     *  A string containing SQL queries delimited by `;`
     * @param boolean $force_engine
     *  If set to true, this will set MySQL's default storage engine to MyISAM.
     *  Defaults to false, which will use MySQL's default storage engine when
     *  tables don't explicitly define which engine they should be created with
     * @return boolean
     *  If one of the queries fails, false will be returned and no further queries
     *  will be executed, otherwise true will be returned.
     */
    public function import($sql, $force_engine = false)
    {
        if ($force_engine) {
            // Silently attempt to change the storage engine. This prevents INNOdb errors.
            $this->query('SET default_storage_engine = MYISAM');
        }

        $queries = preg_split('/;[\\r\\n]+/', $sql, -1, PREG_SPLIT_NO_EMPTY);

        if (!is_array($queries) || empty($queries) || count($queries) <= 0) {
            throw new Exception('The SQL string contains no queries.');
        }

        foreach ($queries as $sql) {
            if (trim($sql) !== '') {
                $result = $this->query($sql);
            }

            if (!$result) {
                return false;
            }
        }

        return true;
    }
}
