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
    array(
        'original_node'  => 'Nodo "Albo Pretorio" da migrare nella nuova classe'
    )
);
$script->initialize();
$script->setUseDebugAccumulators( true );

OpenPALog::setOutputLevel( OpenPALog::ALL );


try
{
    /** @var SQLIScheduledImport[] $scheduledImports */
    $scheduledImports = array_merge(
        SQLIScheduledImport::fetchList( 0, null, array( 'handler' => 'albo' ) ),
        SQLIScheduledImport::fetchList( 0, null, array( 'handler' => 'alboimporthandler' ) )
    );
    if ( count( $scheduledImports ) == 0 )
    {
        //throw new Exception( "Non è attivato alcun importatore dell'albo telematico trentino" );
    }
    else
    {
        $scheduledImport = $scheduledImports[0];
        $options = $scheduledImport->attribute( 'options' );
        $comune = $options['comune'];
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
                array( 'StateGroup_albotelematico' => array(
                    AlbotelematicoHelperBase::getStateID( 'visibile' ),
                    AlbotelematicoHelperBase::getStateID( 'archivioricercabile' ),
                    AlbotelematicoHelperBase::getStateID( 'archiviononricercabile' )
                ) )
            );
            $anonymousRole->store();
        }
    }
    else
    {
        throw new Exception( 'Non trovo il ruolo "Anonymous"' );
    }

    ########################################################################################
    ## cerco la frontpage di nome Albo Pretorio
    ########################################################################################
    OpenPALog::output( "Conversione frontpage Albo pretorio" );
    $frontPageClassId = eZContentClass::classIDByIdentifier( 'frontpage' );
    $search = eZSearch::search( "Albo Pretorio", array( 'SearchContentClassID' => $frontPageClassId ) );
    if ( $search['SearchCount'] > 0 )
    {
        /** @var eZContentObjectTreeNode $original */
        $original = $search['SearchResult'][0];
    }
    elseif ( $options['original_node'] )
    {
        $original = eZContentObjectTreeNode::fetch( $options['original_node'] );
    }

    if ( !$original instanceof eZContentObjectTreeNode )
    {
        throw new Exception( "Non trovo l'albo pretorio: specifica l'opzione --original_node=<node_id>" );
    }
die('ciao');
    ########################################################################################
    ## migrazione container
    ########################################################################################
    $destinationClass = ObjectAlbotelematicoHelper::CONTAINER_CLASS_IDENTIFIER;
    $mapping = array();
    foreach( $original->attribute( 'data_map' ) As $key => $value )
    {
        $mapping[$key] = $key;
    }

    if ( !class_exists( 'conversionFunctions' ) )
    {
        throw new Exception( "Libreria 'conversionFunctions' non trovata" );
    }

    /** @var eZContentObjectTreeNode $container */
    $conversionFunctions = new conversionFunctions();
    $container = $conversionFunctions->convertObject( $original->attribute('contentobject_id'), $destinationClass, $mapping );
    if ( !$container )
    {
        throw new Exception( "Errore nella conversione dell'oggetto contentitore" );
    }

    ########################################################################################
    ## popolamento container
    ########################################################################################
    /** @var eZContentObject $containerObject */
    $containerObject = $container->attribute( 'object' );
    $containerObjectDataMap = $containerObject->attribute( 'data_map' );

    if ( strpos( OpenPABase::getFrontendSiteaccessName(), '_frontend' ) === false )
    {
        throw new Exception( "Sei in entilocali? Occhio al vecchio handler... " ); //@todo
    }
    $oldHandler = new OpenPaAlbotelematicoHelper();
    $defaultLocations = $oldHandler->getDefaultLocations();
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
                $db = eZDB::instance();
                $db->begin();
                $attribute->fromString( implode( '-', $objectIds ) );
                $attribute->store();
                $db->commit();
            }
        }
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

    ########################################################################################
    ## schedulazione importer
    ########################################################################################
    OpenPALog::output( "Schedulazione importer" );
    ObjectAlbotelematicoHelper::appendImporterByObjectId( $containerObject->attribute( 'id' ) );

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
