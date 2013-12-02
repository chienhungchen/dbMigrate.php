<?php
	
	//dbMigrate.php
	//Currently supports MySQL.
	//MIT License
	//Version 0.1.0
	
	$CONFIG_FILE 			= "config.json";
	$MIGRATION_FILETYPE 	= ".php";
	
	class DBMigrator {
		
		function __construct() {
			global $CONFIG_FILE;
			if(file_exists( $CONFIG_FILE )) {
				$this->loadProps();
				$this->justSetup = false;
			}
			else {
				$this->setup();
			}
		}
		
		private function loadProps() {
			global $CONFIG_FILE;
			
			$this->config = json_decode( file_get_contents( $CONFIG_FILE ) );
			$this->dbh = new mysqli( $this->config->db->host, $this->config->db->user, $this->config->db->password, $this->config->db->database, $this->config->db->port );
			$this->stdin = fopen( "php://stdin", "r" );
			//Setting up database connection
			if ($this->dbh->connect_errno) {
				die('Database connection error.');
			}
		}
		
		public function setup() {
			global $CONFIG_FILE;
			
			$this->justSetup = true;
			
			//Requests user to specify location for migrations
			echo "Setting up the DB Migrator. Please specify what directory your migrations will go: ";
			$handle = fopen( "php://stdin", "r" );
			$migrationLocation = trim( fgets( $handle ) );
			//Catching to make sure it always has a / at the end
			if(substr($migrationLocation, -1) != "/") {
				$migrationLocation .= "/";
			}
			if(!file_exists( $migrationLocation )) {
				echo "Directory doesn't exist yet. Creating directory now...\n";
				mkdir( $migrationLocation );
			}
			
			echo "Please input your database host: ";
			$dbHost = trim( fgets( $handle ) );
			
			echo "Please input your database user: ";
			$dbUser = trim( fgets( $handle ) );
			
			echo "Please input your database password: ";
			$dbPass = trim( fgets( $handle ) );
			
			echo "Please input your database name: ";
			$dbName = trim( fgets( $handle ) );
			
			echo "Please input your database port: ";
			$dbPort = intval( trim( fgets( $handle ) ) );
			
			echo "Please input your schema change log table name (if it doesn't exist yet, it will be created for you.): ";
			$dbChangeLogTable = trim( fgets( $handle ) );
			
			//write or create to config.json
			if( file_exists( $CONFIG_FILE ) ) {
				$this->config = json_decode( file_get_contents( $CONFIG_FILE ) );
			}
			else {
				$this->config = json_decode("{}");
			}
			
			//set config->migrationLocation
			$this->config->migrationLocation = $migrationLocation;
			
			//Check if db attribute exists in config, and create an object if it doesnt
			if( empty($this->config->db) ) {
				$this->config->db = new StdClass();
			}
			
			$this->config->db->host = $dbHost;
			$this->config->db->user = $dbUser;
			$this->config->db->password = $dbPass;
			$this->config->db->database = $dbName;
			$this->config->db->port = $dbPort;
			
			$this->config->dbChangeLogTable = $dbChangeLogTable;
			
			file_put_contents( $CONFIG_FILE, json_encode($this->config) );
			
			echo "Creating/Updating " . $CONFIG_FILE . "\n";
			
			$this->loadProps();
			
			//Check if $dbChangeLogTable exists
			$chkChangeLogStmt = $this->dbh->prepare( 'SELECT 1 FROM ' . $this->config->dbChangeLogTable . ' LIMIT 1' );
			if ( $chkChangeLogStmt ) {
				$chkChangeLogStmt->execute();
				$chkChangeLogStmt->close();
			}
			else {
				echo "Looks like the table " . $this->config->dbChangeLogTable . " does not exist. Attempting to create it now.\n";
				$insertChangeLogTableStmt = $this->dbh->prepare( "
					CREATE TABLE IF NOT EXISTS " . $this->config->dbChangeLogTable . " (
					`id` int(11) unsigned NOT NULL AUTO_INCREMENT,
					`scriptName` varchar(255) DEFAULT NULL,
					`dateApplied` int(11) DEFAULT NULL,
					PRIMARY KEY (`id`)
				)");
				if( $insertChangeLogTableStmt ) {
					$insertChangeLogTableStmt->execute();
					$insertChangeLogTableStmt->close();
					echo "Table " . $this->config->dbChangeLogTable . " created successfully.\n";
					echo "Setup should be complete now. Please try creating a migration!\n";
				}
				else {
					echo "Creating table " . $this->config->dbChangeLogTable . " failed. Please check your MySQL settings and try again!\n";
				}
			}
		}
		
		public function migrate() {
			
			$scanDir = scandir( $this->config->migrationLocation ); //get file list from migrationLocation
			$migratedList = $this->getMigratedList();
			$needToMigrateList = array();
				
			//get list of migrations that needs to be ran
			for( $i = 0; $i < count($scanDir); $i++ ) {
				
				//exclude . and .. directory and anything that has already been ran
				if( $scanDir[$i] !== "." && $scanDir[$i] !== ".." && !in_array( $scanDir[$i], $migratedList ) ) {
					
					$needToMigrateList[] = $scanDir[$i];
					
					//Include the file to expose the migration
					include( 'migrations/' . $scanDir[$i] );
					
					echo "Running migration: " . $scanDir[$i] . ".\n";
					
					//Only consider running the migration if the migration script is of length > 0
					if(isset($migrate_up) && gettype($migrate_up) == "string" && strlen($migrate_up) > 0) {
						$migrateStmt = $this->dbh->prepare( $migrate_up );
						if( $migrateStmt ) {
							if( !$migrateStmt->execute() ) {
							    echo "Query Execute failed: (" . $migrateStmt->errno . ") " . $migrateStmt->error;
							}
							else {
								$migrateStmt->close();
								echo "Migration successful.\n";
								$this->recordMigration( $scanDir[$i] );
							}
						}
						else {
							printf("Database Error: (%d) %s.\n", $this->dbh->errno, $this->dbh->error);
						}
					}
					else {
						echo "Migration Error: Migration " . $scanDir[$i] . " syntax incorrect.\n";
					}
				}
			}
			
			if( count($needToMigrateList) === 0 ) {
				echo "No new migrations to run.\n";
			}
		}
		
		public function create() {
			global $MIGRATION_FILETYPE;
			
			$scanDir = scandir( $this->config->migrationLocation ); //get file list from migrationLocation
			$migratedList = $this->getMigratedList();
			$needToMigrateList = array();
			
			for( $i = 0; $i < count($scanDir); $i++ ) {
				//exclude . and .. directory and anything that has already been ran
				if( $scanDir[$i] !== "." && $scanDir[$i] !== ".." && !in_array( $scanDir[$i], $migratedList ) ) {
					$needToMigrateList[] = $scanDir[$i];
				}
			}
			
			if( count($needToMigrateList) !== 0 ) {
				echo "There are pending migrations to be ran. Please run the migrate command first.\n";
				exit;
			}
			else {
				echo "Creating new migration file. Please specify your migration name: ";
				$migrationName = trim( fgets( $this->stdin ) );
				
				$createdTime = time();
				
				$filename = $createdTime . "_" . $migrationName . $MIGRATION_FILETYPE;
				$content = "<?php \n\t//Migration " . $migrationName . "\n\n\t\$migrate_up = \"\";\n\n\t\$migrate_down = \"\";\n\n?>";
				
				file_put_contents($this->config->migrationLocation . $filename, $content);
			}
		}
		
		private function recordMigration($migrationFileName) {
			$time = time();
			$recordMigrateStmt = null;
			if( $recordMigrateStmt = $this->dbh->prepare("INSERT INTO " . $this->config->dbChangeLogTable . " (scriptName, dateApplied) VALUES (?, ?)") ) {
				$recordMigrateStmt->bind_param( 'si', $migrationFileName, $time );
				$recordMigrateStmt->execute();
				$recordMigrateStmt->close();
			}
			else {
				printf("Database Error: (%d) %s.\n", $this->dbh->errno, $this->dbh->error);
			}
		}
		
		private function getMigratedList() {
			//get list of migrations that has happened
			$migratedList = array();
			$getChangeLogStmt = $this->dbh->prepare( 'SELECT * FROM ' . $this->config->dbChangeLogTable );
			if ( $getChangeLogStmt ) {
				$getChangeLogStmt->execute();
				$getChangeLogStmt->store_result();
				$getChangeLogStmt->bind_result( $changeId, $scriptName, $dateApplied );
				while( $getChangeLogStmt->fetch() ) {
					$migratedList[] = $scriptName;
				}
				$getChangeLogStmt->close();
			}
			else {
				echo "Querying the schema change log table failed. Did you setup correctly?\n";
				exit;
			}
			
			return $migratedList;
		}
	}
	
	
	
	
	//PHP Argument Collection from command line
	
	if(isset($argv[1])) {
		$argument1 = $argv[1];
		
		if( $argument1 === "create" ) {
			
			$migrator = new DBMigrator();
			$migrator->create();
			
		}
		else if( $argument1 === "migrate" ) {
			
			$migrator = new DBMigrator();
			$migrator->migrate();
			
		}
		else if( $argument1 === "setup" ) {
			
			$migrator = new DBMigrator();
			
			if(!$migrator->justSetup) {
				$migrator->setup();
			}
		}
		else {
			echo "else!....\n";
			
			$migrator = new DBMigrator();
			
			if ($stmt = $migrator->dbh->prepare( 'SELECT * FROM ' . $migrator->config->dbChangeLogTable ) ) {
				$stmt->execute();
				$stmt->store_result();
				$stmt->bind_result( $changeId, $scriptName, $dateApplied );
				while( $stmt->fetch() ) {
					echo $changeId . " | " . $scriptName . " | " . $dateApplied . "\n";
				}
			}
		}
	}
	else {
		echo "dbMigrate is a barebones db migration tool. \nHere are the existing commands: \n";
		echo "\tsetup: \t\tcalling 'php dbMigrate.php setup' will prompt the setup script. Be prepared to answer some questions to set up dbMigrate.\n";
		echo "\tcreate: \t\tcalling 'php dbMigrate.php create' will prompt the creation of a new migration template. \n";
		echo "\tmigrate: \tcalling 'php dbMigrate.php migrate' will prompt dbMigrate to run all migrations and store what ran successfully in the database. \n";
	}
	
?>