<?php

class AlbotelematicoHelperBase
{
    const SECTION_IDENTIFIER = 'albotelematicotrentino';

    const STATE_VISIBILE = 'visibile';
    const STATE_ARCHIVIO_RICERCABILE = 'archivioricercabile';
    const STATE_ARCHIVIO_NON_RICERCABILE = 'archiviononricercabile';
    const STATE_NON_VISIBILE = 'nonvisibile';
    const STATE_ANNULLATO = 'annullato';

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
    public $content;
    public $mapAttributes = array();
    public $currentObject;
    
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
        unset( $this->content );
        $this->currentObject = null;
    }

    public static function buildRemoteId( $string )
    {
        return 'at_' . $string;
    }

    public function getRemoteID()
    {
        $id = (string) $this->row->id_atto;
        return self::buildRemoteId( $id );
        //return md5( $id );
    }
    
    public function getCreatorID()
    {
        $admin = eZUser::fetchByName( 'admin' );
        $user = eZUser::fetchByName( 'albotelematico' );
        if ( $user )
            return $user->attribute( 'contentobject_id' );
        else
        {
            $ini = eZINI::instance();
            $userClassIdentifier = eZContentClass::classIdentifierByID( $ini->variable( "UserSettings", "UserClassID" ) );
            $userCreatorID = $ini->variable( "UserSettings", "UserCreatorID" );
            
            $params = array();
            $params['class_identifier'] = $userClassIdentifier;
            $params['creator_id'] = $admin->attribute( 'contentobject_id' );
            $params['parent_node_id'] = $admin->attribute( 'contentobject' )->attribute( 'main_parent_node_id' );
            $params['remote_id'] = md5( 'albotelematico' );

            $attributes = array();
            $attributes['first_name'] = 'Importatore';
            $attributes['last_name'] = 'Albotelematico';
            $attributes['user_account'] = 'albotelematico|importer@opencontent.it||md5_password|0';
            $params['attributes'] = $attributes;
            $contentObject = eZContentFunctions::createAndPublishObject( $params );
            return $contentObject->attribute( 'id' );
        }

    }
    
    public function isImported()
    {        
        return $this->getCurrentObject() instanceof eZContentObject;        
    }
    
    public function getCurrentObject()
    {
        if ( $this->currentObject == null )
        {
            $remoteID = $this->getRemoteID();
            $this->currentObject = eZContentObject::fetchByRemoteID( $remoteID );        
        }                
        return $this->currentObject;
    }
    
    public function canProcessRow( $row )
    {                                        
        $this->setCurrentRow( $row );        
        if ( $this->hasArgument( 'clean' ) )
        {
            if ( $this->isImported() )
            {
                $this->registerDelete( $this->getCurrentObject()->attribute( 'id' ), $this->getCurrentObject()->attribute( 'name' ) );
                eZContentObjectOperations::remove( $this->getCurrentObject()->attribute( 'id' ) );                
            }            
            return false;
        }
        $process = true;
        if ( $this->isImported() && !$this->hasArgument( 'update' ) )
        {
            $process = false;
        }
        if ( isset( $this->arguments['field'] ) && isset( $this->arguments['value'] ) )
        {
            $process = ( (string) $this->row->{$this->arguments['field']} == $this->arguments['value'] );
        }
        if ( $this->ini->hasVariable( 'HelperSettings', 'AlwaysRepublish' )
             && $this->ini->variable( 'HelperSettings', 'AlwaysRepublish' ) == 'enabled' )
        {
            $process = true;
        }        
        return $process;
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
                $firstLoop = false;
                self::append_simplexml( $simplexml_temp, $simplexml_child );
            }
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
            /*else
            {
                throw new AlboFatalException( 'Non riesco a disambiguare la classe per ' . $oggetto );
            }*/
        }
        return $parameters[0];
    }
    
    public function locationDisambiguation( $parameters )
    {
        $oggetto = (string) $this->row->oggetto;
        $tipo = (string) $this->row->tipo_atto;
        $organoEmanante = (string) $this->row->organo_emanante;
        if ( $tipo == 'Delibere' )
        {
            foreach( array( 'consiglio', 'consiliar' ) as $term )
            {
                if ( strpos( strtolower( $organoEmanante ), $term ) !== false )
                {
                    return $parameters[1]; // Delibere di Consiglio
                }
            }
            
            foreach( array( 'giunta' ) as $term )
            {
                if ( strpos( strtolower( $organoEmanante ), $term ) !== false )
                {
                    return $parameters[0]; // Delibere di giunta
                }
            }
            
            if ( strpos( strtolower( $organoEmanante ), 'commissario' ) !== false && isset( $parameters[2] ) )
            {
                return $parameters[2]; // Delibere del Commissario
            }

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
            $organoEmanante = (string) $this->row->organo_emanante;
            foreach( array( 'consiglio', 'consiliar' ) as $term )
            {
                if ( strpos( strtolower( $organoEmanante ), $term ) !== false )
                {
                    return 'Consiglio';
                }
            }
            
            if ( strpos( strtolower( $organoEmanante ), 'commissario' ) !== false )
            {
                return 'Commissario';
            }

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
                case 'data_atto':
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
                        $this->values['anno_file'] = $date->format( 'Y' );
                    }
                    if ( $index == 'data_atto' )
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
                            $this->values['allegati'][] = array( 'path' => 'allegati/' . rawurlencode( $allegato->url ),
                                                                 'name' => (string) $allegato->titolo );
                        }
                    }
                } break;

                case 'organo_emanante':
                {
                    $this->values[$index] = (string) $value;
                }

                default:
                {
                    $value = (string) $value;
                    if ( !empty( $value ) )
                    {
                        $this->values[$index] = (string) $value;
                    }
                }
            }
        }

        $baseUrl = str_replace( "ENTE", $this->row->id_ente, $this->options['FileUrl'] );
        $baseUrl = str_replace( "ANNO", $this->values['anno_file'], $baseUrl );

        if ( isset( $this->values['url'] ) )
        {            
            $this->values['url'] = $baseUrl . rawurlencode( $this->values['url'] );
        }
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

    function getSectionID()
    {
        return 0; //vedi eZContentClass::instantiate
    }

    function fillContent()
    {
        //setlocale(LC_ALL, 'it_IT.utf8');

        $contentOptions = new SQLIContentOptions( array(
            'creator_id'            => $this->getCreatorID(),
            'section_id'            => $this->getSectionID(),
            'class_identifier'      => $this->getClassIdentifier(),
            'remote_id'             => $this->getRemoteID()
        ) );

        $this->content = SQLIContent::create( $contentOptions );
        
        if ( $this->mapAttributes == null )
        {
            $this->attributesMap();
        }
        
        foreach( $this->content->fields as $language => $fieldset )
        {
            foreach( $fieldset as $attributeIdentifier => $attribute )
            {
                if ( isset( $this->mapAttributes[$attributeIdentifier] ) )
                {
                    $attributeContent = $this->washValue( $this->mapAttributes[$attributeIdentifier] );
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
                    $this->content->fields->{$attributeIdentifier} = $attributeContent;
                }            
            }            
        }
        return $this->content;
    }

    protected function fixEncoding( $string )
    {
        $currentEncoding = mb_detect_encoding( $string ) ;
        if( $currentEncoding == "UTF-8" && mb_check_encoding( $string, "UTF-8" ) )
            return $string;
        else
            return utf8_encode( $string );
    }
    
    function setPublishedTimestamp()
    {
        $contentObject = $this->content->getRawContentObject();        
        if ( $contentObject instanceof eZContentObject && $contentObject->attribute( 'published' ) !==  $this->values['data_pubblicazione'] )
        {
            $contentObject->setAttribute( 'published', $this->values['data_pubblicazione'] );
            $contentObject->store();
        }
    }
    
    function washValue( $value )
    {
        if( is_string( $value ) )
        {
            // importando gli atti della comunità rotaliana compaiono queste entities
            $value = str_replace( '&#00246;', 'ö', $value );
            $value = str_replace( '&#00224;','à', $value );
        }
        return $value;
    }
    
    function tempFile( $url )
    {        
        if ( OpenPABase::getDataByURL( $url, true ) )
        {                            
            $name = basename( $url );
            $file = eZFile::create( $name, $this->tempVarDir, OpenPABase::getDataByURL( $url ) );
            $filePath = rtrim( $this->tempVarDir, '/' ) . '/' . $name;
            $this->removeFiles[] = $filePath;        
            return $filePath;
        }
        else
        {
            return null;
            //throw new AlboFatalException( "File {$url} non trovato" );
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
            $fileStored = $this->tempFile( $file['url'] );            
            if ( $fileStored !== null )
            {
                $result = array();
                $upload = new eZContentUpload();
                $uploadFile = $upload->handleLocalFile( $result, $fileStored, $this->fileLocation, $node, $name );
                if ( isset( $result['contentobject'] ) && ( !$object instanceof eZContentObject ) )
                {
                    $object = $result['contentobject'];
                    $object->setAttribute( 'remote_id', $remoteID );
                    $object->store();
                }
                elseif ( isset( $result['errors'] ) && !empty( $result['errors'] ) )
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
                    throw new AlboFatalException( 'Errore caricando ' . var_export( $file, 1 ) . ' ' . $fileStored );
                }
            }
        }
        return implode( '-', $objectIDs );
    }
    
    function rollback()
    {
        //@todo rimuovere file e oggetti...
    }
    
    function cleanup()
    {
        foreach( $this->removeFiles as $filePath )
        {
            $file = eZClusterFileHandler::instance( $filePath );
            if ( $file->exists() )
            {
                $file->delete();
            }
        }
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
    
    function registerImport()
    {
        $object = $this->content->getRawContentObject();
        if ( $object instanceof eZContentObject )
        {
            $log = array();
            $log['time'] = date( 'j/m/Y H:i');
            $siteaccess = eZSiteAccess::current();
            $log['siteaccess'] = $siteaccess['name'];
            $log['object_id'] = $object->attribute( 'id' );
            $log['object_version'] = $object->attribute( 'current_version' );
            $log['main_node_id'] = $object->attribute( 'main_node_id' );
            $log['parent_nodes'] = implode( ', ',  $this->locations );
            $log['id_atto'] = (string) $this->row->id_atto;
            $log['oggetto'] = (string) $this->row->oggetto;
            
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
    }
    
    function registerError( $error )
    {
        $log = array();        
        $log['parameter'] = (string) is_object( $this->row ) ? $this->row->id_atto : '';
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
    
    function registerDelete( $id, $name )
    {
        $log = array();        
        $log['id'] = $id;
        $log['name'] = $name;        
        $log['id_atto'] = (string) $this->row->id_atto;
        $log['oggetto'] = (string) $this->row->oggetto;
        
        $logFileName = 'delete_' . date( 'j-m-Y') . '.csv';
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
    
    function saveINILocations( $data )
    {
        return false;
    }

    /**
     * @param $identifier
     *
     * @return int
     * @throws Exception
     */
    public static function getStateID( $identifier )
    {
        $status = self::getState( $identifier );
        if ( $status instanceof eZContentObjectState )
        {
            return $status->attribute( 'id' );
        }
    }

    /**
     * @param string $identifier
     * @return eZContentObjectState
     * @throws Exception
     */
    public static function getState( $identifier )
    {
        if ( $identifier == 'archviononricercabile' ) // fix albotelematico typo...
        {
            $identifier = 'archiviononricercabile';
        }
        $group = eZContentObjectStateGroup::fetchByIdentifier( 'albotelematico' );
        if ( $group instanceof eZContentObjectStateGroup )
        {
            $status = eZContentObjectState::fetchByIdentifier( $identifier, $group->attribute( 'id' ) );
            if ( $status instanceof eZContentObjectState )
            {
                return $status;
            }
            else
            {
                throw new Exception( "Stato {$identifier} non trovato"  );
            }

        }
        else
        {
            throw new Exception( "Gruppo di stati \"albotelematico\" non trovato"  );
        }
    }

    public static function setState( $objectID, $identifier )
    {
        $object = eZContentObject::fetch( $objectID );
        if ( $object instanceof eZContentObject )
        {
            $state = self::getState( $identifier );
            if ( $state instanceof eZContentObjectState )
            {            
                if ( !in_array( 'albotelematico/' . $identifier, $object->stateIdentifierArray() ) )
                {
                    $object->assignState( $state );                    
                    if ( $identifier == "annullato" )
                    {            
                        /** @var eZContentClass $class */
                        $class = $object->attribute( 'content_class' );
                        $name = $class->contentObjectName( $object );        
                        $object->setName( "[ANNULLATO] " . $name );        
                        $object->store();
                    }
                    eZContentOperationCollection::registerSearchObject( $object->attribute( 'id' ), null );
                    $content = SQLIContent::fromContentObject( $object );
                    $content->addPendingClearCacheIfNeeded();
                }
            }                    
        }
    }

    public static function objectStatesArray()
    {
        return array(
            "visibile" => "Visibile",
            "archivioricercabile" => "Archivio ricercabile",
            "archiviononricercabile" => "Archivio non ricercabile",
            "nonvisibile" => "Non visibile",
            "annullato" => "Annullato"
        );
    }

    public static function createStates()
    {
        return OpenPABase::initStateGroup( 'albotelematico', self::objectStatesArray() );
        /*
        $groups = array(
            array(
                'identifier' => 'albotelematico',
                'name' => 'Albo telematico',
                'states' => self::objectStatesArray()
            )
        );

        foreach( $groups as $group )
        {
            $stateGroup = eZContentObjectStateGroup::fetchByIdentifier( $group['identifier'] );
            if ( !$stateGroup instanceof eZContentObjectStateGroup )
            {
                $stateGroup = new eZContentObjectStateGroup();
                $stateGroup->setAttribute( 'identifier', $group['identifier'] );
                $stateGroup->setAttribute( 'default_language_id', 2 );

                $translations = $stateGroup->allTranslations();
                foreach( $translations as $translation )
                {
                    $translation->setAttribute( 'name', $group['name'] );
                    $translation->setAttribute( 'description', $group['name'] );
                }

                $messages = array();
                $isValid = $stateGroup->isValid( $messages );
                if ( !$isValid )
                {
                    throw new Exception( implode( ',', $messages ) );
                }
                $stateGroup->store();
            }

            foreach( $group['states'] as $StateIdentifier => $StateName )
            {
                $stateObject = $stateGroup->stateByIdentifier( $StateIdentifier );
                if ( !$stateObject instanceof eZContentObjectState )
                {
                    $stateObject = $stateGroup->newState( $StateIdentifier );
                }
                $stateObject->setAttribute( 'default_language_id', 2 );
                $stateTranslations = $stateObject->allTranslations();
                foreach( $stateTranslations as $translation )
                {
                    $translation->setAttribute( 'name', $StateName );
                    $translation->setAttribute( 'description', $StateName );
                }
                $messages = array();
                $isValid = $stateObject->isValid( $messages );
                if ( !$isValid )
                {
                    throw new Exception( implode( ',', $messages ) );
                }
                $stateObject->store();
            }
        }
        */
    }

    private static $_section;
    
    /**
     * @return eZSection
     * @throws Exception
     */
    public static function getSection()
    {
        if ( self::$_section === null )
        {
            self::$_section = eZPersistentObject::fetchObject( eZSection::definition(), null, array( "identifier" => self::SECTION_IDENTIFIER ), true );
            if ( !self::$_section instanceOf eZSection )
            {
                throw new Exception( "Section {self::SECTION_IDENTIFIER} non trovata" );
            }
        }
        return self::$_section;
    }

    public static function createSection()
    {
        $section = eZPersistentObject::fetchObject( eZSection::definition(), null, array( "identifier" => self::SECTION_IDENTIFIER ), true );
        if ( !$section instanceOf eZSection )
        {
            $section = new eZSection( array() );
            $section->setAttribute( 'name', 'Albo Telematico Trentino' );
            $section->setAttribute( 'identifier', self::SECTION_IDENTIFIER );
            $section->setAttribute( 'navigation_part_identifier', 'ezcontentnavigationpart' );
            $section->store();
        }
        return $section;
    }

    /**
     * Controlla se il feed porta a una reirezione e nel caso restituisce l'url corretto
     * @param $feed
     *
     * @return string
     * @throws SQLIXMLException
     */
    protected static function checkFeedRedirect( $feed )
    {
        $ch = curl_init();
        curl_setopt( $ch, CURLOPT_URL, $feed );
        curl_setopt( $ch, CURLOPT_HEADER, false );
        curl_setopt( $ch, CURLOPT_NOBODY, 1 );
        curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
        curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, (int) 1 );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );

        // Now check proxy settings
        $ini = eZINI::instance();
        $proxy = $ini->variable( 'ProxySettings', 'ProxyServer' );

        $isHTTP = stripos( $feed, 'http' ) !== false;
        if( $proxy && $isHTTP ) // cURL proxy support is only for HTTP
        {
            curl_setopt( $ch, CURLOPT_PROXY , $proxy );
            $userName = $ini->variable( 'ProxySettings', 'User' );
            $password = $ini->variable( 'ProxySettings', 'Password' );
            if ( $userName )
            {
                curl_setopt( $ch, CURLOPT_PROXYUSERPWD, "$userName:$password" );
            }
        }

        $xmlString = curl_exec( $ch );
        if( $xmlString === false )
        {
            $errMsg = curl_error( $ch );
            $errNum = curl_errno( $ch );
            curl_close( $ch );
            throw new SQLIXMLException( __METHOD__ . ' => Error with stream '.$path.' ('.$errMsg.')', $errNum );
        }

        curl_exec($ch);
        $redirectURL = curl_getinfo( $ch,CURLINFO_EFFECTIVE_URL );
        curl_close($ch);

        if ( !empty( $redirectURL ) && $feed != $redirectURL )
        {
            $feed = $redirectURL;
        }
        return $feed;
    }
}
