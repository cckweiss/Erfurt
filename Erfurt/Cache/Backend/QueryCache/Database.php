<?php
require_once 'Erfurt/Cache/Backend/QueryCache/Backend.php';


class Erfurt_Cache_Backend_QueryCache_Database extends Erfurt_Cache_Backend_QueryCache_Backend {

    //-------------------------------------------------------------------------------	
    //public functions
    //-------------------------------------------------------------------------------

    /**
     *  check the existing cacheVersion and can used for looking up if the cache structure is beeing created
     *  @access     public
     *  @return     boolean         $state          true / false
     */
	final public function checkCacheVersion() {

        $result = null;
        try {            
            $query = "SELECT num FROM ef_cache_query_version" ;
            $result = $this->store->sqlQuery( $query );        
        } catch (Erfurt_Store_Adapter_Exception $f) {
            return false;
        }

		if (!$result) {
			return false;
		}
		return true;

	}

    /**
     *  creating the initially needed cacheStructure. In the database case there will be 6 tables created named as followed:
     *  ef_cache_query_result, ef_cache_query_triple, ef_cache_query_model, ef_cache_query_rt, ef_cache_query_rm, 
     *  ef_cache_query_version. Furthermore there are some indexes which are created. 
     *  NOTE: if this function will be called and database exists, all cached data will be deleted first.
     *  @access     public
     *  @return     boolean         $state          true / false
     */
	final public function createCacheStructure() {

        $vocabulary = array();
        switch ($this->store->getBackendName()) {
            case "ZendDb" :
            case "MySql" :
                $vocabulary['identity'] = "AUTO_INCREMENT";
            break;
            case "Virtuoso":
                $vocabulary['identity'] = "IDENTITY";
            break;
            default:
                $vocabulary['identity'] = "IDENTITY";
            break;
        }


		$this->store->sqlQuery('CREATE TABLE ef_cache_query_result (
                qid VARCHAR( 255 ) NOT NULL ,
                query LONG VARCHAR NULL, 
                result LONG VARBINARY NULL ,
                hit_count INT NULL,
                inv_count INT NULL,
                time_stamp FLOAT NULL,
                duration FLOAT NULL,
                PRIMARY KEY ( qid ))');

		$this->store->sqlQuery('CREATE TABLE ef_cache_query_triple (
                tid INT NOT NULL '.$vocabulary['identity'].',
                subject VARCHAR( 255 ) NULL ,
                predicate VARCHAR( 255 ) NULL ,
                object VARCHAR( 255 ) NULL ,
                PRIMARY KEY ( tid ))');

		$this->store->sqlQuery('CREATE TABLE ef_cache_query_model (
                mid INT NOT NULL '.$vocabulary['identity'].',
                modelIri VARCHAR( 255 ) NULL ,
                PRIMARY KEY ( mid ))');


		$this->store->sqlQuery('CREATE TABLE ef_cache_query_rt (
                qid VARCHAR( 255 ) NOT NULL ,
                tid VARCHAR( 255 ) NOT NULL ,
                PRIMARY KEY ( qid, tid ))');

		$this->store->sqlQuery('CREATE TABLE ef_cache_query_rm (
                qid VARCHAR( 255 ) NOT NULL ,
                mid VARCHAR( 255 ) NOT NULL ,
                PRIMARY KEY ( qid, mid ))');

		$this->store->sqlQuery('CREATE TABLE ef_cache_query_objectKey (
                qid VARCHAR( 255 ) NOT NULL ,
                objectKey VARCHAR( 255 ) NOT NULL ,
                PRIMARY KEY ( qid, objectKey ))');

		$this->store->sqlQuery('CREATE TABLE ef_cache_query_version (
                num INT NOT NULL ,
                PRIMARY KEY ( num )) ');

		$this->store->sqlQuery('INSERT INTO ef_cache_query_version (num) VALUES (1)');

        $this->store->sqlQuery('CREATE INDEX ef_cache_query_result_qid ON ef_cache_query_result(qid)');
        $this->store->sqlQuery('CREATE INDEX ef_cache_query_result_qid_count ON ef_cache_query_result(qid,hit_count,inv_count)');

        $this->store->sqlQuery('CREATE INDEX ef_cache_query_model_mid_modelIri ON ef_cache_query_model(mid, modelIri)');

        $this->store->sqlQuery('CREATE INDEX ef_cache_query_rt_qid_tid ON ef_cache_query_rt(qid, tid)');
        $this->store->sqlQuery('CREATE INDEX ef_cache_query_rm_qid_mid ON ef_cache_query_rm(qid, mid)');

        $this->store->sqlQuery('CREATE INDEX ef_cache_query_objectKey_qid_objectKey ON ef_cache_query_objectKey (qid, objectKey)');

        $this->store->sqlQuery('CREATE INDEX ef_cache_query_triple_tid ON ef_cache_query_triple(tid)');
        $this->store->sqlQuery('CREATE INDEX ef_cache_query_triple_tid_spo ON ef_cache_query_triple(tid, subject, predicate, object)');
	}


    /**
     *  saving a QueryString and its Result according to its QueryHash
     *  Furthermore triplePatterns from given QueryString and ModelIris (from clause) will also be saved . If array of
     *  modelIris is empty the QueryId will be assigned to a NULL-Entry . Last But not Least the duration of the originally 
     *  processed query will be saved.
     *  @access     public
     *  @param      string    $queryId        Its a hash of the QueryString
     *  @param      string    $queryString    SPARQL Query as String
     *  @param      array     $graphUris      An Array of graphUris extracted from the From and FromNamed Clause of the SPARQL Query
     *  @param      array     $triplePatterns An Array of TriplePatterns extracted from the Where Clause of the SPARQL Query
     *  @param      string    $queryResult    the QueryResult
     *  @param      float     $duration       the duration of the originally executed Query in seconds, microseconds
     *  @return     boolean   $result         returns the state of the saveprocess
     */    
    public function save( $queryId, $queryString, $modelIris, $triplePatterns, $queryResult, $duration = 0) {
        #check that this query isn't saved yet

        if ( false === $this->exists ( $queryId ) ) {
            #encoding the queryResult
            $queryResult = $this->_encodeResult( $queryResult );
            #saving the result and its according queryId
            $query = "INSERT INTO ef_cache_query_result (
                qid, 
                query,
                result,
                hit_count,
                inv_count,
                time_stamp, 
                duration) VALUES (
                '".$queryId."',
                '".(str_replace("'", '"', $queryString))."', 
                '".$queryResult."',
                0,
                0, 
                ".(microtime(true)).",
                ".$duration.")" ;
            $ret = $this->_query ( $query ) ;
            //saving triplePatterns in tripleTable
            $this->_saveTriplePatterns ( $queryId, $triplePatterns ) ;

            //saving modelIris in modelTable
            $this->_saveModelIris ( $queryId, $modelIris ) ;

        }
        else {
            $queryResult = $this->_encodeResult( $queryResult );
            $this->_query ( "UPDATE ef_cache_query_result SET result = '".$queryResult."', duration = ".$duration." WHERE qid = '".$queryId."'" );
        }
    }


    /**
     *  saving a Query as String, its result and some more needed information
     *  @access     public
     *  @param      string          $queryId        Its a hash of the QueryString
     *  @return     string/boolean  $result         If a result was found it returns the result or if not then returns false
     */
    public function load ( $queryId ) {
        $query = "SELECT result FROM ef_cache_query_result WHERE qid = '" . $queryId . "'" ;
        $result = $this->_query ( $query );
        if (sizeOf($result) != 0) {
            $result = $result[0]['result'] ;
            $result = $this->_decodeResult( $result );
        }
        else {
            $result = false;
        }
        return $result ;
    }


    /**
     *  increments the count of a query result (needed for logging)
     *  @access       public
     *  @param        string    $queryId        Its a hash of the QueryString
     */
    public function incrementHitCounter( $queryId ) {

        switch ($this->store->getBackendName()) {
            case "ZendDb" :
            case "MySql" :
                $count = $this->_query( "SELECT hit_count 
                                FROM ef_cache_query_result 
                                WHERE qid='".$queryId."' ") ;
                $this->_query ( "UPDATE ef_cache_query_result SET hit_count = ".($count[0]['hit_count']+1)." WHERE qid='".$queryId."' AND result IS NOT NULL" );
            break;
            case "Virtuoso":
            default:
                $query = "  UPDATE ef_cache_query_result 
                            SET hit_count = ( 
                                SELECT hit_count 
                                FROM ef_cache_query_result 
                                WHERE qid='".$queryId."' ) + 1
                            WHERE qid='".$queryId."' AND result IS NOT NULL"  ;
                $this->_query ( $query );
            break;
        }
    }


    /**
     *  increments the count of a query result (needed for logging)
     *  @access       public
     *  @param        string    $queryId        Its a hash of the QueryString
     */
    public function incrementInvalidationCounter( $queryId ) {

        switch ($this->store->getBackendName()) {
            case "ZendDb" :
            case "MySql" :
                $count = $this->_query( "SELECT inv_count 
                                FROM ef_cache_query_result 
                                WHERE qid='".$queryId."' ") ;
                $this->_query ( "UPDATE ef_cache_query_result SET inv_count = ".($count[0]['inv_count']+1)." WHERE qid='".$queryId."' AND result IS NOT NULL" );
            break;
            case "Virtuoso":
            default:
                $query = "  UPDATE ef_cache_query_result 
                            SET inv_count = ( 
                                SELECT inv_count 
                                FROM ef_cache_query_result 
                                WHERE qid='".$queryId."' ) + 1
                            WHERE qid='".$queryId."' AND result IS NOT NULL"  ;
                $this->_query ( $query );
            break;
        }
   
    }


    /**
     *  invalidating a cached Query Result 
     *  @access     public
     *  @param      array   $statements     an Array of statements in the form $statements[$subject][$predicate] = $object
     *  @return     int     $count          count of the affected cached queries         
     */
    public function invalidate ( $modelIri, $statements = array() ) {

        if (sizeof($statements) == 0)
            return false;

        $qids = array();
        $clauses = array();
        foreach ( $statements as $subject => $predicates ) {
            foreach ($predicates as $predicate => $objects) {
                foreach ($objects as $object) {
                    $objectValue = $object['value'] ;
                    if ($object['type'] == 'literal')
                    {
                        $objectValue = (isset($object['lang'])) ?  $objectValue . "@" . $object['lang'] : $objectValue ;
                        $objectValue = (isset($object['datatype'])) ?  $objectValue . "^^" . $object['datatype'] : $objectValue ;
                    }
                    $clause = array();
                    if ($subject != "") {
                        $clause[] = "(subject = '".$subject."' OR subject IS NULL)" ;
                    }
                    if ($predicate != "") {
                        $clause[] = "(predicate = '".$predicate."' OR predicate IS NULL)" ;
                    }

                    if ($object != null ) {
                        $clause[] = "(object = '".$object."' OR object IS NULL)" ;
                    }
                    $clauses[] = "(". (implode (" AND ", $clause)) .")";
                

/*                   "(
                    (subject = '".$subject."' OR subject IS NULL) AND 
                    (predicate = '".$predicate."' OR predicate IS NULL) AND 
                    (object = '".$objectValue."' OR object IS NULL))"; */
                }
            }
        }
        $clauseString = implode (" OR ", $clauses);
        // retrieve List Of qids which have to vbe invalidated
        $query = "  SELECT DISTINCT (qid) 
                    FROM 
                        (
                            SELECT qid qid1
                            FROM ef_cache_query_rt JOIN ef_cache_query_triple ON ef_cache_query_rt.tid = ef_cache_query_triple.tid
                            WHERE ( ".$clauseString." )
                        ) first 
                    JOIN 
                        (
                            SELECT qid qid2
                            FROM ef_cache_query_rm JOIN ef_cache_query_model ON ef_cache_query_rm.mid = ef_cache_query_model.mid
                            WHERE ( ef_cache_query_model.modelIri = '".$modelIri."' OR ef_cache_query_model.modelIri IS NULL )
                        ) second 
                        ON first.qid1 = second.qid2 
                    JOIN 
                        ef_cache_query_result result ON result.qid = qid2 
                    WHERE result.result IS NOT NULL";
        $result = $this->_query ( $query );
        if (!$result)
            return $qids;        

        foreach ($result as $entry) {
            $qids[] = $entry['qid'];
        }
        $query = "  UPDATE ef_cache_query_result SET result = NULL 
                    WHERE 
                    (
                        qid IN 
                        (   
                            SELECT DISTINCT (qid) 
                            FROM ef_cache_query_rt JOIN ef_cache_query_triple ON ef_cache_query_rt.tid = ef_cache_query_triple.tid
                            WHERE ( ".$clauseString." )
                        ) 
                        AND qid IN
                        (
                            SELECT DISTINCT (qid) 
                            FROM ef_cache_query_rm JOIN ef_cache_query_model ON ef_cache_query_rm.mid = ef_cache_query_model.mid
                            WHERE ( ef_cache_query_model.modelIri = '".$modelIri."' OR ef_cache_query_model.modelIri IS NULL )

                        )
                    )";
        $this->_query ( $query );
        return $qids;
    }


    /**
     *  invalidating all cached Query Results according to a given ModelIri 
     *  @access     public
     *  @param      string  $modelIri       A ModelIri
     *  @return     int     $count          count of the affected cached queries         
     */
    public function invalidateWithModelIri( $modelIri ) {
        $query = "SELECT DISTINCT (qid) 
                  FROM ef_cache_query_rm JOIN ef_cache_query_model ON ef_cache_query_rm.mid = ef_cache_query_model.mid
                  WHERE ( ef_cache_query_model.modelIri = '".$modelIri."' OR ef_cache_query_model.modelIri IS NULL )";

        $qids = $this->_query ( $query );

        foreach ($qids as $qid) {
            $qid = $qid['qid'];

            //delete entries in query_triple
            $query = " SELECT ef_cache_query_rt.tid tid
                       FROM ef_cache_query_rt JOIN ef_cache_query_triple ON ef_cache_query_rt.tid = ef_cache_query_triple.tid
                       WHERE ( qid = '".$qid."' )" ;
            $tids = $this->_query ( $query );
            foreach ($tids as $tid) {
                $tid = $tid['tid'] ;
                $this->_query ( "DELETE FROM ef_cache_query_triple WHERE tid = '".$tid."'" );
            }

            //delete entries in query_rt 
            $this->_query ( "DELETE FROM ef_cache_query_rt WHERE qid = '".$qid."'" );

            //delete entries in query_result
            $this->_query ( "DELETE FROM ef_cache_query_result WHERE qid = '".$qid."'" );

            //delete entries in query_rm 
            $this->_query ( "DELETE FROM ef_cache_query_rm WHERE qid = '".$qid."'" );

            //delete entries in query_model
            $this->_query ( "DELETE FROM ef_cache_query_model WHERE modelIri = '".$modelIri."'" );
        }

        foreach ($result as $entry) {
            $qids[] = $entry['qid'];
        }
        return $qids;
    }

    public function invalidateObjectKeys ( $oids = array ()) {

        foreach ($oids as $oid) {
            //delete entries in query_objectKey
            $query = "DELETE FROM ef_cache_query_objectKey WHERE objectKey = '".$oid."'" ;
            $this->_query ( $query );
        }
    }

    public function getObjectKeys ( $qids = array() ) {

        $oKeys = array();
        foreach ($qids as $qid) {
            $query = "SELECT DISTINCT (objectKey) FROM ef_cache_query_objectKey WHERE qid='".$qid."'"; ;
            $result = $this->_query ( $query );
            foreach ($result as $entry) {
                $oKeys[$entry['objectKey']] = $entry['objectKey'];
            }
        }
        return $oKeys;
    }


    /**
     *  deleting all cachedResults
     *  @access     public
     *  @return     boolean         $state          true / false
     */
    public function invalidateAll () {
        $qids = $this->store->sqlQuery('SELECT qid FROM ef_cache_query_result');
        $ret = $this->store->sqlQuery('UPDATE ef_cache_query_result SET result = NULL'); 
        $this->store->sqlQuery('DELETE FROM ef_cache_query_objectKey');

        return $qids;


    }


    /**
     *  deleting the initially created cacheStructure
     *  @access     public
     *  @return     boolean         $state          true / false
     */
    public function uninstall () {

		$this->store->sqlQuery('DROP INDEX ef_cache_query_result_qid');
        $this->store->sqlQuery('DROP INDEX ef_cache_query_result_qid_count');
        $this->store->sqlQuery('DROP INDEX ef_cache_query_model_mid_modelIri');
        $this->store->sqlQuery('DROP INDEX ef_cache_query_rt_qid_tid');
        $this->store->sqlQuery('DROP INDEX ef_cache_query_rm_qid_mid');
        $this->store->sqlQuery('DROP INDEX ef_cache_query_triple_tid');
        $this->store->sqlQuery('DROP INDEX ef_cache_query_triple_tid_spo');
        $this->store->sqlQuery('DROP INDEX ef_cache_query_objectKey_qid_objectKey');

		$this->store->sqlQuery('DROP TABLE ef_cache_query_triple');
		$this->store->sqlQuery('DROP TABLE ef_cache_query_model');
        $this->store->sqlQuery('DROP TABLE ef_cache_query_result');
        $this->store->sqlQuery('DROP TABLE ef_cache_query_rt');
        $this->store->sqlQuery('DROP TABLE ef_cache_query_rm');
        $this->store->sqlQuery('DROP TABLE ef_cache_query_version');
        $this->store->sqlQuery('DROP TABLE ef_cache_query_objectKey');

        return true;

    }












    public function saveTransactions ( $queryId, $transactions ) {

        $keys = array_keys ($transactions) ;
        foreach ($keys as $key) {
            $query = "INSERT INTO ef_cache_query_objectKey (qid, objectKey) VALUES ('".$queryId."', '".$key."')" ;
            $this->_query ($query);
        }

    }


    /**
     *  check if a QueryResult is cached yet
     *  @access     public
     *  @param      string          $queryId        Its a hash of the QueryString
     *  @return     boolean         $state          true / false
     */
    public function exists ( $queryId ) {
        $query = "SELECT hit_count FROM ef_cache_query_result WHERE qid = '".$queryId."'";
	    $count = $this->_query ($query);
        if ( !$count )
            return false;
        return (int) $count[0]['hit_count'];
        #return (bool) $result[0]['count'];
    }


    //-------------------------------------------------------------------------------
    //private functions
    //-------------------------------------------------------------------------------


    /**
     *  this private methode encapsulates the functionality for storing the triplePatterns in the triplePattern table
     *  and assigning them to the according queryResult table via a m:n table
     *  @access     private
     *  @param      string          $queryId        the Hash of the Query
     *  @param      array           $triplePatterns the Array of TriplePatterns
     */	
    private function _saveTriplePatterns ( $queryId, $triplePatterns ) {

        $defaultTP = array (
            'subject'   => 'NULL',
            'predicate'   => 'NULL',
            'object'   => 'NULL',
        );

        foreach ( $triplePatterns as $triplePattern ) {
            #initialize query, encapsulate Values with singlequotes and merge tripleValues with defaultValues
            $query = "";
            foreach ( $triplePattern as $key => $value ) {
                $triplePattern[$key] = "'".$value."'";
            }
            $triplePattern = array_merge( $defaultTP, $triplePattern );

            $tripleId = null;

            $query = "SELECT tid FROM ef_cache_query_triple WHERE 
                        subject ".(( $triplePattern['subject'] == 'NULL' ) ? "IS " : "= " ) . $triplePattern['subject'] . " AND 
                        predicate ".(( $triplePattern['predicate'] == 'NULL' ) ? "IS " : "= " ) . $triplePattern['predicate'] . " AND 
                        object ".(( $triplePattern['object'] == 'NULL' ) ? "IS " : "= " ) . $triplePattern['object'] . " ";
            $result = $this->_query ( $query );

            if ( count($result) == 0 ) {
                $query = "INSERT INTO ef_cache_query_triple (
                    subject,
                    predicate,
                    object) VALUES (
                    ".$triplePattern['subject'].",
                    ".$triplePattern['predicate'].",
                    ".$triplePattern['object'].")";
                $ret = $this->_query ( $query );
                $tripleId = $this->_getLastInsertId();
            } else {
                $tripleId = $result[0]['tid'] ;
            }
            
            // build association of triple and result

            $res = $this->_query ( "SELECT * FROM ef_cache_query_rt WHERE qid = '".$queryId."' AND tid = '".$tripleId."'" );
            if (sizeof($res) == 0) {
                $this->_query ( "INSERT INTO ef_cache_query_rt (qid, tid) VALUES ('".$queryId."','".$tripleId."')" );
            }
        }       
    }


    /**
     *  this private methode encapsulates the functionality for storing the modelIris in the model table
     *  and assigning them to the according queryResult table via a m:n table
     *  @access     private
     *  @param      string          $queryId        the Hash of the Query
     *  @param      array           $modelIris      the Array of modelIris
     */	
    private function _saveModelIris ( $queryId, $modelIris )
    {
        if ( count( $modelIris ) == 0) {
            $modelIris[] = 'NULL' ;
        }

        foreach ($modelIris as $modelIri) {
            $modelId = "";

            if ($modelIri != 'NULL')
                $modelIri = "'" . $modelIri . "'";

            $query = "SELECT mid FROM ef_cache_query_model WHERE modelIri " .(($modelIri == 'NULL') ? "IS " : "= " ). $modelIri;
            $result = $this->_query ( $query );

            if ( count($result) == 0 ) {
                $query = "INSERT INTO ef_cache_query_model (modelIri) VALUES (".$modelIri.")";
                $ret = $this->_query ($query);
                $modelId = $this->_getLastInsertId();
            } else {
                $modelId = $result[0]['mid'] ;
            }


            $this->_query ( "INSERT INTO ef_cache_query_rm (qid, mid) VALUES ('".$queryId."', '".$modelId."')" );
        }
    }


    /**
     *  this private methode encapsulates the functionality to retrieve the last InsertId
     *  @access     private
     *  @return     var           $insertId      InsertId or false if an error occured
     */	
    private function _getLastInsertId() {
        try {
            return $this->store->lastInsertId() ;
        } catch (Erfurt_Store_Adapter_Exception $e) {
            $logger = Erfurt_App::getInstance()->getLog('cache');
            $logger->log($e->getMessage(), $e->getCode());
            return false;
        }        
    }


    /**
     *  this private methode encapsulates the functionality for queriing the database with SQL
     *  @access     private
     *  @param      string          $queryId        the Hash of the Query
     *  @return     resultSet       $result         the result of the SQL Query
     */	
	private function _query( $sql ) {
        $result = false ;
        $checkCache = false;
        try {
            $result = $this->store->sqlQuery( $sql ); 
    
        } catch (Erfurt_Store_Adapter_Exception $e){
            $logger = Erfurt_App::getInstance()->getLog('cache');
            $logger->log($e->getMessage(), $e->getCode());
            $result = false;
            $checkCache = true;
        }

        if ($checkCache) {
            try {            
                if (!$this->checkCacheVersion()) {
                    $this->createCacheStructure ();
                    if (!$this->checkCacheVersion()) {
                        Zend_Cache::throwException('Impossible to build cache structure.');
                    }
                    else { 
                        $result = $this->_query($sql);
                    }
                }
            } catch (Erfurt_Store_Adapter_Exception $f) {
                $this->createCacheStructure ();
                if (!$this->checkCacheVersion()) {
                    Zend_Cache::throwException('Impossible to build cache structure.');
                }
                else { 
                    $result = $this->_query($sql);
                }
            }
        }
        return $result;
	}	


    /**
     *  this private methode encapsulates the functionality for encoding the ResultSet with bas64
     *  @access     private
     *  @param      string         $result      Result as String
     *  @return     string         $result      result as base 64 erncoded String
     */	
    private function _encodeResult ( $result ) {
        $result = addslashes( $result );
        return $result;
    }	


    /**
     *  this private methode encapsulates the functionality decoding the base 64 encoded resultSet 
     *  @access     private
     *  @param      string          $result      base 64 encoded Result
     *  @return     string          $result      decoded result
     */	
    private function _decodeResult ( $result ) {
        #$result = ( $result );
        return $result;
    }	
	
}

?>
