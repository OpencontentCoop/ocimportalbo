<?php
//  php extension/sqliimport/bin/php/sqlidoimport.php --source-handlers="alboimporthandler" -scomunetest_backend --options="alboimporthandler::comune=stenico" -d
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

        $helperClass = $this->handlerConfArray['HelperClass'];
        if ( class_exists( $helperClass ) )
        {
            $this->helper = new $helperClass();
        }
        else
        {
            throw new Exception( "$helperClass non trovata" );
        }

        if ( !$this->helper instanceof AlbotelematicoHelperInterface )
        {
            throw new Exception( "$helperClass non implementa l'interfaccia corretta" );
        }

        $this->helper->loadArguments( $this->options, $this->handlerConfArray );
        $this->cli->output( 'Carico i dati per ' . implode( $this->helper->getArgument( 'comuni' ) ) );

        $this->helper->loadData();
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

            if ( !$this->helper->filterRow() )
            {
                return;
            }
            $this->currentGUID = (string) $row->id_atto;
            $this->currentEnte = (string) $row->desc_ente;
            $this->currentTipoAtto = (string) $row->tipo_atto;

            $this->currentName = $this->currentEnte . ' ' . $this->currentTipoAtto . ' ' . $this->currentGUID;

            $classIdentifier = $this->helper->getClassIdentifier();
            $locations = $this->helper->getLocations();
            $values = $this->helper->prepareValues();

            $contentOptions = new SQLIContentOptions( array(
                'class_identifier'      => $classIdentifier,
                'remote_id'             => md5( $this->currentGUID )
            ) );

            $content = SQLIContent::create( $contentOptions );
            $this->helper->fillContent( $content );

            foreach( $locations as $location )
            {
                $content->addLocation( SQLILocation::fromNodeID( $location ) );
            }
            $publisher = SQLIContentPublisher::getInstance();
            $publisher->publish( $content );

            $contentObject = $content->getRawContentObject();
            $contentObject->setAttribute( 'published', $values['data_pubblicazione'] );
            $contentObject->store();
            unset( $content );
        }
        catch( AlboFatalException $e )
        {
            //manda una mail
            $this->helper->rollback();
        }
        catch( Exception $e )
        {
            //errore interno
            $this->helper->rollback();
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


    private function uploadCorpo( $xmlfield )
    {
        $path = (string) $xmlfield;
        return $this->uploadFile( $path, $this->currentName, $this->ricavaCollocazioneAllegato( 'file' ) );
    }

    private function uploadAllegati( $xmlfield )
    {
        $ids = array();        
        if ( is_a( $xmlfield, 'SimpleXMLElement') )
        {
            foreach ($xmlfield->children() as $allegato)
            {
                $ids[] = $this->uploadFile(
                    'allegati/' . $allegato->url,
                    $this->currentName . '-' . $allegato->titolo,
                    $this->ricavaCollocazioneAllegato( 'file' )
                );
            }
        }        
        return implode( '-', $ids );
    }

    private function hasAllegati( $xmlfield )
    {
        $data = array();
        if ( is_a( $xmlfield, 'SimpleXMLElement') )
        {
            foreach ($xmlfield->children() as $allegato )
            {
                $data[] = array(
                    'titolo' => (string) $allegato->titolo,
                    'url' => (string) $allegato->url,
                );
            }
        }
        return empty( $data ) ? false : true;
    }
    
    private function disambiguaCompetenzaDeliberazione( $string )
    {
        //Delibere della Giunta del 28/04/2011        
        foreach( $this->competenze as $slug => $competenza )
        {
            if ( stripos( $string, $slug ) !== false )
            {
                return $competenza;
            }
        }
        return false;  
    }   
}
