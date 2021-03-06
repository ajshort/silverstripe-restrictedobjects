<?php

/**
 * A wrapper around DataObject:: static methods that perform proper security checks
 * when loading objects
 * 
 *
 * @author marcus@silverstripe.com.au
 * @license BSD License http://silverstripe.org/bsd-license/
 */
class DataService {
	public function __construct() {
	}
	
	/**
	 * Shortcut for loading objects. 
	 * 
	 * Instead of $data->get('Type', 'Filter');
	 * 
	 * you can simply call
	 * 
	 * $data->Type('filter');
	 *
	 * @param type $method
	 * @param type $arguments 
	 */
	public function __call($method, $arguments) {
		$type = null; 
		$call = null;
		
		if (strpos($method, 'getOne') === 0) {
			$call = 'getOne';
		} else if (strpos($method, 'getAll') === 0) {
			$call = 'getAll';
		} else if (strpos($method, 'ById') > 0) {
			$call = 'ById';
		}

		if ($call) {
			$type = ucfirst(str_replace($call, '', $method));
		}

		if ($type && class_exists($type)) {
			array_unshift($arguments, $type);
			return call_user_func_array(array($this, lcfirst($call)), $arguments);
		}
		throw new Exception("Cannot get objects of unknown type in $method");
	}
	
	/**
	 * 
	 * Retrieve a list of data objects
	 * 
	 * @see DataObject::get()
	 *
	 * @param type $callerClass
	 * @param string|array $filter
	 *			
	 * @param type $sort
	 * @param type $join
	 * @param type $limit
	 * @param type $containerClass
	 * @return DataObjectSet
	 */
	public function getAll($callerClass, $filter = "", $sort = "", $join = "", $limit = "", $containerClass = "DataObjectSet") {
		return $this->loadObjects($callerClass, $filter, $sort, $join, $limit, $containerClass);
	}
	
	/**
	 * Gets a single object
	 *
	 * @param type $callerClass
	 * @param type $filter
	 * @param type $cache
	 * @param type $orderby
	 * @return DataObject
	 */
	public function getOne($callerClass, $filter = "", $cache = true, $orderby = "") {
		if (is_array($filter)) {
			$filter = $this->dbQuote($filter);
		}
		$item = DataObject::get_one($callerClass, $filter, $cache, $orderby);
		if ($item && $item->checkPerm('View')) {
			return $item;
		}
	}
	
	/**
	 * Return the given element, searching by ID
	 *
	 * @param string $callerClass The class of the object to be returned
	 * @param int $id The id of the element
	 * @param boolean $cache See {@link get_one()}
	 *
	 * @return DataObject The element
	 */
	public function byId($callerClass, $id, $cache = true) {
		$id = (int) $id;
		$item = DataObject::get_by_id($callerClass, $id, $cache);
		if ($item && $item->hasExtension('Restrictable') && $item->checkPerm('View')) {
			return $item;
		}
		
		if ($item && $item->canView()) {
			return $item;
		}
	}
	
	/**
	 * A reimplementation of DataObject::instance_get
	 *
	 * @param string $filter A filter to be inserted into the WHERE clause.
	 * @param string $sort A sort expression to be inserted into the ORDER BY clause.  If omitted, self::$default_sort will be used.
	 * @param string $join A single join clause.  This can be used for filtering, only 1 instance of each DataObject will be returned.
	 * @param string $limit A limit expression to be inserted into the LIMIT clause.
	 * @param string $containerClass The container class to return the results in.
	 *
	 * @return mixed The objects matching the filter, in the class specified by $containerClass
	 */
	public function loadObjects($type, $filter = "", $sort = "", $join = "", $limit="", $containerClass = "DataObjectSet") {
		if(!DB::isActive()) {
			throw new Exception("DataObjects have been requested before the database is ready. Please ensure your database connection details are correct, your database has been built, and that you are not trying to query the database in _config.php.");
		}
		if (is_array($filter)) {
			$filter = $this->dbQuote($filter);
		}
		$dummy = singleton($type);

		$query = $dummy->extendedSQL($filter, $sort, $limit, $join);
		
		$records = $query->execute();
		
		$ret = $this->buildDataObjectSet($records, $containerClass, $query, $dummy->class);
		if($ret) $ret->parseQueryLimit($query);
		return $ret;
	}
	
	/**
	 * Reimplementation of DataObject:: method, that filters out items that don't have permissions
	 * 
	 * Take a database {@link SS_Query} and instanciate an object for each record.
	 *
	 * @param SS_Query|array $records The database records, a {@link SS_Query} object or an array of maps.
	 * @param string $containerClass The class to place all of the objects into.
	 *
	 * @return mixed The new objects in an object of type $containerClass
	 */
	function buildDataObjectSet($records, $containerClass = "DataObjectSet", $query = null, $baseClass = null) {
		foreach($records as $record) {
			if(empty($record['RecordClassName'])) {
				$record['RecordClassName'] = $record['ClassName'];
			}
			if(class_exists($record['RecordClassName'])) {
				$item = new $record['RecordClassName']($record);
				if ($item->checkPerm('View')) {
					$results[] = $item;
				}
			} else {
				if(!$baseClass) {
					throw new Exception("Bad RecordClassName '{$record['RecordClassName']}' and "
						. "\$baseClass not set", E_USER_ERROR);
				} else if(!is_string($baseClass) || !class_exists($baseClass)) {
					throw new Exception("Bad RecordClassName '{$record['RecordClassName']}' and bad "
						. "\$baseClass '$baseClass not set", E_USER_ERROR);
				}
				
				$item = new $baseClass($record);
				if ($item->checkPerm('View')) {
					$results[] = $item;
				}
			}
		}

		if(isset($results)) {
			return new $containerClass($results);
		}
	}
	
	
	/**
	 * Quote up a filter of the form
	 *
	 * array ("ParentID =" => 1)
	 *
	 * @param array $filter
	 * @return string
	 */
	function dbQuote($filter = array(), $join = " AND ") {
		$QUOTE_CHAR = defined('DB::USE_ANSI_SQL') ? '"' : '';

		$string = '';
		$sep = '';

		foreach ($filter as $field => $value) {
			// first break the field up into its two components
			$operator = '=';
			if (is_string($field) && strpos($field, ' ')) {
				list($field, $operator) = explode(' ', trim($field));
			}

			$value = $this->recursiveQuote($value);

			if (strpos($field, '.')) {
				list($tb, $fl) = explode('.', $field);
				$string .= $sep . $QUOTE_CHAR . $tb . $QUOTE_CHAR . '.' . $QUOTE_CHAR . $fl . $QUOTE_CHAR . " $operator " . $value;
			} else {
				if (is_numeric($field)) {
					$string .= $sep . $value;
				} else {
					$string .= $sep . $QUOTE_CHAR . $field . $QUOTE_CHAR . " $operator " . $value;
				}
			}

			$sep = $join;
		}

		return $string;
	}

	protected function recursiveQuote($val) {
		if (is_array($val)) {
			$return = array();
			foreach ($val as $v) {
				$return[] = $this->recursiveQuote($v);
			}
			return '('.implode(',', $return).')';
		} else if (is_null($val)) {
			$val = 'NULL';
		} else if (is_int($val)) {
			$val = (int) $val;
		} else if (is_double($val)) {
			$val = (double) $val;
		} else if (is_float($val)) {
			$val = (float) $val;
		} else {
			$val = "'" . Convert::raw2sql($val) . "'";
		}

		return $val;
	}
}


if (!function_exists('lcfirst')) {
	function lcfirst($str) {
		if (strlen($str) > 0) {
			return strtolower($str{0}) . substr($str, 1);
		}
		return $str;
	}
}