<?php
require 'autoload.php';

$script = eZScript::instance( array( 'description' => ( "OpenPA Fix data convocazione\n\n" ),
                                     'use-session' => false,
                                     'use-modules' => true,
                                     'use-extensions' => true ) );

$script->startup();
$script->getOptions();
$options = array( 'class' => 'seduta_consiglio',
                  'from' => 'data',
                  'to' => 'data_convocazione' );
$script->initialize();
$script->setUseDebugAccumulators( true );

OpenPALog::setOutputLevel( OpenPALog::ALL );


try
{
    
    $errorClassCount = 0;
    $errorTreeCount = 0;
    $user = eZUser::fetchByName( 'admin' );
    eZUser::setCurrentlyLoggedInUser( $user , $user->attribute( 'contentobject_id' ) );
    
    $siteaccess = eZSiteAccess::current();
    if ( stripos( $siteaccess['name'], 'prototipo' ) !== false )
    {
        throw new Exception( 'Script non eseguibile sul prototipo' );        
    }
    
    $scheduledImport = array_merge(
        SQLIScheduledImport::fetchList( 0, null, array( 'handler' => 'albo' ) ),
        SQLIScheduledImport::fetchList( 0, null, array( 'handler' => 'alboimporthandler' ) )
    );
    if ( count( $scheduledImport ) == 0 )
    {
        throw new Exception( "Non è attivato alcun importatore dell'albo telematico trentino" );
    }
    
    if ( isset( $options['class'] ) && isset( $options['from'] ) && isset( $options['to'] ) )
    {
        $class = eZContentClass::fetchByIdentifier( $options['class'] );
        if ( !$class instanceof eZContentClass )
        {
            throw new Exception( "La classe {$options['class']} non esiste" );
        }
        $originalAttribute = $class->fetchAttributeByIdentifier( $options['from'] );
        if ( !$originalAttribute instanceof eZContentClassAttribute )
        {
            throw new Exception( "L'attributo {$options['from']} non esiste nella classe {$options['class']}" );
        }        
        
        $identifier = trim( $options['to'] );
        $trans = eZCharTransform::instance();
        $identifier = $trans->transformByGroup( $identifier, 'identifier' );
        $alreadyExists = $class->fetchAttributeByIdentifier( $identifier );
        if ( !$alreadyExists )
        {
            $originalAttribute->setAttribute( 'identifier', $identifier );
            $originalAttribute->store();
            
            $tools = new OpenPAClassTools( $options['class'] );
            $tools->sync( true );
            
            $nodes = eZContentObjectTreeNode::subTreeByNodeID( array( 'ClassFilterType' => 'include', 'ClassFilterArray' => array( $options['class'] ) ), 1 );        
            foreach( $nodes as $innerNode )
            {
              $object = $innerNode->attribute( 'object' );
              $class = $object->contentClass();
              $object->setName( $class->contentObjectName( $object ) );
              $object->store();
            }
            
        }
        else
        {
            throw new Exception( "L'identificatore $identifier è già in uso" );            
        }
    }
    else
    {
        throw new Exception( "Inserisci tutti gli argomenti" );
    }
    
    $script->shutdown();
}
catch( Exception $e )
{    
    $errCode = $e->getCode();
    $errCode = $errCode != 0 ? $errCode : 1; // If an error has occured, script must terminate with a status other than 0
    $script->shutdown( $errCode, $e->getMessage() );
}
