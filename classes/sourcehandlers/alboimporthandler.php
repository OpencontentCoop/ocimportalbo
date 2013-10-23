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

    private $registerMail;
    
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
            if ( !$this->helper->canProcessRow( $row  ) )
            {                
                return false;
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
            $locations = $this->helper->getLocations();
            
            $content = $this->helper->fillContent();
            $this->helper->setPublishedTimestamp();
            
            foreach( $locations as $location )
            {
                $content->addLocation( SQLILocation::fromNodeID( $location ) );
            }                    
            
            $publisher = SQLIContentPublisher::getInstance();
            $publisher->publish( $content );
            
            $this->helper->registerImport();
            unset( $content );
        }
        catch( AlboFatalException $e )
        {            
            $this->helper->rollback();
            $this->registerMail[] = array( 'row' => $row, 'message' => $e->getMessage() );
            $this->helper->registerError( $e->getMessage() );
        }
        catch( Exception $e )
        {            
            $this->helper->rollback();
            $this->helper->registerError( $e->getMessage() );
        }

        return;
    }
    
    public function sendMail()
    {
        if ( count( $this->registerMail ) > 0 )
        {
            $mail = new eZMail();                                
            $mail->setSender( eZINI::instance()->variable( 'MailSettings', 'AdminEmail' ) );
            $mail->setReceiver( 'luca.realdi@opencontent.it' );
            
            $sitename = eZSys::hostname();
            $arguments = var_export( $this->helper->arguments, 1 );
            $options = var_export( $this->helper->options, 1 );
            $feed = var_export( $this->helper->feed, 1 );        
                    
            $body = date( 'j/m/Y h:i' ) . "\n\n"
                . "### Sorgente ###\n$feed\n\n"
                . "### Argomenti ###\n$arguments\n\n"
                . "### Opzioni ###\n$options\n\n";
                
            foreach( $this->registerMail as $i => $item )
            {
                $i++;
                $row = var_export( $item['row'], 1 );
                $body .= "{$i}) Errore: {$item['message']}\n\n### Riga ###\n{$row}\n\n";
            }
            
            $errors = count($this->registerMail);
            $mail->setSubject( "{$errors} errori {$this->getHandlerName()} in $sitename" );
            $mail->setBody( $body );
            eZMailTransport::send( $mail );
        }
    }

    public function cleanup()
    {
        $this->helper->cleanup();
        $this->sendMail();
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
