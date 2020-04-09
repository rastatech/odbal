<?php
namespace rastatech\odbal;
use \Exception;

/**
 *
 * Simplified Oracle database driver specifically for Package execution purposes.
 * 
 *
 * This driverlet is designed specifically to our heavy use of PL/SQL packages.
 * This is *NOT* a full-blown DBAL for Oracle; use Doctrine DBAL or something if that is what you need.
 * 
 * This is the main DBAL class. It leverages several sub-classes mentioned below. 
 *  
 *
 * @package \ODBAL
 * @author todd.hochman
 * @uses dbal_connection the Oracle connection object
 * @uses dbal_statement the Oracle statement object
 * @uses dbal_cursor the Oracle cursor object
 * @uses dbal_bindings the Oracle bindings object
 * @uses dbal_result the Oracle result object
 * @uses dbal_configurator a trait containing some configuration setting code that is used across several of the above classes
 * 
 * @todo factor out all the Oracle-specific stuff to the Oracle model
 */
class main
{
    /**
     * @var string path to your configurations file;
     * defaults to the one in this same directory, but you can overwrite with your own, just be sure to use all the same array keys...
     */
    public $configs_path = 'db_configs.ini';

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
    * some factored-out-for-reuse configuration setting code in the form of a Trait
    */
    use configurator;

    /**
     * some factored-out-for-length payload handling code in the form of a Trait
     */
    use payload;
    
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
        if( ! $this->ci['odbal_configs']){
            $this->ci['odbal_configs'] = $this->get_configsFile( $this->ci->get('odbal'));
        }
        $this->connect($cnx_flavor);//uses magic __call to abstract the connection process
    }

    /**
     * compilation function to wrap the whole DB process into a single, simple call
     * This uses magic __call methods to abstract the various pieces of connection
     * 
     * @param boolean|mixed-array $bindVars the array of variables to bind
     * @param NULL|string the return variable(s) if other than the cursor
     * 
     * @return mixed either the cursor object(s) if only one, or the return var if only one
     */
    public function run_sql($bindVars = FALSE, $outvars = NULL)
    {
        $this->parse();//creates $this->statementObj via __call()
        $this->bind_vars($bindVars);
        $this->bind_cursor();
        $this->execute();
        $resultArray = ($outvars) ? $this->result($outvars) : $this->result();
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
     * Magic __call function as a means to simplify what would otherwise be numerous duplicatively-parametered functions
     *
     * Use this function to give \odbal\main (and objects that extend it) access to dependent objects, e.g. the connection, statement or bindings objects
     * the *_sql_elements* attribute comes from the models that extend this class
     *
     * @param string $name the faux method call
     * @param mixed-array $arguments    optional additional parameters; each param will be an additional element of the $arguments array
     * @return mixed    various returns include the statement resource, the connection, the result(s), or object instances depending....
     * @throws Exception
     */
    public function __call($name, $arguments)
    {     
        switch($name){
            case 'connect': //used by __construct()
                $this->connectionObj = ( ! $this->connectionObj) ? new connection($this->ci, $this->_sql_elements) : $this->connectionObj;
                //arguments in this case is the type of connection desired; defaults to standard which is also =0; 1 is a new connection; 2 is a permanent connection:
                $connectFlavor = (($arguments) AND (array_key_exists(0, $arguments)) AND (is_int($arguments[0])) AND ($arguments[0] < 3)) ? $arguments[0] : NULL;
                $return = $this->connectionObj->connect_2db($connectFlavor);
                $this->ci['conn'] = $this->connectionObj->conn; //added klugily to container as I ended up needing it to bind in certain circumstances
//                echo "dying on container connection: <br/>\n";
//                echo "dying on assigned connection: <br/>\n";
//                echo "dying on raw connection: <br/>\n";
//                die(var_export($this->connectionObj->conn, TRUE));
//                die(var_dump($return));
//                die(var_dump($this->ci->get('conn')));
                break;
            case 'parse':
                $this->statementObj = ( ! $this->statementObj) ? new statement($this->ci, $this->_sql_elements) : $this->statementObj;
                $this->statementObj->parse_statement($this->conn, $this->_sql_elements['sql']);//returns statement object w/ parsed statement
                $return = $this->statementObj->stmt;//returns statement object w/ parsed statement
                break;
            case 'bind_vars':
                $this->bindingsObj = ( ! $this->bindingsObj) ? new bindings($this->ci, $this->_sql_elements) : $this->bindingsObj;
                $this->bindingsObj->vars2bind = $this->_sql_elements['bind_vars'];
                //arguments in this case are additional variables to add to those that get processed:
                $vars2bind = $this->bindingsObj->merge_vars($arguments[0]);
                $sqltype = $this->sqltype;
                $return = $this->bindingsObj->bind_vars($sqltype, $vars2bind, $this->stmt);
                break;
            case 'bind_cursor':
                $this->outcursorObj = ( ! $this->outcursorObj) ? new cursor($this->ci, $this->_sql_elements) : $this->outcursorObj;
                if($this->outCursor){ //uses dynamic __get()
                    $this->outcursorObj->create_cursor($this->conn);
                    return $this->outcursorObj->bind_cursor($this->stmt);
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
                if($this->outCursor){//uses dynamic __get()
                    $resource2fetch = $this->outCursor;
                    return $this->resultObj->get_result($resource2fetch);
                }
                if($result_param){
                    if( ! is_array($result_param)){
                        return $this->bindingsObj->$result_param;
                    }
                    foreach ($result_param as $outvar_key){
                        $returns[$outvar_key] = $this->bindingsObj->$outvar_key;
                    }
                    return $returns;
                }
                $return = NULL;
                break;
            case 'cleanup':
                $return = (( ! $this->statementObj->clean_up_after()) OR ( ! $this->connectionObj->clean_up_after())) ? FALSE : TRUE;
                break;
            case 'validate'://changed the visibility of this method back & forth a coupla times
            case '_validatePayload'://changed the visibility of this method back & forth a coupla times
                if ($arguments){//arguments in this case are the payload to validate, and the model to validate against.
                    if(count($arguments != 2)){
                        throw new Exception("you must supply a payload and a model to validatePayload.");
                    }
                    $return = $this->validatePayload($arguments[0],  $arguments[1]);
                }
                break;
            default:
                throw new Exception("the $name function is not supported");
        }
        return isset($return) ? $return : NULL;
    }
}