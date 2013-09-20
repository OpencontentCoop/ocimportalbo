<?php

class ComunWebAlbotelematicoHelper extends AlbotelematicoHelperBase implements AlbotelematicoHelperInterface
{
    private static $xmlComuni;
    public $feed;
    public $comunitaID;
    public $comuni = array();
    public $tools;
    public $storageLocation;
    public $defaultLocation;
    
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

    public function getLocations()
    {        
        $this->locations = array();
        
        if ( $this->comunitaID == null && $this->getTools()->ricavaComunitaDaComune( $this->row->desc_ente, true ) )
        {
            $this->comunitaID = $this->getTools()->ricavaComunitaDaComune( $this->row->desc_ente );
            if ( !eZContentObject::fetch( $this->comunitaID ) )
            {
                throw new AlboFatalException( 'Comunita di ' . $this->row->desc_ente  . ' non trovata' );
            }
        }
        $this->fileLocation = $this->getFileLocationComunita();
        $this->defaultLocation = $this->options['DefaultParentNodeID'];
        $this->storageLocation = $this->tools->ricavaStorageComunita( $this->comunitaID );
        
        
        return $this->locations;
    }

    /*
     * In base all'ini restituisce:
     *  array( 'Nome classe in albo' => array(
     *          'identifier di ez' => array(        //se maggiore di 1 da disambiguare vedi AlbotelematicoHelperBase::valueDisambiguation
     *              'node_ids' => array( 1, 2 ),    //se maggiore di 1 da disambiguare vedi AlbotelematicoHelperBase::valueDisambiguation
     *              'type' => path|node|sitedata    // in base a dove recupera il valore definitivo
     *          )
     *  )
     */
    public function getDefaultLocations()
    {
        $classMaps = (array) $this->ini->variable( 'MapClassSettings', 'MapClass' );
        $locations = array();
        foreach( $classMaps as $alboClass => $classIdentifier )
        {
            $classIdentifiersParts = explode( ';', $classIdentifier );
            $classIdentifiers = explode( '|', $classIdentifiersParts[0] );
            $parentNodes = array();
            $parentPaths = array();

            if ( $this->ini->hasVariable( 'ParentNodeSettings' , 'ParentNode' ) )
            {
                $parentLocation = $this->ini->variable( 'ParentNodeSettings' , 'ParentNode' );
                if ( isset( $parentLocation[$alboClass] ) )
                {
                    $parentNodesParts = explode( ';', $parentLocation[$alboClass] );
                    $parentNodes = explode( '|', $parentNodesParts[0] );
                }
            }

            if ( $this->ini->hasVariable( 'ParentPathSettings' , 'ParentPath' ) )
            {
                $parentPath = $this->ini->variable( 'ParentPathSettings' , 'ParentPath' );
                if ( isset( $parentPath[$alboClass] ) )
                {
                    $parentPathsParts = explode( ';', $parentPath[$alboClass] );
                    $parentPaths = explode( '|', $parentPathsParts[0] );
                }
            }

            foreach( $classIdentifiers as $class )
            {
                $nodes = array();
                $type = false;

                foreach( $parentNodes as $node )
                {
                    $nodes[] = eZContentObjectTreeNode::fetch( $node );
                    $type = 'node';
                }

                if ( count( $nodes ) == 0 )
                {
                    foreach( $parentPaths as $path )
                    {
                        $nodes[] = eZContentObjectTreeNode::fetchByURLPath( $path );
                        $type = 'path';
                    }
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

    /*
     * Parsa il file SourceComuni e restituisce i comuni richiesti
     */
    private function getRequestComuni()
    {        
        $response = array();
        if ( $this->options['SourceComuni'] )
        {
            if ( is_null( self::$xmlComuni ) )
            {
                $xmlComuniPath = eZSys::rootDir() . eZSys::fileSeparator() . $this->options['SourceComuni'];                
                $comuniXmlOptions = new SQLIXMLOptions( array( 'xml_path' => $xmlComuniPath,
                                                               'xml_parser' => 'simplexml' ) );
                $comuniXml = new SQLIXMLParser( $comuniXmlOptions );
                self::$xmlComuni = $comuniXml->parse();
            }

            for( $i = 0; $i < count( self::$xmlComuni->comune ); $i++ )
            {
                $comune = self::$xmlComuni->comune[$i];                
                $response[] = (string) $comune->name;
            }
        }        
        return $response;
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
