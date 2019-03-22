# odbal
Oracle Database Abstraction Layer

This is an abstraction layer intended to take some of the pain & suffering out of using Oracle in PHP, especially Oracle Stored Procedures, for which this DBAL is specifically geared (should work for Pass-Thru SQL too but I've not tested that in a while)

You can do everything pretty much by extending the Main.php class with your own model. I've included a sample model (example_model) extension of the dbal\main as an example of how to use the ODBAL. 
