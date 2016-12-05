<?php
include( 'autoload.php' );

$script = eZScript::instance(
    array(
        "description" => "Importa atto",
        "use-session" => false,
        "use-modules" => false,
        "use-extensions" => true,
    )
);

$script->startup();
$options = $script->getOptions(
    "[albo:][id:][test]",
    "",
    array(
        "albo" => "Id oggetto di classe Albo telematico trentino",
        "id" => "Id atto",
        "test" => "mostra i valori",
    )
);

$script->initialize();
$cli = eZCLI::instance();

$user = eZUser::fetchByName('admin');
eZUser::setCurrentlyLoggedInUser($user, $user->attribute('contentobject_id'));

$handlerOptions = array(
    'object' => $options['albo']
);
$handlerConfArray = eZINI::instance('sqliimport.ini')->group('albo-HandlerSettings');

$moduleRepositories = eZModule::activeModuleRepositories();
eZModule::setGlobalPathList( $moduleRepositories );

try {
    $helper = new ObjectAlbotelematicoHelper();
    $helper->loadArguments($handlerOptions, $handlerConfArray);

    $atto = $helper->findAtto($options['id']);
    if ($atto instanceof SimpleXMLElement) {
        $helper->setCurrentRow($atto);

        if ($options['test']) {
            $classIdentifier = $helper->getClassIdentifier();
            $values = $helper->prepareValues();
            $eZValues = $helper->attributesMap();
            $locations = array();
            $locationsIds = $helper->getLocations();
            foreach ($locationsIds as $id) {
                $location = eZContentObjectTreeNode::fetch($id);
                if ($location) {
                    $locations[] = $location->attribute('path_identification_string');
                } else {
                    $locations[] = "Node not found for {$id}";
                }
            }

            $cli->warning("Classe:");
            $cli->notice($classIdentifier);
            $cli->notice();

            $cli->warning("Valori albo:");
            var_export($values);
            $cli->notice();
            $cli->notice();

            $cli->warning("Valori ez:");
            var_export($eZValues);
            $cli->notice();
            $cli->notice();

            $cli->warning("Collocazioni:");
            var_export($locations);
            $cli->notice();
            $cli->notice();
        } else {
            $locations = $helper->getLocations();

            $content = $helper->fillContent();
            $helper->setPublishedTimestamp();

            foreach ($locations as $location) {
                $content->addLocation(SQLILocation::fromNodeID($location));
            }

            $publisher = SQLIContentPublisher::getInstance();
            $publisher->publish($content);
            $cli->output( 'Published ' . $helper->getRemoteID() );
        }
    } else {
        $cli->error("not found");
    }


    $script->shutdown();
} catch (Exception $e) {
    $errCode = $e->getCode();
    $errCode = $errCode != 0 ? $errCode : 1; // If an error has occured, script must terminate with a status other than 0
    $script->shutdown($errCode, $e->getMessage());
}

