<?php
/***Copyright and coded by Dakusan - See http://www.castledragmire.com/Copyright for more information. ***/
/***Dakusan's MySQL Library (DSQL) - v2.0.1.0 http://www.castledragmire.com/Projects/DSQL ***/

//Primary SQL class
class DSQL
{
	//Members
	public $Debug=false; //If set to true, query information is retained, and errors also output the query parameters
	public $QueryReplacements=Array(); //Replaces a queries format parameter with given regular expressions

	//Create the connection to the database. Throws DSQLException on connection error
	private $SQLConn=NULL, $ConnectionParms;
	function __construct()
	{
		//Get the connection variables
		$VarDefaults=Array('Server'=>'localhost', 'UserName'=>'', 'Password'=>'', 'Database'=>NULL);
		$Vars=func_get_args(); //Same format as $VarDefaults with final data
		switch(count($Vars)) //Number of args determines how the arguments are processed
		{
			case 0: //new DSQL(); Takes a global variable "$DSQLInfo" and calls the DSQL($Vars) construct with it
				global $DSQLInfo;
				if(!isset($DSQLInfo))
					return $this->Error('Cannot find global SQL connecting information');
				$Vars=$DSQLInfo;
				break;
			case 1: //new DSQL($Vars); Calls the primary construct with the variables found in the array as parameters
				$Vars=$Vars[0];
				break;
			default: //new DSQL($Server, $UserName, $Password, $Database); Connect to the server. If database is not specified, none is selected
				$NumVars=min(count($VarDefaults), count($Vars)); //Only process the given number of variables and ignore unpassed variables
				$Vars=array_combine(array_slice(array_keys($VarDefaults), 0, $NumVars), array_slice($Vars, 0, $NumVars));
		}
		$Vars+=$VarDefaults; //Make sure all connection variables are filled in
		$this->ConnectionParms=$Vars;

		//Connect to the database
		if(!($this->SQLConn=@mysqli_connect($Vars['Server'], $Vars['UserName'], $Vars['Password'], $Vars['Database'])))
			return $this->Error('Connect failed: '.mysqli_connect_error());

		//Set connection to UTF8 and used php time zone
		mysqli_query($this->SQLConn, "SET NAMES 'utf8' COLLATE 'utf8_general_ci'");
		mysqli_query($this->SQLConn, "SET CHARACTER SET 'utf8'");
		$this->Query('SET time_zone=?', date_default_timezone_get());

		//If the first connection, or global connection is not currently set, use the new DSQL object
		if(!isset(self::$GlobalConnection) || self::$GlobalConnection->SQLConn===NULL)
			self::$GlobalConnection=$this;
	}

	//Statically call public functions using a remembered DSQL object. The default remembered object is the first DSQL object made (set again if a new object is made after the previously stored DSQL object is closed)
	public static $GlobalConnection; //This connection is used for static calls
	public static function __callStatic($FuncName, $FuncArgs) { return self::CallRealFunc(NULL , $FuncName, $FuncArgs); }
	public        function __call      ($FuncName, $FuncArgs) { return self::CallRealFunc($this, $FuncName, $FuncArgs); } //Call public functions (this is requried to facilitate the statically called functionality)
	private static function CallRealFunc($DSQLObj, $FuncName, $FuncArgs) //If $DSQLObj is NULL, uses $GlobalConnection
	{
		//Check for valid function name
		if(!in_array($FuncName, Array('Close', 'Query', 'CleanQuery', 'GetAffectedRows', 'GetInsertID', 'GetSQLConn', 'GetSQLConnectionParms', 'Self')))
		{
			$Trace=debug_backtrace();
			trigger_error(sprintf('Call to undefined method DSQL::%s() in %s on line %s', $FuncName, $Trace[1]['file'], $Trace[1]['line']), E_USER_ERROR); //Execute function not found error
		}

		//Check for valid global connection
		if($DSQLObj===NULL && !isset(self::$GlobalConnection))
			throw new Exception('Cannot find global connection');

		//Call the requested function
		return call_user_func_array(Array($DSQLObj===NULL ? self::$GlobalConnection : $DSQLObj, '_'.$FuncName), $FuncArgs);
	}

	//Close the connection
	public function _Close()
	{
		$this->CheckConn(); //Confirms connection
		mysqli_close($this->SQLConn); //Close connection
		$this->SQLConn=NULL;
	}

	//Run a query
	public $QuerysInfo=Array(); //List of remembered query information. Each item contains Array(StartTime=>, ExecutionTime=>, QueryFormat=>, Values=>)
	public function _Query($QueryFormat)
	{
		$this->CheckConn(); //Confirms connection

		//Confirm the number of passed variables matches the number of question marks
		$Values=self::FlattenArray(array_slice(func_get_args(), 1)); //To get the variables, get a flattened array of all parameters past the query format
		if(count($this->QueryReplacements)) //Process query regular expression replacements
			$QueryFormat=preg_replace(array_keys($this->QueryReplacements), array_values($this->QueryReplacements), $QueryFormat);
		$SplitQuery=explode('?', $QueryFormat);
		if(count($SplitQuery)-1!=count($Values))
			return $this->SQLError('Query data count does not match', $QueryFormat, array_slice(func_get_args(), 1), NULL, time());

		//Insert the variable’s data into the query
		$FinalQuery=array_fill(0, count($Values)*2+1, 0); //Preallocate the array used to build the final query
		$FinalQuery[0]=$SplitQuery[0]; //Add the initial section of the query (so that the SplitQuery and Values arrays can now be considered parallel)
		foreach($Values as $Index => $Value)
		{
			$FinalQuery[1+$Index*2]=(is_null($Value) ? 'NULL' : (is_int($Value) || is_float($Value) ? $Value : "'".mysqli_escape_string($this->SQLConn, $Value)."'"));
			$FinalQuery[2+$Index*2]=$SplitQuery[$Index+1];
		}

		//Run the query and get its timing information
		$StartTime=microtime(true);
		$FinalQuery=implode('', $FinalQuery);
		$Result=mysqli_query($this->SQLConn, $FinalQuery);
		$ExecutionTime=microtime(true)-$StartTime; //Floating second execution time
		$StartTime=ceil($StartTime); //Change to Unix second timestamp

		//Check query result
		if($Result===FALSE)
			return $this->SQLError('SQL query error: '.mysqli_error($this->SQLConn), $QueryFormat, $Values, $FinalQuery, $StartTime);

		//Remember the original data if debug is turned on
		if($this->Debug)
			$this->QuerysInfo[]=Array('StartTime'=>$StartTime, 'ExecutionTime'=>$ExecutionTime, 'QueryFormat'=>$QueryFormat, 'Values'=>$Values);

		//Returns the DSQLResult
		return new DSQLResult($this, $Result, $QueryFormat, $Values, $FinalQuery, $StartTime, $ExecutionTime);
	}
	public function _CleanQuery($QueryFormat) //Wrapper for Query(), but changes all consecutive whitespace characters into a single splace, and trims beginning and end white space
	{
		$Args=func_get_args();
		$Args[0]=self::CleanQueryString($Args[0]);
		return call_user_func_array(Array($this, '_Query'), $Args);
	}
	private static function CleanQueryString($Q) { return preg_replace('/\s+/', ' ', trim($Q)); } //See CleanQuery

	//Confirm connection is open before performing operations
	private function CheckConn()
	{
		if(!isset($this->SQLConn)) //Confirm connection
			return $this->Error('Connection is not open');
	}

	//Other SQL functions
	public function _GetAffectedRows()	{ $this->CheckConn(); return mysqli_affected_rows($this->SQLConn); }
	public function _GetInsertID()		{ $this->CheckConn(); return mysqli_insert_id($this->SQLConn); }
	public function _GetSQLConn()		{ return $this->SQLConn; }
	public function _GetSQLConnectionParms(){ return $this->ConnectionParms; }
	public function _Self()			{ return $this; }

	//Error functions (throwing and executing error messages)
	public $PrintAndDieOnError=true; //If true, outputs the error as html and dies. Otherwise, throws a "DSQLException" error
	public function Error($Msg)
	{
		if(!$this->PrintAndDieOnError)
			throw new DSQLException($Msg);
		die('<pre>SQL Error encountered, please contact the administrators with the following information: '.htmlspecialchars($Msg, ENT_QUOTES, 'UTF-8').'</pre>');
	}

	//More specific error for SQL queries. Throws a "DSQLSqlException" if PrintAndDieOnError
	public function SQLError($Error, $QueryFormat, $QueryParameters, $CompiledQuery, $StartTime)
	{
		if(!$this->PrintAndDieOnError)
			throw new DSQLSqlException($this, $Error, $QueryFormat, $QueryParameters, $CompiledQuery, $StartTime);
		$this->Error("\n".$this->FormatSQLError($Error, $QueryFormat, $QueryParameters, $CompiledQuery, $StartTime));
	}
	public function FormatSQLError($Error, $QueryFormat, $QueryParameters, $CompiledQuery, $StartTime) //TODO: I want this to be private, but I can't since DSQLSqlException cannot be nested or friended
	{
		return
			"Start Time: ".date("Y-m-d g:i:s\n", $StartTime).
			"Error: $Error\n".
			"Query: $QueryFormat".
			(!$this->Debug ? '' :
				"\nCompiled Query: ".(isset($CompiledQuery) ? $CompiledQuery : 'UNKNOWN').
				"\nParameters: ".var_export($QueryParameters, true));
	}

	//Helper functions
	public static function FlattenArray($Arr) //Flattens all nested arrays into a single array
	{
		$RetArray=Array();
		foreach($Arr as $Val)
			if(is_array($Val))
				$RetArray=array_merge($RetArray, DSQL::FlattenArray($Val));
			else
				$RetArray[]=$Val;
		return $RetArray;
	}
	public static function PrepareList($List) { return implode(', ', array_fill(0, count($List), '?')); } //Returns a string of question marks separated by commas whos list length is equal to the array length of the parameter
	public static function PrepareUpdateList($NameList) { return implode('=?, ', $NameList).'=?'; } //Returns a string in the format "NAME=?, NAME=?, ..., NAME=?"
	public static function EscapeSearchField($S) { $e='\\'; return '%'.str_replace(array($e, '_', '%'), array($e.$e, $e.'_', $e.'%'), $S).'%'; } //Create a LIKE search string [for MySQL]
}

//Exceptions
class DSQLException extends Exception {}
class DSQLSqlException extends Exception
{
	public $Error, $QueryFormat, $QueryParameters, $CompiledQuery, $StartTime;
	public function __construct($DSQLParent, $Error, $QueryFormat, $QueryParameters, $CompiledQuery, $StartTime, $code=0, Exception $previous=null)
	{
		foreach(Array('Error', 'QueryFormat', 'QueryParameters', 'CompiledQuery', 'StartTime') as $VarName)
			$this->$VarName=${$VarName};
		parent::__construct($DSQLParent->FormatSQLError($Error, $QueryFormat, $QueryParameters, $CompiledQuery, $StartTime), $code, $previous);
	}
}

//Result class
class DSQLResult
{
	//Creation; This will generally only be called from DSQL::Query
	private $Parent; //The DSQL parent
	private $SQLResult; //The result object returned from SQL. TRUE if no result
	private $CurRowNum=0; //The current row number ready to be fetched
	private $NumRows, $NumFields; //The number of rows and fields per row in the result set
	private $Finished=false; //If all rows have been retrieved. This is a physical (instead of meta) variable to make sure the MySQL result set is always released exactly once
	private $QueryFormat, $QueryParameters, $CompiledQuery, $StartTime, $ExecutionTime; //The query information
	public function __construct($Parent, $SQLResult, $QueryFormat, $QueryParameters, $CompiledQuery, $StartTime, $ExecutionTime)
	{
		foreach(Array('Parent', 'SQLResult', 'QueryFormat', 'QueryParameters', 'CompiledQuery', 'StartTime', 'ExecutionTime') as $VarName)
			$this->$VarName=${$VarName};
		$this->NumRows=($this->SQLResult===TRUE ? 0 : $SQLResult->num_rows);
		$this->NumFields=($this->SQLResult===TRUE ? 0 : $SQLResult->field_count);
		$this->CheckFinishedState();
	}

	//Handle all getters
	public function __get($VarName)
	{
		//Check for and return valid member from name
		if(in_array($VarName, Array('Parent', 'SQLResult', 'CurRowNum', 'NumRows', 'NumFields', 'Finished', 'QueryFormat', 'QueryParameters', 'CompiledQuery', 'StartTime', 'ExecutionTime')))
			return $this->$VarName;

		//Return error
		$Trace=debug_backtrace();
		trigger_error(sprintf('Undefined property: DSQLResult::$%s in %s on line %s', $VarName, $Trace[0]['file'], $Trace[0]['line']), E_USER_NOTICE);
		return NULL;
	}

	//Test and return if result set is finished, and release a result set when this first occurs
	private function CheckFinishedState()
	{
		if($this->SQLResult!==TRUE && $this->CurRowNum!=$this->NumRows) //If not finished
			return FALSE;

		if(!$this->Finished) //If first occurance of being finished, free the MySQL result set
		{
			if($this->SQLResult!==TRUE)
				mysqli_free_result($this->SQLResult);
			$this->Finished=true;
		}

		return TRUE; //Return as finished
	}

	//Fetch all rows into an array
	public function FetchAll()
	{
		//Confirm start state
		if($this->Finished) //If no result set, return empty array
			return Array();

		//Gather all the rows
		$ReturnArray=Array();
		for(; $this->CurRowNum<$this->NumRows; $this->CurRowNum++)
		{
			$Row=mysqli_fetch_assoc($this->SQLResult);
			$ReturnArray[]=($this->NumFields==1 ? current($Row) : $Row);
		}

		$this->CheckFinishedState(); //Set as finished
		return $ReturnArray; //Return the results
	}

	//Fetch only the requested row number
	public function FetchRow($RowNum)
	{
		//Confirm start state
		if($this->SQLResult===TRUE) //If no result set, return FALSE
			return FALSE;
		if($this->Finished)
			return $this->Parent->SQLError('All rows have already been fetched', $this->QueryFormat, $this->QueryParameters, $this->CompiledQuery, $this->StartTime);
		if($this->CurRowNum>$RowNum)
			return $this->Parent->SQLError('Cannot call FetchRow on a RowNum that has already been fetched', $this->QueryFormat, $this->QueryParameters, $this->CompiledQuery, $this->StartTime);

		//If beyond the last row
		if($RowNum>=$this->NumRows)
		{
			$this->CurRowNum=$this->NumRows; //Set to last row
			return !$this->CheckFinishedState(); //Set as finished and return FALSE
		}

		//Fetch and discard rows until the requested row is found
		do
			$Row=mysqli_fetch_assoc($this->SQLResult);
		while(++$this->CurRowNum<=$RowNum);

		$this->CheckFinishedState(); //Check for finished
		return ($this->NumFields==1 ? current($Row) : $Row); //Return the found value
	}

	//Fetch the next row
	public function FetchNext()
	{
		//Confirm start state
		if($this->Finished) //If no result set, return FALSE
			return FALSE;

		$Row=mysqli_fetch_assoc($this->SQLResult); //Get the result of the next row
		$this->CurRowNum++;
		$this->CheckFinishedState(); //Check for finished
		return ($this->NumFields==1 ? current($Row) : $Row); //Return the found value
	}

	//Wrapper function in which no parameter means FetchAll() and 1 parameter means FetchRow($RowNum)
	public function Fetch($RowNum=null) { return !isset($RowNum) ? $this->FetchAll() : $this->FetchRow($RowNum); }

	//Returns an associative array of the passed array in which the key of each item becomes the first extracted value from the item. Original row ordering is maintained
	public static function GetKeyedArray($Arr)
	{
		if(!count($Arr))
			return Array();
		$KeyName=key($Arr[0]);
		$RetArray=Array();
		foreach($Arr as $Item)
			$RetArray[$Item[$KeyName]]=(count($Item)==2 ? next($Item) : array_slice($Item, 1)); //If only 1 value remains, return the scalar instead
		return $RetArray;
	}
	public function GetKeyed() //Wrapper for GetKeyedArray() with the passed array being retreived from FetchAll()
	{
		return self::GetKeyedArray($this->FetchAll());
	}
}
?>