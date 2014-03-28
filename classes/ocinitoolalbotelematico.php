<?php

class OCIniToolAlbotelematico implements OCIniToolInterface
{
    public $locations;
    public $test = null;    
    public $error = null;
    public $xml = false;
    public $helper = null;

    public function run()
    {
        try
        {
            $scheduledImport = array_merge(
                SQLIScheduledImport::fetchList( 0, null, array( 'handler' => 'albo' ) ),
                SQLIScheduledImport::fetchList( 0, null, array( 'handler' => 'alboimporthandler' ) )
            );
            if ( count( $scheduledImport ) == 0 )
            {
                throw new Exception( "Non è attivato alcun importatore dell'albo telematico trentino" );
            }
            $helperClass = eZINI::instance( 'alboimporthandler.ini' )->variable( 'HelperSettings', 'HelperClass' );            
            if ( class_exists( $helperClass ) )
            {
                $this->helper = new $helperClass();
            }
            else
            {
                throw new Exception( "Classe helper non trovata" );
            }
    
            if ( !$this->helper instanceof AlbotelematicoHelperInterface )
            {
                throw new Exception( "$helperClass non implementa l'interfaccia corretta" );
            }
            
            $http = eZHTTPTool::instance();
            
            if( $http->hasPostVariable( 'SaveLocations' ) )
            {
                $locations = $http->postVariable( 'AlboLocations' );
                if ( !$this->helper->saveINILocations( $locations ) )
                {
                    $this->error = "Non riesco a salvare le configurazioni: il file potrebbe non essere scrivibile";
                }
                elseif ( $this->module instanceof eZModule )
                {
                    return $this->module->redirectTo( 'inigui/tools/albolocation' );
                }
            }
            
            $this->locations = $this->helper->getDefaultLocations();                        
            if( $http->hasPostVariable( 'test' ) )
            {
                $rawText = $http->postVariable( 'test' );
                $this->xml = trim( $rawText );
                $row = new SimpleXMLElement( $this->xml );
                $options = eZINI::instance( 'sqliimport.ini' )->group( 'alboimporthandler-HandlerSettings' );
                $this->helper->loadArguments( array( 'comune' => 'test', 'ente' => 'test', 'comunita' => 'test' ), $options );
                $this->helper->setCurrentRow( $row );

                $test['classIdentifier'] = $this->helper->getClassIdentifier();
                $test['locations'] = $this->helper->getLocations();                
                $test['values'] = $this->helper->attributesMap();
                $this->test = $this->html_show_array( $test );
            }
        }
        catch( Exception $e )
        {
            $this->error = $e->getMessage();
        }
    }

    public function useTemplate()
    {
        return true;
    }

    public function template()
    {
        $tpl = eZTemplate::factory();
        $tpl->setVariable( 'location_hash', $this->locations );
        if ( $this->test !== null )
            $tpl->setVariable( 'test', $this->test );
        if ( $this->error !== null )
            $tpl->setVariable( 'error', $this->error );
        $tpl->setVariable( 'xml', $this->xml );
        $Result = array();
        $tpl->setVariable( 'page_title', 'Collocazioni Albotelematico' );
        $Result['path'] = array( array( 'text' => 'Collocazioni Albotelematico', 'url' => false ) );
        $Result['content'] = $tpl->fetch( 'design:iniguitools/albotelematico.tpl' );
        return $Result;
    }
    
    //http://www.terrawebdesign.com/multidimensional.php
    function do_offset($level){
        $offset = "";             // offset for subarry 
        for ($i=1; $i<$level;$i++){
        $offset = $offset . "<td></td>";
        }
        return $offset;
    }
    
    function show_array($array, $level, $sub, $return){
        if (is_array($array) == 1){          // check if input is an array
           foreach($array as $key_val => $value) {
               $offset = "";
               if (is_array($value) == 1){   // array is multidimensional
               $return .= "<tr>";
               $offset = $this->do_offset($level);
               $return .= $offset . "<td>" . $key_val . "</td>";
               $return .= $this->show_array($value, $level+1, 1);
               }
               else{                        // (sub)array is not multidim
               if ($sub != 1){          // first entry for subarray
                   $return .= "<tr nosub>";
                   $offset = $this->do_offset($level);
               }
               $sub = 0;
               $return .= $offset . "<td main ".$sub." width=\"120\">" . $key_val . "</td><td width=\"120\">" . $value . "</td>"; 
               $return .= "</tr>\n";
               }
           } //foreach $array
        }  
        return $return;
    }
    
    function html_show_array($array){
      $return = "<table cellspacing=\"0\" border=\"0\" class=\"list\">\n";
      $return .= $this->show_array($array, 1, 0, $return);
      $return .= "</table>\n";
      return $return;
    }    
    
}
