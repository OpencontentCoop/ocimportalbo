<?php

class ComunWebAlbotelematicoHelper extends AlbotelematicoHelperBase implements AlbotelematicoHelperInterface
{
    private static $xmlComuni;
    public $feed;
    public $comunitaID;
    public $comuni = array();
    public $tools;    
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
    
    public function loadData()
    {        
        if ( $this->hasArgument( 'comunita' ) )
        {
            $comunita = $this->ricavaComunita( $this->getArgument( 'comunita' ) );
            if ( !empty( $comunita ) )
            {
                $comunita = explode( '-', $comunita );
                $this->comunitaID = $comunita[0];                
                $dataMap = eZContentObject::fetch( $comunita[0] )->dataMap();
                $attributeComuni = $dataMap['comuni']->content();
                foreach( $attributeComuni['relation_list'] as $attribute )
                {
                    $object = eZContentObject::fetch( $attribute["contentobject_id"] );
                    $dataMap = $object->attribute( 'data_map' );
                    $this->comuni[] = $dataMap['name']->content();
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
            else
            {
                throw new AlboFatalException( 'Url non risolto: ' . $feedPath );
            }
        }
        
    }

    public function availableArguments()
    {
        return array(
            'ente' => true,
            'comunita' => true,
            'field' => false,
            'value' => false,
            'test' => false
        );
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
        if ( $this->comunitaID == null && $this->row !== null && $this->getTools()->ricavaComunitaDaComune( $this->row->desc_ente, true ) )
        {
            $this->comunitaID = $this->getTools()->ricavaComunitaDaComune( $this->row->desc_ente );
            if ( !eZContentObject::fetch( $this->comunitaID ) )
            {
                throw new AlboFatalException( 'Comunita di ' . $this->row->desc_ente  . ' non trovata' );
            }
        }
        $this->storageLocation = $this->getTools()->ricavaStorageComunita( $this->comunitaID );
        $storageLocationNode = eZContentObjectTreeNode::fetch( $this->storageLocation );
        if ( !$storageLocationNode instanceOf eZContentObjectTreeNode )
        {
            throw new AlboFatalException( 'Storage non trovato' );
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
        $key = (string) $this->row->tipo_atto;
        $defaultLocations = $this->getDefaultLocations();
        
        $baseLocation = $this->options['DefaultParentNodeID'];
        $storageLocation = $this->getStorageLocation();
        
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
        
        $perContoDi = false;
        //@todo pubblicaNto????
        if( !empty( $this->row->pubblicanto_per_conto_di ) )
        {
            $perContoDi = '(per conto di ' . $this->row->pubblicanto_per_conto_di . ')';
            $this->locations = array();
            if( isset( $defaultLocations['Per conto di'][0]['node_ids'] ) )
            {
                $this->locations[] = $defaultLocations['Per conto di'][0]['node_ids'][0];
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
            throw new AlboFatalException( 'Non trovo la collocazione per ' . $key . ' ' . $perContoDi );
        }
        return $this->locations;        
    }

    /*
     * In base all'ini restituisce:
     *  array( 'Nome classe in albo' => array(
     *          'identifier di ez' => array(        //se maggiore di 1 da disambiguare vedi AlbotelematicoHelperBase::valueDisambiguation
     *              'node_ids' => array( 1, 2 ),    //se maggiore di 1 da disambiguare vedi AlbotelematicoHelperBase::valueDisambiguation
     *              'type' => default|entilocali    // in base a dove recupera il valore definitivo
     *          )
     *  )
     */
    public function getDefaultLocations()
    {
        $classMaps = (array) $this->ini->variable( 'MapClassSettings', 'MapClass' );
        $iniLocations = eZINI::instance( 'entilocali.ini' )->hasVariable( 'LocationsPerClasses', 'Storage_' . $this->getStorageLocation() ) ?
            eZINI::instance( 'entilocali.ini' )->variable( 'LocationsPerClasses', 'Storage_' . $this->getStorageLocation() ) :
            array();
        
        $locationPerClasses = array();
        foreach( $iniLocations as $classAndNode )
        {
            $classAndNode = explode( ';', $classAndNode );            
            $locationPerClasses[$classAndNode[1]] = explode( ',', $classAndNode[0] );
        }
        
        $locations = array();        
        foreach( $classMaps as $alboClass => $classIdentifier )
        {
            $classIdentifiers = explode( ';', $classIdentifier );
            
            foreach( $classIdentifiers as $class )
            {
                $parentLocations = array();            
                foreach( $locationPerClasses as $l => $c )
                {
                    if ( in_array( $class, $c ) )
                    {
                        $parentLocations = array( $l );
                    }
                }
                
                $nodes = array();
                $type = false;

                foreach( $parentLocations as $node )
                {
                    $nodes[] = eZContentObjectTreeNode::fetch( $node );
                    $type = 'entilocali.ini';
                }
                                
                if ( count( $nodes ) == 0 )
                {
                    $nodes[] = $this->getStorageLocation( true );
                    $type = 'default';
                }

                foreach( $nodes as $index => $node )
                {
                    if ( !$node instanceof eZContentObjectTreeNode )
                    {
                        $nodes[$index] = 0;
                    }
                    else
                    {
                        $nodes[$index] = $node->attribute( 'node_id' );
                    }
                }

                $locations[$alboClass][$class]['node_ids'] =  $nodes;
                $locations[$alboClass][$class]['type'] = $type;
            }
        }
        $locations['Per conto di'][0]['node_ids'] = isset( $parentLocation['PerContoDi'] ) ? array( $parentLocation['PerContoDi'] ) : array( 0 );
        $locations['Per conto di'][0]['type'] = isset( $parentLocation['PerContoDi'] ) ? 'node' : '';        
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
        $objectComunita = eZContentObject::fetch( $this->comunitaID );
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
        throw new AlboFatalException( "Comunità {$this->comunitaID}, non ha un media folder Immagini" );        
    }
    
    public function getImageLocationComunita()
    {
        $objectComunita = eZContentObject::fetch( $this->comunitaID );
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
        throw new AlboFatalException( "Comunità {$this->comunitaID}, non ha un media folder File" );   
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
