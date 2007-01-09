<?php
/******************************************************************************
 * Script mit HTML-Code fuer ein Feld der Eigenen-Liste-Konfiguration
 *
 * Copyright    : (c) 2004 - 2006 The Admidio Team
 * Homepage     : http://www.admidio.org
 * Module-Owner : Elmar Meuthen
 *
 * Uebergaben:
 *
 * query : hier steht der Suchstring drin
 ******************************************************************************
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 *
 *****************************************************************************/

require("../../system/common.php");
require("../../system/login_valid.php");

// nur berechtigte User duerfen Querysuggestions empfangen
if (!$g_current_user->editUser())
{
    $g_message->show("norights");
}

if (isset($_GET['members']) && is_numeric($_GET['members']))
{
    $members = $_GET['members'];
}
else
{
    $members = 1;
}

if (isset($_GET['query']) && strlen($_GET['query']) > 0)
{
    $query = strStripTags($_GET['query']);
}
else
{
    $query = null;
}




$xml='<?xml version="1.0" encoding="iso-8859-1" ?>';

if (!$query)
{
    // kein Query - keine Daten...
    $xml .= '<results></results>';
}
else
{
    if (isset($_SESSION['QuerySuggestions']))
    {
        // in der Session ist die Liste noch vorhanden,
        // das heisst es muss keine neue DB-Abfrage abgesetzt werden
        $querySuggestions = $_SESSION['QuerySuggestions'];
    }
    else
    {
        // erst mal die Benutzerliste aus der DB holen und in der Session speichern
        if($members == true)
        {
            $sql    = "SELECT DISTINCT usr_last_name, usr_first_name
                         FROM ". TBL_USERS. ", ". TBL_MEMBERS. ", ". TBL_ROLES. "
                        WHERE usr_valid = 1
                          AND mem_usr_id = usr_id
                          AND mem_rol_id = rol_id
                          AND mem_valid  = 1
                          AND rol_org_shortname = '$g_current_organization->shortname'
                          AND rol_valid  = 1
                        ORDER BY usr_last_name, usr_first_name ";
        }
        else
        {
            $sql    = "SELECT usr_last_name, usr_first_name
                         FROM ". TBL_USERS. "
                        WHERE usr_valid = 1
                        ORDER BY usr_last_name, usr_first_name ";
        }
        $result_mgl = mysql_query($sql, $g_adm_con);
        db_error($result_mgl);

        // Jetzt das komplette resultSet in ein Array schreiben...
        while($row = mysql_fetch_object($result_mgl))
        {
            $entry=array('lastName' => $row->usr_last_name, 'firstName' => $row->usr_first_name);
            $querySuggestions[]=$entry;
        }

        // Jetzt noch das Array für zukuenftiges Nutzen in der Session speichern
        $_SESSION['QuerySuggestions'] = $querySuggestions;
    }


    // ab hier werden jetzt die zur Query passenden Eintraege ermittelt...
    $match=array();
    foreach ($querySuggestions as $suggest)
    {
        $q=strtolower($query);
        if (	strpos(strtolower($suggest['lastName']),$q)===0
            or  strpos(strtolower($suggest['firstName']),$q)===0
            or  strpos(strtolower($suggest['firstName']). " ". strtolower($suggest['lastName']),str_replace(',', '', $q))===0
            or  strpos(strtolower($suggest['lastName']). " ". strtolower($suggest['firstName']),str_replace(',', '', $q))===0)
        {
            $match[]="<rs>". $suggest['lastName']. ", ". $suggest['firstName']. "</rs>";
        }
    }
    //sort($match);
    $xml .= "<results>\n".implode("\n",$match)."</results>";
}

header('Content-Type: text/xml');
echo $xml;

?>