<?php

class OCIniToolAlbotelematico implements OCIniToolInterface
{
    public $locations;
    public $test = false;
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
                $row = new SimpleXMLElement( $http->postVariable( 'test' ) );
                $options = eZINI::instance( 'sqlimport.ini' )->group( 'alboimporthandler-HandlerSettings' );
                $this->helper->loadArguments( array( 'comune' => 'test' ), $options );
                $this->helper->setCurrentRow( $row );

                $this->test['classIdentifier'] = $this->helper->getClassIdentifier();
                $this->test['locations'] = $this->helper->getLocations();
                $this->test['values'] = $this->helper->prepareValues();

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
        $Result = array();
        $tpl->setVariable( 'page_title', 'Collocazioni Albotelematico' );
        $Result['path'] = array( array( 'text' => 'Collocazioni Albotelematico', 'url' => false ) );
        $Result['content'] = $tpl->fetch( 'design:iniguitools/albotelematico.tpl' );
        return $Result;
    }
}
