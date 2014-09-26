<?php

class ObjectHandlerServiceContentAlboTelematico extends ObjectHandlerServiceBase
{
    function run()
    {
        $this->data['is_container'] = $this->isContainer();
        $this->data['container_template'] = "design:openpa/services/content_albotelematico/container.tpl";
        $this->data['is_atto'] = $this->isAtto();
        $this->data['states'] = $this->getStates();

        $this->data['default_state_ids'] = array( AlbotelematicoHelperBase::getStateID( AlbotelematicoHelperBase::STATE_VISIBILE ) );
        $this->data['archive_state_ids'] = array(
            AlbotelematicoHelperBase::getStateID( AlbotelematicoHelperBase::STATE_ARCHIVIO_RICERCABILE ),
            AlbotelematicoHelperBase::getStateID( AlbotelematicoHelperBase::STATE_ARCHIVIO_NON_RICERCABILE )
        );
    }

    function filter( $filterIdentifier, $action )
    {
        if ( $filterIdentifier == 'change_section'
             && $action == 'run'
             && $this->isAtto()
             && OpenPAINI::variable( 'HelperSettings', 'HelperClass' ) == 'ObjectAlbotelematicoHelper' )
        {
            return OpenPAObjectHandler::FILTER_HALT;
        }
        return parent::filter( $filterIdentifier, $action );
    }

    protected function getStates()
    {
        $data = array();
        $access = eZUser::currentUser()->hasAccessTo( 'content', 'read');
        $hasAccess = $access['accessWord'] == 'yes' ? true : array();
        if ( isset( $access['policies'] ) && is_array( $hasAccess ) )
        {
            foreach( $access['policies'] as $policies )
            {
                foreach( $policies as $name => $policy )
                {
                    if ( $name == 'StateGroup_albotelematico' )
                    {
                        $hasAccess = array_merge( $hasAccess, $policy );
                    }
                }
            }
        }
        foreach( AlbotelematicoHelperBase::objectStatesArray() as $identifier => $name )
        {
            try
            {
                $object = AlbotelematicoHelperBase::getState( $identifier );
                if ( $hasAccess === true || ( is_array( $hasAccess ) && in_array( $object->attribute( 'id' ), $hasAccess ) ) )
                {
                    $data[$identifier] = array( 'state_object' => $object, 'name' => $name ); //@todo
                }
            }
            catch( Exception $e )
            {
                eZDebug::writeError( $e->getMessage(), __METHOD__ );
            }
        }
        return $data;
    }

    protected function isContainer()
    {
        $data = false;
        if ( $this->container->currentClassIdentifier == 'pagina_sito' )
        {
            $current = $this->container->getContentObject();
            if ( $current instanceOf eZContentObject )
            {
                $reverseRelated = $current->reverseRelatedObjectList( false, false, false, array( 'AllRelations' => true ) );
                if ( count( $reverseRelated ) > 0 )
                {
                    foreach( $reverseRelated as $related )
                    {
                        if ( $related->attribute( 'class_identifier' ) == ObjectAlbotelematicoHelper::CONTAINER_CLASS_IDENTIFIER )
                        {
                            $data = true;
                            break;
                        }
                    }
                }
            }
        }
        return $data;
    }

    protected function isAtto()
    {
        $data = false;
        $current = $this->container->getContentObject();
        if ( $current instanceOf eZContentObject )
        {
            if ( substr( $current->attribute( 'remote_id' ), 0, 3 ) == 'at_' )
            {
                $data = true;
            }
        }
        return $data;
    }
}