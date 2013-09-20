<?php

class OpenPaAlbotelematicoHelper extends AlbotelematicoHelperBase implements AlbotelematicoHelperInterface
{
    private static $xmlComuni;
    public $feed;
    
    public function loadData()
    {
        //$comuni = (array) $this->getRequestComuni();
        $comuni = (array) explode( ';', $this->arguments['comune'] );
        $this->dataCount = 0;
        $this->data = false;
        foreach( $comuni as $comune )
        {
            $feedComune = str_replace( " ", "-", strtolower( $comune ) );            
            $feedPath = str_replace( "---", $feedComune, $this->options['FeedBase'] );
            $this->feed[] = $feedPath . ': ' . var_export( eZHTTPTool::getDataByUrl( $feedPath, true ), 1 );
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
    }
    
    public function getRemoteID()
    {
        $id = (string) $row->id_atto;
        return md5( $id );
    }

    public function availableArguments()
    {
        return array(
            'comune' => true,
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
        $defaultLocations = $this->getDefaultLocations();
        $key = (string) $this->row->tipo_atto;
        $this->locations = array();
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
        $requests = explode( ';', $this->arguments['comune'] );
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
                foreach( $requests as $request )
                {
                    if ( strtolower( $request ) == strtolower( $comune->name ) )
                    {
                        $response[] = (string) $comune->name;
                    }
                }
            }
        }
        if ( count( $response ) !== count( $requests ) )
        {
            throw new AlboFatalException( "Errore nella ricerca dei comuni " . implode( '; ', $requests ) );
        }
        return $response;
    }

}
