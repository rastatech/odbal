<?php
namespace rastatech\odbal;
/**
 * basic configs for Oracle objects.
 *
 * You can override these with specifics of your own by including your own dbal/db_config.php file as an easy way of setting up a default config
 *
 * Otherwise there are ways to set any of these params during Oracle object construction or later
 *
 *
 * @package URL_ShortR
 * @subpackage dbal
 * @author todd.hochman
 * @link http://www.php.net/manual/en/function.oci-fetch-all.php
 * @link http://www.php.net/manual/en/function.oci-fetch-array.php
 * @version 2.1
 * @todo this whole dbal might need to be moved in namespace to accomodate MySQL dbal if/when we make one of those
 */
$configs = [    'cursor'    => ['out_cursor'	=> FALSE, //false for Function / procedure calls with no OUT CURSOR, true or an array or cursor names otherwise
                ],
                'connection' => ['user'     => '',
                                'pw'        => '',
                                'cnx_str'   => '',
                                //regex for identifying an oracle connection string:
                                'cnx_regex' => '#\(DESCRIPTION\s?=\s?\(ADDRESS_LIST\s?=\s?\(ADDRESS\s?=\s?\(PROTOCOL\s?=\s?\w{3}\)\(HOST\s?=\s?(\w+\.)+\w{2,4}\)\(PORT\s?=\s?(\d{4})\)\)\)\(CONNECT_DATA\s?=\s?\(SERVICE_NAME\s?=\s?\w{2,30}(\w+\.\w+\.\w+)*\)\)\)|\(DESCRIPTION\s?=\s?\(ADDRESS_LIST\s?=\s?\(ADDRESS\s?=\s?\(PROTOCOL\s?=\s?\w{3}\)\(HOST\s?=\s?(\w+\.)+\w{2,4}\)\(PORT\s?=\s?(\d{4})\)\)\)\(CONNECT_DATA\s?=\s?\(SID\s?=\s?\w{2,30}(\w+\.\w+\.\w+)*\)\)\)#i',
                ],
                'statement' => [ 'sql' 	=> '', //fully qualified, e.g. LWB.WWTSPF.p_PermitNfo
                                //types of query the class understands:
                                 'sqltypes' => [//'^\w+(.\w+.){1}\w+$',//fully qualified package call, name only - NOT CURRENTLY SUPPORTED!!!
                                                '^BEGIN\s+((:\w+)(\s*)(:=)(\s*))?(\w+\.\w+\.\w+)(\(((:)?(\w)*,?\s*)+\))?;\s*END;$', //verbose (complete) package call
                                                '^(SELECT((\s\w+)+,?).+FROM(\s\w+)|UPDATE\s\w+(\s\w+)?\s?SET|DELETE\sFROM(\s\w+)|INSERT\sINTO(\s\w+))',//pass-thru CRUD query
                                                ],
                ],
                'result'    => ['fetch_all_params' => ['skip' => 0, //skip none
                                                        'maxrows' => -1, //return all rows
                                                        'flags' => OCI_FETCHSTATEMENT_BY_ROW,
                                                        ],
                                //default mode for php oci_fetch_array:
                                'fetch_arr_mode' => OCI_ASSOC,           
                                'table_style' => "style='border: 1px solid; border-collapse: collapse;'",
                ],
                'bindings'  =>  ['bind_vars' 	=> [], //array of {placeholder_name} => {(value to bind)||([length, oci type, value 2 bind))}                    
                ]
            ];
return $configs;