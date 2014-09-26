<?php
 
class AddAlboImporterType extends eZWorkflowEventType
{
    const WORKFLOW_TYPE_STRING = "addalboimporter";

    public function __construct()
    {
        parent::eZWorkflowEventType( AddAlboImporterType::WORKFLOW_TYPE_STRING, 'Schedula l\'importatore per gli ogetti di classe Albo Telematico Trentino' );
    }

    /**
     * @param eZWorkflowProcess $process
     * @param eZWorkflowEvent $event
     *
     * @return int
     */
    public function execute( $process, $event )
    {
        $parameters = $process->attribute( 'parameter_list' );
        $objectID = $parameters['object_id'];
        $object = eZContentObject::fetch( $objectID );
        if ( $object instanceof eZContentObject )
        {
            if ( $object->attribute( 'class_identifier' ) == ObjectAlbotelematicoHelper::CONTAINER_CLASS_IDENTIFIER )
            {
                ObjectAlbotelematicoHelper::appendImporterByObjectId( $objectID );
            }
        }

        
        return eZWorkflowType::STATUS_ACCEPTED;
    }
}
eZWorkflowEventType::registerEventType( AddAlboImporterType::WORKFLOW_TYPE_STRING, 'addalboimportertype' );
?>