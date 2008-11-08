<?php
require_once 'Erfurt/Sparql/EngineDb/Offsetter/Adapter/RapZendDb.php';
require_once 'Erfurt/Sparql/EngineDb/QuerySimplifier.php';
require_once 'Erfurt/Sparql/EngineDb/SqlGenerator/Adapter/Rap.php';
require_once 'Erfurt/Sparql/EngineDb/SqlMerger.php';

/**
*   SPARQL engine optimized for databases.
*   Generates SQL statements to directly query the database,
*   letting the database system do all the hard work like
*   selecting, joining, filtering and ordering results.
*
*   @author Christian Weiske <cweiske@cweiske.de>
*   @license http://www.gnu.org/licenses/lgpl.html LGPL
*
*   @package sparql
*/
class Erfurt_Sparql_EngineDb_Adapter_RapZendDb {
    
	/**
    *   Sparql Query object.
    *
    *   @var Query
    */
    protected $query;

    /**
    *   RDF dataset object.
    *   @var Dataset
    */
    protected $dataset;

    /**
    *   Database connection object.
    *   @var object mysqli connection
    */
    protected $dbConn;

    /**
    *   Internal ID for our graph model.
    *   Stored in the database along the statements.
    *   Can be of different types:
    *   - array: array of modelIds
    *   - null: all models
    *
    *   @var array OR null
    */
    protected $arModelIds;

    /**
    *   Prepared SQL statements are stored in here.
    *   @var array
    */
    protected $arPrepared    = null;

    /**
    *   If the prepared statement is really prepared, or if we just emulate it.
    *   @var boolean
    */
    protected $bRealPrepared = false;

    /**
    *   SQL generator instance
    *   @var SparqlEngineDb_SqlGenerator
    */
    protected $sg = null;

    /**
    *   Type sorting instance
    *   @var SparqlEngineDb_TypeSorter
    */
    protected $ts = null;

    /**
    *   Prepared statments preparator instance
    *   @var SparqlEngineDb_Preparator
    */
    protected $pr = null;



    /**
    *   Use SparqlEngine::factory() to create the instance
    *
    *   @param mixed $model         DbModel or DbStore
    *   @param mixed $arModelIds    Array of modelIds, or NULL to use all models
    */
    public function __construct($dbConn, $arModelIdMapping = array()) {
	
		$this->dbConn = $dbConn;
		$this->arModelIdMapping = $arModelIdMapping;
    }

    /**
    *   Query the database with the given SPARQL query.
    *
    *
    *   @param  Dataset       $dataset    RDF Dataset
    *   @param  Query         $query      Parsed SPARQL query
    *   @param  string        $resultform Result form. If set to 'xml' the result will be
    *                                   SPARQL Query Results XML Format as described in http://www.w3.org/TR/rdf-sparql-XMLres/ .
    *
    *   @return array/string  array of triple arrays, or XML. Format depends on
    *                                   $resultform parameter.
    */
    public function queryModel(Erfurt_Sparql_Query $query, $resultform = 'plain') 
    {
        $this->query   = $query;
        #$this->dataset = $dataset;

        $qsimp = new Erfurt_Sparql_EngineDb_QuerySimplifier();
        $qsimp->simplify($this->query);

        $this->sg = new Erfurt_Sparql_EngineDb_SqlGenerator_Adapter_Rap($this->query, $this->arModelIdMapping);
        
        $resultform = strtolower($resultform);
        switch ($resultform) {
            case 'xml':
                require_once 'Erfurt/Sparql/EngineDb/ResultRenderer/RapZendDb/Xml.php';
                $this->rc = new Erfurt_Sparql_EngineDb_ResultRenderer_RapZendDb_Xml();
                break;
            case 'plain':
            default:
                require_once 'Erfurt/Sparql/EngineDb/ResultRenderer/RapZendDb/Plain.php';
                $this->rc = new Erfurt_Sparql_EngineDb_ResultRenderer_RapZendDb_Plain();
        }
        
        $this->ts = new Erfurt_Sparql_EngineDb_TypeSorter($this->query, $this->dbConn);

        $this->setOptions();

        if ($this->query->isEmpty()){
            $vartable[0]['patternResult'] = null;
            return $this->returnResult($vartable, $resultform);
        }

        $arSqls = $this->sg->createSql();

        $this->ts->setData($this->sg);

        return
            $this->rc->convertFromDbResults(
                $this->queryMultiple(
                    $this->ts->getOrderifiedSqls(
                        $arSqls
                    )
                ),
                $this->getQuery(),
                $this
            );
    }//public function queryModel($dataset, Query $query, $resultform = false)



    /**
    *   Create a prepared statement that can be executed later.
    *
    *   @param  Dataset       $dataset    RDF Dataset
    *   @param  Query         $query      Parsed SPARQL query
    *
    *   @return SparqlEngineDb_PreparedStatement Prepared statment that can
    *           be execute()d later.
    */
    public function prepare(Dataset $dataset, Query $query)
    {
        //require_once RDFAPI_INCLUDE_DIR . 'sparql/SparqlEngineDb/PreparedStatement.php';
        //require_once RDFAPI_INCLUDE_DIR . 'sparql/SparqlEngineDb/Preparator.php';

        $this->query   = $query;
        $this->dataset = $dataset;
        $this->sg = new SparqlEngineDb_SqlGenerator   ($this->query, $this->dbConn, $this->arModelIds);
        $this->rc = new SparqlEngineDb_ResultConverter($this->query, $this->sg, $this);
        $this->ts = new SparqlEngineDb_TypeSorter     ($this->query, $this->dbConn);
        $this->pr = new SparqlEngineDb_Preparator     ($this->query, $this->dbConn);

        $this->arPrepared = $this->sg->createSql();
        $this->ts->setData($this->sg);

        if ($this->ts->willBeDataDependent()) {
            $this->bRealPrepared = false;
        } else {
            $this->bRealPrepared     = true;
            list($strSelect, $strFrom, $strWhere) = $this->arPrepared;
            $this->arPreparedQueries = $this->ts->getOrderifiedSqls(
                $strSelect,
                $strFrom,
                $strWhere
            );
            $this->arDbStatements    = $this->pr->prepareInDb(
                $this->arPreparedQueries,
                $this->sg->getPlaceholders()
            );
        }


        return new SparqlEngineDb_PreparedStatement(
            $this
        );
    }//public function prepare(Dataset $dataset, Query $query)



    /**
    *   Execute a prepared statement by filling it with variables
    *
    *   @param array $arVariables   Array with (variable name => value) pairs
    *   @param string $resultform   Which form the result should have
    *
    *   @return mixed   Result according to $resultform
    */
    public function execute($arVariables, $resultform = false)
    {
        if ($this->arPrepared === null) {
            throw new Exception('You need to prepare() the query first.');
        }

        if ($this->bRealPrepared) {
            return
                SparqlEngineDb_ResultConverter::convertFromDbResults(
                    $this->pr->execute(
                        $this->arDbStatements,
                        $arVariables
                    ),
                    $this,
                    $resultform
                );
        } else {
            list($strSelect, $strFrom, $strWhere) = $this->arPrepared;

            return SparqlEngineDb_ResultConverter::convertFromDbResults(
                $this->queryMultiple(
                    $this->ts->getOrderifiedSqls(
                        $strSelect,
                        $strFrom,
                        $this->pr->replacePlaceholdersWithVariables(
                            $strWhere,
                            $this->sg->getPlaceholders(),
                            $arVariables
                        )
                    )
                ),
                $this,
                $resultform
            );
        }
    }//public function execute($arVariables, $resultform)



    /**
    *   Executes multiple SQL queries and returns an array
    *   of results.
    *
    *   @param array $arSqls     Array of SQL queries
    *   @return array   Array of query results
    */
    protected function queryMultiple($arSqls)
    {
        $arSM = $this->query->getSolutionModifier();
        if ($arSM['limit'] === null && $arSM['offset'] === null) {
            $nOffset = 0;
            $nLimit  = null;
            $nSql    = 0;
        } else {
            $offsetter = new Erfurt_Sparql_EngineDb_Offsetter_Adapter_RapZendDb($this->dbConn, $this->query);
            list($nSql, $nOffset) = $offsetter->determineOffset($arSqls);
            $nLimit    = $arSM['limit'];
        }

        $nCount    = 0;
        $arResults = array();
        foreach ($arSqls as $nId => $arSql) {
            if ($nId < $nSql) { continue; }

            if ($nLimit != null) {
                $nCurrentLimit = $nLimit - $nCount;
            } else {
                $nCurrentLimit = null;
            }

            //$this->dbConn->setFetchMode(Zend_Db::FETCH_ASSOC);
            $dbResult = $this->queryDb($arSql, $nOffset, $nCurrentLimit)->fetchAll();
            
            //var_dump($dbResult);exit;
            //$this->dbConn->setFetchMode(Zend_Db::FETCH_NUM);
            
            $nCount     += count($dbResult);
            $arResults[] = $dbResult;
            $nOffset = 0;
            if ($nLimit !== null && $nCount >= $nLimit) {
                break;
            }
            
            
        }

        return $arResults;
        //return array_map(array($this, 'queryDb'), $arSql);
    }//protected function queryMultiple($arSql)



    /**
    *   Sends the sql to the database and returns the results.
    *
    *   @internal Switches between ADOConnection::Execute() and
    *    ADOConnection::SelectLimit() depending on the $query parameter's
    *    $solutionModifier "limit" and "offset" settings.
    *   Uses $query variable.
    *
    *   @param array $arSql     Array that gets a SQL query string once imploded
    *
    *   @return mixed           Anything ADOConnection::Execute() may return
    *   @throws Exception       If Database query does not work
    */
    function queryDb($arSql, $nOffset, $nLimit)
    {
        $strSql = Erfurt_Sparql_EngineDb_SqlMerger::getSelect($this->query, $arSql);

        if ($strSql == '()') {
            return array();
        }

#var_dump($arSql);
        #if (isset($GLOBALS['debugSparql']) && $GLOBALS['debugSparql']) {
        #    echo 'SQL query: ' . $strSql . "\n";
        #}
        
        if ($nLimit === null && $nOffset == 0) {
            $ret = $this->dbConn->query($strSql);
        } else if ($nLimit === null) {
// TODO limit
            //$ret = $this->dbConn->sqlQueryLimit($strSql, -1, $nOffset, 'assoc');
            $ret = $this->dbConn->query($strSql . ' LIMIT ' . $nOffset . ', 18446744073709551615');
            
			
        } else {
// TODO limit
            //$ret = $this->dbConn->sqlQueryLimit($strSql, $nLimit, $nOffset, 'assoc');
            $ret = $this->dbConn->query($strSql . ' LIMIT ' . $nOffset . ', ' . $nLimit);
            

        }
#echo '<pre>' . $strSql . '</pre>';
//Erfurt_App::getInstance()->getLog()->debug(array_pop(array_values(array_pop($ret))));
        return $ret;
    }//function queryDb($sql)



    /**
    *   Set options to subobjects like SqlGenerator
    */
    protected function setOptions()
    {
        //allow changing the statements' table name
        if (isset($GLOBALS['RAP']['conf']['database']['tblStatements'])) {
            $this->sg->setStatementsTable(
                $GLOBALS['RAP']['conf']['database']['tblStatements']
            );
        }
    }//protected function setOptions()



    /*
    *   Dumb getters
    */



    public function getQuery()
    {
        return $this->query;
    }//public function getQuery()



    public function getSqlGenerator()
    {
        return $this->sg;
    }//public function getSqlGenerator()



    public function getTypeSorter()
    {
        return $this->ts;
    }//public function getTypeSorter()

}//class SparqlEngineDb
?>