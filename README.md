#dbMigrate.php

Bare-bones MySQL migrations with tracking using mysqli.


##Prerequisites
- Have MySQL installed and a database created. Connection information will be requested from you.

##Commands
Here are the existing commands: 

###php dbMigrate.php setup
calling 'php dbMigrate.php setup' will prompt the setup script. Be prepared to answer some questions to set up dbMigrate.

###create
calling 'php dbMigrate.php create' will prompt the creation of a new migration template. 

###migrate
calling 'php dbMigrate.php migrate' will prompt dbMigrate to run all migrations and store what ran successfully in the database. 


##License
MIT.


##Safe to use?
Sure, why not? This was whipped up over a few hours, contains lots of bugs, but if you can brave that, try it out and let me know what you think.