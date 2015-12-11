<?php

/**
 * Created by PhpStorm.
 * User: luca
 * Date: 11/12/15
 * Time: 16:49
 */
class AlboXMLParser extends SQLIXMLParser
{
    protected function getXMLString( $path )
    {
        $xmlString = parent::getXMLString( $path );
        $xmlString = AlboXMLParser::htmlentities2utf8( $xmlString );
        return $xmlString;
    }

    public static function replace_num_entity($ord)
    {
        $ord = $ord[1];
        if (preg_match('/^x([0-9a-f]+)$/i', $ord, $match))
        {
            $ord = hexdec($match[1]);
        }
        else
        {
            $ord = intval($ord);
        }

        $no_bytes = 0;
        $byte = array();

        if ($ord < 128)
        {
            return chr($ord);
        }
        elseif ($ord < 2048)
        {
            $no_bytes = 2;
        }
        elseif ($ord < 65536)
        {
            $no_bytes = 3;
        }
        elseif ($ord < 1114112)
        {
            $no_bytes = 4;
        }
        else
        {
            return;
        }

        switch($no_bytes)
        {
            case 2:
            {
                $prefix = array(31, 192);
                break;
            }
            case 3:
            {
                $prefix = array(15, 224);
                break;
            }
            case 4:
            {
                $prefix = array(7, 240);
            }
        }

        for ($i = 0; $i < $no_bytes; $i++)
        {
            $byte[$no_bytes - $i - 1] = (($ord & (63 * pow(2, 6 * $i))) / pow(2, 6 * $i)) & 63 | 128;
        }

        $byte[0] = ($byte[0] & $prefix[0]) | $prefix[1];

        $ret = '';
        for ($i = 0; $i < $no_bytes; $i++)
        {
            $ret .= chr($byte[$i]);
        }

        //return $ret;
        return '';
    }

    public static function htmlentities2utf8 ($string) // because of the html_entity_decode() bug with UTF-8
    {
        $string = preg_replace_callback('/&#([0-9a-fx]+);/mi', array( 'AlboXMLParser', 'replace_num_entity' ), $string);
        return $string;
    }
}