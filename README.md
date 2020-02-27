# odbal
Oracle Database Abstraction Layer

implement the normal way:
  `composer require rastatech/odbal`

This is an abstraction layer intended to take some of the pain & suffering out of using Oracle Stored Procedures, for which this DBAL is specifically geared. Eventually this could easily work for Pass-Thru SQL too, as I designed it w/ a placeholder for that, but it doesn't currently. Feel free to fork & add it! :)  )

You can do everything pretty much by extending the Main.php class with your own model. I've included a sample model (example_model) extension of the dbal\main as an example of how to use the ODBAL. 

One last set of things you'll need to define are the following arrays; 
I set these arrays currently via an `.ini` file defined in a location external to the application and that is retrieved and consumed at the application level, but you can do them in the model, or whatever. An example of some suffix arrays for an `.ini` file is in the wiki.

ahem, sorry; the `.ini` file arrays:
- your `cursor_params` - the parameter names/suffix strings (it'll handle both) - you want to define as being `OUT_CURSOR` parameters, e.g. `CURSOR`s you're expecting back from Oracle. When ODBAL sees parameters with these strings, it will know that these should be bound as `CURSOR`s. This allows you to retrieve multiple OUT_CURSORS from a package. Very nifty. 
- `out_params` - OUT variables that are *not* `CURSOR`s. When ODBAL sees parameters with these string names, it will magically create class variables at the level of your Model (exending Main.php) with the same names as the parameters sent. You can access OUT vars (non-`CURSOR`) via `$this->{parametername}` in your model. In your `.ini` file you must define the parameter names/suffix strings you want it to recognize as OUT params. You must pass an array of `[length=x,type={valid oci bind type or non-repeating portion of same},value={null ok here if you are not passing something IN this way}]` to ODBAL as a value for Oracle to bind properly to the *OUT* param. 
- Oh, and if you are using a _Function_ instead of a _Procedure_, you'll want to define the 'function_return_params' - a set of strings or string suffixes that the ODBAL will recognize as a function return variable. This works similarly to the non-`CURSOR` OUT vars, but you can pass in that parameter name as the 2nd argument to the `run_sql` method to get the value back directly.  


I've included a directory of _example_models_ for you to see a coupla different ways to include these arrays. 
