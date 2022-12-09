<?php
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
    /**
     * @var AlbotelematicoHelperInterface
     */
    private $helper;

    private $registerMail = array();

    private static $URLTranslatorSeparator;

    private $initializeError;

    public function __construct(SQLIImportHandlerOptions $options = null)
    {
        parent::__construct($options);
        $this->remoteIDPrefix = $this->getHandlerIdentifier() . '-';
        $this->options = $options;
        $ini = eZINI::instance();
        self::$URLTranslatorSeparator = strtolower($ini->variable("URLTranslator", "WordSeparator"));
    }

    public function initialize()
    {
        try {
            $this->registerMail = array();
            $helperClass = eZINI::instance('alboimporthandler.ini')->variable('HelperSettings', 'HelperClass');
            if (class_exists($helperClass)) {
                $this->helper = new $helperClass();
            } else {
                throw new Exception("Classe helper non trovata");
            }

            if (!$this->helper instanceof AlbotelematicoHelperInterface) {
                throw new Exception("$helperClass non implementa l'interfaccia corretta");
            }

            $this->helper->loadArguments($this->options, $this->handlerConfArray);

            $this->helper->loadData();

            if ($this->helper->hasArgument('test')) {
                $this->helper->test();
            }

            $this->dataSource = $this->helper->getData();
        } catch (Exception $e) {
            $this->initializeError = $e->getMessage();
            AlbotelematicoHelperBase::logError($e->getMessage());
            $this->dataSource = new SimpleXMLElement('<atti></atti>');
        }
        $this->cli->output($this->helper->getDataCount() . ' atti caricati');
    }

    public function getProcessLength()
    {
        if (!isset($this->rowCount)) {
            $this->rowCount = count($this->dataSource->atto);
        }

        return $this->rowCount;
    }

    public function getNextRow()
    {
        if ($this->rowIndex < $this->rowCount) {
            $row = $this->dataSource->atto[$this->rowIndex];
            $this->rowIndex++;
        } else {
            $row = false;
        }

        return $row;
    }

    public function process($row)
    {
        if (!$this->helper instanceof AlbotelematicoHelperInterface) {
            throw new Exception("helper non implementa l'interfaccia corretta");
        }

        $db = eZDB::instance();
        $db->setErrorHandling(eZDB::ERROR_HANDLING_EXCEPTIONS);

        try {
            if (!$this->helper->canProcessRow($row)) {
                return false;
            }

            if ($this->helper->hasArgument('test')) {
                $this->helper->test();
            }

            $this->currentGUID = (string)$row->id_atto;
            $this->currentEnte = (string)$row->desc_ente;
            $this->currentTipoAtto = (string)$row->tipo_atto;

            $this->currentName = $this->currentEnte . ' ' . $this->currentTipoAtto . ' ' . $this->currentGUID;

            $classIdentifier = $this->helper->getClassIdentifier();
            $values = $this->helper->prepareValues();
            $locations = $this->helper->getLocations();

            $content = $this->helper->fillContent();
            $this->helper->setPublishedTimestamp();

            foreach ($locations as $location) {
                $content->addLocation(SQLILocation::fromNodeID($location));
            }

            $publisher = SQLIContentPublisher::getInstance();

            $this->startCharTransformWorkaround();

            $publisher->publish($content);

            $this->endCharTransformWorkaround();

            $this->fixUrlAlias($content);

            $this->cli->output('Published ' . $this->helper->getRemoteID());

            $this->helper->registerImport();
            unset($content);
        } catch (eZDBException $e) {
            $this->helper->rollback();
            $this->registerMail[] = array('row' => $row, 'exception' => $e);
            $this->cli->error($e->getMessage());
            $db->rollback();
        } catch (AlboFatalException $e) {
            $this->helper->rollback();
            $this->registerMail[] = array('row' => $row, 'exception' => $e);
            $this->helper->registerError($e->getMessage());
        } catch (Exception $e) {
            $this->helper->rollback();
            $this->helper->registerError($e->getMessage());
        }

        return true;
    }

    public function sendMail()
    {
        return false;
    }

    private function _sendMail()
    {
        if (count($this->registerMail) > 0) {
            $tpl = eZTemplate::factory();

            $mail = new eZMail();
            $mail->setSender(eZINI::instance()->variable('MailSettings', 'AdminEmail'));
            $mail->setReceiver('innovazione@comunitrentini.it');
            $mail->addCc('assistenza@albotelematico.tn.it');
            $mail->addCc('lr@opencontent.it');

            $sitename = eZSys::hostname();
            $arguments = $this->helper->getArgumentsAsString();
            $options = $this->helper->getOptionsAsString();
            $feed = $this->helper->getFeedsAsString();

            $tpl->setVariable('sitename', $sitename);
            $tpl->setVariable('arguments', $arguments);
            $tpl->setVariable('options', $options);
            $tpl->setVariable('feed', $feed);

            $errors = array();
            foreach ($this->registerMail as $i => $item) {
                $e = $item['exception'];
                if ($e instanceof Exception) {
                    $message = $e->getMessage();
                }
                if ($message == '' && $e instanceof Exception) {
                    $message = $e->getTraceAsString();
                }
                if ($item['row']) {
                    $error['row'] = $item['row']->asXML();
                    $error['row_id'] = $item['row']->id_atto;
                }
                $error['message'] = $message;
                $errors[] = $error;
            }
            $tpl->setVariable('errors', $errors);

            $body = $tpl->fetch('design:mail_error.tpl');
            $mail->setContentType('text/html');
            $mail->setSubject(eZINI::instance()->variable('SiteSettings','SiteName') . " " . count($errors) . " errori {$this->getHandlerName()}");
            $mail->setBody($body);
            eZMailTransport::send($mail);
        }
    }

    public function cleanup()
    {
        if ($this->helper instanceof AlbotelematicoHelperInterface) {
            try{
                $this->helper->cleanup();
            }catch (Exception $e){
                AlbotelematicoHelperBase::logError($e->getMessage());
                $this->registerMail[] = array('exception' => $e);
            }

        }
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
        if ($this->initializeError){
            return $this->initializeError;
        }

        return $this->currentGUID;
    }

    private function removeCharTransformCacheFile($group = 'lowercase', $charset = false)
    {
        $charsetName = ( $charset === false ? eZTextCodec::internalCharset() : eZCharsetInfo::realCharsetCode($charset) );
        $keyText = 'Group:' . $group;
        $key = eZSys::ezcrc32($keyText . '-' . $charset);
        $prefix = 'g-' . $group . '-';
        $suffix = '-' . $charsetName;
        $path = eZCharTransform::cachedTransformationPath() . '/' . $prefix . sprintf("%u",
                $key) . $suffix . '.ctt.php'; // ctt=charset transform table
        if (file_exists($path)) {
            unlink($path);
        }
    }

    private function startCharTransformWorkaround()
    {
        unset($GLOBALS['eZCharTransformInstance']);
        unset($GLOBALS['eZCharTransform_wordSeparator']);

        eZINI::instance()->resetCache();
        eZINI::instance()->setVariable("URLTranslator", "WordSeparator", 'space');

        eZINI::instance('transform.ini')->resetCache();
        eZINI::instance('transform.ini')->setVariable('lowercase', 'Commands', array('url_cleanup', 'lowercase'));

        $this->removeCharTransformCacheFile();

        $trans = eZCharTransform::instance();
        $test = $trans->transformByGroup('TEST TEST', 'lowercase');
    }

    private function endCharTransformWorkaround()
    {
        unset($GLOBALS['eZCharTransformInstance']);
        unset($GLOBALS['eZCharTransform_wordSeparator']);

        eZINI::instance()->resetCache();
        eZINI::instance()->setVariable("URLTranslator", "WordSeparator", self::$URLTranslatorSeparator);

        eZINI::instance('transform.ini')->resetCache();
        eZINI::instance('transform.ini')->setVariable('lowercase', 'Commands', array('lowercase'));

        $this->removeCharTransformCacheFile();

        $trans = eZCharTransform::instance();
        $test = $trans->transformByGroup('TEST TEST', 'lowercase');
        $test = $trans->transformByGroup('TEST TEST', 'urlalias');
    }

    private function fixUrlAlias(SQLIContent $content)
    {
        $object = $content->getRawContentObject();

        $nodes = $object->assignedNodes();
        foreach ($nodes as $node) {
            $node->setName($object->attribute('name'));
            $node->updateSubTreePath();
        }
    }
}
