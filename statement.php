<?php
namespace \rastatech\dbal;
/**
 * Abstraction of the Oracle statement parsing process / statement resource and related functionality for better maintainability/readability
 * 
 * @package URL_ShortR
 * @subpackage dbal
 * @author todd.hochman
 * @uses dbal_configurator a trait containing some configuration setting code that is used across several of the above classes
 * @todo this whole dbal might need to be moved in namespace to accomodate MySQL dbal if/when we make one of those
 */
class statement
{    
    /**
    *
    * @var Container_object    the Dependency Injection Container  
    */
    protected $ci;
    
    /**
    *
    * @var Resource the parsed statement
    */
    public $stmt;  
    
    /**
    *
    * @var string the SQL to execute 
    */
    public $sql = '';
    
    /**
    *
    * @var string-array array of regexes for determining the type of sql statement to process differentially
    * currently ONLY the following types of SQL statement are supported:
    * -packaged procedure or function call; fully qualified package name only
    * -packaged procedure or function call; verbose (complete) package call
    * -SELECT pass-thru query
    * -DELETE pass-thru query
    * -UPDATE pass-thru query
    * -INSERT pass-thru query
    * @see $_sqlType
    * @see config/database.php
    */
    private $__sqltypes;    
    
    /**
    *
    * @var integer the type of sql we are going to execute
    * can be one of three types:
    * -0 the fully qualified name of the package; we can build the rest of it in this class
    * -1 a functional complete package call
    * -2 a standard CRUD call via pass-thru SQL
    * @see $__sqltypes
    */
    protected $_sqlType;    
    
    use configurator;
    
    /**
    * creates the Oracle statement object; 
    * factored out to make the \dbal\main smaller
    * 
    * @param Slim/Container $ci The slim Dependency Injection Container
    * @param string $model_sql_elements   the specific configs from the Model that extends \dbal\main
    * @throws \Exception
    */
    public function __construct($ci, $model_sql_elements)
    {
        $this->ci = $ci;
        $loadedConfigs = $this->_get_configs($model_sql_elements);
        $this->_assign2classVars($loadedConfigs);
    }

    /**
    * parse the sql statement for Oracle's purposes
    * 
    * @return Oracle the oracle object for chaining operations
    * @param Oracle_Connection_Resourece $conn the oracle connection to use
    */
    public function parse_statement($conn)
    {        
////                $conn = oci_connect('URL_SHORTS_USER', 'dfwmnewd', '(DESCRIPTION=(ADDRESS_LIST=(ADDRESS=(PROTOCOL=TCP)(HOST=oradbdev.nmenv.state.nm.us)(PORT=1521)))(CONNECT_DATA=(SERVICE_NAME=xeidd.nmenv.state.nm.us)))');
//
//        echo get_resource_type($conn);
////        oci_close($conn);
////        die(var_dump($conn));
//        print_r($conn); 
//        if( ! $conn){
//           $conn = $this->
//        }
//        if($conn === NULL){
//            echo 'conn is null!';
//        }
//        
        $this->stmt = oci_parse($conn, $this->sql);
//        echo 'made it past parse!<br/>';
//        die(var_dump($this->stmt));
        if( ! $this->stmt)
        {
            $exception = oci_error($conn);
            throw new \Exception('oci parse failed! {' . htmlentities($exception->getCode()) . ': ' . htmlentities($exception->getMessage()) . '}');
        }
        return $this;
    }    
    
    /**
    * Gets the type of SQL we're prcoessing
    * 
    * @return string
    */
    public function get_sqlType()
    {
        if( ! $this->_sqlType){
            return $this->_match_sqlType();
        }
        return $this->_sqlType;
    }    
    
    /**
    * figures out the type of SQL we have so we can process accordingly
    * choices are:
    * #a valid package invocation string
    * #a valid pass-thru SQL statement
    * 
    * There was a 3rd type @ one point but that is no longer supported
    * 
    * @return string the type of SQL to process
    * @throws \Exception if we can't figure out the type of sql this is
    */
    protected function _match_sqlType()
    {
        if(is_array($this->__sqltypes)){
            foreach($this->__sqltypes as $type => $regex2checkvs){
                $pattern = '@' . $regex2checkvs . '@is'; //delimit pattern & make case-insensitive
                $match = preg_match($pattern, $this->sql);
                if($match){
                    $this->_sqlType = $type;
                    return $this->_sqlType;
                }
            }
        }
        throw new \Exception('Unable to match SQL type! Cannot process SQL string: <br/>' . $this->sql, 519);
    }    
    
    /**
    * execute the passed statement
    * 
    * @param	resource 	$outcursor_obj	the optional resource to execute upon, whether cursor object or parsed statement; defaults to $stmt
    * @throws \Exception	exception on failure
    * @return Oracle the oracle object for chaining operations
    */
    public function execute_statement($outcursor_obj = FALSE)
    {
        if(( ! $outcursor_obj) OR (($outcursor_obj->out_cursor) AND ( ! is_array($outcursor_obj->out_cursor)))){
            $executeOn = ( ! $outcursor_obj) ? $this->stmt : $outcursor_obj->out_cursor;
            $success = oci_execute($executeOn);
        }
        elseif(is_array($outcursor_obj->out_cursor)){
            foreach($outcursor_obj->out_cursor as $outcursorname => $outcursor){
                $success[] = oci_execute($outcursor);
            }
        }
        if(( ! isset($success)) OR ( ! $success) OR ((is_array($success)) AND (in_array(FALSE, $success)))){
            $exception = oci_error($this->stmt);
            throw new \Exception('oci execute failed! Oracle Error:{' . htmlentities($exception['code']) . ': ' . htmlentities($exception['message']) . '}', 513);
        }
        return $this;
    }    
    
    /**
    * Frees resources after use
    *
    * @throws \Exception if oci_free_statement fails
    * @return Oracle the oracle object for chaining operations
    */
    public function clean_up_after()
    {
        if($this->stmt){
                $success = oci_free_statement($this->stmt);
                $success AND ($this->stmt = NULL);
        }
        if((isset($success)) AND ( ! $success)){
            $err = oci_error($this->stmt);
            $msg = 'oci close failed! Oracle Error:{' . htmlentities($err['code']) . ': ' . htmlentities($err['message'] . '}', 526);
            throw new \Exception($msg);
        }
        return $this;
    }    
}