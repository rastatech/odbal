<?php
namespace rastatech\odbal;
use \Exception;

/**
 * Abstraction of the Oracle statement parsing process / statement resource and related functionality for better maintainability/readability
 *
 * @package \ODBAL
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
     * -packaged procedure or function call; fully qualified package name only if no parameters
     * -packaged procedure or function call; fully qualified, verbose (complete) package call with binding placeholders
     * -SELECT pass-thru query (not tested)
     * -DELETE pass-thru query (not tested)
     * -UPDATE pass-thru query (not tested)
     * -INSERT pass-thru query (not tested)
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
     * factored out to make the \odbal\main smaller
     *
     * @param Slim/Container $ci The slim Dependency Injection Container
     * @param string $model_sql_elements   the specific configs from the Model that extends \odbal\main
     * @throws Exception
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
     * @param Oracle_Connection_Resource $conn the oracle connection to use
     * @return statement the oracle statement object for chaining operations
     *
     * @throws Exception
     */
    public function parse_statement($conn)
    {
        $this->stmt = oci_parse($conn, $this->sql);
        if( ! $this->stmt)
        {
            $exception = oci_error($conn);
            $message = preg_replace('~[[:cntrl:]]~', '', htmlentities($exception['message']));
            $this->ci['errorMessage'] = $message;
            $this->ci['errorCode'] = htmlentities($exception['code']);
            throw new Exception('oci parse failed!', 518);
        }
        return $this;
    }

    /**
     * Gets the type of SQL we're prcoessing
     *
     * @return string
     * @throws Exception
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
     * #a valid pass-thru SQL statement -- currently unsupported as of 2019.10.29
     *
     * @return string the type of SQL to process
     * @throws Exception if we can't figure out the type of sql this is
     */
    protected function _match_sqlType()
    {
//        var_dump($this->sql);
//        echo "is the bloody SQL at the statement object match type<br/>";
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
        $this->ci['errorMessage'] = ';<br/> Cannot process SQL string: <br/>' . $this->sql;
        $this->ci['errorCode'] = 519;
        throw new Exception('Unable to match SQL type!', 516);
    }

    /**
     * execute the passed statement, executing CURSOR objects 1st
     *
     * @param bool $outcursor_obj the optional resource to execute upon, whether cursor object or parsed statement; defaults to $stmt
     * @return statement the oracle object for chaining operations
     * @throws Exception exception on failure
     */
    public function execute_statement($outcursor_obj = FALSE)
    {
//        echo "executing outcursor? " . (($outcursor_obj) ? 'true!' : 'false.');
        if(( ! $outcursor_obj) OR (($outcursor_obj->out_cursor) AND ( ! is_array($outcursor_obj->out_cursor)))){
            $executeOn = ( ! $outcursor_obj) ? $this->stmt : $outcursor_obj->out_cursor;
            $success = $this->_safe_execute($executeOn);
        }
        elseif(is_array($outcursor_obj->out_cursor)){//handles an array of OUT CURSORs
            foreach($outcursor_obj->out_cursor as $outcursorname => $outcursor){
                $success[] = $this->_safe_execute($outcursor);
            }
        }
        if(( ! isset($success)) OR ( ! $success) OR ((is_array($success)) AND (in_array(FALSE, $success)))){
            $exception = oci_error($this->stmt);
            $message = preg_replace('~[[:cntrl:]]~', '', htmlentities($exception['message']));
            $this->ci['errorMessage'] = $message;
            $this->ci['errorCode'] = htmlentities($exception['code']);
            throw new Exception('oci_execute failed!', 513);
        }
        return $this;
    }

    /**
     * offloaded the oci_execute to its own function so we can try/catch
     *
     * @param Statement $executeOn the Oracle Statement / Cursor to execute upon
     * @return bool the result of the execution
     * @throws Exception on OCI error
     */
    protected function _safe_execute($executeOn)
    {
        try {
            $success = oci_execute($executeOn);
//            echo ($success) ? "execute succeeded" : 'execute failed';
        }
        catch (exception $e) {
            $e = oci_error($this->stmt);
            $message = preg_replace('~[[:cntrl:]]~', '', $e->getMessage());
            $this->ci['errorMessage'] = $message;
            $this->ci['errorCode'] = htmlentities($e['code']);
            throw new Exception('oci execute failed!', 514, $e);
        }
        return $success;
    }

    /**
     * Frees resources after use
     *
     * @return statement the oracle object for chaining operations
     * @throws Exception if oci_free_statement fails
     */
    public function clean_up_after()
    {
        if($this->stmt){
            $success = oci_free_statement($this->stmt);
            $success AND ($this->stmt = NULL);
        }
        if((isset($success)) AND ( ! $success)){
            $err = oci_error($this->stmt);
            $message = preg_replace('~[[:cntrl:]]~', '', htmlentities($err['message']));
            $this->ci['errorMessage'] = $message;
            $this->ci['errorCode'] = htmlentities($err['code']);
            $msg = 'oci close failed!';
            throw new Exception($msg, 526);
        }
        return $this;
    }
}