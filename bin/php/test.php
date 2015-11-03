<?php
include( 'autoload.php' );

$script = eZScript::instance(
    array(
        "description" => "Testa una strings xml",
        "use-session" => false,
        "use-modules" => false,
        "use-extensions" => true,
    )
);

$script->startup();
$options = $script->getOptions(
    "[id:][row:]",
    "",
    array(
        "id" => "Id oggetto di classe Albo telematico trentino per inizializzare il test",
        "row" => "Elemento XML"
    )
);

$script->initialize();
$cli = eZCLI::instance();

$user = eZUser::fetchByName( 'admin' );
eZUser::setCurrentlyLoggedInUser( $user , $user->attribute( 'contentobject_id' ) );

$handlerOptions = array(
    'object' => $options['id']
);
$handlerConfArray = eZINI::instance( 'sqliimport.ini' )->group( 'albo-HandlerSettings' );

$helper = new ObjectAlbotelematicoHelper();
$helper->loadArguments( $handlerOptions, $handlerConfArray );

$helper->loadObject();

$row = simplexml_load_string( $options['row'] );

$helper->setCurrentRow( $row );

$classIdentifier = $helper->getClassIdentifier();
$values = $helper->prepareValues();
$eZValues = $helper->attributesMap();
$locations = array();
$locationsIds = $helper->getLocations();
foreach( $locationsIds as $id )
{
    $location = eZContentObjectTreeNode::fetch( $id );
    if ( $location )
        $locations[] = $location->attribute( 'path_identification_string' );
    else
        $locations[] = "Node not found for {$id}";
}

$cli->warning( "Classe:" );
$cli->notice( $classIdentifier );
$cli->notice();

$cli->warning( "Valori albo:" );
var_export( $values );
$cli->notice();
$cli->notice();

$cli->warning( "Valori ez:" );
var_export( $eZValues );
$cli->notice();
$cli->notice();

$cli->warning( "Collocazioni:" );
var_export( $locations );
$cli->notice();
$cli->notice();

$script->shutdown();