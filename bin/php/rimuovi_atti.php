#!/usr/bin/env php
<?php
set_time_limit ( 0 );
require_once 'autoload.php';

$cli = eZCLI::instance();
$script = eZScript::instance( array(  'description' => ( "Rimuove gli atti importati da Albotelematico" ),
                                      'use-session' => false,
                                      'use-modules' => true,
                                      'use-debug' => true,
                                      'use-extensions' => true ) );


$options = $script->getOptions( "[force_admin][show_parents]",
                                "",
                                array( 'force_admin' => "Filtra atti creati da admin",
                                       'show_parents' =>  "Mostra i nomi e i percorsi dei parent nodes"
                                       ) );
$script->initialize();

$script->startup();

$script->initialize();

$cli->output( 'Inizio la procedura' );

$user = eZUser::fetchByName( 'admin' );
if ( $user )
    eZUser::setCurrentlyLoggedInUser( $user, $user->attribute( 'contentobject_id' ) );
else
{
    $cli->error( 'Could not fetch admin user object' );
    $script->shutdown( 1 );
    return;
}

$userAlbo = $options['force_admin'] ? false : eZUser::fetchByName( 'albotelematico' );

$helperClass = eZINI::instance( 'alboimporthandler.ini' )->variable( 'HelperSettings', 'HelperClass' );
$helper = new $helperClass();
$locations = $helper->getDefaultLocations( true );

$classIdentifiers = array();
$parentNodes = array();

foreach( $locations as $alboClass => $arrayLocations )
{
    foreach( $arrayLocations as $classIdentifier => $values )
    {
        if ( is_string( $classIdentifier ) )
        {
            $classIdentifiers[] = $classIdentifier;        
        }
        if ( isset( $values['node_ids'] ) )
        {
            foreach( $values['node_ids'] as $nodeID )
            {
                if ( $nodeID > 0 )
                {
                    $parentNodes[] = $nodeID;
                }
            }
        } 
    }
}

$classIdentifiers = array_unique( $classIdentifiers );
$parentNodes = array_unique( $parentNodes );

$cli->output( 'Rimuovo oggetti di classe ' . implode( ', ', $classIdentifiers ) );
$cli->output( 'dai nodi ' . implode( ', ', $parentNodes ) );
 
if ( !$userAlbo )
{
    $userAlbo = $user;
    $cli->warning( 'Verranno rimossi gli atti inseriti da admin, Ctrl + C per annullare' );
    sleep(5);
}

if ( $options['show_parents'] )
{
    foreach( $parentNodes as $nodeID )
    {
        $node = eZContentObjectTreeNode::fetch( $nodeID );
        $cli->output();
        $cli->output( $nodeID . ' ' . $node->attribute( 'name' ) . ' ' . $node->attribute( 'path_identification_string' ) );        
    }
}
else
{
    $nodesParams = array();
    foreach( $parentNodes as $nodeID )
    {
        $nodes = eZContentObjectTreeNode::subTreeByNodeID( array( 'ClassFilterType' => 'include',
                                                                  'ClassFilterArray'  =>  $classIdentifiers,
                                                                  'AttributeFilter' => array( array( 'owner', '=', $userAlbo->attribute( 'contentobject_id' ) ) ) ),
                                                           $nodeID );
        $cli->warning( 'Rimuovo ' . count( $nodes ) . ' nodi dal parentNode ' . $nodeID );
        foreach( $nodes as $node )
        {
            $cli->warning( 'Rimuovo ' .$node->attribute( 'class_identifier' ) . ' ' . $node->attribute( 'node_id' ) . ' ' . $node->attribute( 'name' ) );
            eZContentObjectOperations::remove( $node->attribute( 'contentobject_id' ) );        
        }
        $cli->warning();
    }
}

$cli->output( 'Concludo la procedura' );
$script->shutdown();
?>