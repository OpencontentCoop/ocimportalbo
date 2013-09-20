<?php

class OCIniToolAlbotelematico implements OCIniToolInterface
{
    public $locations;
    public $test = false;
    public $xml = false;
    public $helper = null;

    public function run()
    {
        $this->helper = new OpenPaAlbotelematicoHelper();
        $this->locations = $this->helper->getDefaultLocations();
        $http = eZHTTPTool::instance();
        if( $http->hasPostVariable( 'test' ) )
        {
            try
            {
                $rawText = $http->postVariable( 'test' );
                $row = new SimpleXMLElement( $rawText );
                $options = eZINI::instance( 'sqliimport.ini' )->group( 'alboimporthandler-HandlerSettings' );
                $this->helper->loadArguments( array( 'comune' => 'test' ), $options );
                $this->helper->setCurrentRow( $row );

                $this->test['classIdentifier'] = $this->helper->getClassIdentifier();
                $this->test['locations'] = $this->helper->getLocations();
                $this->test['values'] = $this->helper->prepareValues();
                $this->xml = trim( $rawText );


            }
            catch( Exception $e )
            {
                $this->test = $e->getMessage();
            }
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
