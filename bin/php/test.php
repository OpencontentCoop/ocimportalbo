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

$row = simplexml_load_string( $options['row'] );

$helper->setCurrentRow( $row );

$classIdentifier = $helper->getClassIdentifier();
$values = $helper->attributesMap();
$locations = $helper->getLocations();

$cli->notice( "Classe: {$classIdentifier}" );

$cli->notice( "Valori:" );
var_export( $values );

$cli->notice( "Collocazioni:" );
var_export( $locations );

$script->shutdown();