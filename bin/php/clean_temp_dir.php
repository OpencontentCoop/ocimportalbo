<?php

include( 'autoload.php' );

$script = eZScript::instance(
    array(
        "description" => "Clean temp csv albotelematico files.",
        "use-session" => false,
        "use-modules" => false,
        "use-extensions" => true,
    )
);

$script->startup();
$options = $script->getOptions(
    "[n]",
    "",
    array(
        "-q" => "Quiet mode",
        "n" => "Do not wait"
    )
);

$script->initialize();

$cli = eZCLI::instance();

$helper = new AlbotelematicoHelperBase();

$cli->warning( "This cleanup script will remove any file from " . $helper->tempVarDir . ' and all xml and txt files in ' . $helper->tempLogDir );
if ( !isset( $options['n'] ) )
{    
    $cli->warning();
    $cli->warning( "IT IS YOUR RESPONSABILITY TO TAKE CARE THAT NO ITEMS REMAINS IN TRASH BEFORE RUNNING THIS SCRIPT." );
    $cli->warning();
    $cli->warning( "You have 30 seconds to break the script (press Ctrl-C)." );
    sleep( 30 );
}

$fileList = array();
eZDir::recursiveList( $helper->tempVarDir, $helper->tempVarDir, $fileList );
foreach( $fileList as $file )
{
    if ( $file['type'] == 'file' )
    {
        $filepath = $file['path'] . $file['name'];
        $item = eZClusterFileHandler::instance( $filepath );
        if ( $item->exists() )
        {
            $cli->output( 'Remove ' . $filepath );
            $item->delete();
            $item->purge();
        }        
    }
}

$fileList = array();
eZDir::recursiveList( $helper->tempLogDir, $helper->tempLogDir, $fileList );
foreach( $fileList as $file )
{
    if ( $file['type'] == 'file' )
    {
        $filepath = $file['path'] . $file['name'];
        $item = eZClusterFileHandler::instance( $filepath );
        $ext = substr( $filepath, -3, 3 );
        
        if ( $item->exists() && ( $ext == 'txt' || $ext == 'xml' ) )
        {
            $cli->output( 'Remove ' . $filepath );
            $item->delete();
            $item->purge();
        }        
    }
}

$script->shutdown();
?>