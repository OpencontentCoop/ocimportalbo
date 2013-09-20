<?php

/*
<atto>
    <id_atto>67347</id_atto>
    <id_ente>22118</id_ente>
    <desc_ente>Comune di Moena</desc_ente>
    <data_pubblicazione>04/05/2011</data_pubblicazione>
    <data_termine>06/05/2011</data_termine>
    <durata>2</durata>
    <descrizione>Decreti e ordinanze Il Vice Sindaco del 29/04/2011</descrizione>
    <oggetto>Ordinanza n. 20</oggetto>
    <tipo_atto>Decreti e ordinanze</tipo_atto>
    <url>20110504_67347_22118_3_ordinan.pdf</url>
    <allegati>
        <allegato>
            <titolo>Corografia</titolo>
            <url>66813_145955_1.pdf</url>
        </allegato>
    </allegati>
    <altri_enti/>
</atto>
*/

/*
 php extension/sqliimport/bin/php/sqlidoimport.php --source-handlers="alboimporthandler" --options="alboimporthandler::<nome>=<valore>" -s<siteaccess>

 Opzioni possibili:
 ente=<nome ente> => importa gli atti dell'ente il nome dell'ente deve coincidere con http://www.albotelematico.tn.it/archivio/<ente>/exc.xml
 comunita=<nome comunità> => importa gli atti dei comuni della comunita
 update=true => reimporta tutti gli atti (di default se trova un oggetto non lo riscrive)
 test=comunita => mostra il nome della comunità
 test=comuni mostra i comuni fecciati
 test=xml mostra xml ricavato e aggregato
 test=feed mostra i controlli di esistenza dei feed ricavati
 class=class_identifier lavora solo gli oggetti della classe selezionata
 id=XXX importa solo l'id atto passato
 clean= cancella l'atto e lo ripubblica
*/

class AlboImportHandler extends SQLIImportAbstractHandler implements ISQLIImportHandler
{
    protected $rowIndex = 0;
    protected $rowCount;
    protected $currentGUID;
	protected $currentName, $currentDescEnte, $currentTipoAtto, $currentFileUrl, $currentAnno, $currentComunita;
    protected $tipi_atto, $done;
    public $varDir, $logDir;
    public $cacheComuni;
    public $tools;
    /**
     * Constructor
     */
    public function __construct( SQLIImportHandlerOptions $options = null )
    {
        parent::__construct( $options );
        $this->remoteIDPrefix = $this->getHandlerIdentifier().'-';
        $this->currentRemoteIDPrefix = $this->remoteIDPrefix;
        $this->options = $options;
    }


    public function initialize()
    {
        $user = eZUser::fetchByName( 'admin' );
        if ( !$user )
        {
            $user = eZUser::currentUser();
        }
        eZUser::setCurrentlyLoggedInUser( $user, $user->attribute( 'contentobject_id' ) );

        $this->cli->output('Carico in memoria i valori xml... ' );
        $siteINI = eZINI::instance( 'site.ini' );
        $var = $siteINI->variable( 'FileSettings','VarDir' );
        $this->varDir = $var . '/import/';
        $this->logDir = $var . '/import_log/';

        $this->currentComunita = false;
        $this->tools = new EntiLocaliTools();
        $comuni = array();
        $feeds = array();

        if ( isset( $this->options['test'] ) && $this->options['test'] == 'comunita' )
        {
			$this->cli->warning( "Test: " . $this->ricavaComunita( $this->options['comunita'] ) );
            throw new Exception( 'Fine test' );
        }

        if ( isset( $this->options['comunita'] ) )
        {
            $comunita = $this->ricavaComunita( $this->options['comunita'] );
            if ( !empty( $comunita ) )
            {
                $comunita = explode( '-', $comunita );
                $this->currentComunita = $comunita[0];
                $this->cli->output( 'Comunita objectID: ' . $this->currentComunita  );
                $dataMap = eZContentObject::fetch( $comunita[0] )->dataMap();
                $attributeComuni = $dataMap['comuni']->content();
                foreach( $attributeComuni['relation_list'] as $attribute )
                {
                    $object = eZContentObject::fetch( $attribute["contentobject_id"] );
                    $dataMap = $object->attribute( 'data_map' );
                    $comuni[] = $dataMap['name']->content();
                }
            }
            else
            {
                 $this->cli->error( 'Comunita non trovata' );
				 sleep( 1 );
                 return;
            }
        }

        if ( isset( $this->options['ente'] ) )
        {
            $comuni = array( $this->options['ente'] );
        }

        if ( isset( $this->options['test'] ) && $this->options['test'] == 'comuni' )
        {
			$this->cli->warning( var_export( $comuni, 1 ) );
            throw new Exception( 'Fine test' );
        }

        if ( empty( $comuni ) && $this->handlerConfArray['SourceComuni'] )
        {
            $ComuniFeedUrl = eZSys::rootDir() . eZSys::fileSeparator() . $this->handlerConfArray['SourceComuni'];
            $ComuniXmlOptions = new SQLIXMLOptions( array(
                'xml_path'      => $ComuniFeedUrl,
                'xml_parser'    => 'simplexml'
            ) );
            $ComuniXml = new SQLIXMLParser( $ComuniXmlOptions );

            $ComuniXml = $ComuniXml->parse();
            for( $i=0; $i<count($ComuniXml->comune); $i++ )
            {
                $comune = $ComuniXml->comune[$i];
                $comuni[] = (string) $comune->name;
            }
        }
        $xmlCount = 0;
        $xmlParser = false;

        foreach( $comuni as $FeedIdComune )
        {
            $FeedIdComune = str_replace( " ", "-", strtolower( $FeedIdComune ) );
            $FeedIdComune = str_replace( "\'", "-", strtolower( $FeedIdComune ) );
            $FeedIdComune = str_replace( "'", "-", strtolower( $FeedIdComune ) );

            $eccezioni = eZINI::instance( 'alboimporthandler.ini' )->variable( 'NomiFeed', 'StringaFeed' );
            if ( isset( $eccezioni[$FeedIdComune] ) )
            {
                $FeedIdComune = $eccezioni[$FeedIdComune];
            }
            
            $FeedXML = str_replace("---", $FeedIdComune, $this->handlerConfArray['FeedBase']);
            $feeds[] = $FeedXML . ': ' . var_export( eZHTTPTool::getDataByUrl( $FeedXML, true ), 1 );
            if ( eZHTTPTool::getDataByUrl( $FeedXML, true ) )
            {
                $xmlOptions = new SQLIXMLOptions( array(
                    'xml_path'      => $FeedXML,
                    'xml_parser'    => 'simplexml'
                ) );
                $xmlParserComune = new SQLIXMLParser( $xmlOptions );
                $xmlParserComune = $xmlParserComune->parse();
                $xmlCount += (int) $xmlParserComune->atti->numero_atti;
                if ( $xmlParser !== false )
                {
                    self::append_simplexml( $xmlParser, $xmlParserComune->atti );
                }
                else
                {
                    $xmlParser = $xmlParserComune->atti;
                }
            }
            else
            {
                eZDebug::writeWarning('Url non trovata: ' . $FeedXML, __METHOD__);
            }
        }

        if ( isset( $this->options['test'] ) && $this->options['test'] == 'feed' )
        {
			$this->cli->notice( var_export( $feeds, 1 ) );
            throw new Exception( 'Fine test' );
        }

        $this->cli->output( $xmlCount . ' atti caricati.'. "\n" );
        $this->tipi_atto = array();
        $this->dataSource = $xmlParser;
        if ( isset( $this->options['test'] ) && $this->options['test'] == 'xml' )
        {
			$this->cli->notice( $xmlParser->saveXML() );
            eZLog::write( $xmlParser->saveXML(), 'test_xml.log' );
            throw new Exception( 'Fine test' );
        }
        $this->done = 0;
        //eZFile::create( time() . '.import.xml', $this->varDir, var_export( $this->dataSource, 1 ) );
        eZFile::create( $this->getHandlerIdentifier() . '.import.' . time() . '.xml', $this->logDir, $xmlParser->saveXML() );
    }


    public function getProcessLength()
    {

        if( !isset( $this->rowCount ) )
        {
            $this->rowCount = count( $this->dataSource->atto );
        }
        return $this->rowCount;
    }


    public function getNextRow()
    {
        //if ($this->done == true) return false;
        if ( $this->rowIndex < $this->rowCount )
        {
            $row = $this->dataSource->atto[$this->rowIndex];
            $this->rowIndex++;
        }
        else
        {
            $row = false; // We must return false if we already processed all rows
        }
        return $row;
    }

    public function disambigueClassIdentifier( $ini_result )
    {
        $class_identifier = explode( ';', $ini_result );
        return $class_identifier[0];
    }

    public function process( $row )
    {
		/*
		if( $this->rowIndex == 1 )
		{
			var_export( $row );
			sleep( 10 );
		}
		*/
		
        if ( isset( $this->options['id'] ) && $this->options['id'] != (string) $row->id_atto )
        {
            return;
        }
    
        $this->currentGUID = (string) $row->id_atto;
        $this->currentDescEnte = (string) $row->desc_ente;
        $this->currentTipoAtto = (string) $row->tipo_atto;
        $this->currentName = $this->currentDescEnte . ' ' . $this->currentTipoAtto . ' ' . $this->currentGUID;

		// @luca spostato in alboimporthandler.ini - inizio 
        //if( $row->desc_ente == "Comunita Val di Non" ) // modificato da Alessandro il 3 gennaio 2013
		//{
		//	$row->desc_ente = "Comunita della Val di Non";
		//	//$this->cli->warning($row->desc_ente);
		//	//sleep(1);
		//}
        // @luca spostato in alboimporthandler.ini - fine
        
        $comunitaObjectID = $this->tools->ricavaComunitaDaComune( $row->desc_ente, true ) ? $this->tools->ricavaComunitaDaComune( $row->desc_ente ) : $this->currentComunita;

        if ( !$comunitaObjectID && !$this->currentComunita )
        {
            $this->cli->error( 'Comunità da comune (' . $row->desc_ente . ') non trovata' );            
            return;
        }

        if ( !in_array( $this->currentTipoAtto, $this->tipi_atto ) )
        {
            $this->tipi_atto[] = $this->currentTipoAtto;
        }

        $handlerINI = eZINI::instance( $this->handlerConfArray['INI'].'.ini' );
        $MapClass = $handlerINI->variable( 'MapClassSettings' , 'MapClass' );
        $class_identifier = $handlerINI->variable( 'MapClassSettings', 'MapClassDefault' );

        if ( isset( $MapClass[$this->currentTipoAtto] ) )
        {
            $class_identifier = $MapClass[$this->currentTipoAtto];
        }
        else
        {
            $this->cli->error( 'Nessuna classe associata a ' . $this->currentTipoAtto, __METHOD__ );
            return;
        }

        $class_identifier = $this->disambigueClassIdentifier( $class_identifier );

        if ( !eZContentClass::fetchByIdentifier( $class_identifier ) )
        {
            $this->cli->error( 'Nessuna classe trovata per ' . $this->currentTipoAtto . " tanto meno $class_identifier che non esiste...", __METHOD__ );
            return;
        }

        $ParentNodeID = $this->tools->ricavaStorageComunita( $comunitaObjectID );
        if ( !$ParentNodeID  )
        {
            $ParentNodeID = $this->handlerConfArray['DefaultParentNodeID'];
        }

        $map_attributes = $handlerINI->hasVariable( $class_identifier, 'MapAttribute' ) ? $handlerINI->variable( $class_identifier, 'MapAttribute' ) : array();
        $anno = explode( ';', $handlerINI->variable( $class_identifier, 'Anno' ) );

        $MediaFileNodeID = false;
        $MediaImageNodeID = false;

        $arg = array(
            'parent_node_id' => $ParentNodeID,
            'media_file_node_id' => self::mediaFileComunita( $comunitaObjectID ),
            'media_image_node_id' => self::mediaImageComunita( $comunitaObjectID )
        );
        if ( !self::mediaFileComunita( $comunitaObjectID ) )
        {
            $this->cli->error( 'Problemi con atto #' . $this->currentGUID );
            return;
        }

        $xmlField = $anno[0];
        if ( isset( $anno[1]) )
        {
            $custom = $anno[1];
            if ( method_exists( $this, $custom ) )
            {
                $this->currentAnno = $this->$custom( $this->puliciStringa( $row->{$xmlField} ), $arg );
            }
            else
            {
                $this->cli->error( $custom . ' per anno non valido', __METHOD__ );
            }

        }
        else
        {
            $this->currentAnno = $this->puliciStringa( $row->{$xmlField} );
        }


        $this->currentFileUrl = str_replace("ENTE", $row->id_ente, $this->handlerConfArray['FileUrl']);
        $this->currentFileUrl = str_replace("ANNO", $this->currentAnno, $this->currentFileUrl);

        $has_allegati = $this->hasAllegati( $row->allegati);

        if ( isset( $this->options['class'] ) && $this->options['class'] != $class_identifier )
        {
            return;
        }
        
        $contentOptions = new SQLIContentOptions( array(
            'class_identifier'      => $class_identifier,
            'creator_id'      	    => '14',
            'remote_id'             => md5( $this->currentGUID )
        ) );

        if ( !isset( $this->options['clean'] )
             && $object = eZContentObject::fetchByRemoteID( $contentOptions->attribute( 'remote_id' ) ) )
        {
            eZContentObjectOperations::remove( $object->attribute( 'contentobject_id' ) );
        }
        
        if ( !isset( $this->options['update'] )
             && SQLIContent::fromRemoteID( $contentOptions->attribute( 'remote_id' ) ) !== null )
        {
            return;
        }

        $content = SQLIContent::create( $contentOptions );

        foreach( $map_attributes as $attribute_identifier => $map_attribute)
        {
            $map_attribute = explode( ';', $map_attribute );
            $attribute = false;
            $xmlField = $map_attribute[0];
            if ( isset( $map_attribute[1]) )
            {
                $custom = $map_attribute[1];
                if ( method_exists( $this, $custom ) )
                {
                    $attribute = $this->$custom( (string) $row->{$xmlField}, $arg );
                }
                else
                {
                    $this->cli->error( $custom . ' per ' . $attribute_identifier . ' non valido', __METHOD__ );
                }

            }
            else
            {
                $attribute = $this->puliciStringa( $row->{$xmlField} );
            }
            //$this->cli->output( $attribute_identifier . ': ' . $attribute );
            if ( isset( $content->fields->{$attribute_identifier} ) )
                $content->fields->{$attribute_identifier} = $attribute;
            else
                $this->cli->error( "L'attributo $attribute_identifier non esiste in $class_identifier", false );
            //print "\n\n".$attribute_identifier . "=" . $attribute;
        }

        $content->addLocation( SQLILocation::fromNodeID( $ParentNodeID ) );

        $publisher = SQLIContentPublisher::getInstance();
        $publisher->publish( $content );
        if ( $this->dateToTimestamp( $row->data_pubblicazione ) )
        {
            $contentObject = $content->getRawContentObject();
            $contentObject->setAttribute( 'published', $this->dateToTimestamp( $row->data_pubblicazione ) );
            $contentObject->store();
        }
        $this->cli->notice( "Pubblicato oggetto $class_identifier #" . $contentObject->attribute( 'id' ) );
        unset( $content );

        $this->done++;
        return;
    }

    public function cacheComuni( $parentNode = 688 )
    {
        $params = array(
            'Limit'                    => 250,
            'ClassFilterType'          => 'include',
            'ClassFilterArray'         => array( 'comune' )
        );
        $subTree = eZContentObjectTreeNode::subTreeByNodeID( $params, $parentNode );
        foreach( $subTree as $node )
        {
            $dataMap = $node->attribute( 'data_map' );                    ;
            $this->cacheComuni[$dataMap['name']->content()] = $node->attribute( 'contentobject_id' );
        }
    }

    public function cleanup()
    {
        eZFile::create( $this->getHandlerIdentifier() . '.tipi_atto.' . time() . '.txt', $this->logDir, var_export( $this->tipi_atto, 1 ) );
        return;
    }

    public function getHandlerName()
    {
        return 'Albo Import Handler';
    }

    public function getHandlerIdentifier()
    {
        return 'alboimporthandler';
    }

    public function getProgressionNotes()
    {
        print ' ' . $this->done .' ' .  $this->currentDescEnte . ': atto  num. '.$this->currentGUID;
    }

    public static function append_simplexml(&$simplexml_to, &$simplexml_from)
    {

        static $firstLoop=true;

        //Here adding attributes to parent
        if( $firstLoop )
        {
             foreach( $simplexml_from->attributes() as $attr_key => $attr_value )
             {
                 $simplexml_to->addAttribute($attr_key, $attr_value);
             }
        }

        foreach ($simplexml_from->children() as $simplexml_child)
        {
            $simplexml_temp = $simplexml_to->addChild($simplexml_child->getName(), (string) $simplexml_child);
            foreach ($simplexml_child->attributes() as $attr_key => $attr_value)
            {
                $simplexml_temp->addAttribute($attr_key, $attr_value);
            }

            $firstLoop=false;

            self::append_simplexml($simplexml_temp, $simplexml_child);
        }

        unset( $firstLoop );
    }


    private function dateToTimestamp( $string )
    {
        //gg/mm/aaaa
        //$this->cli->error( $string );
        $string = str_replace( 'del', '', $string );
        $data = explode( '/', trim( $string ) );
        $time = mktime(0, 0, 0, $data[1], $data[0], $data[2]);

        $oggi = time();
        $oggifradueanni = $oggi + (2 * 365 * 24 * 60 * 60);

        if ( $time > $oggifradueanni )
            return false;
        return $time;
    }

    public static function storageComunita( $comunitaObjectID )
    {
        $objectComunita = eZContentObject::fetch( $comunitaObjectID );
        $dataMap = $objectComunita->dataMap();
        if ( isset( $dataMap['storage'] ) )
        {
            $object_id = $dataMap['storage']->toString();
            if ( $object_id )
            {
                $node_id = eZContentObject::fetch( $object_id )->attribute( 'main_node_id' );
                return $node_id;
            }
        }
        return false;
    }

    public static function mediaFileComunita( $comunitaObjectID )
    {
        $objectComunita = eZContentObject::fetch( $comunitaObjectID );
        $dataMap = $objectComunita->dataMap();
        if ( isset( $dataMap['media'] ) )
        {
            $object_id = $dataMap['media']->toString();
            $object = eZContentObject::fetch( $object_id  );
            if ( $object )
            {
                $media_node = $object->attribute( 'main_node' );
                foreach( $media_node->children() as $node )
                {
                    if ( $node->attribute( 'name' ) == 'File' )
                    {
                        return $node->attribute( 'node_id' );
                        break;
                    }
                }
            }
        }
        eZCLI::instance()->error( "Comunità objectID #$comunitaObjectID, non ha un media folder (" . $dataMap['media']->toString() . ') in ' . __METHOD__ );
        return false;
    }

    public static function mediaImageComunita( $comunitaObjectID )
    {
        $objectComunita = eZContentObject::fetch( $comunitaObjectID );
        $dataMap = $objectComunita->dataMap();
        if ( isset( $dataMap['media'] ) )
        {
            $object_id = $dataMap['media']->toString();
            $object = eZContentObject::fetch( $object_id  );
            if ( $object )
            {
                $media_node = $object->attribute( 'main_node' );
                foreach( $media_node->children() as $node )
                {
                    if ( $node->attribute( 'name' ) == 'Immagini' )
                    {
                        return $node->attribute( 'node_id' );
                        break;
                    }
                }
            }
        }
        eZCLI::instance()->error( "Comunità objectID #$comunitaObjectID, non ha un media folder (" . $dataMap['media']->toString() . ') in ' . __METHOD__ );
        return false;
    }

    private function ricavaCompetenza( $string )
    {
        //Delibere della Giunta del 28/04/2011
        $competenze = array(
            'Giunta' => 'Giunta',
            'Consiglio' => 'Consiglio',
            'Consiglio circoscrizionale' => 'Consiglio circoscrizionale'
        );
        foreach( $competenze as $competenza )
        {
            if ( strpos( $string, $competenza ) !== false )
            {
                return $competenza;
            }
        }
        $data = explode( ' ', (string)  $string );
        return isset( $competenze[ $data[2] ] ) ? $competenze[ $data[2] ] : false;
    }

    private function ricavaNumero( $string )
    {
        //Deliberazione giuntale n. 80
        //Deliberazioni giuntali dalla n. 79 alla n. 81 della seduta del 21.04.2011
        $strings = array( 'n.', 'numero' );
        foreach( $strings as $s )
        {
            $_data = explode( $s, (string) $string );
            $data = explode( ' ', $_data[1] );
            $numero = (int) $data[1];            
            if ( $numero > 0 )
            {
                break;
            }
        }
        if ( $numero == 0 )
            $numero = $this->currentGUID;
        return $numero;
    }

    //private function ricavaAnno( $string )
    //{
    //    $data = $this->ricavaData( $string );
    //    $anno = date( 'Y', $data );
    //    return $anno;
    //}
    private function ricavaAnno( $string )
    {
        $data = $this->dateToTimestamp( $string );        
        $anno = date( 'Y', $data );
        return $anno;
    }

    private function ricavaData( $string )
    {
        //Delibere della Giunta del 28/04/2011
        $data = explode( ' ', (string) $string );
        return $this->dateToTimestamp( array_pop( $data ) );
    }

    public function ricavaComunitaDaComune( $string )
    {
        $string = (string) $string;
        $comune = $this->ricavaComune( $string );
        $comune = explode( '-', $comune );
        $comune = $comune[0];
        $class = eZContentClass::fetchByIdentifier( 'comunita' );
        $attributes = eZContentClassAttribute::fetchListByClassID( $class->attribute( 'id' ) );
        $attribute = false;
        foreach ( $attributes as $classAttribute )
        {
            if ( $classAttribute->attribute( 'identifier' ) == 'comuni' )
            {
                $attribute = $classAttribute;
                break;
            }
        }
        $objectComune = eZContentObject::fetch( $comune );
        $reverseRelated = $comune->reverseRelatedObjectList( false, $attribute->attribute( 'id' ) );
        return $reverseRelated;
    }

    public function ricavaComunita( $string )
    {
        $string = (string) $string;
        $comuni = '';
        $class = eZContentClass::fetchByIdentifier( 'comunita' );
        $attributes = eZContentClassAttribute::fetchListByClassID( $class->attribute( 'id' ) );
        $attribute = false;
        foreach ( $attributes as $classAttribute )
        {
            if ( $classAttribute->attribute( 'identifier' ) == 'name' )
            {
                $attribute = $classAttribute;
                break;
            }
        }
        if ( $class && $attribute )
        {
            $comuni .= OCImportTools::search( $string, $class->attribute( 'id' ), $attribute->attribute( 'id' ) );
        }
        return $comuni;
    }

    public function ricavaComune( $string )
    {
        $string = (string) $string;
        $string = str_replace("Comune di ", '', $string);

        if ( isset( $this->cacheComuni[$string] ) )
            return $this->cacheComuni[$string];

        $class = eZContentClass::fetchByIdentifier( 'comune' );
        $attributes = eZContentClassAttribute::fetchListByClassID( $class->attribute( 'id' ) );
        $attribute = false;
        foreach ( $attributes as $classAttribute )
        {
            if ( $classAttribute->attribute( 'identifier' ) == 'name' )
            {
                $attribute = $classAttribute;
                break;
            }
        }
        if ( $class && $attribute )
        {
            $comuni = OCImportTools::search( $string, $class->attribute( 'id' ), $attribute->attribute( 'id' ) );
        }
        $this->cacheComuni[$string] = $comuni;
        return $comuni;
    }

    private function uploadFile( $path, $fileName, $parentNodeID = false )
    {
        //return $this->currentFileUrl . $path;
        $fileContentOptions = new SQLIContentOptions( array(
            'class_identifier'      => 'file',
            'creator_id'      	    => '14',
            'remote_id'             => md5( $path )
        ) );
        $fileContent = SQLIContent::create( $fileContentOptions );
        $fileContent->fields->name = $fileName;
        $fileName = str_replace(" ", "-", strtolower( $fileName ));

        $path = (string) $path;
        $url = $this->currentFileUrl . $path;

        $path = str_replace("/", "-", strtolower( $path ));
        $filePath = $this->varDir . $path;
        $h = fopen($filePath,'w');
        if($h)
        {
            fwrite($h, eZHTTPTool::getDataByURL($url));
            fclose($h);
        }
        $fileContent->fields->file = $filePath;
        unlink( $filePath );
        $fileContent->addLocation( SQLILocation::fromNodeID( $parentNodeID ) );
        $filePublisher = SQLIContentPublisher::getInstance();
        $filePublisher->publish( $fileContent );
        $return = $fileContent->getRawContentObject();
        unset( $fileContent );
        return $return->attribute('id');

    }

    private function urlFile( $xmlfield, $arg = array() )
    {
        $path = (string) $xmlfield;
        $url = $this->currentFileUrl . $path;
        //$this->cli->output( $url );
        $path = str_replace("/", "-", strtolower( $path ));
        $filePath = $this->varDir . $path;
        eZFile::create($path, $this->varDir, eZHTTPTool::getDataByURL($url) );
        return $filePath;
    }

    private function uploadCorpo( $xmlfield, $arg = array() )
    {
        $path = (string) $xmlfield;
        return $this->uploadFile( $path, $this->currentName, $arg['media_file_node_id'] );
    }

    private function uploadAllegati( $xmlfield, $arg = array() )
    {
        $ids = array();
        if ( is_a( $xmlfield, 'SimpleXMLElement') )
        {
            foreach ($xmlfield->children() as $allegato)
            {
                $data = array(
                    'titolo' => (string) $allegato->titolo,
                    'url' => (string) $allegato->url,
                );
                $ids[] = $this->uploadFile(
                    'allegati/' . $data['url'],
                    $this->currentName . '-' . $data['titolo'],
                    $arg['media_file_node_id']
                );
            }
        }
        return implode('-',$ids);
    }

    private function hasAllegati( $xmlfield )
    {
        $data = array();
        if ( is_a( $xmlfield, 'SimpleXMLElement') )
        {
            foreach ($xmlfield->children() as $allegato)
            {
                $data[] = array(
                    'titolo' => (string) $allegato->titolo,
                    'url' => (string) $allegato->url,
                );
            }
        }
        return empty( $data ) ? false : true;
    }

    private function puliciStringa( $string )
    {
        $string = (string) $string;
        // @TODO smartellare la pulizia
        // importando gli atti della comunità rotaliana compaiono questi encoding
        $string = str_replace( '&#00246;', 'ö', $string );
        $string = str_replace( '&#00224;','à', $string );
        return $string;
    }

}
