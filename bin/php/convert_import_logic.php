<?php
require 'autoload.php';

$script = eZScript::instance( array( 'description' => ( "OpenPA Fix data convocazione\n\n" ),
                                     'use-session' => false,
                                     'use-modules' => true,
                                     'use-extensions' => true ) );

$script->startup();
$options = $script->getOptions(
    '[original_node:]',
    '',
    array( 'original_node'  => 'Nodo "Albo Pretorio" da migrare nella nuova classe')
);
$script->initialize();
$script->setUseDebugAccumulators( true );

OpenPALog::setOutputLevel( OpenPALog::ALL );

$user = eZUser::fetchByName( 'admin' );
eZUser::setCurrentlyLoggedInUser( $user , $user->attribute( 'contentobject_id' ) );

$db = eZDB::instance();

try
{
    /** @var SQLIScheduledImport[] $scheduledImports */
    $scheduledImports = array_merge(
        SQLIScheduledImport::fetchList( 0, null, array( 'handler' => 'albo' ) ),
        SQLIScheduledImport::fetchList( 0, null, array( 'handler' => 'alboimporthandler' ) )
    );
    if ( count( $scheduledImports ) == 0 )
    {
        throw new Exception( "Non è attivato alcun importatore dell'albo telematico trentino" );
    }
    else
    {
        $scheduledImport = $scheduledImports[0];
        $scheduledOptions = $scheduledImport->attribute( 'options' );
        $comune = $scheduledOptions['comune'];
    }

    OpenPALog::output( "Controllo presenza sezione" );
    try
    {
        AlbotelematicoHelperBase::getSection();
    }
    catch( Exception $e )
    {
        AlbotelematicoHelperBase::createSection();
    }

    ########################################################################################
    ## crea stati se non esistono
    ########################################################################################
    OpenPALog::output( "Controllo presenza stati" );
    try
    {
        AlbotelematicoHelperBase::getStateID( 'visibile' );
    }
    catch( Exception $e )
    {
        AlbotelematicoHelperBase::createStates();
    }

    ########################################################################################
    ##  crea policy sugli stati
    ########################################################################################
    $anonymousRole = eZRole::fetchByName( 'Anonymous' );
    if ( $anonymousRole instanceof eZRole )
    {
        $do = true;
        foreach( $anonymousRole->attribute( 'policies' ) as $policy )
        {
            if ( $policy->attribute( 'module_name' ) == 'content' &&
                $policy->attribute( 'function_name' ) == 'read' )
            {
                foreach( $policy->attribute('limitations') as $limitation )
                {
                    if ( $limitation->attribute( 'identifier' ) == 'StateGroup_albotelematico' )
                    {
                        $do = false;
                        break;
                    }
                }
            }
        }
        if ( $do )
        {
            $anonymousRole->appendPolicy(
                'content',
                'read',
                array(
                    'StateGroup_albotelematico' => array(
                        AlbotelematicoHelperBase::getStateID( 'visibile' ),
                        AlbotelematicoHelperBase::getStateID( 'archivioricercabile' ),
                        AlbotelematicoHelperBase::getStateID( 'archiviononricercabile' )
                    ),
                    'Section' => array( AlbotelematicoHelperBase::getSection()->attribute( 'id' ) )
                )
            );
            $anonymousRole->store();
        }
    }
    else
    {
        throw new Exception( 'Non trovo il ruolo "Anonymous"' );
    }

    $destinationClassId = ObjectAlbotelematicoHelper::CONTAINER_CLASS_IDENTIFIER;
    $destinationClass = eZContentClass::fetchByIdentifier( $destinationClassId );
    if ( !$destinationClass instanceof eZContentClass )
    {
        //throw new Exception( "Classe $destinationClassId non trovata" );
        $tool = new OpenPAClassTools( $destinationClassId, true );
        $tool->compare();
        $tool->sync();
        $destinationClass = $tool->getLocale();
        $destinationClass = eZContentClass::fetchByIdentifier( $destinationClassId );
    }

    if ( !$destinationClass instanceof eZContentClass )
    {
        throw new Exception( "Classe $destinationClassId non trovata" );
    }

    if ( $destinationClass->objectCount() == 0 )
    {
        ########################################################################################
        ## cerco la frontpage di nome Albo Pretorio
        ########################################################################################
        OpenPALog::output( "Conversione frontpage Albo pretorio" );

        /** @var eZContentObjectTreeNode $original */
        $original = false;
        if ( isset( $options['original_node'] ) )
        {
            $original = eZContentObjectTreeNode::fetch( $options['original_node'] );
        }
        else
        {
            $frontPageClassId = eZContentClass::classIDByIdentifier( 'frontpage' );
            $search = eZSearch::search( "Albo pretorio", array( 'SearchContentClassID' => $frontPageClassId ) );
            if ( $search['SearchCount'] > 0 )
            {
                $original = $search['SearchResult'][0];
            }
        }

        if ( !$original instanceof eZContentObjectTreeNode )
        {
            throw new Exception( "Non trovo l'albo pretorio: specifica l'opzione --original_node=<node_id>" );
        }

        ########################################################################################
        ## migrazione container
        ########################################################################################
        $mapping = array();
        foreach( $original->attribute( 'data_map' ) As $key => $value )
        {
            $mapping[$key] = $key;
        }

        foreach( $destinationClass->dataMap() as $identifier => $value )
        {
            if ( !isset( $mapping[$identifier] ) )
            {
                $mapping[$identifier] = '';
            }
        }

        if ( !class_exists( 'conversionFunctions' ) )
        {
            throw new Exception( "Libreria 'conversionFunctions' non trovata" );
        }

        /** @var eZContentObjectTreeNode $container */
        $conversionFunctions = new conversionFunctions();
        $containerId = $original->attribute('contentobject_id');
        $container = $conversionFunctions->convertObject( $containerId, $destinationClassId, $mapping );
        if ( !$container )
        {
            throw new Exception( "Errore nella conversione dell'oggetto contentitore" );
        }
        eZContentObject::clearCache();

        ########################################################################################
        ## popolamento container
        ########################################################################################
        /** @var eZContentObject $containerObject */
        $containerObject = eZContentObject::fetch( $containerId );
        $containerObjectDataMap = $containerObject->attribute( 'data_map' );

        if ( strpos( OpenPABase::getFrontendSiteaccessName(), '_frontend' ) === false )
        {
            throw new Exception( "Sei in entilocali? Occhio al vecchio handler... " ); //@todo
        }
        $oldHandler = new OpenPaAlbotelematicoHelper();
        $defaultLocations = $oldHandler->getDefaultLocations();

        $db->begin();
        foreach( $defaultLocations as $nomeAlbo => $values )
        {
            $trans = eZCharTransform::instance();
            $identifier = $trans->transformByGroup( $nomeAlbo, 'identifier' );
            /** @var eZContentObjectAttribute $attribute */
            $attribute = $containerObjectDataMap[$identifier];
            if ( isset( $attribute )
                 && $attribute instanceof eZContentObjectAttribute )
            {
                $objectIds = $nodeIds = array();
                foreach( $values as $ezId => $parameters )
                {
                    $nodeIds = $parameters['node_ids'];
                }
                if ( !empty( $nodeIds ) )
                {
                    foreach( $nodeIds as $nodeId )
                    {
                        $node = eZContentObjectTreeNode::fetch( $nodeId );
                        if ( $node instanceof eZContentObjectTreeNode )
                        {
                            $objectIds[] = $node->attribute( 'contentobject_id' );
                        }
                    }
                }
                if ( !empty( $objectIds ) )
                {
                    $attribute->fromString( implode( '-', $objectIds ) );
                    $attribute->store();
                }
            }
        }
        if ( isset( $comune ) )
        {
            $identifier = ObjectAlbotelematicoHelper::$identifierMap['current_feed'];
            $attribute = $containerObjectDataMap[$identifier];
            if ( isset( $attribute )
                 && $attribute instanceof eZContentObjectAttribute )
            {
                $attribute->fromString( "http://www.albotelematico.tn.it/bacheca/{$comune}" );
                $attribute->store();
            }
            $identifier = ObjectAlbotelematicoHelper::$identifierMap['archive_feed'];
            $attribute = $containerObjectDataMap[$identifier];
            if ( isset( $attribute )
                 && $attribute instanceof eZContentObjectAttribute )
            {
                $attribute->fromString( "http://www.albotelematico.tn.it/archivio/{$comune}" );
                $attribute->store();
            }
        }
        $db->commit();
    }
    elseif ( $destinationClass->objectCount() == 1 )
    {
        $list = $destinationClass->objectList();
        $containerObject = $list[0];
    }
    elseif ( $destinationClass->objectCount() > 1 )
    {
        throw new Exception( "Trovati più di un oggetto di classe " . ObjectAlbotelematicoHelper::CONTAINER_CLASS_IDENTIFIER . ": non è possibile effettuare l'aggiornamento automatico" );
    }

    ########################################################################################
    ## eliminazione importatori attuali
    ########################################################################################
    OpenPALog::output( "Eliminazione importatori attuali obsoleti" );
    foreach( $scheduledImports as $scheduledImport )
    {
        $scheduledImport->remove();
    }

    ########################################################################################
    ## attivazione workflow
    ########################################################################################
    OpenPALog::warning( "Occorre attivare A MANO il workflow di post pubblicazione <Schedula l'importatore per gli ogetti di classe Albo Telematico Trentino> per accodare i nuovi contenitori albo" );

    ########################################################################################
    ## schedulazione importer
    ########################################################################################
    if ( $containerObject instanceof eZContentObject )
    {
        OpenPALog::output( "Schedulazione importer" );
        $base = new AlbotelematicoHelperBase();
        $base->getCreatorID(); // crea utente albotelematico se non esiste
        $user = eZUser::fetchByName( 'albotelematico' );
        eZUser::setCurrentlyLoggedInUser( $user , $user->attribute( 'contentobject_id' ) );
        ObjectAlbotelematicoHelper::appendImporterByObjectId( $containerObject->attribute( 'id' ) );
    }

    ########################################################################################
    ## salvo handler in ini
    ########################################################################################
    OpenPALog::output( "Salvataggio configurazioni helper" );
    $siteAccess = eZSiteAccess::current();
    $path = "settings/siteaccess/{$siteAccess['name']}/";
    $iniFile = "alboimporthandler.ini";
    $block = "HelperSettings";
    $settingName = "HelperClass";
    $settingValue = "ObjectAlbotelematicoHelper";
    $ini = new eZINI( $iniFile . '.append', $path, null, null, null, true, true );
    $ini->setVariable( $block, $settingName, $settingValue );

    //OpenPAINI::set( 'TopMenu', 'IdentificatoriMenu', array( ObjectAlbotelematicoHelper::CONTAINER_CLASS_IDENTIFIER ) );
    //OpenPAINI::set( 'SideMenu', 'IdentificatoriMenu', array( ObjectAlbotelematicoHelper::CONTAINER_CLASS_IDENTIFIER ) );

    if ( $ini->save() )
    {
        eZCache::clearByTag( 'ini' );
    }
    else
    {
        throw new Exception( "Scrittura fallita su $path/$iniFile" );
    }

    $script->shutdown();
}
catch( Exception $e )
{    
    $errCode = $e->getCode();
    $errCode = $errCode != 0 ? $errCode : 1; // If an error has occured, script must terminate with a status other than 0
    $script->shutdown( $errCode, $e->getMessage() );
}