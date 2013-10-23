<?php

class OCSCAlboTelematicoSource extends OCSCAbstractSource
{
    protected $alboINI;
    protected $classes;
    protected $tools;
    
    function __construct()
    {
        $this->alboINI = eZINI::instance( 'alboimporthandler.ini' );
        $this->tools = new EntiLocaliTools();
    }
    
    function getSourceName()
    {
        return 'AlboTelematico.tn.it';
    }

    function getSourceUser()
    {        
        return false;
    }
    
    function getAvailableClassIdentifiers()
    {
        if( $this->classes == null )
        {
            $classMaps = (array) $this->alboINI->variable( 'MapClassSettings', 'MapClass' );
            foreach( $classMaps as $alboClass => $classIdentifier )
            {
                $classIdentifiers = explode( ';', $classIdentifier );
                foreach( $classIdentifiers as $class )
                {
                    $contentClass = eZContentClass::fetchByIdentifier( $class );
                    if ( $contentClass instanceof eZContentClass )
                    {
                        $this->classes[$contentClass->attribute( 'identifier' )] = $contentClass->attribute( 'name' );
                    }
                }
            }
        }
        return $this->classes;
    }
    
    function getLocationsByClass( $classIdentifier )
    {        
        $locations = $this->getStoredLocationsByClass( $classIdentifier );
        if ( empty( $locations ) )
        {
            $this->storeDefaultLocations();
        }
        return parent::getLocationsByClass( $classIdentifier );
    }
    
    public function getLocationsByObject( eZContentObject $object )
    {
        $selectedNodeIDArray = array();
        $dataMap = $object->attribute( 'data_map' );
        if ( isset( $dataMap['comune'] ) &&
             $dataMap['comune'] instanceof eZContentObjectAttribute &&
             $dataMap['comune']->attribute( 'has_content' ) )
        {
            $rootNode = eZINI::instance( 'content.ini' )->variable( 'NodeSettings', 'RootNode' );
            $comuni = $dataMap['comune']->content();                                        
            foreach( $comuni['relation_list']  as $key => $comune )
            {
                $comune = eZContentObject::fetch( $comune['contentobject_id'] );
                if ( $comune instanceof eZContentObject )
                {
                    $assignedNodes = $comune->attribute( 'assigned_nodes' );
                    foreach( $assignedNodes as $assignedNode )
                    {
                        $pathArray = $assignedNode->attribute( 'path_array' );
                        if ( in_array( $rootNode, $pathArray ) )
                        {
                            $selectedNodeIDArray[] = $assignedNode->attribute( 'node_id' );
                        }
                    }
                }
            }      
        }
        if ( empty( $selectedNodeIDArray ) )
        {
            return false;
        }
        return $selectedNodeIDArray;
    }
    
    function getBaseLocations()
    {
        $storage = $this->getStorageComunita();
        if ( $storage )
        {
            return array( $storage );
        }
        return array();
    }
    
    private function getStorageComunita()
    {
        $rootNode = eZContentObjectTreeNode::fetch( eZINI::instance( 'content.ini' )->variable( 'NodeSettings', 'RootNode' ) );
        if ( $rootNode instanceof eZContentObjectTreeNode && $rootNode->attribute( 'class_identifier' ) == 'comunita' )
        {
            return $this->tools->ricavaStorageComunita( $rootNode->attribute( 'contentobject_id' ) ); 
        }
        return false;
    }    
    
    private function storeDefaultLocations()
    {        
        if ( $this->getStorageComunita() )
        {
            $classMaps = (array) $this->alboINI->variable( 'MapClassSettings', 'MapClass' );
            $iniLocations = eZINI::instance( 'entilocali.ini' )->hasVariable( 'LocationsPerClasses', 'Storage_' . $this->getStorageComunita() ) ?
                eZINI::instance( 'entilocali.ini' )->variable( 'LocationsPerClasses', 'Storage_' . $this->getStorageComunita() ) :
                array();
            $locationPerClasses = array();
            foreach( $iniLocations as $classAndNode )
            {
                $classAndNode = explode( ';', $classAndNode );            
                $locationPerClasses[$classAndNode[1]] = explode( ',', $classAndNode[0] );
            }
                        
            foreach( $classMaps as $alboClass => $classIdentifier )
            {
                $classIdentifiers = explode( ';', $classIdentifier );
                
                foreach( $classIdentifiers as $class )
                {
                    $parentLocations = array();            
                    foreach( $locationPerClasses as $l => $c )
                    {
                        if ( in_array( $class, $c ) )
                        {
                            $parentLocations = array( $l );
                        }
                    }
                    
                    $nodes = array();
                    foreach( $parentLocations as $node )
                    {
                        $nodes[] = eZContentObjectTreeNode::fetch( $node );                    
                    }
                                                    
                    foreach( $nodes as $index => $node )
                    {
                        if ( !$node instanceof eZContentObjectTreeNode )
                        {
                            $nodes[$index] = 0;
                        }
                        else
                        {
                            $nodes[$index] = $node->attribute( 'node_id' );
                        }
                    }
                    
                    $this->storeLocationsForClass( $class, $nodes );
                }
            }
        }
    }
}
