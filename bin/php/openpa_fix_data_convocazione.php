<?php

include( 'autoload.php' );
$arguments = OpenPABase::getOpenPAScriptArguments();
$siteaccess = OpenPABase::getInstances();
foreach( $siteaccess as $sa )
{
    $command = "php extension/ocimportalbo/bin/php/fix_data_convocazione.php -s$sa " . implode( ' ', $arguments );
    print "Eseguo: $command \n";
    system( $command );
}

?>
