<?php

include( 'autoload.php' );
$arguments = OpenPABase::getOpenPAScriptArguments();
$siteaccess = OpenPABase::getInstances();
foreach( $siteaccess as $sa )
{
    $command = "php extension/ocimportalbo/bin/php/fix_scheduled_hourly.php -s$sa ";
    print "Eseguo: $command \n";
    system( $command );
}

?>
