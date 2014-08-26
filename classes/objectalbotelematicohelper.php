<?php

class ObjectAlbotelematicoHelper extends AlbotelematicoHelperBase implements AlbotelematicoHelperInterface
{
    const CONTAINER_CLASS_IDENTIFIER = 'albotelematicotrentino';

    public static $identifierMap = array(
        'current_feed' => 'url_bacheca', //http://www.albotelematico.tn.it/bacheca/rovere-della-luna/exc.xml
        'archive_feed' => 'url_archivio', //http://www.albotelematico.tn.it/archivio/rovere-della-luna/exc.xml
    );

    /**
     * @var eZContentObject
     */
    protected $object;

    /**
     * @var eZContentObjectAttribute
     */
    protected $dataMap;

    /**
     * @var array
     */
    protected $feeds = array();

    /**
     * @var SimpleXMLElement
     */
    public $data;

    protected function loadObject()
    {
        $objectID = $this->arguments['object'];
        $this->object = eZContentObject::fetch( $objectID );
        if ( !$this->object instanceof eZContentObject )
        {
            throw new AlboFatalException( 'Oggetto contenitore non trovato: ' . $objectID );
        }
        $this->dataMap = $this->object->attribute( 'data_map' );
    }


    protected function getFeeds()
    {
        $attribute = $this->dataMap[self::$identifierMap['current_feed']];
        if ( $attribute instanceof eZContentObjectAttribute && $attribute->attribute( 'has_content' ) )
        {
            $this->feeds['current_feed'] = rtrim( trim( $attribute->toString() ), '/' ) . '/exc.xml';
        }
        $attribute = $this->dataMap[self::$identifierMap['archive_feed']];
        if ( $attribute instanceof eZContentObjectAttribute && $attribute->attribute( 'has_content' ) )
        {
            $this->feeds['archive_feed'] = rtrim( trim( $attribute->toString() ), '/' ) . '/exc.xml';
        }
        return $this->feeds;
    }

    public function loadData()
    {
        $this->loadObject();
        $feedPaths = $this->getFeeds();
        foreach( $feedPaths as $feedPath )
        {
            if ( eZHTTPTool::getDataByUrl( $feedPath, true ) )
            {
                $xmlOptions = new SQLIXMLOptions( array( 'xml_path' => $feedPath,
                                                         'xml_parser' => 'simplexml' ));
                $parser = new SQLIXMLParser( $xmlOptions );
                $parsed = $parser->parse();
                $this->dataCount += (int) $parsed->atti->numero_atti;
                if ( $this->data instanceof SimpleXMLElement )
                {
                    self::append_simplexml( $this->data, $parsed->atti );
                }
                else
                {
                    $this->data = $parsed->atti;
                }
            }
            else
            {
                throw new AlboFatalException( 'Url non risolto: ' . $feedPath );
            }
        }
        //eZFile::create( 'data.xml', eZSys::cacheDirectory(), $this->data->saveXML() );
    }

    public function availableArguments()
    {
        return array(
            'object' => true,
            'field' => false,
            'value' => false,
            'test' => false
        );
    }
    
    public function getClassIdentifier()
    {
        $this->classIdentifier = false;
        $classIdentifiers = array();
        $tipoAtto = (string) $this->row->tipo_atto;

        $classMaps = (array) $this->ini->variable( 'MapClassSettings', 'MapClass' );
        foreach( $classMaps as $alboClass => $classIdentifier )
        {
            if ( $alboClass ==  $tipoAtto )
            {
                $classIdentifiersParts = explode( ';', $classIdentifier );
                $classIdentifiers = explode( '|', $classIdentifiersParts[0] );
                break;
            }
        }

        if ( count( $classIdentifiers ) == 1 )
        {
            $this->classIdentifier = $classIdentifiers[0];
        }
        else
        {
            $this->classIdentifier = $this->valueDisambiguation( 'class', $classIdentifiers );
        }

        if ( !$this->classIdentifier )
        {
            throw new AlboFatalException( 'Non trovo la classe per ' . $tipoAtto );
        }
        
        if ( !eZContentClass::fetchByIdentifier( $this->classIdentifier ) )
        {
            throw new AlboFatalException( "Classe {$this->classIdentifier} non installata" );
        }
        
        return $this->classIdentifier;
    }

    public function getLocation( $identifier )
    {
        $location = $relation = false;
        $trans = eZCharTransform::instance();
        $identifier = $trans->transformByGroup( $identifier, 'identifier' );
        $attribute = isset( $this->dataMap[$identifier] ) ? $this->dataMap[$identifier] : false;
        if ( $attribute instanceof eZContentObjectAttribute
             && $attribute->attribute( 'has_content' ) )
        {
            $content = $attribute->attribute( 'content' );
            $relations = $content['relation_list'];
            if ( count( $relations ) == 1 )
            {
                $relation = $relations[0];
            }
            else
            {
                $relation = $this->valueDisambiguation( 'location', $relations );
            }
        }
        if ( is_array( $relation ) )
        {
            if ( isset( $relation['node_id'] ) )
            {
                $location = $relation['node_id'];
            }
            else
            {
                $relatedObject = eZContentObject::fetch( $relation['contentobject_id'] );
                if ( $relatedObject instanceof eZContentObject )
                {
                    $location = $relatedObject->attribute( 'main_node_id' );
                }
            }
        }
        return $location;
    }

    public function getLocations()
    {
        $this->locations = array();
        $identifier = (string) $this->row->tipo_atto;
        $this->locations[] = $this->getLocation( $identifier );

        $perContoDi = false;
        //@todo pubblicaNto????
        if( !empty( $this->row->pubblicanto_per_conto_di ) )
        {
            $perContoDi = '(per conto di ' . $this->row->pubblicanto_per_conto_di . ')';
            $this->locations = array();
            if( $perContoDiLocation = $this->getLocation( 'Per conto di' ) )
            {
                $this->locations[] = $perContoDiLocation;
            }
        }

        foreach( $this->locations as $i => $location )
        {
            if ( !eZContentObjectTreeNode::fetch( $location ) )
            {
                unset( $this->locations[$i] );
            }
        }

        if ( empty( $this->locations ) )
        {
            //throw new AlboFatalException( "Non trovo la collocazione per atti di tipo $identifier $perContoDi" );
            $this->locations = array( $this->object->attribute( 'main_node_id' ) );
            $this->registerError( "Non trovo la collocazione per atti di tipo $identifier $perContoDi, l'atto viene salvato sotto il nodo dell'albo" );
        }
        return $this->locations;
    }

    function saveINILocations( $data )
    {
        return false;
    }

    function cleanup()
    {
        parent::cleanup();
        $feed = str_replace( 'bacheca', 'archivio_stato', $this->feeds['current_feed'] );
        self::fixObjectStates( $feed );
    }

    public static function fixObjectStates( $feed )
    {
        //http://www.albotelematico.tn.it/archivio_stato/ala/exc.xml
        if ( eZHTTPTool::getDataByUrl( $feed, true ) )
        {
            $xmlOptions = new SQLIXMLOptions( array( 'xml_path' => $feed,
                                                   'xml_parser' => 'simplexml' ));
            $parser = new SQLIXMLParser( $xmlOptions );
            $parsed = $parser->parse();
            $dataCount = (int) $parsed->atti->numero_atti;
            $data = $parsed->atti->atto;
            foreach( $data as $atto )
            {
                $id = (string) $atto->id_atto;
                $state = strtolower( $atto->stato_atto );
                $remoteId = self::buildRemoteId( $id );
                $object = eZPersistentObject::fetchObject(
                    eZContentObject::definition(),
                    array( 'id' ),
                    array( 'remote_id' => $remoteId ),
                    false
                );
                if ( !empty( $object ) )
                {
                    self::setState( $object['id'], $state );
                    eZContentObject::clearCache( array( $object['id'] ) );
                }
            }
        }
        else
        {
            throw new AlboFatalException( 'Url non risolto: ' . $feed );
        }
    }

    public static function appendImporterByObjectId( $objectId )
    {
        $object = eZContentObject::fetch( $objectId );
        if ( $object instanceof eZContentObject )
        {
            $importOptions = new SQLIImportHandlerOptions( array( 'object' => $objectId ) );
            $currentImportHandler = 'alboimporthandler';
            $importFrequency = 'daily';

            $row = array(
                'handler'   => $currentImportHandler,
                'user_id'   => eZUser::currentUserID(),
                'label'     => 'Albo telematico trentino (' . $object->attribute( 'id' ) . ')',
                'frequency' => $importFrequency,
                'next'      => time(),
                'is_active' => 1
            );

            $importID = 0;
            foreach( SQLIScheduledImport::fetchList() as $schedule )
            {
                /** @var SQLIScheduledImport $schedule */
                if ( $schedule->attribute( 'label' ) == $row['label'] )
                {
                    $importID = $schedule->attribute( 'id' );
                    break;
                }
            }

            $scheduledImport = SQLIScheduledImport::fetch( $importID );
            if ( !$scheduledImport instanceof SQLIScheduledImport )
            {
                $scheduledImport = new SQLIScheduledImport( $row );
            }
            else
            {
                $scheduledImport->fromArray( $row );
            }

            if ( $importOptions )
            {
                $scheduledImport->setAttribute( 'options', $importOptions );
            }

            $scheduledImport->store();
        }
    }

}
