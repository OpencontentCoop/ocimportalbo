<?php

class OCIniToolAlbotelematico implements OCIniToolInterface
{
    public $locations;
    public $test = false;
    public $xml = false;
    public $helper = null;

    public function run()
    {
        try
        {
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
            
            $this->locations = $this->helper->getDefaultLocations();
            $http = eZHTTPTool::instance();
            if( $http->hasPostVariable( 'test' ) )
            {
                $rawText = $http->postVariable( 'test' );
                $this->xml = trim( $rawText );
                $row = new SimpleXMLElement( $rawText );
                $options = eZINI::instance( 'sqliimport.ini' )->group( 'alboimporthandler-HandlerSettings' );
                $this->helper->loadArguments( array( 'comune' => 'test' ), $options );
                $this->helper->setCurrentRow( $row );

                $this->test['classIdentifier'] = $this->helper->getClassIdentifier();
                $this->test['locations'] = $this->helper->getLocations();
                $this->test['values'] = $this->helper->attributesMap();                
            }
        }
        catch( Exception $e )
        {
            $this->test = $e->getMessage();
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
        $tpl->setVariable( 'test', $this->test );
        $tpl->setVariable( 'xml', $this->xml );
        $Result = array();
        $tpl->setVariable( 'page_title', 'Collocazioni Albotelematico' );
        $Result['path'] = array( array( 'text' => 'Collocazioni Albotelematico', 'url' => false ) );
        $Result['content'] = $tpl->fetch( 'design:iniguitools/albotelematico.tpl' );
        return $Result;
    }
}
