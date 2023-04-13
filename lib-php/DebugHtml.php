<?php
/**
 * SPDX-License-Identifier: GPL-2.0-only
 *
 * This file is part of Nominatim. (https://nominatim.org)
 *
 * Copyright (C) 2022 by the Nominatim developer community.
 * For a full list of authors see the git log.
 */

namespace Nominatim;

class Debug
{
    public static function newFunction($sHeading)
    {
        echo "<pre><h2>Debug output for $sHeading</h2></pre>\n";
    }

    public static function newSection($sHeading)
    {
        echo "<hr><pre><h3>$sHeading</h3></pre>\n";
    }

    public static function printVar($sHeading, $mVar)
    {
        echo '<pre><b>'.$sHeading. ':</b>  ';
        Debug::outputVar($mVar, str_repeat(' ', strlen($sHeading) + 3));
        echo "</pre>\n";
    }

    public static function fmtArrayVals($aArr)
    {
        return array('__debug_format' => 'array_vals', 'data' => $aArr);
    }

    public static function printDebugArray($sHeading, $oVar)
    {

        if ($oVar === null) {
            Debug::printVar($sHeading, 'null');
        } else {
            Debug::printVar($sHeading, $oVar->debugInfo());
        }
    }

    public static function printDebugTable($sHeading, $aVar)
    {
        echo '<b>'.$sHeading.":</b>\n";
        echo "<table border='1'>\n";
        if (!empty($aVar)) {
            echo "  <tr>\n";
            $aKeys = array();
            $aInfo = reset($aVar);
            if (!is_array($aInfo)) {
                $aInfo = $aInfo->debugInfo();
            }
            foreach ($aInfo as $sKey => $mVal) {
                echo '    <th><small>'.$sKey.'</small></th>'."\n";
                $aKeys[] = $sKey;
            }
            echo "  </tr>\n";
            foreach ($aVar as $oRow) {
                $aInfo = $oRow;
                if (!is_array($oRow)) {
                    $aInfo = $oRow->debugInfo();
                }
                echo "  <tr>\n";
                foreach ($aKeys as $sKey) {
                    echo '    <td><pre>';
                    if (isset($aInfo[$sKey])) {
                        Debug::outputVar($aInfo[$sKey], '');
                    }
                    echo '</pre></td>'."\n";
                }
                echo "  </tr>\n";
            }
        }
        echo "</table>\n";
    }

    public static function printGroupedSearch($aSearches, $aWordsIDs)
    {
        echo '<table border="1">';
        echo '<tr><th>rank</th><th>Name Tokens</th><th>Name Not</th>';
        echo '<th>Address Tokens</th><th>Address Not</th>';
        echo '<th>country</th><th>operator</th>';
        echo '<th>class</th><th>type</th><th>postcode</th><th>housenumber</th></tr>';
        foreach ($aSearches as $aRankedSet) {
            foreach ($aRankedSet as $aRow) {
                $aRow->dumpAsHtmlTableRow($aWordsIDs);
            }
        }
        echo '</table>';
    }

    public static function printGroupTable($sHeading, $aVar)
    {
        echo '<b>'.$sHeading.":</b>\n";
        echo "<table border='1'>\n";
        if (!empty($aVar)) {
            echo "  <tr>\n";
            echo '    <th><small>Group</small></th>'."\n";
            $aKeys = array();
            $aInfo = reset($aVar)[0];
            if (!is_array($aInfo)) {
                $aInfo = $aInfo->debugInfo();
            }
            foreach ($aInfo as $sKey => $mVal) {
                echo '    <th><small>'.$sKey.'</small></th>'."\n";
                $aKeys[] = $sKey;
            }
            echo "  </tr>\n";
            foreach ($aVar as $sGrpKey => $aGroup) {
                foreach ($aGroup as $oRow) {
                    $aInfo = $oRow;
                    if (!is_array($oRow)) {
                        $aInfo = $oRow->debugInfo();
                    }
                    echo "  <tr>\n";
                    echo '    <td><pre>'.$sGrpKey.'</pre></td>'."\n";
                    foreach ($aKeys as $sKey) {
                        echo '    <td><pre>';
                        if (!empty($aInfo[$sKey])) {
                            Debug::outputVar($aInfo[$sKey], '');
                        }
                        echo '</pre></td>'."\n";
                    }
                    echo "  </tr>\n";
                }
            }
        }
        echo "</table>\n";
    }

    public static function printSQL($sSQL)
    {
        echo '<p><tt><font color="#aaa">'.htmlspecialchars($sSQL, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401).'</font></tt></p>'."\n";
    }

    private static function outputVar($mVar, $sPreNL)
    {
        if (is_array($mVar) && !isset($mVar['__debug_format'])) {
            $sPre = '';
            foreach ($mVar as $mKey => $aValue) {
                echo $sPre;
                $iKeyLen = Debug::outputSimpleVar($mKey);
                echo ' => ';
                Debug::outputVar(
                    $aValue,
                    $sPreNL.str_repeat(' ', $iKeyLen + 4)
                );
                $sPre = "\n".$sPreNL;
            }
        } elseif (is_array($mVar) && isset($mVar['__debug_format'])) {
            if (!empty($mVar['data'])) {
                $sPre = '';
                foreach ($mVar['data'] as $mValue) {
                    echo $sPre;
                    Debug::outputSimpleVar($mValue);
                    $sPre = ', ';
                }
            }
        } elseif (is_object($mVar) && method_exists($mVar, 'debugInfo')) {
            Debug::outputVar($mVar->debugInfo(), $sPreNL);
        } elseif (is_a($mVar, 'stdClass')) {
            Debug::outputVar(json_decode(json_encode($mVar), true), $sPreNL);
        } else {
            Debug::outputSimpleVar($mVar);
        }
    }

    private static function outputSimpleVar($mVar)
    {
        if (is_bool($mVar)) {
            echo '<i>'.($mVar ? 'True' : 'False').'</i>';
            return $mVar ? 4 : 5;
        }

        if (is_string($mVar)) {
            $sOut = "'$mVar'";
        } else {
            $sOut = (string)$mVar;
        }

        echo htmlspecialchars($sOut, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401);
        return strlen($sOut);
    }
}
