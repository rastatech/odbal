<?php
namespace models;

use odbal\main;

/**
 * Model for DB ops, leverages Oracle DBAL
 * 
 * @package \ODBAL
 * @subpackage example_models
 * @author todd.hochman
 */
class example_model extends main
{
    /**
     *
     * @var sting-array the array of SQL elements organized by Stored Procedure
     */
     protected $_base_sql_elements = [  
            'put_url' =>    ['sql' => 'BEGIN :return_outvar := example_schema.example_pkg.puturl(:origurl, :shorturl); END;',
                        'bind_vars' 	=> array(
                                                    'origurl' => NULL,
                                                    'shorturl' => NULL,
                                                    'return_outvar' => ['length' => 8, 'type' => 'int', 'value' => NULL]
                                                    ),
                        'out_cursor'	=> NULL
                        ],
            'get_url' =>    ['sql' => 'BEGIN example_schema.example_pkg.geturl(:shorturl,:origurl_outvar); END;',
                            'bind_vars' 	=> array(
                                                        'shorturl' => NULL,
                                                        'origurl_outvar' => ['length' => 2000, 'type' => 'chr', 'value' => NULL]
                                                        ),
                            'out_cursor'	=> NULL
                        ],
            'check_short' => ['sql' => 'BEGIN :return_outvar := example_schema.example_pkg.checkShort(:shorturl); END;',
                            'bind_vars' 	=> array(
                                                        'shorturl' => NULL,
                                                        'return_outvar' => ['length' => 8, 'type' => 'int', 'value' => NULL]
                                                        ),
                            'out_cursor'	=> NULL
                        ],
            'check_url' => ['sql' => 'BEGIN :return_outvar := example_schema.example_pkg.checkurl(:url); END;',
                            'bind_vars' 	=> array(
                                                        'url' => NULL,
                                                        'return_outvar' => ['length' => 8, 'type' => 'int', 'value' => NULL]
                                                        ),
                            'out_cursor'	=> NULL
                        ],         
            'get_urlList' => ['sql' => 'BEGIN example_schema.example_pkg.get_urlList(:return_outcur); END;',
                            'bind_vars' 	=> array(),
                            'out_cursor'	=> 'return_outcur'                                    
                        ],
            'get_urlbyid' => ['sql' => 'BEGIN :return_outvar := example_schema.example_pkg.geturlbyid(:urlid); END;',
                            'bind_vars' 	=> array(
                                                        'urlid' => NULL,
                                                        'return_outvar' => ['length' => 2000, 'type' => 'chr', 'value' => NULL]
                                                        ),
                            'out_cursor'	=> NULL
                        ],     
            'get_shortbyid' => ['sql' => 'BEGIN :return_outvar := example_schema.example_pkg.getshortbyid(:urlid); END;',
                            'bind_vars' 	=> array(
                                                        'urlid' => NULL,
                                                        'return_outvar' => ['length' => 2000, 'type' => 'chr', 'value' => NULL]
                                                        ),
                            'out_cursor'	=> NULL
                        ],           
                                    ];
     
         protected $_sql_elements = [];


    /**
     *
     * @var string  the string used for the DBAL to identify PL/SQL OUT variables; this string must be a suffix to the variable name
     */
    protected $_outvarIDstring = '_outvar';
    
    /**
     * inserts a new URL + Shortened URL pair into the DB
     * 
     * @param string-array $URL2shorten the k/v pair of var name ('url') and the original URL to shorten
     * @return string the shortened URL
     */
    public function putURL(array $URL2shorten)
    {
        $this->_sql_elements = $this->_base_sql_elements['put_url'];
         //make DBAL call
        $vars_array = $this->validatePayload($URL2shorten, $this->_sql_elements['bind_vars'], TRUE); //will throw exception if it fails to validate
        $result = $this->run_sql($vars_array, 'return_outvar');
        return $result;
    }
        
    
    /**
     * takes a shortened url, looks that up in the DB to get the original URL. 
     * 
     * @param string-array $shortenedURL    the array of key -> value where value is the shortened URL
     * @return string the retrieved URL associated with the given key
     */
    public function getURL($shortenedURL)
    {
        $this->_sql_elements = $this->_base_sql_elements['get_url'];
        //make DBAL call
        $vars_array = $this->validatePayload($shortenedURL, $this->_sql_elements['bind_vars'], TRUE); //will throw exception if it fails to validate
        $result = $this->run_sql($vars_array, 'origurl');
        return $result;
    }

    /**
     * Determines whether or not a given short URL has already been used
     * 
     * @param string the shortened URL to check to see if it has been used 
     * @return integer whether the short URL has been used (returns the URL id) or not (returns 0) 
     */
    public function checkShort($URL2check)
    {
         $this->_sql_elements = $this->_base_sql_elements['check_short'];
         //make DBAL call
         $checkVars = ['shorturl' => $URL2check];
//         die(var_dump($checkVars));
        $vars_array = $this->validatePayload($checkVars, $this->_sql_elements['bind_vars'], TRUE); //will throw exception if it fails to validate
        $result = $this->run_sql($vars_array, 'return_outvar');
        return $result;       
    }
    
    /**
     * Determines whether or not a given original URL has already been shortened
     * 
     * @param string the original URL to check to see if it has been previously shortened 
     * @return integer whether the original URL has been shortened (returns the URL id) or not (returns 0) 
     */
    public function checkURL($URL2check)
    {
         $this->_sql_elements = $this->_base_sql_elements['check_url'];
         //make DBAL call
         $checkVars = ['url' => $URL2check];
//         die(var_dump($checkVars));
        $vars_array = $this->validatePayload($checkVars, $this->_sql_elements['bind_vars'], TRUE); //will throw exception if it fails to validate
        $result = $this->run_sql($vars_array, 'return_outvar');
        return $result;            
    }
    
    /**
     * 
     * @return CURSOR the array of ALL urls 
     */
    public function geturllist()
    {
        $this->_sql_elements = $this->_base_sql_elements['get_urlList'];
        $result = $this->run_sql();
        return $result;   
    }
    
    /**
     * retrieves a URL via the URL record ID
     * 
     * @param integer $urlID    the URL record ID
     * @return string   the original URL encoded
     */
    public function geturlbyid($urlID)
    {
         $this->_sql_elements = $this->_base_sql_elements['get_urlbyid'];
         //make DBAL call
         $checkVars = ['urlid' => $urlID];
//         die(var_dump($checkVars));
        $vars_array = $this->validatePayload($checkVars, $this->_sql_elements['bind_vars'], TRUE); //will throw exception if it fails to validate
        $result = $this->run_sql($vars_array, 'return_outvar');
        return $result;           
    }
    
    /**
     * retrieves a shortened URL via the URL record ID
     * 
     * @param integer $urlID    the URL record ID
     * @return string   the shortened URL 
     */
    public function getshortbyid($urlID)
    {
         $this->_sql_elements = $this->_base_sql_elements['get_shortbyid'];
         //make DBAL call
         $checkVars = ['urlid' => $urlID];
//         die(var_dump($checkVars));
        $vars_array = $this->validatePayload($checkVars, $this->_sql_elements['bind_vars'], TRUE); //will throw exception if it fails to validate
        $result = $this->run_sql($vars_array, 'return_outvar');
        return $result;           
    }    
    
    /**
     * uses random numbers to generate a unique code string with which to replace the URL
     * @param  $length   the defined length of the generated string
     * @return string   the randomized string
     */    
    public function make_tiny_url($length) {
//        $length = $this->ci->configs_array['url_length'];//get length from $container
	$i = 0;
	$random_string = '';
        //set the char array to use for shortening:
        $character_array = array_merge(range('a', 'z'), range('A', 'Z'), range(0, 9));
	do {
            $random_number = mt_rand(0, count($character_array) - 1);
            $random_string .= $character_array[$random_number];
            $i++;
	}
	while ($i < $length);
	// RETURN THE RANDOM STRING:
	return $random_string;
    }
}