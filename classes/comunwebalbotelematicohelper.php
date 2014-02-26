<?php

class ComunWebAlbotelematicoHelper extends AlbotelematicoHelperBase implements AlbotelematicoHelperInterface
{
    private static $xmlComuni;
    public $feed;
    public $comunita;
    public $comuni = array();
    public $tools;    
    public $ocscsource;    
    public $defaultLocation;
    public $cacheComuni = array();
    public $storageLocation;
    
    public function getTools()
    {
        if ( $this->tools == null )
        {
            $this->tools = new EntiLocaliTools();
        }
        return $this->tools;
    }
    
    public function getOCSCSource( $reset = false )
    {
        if ( $this->ocscsource == null )
        {
            OCSCHandler::loadAndRegisterAllSources();
            $this->ocscsource = OCSCHandler::registeredSources( 'albotelematico' );
            if( is_object( $this->ocscsource ) )
            {
                $this->ocscsource->setStorages( array( $this->getStorageLocation() ) );
                if ( $reset == true )
                {
                    $this->ocscsource->resetData();
                }
            }

        }
        return $this->ocscsource;
    }
    
    public function loadData()
    {        
        $trans = eZCharTransform::instance();
        if ( $this->hasArgument( 'comunita' ) )
        {            
            $comunita = $this->ricavaComunita( $this->getArgument( 'comunita' ) );            
            if ( !empty( $comunita ) )
            {
                $comunita = explode( '-', $comunita );
                $this->comunita = $comunita[0];                
                $dataMap = eZContentObject::fetch( $comunita[0] )->dataMap();
                $attributeComuni = $dataMap['comuni']->content();
                foreach( $attributeComuni['relation_list'] as $attribute )
                {
                    $object = eZContentObject::fetch( $attribute["contentobject_id"] );                    
                    $this->comuni[] = $trans->transformByGroup( $object->attribute( 'name' ), "urlalias" );
                }
            }
            else
            {
                throw new AlboFatalException( 'Comunita non trovata' );
            }
        }
        
        if ( $this->hasArgument( 'ente' ) )
        {
            $this->comuni[] = $this->getArgument( 'ente' );            
        }
        
        if ( empty( $this->comuni ) )
        {
            throw new AlboFatalException( 'Nessun comune trovato' );
        }
        
        foreach( $this->comuni as $comune )
        {
            $comune = str_replace( "comune-di-", "", strtolower( $comune ) );
            $comune = str_replace( " ", "-", strtolower( $comune ) );
            $comune = str_replace( "\'", "-", strtolower( $comune ) );
            $comune = str_replace( "'", "-", strtolower( $comune ) );

            $eccezioni = $this->ini->variable( 'NomiFeed', 'StringaFeed' );
            if ( isset( $eccezioni[$comune] ) )
            {
                $comune = $eccezioni[$comune];
            }
            
            $feedPath = str_replace("---", $comune, $this->options['FeedBase']);

            $this->feed[] = $feedPath . ': ' . var_export( eZHTTPTool::getDataByUrl( $feedPath, true ), 1 );
            if ( eZHTTPTool::getDataByUrl( $feedPath, true ) )
            {
                $xmlOptions = new SQLIXMLOptions( array( 'xml_path'      => $feedPath,
                                                         'xml_parser'    => 'simplexml' ) );
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
            if ( $this->comunita == null )
            {
                $this->comunita = $this->getTools()->ricavaComunitaDaComune( $this->data->atto[0]->desc_ente );
                if ( !eZContentObject::fetch( $this->comunita ) )
                {
                    throw new AlboFatalException( 'Comunita di ' . $this->getArgument( 'ente' )  . ' non trovata' );
                }
            }
        }
        
    }

    public function availableArguments()
    {
        return array(
            'ente' => false,
            'comunita' => false,
            'field' => false,
            'value' => false,
            'test' => false
        );
    }
    
    public function validateArguments()
    {
        if ( !$this->hasArgument( 'ente' ) && !$this->hasArgument( 'comunita' ) )
        {
            throw new AlboFatalException( "Specificare ente o comunita" );
        }
    }

    public function getClassIdentifier()
    {
        $defaultLocations = $this->getDefaultLocations();
        $key = (string) $this->row->tipo_atto;
        $this->classIdentifier = false;
        if ( array_key_exists( $key, $defaultLocations ) )
        {
            $classes = $defaultLocations[$key];
            if ( count( $classes ) == 1 )
            {
                $this->classIdentifier = key( $classes );                
            }
            else
            {
                $this->classIdentifier = $this->valueDisambiguation( 'class', array_keys( $classes ) );
            }
        }

        if ( !$this->classIdentifier )
        {
            throw new AlboFatalException( 'Non trovo la classe per ' . $key );
        }
        
        if ( !eZContentClass::fetchByIdentifier( $this->classIdentifier ) )
        {
            throw new AlboFatalException( "Classe {$this->classIdentifier} non installata" );
        }
        
        return $this->classIdentifier;
    }
    
    public function getStorageLocation( $asObject = false )
    {
        if ( $this->comunita == null && $this->row == null )
        {
            $rootNode = eZContentObjectTreeNode::fetch( eZINI::instance( 'content.ini' )->variable( 'NodeSettings', 'RootNode' ) );
            $this->comunita = $rootNode->attribute( 'contentobject_id' );
        }
        $this->storageLocation = $this->getTools()->ricavaStorageComunita( $this->comunita );        
        $storageLocationNode = eZContentObjectTreeNode::fetch( $this->storageLocation );
        if ( !$storageLocationNode instanceOf eZContentObjectTreeNode )
        {
            throw new AlboFatalException( 'Storage non trovato ' . $this->comunita );
        }
        if ( $asObject )
            return $storageLocationNode;
        else
            return $this->storageLocation;
    }

    public function getLocations()
    {        
        $this->locations = array();
        $this->fileLocation = $this->getFileLocationComunita();                
               
        $defaultLocations = $this->getDefaultLocations();
        if ( isset( $defaultLocations[$key][$this->classIdentifier]['node_ids'] ) )
        {
            $nodes = $defaultLocations[$key][$this->classIdentifier]['node_ids'];
            if ( count( $nodes ) == 1 )
            {
                $this->locations[] = $nodes[0];
            }
            else
            {
                $this->locations[] = $this->valueDisambiguation( 'location', $nodes );
            }
        }
        
        //@todo
        // cerco se configurato perconto di
        $perContoDi = false;        
        if( !empty( $this->row->pubblicanto_per_conto_di ) )
        {
            $perContoDi = '(per conto di ' . $this->row->pubblicanto_per_conto_di . ')';
            $this->locations = array();
            if( isset( $defaultLocations['Per conto di'][0]['node_ids'] ) )
            {
                $this->locations[] = $defaultLocations['Per conto di'][0]['node_ids'][0];
            }
        }
        
        // cerco se è di un comune
        $comuni = false;
        $comuniLocations = array();
        if ( $this->row !== null && isset( $this->row->desc_ente ) )
        {
            $rootNode = eZContentObject::fetch( $this->comunita )->attribute( 'main_node_id' );
            $comuni = $this->ricavaComune( (string) $this->row->desc_ente );            
            $comuniIDs = explode( '-', $comuni );            
            foreach( $comuniIDs as $comuneID )
            {
                $comune = eZContentObject::fetch( $comuneID );
                if ( $comune )
                {
                    $assignedNodes = $comune->attribute( 'assigned_nodes' );
                    foreach( $assignedNodes as $assignedNode )
                    {
                        $pathArray = $assignedNode->attribute( 'path_array' );
                        if ( in_array( $rootNode, $pathArray ) )
                        {
                            $comuniLocations[] = $assignedNode->attribute( 'node_id' );
                        }
                    }
                }
            }
        }
        if ( !empty( $comuniLocations ) )
        {
            $this->locations = $comuniLocations;
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
            // non so dove metterlo: lo metto nello storage
            $storageLocation = $this->getStorageLocation();
            $this->locations = array( $storageLocation );
        }
        return $this->locations;        
    }

    /*
     * Restituisce:
     *  array( 'Nome classe in albo' => array(
     *          'identifier di ez' => array(        //se maggiore di 1 da disambiguare vedi AlbotelematicoHelperBase::valueDisambiguation
     *              'node_ids' => array( 1, 2 ),    //se maggiore di 1 da disambiguare vedi AlbotelematicoHelperBase::valueDisambiguation
     *              'type' => default|entilocali    // in base a dove recupera il valore definitivo
     *          )
     *  )
     */
    public function getDefaultLocations( $reset = false )
    {
        $classMaps = (array) $this->ini->variable( 'MapClassSettings', 'MapClass' );
        
        //@todo serve per rimuovere
        $classMaps['Solo per rimozione_convocazioni'] = 'convocazione';
        $classMaps['Solo per rimozione_atti'] = 'atto';
        
        foreach( $classMaps as $alboClass => $classIdentifier )
        {
            $classIdentifiers = explode( ';', $classIdentifier );
            
            foreach( $classIdentifiers as $class )
            {                
                $locations[$alboClass][$class]['node_ids'] =  $this->getOCSCSource( $reset )->getLocationsByClass( $class );            
            }
        }
        //@todo
        $locations['Per conto di'][0]['node_ids'] = array( 0 );
        
        //@comuni
        $comunitaNode = eZContentObjectTreeNode::fetch( eZINI::instance( 'content.ini' )->variable( 'NodeSettings', 'RootNode' ) );
        if ( $comunitaNode instanceof eZContentObjectTreeNode )
        {
            $dataMap = $comunitaNode->attribute( 'data_map' );
            if ( isset( $dataMap['comuni'] ) && $dataMap['comuni']->hasContent() )
            {
                $relations = $dataMap['comuni']->content();
                foreach( $relations['relation_list'] as $index => $comune )
                {
                    $comune = eZContentObject::fetch( $comune['contentobject_id'] );
                    if ( $comune )
                    {
                        $assigned_nodes = $comune->attribute( 'assigned_nodes' );
                        foreach( $assigned_nodes as $assigned_node )
                        {
                            $pathArray = $assigned_node->attribute( 'path_array' );
                            if ( in_array( eZINI::instance( 'content.ini' )->variable( 'NodeSettings', 'RootNode' ), $pathArray ) )
                            {                                
                                $locations[$comune->attribute('name')][0]['node_ids'] = array( $assigned_node->attribute( 'node_id' ) );
                            }
                        }
                    }                    
                }
            }
        }
        
        return $locations;
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
    
    public function getFileLocationComunita()
    {
        $objectComunita = eZContentObject::fetch( $this->comunita );
        $dataMap = $objectComunita->dataMap();
        if ( isset( $dataMap['media'] ) )
        {
            $objectID = $dataMap['media']->toString();
            $object = eZContentObject::fetch( $objectID  );
            if ( $object )
            {
                $mediaNode = $object->attribute( 'main_node' );
                foreach( $mediaNode->children() as $node )
                {
                    if ( $node->attribute( 'name' ) == 'File' )
                    {
                        return $node->attribute( 'node_id' );
                        break;
                    }
                }
            }
        }
        throw new AlboFatalException( "Comunità {$this->comunita}, non ha un media folder Immagini" );        
    }
    
    public function getImageLocationComunita()
    {
        $objectComunita = eZContentObject::fetch( $this->comunita );
        $dataMap = $objectComunita->dataMap();
        if ( isset( $dataMap['media'] ) )
        {
            $objectID = $dataMap['media']->toString();
            $object = eZContentObject::fetch( $objectID  );
            if ( $object )
            {
                $mediaNode = $object->attribute( 'main_node' );
                foreach( $mediaNode->children() as $node )
                {
                    if ( $node->attribute( 'name' ) == 'Immagini' )
                    {
                        return $node->attribute( 'node_id' );
                        break;
                    }
                }
            }
        }
        throw new AlboFatalException( "Comunità {$this->comunita}, non ha un media folder File" );   
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
    
    public function attributeDisambiguation( $identifier, $value )
    {
        if ( $identifier == 'comune' )
        {
            return $this->ricavaComune( $value );
        }
        return parent::attributeDisambiguation( $identifier, $value );
    }

}
