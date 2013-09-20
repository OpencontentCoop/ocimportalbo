<?php
//  php extension/sqliimport/bin/php/sqlidoimport.php --source-handlers="alboimporthandler" -sprototipo_backend --options="alboimporthandler::comune=stenico" -d
/*
<atto>
    <id_atto>67347</id_atto>
    <id_ente>22118</id_ente>
    <desc_ente>Comune di Moena</desc_ente>
    <data_pubblicazione>04/05/2011</data_pubblicazione>
    <data_termine>06/05/2011</data_termine>
    <durata>2</durata>
    <descrizione>Decreti e ordinanze Il Vice Sindaco del 29/04/2011</descrizione>
    <oggetto>Ordinanza n. 20</oggetto>
    <tipo_atto>Decreti e ordinanze</tipo_atto>
    <url>20110504_67347_22118_3_ordinan.pdf</url>
    <allegati>
        <allegato>
            <titolo>Corografia</titolo>
            <url>66813_145955_1.pdf</url>
        </allegato>
    </allegati>
    <altri_enti/>
</atto>
*/

class AlboImportHandler extends SQLIImportAbstractHandler implements ISQLIImportHandler
{
    public $currentGUID;
    public $currentEnte;
    public $currentTipoAtto;
    public $currentName;

    public $options;

    private $rowIndex = 0;
    private $rowCount;
    private $helper;

    /**
     * Constructor
     */
    public function __construct( SQLIImportHandlerOptions $options = null )
    {
        parent::__construct( $options );
        $this->remoteIDPrefix = $this->getHandlerIdentifier() . '-';
        $this->options = $options;
    }

    public function initialize()
    {	
        //@todo controllare che il RobotUser definito in ini abbia permessi adeguati

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

        $this->helper->loadArguments( $this->options, $this->handlerConfArray );    

        $this->helper->loadData();
        
        if ( $this->helper->hasArgument( 'test' ) )
        {
            $this->helper->test();            
        }
        
        $this->dataSource = $this->helper->getData();
        $this->cli->output( $this->helper->getDataCount() . ' atti caricati' );
    }


    public function getProcessLength()
    {
        if ( !isset( $this->rowCount ) )
        {
            $this->rowCount = count( $this->dataSource->atto );
        }
        return $this->rowCount;
    }


    public function getNextRow()
    {
        if ( $this->rowIndex < $this->rowCount )
        {
            $row = $this->dataSource->atto[$this->rowIndex];
            $this->rowIndex++;
        }
        else
        {
            $row = false;
        }
        return $row;
    }

    public function process( $row )
    {
        if ( !$this->helper instanceof AlbotelematicoHelperInterface )
        {
            throw new Exception( "helper non implementa l'interfaccia corretta" );
        }

        try
        {
            $this->helper->setCurrentRow( $row );
            
            if ( !$this->helper->canProcessRow() )
            {                
                return;
            }
            
            if ( $this->helper->hasArgument( 'test' ) )
            {
                $this->helper->test();            
            }
            
            $this->currentGUID = (string) $row->id_atto;
            $this->currentEnte = (string) $row->desc_ente;
            $this->currentTipoAtto = (string) $row->tipo_atto;

            $this->currentName = $this->currentEnte . ' ' . $this->currentTipoAtto . ' ' . $this->currentGUID;

            $classIdentifier = $this->helper->getClassIdentifier();            
            $values = $this->helper->prepareValues();            
            
            $content = $this->helper->fillContent();

            foreach( $locations as $location )
            {
                $content->addLocation( SQLILocation::fromNodeID( $location ) );
            }
            
            $contentObject = $content->getRawContentObject();
            if ( $contentObject->attribute( 'published' ) !==  $values['data_pubblicazione'] )
            {
                $contentObject->setAttribute( 'published', $values['data_pubblicazione'] );
                $contentObject->store();
            }
            
            $publisher = SQLIContentPublisher::getInstance();
            $publisher->publish( $content );
            
            $this->helper->registerImport( $contentObject );
            unset( $content );
        }
        catch( AlboFatalException $e )
        {
            //@todo manda una mail
            $this->helper->rollback();
            $this->cli->error( $e->getMessage() );
            $this->helper->registerError( $e->getMessage() );        
        }
        catch( Exception $e )
        {            
            $this->helper->rollback();
            $this->helper->registerError( $e->getMessage() );        
        }

        return;
    }

    public function cleanup()
    {
        return;
    }

    public function getHandlerName()
    {
        return 'Albo Import Handler';
    }

    public function getHandlerIdentifier()
    {
        return 'alboimporthandler';
    }

    public function getProgressionNotes()
    {
    }    
}
