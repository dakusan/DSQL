Dakusan’s MySQL Library (DSQL) - v2.0.1.0 http://www.castledragmire.com/Projects/DSQL

**A MySQL library for PHP with functionality to help facilitate cleaner and quicker SQL access.**

I’ve found using php’s included MySQL functions to be cumbersome, take a lot more code than necessary, and create code that is not very readable. I am also not completely happy with PDO, so DSQL is my solution to this problem.

The classes in this library are written generically so they could easily be converted to any other database software.

#Simple examples
##[Create the primary connection](#ObjectCreation)
* Code: `$MyConn=new DSQL('localhost', 'root', '', 'example_db');`

##Create the example table:
* Code: `DSQL::Query('CREATE TABLE example_data (w int, x int, y int NULL, z varchar(255))');`
* Notes: Example of [statically](#Main_StaticCalls) using the [globally remembered connection](#Main_Member_GlobalConnection)

##[Table prefix](#Main_Member_QueryReplacements):
* Code: `$MyConn->QueryReplacements=Array('/_TBL_/'=>'example_');`
* Notes: All instances of “_TBL” are now replaced with “example_”

##[Query](#Main_Function_Query) creation with values:
* Code:
```php
$Values=Array(1.0, Array('30.0', null), "test ' string");
$MyConn->Query('INSERT INTO _TBL_data VALUES (?, ?, ?, ?)', $Values);
$MyConn->Query('INSERT INTO _TBL_data VALUES (?, ?, ?, ?), (?, ?, ?, ?)', 1, 2, 3, 4, 5, 6, 7, 8);
```
* Ran Queries:
```sql
INSERT INTO example_data VALUES (1.0, '30.0', NULL, 'test \' string')
INSERT INTO example_data VALUES (1, 2, 3, 4), (5, 6, 7, 8)
```
* Notes: This is also an example of [flattening](#Main_Function_FlattenArray)

##[Cleaning a query](#Main_Function_CleanQuery):
* Code:
```php
$Data=$MyConn->CleanQuery('
  SELECT T1.w AS W1, T2.w AS W2, T1.x AS X1, T2.x AS X2
  FROM _TBL_data AS T1
  INNER JOIN example_data AS T2 ON T2.w=T1.w
  WHERE T1.x=?', 30);
```
* Ran Queries:
```sql
SELECT T1.w AS W1, T2.w AS W2, T1.x AS X1, T2.x AS X2 FROM example_data AS T1 INNER JOIN example_data AS T2 ON T2.w=T1.w WHERE T1.x=30
```

##[Fetching all data](#Result_Function_FetchAll):
* Code:
```php
$Result=$Data->FetchAll(); //Uses $Data from “Cleaning a query”
```
* Results:
```php
Array(
  Array('1', '1', '30', '30'),
  Array('1', '1', '30', '2')
)
```

##[Fetching scalars](#Result_Scalars):
* Code:
```php
$Result=$MyConn->Query('SELECT x FROM example_data')->FetchAll();
```
* Return:
```php
Array('30', '2', '6');
```
* Notes:
  * Since there is only 1 value returned, each item in the result set is returned as a [scalar](#Result_Scalars) instead of an array

##[Fetching keyed data](#Result_Function_GetKeyed):
* Code:
```php
$Result=$MyConn->Query('SELECT w, x, y, z FROM example_data WHERE x!=?', 2)->GetKeyed();
```
* Result:
```php
Array(
  1=>Array('x'=>'30', 'y'=>NULL, 'z'=>"test ' string"),
  5=>Array('x'=>'6', 'y'=>'7', 'z'=>'8')
)
```
* Notes:
  * If only 2 return fields are requested with [GetKeyed](#Result_Function_GetKeyed), the value of each item would instead be a [scalar](#Result_Scalars)

#<div name="Main_Class">Class DSQL</div>
##General information
* MySQL interface
* <div name="Main_StaticCalls">Statically called functions use the remembered DSQL object in [$GlobalConnection](#Main_Member_GlobalConnection)</div>
  * This allows the [default] [DSQL](#Main_Class) object to not have to be remembered or passed externally. This is useful since generally only 1 database connection is established.
  * A general exception is thrown if the [$GlobalConnection](#Main_Member_GlobalConnection) is not valid

##Implementation specific information
* This implementation is MySQL specific
* UTF8 is automatically used as the character set
* The [time zone](http://dev.mysql.com/doc/refman/5.6/en/server-system-variables.html#sysvar_time_zone) for SQL is pulled from the [php default time zone](http://php.net/manual/en/function.date-default-timezone-get.php)

##Members
* <div name="Main_Member_Debug">**Debug**</div>
  * If set to true, [query information](#Main_Member_QuerysInfo) is retained, and [error functions](#Main_ErrorFunctions) also output the query parameters and compiled query
* <div name="Main_Member_PrintAndDieOnError">**PrintAndDieOnError**</div>
  * If true, outputs the error as html and dies. Otherwise, throws the appropriate [DSQL exception type](#Main_ErrorFunctions)
  * Default=true
* <div name="Main_Member_QuerysInfo">**QuerysInfo**</div>
  * When [debug](#Main_Member_Debug) is turned on, [Query()](#Main_Function_Query) adds items to this list with its information
  * Each item contains an array of:
    * **StartTime**: The unix timestamp of when the query started
    * **ExecutionTime**: The number of seconds (including fractions) the query took to execute. This includes the round trip to the server
    * **QueryFormat**: The query format after replacements by [$QueryReplacements](#Main_Member_QueryReplacements)
    * **Values**: An array of the values filled into the query
* <div name="Main_Member_GlobalConnection">**GlobalConnection** (static)</div>
  * This [DSQL](#Main_Class) is used for [static calls](#Main_StaticCalls)
  * The default remembered object is the first DSQL object made (set again if a new object is made after the previously stored DSQL object is closed)
* <div name="Main_Member_QueryReplacements">**QueryReplacements**</div>
  * An array of: `RegularExpression => Replacement`
  * Replaces a [queries](#Main_Function_Query) [query format](#Main_Query_Member_QueryFormat) parameter with given regular expressions
  * For example:
```php
Array(
  '/_TBL_/'=>'MyForum_',               //Changes all instance of “_TBL_” to “MyForum_” (useful for adding table prefixes)
  '/^(INSERT)(\s+)/i'=>'$1 IGNORE$2',  //Changes all queries starting with “INSERT ” to “INSERT IGNORE ” (for example: “insert   into foo” to “insert INGORE   into foo”)
  '/\s*;?\s*$/'=>''                    //Removes a semicolon at the end of the query and/or any whitespace at the end
)
```
  * This happens at the top of the [Query()](#Main_Function_Query) function, so all [errors](#Main_ErrorFunctions) and [information](#Main_Member_QuerysInfo) regarding the query will reflect these replacements

##<div name="ObjectCreation">Object Creation</div>
* <div name="Main_Function_PrimaryCreation">`new DSQL($Server='localhost', $UserName='', $Password='', $Database=NULL)`</div>
  * Connect to the server
  * If database is not specified, none is selected
  * In all construct functions, if a parameter is not specified, the default is used
  * This is used if 2 or more parameters are passed to the constructor
  * Possible Errors:
    * ([DSQLException](#Main_ErrorFunctions)) Connect failed: ...
* <div name="Main_Function_ArrayParamCreation">`new DSQL($Vars)`</div>
  * Calls the [primary construct](#Main_Function_PrimaryCreation) with the members found in the array as parameters
  * This is used if 1 parameter is passed to the constructor
* `new DSQL()`
  * Takes a global variable “$DSQLInfo” and calls the [DSQL($Vars)](#Main_Function_ArrayParamCreation) construct with it
  * This is used if no parameters are passed to the constructor
  * Possible Errors:
    * ([DSQLException](#Main_ErrorFunctions)) Cannot find global SQL connecting information

##Functions
* <div name="Main_Function_Close">**Close**()</div>
  * Close and clear the database connection
* <div name="Main_Function_Query">**Query**($QueryFormat, $Variables...)</div>
  * Execute a query
  * <div name="Main_Query_Member_QueryFormat">The query contains question marks where its variables need to be inserted. The number of variables must equal the number of question marks</div>
  * All passed parameters (except the query format) are [flattened](#Main_Function_FlattenArray) to find the final list of variables
    * For example:
```php
Query('INSERT INTO foo VALUES (?, ?, ?, ?, ?)', Array(01, Array('01', 1.10), '1.10'), null)
```
* <div></div>
    * Will execute a query of:
```sql
INSERT INTO foo VALUES (1, '01', 1.1, '1.10', NULL)
```
* <div></div>
    * NULL and numbers are passed unescaped
    * Returns a [DSQLResult](#Result_Class)
    * Possible Errors:
      * <div name="Main_Error_QueryCountNoMatch">([DSQLException](#Main_ErrorFunctions)) Query data count does not match</div>
      * ([DSQLException](#Main_ErrorFunctions)) SQL query error: ...
* <div name="Main_Function_CleanQuery">**CleanQuery**($QueryFormat, $Variables...)</div>
  * Wrapper for [Query()](#Main_Function_Query), but changes all consecutive whitespace characters into a single space, and trims beginning and end white space
* <div name="Main_Function_GetAffectedRows">**GetAffectedRows**()</div>
  * Returns the number of affected rows in the last statement
* <div name="Main_Function_GetInsertID">**GetInsertID**()</div>
  * Returns the insert ID for the last statement
* <div name="Main_Function_GetSQLConn">**GetSQLConn**()</div>
  * Returns the SQL connection object ([private] SQLConn member)
* <div name="Main_Function_GetSQLConnectionParms">**GetSQLConnectionParms**()</div>
  * Returns an array containing the [connection parameters](#Main_Function_PrimaryCreation)
```php
Array('Server'=>, 'Username'=>, 'Password'=>, 'Database'=>)
```
* <div></div>
  * Database is null if not initially specified
* <div name="Main_Function_Self">**Self**()</div>
  * Returns the [DSQL](#Main_Class) object
  * Useful for when calling [statically](#Main_StaticCalls)

##Function Notes
* All functions except [GetSQLConn()](#Main_Function_GetSQLConn), [GetSQLConnectionParms()](#Main_Function_GetSQLConnectionParms), and [Self()](#Main_Function_Self) can throw a ([DSQLException](#Main_ErrorFunctions)) “Connection is not open”
* All functions are internally named starting with an underscore to facilitate the dual [static](#Main_StaticCalls)/nonstatic calling functionality. For example: DSQL::_Query

##Helper (static) functions
* <div name="Main_Function_FlattenArray">**FlattenArray**($Array)</div>
  * Flattens all nested arrays into a single array
* <div name="Main_Function_PrepareList">**PrepareList**($List)</div>
  * Returns a string of question marks separated by commas whose list length is equal to the array length of the parameter
  * For example:
```php
$Values=Array('a'=>5, 'b'=>10, 'c'=>15); //This is an example array in which the keys are the column names and the values are also the SQL’s field values. This array can be passed directly to Query()
PrepareList($Values); //The keys are not needed for this function; only the list length
```
* <div></div>
  * Will return: `'?, ?, ?'`
* <div name="Main_Function_PrepareUpdateList">**PrepareUpdateList**($NameList)</div>
  * Returns a string in the format “NAME=?, NAME=?, ..., NAME=?”
  * For example:
```php
PrepareUpdateList(array_keys($Values)) //See $Values from above example
```
* <div></div>
  * Will return: `'a=?, b=?, c=?'`
  * This does not account for reserved field names that need to be enclosed in backticks
* <div name="Main_Function_EscapeSearchField">**EscapeSearchField**($S)</div>
  * Create a LIKE search string [for MySQL]
  * This is done by:
    * Escaping with a backslash all backslashes, underscores, and percent signs
    * Adding a percent sign to the beginning and end of the string
  * For example:
```php
EscapeSearchField('ab%c_d\\e')
```
* <div></div>
  * Will return:
```php
'%ab\\%c\\_d\\\\e%'
```
* <div></div>
    * Un-php-stringified: `%ab\%c\_d\\e%`

##<div name="Main_ErrorFunctions">Exceptions and Errors</div>
* The error functions may be overwritten in a derived class
* EXCEPTION DSQLException
  * Standard exception with just an error message
* EXCEPTION DSQLSqlException
  * More specific exception for SQL queries
  * See “virtual [SQLError](#Main_Function_SQLError)” for members
* virtual Error($Msg)
  * Throws a DSQLException (if not [$PrintAndDieOnError](#Main_Member_PrintAndDieOnError))
* <div name="Main_Function_SQLError">virtual SQLError(</div><pre>
  $Error:                  The error message
  $QueryFormat:            The passed query format
  $QueryParameters:        The passed parameters. This is not [flattened](#Main_Function_FlattenArray) on the “[Query data count does not match](#Main_Error_QueryCountNoMatch)” error
  $CompiledQuery:          The compiled query with question marks replaced
  $StartTime:              The unix timestamp of when the query started
)</pre>
  * Throws a DSQLSqlException (if not [$PrintAndDieOnError](#Main_Member_PrintAndDieOnError))

#<div name="Result_Class">Class DSQLResult</div>
##General information
* MySQL interface for returns from [executed statements](#Main_Function_Query)

##Implementation specific information
* This implementation is MySQL specific
* <div name="Result_Scalars">If only 1 return field is requested in the SQL statement, each row is returned as just a scalar instead of an array</div>

##<div name="Result_GettableMembers">Gettable Members</div>
* <div name="Result_Member_Parent">**Parent**: The [DSQL](#Main_Class) parent</div>
* <div name="Result_Member_SQLResult">**SQLResult**: The result object returned from SQL. TRUE if no result</div>
* <div name="Result_Member_CurRowNum">**CurRowNum**: The current row number ready to be fetched</div>
* <div name="Result_Member_NumRows">**NumRows**: The number of rows in the result set</div>
* <div name="Result_Member_NumFields">**NumFields**: The number of fields in the return rows</div>
* <div name="Result_Member_Finished">**Finished**: If all rows have been retrieved</div>
* <div name="Result_Members_Errors">**QueryFormat**, **QueryParameters**, **CompiledQuery**, **StartTime**: See parameter explanations from “[DSQL::SQLError](#Main_ErrorFunctions)”</div>
* <div name="Result_Member_ExecutionTime">**ExecutionTime**: The unix time the query took to execute with fractions of a second. It includes the round trip to the server</div>

##Object Creation
* `new DSQLResult($Parent, $SQLResult, $QueryFormat, $QueryParameters, $CompiledQuery, $StartTime, $ExecutionTime)`
  * See [Gettable Members](#Result_GettableMembers) for parameter information
  * This will generally only be called from [DSQL::Query](#Main_Function_Query)

##Functions
* <div name="Result_Function_FetchAll">**FetchAll**()</div>
  * Fetch all rows into an array
* <div name="Result_Function_FetchRow">**FetchRow**($RowNum)</div>
  * Fetch only the requested row number
  * Returns FALSE if the row does not exist
  * Possible Errors:
    * ([DSQLSqlException](#Main_ErrorFunctions)) All rows have already been fetched
    * ([DSQLSqlException](#Main_ErrorFunctions)) Cannot call FetchRow on a RowNum that has already been fetched
* <div name="Result_Function_FetchNext">**FetchNext**()</div>
  * Fetch the next row
  * Returns FALSE if the next row does not exist
* <div name="Result_Function_Fetch">**Fetch**($RowNum=null)</div>
  * Wrapper function in which no parameter means [FetchAll()](#Result_Function_FetchAll) and 1 parameter means [FetchRow($RowNum)](#Result_Function_FetchRow)
* <div name="Result_Function_GetKeyed">**GetKeyed**()</div>
  * Wrapper for [GetKeyedArray()](#Result_Function_GetKeyedArray) with the passed array being retrieved from [FetchAll()](#Result_Function_FetchAll)

##Helper (static) functions
* <div name="Result_Function_GetKeyedArray">**GetKeyedArray**($Array)</div>
  * Returns an associative array of the passed array in which the key of each item becomes the first extracted value from the item
  * Original row ordering is maintained
  * The first key in each original item must have the same name
  * For example:
```php
GetKeyedArray(Array(
  Array('v1'=>'b', 'v2'=>1, 'v3'=>2),
  Array('v1'=>'a', 'v2'=>3, 'v3'=>4),
  Array('v1'=>'c', 'v2'=>5, 'v3'=>6)
))
```
* <div></div>
  * Will Return:
```php
Array(
  'b'=>Array('v2'=>1,'v3'=>2),
  'a'=>Array('v2'=>3,'v3'=>4),
  'c'=>Array('v2'=>5,'v3'=>6)
)
```
* <div></div>
  * If only 1 field remains after the key extraction, the value is just the [scalar](#Result_Scalars) instead of an array
  * For example:
```php
GetKeyedArray(Array(
  Array('v1'=>'b', 'v2'=>1),
  Array('v1'=>'a', 'v2'=>3)
))
```
* <div></div>
  * Will Return:
```php
Array(
  'b'=>1,
  'a'=>3,
)
```

Copyright and coded by Dakusan - See http://www.castledragmire.com/Copyright for more information.