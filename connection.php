<?php
namespace rastatech\odbal;
use \Exception;

/**
 * Abstraction of the Oracle connection process / resource and related functionality for better maintainability/readability
 * 
 * @package \ODBAL
 * @author todd.hochman
 * @uses dbal_configurator a trait containing some configuration setting code that is used across several of the above classes
 * @todo this whole dbal might need to be moved in namespace to accomodate MySQL dbal if/when we make one of those
 */
class connection
{
    /**
     *
     * @var Container_object    the Dependency Injection Container  
     */
    public $ci;

    /**
    *
    * @var Resource the connection resource
    */
    public $conn;
    
    /**
    *
    * @var boolean	type of connection to make:
    * -0: regular oci_connect
    * -1: permanent connection (oci_pconnect)
    * -2: new (separate) connection (oci_new_connect)
    */
    public $connect_flavor = 0;    
    
    /**
    *
    * @var string  the user name 
    */
    protected $_user = '';
 
    /**
    *
    * @var string  the password 
    */    
    protected $_pw = '';
    
    /**
    *
    * @var string  the connection string for attaching to the Oracle DB 
    */
    protected $_cnx_str = '';
    
    /**
    *
    * @var string RegEx to prevent bad connection strings from being sent
    * @see config/database.php
    */
    protected $_cnx_regex;
    
    /**
    * Configuration loading Trait
    */
    use configurator;
    
    /**
    * creates the oracle connection object; 
    * 
    * refactored out of \odbal\main to make that class less enormous
    * 
    * @param Container $ci The Dependency Injection Container
    * @param mixed-array    $model_sql_elements   the array of configuration items
    * @uses dbal_configurator 
    */
    public function __construct($ci, $model_sql_elements)
    {
        $this->ci = $ci;
        $loadedConfigs = $this->_get_configs($model_sql_elements);
        $this->_assign2classVars($loadedConfigs);
    }

    /**
     * instantiate database connection
     * set both instance and static connection variables to prevent multiple connects
     *
     * @param integer $connectFlavor the type of connection:
     *                               -0: regular oci_connect (default)
     *                               -1: new (separate) connection (oci_new_connect)
     *                               -2: permanent connection (oci_pconnect)
     * @return connection the connection object for chaining operations
     * @throws Exception
     * @uses show_error()
     */
    public function connect_2db($connectFlavor = NULL)
    {
        if(( ! $this->conn) AND ($this->_validateConnection($this->_cnx_str))){
            $connectFlavor = (is_null($connectFlavor)) ? $this->connect_flavor : $connectFlavor;
            switch($connectFlavor){
                case 2://permanent
                    $this->conn = oci_pconnect($this->_user, $this->_pw, $this->_cnx_str);
                    break;
                case 1://new (separate) connection
                    $this->conn = oci_new_connect($this->_user, $this->_pw, $this->_cnx_str);
                    break;
                default://== 0
                    $this->conn = oci_connect($this->_user, $this->_pw, $this->_cnx_str);
                    break;
            }
            if( ! $this->conn){
                $exception = oci_error();
                $msg = 'Cannot connect - check credentials and connection string? {' . htmlentities($exception['message']) . '}';
                $msg .= '; connection string used was: ' . $this->_cnx_str;
                $message = preg_replace('~[[:cntrl:]]~', '', $msg);
                $this->ci['errorMessage'] = $message;
                $this->ci['errorCode'] = htmlentities($exception['code']);
                throw new Exception('Oracle connection failed!',516);
            }
        }
        return $this->conn;
    }
    
    /**
    * tests the connection string against RegEx for validity
     * 
    * @param string $cnxStr the connection string
    * @return boolean
    * @throws Exception
    */
    protected function _validateConnection($cnxStr)
    {
        $cnxString = [];
        if( ! preg_match($this->_cnx_regex, $cnxStr, $cnxString)){
            $msg = 'connection string not valid!<br/> Regex: ' . $this->_cnx_regex . '<br/>connection string =' . $cnxStr;
            $this->ci['errorMessage'] = $msg;
            $this->ci['errorCode'] = 515;
            throw new Exception('Connection string not valid', 515);
        }
        if($this->_cnx_str != $cnxString[0]){
            $this->_cnx_str = $cnxString[0]; //only want the connection string part;
        }
        return TRUE;
    }

    /**
     * actually commit to the database via oci_commit;
     *
     * @return connection the connection object for chaining operations
     * @throws Exception on commit fail
     */
    public function commit()
    {
        $success = oci_commit($this->conn);
        if( ! $success){
                $err = oci_error($this->conn);
                $message = preg_replace('~[[:cntrl:]]~', '', htmlentities($err['message']));
                $this->ci['errorMessage'] = $message;
                $this->ci['errorCode'] = htmlentities($err['code']);
                throw new Exception('oci commit failed!', 524);
        }
        return $this;
    }

    /**
     * Frees resources after use
     *
     * @return connection the connection object for chaining operations
     * @throws Exception if oci_close fails
     */
    public function clean_up_after()
    {
        If($this->conn){
            $success = oci_close($this->conn);
        }
        if((isset($success)) AND ( ! $success)){
            $err = oci_error($this->conn);
            $message = preg_replace('~[[:cntrl:]]~', '', htmlentities($err['message']));
            $this->ci['errorMessage'] = $message;
            $this->ci['errorCode'] = htmlentities($err['code']);
            throw new Exception('oci close failed!', 523);
        }
        return $this;
    }    
}