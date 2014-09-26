<?php
include( 'autoload.php' );

$script = eZScript::instance(
    array(
         "description" => "Corregge gli stati degli atti importati.",
         "use-session" => false,
         "use-modules" => false,
         "use-extensions" => true,
    )
);

$script->startup();
$options = $script->getOptions(
    "[ente:]",
    "",
    array(
         "ente" => "Specifica il nome dell'ente ricavabile da http://www.albotelematico.tn.it/archivio_stato/<enteId>/exc.xml"
    )
);

$script->initialize();
$cli = eZCLI::instance();

$ente = $options['ente'];
$feed = "http://www.albotelematico.tn.it/archivio_stato/{$ente}/exc.xml";
ObjectAlbotelematicoHelper::fixObjectStates( $feed );

