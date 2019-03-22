<?php
namespace \rastatech\dbal;
/**
 *
 * Simplified Oracle database driver specifically for NMED purposes.
 * 
 *
 * This driverlet is designed specifically to our heavy use of PL/SQL packages.
 * This is *NOT* a full-blown DBAL for Oracle; use Doctrine DBAL or something if that is what you need.
 * 
 * This is the main DBAL class. It leverages several sub-classes mentioned below. 
 *  
 *
 * @package URL_ShortR
 * @subpackage dbal
 * @author todd.hochman
 * @uses dbal_connection the Oracle connection object
 * @uses dbal_statement the Oracle statement object
 * @uses dbal_cursor the Oracle cursor object
 * @uses dbal_bindings the Oracle bindings object
 * @uses dbal_result the Oracle result object
 * @uses dbal_configurator a trait containing some configuration setting code that is used across several of the above classes
 * 
 * @todo this whole dbal might need to be moved in namespace to accomodate MySQL dbal if/when we make one of those
 */
class main
{
    /**
     *
     * @var Container_object    the Dependency Injection Container  
     */
    protected $ci;
        
    /**
    *
    * @var dbal_cursor	the cursor abstraction object
    *
    */    
    public $outcursorObj;
    
    /**
    *
    * @var Resource the connection resource
    */
    public $connectionObj;
    
    /**
    *
    * @var Resource the statement resource 
    */
    public $statementObj;
    
    /**
    *
    * @var dbal_bindings object to abstract the statement binding processes 
    */
    public $bindingsObj;
    
    /**
     *
     * @var dbal_result object to abstract the SQL results and whatever we may want to do with them 
     */
    public $resultObj;
    
    /**
    *
    * @var string-array list of attributes to exclude from the magic __set() function 
    */
    protected  $_exclusions = array('result',
                                    'conn',
                                    'stmt',
                                    'sqlType',
                                    'cnx_regex',
                                );

    /**
     *
     * @var string  the string used for the DBAL to identify PL/SQL OUT variables; this string must be a suffix to the variable name
     */
    protected $_outvarIDstring = '';

/**
    * some factored-out-for-reuse configuration setting code in the form of a Trait
    */
    use configurator;
    
    /**
    * potentially allows you to override or add additional configuration / functionality upon construction if you extend this class with your model
    * 
    * @param Slim/Container $ci The slim Dependency Injection Container
    * @param integer the connection flavor -- see /dbal/connection
    * 
    * @see dbconfigs.php
    */
    public function __construct($ci, $cnx_flavor = NULL)
    {
        $this->ci = $ci; //establish the container object; currently used for the DB configs only, but anything we need from the routes or anyplace we can get from here
        $this->connect($cnx_flavor);//uses magic __call to abstract the connection process
    }
    
    /**
     * Function to validate payloads for POST, PUT operations vs. what is described in the models that are children of this class. 
     * 
     * @param mixed-array $payload    the array of key/value pairs that comprises the PUT or POST payload
     * @param mixed-array $model      the array of key/value pairs for the PUT or POST that are defined in the model
     * @return boolean  TRUE if the two arrays properly match
     * @throws \Exception    if they don't match
     */
    protected function _validatePayload(array $payload, array $model){
        $payload_keys = array_keys($payload);
        $model_keys = array_keys($model);
        foreach($model_keys as $key){
            if( ! strpos($key, $this->_outvarIDstring)){ //don't include the return vars
                $model_keys_noOutvar[] = $key;
            }
        }
        $diffs = array_diff($model_keys_noOutvar, $payload_keys);
        if( ! empty($diffs)){
            if(count($diffs) == count($model_keys)){//if they are totally different
                throw new \Exception('payload differs from model! Cannot process data. Differences were: <br/>{{' . var_export($diffs, TRUE) . '}}<br/>', 514);
            }        
        }
        return $this->_cleanVars($model_keys, $payload);
    }
    
    /**
     * cleanse the values of stuff that will make Oracle explode
     * 
     * @param string-array $model_keys  the variable names of the model
     * @param mixed-array $payload  the data submitted for POST or PUT
     * @return mixed-array  
     * @TODO fix the $numberRegEx portion to account for float, double, single, integer
     */
    protected function _cleanVars($model_keys, $payload){
        $cleaned_vars = [];
        $standardized_payload = $this->_standardizePayload($payload);
        foreach($model_keys as $key){
            if(strpos($key, $this->_outvarIDstring)){
                continue;
            }
            if($this->_testPayload($standardized_payload, $key)){
                $cleaned_vars[$key] = $standardized_payload[$key];
                continue;
            }
            if( ! array_key_exists($key, $standardized_payload)){
                $cleaned_vars[$key] = NULL;//add the key to the payload
                continue;
            }
            $numberRegEx = '~^\$?((\d+,?\d+\.\d{2})|(0\.00)|(\d+))~';
            $value = (preg_match($numberRegEx, $standardized_payload[$key])) ? preg_replace("/[^0-9.]/", "", $standardized_payload[$key]) : $standardized_payload[$key];
            $cleaned_vars[$key] = $value;
        }
        return $cleaned_vars;
    }
    
    /**
     * makes sure all array keys are lowercased to avoid missing key issues
     * 
     * @param mixed-array $payload the data to standardize
     * @return mixed-array  the payload with lower-cased keys
     */
    protected function _standardizePayload($payload){
        $standardized_payload = [];
        foreach($payload as $key => $value){
            $standardized_payload[strtolower($key)] = $value;
        }
        return $standardized_payload;
    }
    
    /**
     * determines if the array element is a special case for cleaning purposes.
     * 
     * @param mixed-array $payload the data
     * @param string $key   the key to check
     * @return boolean TRUE if it matches the special case
     */
    protected function _testPayload($payload, $key){
        $exempt_vars = ['zipcode', 'id', 'phone'];
        $dateRegEx = '~(\d{1,4}[\/.-](\d{1,2}|\w{3})[\/.-]\d{2,4})~';
        if(strpos($key, '_id')){
            if(array_key_exists($key, $payload)){
                return TRUE;
            }
        }
        if((in_array($key, $exempt_vars)) OR ((array_key_exists($key, $payload)) AND (is_null($payload[$key])))
                    OR ((array_key_exists($key, $payload)) AND (preg_match($dateRegEx, $payload[$key])))){
            return TRUE;
        }
    }
    
    /**
     * converts numeric strings to actual numbers
     * 
     * @param number $num  the number-as-string
     * @return integer|float
     */
    protected function tofloat($num) {
        $dotPos = strrpos($num, '.');
        $commaPos = strrpos($num, ',');
        $sep = (($dotPos > $commaPos) AND $dotPos) ? $dotPos : ((($commaPos > $dotPos) AND $commaPos) ? $commaPos : FALSE);
        if(( ! $sep) OR ( ! $dotPos)){
            $return = intval(preg_replace("/[^0-9]/", "", $num));
        }
        $return = floatval(preg_replace("/[^0-9]/", "", substr($num, 0, $sep)) . '.' . preg_replace("/[^0-9]/", "", substr($num, $sep+1, strlen($num))));
        $finalReturn = ($return == '0.00') ? 0 : $return;
        return $return;
    }


    /**
     * compilation function to wrap the whole DB process into a single, simple call
     * This uses magic __call methods to abstract the various pieces of connection
     * 
     * @param boolean|mixed-array $bindVars the array of variables to bind
     * @param NULL|string the return variable if other than the cursor
     * 
     * @return mixed either the cursor object if only one, or the return var if only one
     */
    public function run_sql($bindVars = FALSE, $outvar = NULL)
    {
//        die(var_dump($this->connectionObj->conn));
        $this->parse();
        $this->bind_vars($bindVars);
        $this->bind_cursor(); 
        $this->execute();
        $resultArray = ($outvar) ? $this->result($outvar) : $this->result();
        $this->commit();
        if( ! $this->cleanup()){
            $this->ci->logger->error('DBAL cleanup failed!');
        }
        return $resultArray;
    }
    
    /**
    * Setter function; will not set a property if it's a member of the set of excluded methods.
    *
    * @param string	$key	the attribute to change
    * @param mixed	$value	the value to which to change it
    * @return mixed|boolean	will return the value on a successful value assignment, FALSE othewise
    */    
    public function __set($key, $value)
    {
        if((property_exists(get_class($this), $key)) AND ( ! in_array($key, $this->_exclusions))){
            $return = $this->$key = $value;
        }
        return (isset($return)) ? $return : FALSE;
    } 
    
    /**
    * Getter function to access needed attributes
    * 
    * @param string $key   the attribute name being sought
    * @return mixed the requested attribute
    */
    public function __get($key){
        switch($key){
            case 'sql':
                $return = ($this->statementObj) ? $this->statementObj->sql : NULL;
                break;
            case 'sqltype':
                $return = ($this->statementObj) ? $this->statementObj->get_sqlType() : NULL;
                break;
            case 'conn':
                $return = ($this->connectionObj) ? $this->connectionObj->conn : NULL;
                break;
            case 'stmt':
                $return = ($this->statementObj) ? $this->statementObj->stmt : NULL;
                break;
            case 'outCursor':
                $return = ($this->outcursorObj) ? $this->outcursorObj->out_cursor : NULL;
                break;
            case 'bindVars':
                $return = ($this->bindingsObj) ? $this->bindingsObj->bound_vars : NULL;
                break;      
            default:
                $return = (property_exists($this, $key)) ? $this->$key : (property_exists($this->bindingsObj, $key)) ? $this->bindingsObj->$key : NULL;
                break;            //used to retrieve non-cursor OUT parameters from PL/SQL procedures
        }
        return $return;
    }
    
    /**
     * Magic __call function to handle abstracted object access
     * 
     * Use this function to give \dbal\main (and objects that extend it) access to dependent objects, e.g. the connection, statement or bindings objects 
     * the *_sql_elements* attribute comes from the models that extend this class
     * 
     * @param string $name  the faux method call 
     * @param mixed-array $arguments    optional additional parameters; each param will be an additional element of the $arguments array
     * @return mixed    various returns include the statement resource, the connection, or object instances depending....
     */
    public function __call($name, $arguments)
    {     
        switch($name){
            case 'bind_vars':
                $this->bindingsObj = ( ! $this->bindingsObj) ? new bindings($this->ci, $this->_sql_elements) : $this->bindingsObj;
                $this->bindingsObj->vars2bind = $this->_sql_elements['bind_vars'];
                $vars2bind = $this->bindingsObj->merge_vars($arguments[0]);
                $sqltype = $this->sqltype;
                $return = $this->bindingsObj->bind_vars($sqltype, $vars2bind, $this->stmt);
                break;
            case 'connect':
                $this->connectionObj = ( ! $this->connectionObj) ? new connection($this->ci, $this->_sql_elements) : $this->connectionObj;
                $connectFlavor = (($arguments) AND (array_key_exists(0, $arguments)) AND (is_int($arguments[0])) AND ($arguments[0] < 3)) ? $arguments[0] : NULL;
                $return = $this->connectionObj->connect_2db($connectFlavor);
                break;
            case 'parse':
                $this->statementObj = ( ! $this->statementObj) ? new statement($this->ci, $this->_sql_elements) : $this->statementObj;
                $return = $this->statementObj->parse_statement($this->conn, $this->_sql_elements['sql'])->stmt;
                break;  
            case 'bind_cursor':
                $this->outcursorObj = ( ! $this->outcursorObj) ? new cursor($this->ci, $this->_sql_elements) : $this->outcursorObj;
                if($this->outCursor){
                    $this->outcursorObj->create_cursor($this->conn);
                    $return = $this->outcursorObj->bind_cursor($this->stmt);
                }
                $return = NULL;
                break;
            case 'execute':
                $executed = $this->statementObj->execute_statement();//execute the statement
                $return = ($this->outCursor) ? $this->statementObj->execute_statement($this->outcursorObj): $executed; //execute the OUT CURSOR(s) if any
                break;
            case 'commit':
                $return = $this->connectionObj->commit();
                break;
            case 'result':
                $model_sql_elements = (($arguments) AND (array_key_exists(1, $arguments))) ? $arguments[1] : [];
                $result_param = (($arguments) AND ($arguments[0])) ? $arguments[0] : NULL;//set result var as an arg for non-cursor results
                $this->resultObj = ( ! $this->resultObj) ? new result($this->ci, $result_param, $model_sql_elements) : $this->resultObj;
                
                if($this->outCursor){
                    $resource2fetch = $this->outCursor;
                    return $this->resultObj->get_result($resource2fetch);
                }
                $return = $this->bindingsObj->$result_param;
//                
//                die(var_dump($this->bindingsObj));
                break;
            case 'cleanup':
                $cleanStatement = $this->statementObj->clean_up_after();
                $cleanConnection = $this->connectionObj->clean_up_after();
                $return = (( ! $cleanStatement) OR ( ! $cleanConnection)) ? FALSE : TRUE;
                break;
            default:
                throw new \Exception("the $name function is not supported");
        }
        return $return;
    }
}