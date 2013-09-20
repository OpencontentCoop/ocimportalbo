<?php

class AlbotelematicoHelperBase
{
    public $ini;
    public $tempVarDir;
    public $tempLogDir;
    public $arguments;
    public $options;
    public $dataCount;
    public $data;
    public $row;
    public $fileLocation = 'auto';
    public $removeFiles = array();
    public $removeObjects = array();

    // valori da resettare al setCurrentRow
    public $classIdentifier;
    public $locations;
    public $values;
    public $mapAttributes = array();
    
    public function __construct()
    {
        $this->ini = eZINI::instance( 'alboimporthandler.ini' );
        $this->tempVarDir = eZINI::instance()->variable( 'FileSettings','VarDir' ) . '/import/';
        eZDir::mkdir( $this->tempVarDir );
        $this->tempLogDir = eZINI::instance()->variable( 'FileSettings','VarDir' ) . '/import_log/';
        eZDir::mkdir( $this->tempLogDir );
    }
    
    public function loadArguments( $arguments, $options )
    {
        $this->arguments = $arguments;
        $this->options = $options;
        $this->validateArguments();
    }

    public function hasArgument( $name )
    {
        return isset( $this->arguments[$name] );
    }
    
    public function getArgument( $name )
    {
        if ( !isset( $this->arguments[$name] ) )
        {
            throw new InvalidArgumentException( "Argomento $name non trovato" );
        }
        return $this->arguments[$name];
    }

    public function getData()
    {
        return $this->data;
    }

    public function getDataCount()
    {
        return $this->dataCount;
    }

    public function validateArguments()
    {
        foreach( $this->availableArguments() as $argument => $isRequired )
        {
            if ( !isset( $this->arguments[$argument] ) && $isRequired )
            {
                throw new AlboFatalException( "Opzione $argument non trovata" );
            }
        }
    }

    public function setCurrentRow( $row )
    {
        $this->row = $row;
        $this->classIdentifier = null;
        $this->locations = null;
        $this->values = null;
        $this->mapAttributes = array();
    }

    public static function append_simplexml( SimpleXMLElement &$simplexml_to, SimpleXMLElement &$simplexml_from )
    {

        static $firstLoop = true;

        //Here adding attributes to parent
        if ( $firstLoop )
        {
            foreach( $simplexml_from->attributes() as $attr_key => $attr_value )
            {
                $simplexml_to->addAttribute($attr_key, $attr_value);
            }
        }

        foreach ($simplexml_from->children() as $simplexml_child)
        {
            if ( $simplexml_child instanceof SimpleXMLElement )
            {
                $simplexml_temp = $simplexml_to->addChild( $simplexml_child->getName(), (string) $simplexml_child );
                foreach ( $simplexml_child->attributes() as $attr_key => $attr_value )
                {
                    $simplexml_temp->addAttribute($attr_key, $attr_value);
                }
            }

            $firstLoop = false;

            self::append_simplexml( $simplexml_temp, $simplexml_child );
        }

        unset( $firstLoop );
    }

    public function classDisambiguation( $parameters )
    {
        $oggetto = (string) $this->row->oggetto;
        $tipo = (string) $this->row->tipo_atto;
        if ( $tipo == 'Decreti e ordinanze' )
        {
            if ( strpos( strtolower( $oggetto ), 'decreto' ) !== false )
            {
                return 'decreto_sindacale';
            }
            elseif ( strpos( strtolower( $oggetto ), 'ordinanza' ) !== false )
            {
                return 'ordinanza';
            }
            else
            {
                throw new AlboFatalException( 'Non riesco a disambiguare la classe per ' . $oggetto );
            }
        }
    }
    
    public function locationDisambiguation( $parameters )
    {
        $oggetto = (string) $this->row->oggetto;
        $tipo = (string) $this->row->tipo_atto;
        if ( $tipo == 'Delibere' )
        {
            foreach( array( 'consiglio', 'consiliar' ) as $term )
            {
                if ( strpos( strtolower( $oggetto ), $term ) !== false )
                {
                    return $parameters[1]; // Delibere di Consiglio
                }
            }

            return $parameters[0]; // Delibere di Giunta (default)
        }

        if ( $tipo == 'Decreti e ordinanze' )
        {
            foreach( array( 'decreto' ) as $term )
            {
                if ( strpos( strtolower( $oggetto ), $term ) !== false )
                {
                    return $parameters[0]; // Decreti sindacali
                }
            }

            return $parameters[1]; // Ordinanze (default)
        }
    }
    
    public function attributeDisambiguation( $identifier, $value )
    {
        $oggetto = (string) $this->row->oggetto;        
        
        if ( $this->classIdentifier == 'deliberazione' && $identifier == 'competenza' )
        {
            foreach( array( 'consiglio', 'consiliar' ) as $term )
            {
                if ( strpos( strtolower( $oggetto ), $term ) !== false )
                {
                    return 'Consiglio';
                }
            }

            return 'Giunta'; // Delibere di Giunta (default)
        }
        
        if ( $identifier == 'anno' )
        {
            $date = DateTime::createFromFormat( "U", (string) $value );
            if ( !$date instanceof DateTime )
            {
                throw new AlboFatalException( "Errore nel calcolare l'anno" );            
            }
            return $date->format( 'Y' );
        }
        
        if ( $identifier == 'data' )
        {
            $date = DateTime::createFromFormat( "U", (string) $value );
            if ( !$date instanceof DateTime )
            {
                preg_match('/(\d?\d\/\d\d\/\d\d\d\d)/', $value, $matches);
                foreach( $matches as $match )
                {
                    $findDate = DateTime::createFromFormat( "d/m/Y", (string) $match );
                    if ( $findDate instanceof DateTime )
                    {
                        $date = $findDate;
                        break;
                    }
                }
                if ( !$date instanceof DateTime )
                {
                    $date = DateTime::createFromFormat( "d/m/Y", (string) $this->row->data_pubblicazione );
                }
            }
            return $date->format( 'U' );
        }

        return $value;        
    }
    
    public function valueDisambiguation( $typeValue, $parameters = array() )
    {
        $oggetto = (string) $this->row->oggetto;
        $tipo = (string) $this->row->tipo_atto;

        switch( $typeValue )
        {
            case 'class':
            {
                return $this->classDisambiguation( $parameters );
                
            } break;

            case 'location':
            {
                return $this->locationDisambiguation( $parameters );
            }
            
            case 'attribute':
            {
                return $this->attributeDisambiguation( $parameters['identifier'], $parameters['value'] );
            }
            
        }
        return false;
    }

    public function prepareValues()
    {
        $this->values = array();
        foreach( (array) $this->row as $index => $value )
        {
            switch( $index )
            {
                case 'data_pubblicazione':
                case 'data_termine':
                {
                    $date = DateTime::createFromFormat( "d/m/Y", (string) $value );
                    if ( !$date instanceof DateTime )
                    {
                        throw new AlboFatalException( "$value non è una data" );
                    }
                    if ( $index == 'data_pubblicazione' )
                    {
                        $this->values['anno'] = $date->format( 'Y' );
                    }
                    $this->values[$index] = $date->format( 'U' );
                } break;

                case 'allegati':
                {
                    $this->values['allegati'] = array();
                    if ( $value instanceof SimpleXMLElement )
                    {
                        foreach ( $value->children() as $allegato )
                        {
                            $this->values['allegati'][] = array( 'path' => 'allegati/' . $allegato->url,
                                                                 'name' => (string) $allegato->titolo );
                        }
                    }
                } break;

                default:
                {
                    $this->values[$index] = (string) $value;
                }
            }
        }

        $baseUrl = str_replace( "ENTE", $this->row->id_ente, $this->options['FileUrl'] );
        $baseUrl = str_replace( "ANNO", $this->values['anno'], $baseUrl );

        $this->values['url'] = $baseUrl . $this->values['url'];
        foreach( $this->values['allegati'] as $i => $item )
        {
            $this->values['allegati'][$i]['url'] = $baseUrl . $item['path'];
        }

        return $this->values;
    }

    public function attributesMap()
    {
        if ( !$this->ini->hasVariable( $this->classIdentifier, 'MapAttribute' ) )
        {
            throw new RuntimeException( 'Non trovo la mappa degli attributi per ' . $this->classIdentifier );
        }
        if ( $this->values == null )
        {
            $this->prepareValues();
        }
        $map = $this->ini->variable( $this->classIdentifier, 'MapAttribute' );
        foreach( $map as $ez => $albo )
        {            
            $albo = explode( ';', $albo ); //retrocompatibilità con ini
            if ( isset( $this->values[$albo[0]] ) )
            {
                $this->mapAttributes[$ez] = $this->valueDisambiguation( 'attribute', array( 'identifier' => $ez,
                                                                                            'value' => $this->values[$albo[0]] ) );               
            }
            else
            {
                $this->mapAttributes[$ez] =false;
            }
        }
        return $this->mapAttributes;
    }

    function fillContent( SQLIContent $content )
    {
        if ( $this->mapAttributes == null )
        {
            $this->attributesMap();
        }        
        foreach( $content->fields as $language => $fieldset )
        {
            foreach( $fieldset as $attributeIdentifier => $attribute )
            {
                if ( isset( $this->mapAttributes[$attributeIdentifier] ) )
                {
                    $attributeContent = $this->mapAttributes[$attributeIdentifier];
                    switch( $attribute->attribute( 'data_type_string' ) )
                    {
                        case 'ezbinaryfile':
                        {
                            $attributeContent =  $this->tempFile( $attributeContent );                        
                        } break;
                        case 'ezobjectrelationlist':                    
                        {                        
                            foreach( $attributeContent as $item )
                            {
                                if ( isset( $item['url'] ) && isset( $item['name'] ) )
                                {
                                    $attributeContent = $this->uploadFiles( $attributeContent );
                                    break;
                                }
                            }
                        } break;
                        case 'ezdate':
                        case 'ezdatetime':
                        {
                            if ( !empty( $attributeContent ) )
                            {
                                $date = DateTime::createFromFormat( "U", $attributeContent );
                                if ( !$date instanceof DateTime )
                                {
                                    throw new AlboFatalException( "{$attributeContent} non è una data" );
                                }
                            }
                        }                    
                    }
                    $content->fields->{$attributeIdentifier} = $attributeContent;
                }            
            }            
        }        
    }
    
    function tempFile( $url )
    {
        if ( eZHTTPTool::getDataByURL( $url, true ) )
        {                            
            $name = basename( $url );
            $file = eZFile::create( $name, $this->tempVarDir, eZHTTPTool::getDataByURL( $url ) );
            $filePath = $this->tempVarDir . '/' . $name;
            $this->removeFiles[] = $filePath;
            return $filePath;
        }
        else
        {
            throw new AlboFatalException( "File {$url} non trovato" );
        }        
    }

    function uploadFiles( $files )
    {
        $objectIDs = array();
        foreach( $files as $file )
        {
            $remoteID = md5( $file['path'] );
            $node = false;
            $object = eZContentObject::fetchByRemoteID( $remoteID );
            if ( $object instanceof eZContentObject )
            {
                $node = $object->attribute( 'main_node' );
            }
            $name = $file['name'];
            $file = $this->tempFile( $file['url'] );
            $result = array();
            $upload = new eZContentUpload();
            $upload->handleLocalFile( $result, $file, $this->fileLocation, $node, $name );
            if ( isset( $result['contentobject'] ) && ( !$object instanceof eZContentObject ) )
            {
                $object = $result['contentobject'];
                $object->setAttribute( 'remote_id', $remoteID );
            }
            elseif ( isset( $result['errors'] ) )
            {                
                throw new AlboFatalException( implode( ', ', $result['errors'] ) );
            }
            
            if ( $object instanceof eZContentObject )
            {
                $objectIDs[] = $object->attribute( 'id' );
                $this->removeObjects[] = $object;                
            }
            else
            {
                throw new AlboFatalException( 'Errore caricando ' . $file['url'] );
            }            
        }
        return implode( '-', $objectIDs );
    }
    
    function rollback()
    {
        //@todo rimuovere file e oggetti...
    }
    
    function test()
    {
        $value = $this->getArgument( 'test' );                
        if ( isset( $this->{$value} ) )
        {
            eZCLI::instance()->output();
            eZCLI::instance()->warning( 'Test ' . $value );
            eZCLI::instance()->output();
            eZCLI::instance()->output( var_export( $this->{$value}, 1 ) );            
            eZCLI::instance()->output();            
            eZCLI::instance()->warning( 'Fine test ' . $value );            
            $importFactory = SQLIImportFactory::instance();            
            $importFactory->cleanup();
            eZExecution::cleanExit();
        }        
    }
    
    function registerImport( eZContentObject $object )
    {
        $log = array();
        $log['time'] = date( 'j/m/Y H:i');
        $siteaccess = eZSiteAccess::current();
        $log['siteaccess'] = $siteaccess['name'];
        $log['object_id'] = $object->attribute( 'id' );
        $log['object_version'] = $object->attribute( 'current_version' );
        $log['main_node_id'] = $object->attribute( 'main_node_id' );
        $log['parent_nodes'] = implode( ', ',  $this->locations );
        $log['parameters'] = (string) $this->row->id_atto;
        
        $logFileName = 'import_' . date( 'j-m-Y') . '.csv';
        $logFile = $this->tempLogDir . $logFileName;
        if ( !file_exists( $logFile ) )
        {
            eZFile::create( $logFileName, $this->tempLogDir );
            $fp = fopen( $this->tempLogDir . $logFileName, 'w' );
            fputcsv( $fp, array_keys( $log ) );
            fclose( $fp );  
        }
        
        $fp = fopen( $this->tempLogDir . $logFileName, 'a+' );
        fputcsv( $fp, array_values( $log ) );
        fclose( $fp ); 
    }
    
    function registerError( $error )
    {
        $log = array();        
        $log['parameter'] = (string) $this->row->id_atto;
        $log['error'] = $error;
        
        $logFileName = 'error_' . date( 'j-m-Y') . '.csv';
        $logFile = $this->tempLogDir . $logFileName;
        if ( !file_exists( $logFile ) )
        {
            eZFile::create( $logFileName, $this->tempLogDir );
            $fp = fopen( $this->tempLogDir . $logFileName, 'w' );
            fputcsv( $fp, array_keys( $log ) );
            fclose( $fp );  
        }
        
        $fp = fopen( $this->tempLogDir . $logFileName, 'a+' );
        fputcsv( $fp, array_values( $log ) );
        fclose( $fp ); 
    }
}
