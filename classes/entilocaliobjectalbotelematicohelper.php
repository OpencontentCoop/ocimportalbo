<?php

class EntiLocaliObjectAlbotelematicoHelper extends ObjectAlbotelematicoHelper
{
    /**
     * @var eZContentObjectTreeNode
     */
    protected $comunita;

    public function getLocations()
    {
        $this->fileLocation = $this->getFileLocationComunita();
        return parent::getLocations();
    }

    protected function getComunita()
    {
        if ( $this->comunita === null )
        {
            $startNode = $this->object->attribute( 'main_node' );
            $classIdentifier = 'comunita';
            if ( $startNode instanceof eZContentObjectTreeNode )
            {
                $path = $startNode->attribute( 'path' );
                $path = array_reverse( $path );
                foreach( $path as $item )
                {
                    if ( $item->attribute( 'class_identifier' ) == $classIdentifier )
                    {
                        $this->comunita = $item;
                        break;
                    }
                }
            }
            if ( $this->comunita === null )
            {
                throw new Exception( "Comunità non trovata nel percorso dell'oggetto contenitore" );
            }
        }
        return $this->comunita;
    }

    protected function getFileLocationComunita()
    {
        /** @var eZContentObject $objectComunita */
        $objectComunita = $this->getComunita()->attribute( 'object' );
        /** @var eZContentObjectAttribute[] $dataMap */
        $dataMap = $objectComunita->dataMap();
        if ( isset( $dataMap['media'] ) )
        {
            $objectID = $dataMap['media']->toString();
            $object = eZContentObject::fetch( $objectID  );
            if ( $object )
            {
                /** @var eZContentObjectTreeNode $mediaNode */
                $mediaNode = $object->attribute( 'main_node' );
                foreach( $mediaNode->children() as $node )
                {
                    if ( $node->attribute( 'name' ) == 'File' )
                    {
                        return $node->attribute( 'node_id' );
                        break;
                    }
                }
            }
        }
        throw new AlboFatalException( "Comunità {$this->comunita->attribute( 'name' )}, non ha un media folder File" );
    }
}