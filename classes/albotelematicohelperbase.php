<?php

class AlbotelematicoHelperBase
{
    public $ini;
    public $tempVarDir;
    public $arguments;
    public $options;
    public $dataCount;
    public $data;
    public $row;

    public $classIdentifier;
    public $locations;

    public $values;
    public $mapAttributes = array();
    
    public function __construct()
    {
        $this->ini = eZINI::instance( 'alboimporthandler.ini' );
        $this->tempVarDir = eZINI::instance()->variable( 'FileSettings','VarDir' ) . '/import/';
    }
    
    public function loadArguments( $arguments, $options )
    {
        $this->arguments = $arguments;
        $this->options = $options;
        $this->validateArguments();
    }

    public function getArgument( $name )
    {
        if ( !isset( $this->arguments[$name] ) )
        {
            throw new InvalidArgumentException( "Argomento $name non trovato" );
        }
        return $this->arguments[$name];
    }

    public function getData()
    {
        return $this->data;
    }

    public function getDataCount()
    {
        return $this->dataCount;
    }

    public function validateArguments()
    {
        foreach( $this->availableArguments() as $argument => $isRequired )
        {
            if ( !isset( $this->arguments[$argument] ) && $isRequired )
            {
                throw new AlboFatalException( "Opzione $argument non trovata" );
            }
        }
    }

    public function setCurrentRow( $row )
    {
        $this->row = $row;
    }

    public static function append_simplexml( SimpleXMLElement &$simplexml_to, SimpleXMLElement &$simplexml_from )
    {

        static $firstLoop = true;

        //Here adding attributes to parent
        if ( $firstLoop )
        {
            foreach( $simplexml_from->attributes() as $attr_key => $attr_value )
            {
                $simplexml_to->addAttribute($attr_key, $attr_value);
            }
        }

        foreach ($simplexml_from->children() as $simplexml_child)
        {
            if ( $simplexml_child instanceof SimpleXMLElement )
            {
                $simplexml_temp = $simplexml_to->addChild( $simplexml_child->getName(), (string) $simplexml_child );
                foreach ( $simplexml_child->attributes() as $attr_key => $attr_value )
                {
                    $simplexml_temp->addAttribute($attr_key, $attr_value);
                }
            }

            $firstLoop = false;

            self::append_simplexml( $simplexml_temp, $simplexml_child );
        }

        unset( $firstLoop );
    }

    public function valueDisambiguation( $typeValue, $parameters )
    {
        $oggetto = (string) $this->row->oggetto;
        $tipo = (string) $this->row->tipo_atto;

        switch( $typeValue )
        {
            case 'class':
            {
                if ( $tipo == 'Decreti e ordinanze' )
                {
                    if ( strpos( strtolower( $oggetto ), 'decreto' ) !== false )
                    {
                        return 'decreto_sindacale';
                    }
                    elseif ( strpos( strtolower( $oggetto ), 'ordinanza' ) !== false )
                    {
                        return 'ordinanza';
                    }
                    else
                    {
                        throw new AlboFatalException( 'Non riesco a disambiguare la classe per ' . $oggetto, __METHOD__);
                    }
                }
            } break;

            case 'location':
            {
                if ( $tipo == 'Delibere' )
                {
                    foreach( array( 'consiglio', 'consiliar' ) as $term )
                    {
                        if ( strpos( strtolower( $oggetto ), $term ) !== false )
                        {
                            return $parameters[1]; // Delibere di Consiglio
                        }
                    }

                    return $parameters[0]; // Delibere di Giunta (default)
                }

                if ( $tipo == 'Decreti e ordinanze' )
                {
                    foreach( array( 'decreto' ) as $term )
                    {
                        if ( strpos( strtolower( $oggetto ), $term ) !== false )
                        {
                            return $parameters[0]; // Decreti sindacali
                        }
                    }

                    return $parameters[1]; // Ordinanze (default)
                }
            }
        }
        return false;
    }

    public function prepareValues()
    {
        $this->values = array();
        foreach( (array) $this->row as $index => $value )
        {
            switch( $index )
            {
                case 'data_pubblicazione':
                case 'data_termine':
                {
                    $date = DateTime::createFromFormat( "d/m/Y", (string) $value );
                    if ( !$date instanceof DateTime )
                    {
                        throw new AlboFatalException( "$value non è una data" );
                    }
                    if ( $index == 'data_pubblicazione' )
                    {
                        $this->values['anno'] = $date->format( 'Y' );
                    }
                    $this->values[$index] = $date->format( 'U' );
                } break;

                case 'allegati':
                {
                    $this->values['allegati'] = array();
                    if ( $value instanceof SimpleXMLElement )
                    {
                        foreach ( $value->children() as $allegato )
                        {
                            $this->values['allegati'] = array( 'url' => 'allegati/' . $allegato->url,
                                                        'name' => (string) $allegato->titolo );
                        }
                    }
                } break;

                default:
                {
                    $this->values[$index] = (string) $value;
                }
            }
        }

        $baseUrl = str_replace( "ENTE", $this->row->id_ente, $this->options['FileUrl'] );
        $baseUrl = str_replace( "ANNO", $this->values['anno'], $baseUrl );

        $this->values['url'] = $baseUrl . $this->values['url'];
        foreach( $this->values['allegati'] as $allegato )
        {
            $allegato['url'] = $baseUrl . $allegato['url'];
        }

        return $this->values;
    }

    public function attributesMap()
    {
        if ( !$this->ini->hasVariable( $this->classIdentifier, 'MapAttribute' ) )
        {
            throw new RuntimeException( 'Non trovo la mappa degli attributi per ' . $this->classIdentifier );
        }
        if ( $this->values == null )
        {
            $this->prepareValues();
        }
        $map = $this->ini->variable( $this->classIdentifier, 'MapAttribute' );
        foreach( $map as $ez => $albo )
        {
            //retrocompatibilità con ini
            $albo = explode( ';', $albo );
            $this->mapAttributes[$ez] = isset( $this->values[$albo[0]] ) ? $this->values[$albo[0]] : false;
        }
        return $this->mapAttributes;
    }

    function fillContent( SQLIContent $content )
    {
        if ( $this->mapAttributes == null )
        {
            $this->attributesMap();
        }
        foreach( $content->fields as $attributeIdentifier => $attribute )
        {
            if ( isset( $this->mapAttributes[$attributeIdentifier] ) )
            {
                switch( $attribute->attribute( 'data_type_string' ) )
                {
                    case 'ezbinaryfile':
                    {

                    } break;
                    default:
                    {
                        $content->{$attributeIdentifier} = $this->mapAttributes[$attributeIdentifier];
                    }
                }
            }
        }
    }

    function rollback()
    {

    }
}
