<?php
require 'autoload.php';

$script = eZScript::instance( array( 'description' => ( "OpenPA Fix albo schedule\n\n" ),
                                     'use-session' => false,
                                     'use-modules' => true,
                                     'use-extensions' => true ) );

$script->startup();
$options = $script->getOptions();
$script->initialize();
$script->setUseDebugAccumulators( true );

OpenPALog::setOutputLevel( OpenPALog::ALL );

try
{
    $scheduledImports = array_merge(
            SQLIScheduledImport::fetchList( 0, null, array( 'handler' => 'albo' ) ),
            SQLIScheduledImport::fetchList( 0, null, array( 'handler' => 'alboimporthandler' ) )
        );
    
    foreach( $scheduledImports as $scheduledImport )
    {
        if ( $scheduledImport instanceof SQLIScheduledImport )
        {            
            OpenPALog::warning( "Change {$scheduledImport->attribute( 'label' )}" );
            $scheduledImport->setAttribute( 'frequency', SQLIScheduledImport::FREQUENCY_HOURLY );
            $scheduledImport->store();
        }
    }
    $script->shutdown();
}
catch( Exception $e )
{    
    $errCode = $e->getCode();
    $errCode = $errCode != 0 ? $errCode : 1; // If an error has occured, script must terminate with a status other than 0
    $script->shutdown( $errCode, $e->getMessage() );
}