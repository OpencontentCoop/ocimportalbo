<?php

class OCIniToolAlbotelematico implements OCIniToolInterface
{
    public $locations;
    public $test = null;

    public function run()
    {
        $helper = new AlbotelematicoHelper();
        $this->locations = $helper->getDefaultLocations();
        $http = eZHTTPTool::instance();
        if( $http->hasPostVariable( 'test' ) )
        {
            try
            {
                $this->test = new SimpleXMLElement( $http->postVariable( 'test' ) );
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
