<?php

namespace rastatech\odbal;

use \Exception;

/**
 * Abstraction of the Oracle variable binding process and related functionality for better maintainability/readability
 *
 * Refactored 2019.10.30 to handle arrayed parameters for Oracle Table types or VARRAYs
 * Refactored 2020.04.08 to BETTER handle arrayed parameters, including empty arrays and arrays with NULL values, as well as support for Named Types and for Oracle Table types or VARRAYs
 *
 * @package    \ODBAL
 * @author     todd.hochman
 * @uses       dbal_configurator a trait containing some configuration setting code that is used across several of the above classes
 * @link       http://us1.php.net/manual/en/function.oci-bind-by-name.php see list of bind types for regular parameters
 * @link       http://us1.php.net/manual/en/function.oci-bind-array-by-name.php see list of bind types for array parameters
 * @author     todd.hochman
 *
 * @todo       doesn't support pass-thru SQL yet
 */
class bindings
{
    /**
     *
     * @var Container    the Dependency Injection Container
     */
    protected $ci;

    /**
     *
     * @var array public variable so we can access bound variables; will be a numerically indexed array in order of the array of bound variables
     */
    public $bound_vars = array();

    /**
     *
     * @var mixed-array array of variables to bind and the values to which to bind them
     */
    public $vars2bind = array();

    /**
     * @var string-array    the array of regexes to use to parse for arrayed paramter bind types; trickier than it sounds....
     *      comes from the .ini file for improvement....
     */
    protected $_type_regexes = [];

    /**
     * Configuration loading Trait
     */
    use configurator;

    /**
     * some factored-out-for-length payload handling code in the form of a Trait
     */
    use payload;

    /**
     * some factored-out-for-length type parsing code in the form of a Trait
     */
    use bindtype;

    /**
     * some factored-out-for-length length parsing code in the form of a Trait
     */
    use bindlength;

    /**
     * some factored-out-for-length value parsing code in the form of a Trait
     */
    use bindvalue;

    /**
     * Constructor; sets configs and assigns those that match to class attributes
     *
     * @param  Container    $ci the Dependency Injection Container
     * @param bool $model_sql_elements  the array of SQL elements; see the example models for structure
     */
    public function __construct($ci, $model_sql_elements = FALSE)
    {
        $this->ci = $ci;
        $loadedConfigs = $this->_get_configs($model_sql_elements);
        $this->_assign2classVars($loadedConfigs);
    }

    /**
     * Either sets the variables & values to bind if an array of such is passed, OR binds the variables to the oracle resources via __bindVars2SQL() if not.
     *
     * <b>dynamically creates public class attributes corresponding to the keys in the 'bind_vars' array, via which the caller can access the bound variables directly</b>
     *
     *
     * @param string $sqlType the type of SQL being processed, e.g. stored procedure (which is all that is currently supported, but leaving it open....)
     * @param bool   $bind_var_array
     * @param bool   $stmt    the parsed OCI statement resource
     * @return array|void boolean-array    the results of the binding operation(s)
     * @throws Exception it doesn't, tho.....
     * @uses           __bindVars2SQL()
     * @link           https://www.php.net/manual/en/function.oci-bind-by-name.php valid OCI datatypes for atomic values
     * @link           https://www.php.net/manual/en/function.oci-bind-array-by-name.php valid OCI datatypes for arrayed values
     */
    public function bind_vars($sqlType, $bind_var_array = FALSE, $stmt = FALSE)
    {
        if ($bind_var_array) {
            foreach ($bind_var_array as $key2bind => $value2bind) {
                $this->vars2bind[$key2bind] = $value2bind;
            }
            $return = (!$stmt) ? $this->vars2bind : $this->_bindVars2SQL($stmt, $sqlType);
        }
        $return = ((!isset($return)) AND ($stmt)) ? $this->_bindVars2SQL($stmt, $sqlType) : $return;
        return $return;
    }

    /**
     * Determines the SQL type and passes off the actual variable binding to the appropriate method for binding.
     *
     * Only one method (for packages) is supported currently, but this method is designed to support pass-thru SQL when that is added, if ever
     *
     * @param         $stmt
     * @param integer $sqlType the type of SQL statement being bound
     * @return array|void integer-array the array of boolean return values for the binding operation(s);
     * @throws Exception if no variables were available for binding
     */
    protected function _bindVars2SQL($stmt, $sqlType)
    {
        if (!$stmt) {
            throw new Exception('no statement found to bind on!', 517);
        }
        if ($this->vars2bind) {
            $b = ($sqlType < 2) ? $this->_bind_pkg($stmt) : $this->bind_passThruSQL($stmt); //not currently supported but leaving it open....
            $this->bound_vars = $b;
            return $b;
        }
    }

    /**
     * Iterates through the Oracle function or procedure variable array and binds them to the statement
     *
     * This function also will assign each key/ value pair to a dynamically-created public class attribute,
     * so you can reference those variables from outside the class - like say if you have a function that returns a string or integer,
     * or other non-cursor OUT parameters from a procedure.
     *
     * This function will also account for arrayed parameter values, i.e. if the passed parameter value is an array of raw values.
     * This enables support of array-type Oracle parameters, such as a parameter of type table or VARRAY.
     *
     * @param statement $stmt the Oracle parsed statement resource handle
     * @return array boolean-array the array of binding results
     * @throws Exception failure to bind variables will throw an exception
     */
    protected function _bind_pkg($stmt)
    {
        if (! $stmt){
            throw new Exception('no valid OCI statement received in _bind_pkg', 520);
        }
        $b = array();
        foreach ($this->vars2bind as $bind_varname => $bind_value) {
            $this->bound_vars[$bind_varname] = $bind_value;
//            //dynamically add a public class attribute by which to access the bound var:
            $this->$bind_varname = ((is_array($bind_value)) AND (array_key_exists('value', $bind_value))) ? $bind_value['value'] : $bind_value;
            $b[$bind_varname] = $this->_bind_package_parameter($stmt, $bind_varname, $bind_value);
        }
        return $b;
    }

    /**
     * Placeholder function for some functionality that was not commonly used and was too much trouble to port over if we weren't going to need it.
     *
     * @param statement $stmt the Oracle parsed statement resource handle
     * @return void
     * @throws Exception this method is not yet implemented
     */
    protected function bind_passThruSQL($stmt)
    {
        $errMsg = 'pass-thru SQL not currently supported in this version of Oracle DBAL; Please make a PL/SQL stored procedure instead, or fork Rastatech/ODBAL on GitHub.';
        if (! $stmt){
            throw new Exception($errMsg, 520);
        }
        $b = FALSE; //replace this with actual binding for the pass-through sql
        if($b === FALSE){
            throw new Exception($errMsg, 520);
        }
    }

    /**
     * Merges any existing array of variables to bind with a new array of 'em
     *
     * typically accessed publicly; no internal usage
     *
     * @param  mixed-array    $newVars2bind  the array of variables to bind in the format of name => value
     * @return array mixed-array the merged array
     */
    public function merge_vars($newVars2bind)
    {
        return (is_array($newVars2bind)) ? array_merge($this->vars2bind, $newVars2bind) : $this->vars2bind;
    }

    /**
     *  Abstraction of Oracle error capture process & associated Exception throwing
     *
     * @param $o_err    Oracle_Error_Object
     * @param $bind_varname string  the name of the failed variable
     * @param $message_preamble string  the message for the Exception
     * @param $code integer the Exception code
     * @throws Exception
     */
    protected function _throwBindingError($o_err, $bind_varname, $message_preamble, $code)
    {
        $err = $o_err['message'] . ';<br/>' . $bind_varname . '<br/>' . 'SQL was: ' . $o_err['sqltext'] . '<br/>';
        $message = preg_replace('~[[:cntrl:]]~', '', $err);
        $this->ci['errorMessage'] = $message;
        $this->ci['errorCode'] = htmlentities($o_err['code']);
        throw new Exception($message_preamble, $code);
    }

    /**
     * Handles variable binding (new version 2020.04.04!)
     *
     * branches on scalar variables vs. arrayed parameters, and simple arrays vs. compound (length, type, value) arrays
     *
     * @param statement $stmt         the Oracle statement object
     * @param string    $bind_varname the variable name to bind
     * @param array     $bind_value   the value of the variable to bind, in this case a compound array of length, type, value (if any)
     * @return bool     TRUE for bind success, FALSE for bind failure
     * @throws Exception
     * @link https://github.com/php/php-src/tree/master/ext/oci8/tests all the best documentation for bind_array_by_name and oci_collection are in these docs
     */
    protected function _bind_package_parameter($stmt, $bind_varname, $bind_value)
    {
        $var_bind_placeholder = ":$bind_varname";
        if(is_array($bind_value)){//treat arrayed values differently
            $is_outvar = $this->_is_outVar($bind_varname);
            if($is_outvar){//treat OUT variables differently especially as they must be compound values
                $binding_info = $this->_process_outvar($bind_value);
                $this->$bind_varname = $binding_info['value']; //this allows direct access to the OUT variable as a class variable
                //only need set length & type for OUT params:
                return $this->_bind_value($stmt, $bind_varname, $var_bind_placeholder, $binding_info);
            }
            if($this->_is_compoundVar($bind_value)){//treat compound IN values differently
                $binding_info = $this->_process_compound_array($bind_value, FALSE);
                $this->$bind_varname = $binding_info['value']; //assigned to class variable out of convenience
                return $this->_bind_value($stmt, $bind_varname, $var_bind_placeholder, $binding_info);
            }
            $binding_info['value'] = $this->$bind_varname = $this->_handle_array_value($bind_value);  //assigned to class variable out of convenience
            $binding_info['type'] = $this->_determine_SQLT_type($binding_info, FALSE); //get type
            $binding_info['length'] = $this->_determine_length($binding_info, FALSE );//get length
            return $this->_bind_value($stmt, $bind_varname, $var_bind_placeholder, $binding_info);
        }
        $this->$bind_varname = $bind_value;
        return $this->_bind_scalar_value($stmt, $bind_varname, $var_bind_placeholder);
    }

    /**
     * Implements OCI bind_by_name to bind the scalar variable to the statement
     *
     * @param statement $stmt                 the Oracle statement object
     * @param string    $bind_varname         the variable name to bind
     * @param string    $var_bind_placeholder the placeholder string
     * @param null|integer      $bind_length    the length of the scalar variable to bind (optional)
     * @param null|integer      $bind_type      the type of the scalar variable to bind (optional)
     * @return bool     TRUE for bind success, FALSE for bind failure
     * @throws Exception
     */
    protected function _bind_scalar_value($stmt, $bind_varname, $var_bind_placeholder, $bind_length = NULL, $bind_type = NULL)
    {
        if(( ! $bind_length) AND ( !  $bind_type)){
            $boundVar = oci_bind_by_name($stmt, $var_bind_placeholder, $this->$bind_varname); //let oracle decide length and type for normal IN parameters
        }
        else{
            $boundVar = oci_bind_by_name($stmt, $var_bind_placeholder, $this->$bind_varname, $bind_length, $bind_type);//typically OUT parameters need type &/or length defined
        }
        if ($o_err = oci_error($stmt)) {
            $this->_throwBindingError($o_err, $bind_varname, 'Oracle bind by name failed!', 523);
        }
        return $boundVar;
    }

    /**
     * Uses oci_bind_array_by_name to bind the arrayed variable
     *
     * @param statement $stmt                 the Oracle statement object
     * @param string    $bind_varname         the variable name to bind
     * @param string    $var_bind_placeholder the placeholder string
     * @param  array    $bind_info              the array of bind info; length, type, value
     * @return bool     TRUE on bind success, FALSE on failure
     * @throws Exception
     */
    protected function _bind_arrayed_value($stmt, $bind_varname, $var_bind_placeholder, $bind_info)
    {
        //check this vs the docu
        $t_length = $bind_info['length']['max_table_length'];
        $i_length = $bind_info['length']['max_item_length'];
        $type = $bind_info['type'];
        $boundVar = oci_bind_array_by_name($stmt, $var_bind_placeholder, $this->$bind_varname, $t_length, $i_length, $type);
        if ($o_err = oci_error($stmt)) {
            $this->_throwBindingError($o_err, $bind_info, 'Oracle bind by name failed!', 523);
        }
        return $boundVar;
    }

    /**
     * Processes the binding of values
     *
     * includes arrayed values, null values in arrays, empty arrays and custom collection handling
     * This method essentially brokers the binding of each variable
     *
     * @param statement $stmt                 the Oracle statement object
     * @param string    $bind_varname         the variable name to bind
     * @param string $var_bind_placeholder the placeholder string
     * @param array  $bind_info            the array of bind info; length, type, value
     * @return bool TRUE on bind success, FALSE on failure
     * @throws Exception
     */
    protected function _bind_value($stmt, $bind_varname, $var_bind_placeholder, $bind_info)
    {
        if(is_array($bind_info['value'])){//treat arrayed values differently
            $custom_type = $this->_check_4_customType($bind_info); //should support custom declared types in the form of schema.type
            if(is_array($custom_type)){
                $collection = $this->_create_collection($bind_info);
                $this->$bind_varname = $collection;
                return $this->_bind_collection($stmt,$bind_varname,  $var_bind_placeholder);
            }
            if($this->_check_4nulls($bind_info['value'])){//treat arrayed values with a NULL value or an empty array differently
                $bind_info['value'] = $this->_handle_arrayedValues_wnulls($bind_info);
            }//bind the array without nulls, the easy way:
            $this->$bind_varname = $bind_info['value'];
            return $this->_bind_arrayed_value($stmt, $bind_varname, $var_bind_placeholder, $bind_info);
        }
        return $this->_bind_scalar_value($stmt, $bind_varname, $var_bind_placeholder, $bind_info['length'], $bind_info['type']);
    }

    /**
     * Binds custom collection types
     *
     * @param statement $stmt                 the Oracle statement object
     * @param string    $bind_varname         the variable name to bind
     * @param string $var_bind_placeholder the placeholder string
     * @return bool
     * @throws Exception
     */
    protected function _bind_collection($stmt, $bind_varname, $var_bind_placeholder)
    {
        // Bind the collection to the parameter
        $boundvar = oci_bind_by_name($stmt,$var_bind_placeholder, $this->$bind_varname,-1,OCI_B_SQLT_NTY);
        if ($o_err = oci_error($stmt)) {
            $this->_throwBindingError($o_err, $bind_varname, 'Oracle bind collection failed!', 525);
        }
        return $boundvar;
    }
}